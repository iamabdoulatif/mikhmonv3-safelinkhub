# Documentation PPPoE - MikroTik RB951

Date : 27 mai 2026
Routeur : MikroTik RB951Ui-2HnD
Adresse de gestion locale : 192.168.1.64
Session Mikhmon locale : RB951
Version RouterOS observee : 7.16.1 stable

## 1. Objectif

Ce document explique la configuration PPPoE creee sur le MikroTik RB951 et la maniere de la tester avec un routeur client, un ordinateur ou un iPhone connecte derriere un routeur PPPoE.

Le PPPoE permet de fournir un acces Internet par identifiant et mot de passe. Il est utile pour les abonnements clients, les boutiques, les maisons, les routeurs clients et les installations ou l'on veut separer chaque client avec une session dediee.

## 2. Configuration creee sur le MikroTik

Les elements suivants ont ete ajoutes sur le RB951 :

- Pool PPPoE : MIKHMON-PPPOE-POOL
- Plage IP clients : 172.31.95.10-172.31.95.254
- Profil PPP : MIKHMON-PPPOE
- Adresse locale du routeur PPPoE : 172.31.95.1
- DNS fournis aux clients : 10.0.0.1 et 8.8.8.8
- Serveur PPPoE : SafeLinkHub-PPPoE
- Interface du serveur PPPoE : HOTSPOT
- NAT PPPoE : 172.31.95.0/24 vers E1-WAN-FAI
- Compte de test : pppoe-test / test12345

Le serveur PPPoE a ete place sur le bridge HOTSPOT parce que ce bridge regroupe les ports clients du RB951. Il ne faut pas le placer sur le WAN, sinon les clients internes ne pourront pas l'utiliser correctement.

## 3. Commandes RouterOS equivalentes

Les commandes suivantes representent la configuration appliquee :

```routeros
/ip pool add name=MIKHMON-PPPOE-POOL ranges=172.31.95.10-172.31.95.254 comment="MIKHMON PPPoE pool"

/ppp profile add name=MIKHMON-PPPOE local-address=172.31.95.1 remote-address=MIKHMON-PPPOE-POOL dns-server=10.0.0.1,8.8.8.8 only-one=yes change-tcp-mss=yes use-ipv6=no use-mpls=no use-compression=no use-encryption=no use-upnp=no comment="MIKHMON PPPoE default profile"

/interface pppoe-server server add service-name=SafeLinkHub-PPPoE interface=HOTSPOT default-profile=MIKHMON-PPPOE authentication=pap,chap,mschap1,mschap2 one-session-per-host=yes max-mtu=1480 max-mru=1480 disabled=no comment="MIKHMON PPPoE server on HOTSPOT bridge"

/ppp secret add name=pppoe-test password=test12345 service=pppoe profile=MIKHMON-PPPOE comment="MIKHMON PPPoE test account" disabled=no

/ip firewall nat add chain=srcnat src-address=172.31.95.0/24 out-interface=E1-WAN-FAI action=masquerade comment="MIKHMON PPPoE NAT"
```

## 4. Comment tester le PPPoE avec un routeur client

C'est la methode la plus fiable.

1. Brancher le port WAN du routeur client sur un port client du RB951, par exemple ether4.
2. Ouvrir la page de configuration du routeur client.
3. Aller dans Internet, WAN ou Network selon le modele.
4. Choisir le type de connexion PPPoE.
5. Renseigner :
   - Nom utilisateur : pppoe-test
   - Mot de passe : test12345
   - Service name : vide, ou SafeLinkHub-PPPoE si le routeur le demande
6. Enregistrer et connecter.
7. Le routeur client doit recevoir une adresse 172.31.95.x.
8. Connecter l'iPhone au Wi-Fi du routeur client.
9. Tester Internet sur l'iPhone.

Schema :

```text
iPhone -> Wi-Fi du routeur client -> PPPoE -> RB951 -> NAT -> Internet
```

## 5. Comment tester depuis un ordinateur

Un ordinateur avec Ethernet peut aussi tester le PPPoE.

### Sur Windows

1. Brancher le PC en Ethernet sur un port client du RB951.
2. Aller dans Parametres reseau.
3. Ajouter une connexion haut debit PPPoE.
4. Entrer :
   - utilisateur : pppoe-test
   - mot de passe : test12345
5. Connecter.

### Sur macOS

1. Brancher le Mac en Ethernet sur le RB951.
2. Aller dans Reglages Systeme, Reseau.
3. Ajouter une interface PPPoE si disponible selon la version de macOS.
4. Entrer pppoe-test et test12345.
5. Connecter.

