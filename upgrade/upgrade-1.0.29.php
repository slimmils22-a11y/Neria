<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Upgrade 1.0.29
 *
 * Suite audit complet (2026-07-20) de toutes les clés UNIQUE portant sur
 * id_customer : plusieurs tables partageaient le même défaut que
 * neria_waitlist (upgrade 1.0.28) — aucune notion de boutique, alors que
 * les méthodes qui les alimentent (BehavioralCronManager, notamment) n'ont
 * elles-mêmes aucun filtre id_shop. Sur une boutique multi-boutique
 * (clients partagés), un client de la boutique 2 pouvait ne jamais
 * recevoir un email comportemental (anniversaire, relance, win-back...)
 * déjà "marqué envoyé" par la boutique 1, ou recevoir un bon/email à
 * l'image de la mauvaise boutique.
 *
 * 1. neria_behavioral_sent (id_customer, template, ref_id) → + id_shop.
 *    16 méthodes de BehavioralCronManager corrigées en parallèle pour
 *    filtrer leurs SELECT par id_shop.
 * 2. neria_birthday_voucher (id_customer, year) → + id_shop. Cohérent
 *    avec sendBirthdays() qui ne cible déjà que la boutique courante.
 * 3. neria_milestone_voucher (id_customer, milestone) → + id_shop.
 *    Cohérent avec OrderTriggersManager::handleNewOrder() qui compte déjà
 *    les commandes du palier UNIQUEMENT pour la boutique courante.
 * 4. neria_loyalty_points : + colonne id_shop (pas de changement de clé,
 *    id_stat suffit déjà à l'idempotence) — nécessaire pour permettre un
 *    SUM(points) scopé par boutique.
 * 5. neria_loyalty_rewards (id_customer, tier_key) → + id_shop dans la
 *    clé. Nouveau réglage marchand NERIA_LOYALTY_CROSS_SHOP_ENABLED
 *    (activé par défaut, préserve le comportement actuel) : la fidélité
 *    reste cumulée sur toutes les boutiques par défaut, mais peut être
 *    séparée par boutique si le marchand le souhaite (bouton Configuration).
 *
 * Nettoyage avant migration : sans risque pour toutes ces tables — les
 * anciennes clés UNIQUE (sans id_shop) garantissent qu'aucune ligne
 * existante ne peut entrer en collision sur les nouvelles clés, plus
 * larges. Les installations mono-boutique existantes sont rattachées à
 * la boutique 1 par défaut (DEFAULT 1 sur la nouvelle colonne).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Ajoute la colonne id_shop (si absente) puis remplace l'ancienne clé
 * UNIQUE par une nouvelle incluant id_shop. Réutilisé pour les 4 tables
 * de ce chantier — même motif exact que l'upgrade 1.0.28 (neria_waitlist).
 */
function neria_upgrade_1_0_29_add_shop_key(
    Db $db,
    string $table,
    string $afterColumn,
    string $oldKeyName,
    string $newKeyName,
    array $newKeyColumns
): bool {
    $ok = true;

    $hasColumn = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$table}'
          AND COLUMN_NAME  = 'id_shop'
    ");
    if (!$hasColumn) {
        $ok = $ok && $db->execute("
            ALTER TABLE `{$table}`
            ADD COLUMN `id_shop` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `{$afterColumn}`
        ");
    }

    if ($oldKeyName !== '') {
        $hasOldKey = (bool) $db->getValue("
            SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = '{$table}'
              AND INDEX_NAME   = '{$oldKeyName}'
        ");
        if ($hasOldKey) {
            $ok = $ok && $db->execute("ALTER TABLE `{$table}` DROP INDEX `{$oldKeyName}`");
        }
    }

    $hasNewKey = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$table}'
          AND INDEX_NAME   = '{$newKeyName}'
    ");
    if (!$hasNewKey) {
        $cols = '`' . implode('`, `', $newKeyColumns) . '`';
        $ok = $ok && $db->execute("
            ALTER TABLE `{$table}`
            ADD UNIQUE KEY `{$newKeyName}` ({$cols})
        ");
    }

    return $ok;
}

function upgrade_module_1_0_29(Neria $module): bool
{
    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $ok     = true;

    $ok = $ok && neria_upgrade_1_0_29_add_shop_key(
        $db, $prefix . 'neria_behavioral_sent', 'ref_id',
        'uq_customer_template_ref', 'uq_customer_template_ref_shop',
        ['id_customer', 'template', 'ref_id', 'id_shop']
    );

    $ok = $ok && neria_upgrade_1_0_29_add_shop_key(
        $db, $prefix . 'neria_birthday_voucher', 'voucher_code',
        'uq_customer_year', 'uq_customer_year_shop',
        ['id_customer', 'year', 'id_shop']
    );

    $ok = $ok && neria_upgrade_1_0_29_add_shop_key(
        $db, $prefix . 'neria_milestone_voucher', 'voucher_code',
        'uq_customer_milestone', 'uq_customer_milestone_shop',
        ['id_customer', 'milestone', 'id_shop']
    );

    // neria_loyalty_points : colonne seule, la clé UNIQUE existante
    // (id_stat, event_type) reste correcte telle quelle.
    $hasPointsShop = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$prefix}neria_loyalty_points'
          AND COLUMN_NAME  = 'id_shop'
    ");
    if (!$hasPointsShop) {
        $ok = $ok && $db->execute("
            ALTER TABLE `{$prefix}neria_loyalty_points`
            ADD COLUMN `id_shop` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `points`
        ");
    }

    $ok = $ok && neria_upgrade_1_0_29_add_shop_key(
        $db, $prefix . 'neria_loyalty_rewards', 'is_percent',
        'uq_customer_tier', 'uq_customer_tier_shop',
        ['id_customer', 'tier_key', 'id_shop']
    );

    // Nouveau réglage marchand : cumul transversal activé par défaut
    // (préserve le comportement actuel — getCustomerPoints() sommait déjà
    // globalement toutes boutiques confondues avant ce chantier).
    if (Configuration::get('NERIA_LOYALTY_CROSS_SHOP_ENABLED') === false) {
        Configuration::updateGlobalValue('NERIA_LOYALTY_CROSS_SHOP_ENABLED', 1);
    }

    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return $ok && $module->importTranslations();
}
