# P8b — exécution des actions du back-office (POST, paramètres vides sauf spécification)

Langue : fr · actions : 167 · S=29 · I=138

| Action | Niveau | Bannière / sortie | Effet mesuré | Note |
|---|---|---|---|---|
| activate_license | S |  |  | non lancée : serveur de licences |
| add_blacklist | I | Impossible d'effectuer cette action sur la liste noire. |  |  |
| add_calendar_event | I |  | {"neria_log":1} |  |
| add_manual_bounce | I |  | {"neria_log":1} |  |
| apply_abtest_winner | I |  | {"neria_log":1} |  |
| auto_force_run | S |  |  | non lancée : exécute les automatisations (envois) |
| auto_toggle | I |  | NERIA_GHOST_CART_ENABLED [] |  |
| auto_translate_template | S |  |  | non lancée : API DeepL |
| auto_translate_variant_b | S |  |  | non lancée : API DeepL |
| bounce_cross_shop_toggle | I |  | NERIA_BOUNCE_CROSS_SHOP_ENABLED [] |  |
| cert_delete | I |  | {"neria_log":1} |  |
| cert_download | I |  |  |  |
| cert_issue | I |  | {"neria_log":1} |  |
| cert_save_config | I | Configuration du certificat enregistrée. | NERIA_CERT_ENABLED,NERIA_CERT_SERIAL_PREFIX,NERIA_CERT_TITLE,NERIA_CERT_SUBTITLE,NERIA_CERT_BODY,NERIA_CERT_QR_ENABLED,NERIA_CERT_QR_URL [] |  |
| check_anniversary_guard | I |  |  |  |
| check_cooldown_guest_notice | I |  |  |  |
| check_preferences_guard | I |  |  |  |
| check_send_duplicate | I |  |  | sortie directe (redirection/exit) sans effet mesuré avec ces paramètres |
| check_voice_profile | S |  |  | non lancée : API externe |
| checkout_abandonment_toggle | I |  | NERIA_CHECKOUT_ABANDONMENT_ENABLED [] |  |
| clear_logs | I | Journal Watchdog vidé. | {"neria_log":-508} |  |
| collection_add | I |  |  | sortie directe (redirection/exit) sans effet mesuré avec ces paramètres |
| collection_completion_toggle | I |  | NERIA_COLLECTION_COMPLETION_ENABLED [] |  |
| collection_delete | I |  | {"neria_collection":-1,"neria_collection_sent":-1} |  |
| collection_toggle | I |  |  | sortie directe (redirection/exit) sans effet mesuré avec ces paramètres |
| connect_postmaster | S |  |  | non lancée : redirection Google |
| connect_searchconsole | S |  |  | non lancée : redirection Google |
| create_abtest | I |  | {"neria_log":1} |  |
| cron_toggle | I | Fonctionnalité désactivée. | NERIA_CRON_ENABLED [] |  |
| customer_autocomplete | I |  |  | sortie directe (redirection/exit) sans effet mesuré avec ces paramètres |
| deactivate_abtest | I |  | {"neria_log":1} |  |
| delete_bounce | I |  | {"neria_log":1} |  |
| delete_calendar_event | I |  | {"neria_log":1} |  |
| delete_history | I |  | {"neria_log":1} |  |
| delete_seasonal_campaign | I |  | {"neria_log":1} |  |
| deliverability_score | S |  |  | non lancée : requêtes DNS externes |
| disconnect_postmaster | I | Compte Google déconnecté. | NERIA_POSTMASTER_REFRESH_TOKEN,NERIA_POSTMASTER_ACCESS_TOKEN,NERIA_POSTMASTER_TOKEN_EXPIRY [] |  |
| disconnect_searchconsole | I | Search Console déconnecté. | NERIA_SC_REFRESH_TOKEN,NERIA_SC_ACCESS_TOKEN,NERIA_SC_TOKEN_EXPIRY [] |  |
| dismiss_design_wizard | I |  | NERIA_DESIGN_WIZARD_SEEN [] |  |
| export_translations_csv | I |  |  |  |
| export_variant_b_csv | I |  |  |  |
| gdpr_encrypt_all | S |  |  | non lancée : chiffre toutes les données |
| gdpr_pdf | I |  |  |  |
| gdpr_purge | I |  | {"neria_log":1} |  |
| generate_signature | I | Veuillez renseigner le nom du fondateur dans « Variables personnalisées » avant de générer la signature. |  |  |
| ghost_cart_toggle | I |  | NERIA_GHOST_CART_ENABLED [] |  |
| health_pixel_test | S |  |  | non lancée : requête HTTP |
| ignore_bounce | I |  | {"neria_log":1} |  |
| import_translations_csv | I | Aucun fichier CSV valide reçu. |  |  |
| import_variant_b_csv | I | Aucun fichier CSV valide reçu pour la Variante B. |  |  |
| lifespan_add | I | Veuillez renseigner un produit et une durée valides. |  |  |
| lifespan_delete | I |  | {"neria_log":1} |  |
| lifespan_toggle | I |  | NERIA_LIFESPAN_ENABLED [] |  |
| load_translations | I |  | {"neria_log":1} |  |
| look_completion_toggle | I |  | NERIA_LOOK_COMPLETION_ENABLED [] |  |
| look_rule_add | I |  |  | sortie directe (redirection/exit) sans effet mesuré avec ces paramètres |
| look_rule_delete | I |  |  | sortie directe (redirection/exit) sans effet mesuré avec ces paramètres |
| look_rule_toggle | I |  | {"neria_look_rule":1} |  |
| loyalty_cross_shop_toggle | I |  | NERIA_LOYALTY_CROSS_SHOP_ENABLED [] |  |
| loyalty_toggle | I |  | NERIA_LOYALTY_ENABLED [] |  |
| menu_visibility_bulk | I |  | NERIA_MENU_HIDDEN_ITEMS [] |  |
| menu_visibility_toggle | I |  | NERIA_MENU_HIDDEN_ITEMS [] |  |
| multipreview_poll_eoa | I |  |  | sortie directe (redirection/exit) sans effet mesuré avec ces paramètres |
| multipreview_poll_litmus | I |  |  | sortie directe (redirection/exit) sans effet mesuré avec ces paramètres |
| multipreview_render | I | Prévisualisation générée pour 15 client(s). |  |  |
| multipreview_submit_eoa | I |  |  | sortie directe (redirection/exit) sans effet mesuré avec ces paramètres |
| multipreview_submit_litmus | I |  |  | sortie directe (redirection/exit) sans effet mesuré avec ces paramètres |
| preview | I |  |  | sortie directe (redirection/exit) sans effet mesuré avec ces paramètres |
| preview_manual | I |  |  | sortie directe (redirection/exit) sans effet mesuré avec ces paramètres |
| preview_signature | I |  |  | sortie directe (redirection/exit) sans effet mesuré avec ces paramètres |
| process_queue_now | S |  |  | non lancée : envoie la file d'e-mails |
| process_webhook_queue_now | S |  |  | non lancée : appelle les webhooks |
| product_search | I |  |  |  |
| propensity_toggle | I |  | NERIA_PROPENSITY_ENABLED [] |  |
| purchase_window_toggle | I |  | NERIA_PURCHASE_WINDOW_ENABLED [] |  |
| quote_add | I | Client, référence et date d'expiration sont requis. |  |  |
| quote_delete | I |  | {"neria_log":1} |  |
| quote_mark_lost | I |  | {"neria_log":1} |  |
| quote_mark_won | I |  | {"neria_log":1} |  |
| quote_reminder_toggle | I |  | NERIA_QUOTE_REMINDERS_ENABLED [] |  |
| reactivate_bounce | I |  | {"neria_log":1} |  |
| recompute_churn | I | 33 score(s) de désabonnement recalculé(s). | NERIA_CHURN_LAST_RUN {"neria_log":1} |  |
| recompute_segments | I | 34 client(s) mis à jour. |  |  |
| reconciliation_toggle | I |  | NERIA_REFUND_RECONCILIATION_ENABLED [] |  |
| refresh_domain_reputation | S |  |  | non lancée : requêtes DNS/DoH externes |
| refresh_pagespeed | S |  |  | non lancée : API Google |
| refresh_postmaster | S |  |  | non lancée : API Google |
| refresh_searchconsole | S |  |  | non lancée : API Google |
| refresh_seo_api | S |  |  | non lancée : API SEO externe |
| regenerate_cron_token | I | Token cron régénéré. Mettez à jour votre tâche planifiée avec la nouvelle URL. | NERIA_CRON_TOKEN [] |  |
| regenerate_emergency_token | I | Token régénéré. Mettez à jour votre signet avec la nouvelle URL. | NERIA_EMERGENCY_TOKEN [] |  |
| relationship_anniversary_toggle | I |  | NERIA_RELATIONSHIP_ANNIVERSARY_ENABLED [] |  |
| reload_all_translations | I | Traductions par défaut rechargées depuis le fichier source (vos personnalisations sont conservées). |  |  |
| remove_blacklist | I | Impossible d'effectuer cette action sur la liste noire. |  |  |
| repair_bounces_check | I | Échec de la vérification de la boîte de rejets : Bounce checker désactivé. | NERIA_CRON_LAST_BOUNCES [] |  |
| repair_module_version | S |  |  | non lancée : rejoue les scripts d'upgrade (DDL : commit implicite, non annulable) |
| reset_all_data | S |  |  | non lancée : détruit toutes les données |
| reset_all_translations | S |  |  | non lancée : détruit les traductions |
| reset_blacklist | I | Liste noire réinitialisée. | {"neria_blacklist":-1} |  |
| reset_design | I | Sauvegardé avec succès |  |  |
| reset_template | I |  | {"neria_log":1} |  |
| reset_template_all_langs | I |  | {"neria_log":1} |  |
| reset_time_greetings_all | I | Salutations réinitialisées aux valeurs par défaut (toutes les langues). |  |  |
| reset_time_greetings_lang | I | Langue invalide. |  |  |
| reset_variant_b | I |  | {"neria_log":-8} |  |
| restore_translation | I |  | {"neria_log":1} |  |
| restore_variant_b | I |  | {"neria_log":1} |  |
| retry_webhook | S |  |  | non lancée : appel webhook |
| run_bounce_check | S |  |  | non lancée : lecture IMAP externe |
| run_code_diagnostic | I | Scan de code terminé — des anomalies ont été détectées, voir le détail ci-dessous. | {"neria_log":1} |  |
| run_full_diagnostic | I | Diagnostic complet exécuté — résultats mis à jour. | NERIA_HEALTH_LAST_RUN,NERIA_HEALTH_RESULTS,NERIA_KRG_LAST_RUN {"neria_log":7} |  |
| save_alert_config | I | Configuration des alertes enregistrée. | NERIA_ALERT_IMMEDIATE_ENABLED [] |  |
| save_archive_config | I | Configuration du Témoin silencieux enregistrée. | NERIA_ARCHIVE_EMAIL {"neria_log":1} |  |
| save_birthday_voucher | I | Sauvegardé avec succès |  |  |
| save_bounce_config | I | Configuration des bounces enregistrée. | NERIA_BOUNCE_ENABLED,NERIA_BOUNCE_IMAP_HOST,NERIA_BOUNCE_IMAP_PORT,NERIA_BOUNCE_IMAP_USER,NERIA_BOUNCE_IMAP_SSL,NERIA_BOUNCE_IMAP_FOLDER,NERIA_BOUNCE_SOFT_THRESHOLD [] |  |
| save_calendar_event | I |  | {"neria_log":1} |  |
| save_carbon | I | Sauvegardé avec succès | NERIA_CARBON_ENABLED [] |  |
| save_cooldown | I | Sauvegardé avec succès | NERIA_COOLDOWN_MINUTES [] |  |
| save_custom_vars | I | Sauvegardé avec succès |  |  |
| save_deepl_key | I | Clé API DeepL enregistrée. | NERIA_DEEPL_KEY {"neria_log":1} |  |
| save_design | I | Sauvegardé avec succès | NERIA_CONTAINER_WIDTH,NERIA_LOGO_WIDTH,NERIA_SECTION_PADDING,NERIA_BLOCK_SPACING [] |  |
| save_firstname_fallbacks | I | Fallbacks de prénom enregistrés. | NERIA_FIRSTNAME_FALLBACKS [] |  |
| save_log_internal | I | Sauvegardé avec succès | NERIA_LOG_INTERNAL [] |  |
| save_loyalty_tiers | I | Paliers de fidélité enregistrés. | NERIA_LOYALTY_TIERS [] |  |
| save_milestone_voucher | I | Sauvegardé avec succès |  |  |
| save_multipreview_keys | I | Sauvegardé avec succès | NERIA_LITMUS_KEY,NERIA_EOA_KEY [] |  |
| save_pagespeed_key | I | Configuration PageSpeed enregistrée. | NERIA_PAGESPEED_TARGET_URL_1 [] |  |
| save_postmaster_config | I | Identifiants Google sauvegardés. Cliquez sur « Connecter » pour autoriser l'accès. | NERIA_POSTMASTER_CLIENT_ID,NERIA_POSTMASTER_CLIENT_SECRET,NERIA_POSTMASTER_REFRESH_TOKEN,NERIA_POSTMASTER_ACCESS_TOKEN,NERIA_POSTMASTER_TOKEN_EXPIRY [] |  |
| save_report_config | I | Sauvegardé avec succès | NERIA_REPORT_RECIPIENTS [] |  |
| save_searchconsole_config | I | Identifiants Search Console enregistrés. | NERIA_SC_CLIENT_ID,NERIA_SC_CLIENT_SECRET,NERIA_SC_REFRESH_TOKEN,NERIA_SC_ACCESS_TOKEN,NERIA_SC_TOKEN_EXPIRY [] |  |
| save_seasonal_campaign | I | Le nom et un modèle d'e-mail valide sont obligatoires. |  |  |
| save_senders | I | Sauvegardé avec succès | NERIA_CRON_LAST_DOMREP,NERIA_DOMAIN_REP_CACHE,NERIA_DOMAIN_REP_LAST_CHECK,NERIA_SENDERS_JSON [] |  |
| save_seo_config | I | Configuration SEO enregistrée. | NERIA_SEO_PROVIDER,NERIA_SEMRUSH_API_KEY,NERIA_MOZ_ACCESS_ID [] |  |
| save_smtp_quota | I | Quota SMTP journalier enregistré. | NERIA_SMTP_DAILY_QUOTA [] |  |
| save_social | I | Sauvegardé avec succès | NERIA_SOCIAL_INSTAGRAM [] |  |
| save_target_countries | I | Pays cibles enregistrés (0 pays activés). | NERIA_TARGET_COUNTRIES {"neria_log":1} |  |
| save_time_greetings | I | Salutations horaires enregistrées. | NERIA_TIME_GREETINGS [] |  |
| save_translations | I |  | {"neria_log":1} |  |
| save_typography | I | Sauvegardé avec succès |  |  |
| save_variant_b | I |  | {"neria_log":1} |  |
| save_voice_profile | I | Empreinte vocale enregistrée. | {"neria_log":1} |  |
| save_voucher_validity | I | Sauvegardé avec succès | NERIA_VOUCHER_FIXED_CAP [] |  |
| save_webhooks | I | Enregistré — l'URL des webhooks a été vidée : les webhooks sortants sont désormais désactivés. | NERIA_WEBHOOK_EVENTS [] |  |
| search_customers | I |  |  | sortie directe (redirection/exit) sans effet mesuré avec ces paramètres |
| search_translations | I |  |  |  |
| send_log_email | S |  |  | non lancée : envoi d'e-mail |
| send_manual | S |  |  | non lancée : envoi d'e-mail |
| send_report_now | S |  |  | non lancée : envoi d'e-mail |
| send_segment_campaign | S |  |  | non lancée : envoi d'e-mails groupés |
| send_test | S |  |  | non lancée : envoi d'e-mail |
| test_imap_connection | S |  |  | non lancée : connexion IMAP externe |
| test_webhook | S |  |  | non lancée : appel HTTP externe |
| toggle_autolang | I | Détection automatique de la langue désactivée. | NERIA_AUTO_LANG [] |  |
| toggle_calendar_event | I |  | {"neria_log":1} |  |
| toggle_cooldown | I | Mode Silence désactivé. | NERIA_COOLDOWN_ENABLED [] |  |
| toggle_firstname_fallback | I | Smart Fallbacks désactivés. | NERIA_FIRSTNAME_FALLBACK_ENABLED [] |  |
| toggle_gdpr_auto_purge | I | Purge automatique RGPD désactivée. | NERIA_GDPR_AUTO_PURGE_ENABLED [] |  |
| toggle_multi_sender | I | Multi-expéditeur désactivé. | NERIA_MULTI_SENDER_ENABLED [] |  |
| toggle_report | I | Rapport mensuel désactivé. | NERIA_REPORT_ENABLED [] |  |
| toggle_seasonal_campaign | I | Sauvegardé avec succès | {"neria_seasonal_campaign":1} |  |
| toggle_signature | I | Signature manuscrite désactivée. | NERIA_SIGNATURE_ENABLED [] |  |
| toggle_time_greeting | I | Smart Salutation désactivée. | NERIA_TIME_GREETING_ENABLED [] |  |
| upsell_preview | I |  |  |  |
| upsell_toggle | I |  | NERIA_UPSELL_ENABLED [] |  |
| waitlist_reservation_save | I |  | NERIA_WAITLIST_RESERVATION_HOURS [] |  |
| waitlist_toggle | I |  | NERIA_WAITLIST_ENABLED [] |  |
| watchdog_refresh | I |  |  | sortie directe (redirection/exit) sans effet mesuré avec ces paramètres |
