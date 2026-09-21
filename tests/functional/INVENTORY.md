# Inventaire P1 — campagne de tests fonctionnels

Généré le 2026-09-21 13:33:47 — module v1.0.48 — **856 lignes** dans `inventory/matrix.csv`.

| Catégorie | Éléments |
|---|---|
| bo_actions | 167 |
| buttons | 235 |
| admin_templates | 25 |
| tabs | 21 |
| subsections | 49 |
| email_templates | 117 |
| behavioral_steps | 21 |
| heartbeat_jobs | 12 |
| throttle_keys | 30 |
| hooks | 16 |
| front_controllers | 9 |
| watchdog_checks | 127 |
| control_center | 43 |
| sql_tables | 38 |
| config_keys | 220 |
| languages | 19 |

## Points d'attention repérés automatiquement

- Actions BO sans gabarit qui les déclenche (5) : multipreview_poll_eoa, multipreview_poll_litmus, multipreview_submit_eoa, multipreview_submit_litmus, search_customers
- Actions BO sans bannière ni réponse AJAX (7) : check_voice_profile, deliverability_score, health_pixel_test, preview, run_bounce_check, search_customers, send_test
- Résidus de test dans le module (0) : 
- Noms de templates sans fichier HTML (1) : monthly_report
- Templates par type de déclencheur : {"code Neria":90,"natif PrestaShop (déclenché par le cœur ou un module PS)":24,"dynamique (campagne saisonnière \/ envoi manuel \/ A-B)":3}
- Templates sans version TXT (0) : 
- Templates sans émetteur repéré dans le code (27) — natifs PrestaShop ou envoi manuel : backoffice_order, bankwire, cheque, contact_form, credit_slip, customer_qty, download_product, employee_password, forward_msg, import, in_transit, mothers_day, new_order, newsletter_verif, order_customer_comment, order_merchant_comment, order_return_state, outofstock, payment_error, preparation, productcoverage, productoutofstock, refund, reply_msg, return_slip, shipped, voucher_new
- Hooks sans handler (0) : 
- Dictionnaire admin : {"keys":3380,"missing_by_lang":[]}
- Dictionnaire noms de templates : {"keys":118,"missing_by_lang":[]}

## Couverture des méthodes publiques par les tests de régression existants

167 méthodes publiques sur 609 ne sont citées dans aucun test (indicateur de lacunes, pas une preuve de bug). Détail par classe dans `inventory.json` › `method_coverage`.