Si macOS ne propose pas clairement PPPoE, utiliser plutot un routeur client. C'est plus proche du cas reel.

## 6. Verification dans Winbox

Apres une connexion PPPoE reussie :

1. Ouvrir Winbox.
2. Se connecter au MikroTik RB951.
3. Aller dans PPP.
4. Ouvrir l'onglet Active Connections.
5. Verifier que pppoe-test apparait.
6. Verifier que l'adresse attribuee est dans 172.31.95.x.

Autres emplacements utiles :

- PPP > Secrets : liste des comptes PPPoE.
- PPP > Profiles : profils de debit, IP et DNS.
- PPP > Active Connections : clients connectes.
- Interfaces > PPPoE Server : serveur SafeLinkHub-PPPoE.
- IP > Firewall > NAT : regle MIKHMON PPPoE NAT.

## 7. Verification par terminal RouterOS

Utiliser ces commandes dans le terminal MikroTik :

```routeros
/interface pppoe-server server print
/ppp profile print
/ppp secret print
/ppp active print
/ip pool print
/ip firewall nat print where comment="MIKHMON PPPoE NAT"
```

Pour voir les clients connectes :

```routeros
/ppp active print
```

Pour deconnecter un client PPPoE actif :

```routeros
/ppp active remove [find where name="pppoe-test"]
```

## 8. Integration avec Mikhmon

Dans Mikhmon local :

```text
http://localhost:8888/mikhmon/?session=RB951
```

La section PPPoE doit permettre de voir et gerer :

- les profils PPP ;
- les secrets PPP ;
- les serveurs PPPoE ;
- les connexions PPP actives.

Pour creer un client PPPoE depuis Mikhmon, creer un secret PPP avec :

- service : pppoe
- profile : MIKHMON-PPPOE
- username : nom du client
- password : mot de passe du client

Le client utilise ensuite ces identifiants sur son routeur ou son CPE.

## 9. Importance du PPPoE

Le PPPoE est important pour les raisons suivantes :

- Authentification claire : chaque client a son nom d'utilisateur et son mot de passe.
- Sessions controlees : le MikroTik sait qui est connecte et depuis quand.
- Isolation des clients : chaque client recoit une session et une IP dediees.
- Gestion d'abonnements : ideal pour les clients mensuels, hebdomadaires ou residentiels.
- Coupure simple : il suffit de desactiver ou supprimer le secret PPP.
- Suivi technique : les connexions actives sont visibles dans PPP > Active Connections.
- Meilleure organisation que le Hotspot pour les clients fixes.

Le Hotspot reste utile pour les tickets courts, les clients de passage, les cybercafes, hotels, restaurants ou lieux publics. Le PPPoE est plus adapte aux clients reguliers avec routeur personnel.

## 10. Points de depannage

### Le client ne voit pas le serveur PPPoE

- Verifier que le cable du routeur client est branche sur un port du bridge HOTSPOT.
- Verifier que le serveur PPPoE est active.
- Verifier l'interface du serveur : HOTSPOT.

Commande :

```routeros
/interface pppoe-server server print
```

### Le client s'authentifie mais n'a pas Internet

- Verifier la regle NAT PPPoE.
- Verifier la route Internet du RB951.
- Verifier les DNS.

Commandes :

```routeros
/ip firewall nat print where comment="MIKHMON PPPoE NAT"
/ip route print where dst-address=0.0.0.0/0
/ip dns print
```

### Le mot de passe est refuse

- Verifier le secret PPP.
- Verifier que le service est pppoe.
- Verifier que le compte n'est pas desactive.

Commande :

```routeros
/ppp secret print where name="pppoe-test"
```

### L'iPhone ne peut pas se connecter directement

L'iPhone ne supporte pas le PPPoE direct comme interface WAN. Il faut utiliser un routeur client PPPoE, puis connecter l'iPhone au Wi-Fi de ce routeur client.

## 11. Recommandations

- Garder le compte pppoe-test uniquement pour les tests.
- Creer un compte PPPoE different pour chaque client.
- Utiliser des mots de passe forts.
- Desactiver ou supprimer les comptes inutilises.
- Eviter d'activer PPPoE sur l'interface WAN.
- Sur RB951, eviter trop de clients PPPoE simultanes, car le processeur est limite.
- Si le nombre de clients augmente, passer a un routeur MikroTik plus puissant.

## 12. Resume rapide

Le PPPoE est maintenant pret sur le RB951. Pour tester rapidement, il faut un routeur client configure en PPPoE avec pppoe-test / test12345, branche sur un port client du RB951. Si la connexion est correcte, le client apparaitra dans PPP > Active Connections avec une IP 172.31.95.x.
