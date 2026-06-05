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
    `abtest_variant`    CHAR(1)         NOT NULL DEFAULT '' COMMENT 'A, B ou vide si pas de test',
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
    INDEX `idx_shop_template_event` (`id_shop`, `template`, `event_type`)
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
    (1, 'founder_title',    '',  'Titre du fondateur (ex: Fondatrice & Directrice Artistique)', NOW(), NOW());


-- Occasions calendaires par défaut
INSERT INTO `PREFIX_neria_calendar_event`
    (`id_shop`, `event_key`, `lang`, `country_code`, `template`, `send_days_before`, `is_active`, `date_add`, `date_upd`)
VALUES
    (1, 'eid',              'ar',  'SA',  'eid',              7, 1, NOW(), NOW()),
    (1, 'eid',              'ar',  'AE',  'eid',              7, 1, NOW(), NOW()),
    (1, 'eid',              'ar',  'MA',  'eid',              7, 1, NOW(), NOW()),
    (1, 'lunar_new_year',   'zh',  'CN',  'lunar_new_year',   7, 1, NOW(), NOW()),
    (1, 'lunar_new_year',   'tw',  'TW',  'lunar_new_year',   7, 1, NOW(), NOW()),
    (1, 'lunar_new_year',   'ko',  'KR',  'lunar_new_year',   7, 1, NOW(), NOW()),
    (1, 'christmas',        'fr',  'FR',  'christmas',        5, 1, NOW(), NOW()),
    (1, 'christmas',        'en',  'GB',  'christmas',        5, 1, NOW(), NOW()),
    (1, 'christmas',        'de',  'DE',  'christmas',        5, 1, NOW(), NOW()),
    (1, 'christmas',        'it',  'IT',  'christmas',        5, 1, NOW(), NOW()),
    (1, 'christmas',        'es',  'ES',  'christmas',        5, 1, NOW(), NOW()),
    (1, 'halloween',        'en',  'US',  'halloween',        7, 1, NOW(), NOW()),
    (1, 'halloween',        'en',  'GB',  'halloween',        7, 1, NOW(), NOW()),
    (1, 'valentine',        'fr',  'FR',  'valentine',        7, 1, NOW(), NOW()),
    (1, 'valentine',        'en',  'US',  'valentine',        7, 1, NOW(), NOW()),
    (1, 'valentine',        'en',  'GB',  'valentine',        7, 1, NOW(), NOW()),
    (1, 'ramadan',          'ar',  'SA',  'ramadan',         14, 1, NOW(), NOW()),
    (1, 'ramadan',          'ar',  'AE',  'ramadan',         14, 1, NOW(), NOW());