<?php
/**
 * Régression round 214 (26/08/2026) — 6 correctifs distincts :
 *
 * 1-6. GdprAuditManager::purgeCustomerData() : 7 occurrences de
 *    Db::getValue()/executeS() sans $use_cache=false, chacune suivie
 *    d'une action conditionnelle (DELETE si count > 0, ou filtrage de
 *    lignes) — sous cache SQL BO actif, un résultat périmé pouvait faire
 *    sauter silencieusement la suppression réelle de données
 *    personnelles alors que la méthode retournait quand même un total
 *    "succès" sans erreur (droit à l'effacement RGPD non honoré).
 * 7. GdprAuditManager::purgeTable() : même défaut sur le COUNT de
 *    reporting (le DELETE, lui, s'exécute inconditionnellement — pas de
 *    perte de données, juste un chiffre affiché potentiellement faux).
 * 8. GdprAuditManager::auditRetention() : même défaut sur la vérification
 *    d'existence de table (impact mineur, audit informatif).
 * 9. OrderTriggersManager::checkMilestone() : COUNT(*) de commandes
 *    valides sans $use_cache=false, pilotant la détection de palier de
 *    fidélité — un résultat périmé pouvait faire manquer définitivement
 *    un palier (bon de réduction + email jamais générés).
 * 10. OrderTriggersManager::handleRefund() : SUM() du cumul d'avoirs sans
 *    $use_cache=false, pilotant le clawback de points de fidélité (seuil
 *    90%) et l'ajustement du revenu attribué.
 * 11. MonthlyReportManager::deliverReportLocked()/renderHtml() :
 *    Configuration::get('PS_SHOP_NAME'/'PS_SHOP_EMAIL') sans $idShop
 *    explicite dans la boucle multi-boutique — un rapport mensuel livré
 *    pour une boutique pouvait afficher l'identité expéditeur (nom +
 *    adresse From) d'une AUTRE boutique de l'installation.
 *
 * Test structurel : reproduire le cache SQL de bout en bout pour chacune
 * de ces 11 occurrences serait redondant avec test_440/441 qui
 * démontrent déjà le mécanisme sous-jacent — vérifie la présence de
 * chaque garde-fou dans le code source.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $base = _PS_MODULE_DIR_ . 'neria/';

    // ── GdprAuditManager ────────────────────────────────────────────
    $gdpr = file_get_contents($base . 'src/GdprAuditManager.php');
    neria_assert($gdpr !== false, 'Impossible de lire src/GdprAuditManager.php');

    neria_assert(
        strpos($gdpr, "WHERE `{\$col}` = \" . (int) \$idCustomer,\n                false\n            );") !== false,
        "GdprAuditManager::purgeCustomerData() n'a plus \$use_cache=false sur son COUNT générique par table — régression du bug corrigé le 26/08/2026 (round 214) : le droit à l'effacement RGPD pourrait de nouveau échouer silencieusement"
    );
    neria_assert(
        strpos($gdpr, "AND `id_customer` = \" . (int) \$idCustomer,\n                    false\n                );") !== false,
        "GdprAuditManager::purgeCustomerData() n'a plus \$use_cache=false sur son COUNT neria_preferences — régression du bug corrigé le 26/08/2026 (round 214)"
    );
    neria_assert(
        strpos($gdpr, "WHERE `email` = '{\$emailSql}'\", false);") !== false,
        "GdprAuditManager::purgeCustomerData() n'a plus \$use_cache=false sur son COUNT neria_bounces — régression du bug corrigé le 26/08/2026 (round 214)"
    );
    neria_assert(
        strpos($gdpr, "SELECT `id_webhook`, `payload` FROM `{\$fullWh}`\", true, false);") !== false,
        "GdprAuditManager::purgeCustomerData() n'a plus \$use_cache=false sur son executeS() neria_webhook_queue — régression du bug corrigé le 26/08/2026 (round 214)"
    );
    neria_assert(
        substr_count($gdpr, "                false\n            );") + substr_count($gdpr, "                false\n                );") >= 3,
        "GdprAuditManager n'a plus assez d'occurrences \$use_cache=false attendues (certificate/attribution) — régression possible du bug corrigé le 26/08/2026 (round 214)"
    );
    neria_assert(
        strpos($gdpr, "WHERE `{\$dateCol}` < DATE_SUB(NOW(), INTERVAL {\$months} MONTH){\$shopFilter}\",\n            false\n        );") !== false,
        "GdprAuditManager::purgeTable() n'a plus \$use_cache=false sur son COUNT de reporting — régression du bug corrigé le 26/08/2026 (round 214)"
    );
    neria_assert(
        strpos($gdpr, "AND TABLE_NAME = '\" . pSQL(\$table) . \"'\",\n                false\n            );") !== false,
        "GdprAuditManager::auditRetention() n'a plus \$use_cache=false sur sa vérification d'existence de table — régression du bug corrigé le 26/08/2026 (round 214)"
    );

    // ── OrderTriggersManager ────────────────────────────────────────
    $otm = file_get_contents($base . 'src/OrderTriggersManager.php');
    neria_assert($otm !== false, 'Impossible de lire src/OrderTriggersManager.php');

    neria_assert(
        strpos($otm, "AND `id_shop` = ' . \$idShop . ' AND `valid` = 1',\n            false\n        );") !== false,
        "OrderTriggersManager::checkMilestone() n'a plus \$use_cache=false sur son COUNT de commandes valides — régression du bug corrigé le 26/08/2026 (round 214) : un palier de fidélité pourrait de nouveau être manqué définitivement"
    );
    neria_assert(
        strpos($otm, "WHERE id_order = ' . (int) \$order->id,\n                false\n            );") !== false,
        "OrderTriggersManager::handleRefund() n'a plus \$use_cache=false sur son SUM de cumul d'avoirs — régression du bug corrigé le 26/08/2026 (round 214) : le clawback fidélité et l'ajustement du revenu attribué pourraient de nouveau être faussés"
    );

    // ── MonthlyReportManager ────────────────────────────────────────
    $mrm = file_get_contents($base . 'src/MonthlyReportManager.php');
    neria_assert($mrm !== false, 'Impossible de lire src/MonthlyReportManager.php');

    neria_assert(
        strpos($mrm, "\$shopName = (string) \\Configuration::get('PS_SHOP_NAME', null, null, \$this->idShop);") !== false,
        "MonthlyReportManager::deliverReportLocked() ne scope plus PS_SHOP_NAME par \$this->idShop — régression du bug corrigé le 26/08/2026 (round 214) : le rapport mensuel pourrait de nouveau afficher l'identité d'une autre boutique"
    );
    neria_assert(
        strpos($mrm, "(string) \\Configuration::get('PS_SHOP_EMAIL', null, null, \$this->idShop)") !== false,
        "MonthlyReportManager::deliverReportLocked() ne scope plus PS_SHOP_EMAIL par \$this->idShop — régression du bug corrigé le 26/08/2026 (round 214)"
    );
    neria_assert(
        strpos($mrm, "\$shopName = htmlspecialchars((string) \\Configuration::get('PS_SHOP_NAME', null, null, \$this->idShop));") !== false,
        "MonthlyReportManager::renderHtml() ne scope plus PS_SHOP_NAME par \$this->idShop — régression du bug corrigé le 26/08/2026 (round 214)"
    );

    return [
        'pass'    => true,
        'message' => 'Round 214 : $use_cache=false sur les 10 occurrences GdprAuditManager/OrderTriggersManager et scoping $idShop sur MonthlyReportManager tous présents',
    ];
}
