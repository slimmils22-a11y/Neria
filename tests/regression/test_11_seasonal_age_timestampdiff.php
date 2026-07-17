<?php
/** Régression : SeasonalCampaignManager doit calculer l'âge via TIMESTAMPDIFF, pas YEAR()-YEAR(). */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();

    $origBirthday = $db->getValue("SELECT birthday FROM {$prefix}customer WHERE id_customer={$idCustomer}");
    $birthday = date('Y-m-d', strtotime('+3 days -18 years')); // 18 ans dans 3 jours -> 17 ans aujourd'hui
    $db->execute("UPDATE {$prefix}customer SET birthday = '{$birthday}' WHERE id_customer={$idCustomer}");

    try {
        $buggyMatch = (bool) $db->getValue("SELECT (YEAR(NOW()) - YEAR('{$birthday}')) >= 18");
        $fixedMatch = (bool) $db->getValue("SELECT TIMESTAMPDIFF(YEAR, '{$birthday}', CURDATE()) >= 18");
        neria_assert($buggyMatch === true && $fixedMatch === false, "le jeu de test ne reproduit plus le scénario (buggy={$buggyMatch}, fixed={$fixedMatch})");

        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SeasonalCampaignManager.php');
        neria_assert(
            str_contains($src, 'TIMESTAMPDIFF(YEAR, c.birthday, CURDATE())'),
            "getEligibleCustomers() n'utilise plus TIMESTAMPDIFF pour l'âge — régression du bug corrigé le 17/07/2026 (commit e3270a5)"
        );

        return ['pass' => true, 'message' => 'Filtre d\'âge saisonnier toujours basé sur TIMESTAMPDIFF'];
    } finally {
        if ($origBirthday) {
            $db->execute("UPDATE {$prefix}customer SET birthday = '{$origBirthday}' WHERE id_customer={$idCustomer}");
        } else {
            $db->execute("UPDATE {$prefix}customer SET birthday = NULL WHERE id_customer={$idCustomer}");
        }
    }
}
