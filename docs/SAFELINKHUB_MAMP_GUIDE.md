# Guide SafeLinkHub + Mikhmon (MAMP)

Ce guide explique l'intégration entre:
- **SafeLinkHub**: génération de clé API + Webhooks
- **Mikhmon sur MAMP**: réception webhook + test API

---

## 1) Prérequis

- MAMP démarré (`Apache` actif)
- Mikhmon accessible, par exemple: `http://localhost:8888/mikhmon`
- Compte connecté sur `https://safelinkhub.io`
- Droits admin Mikhmon

---

## 2) Côté SafeLinkHub (www.safelinkhub.io)

### 2.1 Créer la clé API
1. Ouvrez: `Dashboard > Developer`
2. Section **Clés API** > **Nouvelle clé**
3. Renseignez:
   - Nom (ex: `mikhmon-mamp`)
   - Permissions (ex: `read` ou `full`)
   - Rate limit
4. Copiez la clé `sla_...` immédiatement

### 2.2 Créer le webhook
1. Toujours dans `Dashboard > Developer`
2. Section **Webhooks** > **Nouveau webhook**
3. URL webhook: collez l'URL fournie par Mikhmon (voir section 3.1)
4. Événements: cochez ceux à recevoir (ex: `payment.success`, `ticket.created`)
5. Définissez/stockez le **secret webhook**

---

## 3) Côté Mikhmon (MAMP)

### 3.1 Ouvrir la page d'intégration
1. Ouvrez Mikhmon
2. Menu gauche > **SafeLink API**
3. Copiez l'URL affichée:
   - Exemple local: `http://localhost:8888/mikhmon/process/safelink_webhook.php`

### 3.2 Configurer
Dans **Configuration API / Webhook**:
- Base API SafeLink: `https://safelinkhub.io`
- Clé API: `sla_...`
- Secret Webhook: le même que sur SafeLinkHub
- URL webhook de test: optionnel (ex: `https://webhook.site/xxxx`)
- Cochez `Activer l'intégration SafeLink`
- Cliquez **Enregistrer**

### 3.3 Tester
- Bouton **Tester la clé API**
- Bouton **Envoyer un webhook test**

---

## 4) Vérifier les événements reçus

Les webhooks entrants sont journalisés ici:

`/Applications/MAMP/htdocs/mikhmon/logs/safelink_webhooks.jsonl`

Commande utile:

```bash
tail -f /Applications/MAMP/htdocs/mikhmon/logs/safelink_webhooks.jsonl
```

---

## 5) Important: localhost n'est pas public

SafeLinkHub (serveur externe) ne peut pas appeler directement `localhost`.

Pour les tests réels de webhooks, utilisez un tunnel:
- Cloudflare Tunnel
- ngrok

Puis remplacez l'URL webhook SafeLinkHub par votre URL publique (tunnel) qui pointe vers:

`/mikhmon/process/safelink_webhook.php`

---

## 6) Sécurité recommandée

- Ne jamais exposer la clé API côté front/public
- Utiliser un secret webhook long et unique
- Vérifier la signature webhook (déjà pris en charge côté endpoint Mikhmon)
- Rotation régulière des clés API

---

## 7) Dépannage rapide

### Erreur test API HTTP 401/403
- Vérifiez la clé `sla_...`
- Vérifiez que la clé est active dans SafeLinkHub

### Aucun webhook reçu
- Vérifiez URL webhook
- Vérifiez que le tunnel est actif (si local)
- Vérifiez le secret webhook identique des deux côtés

### Statut webhook HTTP 500
- Ouvrez le log MAMP/Apache
- Vérifiez permissions d'écriture sur `logs/`

---

## 8) Flux recommandé en production

1. Créer clé API SafeLinkHub
2. Déployer Mikhmon derrière une URL HTTPS publique
3. Configurer webhook + secret
4. Tester un événement de paiement
5. Vérifier logs et traitement métier

