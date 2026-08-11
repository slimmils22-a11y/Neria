<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Upgrade 1.0.40
 *
 * Script de rattrapage (round 146, audit complet des 39 scripts upgrade-*)
 * pour 3 bugs historiques distincts, jamais corrigés jusqu'ici car déjà
 * exécutés sur les installs existantes :
 *
 * 1. upgrade-1.0.29.php ajoutait id_shop DEFAULT 1 sur 5 tables
 *    (neria_behavioral_sent, neria_birthday_voucher, neria_milestone_voucher,
 *    neria_loyalty_points, neria_loyalty_rewards) SANS backfill réel — toutes
 *    les lignes historiques d'une install multi-boutique antérieure à ce
 *    scoping se sont retrouvées taguées boutique 1, y compris celles
 *    réellement générées pour d'autres boutiques (doublons d'envoi possibles,
 *    corruption de solde de fidélité si NERIA_LOYALTY_CROSS_SHOP_ENABLED est
 *    ensuite désactivé). Ici : backfill réel via JOIN ps_orders/ps_cart pour
 *    neria_behavioral_sent sur les templates dont ref_id est identifiable
 *    (post_purchase_care/review, first_anniversary, relationship_anniversary,
 *    refund_reconciliation_1/2/3 → id_order ; abandoned_cart_1/2/3,
 *    checkout_abandonment → id_cart). Pour les 4 autres tables (aucune
 *    référence commande/panier dans leur clé), aucun backfill fiable n'existe
 *    — journalisé via Watchdog pour transparence marchand plutôt que deviné.
 *
 * 2. upgrade-1.0.1/2/3/34.php activaient 4 réglages par défaut via
 *    Configuration::updateValue($key, 1) SANS id_shop ni updateGlobalValue()
 *    — la valeur n'était écrite que pour la boutique du contexte BO actif au
 *    moment de l'upgrade, pas globalement. Sur une install multi-boutique,
 *    les autres boutiques n'ont jamais eu ces fonctionnalités actives par
 *    défaut, silencieusement. Ici : seed de la valeur globale si elle n'a
 *    jamais été définie (même garde-fou "ne touche que ce qui est absent"
 *    que upgrade-1.0.30.php).
 *
 * 3. upgrade-1.0.25/28/29/38.php utilisent `$ok = $ok && $db->execute(...)`
 *    pour DROP puis ADD d'une contrainte UNIQUE — un échec transitoire du
 *    DROP (verrou InnoDB) court-circuite l'ADD qui suit (opérande droite de
 *    && jamais évaluée), laissant la table SANS contrainte de déduplication
 *    jusqu'au prochain déclenchement de l'upgrade. Ici : vérifie l'existence
 *    réelle de chacune des 7 contraintes concernées et la recrée si absente
 *    (purge des doublons résiduels au préalable, motif déjà validé dans
 *    upgrade-1.0.36.php pour neria_queue).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Vérifie qu'une contrainte UNIQUE existe réellement sur une table et la
 * recrée si absente — filet de sécurité rétroactif pour le bug 3 (voir
 * docblock). Purge les doublons résiduels (le plus ancien conservé, par
 * clé primaire croissante) avant de recréer la contrainte, sinon l'ADD
 * échouerait de nouveau silencieusement sur les doublons accumulés pendant
 * la fenêtre sans contrainte.
 */
