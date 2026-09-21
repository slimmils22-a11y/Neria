# P8d — injection de pannes sur les contrôles du Watchdog

Panne provoquée dans une transaction annulée ; le contrôle doit changer de statut.

| Contrôle | Panne provoquée | Avant | Après | Restauré | Résultat | Détail |
|---|---|---|---|---|---|---|
| json_config_integrity | NERIA_LOYALTY_TIERS = JSON invalide | ok | warning | ok | DÉTECTÉ | Configuration(s) JSON corrompue(s), retombée silencieuse sur les valeurs par défaut : NERIA_LOYALTY_TIERS → Qu |
| multi_sender_json | NERIA_SENDERS_JSON = JSON invalide | ok | warning | ok | DÉTECTÉ | Configuration multi-expéditeur corrompue réinitialisée automatiquement — tous les emails repartent avec l'expé |
| all_email_crons_disabled | tous les crons d'e-mail désactivés | ok | error | ok | DÉTECTÉ | Tous les crons email sont désactivés — aucun email comportemental ne sera envoyé. Réactivez au moins un cron d |
| crypto_key_health | clé de chiffrement absente | ok | error | ok | DÉTECTÉ | Clé de chiffrement absente (NERIA_ENCRYPTION_KEY) — toutes les données chiffrées en base sont illisibles. → Qu |
| webhook_failures | webhook en échec récent | ok | warning | ok | DÉTECTÉ | 1 webhook(s) en échec remis en file et retraité(s) automatiquement, plutôt que de rester en échec permanent. |
| bounces_unprocessed | rebond actif récent sans cron de traitement | ok | warning | ok | DÉTECTÉ | 1 bounce(s) reçu(s) dans les 48h mais cron IMAP jamais exécuté. → Que faire : Vérifiez la configuration IMAP d |
| queue_overflow | plus de 1000 e-mails en attente | ok | warning | ok | DÉTECTÉ | 1100 emails en attente dans la file (seuil d'attention : 1 000). → Que faire : Surveillez l'évolution ; si la  |
| queue_blocked | e-mail en attente depuis plus de 2 h | ok | warning | ok | DÉTECTÉ | File d'envoi bloquée débloquée automatiquement — 1 email(s) traité(s) immédiatement plutôt que d'attendre le p |
| abtest_stuck | test A/B actif depuis plus de 30 jours | ok | warning | ok | DÉTECTÉ | 1 A/B test(s) actif(s) depuis plus de 30 jours sans gagnant déclaré. → Que faire : Consultez l'onglet Statisti |
| consecutive_failures | 3 échecs consécutifs | ok | error | ok | DÉTECTÉ | 3 échecs de rendu consécutifs détectés. → Que faire : Consultez le journal Watchdog pour identifier le templat |
| monthly_report_cfg | rapport mensuel activé sans destinataire ni e-mail boutique | ok | warning | ok | DÉTECTÉ | Le rapport mensuel est activé mais aucun email destinataire n'est configuré. → Que faire : Renseignez l'adress |
| smtp_quota | quota SMTP journalier atteint | ok | error | ok | DÉTECTÉ | Quota SMTP journalier dépassé : 81/1 emails envoyés aujourd'hui (8100%). → Que faire : Les emails suivants ris |
| cron_triggered | aucun passage du déclencheur visiteurs | ok | warning | ok | DÉTECTÉ | Dernier déclenchement il y a 30 jour(s) — trafic front très faible |
| alert_email_invalid | adresse d'alerte invalide et e-mail boutique invalide | ok | warning | ok | DÉTECTÉ | Aucune adresse email d'alerte Watchdog valide (NERIA_ALERT_EMAIL et l'email boutique sont tous deux invalides  |
