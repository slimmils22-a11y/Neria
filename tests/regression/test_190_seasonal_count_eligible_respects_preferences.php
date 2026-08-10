<?php
/**
 * Régression : SeasonalCampaignManager::countEligible() (aperçu BO) doit
 * retirer les clients ayant désactivé les communications du template
 * concerné, pas seulement appliquer le ciblage (genre/langue/âge/segment).
 *
 * Bug réel corrigé le 09/08/2026 (round 143) : countEligible() faisait
 * count(getEligibleCustomers($campaign)) — celle-ci ne filtre QUE le
 * ciblage, jamais les préférences (voir le commentaire de
 * runDueCampaigns()). L'aperçu BO annonçait donc un nombre de destinataires
 * structurellement supérieur au nombre réel d'envois le jour J.
 *
 * Test comportemental réel : désabonne un client réel de la catégorie
 * 'season' (voucher PreferencesManager), vérifie que countEligible() pour
 * une campagne 'christmas' (template mappé sur 'season') exclut bien ce
 * client alors que getEligibleCustomers() (ciblage seul) l'inclut toujours.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/SeasonalCampaignManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/PreferencesManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $module     = neria_test_module();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) \Context::getContext()->shop->id;

    $customerRow = $db->getRow("SELECT email FROM {$prefix}customer WHERE id_customer = {$idCustomer}");
    neria_assert($customerRow !== false, 'Client de test introuvable — jeu de test invalide');
    $email = $customerRow['email'];

    $campaign = [
        'target_segment' => '',
        'target_gender'  => 0,
        'target_lang'    => '',
        'min_age'        => 0,
        'max_age'        => 0,
        'template'       => 'christmas',
        'gift_mode'      => false,
    ];

    $mgr = new SeasonalCampaignManager($module);

    try {
        $eligibleBefore = $mgr->getEligibleCustomers($campaign);
        $idsBefore = array_map(fn($c) => (int) $c['id_customer'], $eligibleBefore);
        neria_assert(
            in_array($idCustomer, $idsBefore, true),
            "le client de test n'est pas dans le ciblage de base — jeu de test invalide (aucun critère pourtant)"
        );

        $countBefore = $mgr->countEligible($campaign);
        neria_assert($countBefore === count($eligibleBefore), "countEligible() diverge de getEligibleCustomers() alors qu'aucune préférence n'est encore posée — jeu de test invalide");

        // Désabonne ce client de la catégorie 'season'
        $db->execute(
            "INSERT INTO {$prefix}neria_preferences (id_shop, id_customer, email, category, subscribed, date_upd)
             VALUES ({$idShop}, {$idCustomer}, '" . pSQL($email) . "', 'season', 0, NOW())
             ON DUPLICATE KEY UPDATE subscribed = 0"
        );

        $countAfter = $mgr->countEligible($campaign);
        neria_assert(
            $countAfter === $countBefore - 1,
            "countEligible() vaut {$countAfter} au lieu de " . ($countBefore - 1) . " après désabonnement d'un client — régression du bug corrigé le 09/08/2026 (round 143) : l'aperçu BO ne retire de nouveau pas les clients désabonnés"
        );

        // getEligibleCustomers() (ciblage seul), lui, doit rester inchangé
        $eligibleAfter = $mgr->getEligibleCustomers($campaign);
        neria_assert(
            count($eligibleAfter) === count($eligibleBefore),
            "getEligibleCustomers() a changé après désabonnement — cette méthode ne doit filtrer QUE le ciblage, pas les préférences"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_preferences WHERE id_customer = {$idCustomer} AND category = 'season' AND id_shop = {$idShop}");
    }

    return [
        'pass'    => true,
        'message' => "SeasonalCampaignManager::countEligible() retire bien les clients désabonnés du template, contrairement à getEligibleCustomers() (ciblage seul)",
    ];
}