function neria_upgrade_1_0_40_ensure_unique_key(
    Db $db,
    string $table,
    string $keyName,
    array $columns,
    string $primaryKey
): bool {
    $hasKey = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$table}'
          AND INDEX_NAME   = '{$keyName}'
    ");
    if ($hasKey) {
        return true;
    }

    $tableExists = (bool) $db->getValue("SHOW TABLES LIKE '{$table}'");
    if (!$tableExists) {
        return true;
    }

    $onClause = implode(' AND ', array_map(
        fn($c) => "t1.`{$c}` = t2.`{$c}`",
        $columns
    ));
    $db->execute("
        DELETE t1 FROM `{$table}` t1
        INNER JOIN `{$table}` t2
            ON {$onClause}
           AND t1.`{$primaryKey}` > t2.`{$primaryKey}`
    ");

    $colsSql = '`' . implode('`, `', $columns) . '`';
    return (bool) $db->execute("
        ALTER TABLE `{$table}`
        ADD UNIQUE KEY `{$keyName}` ({$colsSql})
    ");
}

function upgrade_module_1_0_40(Neria $module): bool
{
    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $ok     = true;
    $watchdog = class_exists('WatchdogManager') ? new WatchdogManager($module) : null;

    // ── Bug 2 : 4 toggles jamais scopés globalement ─────────────────────
    foreach ([
        'NERIA_QUOTE_REMINDERS_ENABLED',
        'NERIA_REFUND_RECONCILIATION_ENABLED',
        'NERIA_LIFESPAN_ENABLED',
        'NERIA_GDPR_AUTO_PURGE_ENABLED',
    ] as $key) {
        if (Configuration::getGlobalValue($key) === false) {
            Configuration::updateGlobalValue($key, 1);
        }
    }

    // ── Bug 3 : 7 contraintes UNIQUE potentiellement manquantes ─────────
    $uniqueKeys = [
        [$prefix . 'neria_behavioral_sent',   'uq_customer_template_ref_shop', ['id_customer', 'template', 'ref_id', 'id_shop'], 'id'],
        [$prefix . 'neria_birthday_voucher',  'uq_customer_year_shop',         ['id_customer', 'year', 'id_shop'],               'id_voucher'],
        [$prefix . 'neria_milestone_voucher', 'uq_customer_milestone_shop',    ['id_customer', 'milestone', 'id_shop'],          'id_voucher'],
        [$prefix . 'neria_loyalty_rewards',   'uq_customer_tier_shop',         ['id_customer', 'tier_key', 'id_shop'],           'id_reward'],
        [$prefix . 'neria_preferences',       'uq_shop_customer_email_cat',    ['id_shop', 'id_customer', 'email', 'category'],  'id_preference'],
        [$prefix . 'neria_waitlist',          'uq_customer_product_shop',      ['id_customer', 'id_product', 'id_shop'],         'id_neria_waitlist'],
        [$prefix . 'neria_collection_sent',   'uq_col_customer_shop',          ['id_neria_collection', 'id_customer', 'id_shop'], 'id'],
    ];
    foreach ($uniqueKeys as [$table, $keyName, $columns, $primaryKey]) {
        $hasPk = (bool) $db->getValue("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = '{$table}'
              AND COLUMN_NAME  = '{$primaryKey}'
        ");
        if (!$hasPk) {
            // Nom de colonne primaire différent de celui attendu (schéma déjà
            // modifié entre-temps) — on ne devine pas, on journalise et on
            // passe à la suivante plutôt que de risquer une purge incorrecte.
            if ($watchdog) {
                $watchdog->warning(
                    "Upgrade 1.0.40 : vérification de la contrainte {$keyName} ignorée sur {$table} (colonne clé primaire '{$primaryKey}' introuvable).",
                    '', 'Upgrade1040'
                );
            }
            continue;
        }
        $ok = $ok && neria_upgrade_1_0_40_ensure_unique_key($db, $table, $keyName, $columns, $primaryKey);
    }

    // ── Bug 1 : backfill id_shop sur neria_behavioral_sent ──────────────
    // Uniquement utile sur une install réellement multi-boutique — sur une
    // install mono-boutique, id_shop=1 partout est déjà correct par
    // construction, aucun backfill n'est nécessaire.
    $shopCount = (int) $db->getValue('SELECT COUNT(*) FROM `' . $prefix . 'shop` WHERE `active` = 1');
    if ($shopCount > 1) {
        $bhTable = $prefix . 'neria_behavioral_sent';
        $bhExists = (bool) $db->getValue("SHOW TABLES LIKE '{$bhTable}'");
        if ($bhExists) {
            // Templates dont ref_id = id_order (voir sql/install.sql, table 12).
            $orderLinkedTemplates = [
                'post_purchase_care', 'post_purchase_review',
                'first_anniversary', 'relationship_anniversary',
                'refund_reconciliation_1', 'refund_reconciliation_2', 'refund_reconciliation_3',
            ];
            $orderList = implode(',', array_map(fn($t) => "'" . pSQL($t) . "'", $orderLinkedTemplates));
            $db->execute("
                UPDATE `{$bhTable}` bs
                INNER JOIN `{$prefix}orders` o ON o.id_order = bs.ref_id
                SET bs.id_shop = o.id_shop
                WHERE bs.template IN ({$orderList})
                  AND bs.id_shop != o.id_shop
            ");

            // Templates dont ref_id = id_cart.
            $cartLinkedTemplates = ['abandoned_cart_1', 'abandoned_cart_2', 'abandoned_cart_3', 'checkout_abandonment'];
            $cartList = implode(',', array_map(fn($t) => "'" . pSQL($t) . "'", $cartLinkedTemplates));
            $db->execute("
                UPDATE `{$bhTable}` bs
                INNER JOIN `{$prefix}cart` c ON c.id_cart = bs.ref_id
                SET bs.id_shop = c.id_shop
                WHERE bs.template IN ({$cartList})
                  AND bs.id_shop != c.id_shop
            ");

            // Templates restants (YEAR pour birthday/win_back, et les 4
            // autres tables sans backfill possible) : aucune source fiable
            // pour reconstituer la vraie boutique — journalise le volume
            // concerné pour que le marchand en soit informé, plutôt que de
            // deviner silencieusement.
            $ambiguousCount = (int) $db->getValue("
                SELECT COUNT(*) FROM `{$bhTable}`
                WHERE template NOT IN ({$orderList})
                  AND template NOT IN ({$cartList})
            ");
            $otherTables = [
                $prefix . 'neria_birthday_voucher',
                $prefix . 'neria_milestone_voucher',
                $prefix . 'neria_loyalty_points',
                $prefix . 'neria_loyalty_rewards',
            ];
            foreach ($otherTables as $t) {
                if ((bool) $db->getValue("SHOW TABLES LIKE '{$t}'")) {
                    $ambiguousCount += (int) $db->getValue("SELECT COUNT(*) FROM `{$t}`");
                }
            }
            if ($ambiguousCount > 0 && $watchdog) {
                $watchdog->warning(
                    "Upgrade 1.0.40 : installation multi-boutique détectée ({$shopCount} boutiques actives). {$ambiguousCount} ligne(s) historique(s) (anniversaires/fidélité/paliers/relances de réactivation) n'ont pas pu être réattribuées avec certitude à leur boutique d'origine — elles restent sur la boutique par défaut de la migration d'origine (upgrade 1.0.29). Impact possible : un email déjà envoyé à un client avant cette mise à jour pourrait être renvoyé une fois sur une autre boutique du même client partagé. Contactez le support si besoin d'une correction manuelle ciblée.",
                    '', 'Upgrade1040'
                );
            }
        }
    }

    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return $ok && $module->importTranslations();
}
