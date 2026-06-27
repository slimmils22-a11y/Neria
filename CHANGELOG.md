# Changelog — Neria Luxury Email Suite

Toutes les modifications notables sont documentées ici.
Format : [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/) — versionnage [SemVer](https://semver.org/).

---

## [1.0.12] — 2026-06-26

### Ajouté
- **Centre de préférences email** — page autonome (sans thème PS) permettant aux clients de gérer leur opt-in/opt-out par catégorie (panier, post-achat, fidélité, comportemental, saisonnier, B2B, newsletter)
- `PreferencesManager` — 7 catégories, opt-in par défaut, token HMAC, URL personnalisée par langue
- `controllers/front/preferences.php` — GET (affichage) + POST (sauvegarde)
- `views/templates/front/preferences.tpl` — page standalone avec toggles CSS
- Lien `{preferences_url}` injecté automatiquement dans le footer de tous les emails
- Clé de traduction `footer_preferences` en 18 langues
- Vérification des préférences avant chaque envoi dans `BehavioralCronManager`
- Hook `actionDeleteGDPRCustomer` — purge automatique des données client à la suppression PS
- **TABLE 33** `neria_preferences`
- Revenus B2B câblés dans `ps_neria_stat` : `quote_mark_won` insère désormais une conversion lors de la validation d'un devis
- **Watchdog — 10 nouveaux checks** (total : 22) :
  - #13 `gdpr_registry` — cohérence table/colonne du REGISTRY RGPD
  - #14 `critical_methods` — 7 sondes en lecture seule sur les classes clés
  - #15 `template_files` — intégrité des fichiers HTML/TXT sur disque
  - #16 `trad_keys` — cohérence des clés `{neria_trad}` dans les templates vs translations.json
  - #17 `hooks_registered` — tous les hooks déclarés présents dans ps_hook_module
  - #18 `version_sync` — version module synchronisée avec la base de données
  - #19 `open_rate` — taux d'ouverture 7 jours (détection délivrabilité)
  - #20 `queue_blocked` — file d'envoi bloquée depuis > 2h
  - #21 `hmac_secret` — robustesse de `_COOKIE_KEY_` PS
- **REGISTRY RGPD** — source unique de vérité dans `GdprAuditManager` ; 9 tables PII ajoutées
- `purgeCustomerData()` — gère `neria_bounces` (email), `neria_certificate` (JOIN orders)
- `NERIA_VERSION` enregistré en base à l'installation et à chaque upgrade

### Corrigé
- REGISTRY RGPD : `neria_queue.date_col` `scheduled_at` → `send_at` (colonne réelle)
- REGISTRY RGPD : `neria_ab_test` → `neria_abtest` (nom réel de la table)
- REGISTRY RGPD : `neria_clv` supprimé (table inexistante — purge silencieusement échouait)
- `quote_mark_won` : suppression de la colonne `reminders_sent` inexistante dans la requête SELECT
- `ps_neria_stat` INSERT : ajout des colonnes `lang` et `tracking_token` manquantes

---

## [1.0.11] — 2026-06-25

### Ajouté
- **Panier fantôme récurrent** (`ghost_cart`) — détecte les clients qui ont ajouté le même produit dans 3 paniers distincts sans jamais acheter ; email humain, sans réduction, un seul envoi par produit/client
- Recharge complète des traductions depuis `translations.json` à chaque upgrade (fix bug clés absentes sur installs existantes)

---

## [1.0.10] — 2026-06-25

### Ajouté
- **Liste d'attente produits** (`WaitlistManager`) — bouton "M'avertir" sur les fiches produit en rupture, notification automatique au retour en stock
- **TABLE 32** `neria_waitlist`
- Variable `{days_waited_plural}` dans le template `waitlist_available`
- Lien de notification via GET pour compatibilité emails

---

## [1.0.9] — 2026-06-24

### Ajouté
- **Complétez votre look** — détecte les clients ayant acheté un produit d'une règle d'association et suggère les compléments
- **TABLE 30** `neria_look_rule`, **TABLE 31** `neria_look_sent`

---

## [1.0.8] — 2026-06-24

### Ajouté
- **Complétion de collection** — détecte les clients ayant acheté toutes les pièces d'une collection sauf une
- **TABLE 28** `neria_collection`, **TABLE 29** `neria_collection_sent`

---

## [1.0.7] — 2026-06-24

### Corrigé
- Ajout de la colonne `gift_mode` sur `neria_seasonal_campaign` (manquante à l'upgrade)

---

## [1.0.6] — 2026-06-24

### Ajouté
- **Fenêtre d'achat individuelle** (`PurchaseWindowManager` + `QueueManager`) — emails comportementaux programmés à l'heure préférée de chaque client
- **TABLE 27** `neria_queue`
- Clé de configuration `NERIA_PURCHASE_WINDOW_ENABLED`

---

## [1.0.5] — 2026-06-24

### Ajouté
- **Anniversaire de la relation client** (`relationship_anniversary`) — email personnalisé à la date du premier achat, adapté à l'année écoulée (1 an, 2 ans, 3 ans…)
- Déduplication via `neria_behavioral_sent` (ref_id = année, pas de nouvelle table)

---

## [1.0.4] — 2026-06-23

### Ajouté
- **Score de propension à l'achat** — calcul quotidien par client (0–100), 4 facteurs comportementaux
- **TABLE 26** `neria_propensity_score`
- Section dédiée dans l'onglet Statistiques avec filtres campagne

---

## [1.0.3] — 2026-06-23

### Ajouté
- **Rappel fin de vie produit** (`product_lifespan_reminder`) — associez une durée de vie à vos produits consommables, email automatique avant épuisement estimé
- **TABLE 25** `neria_product_lifespan`
- Annulation automatique si le client a déjà racheté le produit entre-temps

---

## [1.0.2] — 2026-06-23

### Ajouté
- **Réconciliation post-remboursement** — séquence de 3 emails (J+1, J+3, J+7) après un remboursement PS ; annulation automatique si nouvelle commande
- **TABLE 24** `neria_reconciliation`
- Clé de configuration `NERIA_REFUND_RECONCILIATION_ENABLED`

---

## [1.0.1] — 2026-06-23

### Ajouté
- **Relances Devis B2B** — suivi de devis avec 3 emails automatiques (48h avant expiration, jour J, prolongation J+1)
- **TABLE 23** `neria_quote`
- Clé de configuration `NERIA_QUOTE_REMINDERS_ENABLED`
- Section dédiée dans l'onglet Statistiques (CRUD devis + statuts)

---

## [1.0.0] — 2026-06-20

### Version initiale — 22 tables, 111 templates × 18 langues

#### Email & Rendu
- `EmailRenderer` — rendu Smarty + injection variables + CSS inlining (Gmail, Orange, Yahoo)
- `TranslationEngine` — moteur de traduction 18 langues, clés `{neria_trad}` dans tous les templates
- `NeriaTools` — helpers partagés (formatage produits, prix, dates)
- Thème global `neria_global` avec layout responsive et footer RGPD
- Empreinte carbone dynamique dans le footer (`<!-- NERIA_CARBON -->`)
- Multi-expéditeur par langue (`NERIA_SENDERS_JSON`)

#### Templates (111 au total)
- Transactionnels : `order_conf`, `payment_error`, `shipping_info`, `delivered`, `invoice`, `return_slip`…
- Comportementaux : `abandoned_cart_1/2/3`, `checkout_abandonment`, `birthday`, `anniversary`, `reorder_reminder`, `win_back`, `post_purchase_care/review`, `shipped_delay`
- Fidélité : `loyalty_tier_upgrade`, `loyalty_recap`, `referral_invitation`
- Saisonniers : `black_friday`, `new_year`, `valentines_day`, `mothers_day`, `fathers_day`…
- Urgence : `product_recall`, `stock_alert`, `waitlist_available`

#### Fonctionnalités
- **Watchdog** (`WatchdogManager`) — journal 4 niveaux, rétention 30j/500 lignes, alertes email immédiates + digest quotidien
- **A/B Testing** — tests sur objet/contenu/expéditeur, significance statistique
- **Attribution de revenus** — last-click 24h, cookie `neria_ref`
- **Score de délivrabilité** — 8 critères anti-spam, analyse texte visible
- **Score de réputation de domaine** — SPF/DKIM/DMARC + 42 RBL DNS, cron quotidien
- **Segmentation comportementale** — 5 segments (Ambassador → Ghost), recalcul quotidien
- **Tranche horaire** — preferred_slot par client, filtres slot/langue/pays
- **Programme de fidélité** — points (open=1/click=3/achat=10), 3 paliers, CartRule PS, recap mensuel
- **Campagnes saisonnières** — CRUD + calendrier 12 mois, ciblage segment/genre/langue/âge
- **Upsell intelligent post-achat** — 3 niveaux (accessoires → co-achat → catégorie)
- **CLV — Valeur client 12 mois** — formule transparente, Top 20, filtres 18 langues
- **Prévisualisation multi-client** — 6 clients simulés (Gmail, Outlook, Apple Mail, Orange, Yahoo, Mobile)
- **Certificats d'authenticité** — TCPDF, QR code optionnel, 18 langues
- **Audit RGPD** — score A–D, rapport PDF, purge par table, chiffrement AES-256-GCM
- **Neria Academy** — 3 guides (ouverture/objet/RGPD), 18 langues
- **Page d'urgence autonome** — `neria-emergency.php` sans PS, token secret
- **Webhooks sortants** — 5 events, queue DB, retry 3×, HMAC-SHA256
- **Email de secours** (`neria_fallback`) — annule l'email natif PS en cas d'erreur
- **Désabonnement complet** — List-Unsubscribe POST (RFC 8058), lien footer
- **Détection auto de langue** — multi-langues→compte / mono→pays facturation
- **HealthCheckManager** — 12 checks automatiques 1×/jour

#### Compatibilité
- PrestaShop 8.x, PHP 8.1+
- 22 tables SQL, upgrade scripts idempotents
