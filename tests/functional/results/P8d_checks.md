# P8d — contrôles du Watchdog exécutés un par un

Contrôles : 127 · langues : fr,en · statuts : {"ok":112,"warning":11,"error":2,"skipped":2} · anomalies : **0 E**, 0 W

| Niv. | Contrôle | Code | Langues | Détail |
|---|---|---|---|---|

## Statut de chaque contrôle (état actuel de la base)

| Contrôle | Statut | ms | Détail |
|---|---|---|---|
| sent_reconciliation | ok | 36 | 931 total, 231 ces 7 derniers jours |
| pixel_in_html | ok | 33 | URL track présente dans order_conf compilé |
| theme_override | ok | 32 | Aucune surcharge de thème détectée |
| class_override | ok | 30 | Aucune surcharge de classe PHP détectée |
| smarty_compile_check | ok | 31 | Vérification de compilation Smarty active — les modifications de templates sont bien prises en compte. |
| upgrade_script_safety | ok | 49 | Aucun script d'upgrade n'appelle une méthode non publique de Neria. |
| config_defaults_seeded | warning | 37 | Réglage(s) manquant(s) en base sur cette installation : NERIA_BIRTHDAY_ENABLED, NERIA_FIRST_ANNIVERSARY_ENABLED, NERIA_WIN_BACK_ENABLED, NER |
| upgrade_version_file | ok | 38 | Un script d'upgrade correspond bien à la version actuelle du module. |
| security_pattern_scan | ok | 234 | Aucune vérification SSL désactivée ni fonction dangereuse détectée dans le code du module. |
| destructive_actions_post | ok | 45 | Toutes les actions d'écriture du back-office exigent une requête POST. |
| hardcoded_date_format | ok | 162 | Aucune date codée en dur détectée dans les emails — toutes passent par le formatage localisé. |
| rtl_hardcoded_align | ok | 51 | Aucun text-align/float codé en dur détecté dans les templates email. |
| display_price_missing_lang | ok | 175 | Tous les appels à NeriaTools::displayPrice() précisent la langue du destinataire. |
| hardcoded_decimal_format | ok | 190 | Aucun formatage décimal codé en dur détecté (virgule française fixe) : tous les montants passent par un helper localisé. |
| chained_str_replace | ok | 163 | Aucune substitution de variables par str_replace(array_keys(...)) détectée : toutes utilisent strtr() (pas de risque de re-substitution en c |
| customer_email_shop_scope | ok | 143 | Aucune résolution de client par email sans filtre id_shop détectée : les recherches par email sur la table customer sont toutes scopées par  |
| default_currency_usage | ok | 40 | Aucune occurrence de Currency::getDefaultCurrency() détectée dans src/. |
| upgrade_unique_key_shop_scope | ok | 50 | Toutes les clés UNIQUE des scripts d'upgrade incluent id_shop. |
| tpl_request_uri_escape | ok | 38 | Toutes les utilisations de $smarty.server.REQUEST_URI dans les templates sont échappées. |
| cron_strict_date_equality | ok | 160 | Aucune requête cron ne compare une date de déclenchement par égalité stricte. |
| cron_loop_try_catch | ok | 114 | Toutes les boucles cron envoyant des emails comportementaux contiennent une protection try/catch. |
| tpl_js_escape_missing | ok | 35 | Aucune interpolation JS non échappée détectée dans les .tpl du BO. |
| imap_timeout_missing | ok | 159 | Tous les appels imap_open() sont protégés par un timeout. |
| oauth_refresh_error_surfaced | ok | 161 | Toutes les méthodes refreshAccessToken() remontent leurs échecs au canal d'erreur standard. |
| known_regressions_guard | ok | 380 | Aucune des 242 régressions connues n'a réapparu dans le code. |
| queue_status_enum_migrated | ok | 34 | Le schéma des colonnes `status` des files d'envoi (email/webhook) inclut bien la valeur 'sending'. |
| known_regressions_guard_freshness | ok | 31 | Scan approfondi anti-régression exécuté récemment (dernière fois : 2026-09-21 18:56:49). |
| control_center_defaults_consistency | ok | 238 | Le registre du centre de contrôle est cohérent : chaque interrupteur a un statut par défaut fiable. |
| sql_pattern_risks | ok | 204 | Aucun piège SQL/PHP générique connu détecté dans le code (LIMIT en double via getRow(), appel non protégé à Product::getPriceStatic()). |
| unescaped_like_metachars | ok | 42 | Aucune requête LIKE avec métacaractères % ou _ non échappés détectée (risque de sur-correspondance, ex. purge RGPD touchant un tiers). |
| template_cat_mapping_complete | ok | 49 | Tous les templates réellement envoyés sont présents dans PreferencesManager::TEMPLATE_CAT et StatsManager::$CHART_CATEGORIES. |
| php_mysql_clock_mismatch | ok | 49 | Aucune colonne date_add/created_at écrite via l'horloge PHP détectée sans NOW() à proximité (risque de décalage d'horloge PHP/MySQL). |
| i18n_pattern_risks | ok | 671 | Aucun piège i18n/Smarty générique connu détecté (modificateur après paramètre nommé, clé de langue orpheline). |
| hardcoded_french_text | ok | 69 | Aucun texte français codé en dur détecté hors du système de traduction dans src/*.php. |
| idlang_missing | warning | 289 | Appel(s) getPageLink()/getModuleLink() sans idLang explicite (risque de lien dans la mauvaise langue) : neria.php (2). |
| version_files_sync | ok | 42 | Version synchronisée entre neria.php et config.xml (1.0.48). |
| translation_dict_coverage | ok | 105 | Les 19 langues sont couvertes dans les 4 dictionnaires de traduction (contenu, BO, labels, académie). |
| clickable_tracking_links | ok | 45 | Les liens de suivi (history_info/guest_tracking_info/tracking_info) sont bien cliquables partout. |
| dev_tool_residue | ok | 38 | Aucune trace d'outil de développement (Mailpit...) dans les templates/JS livrés. |
| fragile_neriaconfig_usage | ok | 36 | Aucun usage fragile de neriaConfig.adminUrl/moduleName détecté dans les templates BO. |
| bare_template_var_keys | ok | 258 | Aucune clé brute (sans accolades) suspecte dans templateVars — pas de risque de corruption via le DecoratorPlugin. |
| txt_placeholder_coverage | ok | 108 | Toutes les variables des templates texte (.txt) sont couvertes par le code. |
| orphaned_voucher_reservations | ok | 43 | Aucune réservation de bon de réduction orpheline. |
| orphaned_waitlist_claims | ok | 34 | Aucune réclamation de notification liste d'attente orpheline. |
| encoded_residual_links | ok | 1035 | Aucun résidu de variable encodé détecté dans les liens des templates. |
| txt_raw_html_leak | ok | 977 | Aucune balise HTML brute détectée dans les versions texte des templates. |
| crypto_key_health | ok | 38 | Clé de chiffrement présente et fonctionnelle (test aller-retour réussi). |
| html_txt_pairs | ok | 42 | Les 117 templates ont bien leurs deux fichiers (.html et .txt). |
| template_files | ok | 41 | 117 templates présents sur disque (HTML + TXT). |
| trad_keys | ok | 79 | 699 clés de traduction présentes et complètes. |
| open_rate_7d | error | 40 | Taux d'ouverture 7j : 0% (0/231). → Que faire : Vérifiez le score de réputation dans l'onglet Statistiques, testez le pixel de tracking (ong |
| bounce_rate | ok | 35 | Taux de bounce : 0% sur 24h (0 / 94 envois). Excellent. |
| consecutive_failures | ok | 32 | Aucun échec consécutif de rendu détecté. |
| hooks_registered | ok | 40 | 16 hooks correctement enregistrés. |
| cron_triggered | ok | 36 | Dernier déclenchement : 19/09 10:45 |
| crons_health | warning | 37 | Ces tâches automatiques sont en retard : Cron comportemental (dernière exéc. il y a 57h); Cron calendaire (dernière exéc. il y a 57h); Queue |
| queue_blocked | ok | 43 | File d'envoi fluide — 0 email(s) en attente programmé(s). |
| ajax_endpoints | ok | 37 | Aucune erreur AJAX dans les dernières 24h — endpoints back-office opérationnels. |
| bounces_unprocessed | ok | 40 | Bounces traités correctement — 1 adresse(s) en base. |
| config_keys | ok | 34 | 12 clés vérifiées |
| version_sync | ok | 34 | Version synchronisée : 1.0.48. |
| upgrade_integrity | error | 67 | 3 upgrade(s) potentiellement incomplet(s) : 1.0.30, 1.0.31, 1.0.32. → Que faire : vérifiez les logs d'installation, ou contactez le support  |
| hmac_security | ok | 37 | _COOKIE_KEY_ présente et robuste (64 chars) — HMAC désabonnement sécurisé. |
| smtp_config | ok | 35 | SMTP configuré : 127.0.0.1 (utilisateur : localhost). |
| list_unsubscribe | ok | 37 | API Swift_Message compatible |
| translation_gaps | ok | 95 | 3383 clés × 19 langues — complet |
| assets | ok | 34 | Logo et signature vérifiés |
| managers_available | ok | 61 | Tous les managers PHP sont disponibles (14/14). |
| critical_methods | ok | 35 | 19 méthodes critiques vérifiées par réflexion — API interne intacte. |
| webhook_failures | ok | 39 | Webhooks opérationnels — 1 en attente. |
| abtest_stuck | ok | 39 | Aucun A/B test bloqué. |
| crypto_key | ok | 36 | Clé de chiffrement AES-256-GCM présente et fonctionnelle. |
| secrets_encrypted | ok | 36 | Tous les secrets sensibles sont chiffrés. |
| send_volume_spike | warning | 39 | Volume d'envoi élevé : 79 emails aujourd'hui vs moyenne 7j de 20 (×3.9). → Que faire : Vérifiez qu'une campagne manuelle ou saisonnière n'a  |
| domain_rep_score | warning | 35 | Réputation domaine dégradée : score 42/100 (grade B). → Que faire : Vérifiez votre configuration SPF/DKIM/DMARC dans l'onglet Statistiques → |
| ptr_record | warning | 35 | PTR / rDNS absent pour l'IP ?. Certains serveurs (Orange, SFR, serveurs corporate) rejettent les emails sans reverse DNS. → Que faire : Cont |
| db_tables | ok | 37 | 38 tables présentes en base. |
| unsubscribe_url | ok | 38 | Lien de désabonnement accessible (HTTP 200). |
| waitlist_backlog | ok | 41 | Waitlist à jour — 0 client(s) en attente de restockage. |
| smtp_quota | ok | 34 | Aucun quota SMTP journalier configuré. |
| postmaster_rep | skipped |  | API Google Postmaster |
| click_rate_7d | ok | 38 | Aucune ouverture enregistrée ces 7 derniers jours — taux de clic non applicable. |
| unsubscribe_spike | ok | 38 | Taux de désabonnement 7j : 0% (0 / 231 envois). Dans les normes. |
| fallback_template | ok | 34 | Template de secours neria_fallback présent avec traduction FR. |
| front_controllers | ok | 35 | 8 contrôleurs frontaux présents (tracking, désabonnement, liste d'attente, cron externe, OAuth, bounces, préférences). |
| queue_overflow | ok | 36 | 0 email(s) en attente dans la file. Charge normale. |
| behavioral_dedup | ok | 35 | Table de déduplication comportementale : 61 lignes. Taille saine. |
| multi_sender_json | ok | 36 | Multi-expéditeur non configuré (fonctionnement mono-expéditeur). |
| monthly_report_cfg | ok | 33 | Rapport mensuel actif — destinataire : admin@test.com. |
| deepl_key_valid | skipped |  | API DeepL |
| php_memory_limit | ok | 34 | Mémoire PHP : 1024 MB. Suffisante pour toutes les opérations Neria. |
| loyalty_integrity | ok | 38 | Programme de fidélité : intégrité des données vérifiée. |
| segment_freshness | ok | 35 | Segments recalculés il y a 0.7h. À jour. |
| clv_freshness | ok | 37 | CLV dynamique actif — scores sources (33 clients) calculés il y a 0.6h. |
| quote_reminders | ok | 37 | Relances devis : aucun devis actif bloqué sans relance. |
| campaign_empty_seg | ok | 38 | Aucune campagne active avec ciblage de segment. |
| alert_email_invalid | ok | 34 | Adresse email d'alerte Watchdog valide. |
| attribution_coverage | ok | 43 | Attribution active. Trop peu de commandes récentes pour mesurer la couverture. |
| history_table_size | ok | 34 | Historique des traductions : 0 entrées. Taille normale. |
| abtest_trad_gaps | ok | 36 | Aucun test A/B actif. |
| engagement_trend | ok | 38 | Historique insuffisant pour détecter une tendance (besoin de ≥ 50 envois sur les deux périodes). |
| oauth_freshness | ok | 38 | Connexions OAuth (Search Console / Postmaster) à jour ou non configurées. |
| visibility_freshness | ok | 38 | Intégrations de visibilité web (PageSpeed / API SEO) à jour ou non configurées. |
| active_cron | warning | 35 | Aucun cron serveur externe détecté — Neria repose uniquement sur le trafic visiteurs (hookDisplayHeader) pour ses tâches de fond, ce qui peu |
| template_staleness | warning | 40 | 9 template(s) envoyaient régulièrement des emails jusqu'ici mais n'en ont envoyé aucun sur les 30 derniers jours : order_conf, loyalty_recap |
| blacklist_stale_files | warning | 40 | 18 fichier email compilé résiduel pour des templates désactivés : care_certificate (fr), care_certificate (en), care_certificate (de), care_ |
| residual_vars_recent | warning | 34 | 48 email(s) envoyé(s) ces 7 derniers jours avaient une variable de contenu manquante (nettoyée automatiquement, mais à corriger à la source) |
| sig_social_recent | ok | 279 | 120 email(s) récent(s) vérifié(s) : signature/réseaux sociaux correctement présents. |
| action_banner_coverage | ok | 49 | 140 actions du BO analysées, toutes affichent bien une bannière de confirmation. |
| orphan_placeholders | ok | 108 | 136 variables de template vérifiées, toutes référencées dans le code du module. |
| render_canary_recent | ok | 35 | Aucun problème détecté lors du dernier canari de rendu (tous les templates compilent proprement). |
| milestone_order_health | ok | 39 | Palier de fidélisation (milestone_order) : ordinaux localisés complets, aucune erreur récente. |
| custom_vars_completeness | ok | 132 | Aucune variable personnalisée requise par les templates actuels. |
| churn_propensity_freshness | ok | 46 | Scores de désabonnement et de propension d'achat à jour. |
| collection_look_products | ok | 39 | Toutes les règles Collection/Complétez votre look actives référencent des produits valides. |
| queue_failed_rate | ok | 38 | Taux d'échec normal de la file d'attente comportementale : 0% sur 1 envoi(s) (30 jours). |
| json_config_integrity | ok | 35 | Configurations JSON (paliers fidélité, expéditeurs) valides. |
| crypto_unavailable_plain | ok | 34 | Chiffrement disponible, ou aucune donnée en clair à protéger. |
| abtest_variant_pair | ok | 38 | Tous les tests A/B actifs ont leurs 2 variantes. |
| milestone_voucher_cartrule | ok | 38 | Tous les bons de palier récents référencent un CartRule valide. |
| css_inliner_failures | ok | 35 | Aucun échec d'insertion CSS détecté depuis le dernier contrôle. |
| stored_secrets_decryptable | ok | 38 | Tous les secrets chiffrés stockés restent déchiffrables avec la clé actuelle. |
| stats_snapshot_decryptable | ok | 38 | Les snapshots chiffrés récents (neria_stat.rendered_vars) sont correctement déchiffrables. |
| calendar_json_integrity | ok | 44 | Calendrier des occasions (calendar.json) valide. |
| first_install_checklist | ok | 32 | Fenêtre de première installation dépassée (30 jours) — ce rappel ne s'affiche plus. |
| all_email_crons_disabled | ok | 34 | 10/18 crons email actifs. |
| behavioral_silence | warning | 35 | Aucun email comportemental envoyé depuis 7 jours alors que 33 clients sont actifs et que des crons sont activés. Vérifiez les conditions d'é |
