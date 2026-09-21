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
| smtp_quota | quota SMTP journalier atteint | ok | error | ok | DÉTECTÉ | Quota SMTP journalier dépassé : 89/1 emails envoyés aujourd'hui (8900%). → Que faire : Les emails suivants ris |
| cron_triggered | aucun passage du déclencheur visiteurs | ok | warning | ok | DÉTECTÉ | Dernier déclenchement il y a 30 jour(s) — trafic front très faible |
| alert_email_invalid | adresse d'alerte invalide et e-mail boutique invalide | ok | warning | ok | DÉTECTÉ | Aucune adresse email d'alerte Watchdog valide (NERIA_ALERT_EMAIL et l'email boutique sont tous deux invalides  |
| open_rate_7d | 60 envois en 7 jours, aucune ouverture | error | error | error | DÉTECTÉ | Taux d'ouverture 7j : 0% (0/60). → Que faire : Vérifiez le score de réputation dans l'onglet Statistiques, tes |
| bounce_rate | 30 envois en 24 h et 10 rebonds actifs | ok | error | ok | DÉTECTÉ | Taux de bounce : 33.3% sur 24h (10 bounces / 30 envois). → Que faire : Nettoyez votre liste d'abonnés, vérifie |
| queue_failed_rate | 50 % d'échecs sur 30 jours | ok | error | ok | DÉTECTÉ | Taux d'échec critique de la file d'attente comportementale : 50% (6/12 sur 30 jours) → Que faire : vérifiez la |
| click_rate_7d | ouvertures sans aucun clic | ok | warning | ok | DÉTECTÉ | 10 ouvertures enregistrées ces 7 derniers jours, mais aucun clic. → Que faire : Vérifiez que track.php est acc |
| unsubscribe_spike | 110 envois et 10 désabonnements en 7 jours | ok | error | ok | DÉTECTÉ | Pic de désabonnements : 9.09% sur 7j (10 / 110 envois). Seuil critique dépassé (> 0,5%). → Que faire : Examine |
| send_volume_spike | 100 envois aujourd'hui contre quelques-uns les jours précédents | warning | error | warning | DÉTECTÉ | Pic d'envoi critique : 100 emails aujourd'hui vs moyenne 7j de 10 (×10.0). → Que faire : Vérifiez immédiatemen |
| history_table_size | plus de 50 000 lignes d'historique de traduction | ok | warning | ok | DÉTECTÉ | L'historique des traductions contient 50100 entrées — taille importante. → Que faire : Nettoyez les entrées an |
| behavioral_dedup | plus de 50 000 réservations comportementales | ok | warning | ok | DÉTECTÉ | La table neria_behavioral_sent contient 50161 lignes. Croissance normale mais surveillée (seuil : 50 000). |
| smtp_config | envoi par la fonction mail() de PHP | ok | warning | ok | DÉTECTÉ | Envoi via PHP mail() (méthode basique). → Que faire : Configurez un serveur SMTP dédié dans Paramètres → Email |
| version_sync | version installée plus ancienne que le code | ok | warning | ok | DÉTECTÉ | Version installée (1.0.1) < version module (1.0.48). Un upgrade script n'a peut-être pas tourné. → Que faire : |
| stored_secrets_decryptable | secret chiffré illisible (clé DeepL) | ok | error | ok | DÉTECTÉ | Secret(s) chiffré(s) illisible(s) avec la clé actuelle : NERIA_DEEPL_KEY → Que faire : la clé de chiffrement a |
| crypto_key | clé de chiffrement absente (contrôle de présence) | ok | error | ok | DÉTECTÉ | Clé de chiffrement absente (NERIA_ENCRYPTION_KEY) — toutes les données chiffrées en base sont illisibles. → Qu |
| php_memory_limit | memory_limit PHP à 60 Mo (lancement du processus) | ok | error | ok | DÉTECTÉ | Mémoire PHP insuffisante : 60 MB (minimum requis : 128 MB). → Que faire : Augmentez memory_limit dans php.ini  |
| abtest_variant_pair | test A/B actif avec une seule variante | ok | warning | ok | DÉTECTÉ | Test(s) A/B actif(s) sans paire complète de variantes : order_conf (1 variante(s)) → Que faire : vérifiez qu'A |
| churn_propensity_freshness | scores de désabonnement calculés il y a 5 jours | ok | warning | ok | DÉTECTÉ | Score de risque de désabonnement figé depuis 120h — le cron comportemental a peut-être échoué avant de le reca |
| orphaned_voucher_reservations | réservation de bon d'anniversaire sans bon créé depuis plus de 24 h | ok | warning | ok | DÉTECTÉ | 1 réservation(s) de bon de réduction bloquée(s) (échec de génération, plus de 24h) supprimée(s) automatiquemen |
| sent_reconciliation | installé depuis 10 jours, aucun envoi enregistré | ok | warning | ok | DÉTECTÉ | 0 envois enregistrés depuis 10 jour(s) — vérifier que des emails ont bien été envoyés |
| template_staleness | modèle envoyé régulièrement puis plus rien depuis 30 jours | warning | warning | warning | DÉTECTÉ | 1 template(s) envoyaient régulièrement des emails jusqu'ici mais n'en ont envoyé aucun sur les 30 derniers jou |
| engagement_trend | taux d'ouverture divisé par plus de deux d'une période à l'autre | ok | warning | ok | DÉTECTÉ | Tendance d'engagement en baisse : 0% cette semaine vs 50% en moyenne sur les 30 jours précédents (-100% relati |
| milestone_voucher_cartrule | bon de palier dont la règle panier a disparu | ok | warning | ok | DÉTECTÉ | 1 bon(s) de palier référence(nt) un CartRule inexistant/inactif : 1379 (cart_rule#99999999) → Que faire : ces  |
| collection_look_products | collection dont les produits n'existent plus | ok | warning | ok | DÉTECTÉ | 1 règle(s) Collection/Complétez votre look active(s) référence(nt) des produits supprimés ou désactivés : P8D  |
| orphaned_waitlist_claims | réservation liste d'attente commencée il y a 3 h sans envoi | ok | warning | ok | DÉTECTÉ | 1 réclamation(s) de notification liste d'attente bloquée(s) (échec d'envoi, plus d'1h) libérée(s) automatiquem |
| loyalty_integrity | solde de points de fidélité négatif | ok | error | ok | DÉTECTÉ | 1 client(s) avec un solde de points de fidélité négatif. → Que faire : Vérifiez la logique de déduction des po |
| campaign_empty_seg | campagne saisonnière ciblant un segment vide | ok | warning | ok | DÉTECTÉ | 1 campagne(s) active(s) ciblant un segment vide : "P8D" (segment : 0). → Que faire : Recalculez les segments o |
| residual_vars_recent | e-mails récents envoyés avec une variable de contenu manquante | warning | warning | warning | DÉTECTÉ | 43 email(s) envoyé(s) ces 7 derniers jours avaient une variable de contenu manquante (nettoyée automatiquement |
| hardcoded_date_format | date('d/m/Y') codé en dur (faux module) | ok | warning | ok | DÉTECTÉ | 1 date(s) codée(s) en dur (d/m/Y) détectée(s) hors NeriaTools::formatDate() : src/Bad.php:2 → Que faire : un c |
| hardcoded_decimal_format | number_format avec virgule décimale codée en dur (faux module) | ok | warning | ok | DÉTECTÉ | 1 formatage(s) décimal(aux) codé(s) en dur détecté(s) : src/Bad.php:2 → Que faire : remplacez number_format($x |
| rtl_hardcoded_align | text-align:left codé en dur dans un e-mail (faux module) | ok | warning | ok | DÉTECTÉ | 1 text-align/float codé(s) en dur détecté(s) dans les templates email : t/core/bad.html:1 → Que faire : casse  |
| chained_str_replace | str_replace(array_keys(...)) enchaîné (faux module) | ok | warning | ok | DÉTECTÉ | 1 substitution(s) de variables par str_replace(array_keys(...)) détectée(s) : src/Bad.php:2 → Que faire : remp |
| imap_timeout_missing | imap_open() sans imap_timeout() (faux module) | ok | warning | ok | DÉTECTÉ | 1 appel(s) imap_open() sans imap_timeout() détecté(s) : src/Bad.php:2 → Que faire : un serveur IMAP lent/injoi |
| unescaped_like_metachars | LIKE construit avec une variable non échappée (faux module) | ok | warning | ok | DÉTECTÉ | Requête LIKE avec métacaractères % ou _ non échappés détectée : Bad.php : LIKE '%$term%' sans échappement des  |
| fragile_neriaconfig_usage | gabarit lisant neriaConfig.adminUrl (faux module) | ok | warning | ok | DÉTECTÉ | Usage fragile de neriaConfig.adminUrl/moduleName détecté (bloqué par le CSP BO, bouton sans effet) : bad.tpl. |
| dev_tool_residue | référence à mailpit (outil de développement) laissée dans un gabarit (faux module) | ok | warning | ok | DÉTECTÉ | Trace(s) d'outil de développement détectée(s) dans les fichiers livrés : bad.tpl (mailpit). |
| cron_strict_date_equality | DATE(colonne) = CURDATE() dans un cron (faux module) | ok | warning | ok | DÉTECTÉ | 1 requête(s) cron comparent une date de déclenchement par égalité stricte (DATE(col) = ...) au lieu d'une plag |
| tpl_js_escape_missing | variable Smarty non échappée dans un getElementById (faux module) | ok | warning | ok | DÉTECTÉ | 1 interpolation(s) JS non échappée(s) détectée(s) : bad.tpl:1 → Que faire : une valeur contenant une apostroph |
