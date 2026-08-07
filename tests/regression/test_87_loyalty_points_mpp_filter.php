<?php
/**
 * Régression : StatsManager::record() ne doit plus attribuer de points de
 * fidélité (LoyaltyManager::awardPoints()) pour un événement 'open' qui est
 * en réalité un pré-chargement automatique Apple Mail Privacy Protection
 * (is_mpp = 1).
 *
 * Bug réel corrigé le 07/08/2026 (round 83) : le garde d'appel à
 * awardPoints() dans record() testait bien id_customer/id_stat/event mais
 * jamais $isMpp. Tous les consommateurs de lecture de event_type='open'
 * (ChurnScoreManager, SegmentManager, ClvManager, CustomerEmailHistoryManager,
 * PropensityScoreManager, MonthlyReportManager) filtrent is_mpp=0 depuis les
 * rounds 74-82, mais ce chemin d'ÉCRITURE (attribution de points au moment
 * du tracking) avait été oublié : un client Apple Mail qui n'ouvre jamais
 * réellement ses emails recevait quand même des points de fidélité à chaque
 * pré-chargement du pixel de tracking par le proxy Apple.
 *
 * Test comportemental réel : appelle StatsManager::record() (via Reflection,
 * méthode privée) avec event='open' et is_mpp=1 pour un client de test avec
 * le programme de fidélité activé. Avec le correctif, aucune ligne ne doit
 * apparaître dans ps_neria_loyalty_points pour ce stat.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/StatsManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/LoyaltyManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) Context::getContext()->shop->id;
    $token      = 'regtest87-' . uniqid();
    $module     = neria_test_module();

    $wasEnabled = Configuration::getGlobalValue('NERIA_LOYALTY_ENABLED');
    Configuration::updateGlobalValue('NERIA_LOYALTY_ENABLED', 1);

    $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token = '" . pSQL($token) . "'");

    try {
        $statsMgr = new StatsManager($module);
        $record   = new ReflectionMethod(StatsManager::class, 'record');
        $record->setAccessible(true);

        $record->invoke(
            $statsMgr,
            'newsletter',
            'fr',
            $token,
            'open',
            [
                'id_customer' => $idCustomer,
                'is_mpp'      => 1,
            ],
            true
        );

        $idStat = (int) $db->getValue(
            "SELECT id_stat FROM {$prefix}neria_stat WHERE tracking_token = '" . pSQL($token) . "' AND event_type = 'open'"
        );
        neria_assert($idStat > 0, "record() n'a pas inséré la ligne 'open' de test — jeu de test invalide");

        $pointsRows = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_loyalty_points WHERE id_stat = {$idStat}"
        );

        neria_assert(
            $pointsRows === 0,
            "StatsManager::record() attribue encore des points de fidélité pour un 'open' MPP (id_stat={$idStat}, {$pointsRows} ligne(s) trouvée(s)) — régression du bug corrigé le 07/08/2026 (round 83)"
        );

        return [
            'pass'    => true,
            'message' => "StatsManager::record() n'attribue plus de points de fidélité pour un événement 'open' MPP",
        ];
    } finally {
        $idStat = (int) $db->getValue(
            "SELECT id_stat FROM {$prefix}neria_stat WHERE tracking_token = '" . pSQL($token) . "'"
        );
        if ($idStat > 0) {
            $db->execute("DELETE FROM {$prefix}neria_loyalty_points WHERE id_stat = {$idStat}");
        }
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token = '" . pSQL($token) . "'");
        Configuration::updateGlobalValue('NERIA_LOYALTY_ENABLED', $wasEnabled !== false ? $wasEnabled : 0);
    }
}
