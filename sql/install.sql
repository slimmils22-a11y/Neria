-- ============================================================
-- NERIA — Script d'installation SQL
-- Module Email Luxe PrestaShop
-- Version : 1.0.0
-- ============================================================
-- NOTE : PREFIX_ est remplacé automatiquement par le vrai
-- préfixe de la base de données lors de l'installation
-- (ex: ps_ → ps_neria_translation)
-- ============================================================


-- ------------------------------------------------------------
-- TABLE 1 : neria_translation
-- Stocke tous les textes traduits dans les 18 langues
-- Alimentée au premier install depuis translations.json
-- Modifiable via le back-office (onglet Traductions)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_translation` (
    `id_translation`    INT(11)         NOT NULL AUTO_INCREMENT,
    `template`          VARCHAR(100)    NOT NULL COMMENT 'Nom du template email (ex: order_conf)',
    `lang`              VARCHAR(5)      NOT NULL COMMENT 'Code langue ISO (ex: fr, ja, ar)',
    `translation_key`   VARCHAR(150)    NOT NULL COMMENT 'Clé du champ (ex: greeting_main)',
    `translation_value` MEDIUMTEXT      NOT NULL COMMENT 'Texte traduit',
    `is_custom`         TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = modifié par le marchand',
    `date_add`          DATETIME        NOT NULL COMMENT 'Date de création',
    `date_upd`          DATETIME        NOT NULL COMMENT 'Date de dernière modification',
    PRIMARY KEY (`id_translation`),
    UNIQUE KEY `uq_template_lang_key` (`template`, `lang`, `translation_key`),
    INDEX `idx_template` (`template`),
    INDEX `idx_lang` (`lang`),
    INDEX `idx_template_lang` (`template`, `lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Dictionnaire maître des traductions Neria (18 langues)';


