<?php
/**
 * Régression : AbTestManager::deleteTests() désétiquetait les événements
 * neria_stat d'un test supprimé via `SET abtest_variant = NULL`, alors que
 * la colonne est déclarée `NOT NULL DEFAULT ''` (sql/install.sql). En mode
 * SQL strict (MySQL 8+/MariaDB récent, défaut sur les installs récentes),
 * cet UPDATE échouait intégralement (colonne ne peut être NULL) — les
 * lignes gardaient leur ancienne variante A/B, et un nouveau test relancé
 * plus tard sur le même template récupérait silencieusement les anciens
 * événements dans son calcul de significativité (StatsManager::
 * getABTestReport()), pouvant faire déclarer un "gagnant" erroné.
 *
 * Corrigé le 15/08/2026 (round 177) : `SET abtest_variant = ''` au lieu de
 * `NULL`.
 *
 * Test comportemental réel : crée un test A/B + des événements neria_stat
 * réels avec abtest_variant='A'/'B', appelle deleteTests(), vérifie que les
 * lignes neria_stat sont bien désétiquetées (abtest_variant='') et non plus
 * filtrées par StatsManager::getABTestReport() (IN ('A','B')).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/AbTestManager.php';

    $db      = neria_test_db();
    $prefix  = neria_test_prefix();
    $module  = neria_test_module();
    $idShop  = (int) Context::getContext()->shop->id;
    $template = 'regtest349_' . substr(uniqid(), -8);

    $mgr = new AbTestManager($module);

    try {
        $now = date('Y-m-d H:i:s');
        $db->execute(
            "INSERT INTO {$prefix}neria_abtest
                (id_shop, template, variant, variant_name, description, split_percent, is_active, date_add, date_upd)
             VALUES
                ({$idShop}, '" . pSQL($template) . "', 'A', 'Variante A', '', 50, 1, '{$now}', '{$now}'),
                ({$idShop}, '" . pSQL($template) . "', 'B', 'Variante B', '', 50, 1, '{$now}', '{$now}')"
        );

        $db->execute(
            "INSERT INTO {$prefix}neria_stat
                (id_shop, template, lang, tracking_token, event_type, abtest_variant, date_add)
             VALUES
                ({$idShop}, '" . pSQL($template) . "', 'fr', '" . bin2hex(random_bytes(16)) . "', 'sent', 'A', '{$now}'),
                ({$idShop}, '" . pSQL($template) . "', 'fr', '" . bin2hex(random_bytes(16)) . "', 'sent', 'B', '{$now}')"
        );

        $ok = $mgr->deleteTests($template);
        neria_assert($ok === true, "deleteTests() a échoué — jeu de test invalide ou régression du correctif round 177");

        $remainingTagged = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_stat
             WHERE id_shop = {$idShop} AND template = '" . pSQL($template) . "' AND abtest_variant IN ('A', 'B')"
        );

        neria_assert(
            $remainingTagged === 0,
            "AbTestManager::deleteTests() n'a pas désétiqueté les événements neria_stat du test supprimé (obtenu {$remainingTagged} lignes encore taguées 'A'/'B') — régression du bug corrigé le 15/08/2026 (round 177) : SET abtest_variant = NULL échoue silencieusement en mode SQL strict (colonne NOT NULL), laissant les anciens événements polluer le calcul de significativité d'un futur test sur ce même template"
        );

        return [
            'pass'    => true,
            'message' => "AbTestManager::deleteTests() désétiquette bien les événements neria_stat via abtest_variant = '' (pas NULL) — bug corrigé le 15/08/2026 (round 177)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_shop = {$idShop} AND template = '" . pSQL($template) . "'");
        $db->execute("DELETE FROM {$prefix}neria_abtest WHERE id_shop = {$idShop} AND template = '" . pSQL($template) . "'");
    }
}
