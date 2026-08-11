<?php
/**
 * Régression : upgrade_module_1_0_40() (script de rattrapage round 146
 * pour 3 bugs historiques distincts des scripts upgrade-1.0.1/2/3/25/28/
 * 29/34/38.php) doit s'exécuter sans erreur et produire un état correct,
 * de façon idempotente.
 *
 * Contrairement à la plupart des tests d'upgrade de cette suite (voir
 * test_138, qui reste structurel car invoquer la fonction réelle "toucherait
 * la config globale réelle... trop invasif"), ce script EST volontairement
 * conçu pour être invoqué en conditions réelles sans risque : chaque
 * opération est un "corrige seulement si manquant/incorrect" idempotent
 * (Configuration::getGlobalValue()===false avant d'écrire, vérification
 * d'existence de contrainte avant recréation, UPDATE ... WHERE id_shop !=
 * avant backfill) — l'invoquer réellement ici teste exactement le
 * comportement de production, sans laisser d'état de test à nettoyer.
 *
 * Test comportemental réel : invoque upgrade_module_1_0_40() deux fois de
 * suite (la 2e fois doit être un no-op sans erreur — idempotence réelle),
 * vérifie que les 4 clés de config globales valent bien '1', et que les 7
 * contraintes UNIQUE existent bien en base après coup.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/upgrade/upgrade-1.0.40.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();

    $result1 = upgrade_module_1_0_40($module);
    neria_assert($result1 === true, "upgrade_module_1_0_40() a échoué au premier appel");

    $result2 = upgrade_module_1_0_40($module);
    neria_assert($result2 === true, "upgrade_module_1_0_40() a échoué au second appel — régression : le script n'est plus idempotent (une contrainte/valeur déjà présente ne devrait jamais faire échouer un rappel)");

    foreach ([
        'NERIA_QUOTE_REMINDERS_ENABLED',
        'NERIA_REFUND_RECONCILIATION_ENABLED',
        'NERIA_LIFESPAN_ENABLED',
        'NERIA_GDPR_AUTO_PURGE_ENABLED',
    ] as $key) {
        $val = Configuration::getGlobalValue($key);
        neria_assert(
            (string) $val === '1',
            "{$key} vaut '" . var_export($val, true) . "' au lieu de '1' globalement après upgrade_module_1_0_40() — régression du bug corrigé le 09/08/2026 (round 146) : ce réglage pourrait de nouveau rester inactif par défaut sur certaines boutiques d'une install multi-boutique"
        );
    }

    $uniqueKeys = [
        [$prefix . 'neria_behavioral_sent',   'uq_customer_template_ref_shop'],
        [$prefix . 'neria_birthday_voucher',  'uq_customer_year_shop'],
        [$prefix . 'neria_milestone_voucher', 'uq_customer_milestone_shop'],
        [$prefix . 'neria_loyalty_rewards',   'uq_customer_tier_shop'],
        [$prefix . 'neria_preferences',       'uq_shop_customer_email_cat'],
        [$prefix . 'neria_waitlist',          'uq_customer_product_shop'],
        [$prefix . 'neria_collection_sent',   'uq_col_customer_shop'],
    ];
    foreach ($uniqueKeys as [$table, $keyName]) {
        $exists = (bool) $db->getValue(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND INDEX_NAME = '{$keyName}'"
        );
        neria_assert(
            $exists,
            "la contrainte UNIQUE '{$keyName}' est absente de {$table} après upgrade_module_1_0_40() — régression du bug corrigé le 09/08/2026 (round 146) : la déduplication anti-doublon ne serait de nouveau plus garantie sur cette table"
        );
    }

    return [
        'pass'    => true,
        'message' => "upgrade_module_1_0_40() s'exécute correctement et de façon idempotente : 4 réglages globaux corrects, 7 contraintes UNIQUE vérifiées/recréées",
    ];
}