-- ------------------------------------------------------------
-- TABLE 2 : neria_config
-- Stocke la configuration du panneau back-office
-- Couleurs, typographie, logo, réseaux sociaux, variables
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_config` (
    `id_config`         INT(11)         NOT NULL AUTO_INCREMENT,
    `id_shop`           INT(11)         NOT NULL DEFAULT 1 COMMENT 'Support multi-boutique',
    `config_key`        VARCHAR(100)    NOT NULL COMMENT 'Clé de configuration (ex: color_accent)',
    `config_value`      TEXT            NOT NULL COMMENT 'Valeur de configuration',
    `date_add`          DATETIME        NOT NULL,
    `date_upd`          DATETIME        NOT NULL,
    PRIMARY KEY (`id_config`),
    UNIQUE KEY `uq_shop_key` (`id_shop`, `config_key`),
    INDEX `idx_shop` (`id_shop`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Configuration du back-office Neria par boutique';


-- ------------------------------------------------------------
-- TABLE 3 : neria_custom_variable
-- Variables personnalisées définies par le marchand
-- Ex: {maison_name}, {slogan}, {signature_text}
-- Injectées dans les templates via le moteur de rendu
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_custom_variable` (
    `id_variable`       INT(11)         NOT NULL AUTO_INCREMENT,
    `id_shop`           INT(11)         NOT NULL DEFAULT 1,
    `variable_key`      VARCHAR(50)     NOT NULL COMMENT 'Clé sans accolades (ex: maison_name)',
    `variable_value`    VARCHAR(500)    NOT NULL COMMENT 'Valeur injectée dans les emails',
    `description`       VARCHAR(200)    NOT NULL DEFAULT '' COMMENT 'Description pour le marchand',
    `date_add`          DATETIME        NOT NULL,
    `date_upd`          DATETIME        NOT NULL,
    PRIMARY KEY (`id_variable`),
    UNIQUE KEY `uq_shop_variable` (`id_shop`, `variable_key`),
    INDEX `idx_shop` (`id_shop`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Variables personnalisées du marchand injectées dans les emails';


-- ------------------------------------------------------------
-- TABLE 4 : neria_signature
-- Signatures manuscrites générées par SignatureGenerator
-- Liées à un shop, stockées comme image PNG
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_signature` (
    `id_signature`      INT(11)         NOT NULL AUTO_INCREMENT,
    `id_shop`           INT(11)         NOT NULL DEFAULT 1,
    `signer_name`       VARCHAR(100)    NOT NULL COMMENT 'Nom affiché dans la signature',
    `signer_title`      VARCHAR(100)    NOT NULL DEFAULT '' COMMENT 'Titre (ex: Fondatrice)',
    `font_style`        VARCHAR(50)     NOT NULL DEFAULT 'elegant' COMMENT 'Style de fonte manuscrite',
    `color`             VARCHAR(7)      NOT NULL DEFAULT '#b38b59' COMMENT 'Couleur de la signature',
    `image_path`        VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'Chemin relatif vers le PNG généré',
    `is_active`         TINYINT(1)      NOT NULL DEFAULT 1,
    `date_add`          DATETIME        NOT NULL,
    `date_upd`          DATETIME        NOT NULL,
    PRIMARY KEY (`id_signature`),
    INDEX `idx_shop` (`id_shop`),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Signatures manuscrites générées pour les emails Neria';


-- ------------------------------------------------------------
-- TABLE 5 : neria_abtest
-- Définition des variantes A/B par template email
-- Ex: abandoned_cart_1 → variante A (ton discret)
--                      → variante B (ton urgence)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_abtest` (
    `id_abtest`         INT(11)         NOT NULL AUTO_INCREMENT,
    `id_shop`           INT(11)         NOT NULL DEFAULT 1,
    `template`          VARCHAR(100)    NOT NULL COMMENT 'Template concerné (ex: abandoned_cart_1)',
    `variant`           CHAR(1)         NOT NULL COMMENT 'A ou B',
    `variant_name`      VARCHAR(100)    NOT NULL COMMENT 'Nom lisible (ex: Ton discret)',
    `description`       VARCHAR(255)    NOT NULL DEFAULT '',
    `split_percent`     TINYINT(3)      NOT NULL DEFAULT 50 COMMENT 'Pourcentage envoyé (A+B = 100)',
    `is_active`         TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = test en cours',
    `date_start`        DATETIME        NULL     COMMENT 'Début du test',
    `date_end`          DATETIME        NULL     COMMENT 'Fin prévue du test',
    `date_add`          DATETIME        NOT NULL,
    `date_upd`          DATETIME        NOT NULL,
    PRIMARY KEY (`id_abtest`),
    UNIQUE KEY `uq_shop_template_variant` (`id_shop`, `template`, `variant`),
    INDEX `idx_template` (`template`),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Définition des tests A/B par template email';


-- ------------------------------------------------------------
-- TABLE 6 : neria_abtest_translation
-- Textes alternatifs pour la variante B d'un test A/B
-- Même structure que neria_translation mais liée à un abtest
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_abtest_translation` (
    `id_abtest_translation` INT(11)     NOT NULL AUTO_INCREMENT,
    `id_abtest`             INT(11)     NOT NULL COMMENT 'FK vers neria_abtest',
    `lang`                  VARCHAR(5)  NOT NULL,
    `translation_key`       VARCHAR(150) NOT NULL,
    `translation_value`     MEDIUMTEXT  NOT NULL,
    `date_add`              DATETIME    NOT NULL,
    `date_upd`              DATETIME    NOT NULL,
    PRIMARY KEY (`id_abtest_translation`),
    UNIQUE KEY `uq_abtest_lang_key` (`id_abtest`, `lang`, `translation_key`),
    INDEX `idx_abtest` (`id_abtest`),
    CONSTRAINT `fk_abtest_translation`
        FOREIGN KEY (`id_abtest`)
        REFERENCES `PREFIX_neria_abtest` (`id_abtest`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Textes alternatifs pour les variantes B des tests A/B';


-- ------------------------------------------------------------
-- TABLE 7 : neria_stat
-- Tracking des événements email (envoi, ouverture, clic)
-- Chaque email envoyé génère un token unique pour le pixel
-- de tracking et les liens cliquables
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_stat` (
    `id_stat`           INT(11)         NOT NULL AUTO_INCREMENT,
    `id_shop`           INT(11)         NOT NULL DEFAULT 1,
    `template`          VARCHAR(100)    NOT NULL COMMENT 'Template email (ex: order_conf)',
    `lang`              VARCHAR(5)      NOT NULL COMMENT 'Langue de l\'email envoyé',
    `country_code`      VARCHAR(3)      NOT NULL DEFAULT '' COMMENT 'Code pays ISO (ex: FR, JP)',
    `id_customer`       INT(11)         NOT NULL DEFAULT 0 COMMENT '0 = invité',
    `id_order`          INT(11)         NOT NULL DEFAULT 0 COMMENT 'Commande liée si applicable',
    `tracking_token`    VARCHAR(64)     NOT NULL COMMENT 'Token unique SHA256 pour le pixel',
    `event_type`        ENUM('sent','open','click','conversion')
                                        NOT NULL DEFAULT 'sent',
    `is_mpp`            TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Apple Mail Privacy Protection : 1 = ouverture probable MPP',
    `abtest_variant`    CHAR(1)         NOT NULL DEFAULT '' COMMENT 'A, B ou vide si pas de test',
    `rendered_vars`     MEDIUMTEXT      NULL COMMENT 'Snapshot chiffré (AES-256-GCM) des variables au moment de l\'envoi',
    `revenue`           DECIMAL(10,2)   NOT NULL DEFAULT 0 COMMENT 'Montant attribué (conversions uniquement)',
    `ip_address`        VARCHAR(45)     NOT NULL DEFAULT '' COMMENT 'IPv4 ou IPv6 anonymisée',
    `user_agent`        VARCHAR(255)    NOT NULL DEFAULT '',
    `date_add`          DATETIME        NOT NULL,
    PRIMARY KEY (`id_stat`),
    INDEX `idx_template` (`template`),
    INDEX `idx_lang` (`lang`),
    INDEX `idx_country` (`country_code`),
    INDEX `idx_customer` (`id_customer`),
    INDEX `idx_token` (`tracking_token`),
    INDEX `idx_event` (`event_type`),
    INDEX `idx_date` (`date_add`),
    INDEX `idx_shop_template_event` (`id_shop`, `template`, `event_type`, `date_add`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Statistiques d\'envoi, ouverture et clic des emails Neria';


-- ------------------------------------------------------------
-- TABLE 8 : neria_calendar_event
-- Occasions calendaires automatiques par langue/pays
-- Complète la table data/calendar.json avec les overrides
-- marchand (dates personnalisées, activation/désactivation)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_calendar_event` (
    `id_event`          INT(11)         NOT NULL AUTO_INCREMENT,
    `id_shop`           INT(11)         NOT NULL DEFAULT 1,
    `event_key`         VARCHAR(50)     NOT NULL COMMENT 'Clé (ex: eid, lunar_new_year, christmas)',
    `lang`              VARCHAR(5)      NOT NULL COMMENT 'Langue cible (ex: ar, zh)',
    `country_code`      VARCHAR(3)      NOT NULL DEFAULT '' COMMENT 'Pays cible (ex: SA, CN)',
    `custom_date`       VARCHAR(5)      NOT NULL DEFAULT '' COMMENT 'Date fixe perso MM-DD (occasions hors calendrier)',
    `template`          VARCHAR(100)    NOT NULL COMMENT 'Template email à envoyer',
    `send_days_before`  TINYINT(3)      NOT NULL DEFAULT 3 COMMENT 'J-X avant la date',
    `is_active`         TINYINT(1)      NOT NULL DEFAULT 1,
    `date_add`          DATETIME        NOT NULL,
    `date_upd`          DATETIME        NOT NULL,
    PRIMARY KEY (`id_event`),
    UNIQUE KEY `uq_shop_event_lang` (`id_shop`, `event_key`, `lang`),
    INDEX `idx_event_key` (`event_key`),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Configuration des occasions calendaires automatiques';


-- ------------------------------------------------------------
-- TABLE 9 : neria_log
-- Journal des erreurs et événements du module (watchdog)
-- Accessible depuis l'onglet Aide du back-office
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_log` (
    `id_log`        INT(11)         NOT NULL AUTO_INCREMENT,
    `id_shop`       INT(11)         NOT NULL DEFAULT 1,
    `level`         ENUM('info','warning','error','critical') NOT NULL DEFAULT 'info',
    `template`      VARCHAR(100)    NOT NULL DEFAULT '',
    `class`         VARCHAR(100)    NOT NULL DEFAULT '',
    `message`       TEXT            NOT NULL,
    `context`       TEXT            NULL,
    `date_add`      DATETIME        NOT NULL,
    `occurrence_count` INT(11)      NOT NULL DEFAULT 1 COMMENT 'Nombre d''occurrences consolidées (déduplication 1h)',
    PRIMARY KEY (`id_log`),
    INDEX `idx_shop`    (`id_shop`),
    INDEX `idx_level`   (`level`),
    INDEX `idx_date`    (`date_add`),
    INDEX `idx_template`(`template`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Journal des erreurs et événements du module Neria';


-- ------------------------------------------------------------
-- TABLE 10 : neria_blacklist
-- Règles de désactivation de templates par langue
-- lang = '' signifie toutes les langues
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_blacklist` (
    `id_blacklist`  INT(11)         NOT NULL AUTO_INCREMENT,
    `id_shop`       INT(11)         NOT NULL DEFAULT 1,
    `template`      VARCHAR(100)    NOT NULL COMMENT 'Template désactivé',
    `lang`          VARCHAR(5)      NOT NULL DEFAULT '' COMMENT 'Code langue Neria, vide = toutes',
    `date_add`      DATETIME        NOT NULL,
    PRIMARY KEY (`id_blacklist`),
    UNIQUE KEY `uq_shop_tpl_lang` (`id_shop`, `template`, `lang`),
    INDEX `idx_shop_tpl` (`id_shop`, `template`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Templates Neria désactivés par langue par le marchand';


-- ------------------------------------------------------------
-- TABLE 11 : neria_attribution
-- Liaison temporaire commande → token de tracking.
-- Posée par hookActionObjectOrderAddAfter (contexte client),
-- lue par hookActionOrderStatusPostUpdate (contexte BO/paiement).
-- Supprimée après attribution réussie.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_attribution` (
    `id_order`       INT(10) UNSIGNED NOT NULL,
    `tracking_token` VARCHAR(128)     NOT NULL,
    `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Attribution email → commande : pont entre cookie client et hook de statut';


-- ============================================================
-- DONNÉES PAR DÉFAUT
-- Injectées à l'installation pour que le module soit
-- opérationnel immédiatement sans configuration manuelle
-- ============================================================

-- Configuration par défaut (design global)
INSERT INTO `PREFIX_neria_config`
    (`id_shop`, `config_key`, `config_value`, `date_add`, `date_upd`)
VALUES
    (1, 'color_background',     '#f4f1eb',                                          NOW(), NOW()),
    (1, 'color_container',      '#ffffff',                                          NOW(), NOW()),
    (1, 'color_accent',         '#b38b59',                                          NOW(), NOW()),
    (1, 'color_text',           '#2c2c2c',                                          NOW(), NOW()),
    (1, 'font_latin',           'Cormorant Garamond, Georgia, Times New Roman, serif', NOW(), NOW()),
    (1, 'font_arabic',          'Noto Naskh Arabic, Traditional Arabic, serif',     NOW(), NOW()),
    (1, 'font_japanese',        'Noto Serif JP, Hiragino Mincho Pro, serif',        NOW(), NOW()),
    (1, 'font_korean',          'Noto Serif KR, Batang, serif',                     NOW(), NOW()),
    (1, 'font_chinese_simplified',  'Noto Serif SC, SimSun, serif',                NOW(), NOW()),
    (1, 'font_chinese_traditional', 'Noto Serif TC, PMingLiU, serif',              NOW(), NOW()),
    (1, 'font_cyrillic',        'EB Garamond, Cormorant Garamond, serif',           NOW(), NOW()),
    (1, 'dark_mode',            '0',                                                NOW(), NOW()),
    (1, 'container_width',      '620',                                              NOW(), NOW()),
    (1, 'logo_width',           '160',                                              NOW(), NOW()),
    (1, 'social_instagram',     '',                                                 NOW(), NOW()),
    (1, 'social_pinterest',     '',                                                 NOW(), NOW()),
    (1, 'social_facebook',      '',                                                 NOW(), NOW()),
    (1, 'active_theme',         'neria_global',                                     NOW(), NOW());


-- Variables personnalisées par défaut (exemples pour guider le marchand)
INSERT INTO `PREFIX_neria_custom_variable`
    (`id_shop`, `variable_key`, `variable_value`, `description`, `date_add`, `date_upd`)
VALUES
    (1, 'maison_name',      '',  'Nom de votre maison ou marque (ex: Maison Dupont)', NOW(), NOW()),
    (1, 'slogan',           '',  'Votre slogan ou devise (ex: L\'élégance au quotidien)', NOW(), NOW()),
    (1, 'signature_closing','',  'Formule de clôture personnalisée (ex: Avec toute notre estime)', NOW(), NOW()),
    (1, 'founder_name',     '',  'Nom du fondateur pour la signature manuscrite', NOW(), NOW()),
    (1, 'founder_title',    '',  'Titre du fondateur (ex: Fondatrice & Directrice Artistique)', NOW(), NOW()),
    (1, 'return_address',        '',    'Adresse de retour des marchandises (bon de retour)', NOW(), NOW()),
    (1, 'return_deadline_days',  '14',  'Bon de retour : délai accordé pour renvoyer le colis (en jours)', NOW(), NOW()),
    (1, 'return_processing_days','5-7', 'Bon de retour : délai d\'examen du retour (jours ouvrés, ex: 5-7)', NOW(), NOW());


-- Occasions calendaires par défaut
INSERT IGNORE INTO `PREFIX_neria_calendar_event`
    (`id_shop`, `event_key`, `lang`, `country_code`, `template`, `send_days_before`, `is_active`, `date_add`, `date_upd`)
VALUES
    (1, 'eid',              'ar',  'SA',  'eid',              7, 1, NOW(), NOW()),
    (1, 'lunar_new_year',   'zh',  'CN',  'lunar_new_year',   7, 1, NOW(), NOW()),
    (1, 'lunar_new_year',   'tw',  'TW',  'lunar_new_year',   7, 1, NOW(), NOW()),
    (1, 'lunar_new_year',   'ko',  'KR',  'lunar_new_year',   7, 1, NOW(), NOW()),
    (1, 'christmas',        'fr',  'FR',  'christmas',        5, 1, NOW(), NOW()),
    (1, 'christmas',        'en',  'GB',  'christmas',        5, 1, NOW(), NOW()),
    (1, 'christmas',        'de',  'DE',  'christmas',        5, 1, NOW(), NOW()),
    (1, 'christmas',        'it',  'IT',  'christmas',        5, 1, NOW(), NOW()),
    (1, 'christmas',        'es',  'ES',  'christmas',        5, 1, NOW(), NOW()),
    (1, 'halloween',        'en',  'US',  'halloween',        7, 1, NOW(), NOW()),
    (1, 'valentine',        'fr',  'FR',  'valentine',        7, 1, NOW(), NOW()),
    (1, 'valentine',        'en',  'US',  'valentine',        7, 1, NOW(), NOW()),
    (1, 'ramadan',          'ar',  'SA',  'ramadan',         14, 1, NOW(), NOW());


-- ------------------------------------------------------------
-- TABLE 12 : neria_behavioral_sent
-- Déduplication des emails comportementaux (crons Vague 2)
-- UNIQUE sur (id_customer, template, ref_id, id_shop) pour éviter les
-- doublons même en cas d'exécution parallèle du cron, ET pour permettre
-- une déduplication distincte par boutique en multi-boutique (même motif
-- que neria_waitlist, upgrade 1.0.28) — sans id_shop, un client partagé
-- entre boutiques ne recevait l'email comportemental QUE de la première
-- boutique dont le cron tournait ce jour-là, jamais des suivantes.
-- ref_id sémantique : YEAR pour birthday/win_back,
--                     id_order pour post_purchase/anniversary,
--                     id_cart pour abandoned_cart_*
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_behavioral_sent` (
    `id`            INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    `id_customer`   INT UNSIGNED        NOT NULL,
    `template`      VARCHAR(100)        NOT NULL,
    `ref_id`        INT UNSIGNED        NOT NULL DEFAULT 0,
    `id_shop`       INT UNSIGNED        NOT NULL DEFAULT 1,
    `sent_at`       DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_customer_template_ref_shop` (`id_customer`, `template`, `ref_id`, `id_shop`),
    INDEX `idx_sent_at` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Déduplication des emails comportementaux Neria (crons Vague 2)';


-- ------------------------------------------------------------
-- TABLE 13 : neria_webhook_queue
-- File d'attente des notifications HTTP sortantes.
-- Chaque événement Neria est mis en queue et traité par lot
-- via le cron ou le hook displayHeader (toutes les 5 min).
-- Max 3 tentatives par événement, timeout 3 s par appel.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_webhook_queue` (
    `id_webhook`    INT(11)         NOT NULL AUTO_INCREMENT,
    `id_shop`       INT(11)         NOT NULL DEFAULT 1,
    `event`         VARCHAR(50)     NOT NULL COMMENT 'Type d\'événement (email_sent, conversion…)',
    `payload`       MEDIUMTEXT      NOT NULL COMMENT 'Corps JSON envoyé à l\'endpoint',
    `status`        ENUM('pending','done','failed') NOT NULL DEFAULT 'pending',
    `attempts`      TINYINT(3)      NOT NULL DEFAULT 0 COMMENT 'Nombre de tentatives effectuées',
    `last_attempt`  DATETIME        NULL     COMMENT 'Date de la dernière tentative',
    `date_add`      DATETIME        NOT NULL,
    PRIMARY KEY (`id_webhook`),
    INDEX `idx_shop_status`   (`id_shop`, `status`),
    INDEX `idx_shop_pending`  (`id_shop`, `status`, `attempts`),
    INDEX `idx_date`          (`date_add`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='File d\'attente des notifications webhook sortantes Neria';


-- ------------------------------------------------------------
-- TABLE 14 : neria_customer_segment
-- Segments comportementaux calculés à partir des stats email.
-- Recalculés une fois par jour via BehavioralCronManager.
-- Segments : ambassador / loyal / warm / dormant / ghost
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_customer_segment` (
    `id_segment`        INT(11)     NOT NULL AUTO_INCREMENT,
    `id_shop`           INT(11)     NOT NULL DEFAULT 1,
    `id_customer`       INT(11)     NOT NULL,
    `segment`           ENUM('ambassador','loyal','warm','dormant','ghost') NOT NULL DEFAULT 'ghost',
    `total_sent`        INT(11)     NOT NULL DEFAULT 0,
    `total_opens`       INT(11)     NOT NULL DEFAULT 0,
    `total_clicks`      INT(11)     NOT NULL DEFAULT 0,
    `total_conversions` INT(11)     NOT NULL DEFAULT 0,
    `last_open`         DATETIME    NULL,
    `last_conversion`   DATETIME    NULL,
    `computed_at`       DATETIME    NOT NULL,
    PRIMARY KEY (`id_segment`),
    UNIQUE KEY `uq_shop_customer` (`id_shop`, `id_customer`),
    INDEX `idx_segment`  (`id_shop`, `segment`),
    INDEX `idx_customer` (`id_customer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Segments comportementaux clients Neria (recalcul quotidien)';


-- ------------------------------------------------------------
-- TABLE 15 : neria_churn_score
-- Score de risque de désabonnement (0-100) par client.
-- Basé sur l'évolution du taux d'ouverture sur 3 × 30 jours.
-- Score ≥ 70 = risque élevé → alerte sur la fiche client.
-- Recalculé quotidiennement via BehavioralCronManager.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_churn_score` (
    `id_score`      INT(11)          NOT NULL AUTO_INCREMENT,
    `id_shop`       INT(11)          NOT NULL DEFAULT 1,
    `id_customer`   INT(11)          NOT NULL,
    `score`         TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-100, ≥70 = risque élevé',
    `rate_p1`       DECIMAL(5,4)     NOT NULL DEFAULT 0 COMMENT 'Taux ouverture 0-30 j',
    `rate_p2`       DECIMAL(5,4)     NOT NULL DEFAULT 0 COMMENT 'Taux ouverture 31-60 j',
    `rate_p3`       DECIMAL(5,4)     NOT NULL DEFAULT 0 COMMENT 'Taux ouverture 61-90 j',
    `last_open`      DATETIME         NULL,
    `preferred_slot` ENUM('morning','afternoon','evening','night') NULL DEFAULT NULL COMMENT 'Tranche horaire préférée d''ouverture',
    `computed_at`    DATETIME         NOT NULL,
    PRIMARY KEY (`id_score`),
    UNIQUE KEY `uq_shop_customer` (`id_shop`, `id_customer`),
    INDEX `idx_score`    (`id_shop`, `score`),
    INDEX `idx_customer` (`id_customer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Score de risque de désabonnement Neria (0-100, recalcul quotidien)';


-- ------------------------------------------------------------
-- TABLE 16 : neria_translation_history
-- Changelog des modifications de traductions.
-- Enregistre l'ancienne et la nouvelle valeur à chaque save
-- dans l'onglet Traductions. Limite : 50 entrées par clé.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_translation_history` (
    `id_history`      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `id_shop`         INT UNSIGNED  NOT NULL DEFAULT 1,
    `template_key`    VARCHAR(100)  NOT NULL COMMENT 'Template email (ex: order_conf)',
    `lang_code`       VARCHAR(5)    NOT NULL COMMENT 'Code langue (ex: fr)',
    `translation_key` VARCHAR(150)  NOT NULL COMMENT 'Cle de traduction (ex: subject)',
    `old_value`       MEDIUMTEXT    NOT NULL COMMENT 'Ancienne valeur avant modification',
    `new_value`       MEDIUMTEXT    NOT NULL COMMENT 'Nouvelle valeur apres modification',
    `author`          VARCHAR(200)  NOT NULL DEFAULT '' COMMENT 'Nom employe auteur',
    `date_add`        DATETIME      NOT NULL COMMENT 'Horodatage de la modification',
    PRIMARY KEY (`id_history`),
    KEY `idx_lookup` (`id_shop`, `template_key`, `lang_code`, `translation_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Changelog des textes emails Neria (Git simplifie)';

-- ------------------------------------------------------------
-- TABLE 17 : neria_upsell
-- Journal des suggestions produit post-achat (upsell intelligent).
-- Un enregistrement = une suggestion envoyée dans post_purchase_review.
-- clicked_at et converted_at sont mis à jour par le tracking.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_upsell` (
    `id_upsell`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `id_customer`        INT UNSIGNED  NOT NULL DEFAULT 0,
    `id_order_source`    INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'Commande déclenchante',
    `id_product_upsell`  INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'Produit suggéré',
    `product_name`       VARCHAR(255)  NOT NULL DEFAULT '',
    `tier`               ENUM('accessory','co_purchase','bestseller') NOT NULL DEFAULT 'bestseller',
    `reason`             VARCHAR(100)  NOT NULL DEFAULT '',
    `sent_at`            DATETIME      NOT NULL,
    `clicked_at`         DATETIME      NULL     DEFAULT NULL,
    `id_order_converted` INT UNSIGNED  NULL     DEFAULT NULL COMMENT 'Commande passée après clic',
    `converted_at`       DATETIME      NULL     DEFAULT NULL,
    `conversion_amount`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (`id_upsell`),
    KEY `idx_customer`    (`id_customer`),
    KEY `idx_order_src`   (`id_order_source`),
    KEY `idx_product`     (`id_product_upsell`),
    KEY `idx_clicked`     (`clicked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Journal upsell post-achat Neria — suggestions et conversions';


-- ------------------------------------------------------------
-- TABLE 18 : neria_loyalty_points
-- Historique de chaque attribution de points fidélité.
-- Clé UNIQUE (id_stat, event_type) pour l'idempotence :
-- un même événement ne peut créditer qu'une fois. id_shop conservé pour
-- permettre le cumul PAR boutique quand le marchand désactive le cumul
-- transversal (NERIA_LOYALTY_CROSS_SHOP_ENABLED) — un id_stat donné
-- appartient déjà à une seule boutique, donc pas besoin dans la clé
-- UNIQUE elle-même, seulement pour les requêtes SUM() scopées.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_loyalty_points` (
    `id_point`    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `id_customer` INT UNSIGNED  NOT NULL,
    `id_stat`     INT UNSIGNED  NOT NULL COMMENT 'Événement de tracking source',
    `event_type`  ENUM('open','click','conversion') NOT NULL,
    `points`      TINYINT       NOT NULL DEFAULT 0,
    `id_shop`     INT UNSIGNED  NOT NULL DEFAULT 1,
    `date_add`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_point`),
    UNIQUE KEY `uq_stat_event` (`id_stat`, `event_type`),
    KEY `idx_customer` (`id_customer`),
    KEY `idx_date`     (`date_add`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Points fidélité Neria attribués par événement email';


-- ------------------------------------------------------------
-- TABLE 19 : neria_loyalty_rewards
-- Bons de réduction envoyés lorsqu'un palier est atteint.
-- Un seul enregistrement par (id_customer, tier_key, id_shop) — id_shop
-- toujours présent dans la clé ; en mode cumul transversal (réglage
-- NERIA_LOYALTY_CROSS_SHOP_ENABLED activé), la vérification applicative
-- ignore volontairement id_shop pour bloquer un 2e bon quelle que soit la
-- boutique d'origine ; en mode séparé, elle le respecte pour autoriser un
-- bon distinct par boutique.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_loyalty_rewards` (
    `id_reward`        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `id_customer`      INT UNSIGNED  NOT NULL,
    `tier_key`         VARCHAR(20)   NOT NULL COMMENT 'bronze / silver / gold',
    `tier_name`        VARCHAR(50)   NOT NULL DEFAULT '',
    `points_at_reward` INT           NOT NULL DEFAULT 0,
    `id_cart_rule`     INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'ID CartRule PS créée',
    `voucher_code`     VARCHAR(50)   NOT NULL DEFAULT '',
    `voucher_amount`   DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
    `is_percent`       TINYINT(1)    NOT NULL DEFAULT 0,
    `id_shop`          INT UNSIGNED  NOT NULL DEFAULT 1,
    `sent_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_reward`),
    UNIQUE KEY `uq_customer_tier_shop` (`id_customer`, `tier_key`, `id_shop`),
    KEY `idx_customer` (`id_customer`),
    KEY `idx_tier`     (`tier_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Bons de réduction fidélité Neria envoyés par palier';


-- ------------------------------------------------------------
-- TABLE 20 : neria_seasonal_campaign
-- Campagnes saisonnières récurrentes définies par le marchand.
-- L'envoi est automatique chaque année via le cron behavioral.
-- annual_date = 'MM-DD' (ex: '12-25' pour Noël)
-- days_before = 0 = jour J, 3 = 3 jours avant la date
-- Ciblage : segment, genre, langue, tranche d'âge
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_seasonal_campaign` (
    `id_campaign`    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `id_shop`        INT           NOT NULL DEFAULT 1,
    `name`           VARCHAR(100)  NOT NULL DEFAULT '' COMMENT 'Nom interne de la campagne',
    `template`       VARCHAR(100)  NOT NULL DEFAULT '' COMMENT 'Nom du template email à utiliser',
    `annual_date`    CHAR(5)       NOT NULL DEFAULT '01-01' COMMENT 'Date annuelle MM-DD',
    `days_before`    TINYINT       NOT NULL DEFAULT 0 COMMENT '0 = le jour J, N = N jours avant',
    `is_active`      TINYINT(1)    NOT NULL DEFAULT 1,
    `target_segment` VARCHAR(255)  NOT NULL DEFAULT '' COMMENT 'CSV segments ou vide = tous',
    `target_gender`  TINYINT       NOT NULL DEFAULT 0 COMMENT '0 = tous, 1 = hommes, 2 = femmes',
    `target_lang`    VARCHAR(255)  NOT NULL DEFAULT '' COMMENT 'CSV codes ISO langue ou vide = toutes',
    `min_age`        TINYINT       NOT NULL DEFAULT 0 COMMENT '0 = sans minimum',
    `max_age`        TINYINT       NOT NULL DEFAULT 0 COMMENT '0 = sans maximum',
    `gift_mode`      TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = mode idées cadeaux (ton offrir, segments fidèles)',
    `date_add`       DATETIME      NOT NULL,
    `date_upd`       DATETIME      NOT NULL,
    PRIMARY KEY (`id_campaign`),
    KEY `idx_shop_active` (`id_shop`, `is_active`),
    KEY `idx_date`        (`annual_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Campagnes saisonnières annuelles Neria — envoi automatique par cron';


-- ------------------------------------------------------------
-- TABLE 21 : neria_bounces
-- Adresses email invalides détectées par BounceManager.
-- Source : boîte IMAP/POP3 Return-Path ou webhook ESP.
-- Hard bounce = bloqué immédiatement.
-- Soft bounce = bloqué après N échecs (seuil configurable).
-- ------------------------------------------------------------
-- TABLE 22 : neria_certificate
-- Certificats d'authenticité émis manuellement par l'artisan.
-- Un certificat par produit / par commande.
-- Le PDF est généré avec TCPDF + signature manuscrite Neria.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_certificate` (
    `id_certificate`  INT(11)      NOT NULL AUTO_INCREMENT,
    `id_shop`         INT(11)      NOT NULL DEFAULT 1,
    `id_order`        INT(11)      NOT NULL,
    `id_product`      INT(11)      NOT NULL,
    `id_order_detail` INT(11)      NOT NULL DEFAULT 0,
    `serial_number`   VARCHAR(100) NOT NULL COMMENT 'N° de série unique (ex: LUX-2026-000042)',
    `customer_name`   VARCHAR(255) NOT NULL DEFAULT '',
    `product_name`    VARCHAR(255) NOT NULL DEFAULT '',
    `artisan_note`    TEXT         DEFAULT NULL COMMENT 'Note manuscrite optionnelle de l''artisan',
    `pdf_path`        VARCHAR(500) DEFAULT NULL COMMENT 'Chemin relatif du PDF stocké',
    `emailed`         TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = envoyé au client par email',
    `date_issued`     DATETIME     NOT NULL,
    `date_add`        DATETIME     NOT NULL,
    PRIMARY KEY (`id_certificate`),
    UNIQUE KEY `uq_serial` (`serial_number`),
    KEY `idx_order`   (`id_order`),
    KEY `idx_product` (`id_product`),
    KEY `idx_shop`    (`id_shop`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Certificats d''authenticité émis par Neria — un par produit/commande';

-- TABLE 21 : neria_bounces (ancienne numérotation conservée)
-- Adresses email invalides détectées par BounceManager.
-- Source : boîte IMAP/POP3 Return-Path ou webhook ESP.
-- Hard bounce = bloqué immédiatement.
-- Soft bounce = bloqué après N échecs (seuil configurable).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_bounces` (
    `id`             INT(11)      NOT NULL AUTO_INCREMENT,
    `email`          VARCHAR(255) NOT NULL COMMENT 'Adresse email invalide (lowercase)',
    `type`           ENUM('hard','soft') NOT NULL DEFAULT 'hard' COMMENT 'hard = permanent, soft = temporaire',
    `reason`         VARCHAR(500) DEFAULT NULL COMMENT 'Message de rejet ou code DSN',
    `source`         ENUM('imap','webhook','manual') NOT NULL DEFAULT 'imap' COMMENT 'Canal de détection',
    `bounce_count`   INT(11)      NOT NULL DEFAULT 1 COMMENT 'Nombre total de rebonds enregistrés',
    `last_bounce_at` DATETIME     NOT NULL COMMENT 'Date du dernier rebond',
    `status`         ENUM('active','ignored') NOT NULL DEFAULT 'active' COMMENT 'active = bloqué, ignored = faux positif',
    `date_add`       DATETIME     NOT NULL COMMENT 'Date de première détection',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_email` (`email`),
    KEY `idx_type`        (`type`),
    KEY `idx_status`      (`status`),
    KEY `idx_last_bounce` (`last_bounce_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Adresses email en rebond détectées par Neria — exclues automatiquement des envois';

-- ------------------------------------------------------------
-- TABLE 23 : neria_quote
-- Devis B2B suivis par Neria pour la séquence de relance.
-- Le marchand saisit ses devis depuis l'onglet Statistiques.
-- Trois emails automatiques : J-2 (48h), Jour J, prolongation.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_quote` (
    `id_quote`        INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `id_shop`         INT             NOT NULL DEFAULT 1,
    `id_customer`     INT UNSIGNED    NOT NULL,
    `quote_ref`       VARCHAR(50)     NOT NULL DEFAULT '' COMMENT 'Référence devis (ex: DEVIS-2026-042)',
    `quote_total`     DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    `id_currency`     INT UNSIGNED    NOT NULL DEFAULT 1,
    `expiry_date`     DATE            NOT NULL COMMENT 'Date d\'expiration du devis',
    `status`          ENUM('active','won','lost','expired','extended') NOT NULL DEFAULT 'active',
    `sent_48h`        TINYINT(1)      NOT NULL DEFAULT 0,
    `sent_day`        TINYINT(1)      NOT NULL DEFAULT 0,
    `sent_extension`  TINYINT(1)      NOT NULL DEFAULT 0,
    `date_add`        DATETIME        NOT NULL,
    `date_upd`        DATETIME        NOT NULL,
    PRIMARY KEY (`id_quote`),
    KEY `idx_shop_status`  (`id_shop`, `status`),
    KEY `idx_customer`     (`id_customer`),
    KEY `idx_expiry`       (`expiry_date`),
    KEY `idx_shop_expiry`  (`id_shop`, `expiry_date`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Devis B2B suivis par Neria — séquence de relance automatique 48h/J/prolongation';

-- ------------------------------------------------------------
-- TABLE 24 : neria_reconciliation
-- Séquence de réconciliation post-remboursement (J+1/J+3/J+7).
-- Une seule ligne par commande (dédup sur id_order).
-- La séquence est annulée si le client passe une nouvelle commande
-- avant l'envoi du prochain email (vérifié au moment du cron).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_reconciliation` (
    `id_reconciliation` INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `id_order`          INT UNSIGNED  NOT NULL,
    `id_customer`       INT UNSIGNED  NOT NULL,
    `id_shop`           INT           NOT NULL DEFAULT 1,
    `send_1_date`       DATE          NOT NULL COMMENT 'J+1 après le remboursement',
    `send_2_date`       DATE          NOT NULL COMMENT 'J+3 après le remboursement',
    `send_3_date`       DATE          NOT NULL COMMENT 'J+7 après le remboursement',
    `sent_1`            TINYINT(1)    NOT NULL DEFAULT 0,
    `sent_2`            TINYINT(1)    NOT NULL DEFAULT 0,
    `sent_3`            TINYINT(1)    NOT NULL DEFAULT 0,
    `status`            ENUM('active','cancelled') NOT NULL DEFAULT 'active',
    `date_add`          DATETIME      NOT NULL,
    PRIMARY KEY (`id_reconciliation`),
    UNIQUE KEY `uniq_order` (`id_order`),
    KEY `idx_status`    (`status`),
    KEY `idx_customer`  (`id_customer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Séquence de réconciliation Neria — 3 emails post-remboursement par commande';

-- ------------------------------------------------------------
-- TABLE 25 : neria_product_lifespan
-- Durée de vie estimée par produit (en jours).
-- alert_days = délai avant épuisement estimé pour envoyer l'email.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_product_lifespan` (
    `id_lifespan`   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `id_shop`       INT           NOT NULL DEFAULT 1,
    `id_product`    INT UNSIGNED  NOT NULL,
    `lifespan_days` SMALLINT UNSIGNED NOT NULL COMMENT 'Durée de vie estimée du produit en jours',
    `alert_days`    SMALLINT UNSIGNED NOT NULL DEFAULT 7 COMMENT 'Envoyer l\'alerte X jours avant la date estimée de fin',
    `date_add`      DATETIME      NOT NULL,
    `date_upd`      DATETIME      NOT NULL,
    PRIMARY KEY (`id_lifespan`),
    UNIQUE KEY `uniq_shop_product` (`id_shop`, `id_product`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Durées de vie produits Neria — rappels de renouvellement';

-- ------------------------------------------------------------
-- TABLE 26 : neria_propensity_score
-- Score de propension à l'achat (0–100) par client.
-- Recalculé chaque nuit par le cron comportemental.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_propensity_score` (
    `id_propensity`      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `id_customer`        INT UNSIGNED  NOT NULL,
    `id_shop`            INT           NOT NULL DEFAULT 1,
    `score`              TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `score_recency`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `score_frequency`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `score_engagement`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `score_seasonality`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `date_upd`           DATETIME      NOT NULL,
    PRIMARY KEY (`id_propensity`),
    UNIQUE KEY `uniq_customer_shop` (`id_customer`, `id_shop`),
    KEY `idx_score` (`score`),
    KEY `idx_shop_score` (`id_shop`, `score`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Score de propension à l\'achat Neria — fenêtres d\'achat optimales';

-- ------------------------------------------------------------
-- TABLE 27 : neria_queue
-- File d'attente des emails comportementaux programmés à l'heure
-- préférée d'achat de chaque client (fenêtre individuelle).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_queue` (
    `id_neria_queue`  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `id_customer`     INT UNSIGNED     NOT NULL,
    `id_shop`         INT UNSIGNED     NOT NULL DEFAULT 1,
    `id_lang`         INT UNSIGNED     NOT NULL DEFAULT 1,
    `template`        VARCHAR(100)     NOT NULL,
    `recipient_email` VARCHAR(255)     NOT NULL,
    `recipient_name`  VARCHAR(255)     NOT NULL DEFAULT '',
    `vars_json`       MEDIUMTEXT,
    `ref_id`          INT UNSIGNED     DEFAULT NULL,
    `send_at`         DATETIME         NOT NULL,
    `status`          ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    `attempts`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `error`           TEXT             DEFAULT NULL,
    `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `sent_at`         DATETIME         DEFAULT NULL,
    PRIMARY KEY (`id_neria_queue`),
    KEY `idx_send_at_status` (`send_at`, `status`),
    KEY `idx_customer`       (`id_customer`),
    KEY `idx_status`         (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='File d\'attente d\'emails Neria — envoi à la fenêtre d\'achat individuelle';

-- ------------------------------------------------------------
-- TABLE 28 : neria_collection
-- Collections de produits définies manuellement par le marchand.
-- Neria détecte les clients ayant acheté N-1 pièces et les relance.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_collection` (
    `id_neria_collection` INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `name`                VARCHAR(255)     NOT NULL,
    `product_ids`         TEXT             NOT NULL COMMENT 'JSON array of product IDs',
    `active`              TINYINT(1)       NOT NULL DEFAULT 1,
    `created_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_neria_collection`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Collections produits Neria — suggestion de complétion';

-- ------------------------------------------------------------
-- TABLE 29 : neria_collection_sent
-- Déduplication : un email par collection × client.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_collection_sent` (
    `id`                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `id_neria_collection` INT UNSIGNED     NOT NULL,
    `id_customer`         INT UNSIGNED     NOT NULL,
    `sent_at`             DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_col_customer` (`id_neria_collection`, `id_customer`),
    KEY `idx_customer`    (`id_customer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Déduplication des emails de complétion de collection Neria';

-- ------------------------------------------------------------
-- TABLE 30 : neria_look_rule
-- Règles d'association catégorie → produits complémentaires.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_look_rule` (
    `id_neria_look_rule` INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `id_category`        INT UNSIGNED     NOT NULL,
    `product_ids`        TEXT             NOT NULL COMMENT 'JSON array, max 3 product IDs',
    `active`             TINYINT(1)       NOT NULL DEFAULT 1,
    `created_at`         DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_neria_look_rule`),
    KEY `idx_category`   (`id_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Règles "Complétez votre look" Neria — catégorie → produits suggérés';

-- ------------------------------------------------------------
-- TABLE 31 : neria_look_sent
-- Déduplication : un seul email par commande.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_look_sent` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_order`    INT UNSIGNED NOT NULL,
    `id_customer` INT UNSIGNED NOT NULL,
    `sent_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_order` (`id_order`),
    KEY `idx_customer`    (`id_customer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Déduplication des emails "Complétez votre look" Neria';

-- ------------------------------------------------------------
-- TABLE 32 : neria_waitlist
-- Liste d'attente : clients souhaitant être notifiés au retour en stock.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_waitlist` (
    `id_neria_waitlist` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_customer`       INT UNSIGNED NOT NULL,
    `id_product`        INT UNSIGNED NOT NULL,
    `id_shop`           INT UNSIGNED NOT NULL DEFAULT 1,
    `registered_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `notified_at`       DATETIME     NULL DEFAULT NULL,
    `claim_started_at`  DATETIME     NULL DEFAULT NULL COMMENT 'Réservation posée avant envoi, distincte de notified_at (posée après confirmation) — permet de détecter un crash entre les deux sans risquer de redéclencher un envoi déjà réussi',
    PRIMARY KEY (`id_neria_waitlist`),
    UNIQUE KEY `uq_customer_product_shop` (`id_customer`, `id_product`, `id_shop`),
    KEY `idx_product`   (`id_product`),
    KEY `idx_notified`  (`notified_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Liste d\'attente produits Neria — notification retour en stock';

-- ------------------------------------------------------------
-- TABLE 33 : neria_preferences
-- Centre de préférences email : opt-in/out par catégorie.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_preferences` (
    `id_preference` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shop`       INT UNSIGNED NOT NULL DEFAULT 1,
    `id_customer`   INT UNSIGNED NOT NULL DEFAULT 0,
    `email`         VARCHAR(150) NOT NULL DEFAULT '',
    `category`      ENUM('cart','post','loyalty','behav','season','b2b','newsletter') NOT NULL,
    `subscribed`    TINYINT(1) NOT NULL DEFAULT 1,
    `date_upd`      DATETIME NOT NULL,
    PRIMARY KEY (`id_preference`),
    -- id_customer=0 (compte supprimé/RGPD, jamais inscrit) ne suffisait pas
    -- à distinguer deux clients différents : la clé doit inclure l'email,
    -- sinon la préférence d'un client "invité" écrase celle d'un autre.
    UNIQUE KEY `uq_shop_customer_email_cat` (`id_shop`,`id_customer`,`email`,`category`),
    KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Centre de préférences email Neria — opt-in/out par catégorie';

-- ------------------------------------------------------------
-- TABLE 34 : neria_abtest_history
-- Historique des tests A/B terminés (archivage après application
-- du gagnant). Alimentée par ABTestManager.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_abtest_history` (
    `id_history`      INT(11)        NOT NULL AUTO_INCREMENT,
    `id_shop`         INT(11)        NOT NULL DEFAULT 1,
    `template`        VARCHAR(100)   NOT NULL,
    `variant_a_name`  VARCHAR(100)   NOT NULL DEFAULT '',
    `variant_b_name`  VARCHAR(100)   NOT NULL DEFAULT '',
    `split_percent`   TINYINT(3)     NOT NULL DEFAULT 50,
    `sent_a`          INT(11)        NOT NULL DEFAULT 0,
    `sent_b`          INT(11)        NOT NULL DEFAULT 0,
    `rate_open_a`     DECIMAL(5,2)   NOT NULL DEFAULT 0,
    `rate_open_b`     DECIMAL(5,2)   NOT NULL DEFAULT 0,
    `rate_click_a`    DECIMAL(5,2)   NOT NULL DEFAULT 0,
    `rate_click_b`    DECIMAL(5,2)   NOT NULL DEFAULT 0,
    `revenue_a`       DECIMAL(10,2)  NOT NULL DEFAULT 0,
    `revenue_b`       DECIMAL(10,2)  NOT NULL DEFAULT 0,
    `winner`          CHAR(1)        NULL,
    `confidence`      TINYINT(3)     NULL,
    `applied`         TINYINT(1)     NOT NULL DEFAULT 0,
    `date_start`      DATETIME       NULL,
    `date_end`        DATETIME       NOT NULL,
    PRIMARY KEY (`id_history`),
    INDEX `idx_shop_template` (`id_shop`, `template`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Historique des tests A/B terminés';

-- ------------------------------------------------------------
-- TABLE 35 : neria_cron_health
-- Monitoring des crons internes (behavioral, calendar, webhook)
-- pour le score de santé du Watchdog.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_cron_health` (
    `id_shop`     INT(11)      NOT NULL DEFAULT 1,
    `cron_key`    VARCHAR(50)  NOT NULL,
    `last_run`    DATETIME     NULL,
    `last_status` ENUM('ok','warning','error') NOT NULL DEFAULT 'ok',
    `last_count`  INT(11)      NOT NULL DEFAULT 0,
    PRIMARY KEY (`id_shop`, `cron_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Monitoring des crons internes Neria (Watchdog)';

-- ------------------------------------------------------------
-- TABLE 36 : neria_voice_profile
-- Empreinte vocale de la marque, par langue : mots bannis/préférés
-- et notes de ton — sert à détecter les incohérences éditoriales
-- dans les traductions.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_voice_profile` (
    `id_voice_profile` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shop`          INT UNSIGNED NOT NULL DEFAULT 1,
    `lang`             VARCHAR(5)   NOT NULL,
    `banned_words`     TEXT         DEFAULT NULL,
    `preferred_words`  TEXT         DEFAULT NULL,
    `tone_notes`       TEXT         DEFAULT NULL,
    `date_upd`         DATETIME     NOT NULL,
    PRIMARY KEY (`id_voice_profile`),
    UNIQUE KEY `uq_shop_lang` (`id_shop`, `lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- TABLE 37 : neria_birthday_voucher
-- Bon de réduction anniversaire (CartRule PS réel) — anti-doublon
-- par (id_customer, year, id_shop) : un seul bon généré par client, par
-- an ET par boutique — cohérent avec sendBirthdays() (BehavioralCronManager)
-- qui n'envoie déjà l'email qu'aux clients de la boutique courante.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_birthday_voucher` (
    `id_voucher`   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `id_customer`  INT UNSIGNED  NOT NULL,
    `year`         SMALLINT UNSIGNED NOT NULL,
    `id_cart_rule` INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'ID CartRule PS créée',
    `voucher_code` VARCHAR(50)   NOT NULL DEFAULT '',
    `id_shop`      INT UNSIGNED  NOT NULL DEFAULT 1,
    `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_voucher`),
    UNIQUE KEY `uq_customer_year_shop` (`id_customer`, `year`, `id_shop`),
    KEY `idx_customer` (`id_customer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Bon de réduction anniversaire (CartRule PS), anti-doublon par client, année et boutique';

-- ------------------------------------------------------------
-- TABLE 38 : neria_milestone_voucher
-- Bon de réduction sur palier de commandes (CartRule PS réel, optionnel,
-- activé par le marchand) — anti-doublon par (id_customer, milestone,
-- id_shop) : un seul bon généré par client, par palier atteint (5/10/25/
-- 50/100) ET par boutique — cohérent avec OrderTriggersManager::
-- handleNewOrder() qui compte déjà les commandes du palier UNIQUEMENT
-- pour la boutique courante (WHERE id_shop = ...), donc "palier 5" en
-- boutique A et "palier 5" en boutique B sont deux jalons distincts, pas
-- le même — contrairement aux points de fidélité (cumul transversal
-- configurable), pas d'ambiguïté ici : toujours par boutique.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `PREFIX_neria_milestone_voucher` (
    `id_voucher`   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `id_customer`  INT UNSIGNED  NOT NULL,
    `milestone`    SMALLINT UNSIGNED NOT NULL,
    `id_cart_rule` INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'ID CartRule PS créée',
    `voucher_code` VARCHAR(50)   NOT NULL DEFAULT '',
    `id_shop`      INT UNSIGNED  NOT NULL DEFAULT 1,
    `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_voucher`),
    UNIQUE KEY `uq_customer_milestone_shop` (`id_customer`, `milestone`, `id_shop`),
    KEY `idx_customer` (`id_customer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Bon de réduction par palier de commandes (CartRule PS), anti-doublon par client, palier et boutique';
