# Guide Expert MikroTik — PPP, PPPoE & Gestion des Sessions
### Documentation technique complète — MIKHMON by SafeLink Africa

---

> **Niveau** : Intermédiaire à Expert
> **Audience** : Administrateurs réseau, ISP, opérateurs Hotspot
> **Plateforme** : MikroTik RouterOS (v6.x / v7.x)
> **Outil de gestion** : MIKHMON

---

## Table des matières

1. [Comprendre le protocole PPP](#1-comprendre-le-protocole-ppp)
2. [PPPoE — PPP over Ethernet](#2-pppoe--ppp-over-ethernet)
3. [Architecture réseau PPPoE sur MikroTik](#3-architecture-réseau-pppoe-sur-mikrotik)
4. [Profils PPP — Cœur de la politique réseau](#4-profils-ppp--cœur-de-la-politique-réseau)
5. [Secrets PPP — Gestion des comptes clients](#5-secrets-ppp--gestion-des-comptes-clients)
6. [Serveur PPPoE — Configuration de A à Z](#6-serveur-pppoe--configuration-de-a-à-z)
7. [PPP Actifs — Supervision en temps réel](#7-ppp-actifs--supervision-en-temps-réel)
8. [Utilité pour l'administrateur](#8-utilité-pour-ladministrateur)
9. [Utilité pour les utilisateurs (abonnés)](#9-utilité-pour-les-utilisateurs-abonnés)
10. [Cas d'usage concrets](#10-cas-dusage-concrets)
11. [Dépannage et erreurs courantes](#11-dépannage-et-erreurs-courantes)
12. [Bonnes pratiques & Sécurité](#12-bonnes-pratiques--sécurité)
13. [Commandes CLI de référence](#13-commandes-cli-de-référence)

---

## 1. Comprendre le protocole PPP

### 1.1 Qu'est-ce que PPP ?

**PPP** (Point-to-Point Protocol) est un protocole de liaison de données défini par la **RFC 1661** (1994). Il permet d'établir une connexion directe entre deux nœuds réseau, en encapsulant des paquets IP dans des trames de liaison.

PPP est le fondement de toutes les connexions DSL, ADSL, VDSL, fibre (PPPoE) et VPN dial-up dans le monde entier. Il offre :

| Fonctionnalité | Description |
|----------------|-------------|
| **Authentification** | PAP, CHAP, MS-CHAPv2 |
| **Compression** | Réduction de la bande passante |
| **Chiffrement** | MPPE (Microsoft Point-to-Point Encryption) |
| **Multi-protocoles** | IP, IPX, AppleTalk |
| **Négociation** | LCP (Link Control Protocol) |
| **Attribution IP** | Automatique côté serveur |

### 1.2 La famille PPP dans MikroTik

MikroTik implémente plusieurs variantes du protocole PPP :

```
PPP (famille)
├── PPPoE   → PPP over Ethernet       (ISP, ADSL, fibre sur réseau LAN)
├── PPTP    → PPP over TCP/IP         (VPN client-serveur, obsolète)
├── L2TP    → Layer 2 Tunneling       (VPN sécurisé, avec IPsec)
├── SSTP    → Secure Socket Tunneling (VPN via HTTPS, Windows)
└── OpenVPN → Open-source VPN        (certificats, très sécurisé)
```

> Dans MIKHMON, la section **PPPoE** gère spécifiquement les serveurs PPPoE et les connexions client via Ethernet.

### 1.3 Cycle de vie d'une session PPP

```
CLIENT                          SERVEUR MIKROTIK
  │                                    │
  │──── PADI (Discovery Init) ────────▶│  Phase 1 : Découverte
  │◀─── PADO (Discovery Offer) ────────│
  │──── PADR (Session Request) ────────▶│
  │◀─── PADS (Session Confirm) ────────│
  │                                    │
  │══════════ LCP Negotiate ══════════│  Phase 2 : Établissement LCP
  │══════════ Auth (CHAP/PAP) ════════│  Phase 3 : Authentification
  │══════════ IPCP (IP assign) ═══════│  Phase 4 : Attribution IP
  │                                    │
  │◀══════ SESSION ACTIVE ═══════════▶│  Phase 5 : Données
  │                                    │
  │──── PADT (Terminate) ──────────────▶│  Phase 6 : Déconnexion
```

---

## 2. PPPoE — PPP over Ethernet

### 2.1 Définition et rôle

**PPPoE** (RFC 2516) encapsule des sessions PPP dans des trames Ethernet standard. C'est la technologie utilisée par la quasi-totalité des opérateurs télécom pour fournir un accès Internet haut débit à leurs abonnés.

**Avantages de PPPoE :**

- **Authentification par abonné** : chaque client a ses identifiants uniques
- **Gestion de la bande passante** : limitation par profil (rate-limit)
- **Attribution d'IP dynamique ou statique** par client
- **Comptabilité précise** : sessions traçables (durée, volume, heure)
- **Multi-tenant** : un seul routeur peut servir des milliers d'abonnés
- **Facturation** : base pour les systèmes de facturation (RADIUS, MIKHMON)

### 2.2 Comparaison PPPoE vs Hotspot

| Critère | PPPoE | Hotspot |
|---------|-------|---------|
| **Couche réseau** | Couche 2 (Ethernet) | Couche 3 (IP/HTTP) |
| **Authentification** | Client PPPoE natif | Portail web (navigateur) |
| **Type d'accès** | Filaire (câble) | Filaire + WiFi |
| **Attribution IP** | Pool PPP dédié | Pool DHCP |
| **Limitation débit** | Rate-limit PPP | Queue / Hotspot profile |
| **Cas d'usage** | ISP résidentiel/pro | Hôtel, café, campus |
| **Reconnexion auto** | Oui (client PPPoE) | Non (re-login web) |

---

## 3. Architecture réseau PPPoE sur MikroTik

### 3.1 Topologie typique

```
                    INTERNET
                       │
                  ┌────┴────┐
                  │  WAN    │ ether1 (IP publique FAI)
                  │         │
                  │MikroTik │ ← Serveur PPPoE actif
                  │ Router  │
                  │         │ ether2 (IP: 192.168.88.1/24)
                  └────┬────┘
                       │ Switch LAN
          ┌────────────┼────────────┐
          │            │            │
     ┌────┴───┐   ┌────┴───┐  ┌────┴───┐
     │Client 1│   │Client 2│  │Client N│
     │PPPoE   │   │PPPoE   │  │PPPoE   │
     │user:a  │   │user:b  │  │user:n  │
     │pass:xxx│   │pass:xxx│  │pass:xxx│
     └────────┘   └────────┘  └────────┘
     IP: 10.0.0.2  IP: 10.0.0.3  IP: 10.0.0.N
         (pool PPP attribué dynamiquement)
```

### 3.2 Composants clés dans MikroTik

```
/interface pppoe-server server   ← Serveur PPPoE (interface virtuelle)
/ppp profile                     ← Profils de service (débit, IP, DNS)
/ppp secret                      ← Comptes clients (user/pass/IP statique)
/ppp active                      ← Sessions actives (monitoring)
/ip pool                         ← Plage d'IP à distribuer aux clients
```

---

## 4. Profils PPP — Cœur de la politique réseau

### 4.1 Rôle du profil PPP

Un **profil PPP** est un modèle de service qui définit les conditions de connexion appliquées à un client ou groupe de clients. C'est l'équivalent d'un "forfait" réseau : il détermine le débit, les DNS, les adresses IP, le timeout, et la politique de sécurité.

> Analogie : Le profil PPP = la fiche tarifaire d'un abonnement télécom. Le secret PPP = l'abonné lui-même.

### 4.2 Paramètres d'un profil PPP

| Paramètre | Description | Exemple |
|-----------|-------------|---------|
| `name` | Nom du profil | `forfait-5mbps` |
| `local-address` | IP locale du routeur (passerelle) | `10.0.0.1` |
| `remote-address` | Pool d'IP pour les clients | `pool-pppoe` |
| `rate-limit` | Débit upload/download | `5M/10M` |
| `dns-server` | Serveurs DNS injectés | `8.8.8.8,1.1.1.1` |
| `session-timeout` | Durée max de session | `24:00:00` |
| `idle-timeout` | Déconnexion si inactif | `00:30:00` |
| `only-one` | Une seule connexion simultanée | `yes` |
| `use-encryption` | Chiffrement MPPE | `yes/no` |

### 4.3 Créer un profil PPP — Étape par étape

#### Via l'interface Winbox / WebFig

```
PPP → Profiles → [+] Add
```

#### Via CLI (Terminal MikroTik)

```bash
# Étape 1 : Créer un pool d'adresses IP pour les clients
/ip pool add name="pool-pppoe" ranges=10.10.10.2-10.10.10.254

# Étape 2 : Créer le profil "standard" (5 Mbps / 10 Mbps)
/ppp profile add \
  name="forfait-10mbps" \
  local-address=10.10.10.1 \
  remote-address=pool-pppoe \
  rate-limit="5M/10M" \
  dns-server="8.8.8.8,8.8.4.4" \
  only-one=yes \
  session-timeout=0 \
  idle-timeout=00:30:00 \
  use-compression=no

# Étape 3 : Créer le profil "premium" (20 Mbps / 50 Mbps)
/ppp profile add \
  name="forfait-50mbps" \
  local-address=10.10.10.1 \
  remote-address=pool-pppoe \
  rate-limit="20M/50M" \
  dns-server="8.8.8.8,1.1.1.1" \
  only-one=yes \
  session-timeout=0 \
  idle-timeout=01:00:00
```

#### Via MIKHMON

1. Se connecter à MIKHMON en tant qu'admin
2. Naviguer vers **PPPoE → Profils PPP**
3. Cliquer sur **Ajouter un profil**
4. Remplir les champs : Nom, Adresse locale, Pool distant, Rate-limit
5. Sauvegarder

### 4.4 Stratégie de nommage des profils

```
Bonne pratique :
  forfait-5M-10M      → Upload 5 Mbps / Download 10 Mbps
  forfait-illimite    → Pas de limitation de débit
  forfait-pro-50M     → Offre professionnelle 50 Mbps
  forfait-trial-1M    → Essai gratuit 1 Mbps
```

---

## 5. Secrets PPP — Gestion des comptes clients

### 5.1 Qu'est-ce qu'un secret PPP ?

Un **secret PPP** est un compte d'authentification individuel associé à un abonné. Il contient les identifiants de connexion du client (username/password) et peut lui assigner un profil spécifique, une IP statique, et d'autres paramètres personnalisés.

> C'est la "carte d'identité réseau" de l'abonné.

### 5.2 Paramètres d'un secret PPP

| Paramètre | Description | Exemple |
|-----------|-------------|---------|
| `name` | Identifiant de connexion | `client001` |
| `password` | Mot de passe | `P@ssw0rd123` |
| `service` | Type de service | `pppoe`, `any` |
| `profile` | Profil appliqué | `forfait-10mbps` |
| `remote-address` | IP statique (optionnel) | `10.10.10.50` |
| `caller-id` | Filtrer par MAC/interface | `AA:BB:CC:DD:EE:FF` |
| `limit-bytes-in` | Quota download (bytes) | `107374182400` (=100 Go) |
| `limit-bytes-out` | Quota upload (bytes) | `53687091200` (=50 Go) |
| `comment` | Note admin | `M. Dupont - Contrat #1234` |
| `disabled` | Activer/désactiver | `no` |

### 5.3 Créer un secret PPP — Étape par étape

#### Via CLI

```bash
# Client standard avec profil 10 Mbps
/ppp secret add \
  name="jean.dupont" \
  password="SecurePass2024!" \
  service=pppoe \
  profile="forfait-10mbps" \
  comment="Jean Dupont - Rue des Fleurs 12"

# Client avec IP statique garantie
/ppp secret add \
  name="entreprise.abc" \
  password="Corp@2024!" \
  service=pppoe \
  profile="forfait-50mbps" \
  remote-address=10.10.10.100 \
  comment="SARL ABC - Contrat pro #5678"

# Client avec quota de données (10 Go download)
/ppp secret add \
  name="forfait.prepaye" \
  password="prepaye123" \
  service=pppoe \
  profile="forfait-10mbps" \
  limit-bytes-in=10737418240 \
  comment="Prépayé 10 Go"
```

#### Via MIKHMON

1. Aller dans **PPPoE → Secrets PPP**
2. Cliquer sur **Ajouter un secret**
3. Remplir : Nom d'utilisateur, Mot de passe, Service, Profil
4. (Optionnel) Ajouter une IP statique ou un commentaire
5. Sauvegarder

### 5.4 Gestion en masse des secrets

```bash
# Désactiver tous les secrets d'un profil expiré
/ppp secret set [find profile="forfait-expire"] disabled=yes

# Exporter la liste des secrets (backup)
/ppp secret export file=ppp-secrets-backup

# Changer le profil de plusieurs clients
/ppp secret set [find comment~"VIP"] profile="forfait-50mbps"
```

---

## 6. Serveur PPPoE — Configuration de A à Z

### 6.1 Prérequis

Avant de configurer le serveur PPPoE, vous devez avoir :

- [ ] Une interface Ethernet dédiée au LAN des abonnés (ex: `ether2`)
- [ ] Un pool d'adresses IP créé (`/ip pool`)
- [ ] Au moins un profil PPP créé
- [ ] L'interface LAN **sans IP Bridge** (le serveur PPPoE gère l'adressage)

### 6.2 Configuration complète de A à Z

#### ÉTAPE 1 — Préparer l'interface LAN

```bash
# Vérifier l'interface ether2 (LAN abonnés)
/interface print
# Assurez-vous qu'ether2 n'a pas d'IP attribuée directement
# sauf si vous utilisez une interface bridge

# Si vous utilisez un bridge (recommandé pour plusieurs ports LAN)
/interface bridge add name=bridge-lan
/interface bridge port add interface=ether2 bridge=bridge-lan
/interface bridge port add interface=ether3 bridge=bridge-lan
```

#### ÉTAPE 2 — Créer le pool d'adresses IP

```bash
/ip pool add \
  name="pool-pppoe-clients" \
  ranges=192.168.100.2-192.168.100.254
```

#### ÉTAPE 3 — Créer le profil par défaut

```bash
/ppp profile add \
  name="default-pppoe" \
  local-address=192.168.100.1 \
  remote-address=pool-pppoe-clients \
  rate-limit="10M/20M" \
  dns-server="8.8.8.8,8.8.4.4" \
  only-one=yes \
  use-compression=no
```

#### ÉTAPE 4 — Activer le serveur PPPoE

```bash
/interface pppoe-server server add \
  name="pppoe-server-lan" \
  interface=ether2 \
  service-name="ISP-SafeLink" \
  authentication=chap,mschap2 \
  default-profile=default-pppoe \
  max-mtu=1480 \
  max-mru=1480 \
  keepalive-timeout=10 \
  one-session-per-host=yes \
  disabled=no
```

> **Paramètre `one-session-per-host`** : empêche qu'un même équipement ouvre plusieurs sessions simultanées (anti-fraude).

#### ÉTAPE 5 — Configurer le NAT (masquerade)

```bash
# Permettre aux clients PPPoE d'accéder à Internet
/ip firewall nat add \
  chain=srcnat \
  out-interface=ether1 \
  action=masquerade \
  comment="NAT PPPoE clients vers Internet"
```

#### ÉTAPE 6 — Route par défaut (si absente)

```bash
# Vérifier la route par défaut
/ip route print

# Ajouter si nécessaire
/ip route add \
  dst-address=0.0.0.0/0 \
  gateway=IP_DU_FAI \
  comment="Route par défaut Internet"
```

#### ÉTAPE 7 — Créer les comptes clients (secrets)

```bash
# Premier abonné
/ppp secret add \
  name="abonne001" \
  password="motdepasse001" \
  service=pppoe \
  profile=default-pppoe \
  comment="Premier abonné"
```

#### ÉTAPE 8 — Vérifier la configuration

```bash
# Lister les serveurs PPPoE actifs
/interface pppoe-server server print

# Lister les profils
/ppp profile print

# Lister les secrets
/ppp secret print

# Surveiller les connexions actives
/ppp active print
```

### 6.3 Configuration recommandée du pare-feu pour PPPoE

```bash
# Accepter les connexions PPPoE entrantes (port 1863, protocole pppoe)
/ip firewall filter add \
  chain=input \
  protocol=pppoe \
  action=accept \
  comment="Autoriser PPPoE"

# Protéger le serveur contre les attaques
/ip firewall filter add \
  chain=input \
  connection-state=invalid \
  action=drop \
  comment="Bloquer connexions invalides"
```

### 6.4 Paramètres avancés du serveur PPPoE

| Paramètre | Valeur recommandée | Explication |
|-----------|-------------------|-------------|
| `max-mtu` | 1480 | MTU optimal pour PPPoE (1500 - 20 bytes overhead) |
| `max-mru` | 1480 | MRU cohérent avec MTU |
| `keepalive-timeout` | 10 | Détecte les clients déconnectés en 10 secondes |
| `authentication` | `chap,mschap2` | Exclure PAP (mot de passe en clair) |
| `one-session-per-host` | `yes` | Évite le partage de compte |
| `max-sessions` | selon capacité | Limite le nombre de clients simultanés |

---

## 7. PPP Actifs — Supervision en temps réel

### 7.1 Qu'est-ce que la vue "PPP Actifs" ?

La section **PPP Actifs** affiche en temps réel toutes les sessions PPPoE/PPP en cours sur le routeur. C'est le tableau de bord opérationnel de l'administrateur réseau.

### 7.2 Informations disponibles par session

| Colonne | Description |
|---------|-------------|
| **Name** | Identifiant du client connecté |
| **Service** | Type (pppoe, pptp, l2tp...) |
| **Caller-ID** | Adresse MAC ou interface source |
| **Address** | IP attribuée au client |
| **Uptime** | Durée de la session active |
| **Encoding** | Algorithme de chiffrement |
| **Session-ID** | Identifiant unique de session |
| **Limit Bytes In/Out** | Quota consommé vs alloué |

### 7.3 Commandes de supervision

```bash
# Voir toutes les sessions actives
/ppp active print

# Vue détaillée avec statistiques
/ppp active print detail

# Filtrer par utilisateur
/ppp active print where name="jean.dupont"

# Voir la bande passante en temps réel
/interface monitor-traffic [find name~"<pppoe>"]

# Déconnecter un client spécifique
/ppp active remove [find name="jean.dupont"]

# Déconnecter tous les clients d'un profil
/ppp active remove [find]
```

### 7.4 Monitoring via MIKHMON

Dans MIKHMON, la section **PPP Actifs** affiche :

- Le nombre de sessions actives en temps réel
- Le temps de connexion de chaque session
- L'adresse IP attribuée
- Un bouton de déconnexion rapide
- Les statistiques de trafic par session

---

## 8. Utilité pour l'administrateur

### 8.1 Contrôle total de l'accès réseau

L'administrateur dispose de pouvoirs complets sur chaque connexion :

```
✅ Créer / Modifier / Supprimer des comptes abonnés
✅ Définir des limites de débit par client ou groupe
✅ Attribuer des IP statiques pour les clients fixes
✅ Activer / Désactiver un compte instantanément
✅ Fixer des quotas de données
✅ Surveiller les connexions en temps réel
✅ Déconnecter à distance un abonné
✅ Gérer plusieurs offres tarifaires (profils)
✅ Générer des rapports de consommation
✅ Intégrer avec RADIUS pour la facturation automatisée
```

### 8.2 Gestion de la qualité de service (QoS)

Grâce aux profils PPP, l'administrateur peut implémenter une QoS granulaire :

```bash
# Profil voix/streaming (priorité haute)
/ppp profile add name="voip-priority" \
  rate-limit="2M/5M" \
  local-address=10.0.0.1 \
  remote-address=pool-voip

# Dans le queue tree, prioriser le trafic de ce pool
/queue tree add name="voip-up" \
  parent=global \
  packet-mark=voip-up \
  priority=1 \
  max-limit=2M
```

### 8.3 Sécurité et contrôle d'accès

```bash
# Bloquer un client immédiatement (impayé)
/ppp secret set [find name="client-impaye"] disabled=yes

# Limiter à un seul appareil par compte
/ppp secret set [find] service=pppoe

# Lier un compte à une MAC address précise
/ppp secret set [find name="entreprise-vip"] \
  caller-id="AA:BB:CC:DD:EE:FF"
```

### 8.4 Tableaux de bord disponibles dans MIKHMON

| Section | Ce que l'admin voit |
|---------|---------------------|
| **PPP Actifs** | Nombre de sessions, durées, IPs |
| **Profils PPP** | Nombre de profils définis |
| **Secrets PPP** | Nombre de comptes clients total |
| **Serveurs PPPoE** | Statut des serveurs actifs |

---

## 9. Utilité pour les utilisateurs (abonnés)

### 9.1 Expérience côté client

Pour l'abonné final, PPPoE est transparent et simple :

**Sur Windows :**
```
Panneau de configuration → Réseau → Nouvelle connexion
→ "Se connecter à Internet" → "Connexion haut débit (PPPoE)"
→ Saisir : Nom d'utilisateur + Mot de passe
→ Se connecter
```

**Sur macOS :**
```
Préférences système → Réseau → [+] Ajouter
→ Interface : PPPoE → Saisir identifiants
```

**Sur routeur domestique (ex: TP-Link, Asus) :**
```
Interface d'administration → WAN → Type de connexion : PPPoE
→ Nom d'utilisateur + Mot de passe
→ Appliquer (le routeur se connecte automatiquement)
```

### 9.2 Avantages pour l'abonné

| Avantage | Explication |
|----------|-------------|
| **Reconnexion automatique** | Si la connexion est perdue, le client PPPoE se reconnecte seul |
| **IP dédiée** | L'abonné peut obtenir une IP fixe (pour serveur, caméra IP...) |
| **Débit garanti** | Le rate-limit assure un débit constant peu importe la charge |
| **Sécurité** | Les identifiants sont chiffrés (CHAP/MS-CHAPv2) |
| **Comptabilité propre** | L'abonné peut vérifier son uptime et sa consommation |

### 9.3 Ce que l'abonné ne voit pas (mais bénéficie)

- La translation NAT (accès Internet depuis IP privée)
- La gestion DNS automatique
- La priorisation du trafic VoIP/streaming
- Les règles de pare-feu qui le protègent

---

## 10. Cas d'usage concrets

### 10.1 ISP résidentiel (Quartier / Ville)

```
Contexte : Opérateur local couvrant 200 maisons
Solution :
  - 1 MikroTik CCR (Cloud Core Router)
  - 200 secrets PPP (un par maison)
  - 3 profils : basique (5M), standard (20M), premium (50M)
  - Pool IP : 10.0.0.2 - 10.0.255.254
  - Facturation mensuelle via MIKHMON

Bénéfice : Contrôle total, facturation automatisée, support client facilité
```

### 10.2 Immeuble de bureaux (multi-tenant)

```
Contexte : 20 entreprises dans un immeuble
Solution :
  - IP statique pour chaque entreprise
  - Profil dédié par entreprise (SLA garanti)
  - Caller-ID lié à la MAC du routeur de chaque bureau
  - VLAN par étage + PPPoE overlay

Bénéfice : Isolation totale des trafics, SLA garanti par contrat
```

### 10.3 Opérateur mobile avec backhaul fibre

```
Contexte : Connexion de BTS (antennes) via fibre
Solution :
  - PPPoE pour chaque lien BTS vers NOC
  - Profil haute priorité avec QoS VoIP
  - Monitoring centralisé via MIKHMON

Bénéfice : Gestion centralisée de tous les liens backhaul
```

---

## 11. Dépannage et erreurs courantes

### 11.1 Problèmes fréquents et solutions

#### Erreur : "Authentication failed"
```
Cause : Mauvais username/password, compte désactivé
Solution :
  /ppp secret print where name="client"
  → Vérifier que disabled=no
  → Vérifier le password exact (sensible à la casse)
```

#### Erreur : "LCP timeout" / "No response"
```
Cause : Interface LAN hors service, câble défectueux
Solution :
  /interface print → Vérifier que ether2 est "running"
  /interface pppoe-server server print → Vérifier le serveur actif
```

#### Erreur : "Maximum sessions reached"
```
Cause : Limite max-sessions atteinte
Solution :
  /interface pppoe-server server set max-sessions=0 (illimité)
  Ou augmenter la valeur selon la capacité du routeur
```

#### Pas d'accès Internet malgré connexion PPPoE établie
```
Cause : NAT manquant ou route par défaut absente
Solution :
  /ip firewall nat print → Vérifier la règle masquerade
  /ip route print → Vérifier la route 0.0.0.0/0
```

#### Client reçoit une IP en dehors du pool attendu
```
Cause : Profil utilise le pool "default" au lieu du pool PPPoE
Solution :
  /ppp profile set [find name="votre-profil"] remote-address=pool-pppoe-clients
```

### 11.2 Outils de diagnostic

```bash
# Tester la connexion d'un client manuellement
/ppp active print where name="client"

# Voir les logs PPP en temps réel
/log follow topics=pppoe,ppp

# Vérifier les statistiques d'interface PPPoE
/interface pppoe-server print stats

# Ping depuis le routeur vers un client PPPoE
/ping 192.168.100.5 src-address=192.168.100.1

# Tracer la route
/tool traceroute 8.8.8.8
```

---

## 12. Bonnes pratiques & Sécurité

### 12.1 Sécuriser le serveur PPPoE

```bash
# 1. Toujours utiliser CHAP ou MS-CHAPv2 (jamais PAP seul)
/interface pppoe-server server set authentication=chap,mschap2

# 2. Activer one-session-per-host
/interface pppoe-server server set one-session-per-host=yes

# 3. Limiter le keepalive pour détecter vite les déconnexions
/interface pppoe-server server set keepalive-timeout=10

# 4. Utiliser des mots de passe forts (minimum 12 caractères)
# Bonne pratique : générer aléatoirement dans MIKHMON

# 5. Désactiver les comptes immédiatement à l'expiration du contrat
/ppp secret set [find name="client-expire"] disabled=yes

# 6. Auditer régulièrement la liste des secrets
/ppp secret print where disabled=no
```

### 12.2 Optimisation des performances

```bash
# Activer FastPath pour PPPoE (RouterOS v7+)
/interface pppoe-server server set max-mtu=1500

# Utiliser des queues simples plutôt que des queues complexes
# pour des performances maximales

# Sur routeurs haute capacité, utiliser HW-offload
/interface ethernet set ether2 hw-offload=yes
```

### 12.3 Plan de sauvegarde

```bash
# Sauvegarder tous les secrets (mensuel)
/ppp secret export file=backup-secrets-$(date)

# Sauvegarder la configuration complète
/system backup save name=backup-complet

# Exporter en texte lisible
/export file=config-export
```

### 12.4 Checklist de déploiement PPPoE

```
Avant mise en production :
  ✅ Pool IP correctement dimensionné (nombre d'abonnés × 1,2)
  ✅ Profils créés et testés
  ✅ NAT masquerade configuré
  ✅ Route par défaut présente
  ✅ Keepalive configuré
  ✅ Pare-feu en place
  ✅ Log PPP activé
  ✅ Un compte test créé et validé
  ✅ Sauvegarde de la config effectuée
  ✅ MIKHMON connecté et synchronisé
```

---

## 13. Commandes CLI de référence

### 13.1 Référence rapide

```bash
# ── Serveur PPPoE ───────────────────────────────────────────
/interface pppoe-server server add    # Créer un serveur
/interface pppoe-server server print  # Lister les serveurs
/interface pppoe-server server enable # Activer un serveur
/interface pppoe-server server disable# Désactiver un serveur
/interface pppoe-server server remove # Supprimer un serveur

# ── Profils PPP ─────────────────────────────────────────────
/ppp profile add name="profil" ...    # Créer un profil
/ppp profile print                    # Lister les profils
/ppp profile set [find name="x"] ...  # Modifier un profil
/ppp profile remove [find name="x"]   # Supprimer un profil

# ── Secrets PPP ─────────────────────────────────────────────
/ppp secret add name="user" ...       # Créer un compte
/ppp secret print                     # Lister les comptes
/ppp secret set [find name="x"] ...   # Modifier un compte
/ppp secret remove [find name="x"]    # Supprimer un compte
/ppp secret print where disabled=yes  # Lister les désactivés

# ── Sessions Actives ────────────────────────────────────────
/ppp active print                     # Voir les sessions actives
/ppp active print detail              # Vue détaillée
/ppp active remove [find name="x"]    # Déconnecter un client

# ── Pool d'adresses ─────────────────────────────────────────
/ip pool add name="pool" ranges=...   # Créer un pool
/ip pool print                        # Lister les pools
/ip pool used print                   # IPs déjà attribuées
```

### 13.2 Scripts utiles

```bash
# Script : Rapport quotidien des sessions actives
:local count [/ppp active count-without-paging]
:log info "Sessions PPPoE actives : $count"

# Script : Désactiver automatiquement les comptes après 30 jours sans connexion
/ppp secret set [find last-logged-out<([:timestamp] - 30d)] disabled=yes

# Script : Alerte si plus de 90% du pool est utilisé
:local used [/ip pool used count-without-paging name=pool-pppoe-clients]
:local total 253
:if ($used > 227) do={
  :log warning "Pool PPPoE > 90% plein : $used/$total IPs utilisées"
}
```

---

## Glossaire

| Terme | Définition |
|-------|------------|
| **PPP** | Point-to-Point Protocol — protocole de liaison point à point |
| **PPPoE** | PPP over Ethernet — PPP encapsulé dans des trames Ethernet |
| **LCP** | Link Control Protocol — négocie les paramètres de liaison PPP |
| **CHAP** | Challenge Handshake Auth Protocol — authentification sécurisée |
| **MS-CHAPv2** | Version Microsoft de CHAP — standard de l'industrie |
| **PAP** | Password Auth Protocol — **déconseillé** (mot de passe en clair) |
| **Rate-limit** | Limitation de débit (upload/download) |
| **Pool IP** | Plage d'adresses IP allouées aux clients PPPoE |
| **MTU** | Maximum Transmission Unit — taille max d'un paquet |
| **NAT** | Network Address Translation — masquerade du réseau privé |
| **RADIUS** | Serveur d'authentification centralisé (extensions MIKHMON) |
| **Caller-ID** | Identifiant de l'interface ou MAC d'origine du client |
| **Keepalive** | Signal périodique pour détecter les sessions mortes |
| **one-session-per-host** | Un seul compte actif par équipement physique |

---

## Ressources

- **Documentation officielle MikroTik** : [wiki.mikrotik.com/wiki/PPPoE](https://wiki.mikrotik.com/wiki/PPPoE)
- **RFC 2516** — A Method for Transmitting PPP Over Ethernet (PPPoE)
- **RFC 1661** — The Point-to-Point Protocol (PPP)
- **MIKHMON** : Interface de gestion avancée pour MikroTik Hotspot & PPPoE

---

*Document rédigé par SafeLink Africa — MIKHMON Documentation Technique*
*Version 1.0 — Mai 2026*
*Ce document est la propriété de SafeLink Africa. Toute reproduction doit mentionner la source.*
