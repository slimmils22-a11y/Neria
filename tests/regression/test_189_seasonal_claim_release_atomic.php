<?php
/**
 * Régression : SeasonalCampaignManager::claimSend() doit être une
 * réservation atomique compare-and-swap, pas un simple SELECT COUNT(*)
 * suivi d'un INSERT après l'envoi.
 *
 * Bug réel corrigé le 09/08/2026 (round 143) : runDueCampaigns() vérifiait
 * "déjà envoyé ?" via SELECT COUNT(*), envoyait l'email, PUIS posait la
 * dédup via INSERT IGNORE — contrairement à ses jumelles
 * CollectionManager::claimSend()/LookCompletionManager (rounds 63/87),
 * corrigées pour exactement ce défaut. Deux déclenchements cron quasi
 * simultanés (fallback webcron + hookDisplayHeader) passaient tous deux le
 * SELECT avant que l'un des deux n'ait inséré sa ligne, et le même client
 * recevait deux fois la même campagne saisonnière.
 *
 * Test comportemental réel (via Reflection, les méthodes sont privées) :
 * un premier claimSend() doit réussir (true, ligne insérée) ; un second
 * claimSend() avec les mêmes paramètres doit échouer (false, la ligne
 * existe déjà — c'est exactement la protection anti-course : sous deux
 * appels concurrents, seul l'un des deux gagnerait la réservation) ;
 * releaseSendClaim() doit ensuite permettre une nouvelle réservation.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/SeasonalCampaignManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();

    $idCustomer = neria_test_any_customer_id();
    $sentKey    = 'seasonal_neria_test_round143';
    $year       = 2097; // hors plage réelle, pas de collision possible

    $mgr = new SeasonalCampaignManager($module);

    $refClaim = new ReflectionMethod(SeasonalCampaignManager::class, 'claimSend');
    $refClaim->setAccessible(true);
    $refRelease = new ReflectionMethod(SeasonalCampaignManager::class, 'releaseSendClaim');
    $refRelease->setAccessible(true);

    try {
        $first = $refClaim->invoke($mgr, $idCustomer, $sentKey, $year);
        neria_assert($first === true, "claimSend() a échoué sur la toute première réservation — jeu de test invalide");

        $second = $refClaim->invoke($mgr, $idCustomer, $sentKey, $year);
        neria_assert(
            $second === false,
            "claimSend() a réussi une SECONDE fois pour le même (customer, template, année) — régression du bug corrigé le 09/08/2026 (round 143) : deux déclenchements concurrents pourraient de nouveau tous deux 'gagner' et envoyer l'email deux fois"
        );

        $count = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_behavioral_sent
             WHERE id_customer = {$idCustomer} AND template = '{$sentKey}' AND ref_id = {$year}"
        );
        neria_assert($count === 1, "deux lignes de réservation ont été créées au lieu d'une seule (count={$count})");

        $refRelease->invoke($mgr, $idCustomer, $sentKey, $year);
        $countAfterRelease = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_behavioral_sent
             WHERE id_customer = {$idCustomer} AND template = '{$sentKey}' AND ref_id = {$year}"
        );
        neria_assert($countAfterRelease === 0, "releaseSendClaim() n'a pas supprimé la réservation");

        $third = $refClaim->invoke($mgr, $idCustomer, $sentKey, $year);
        neria_assert($third === true, "claimSend() échoue après releaseSendClaim() — la réservation n'a pas été correctement libérée");
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_behavioral_sent WHERE id_customer = {$idCustomer} AND template = '{$sentKey}' AND ref_id = {$year}");
    }

    return [
        'pass'    => true,
        'message' => "SeasonalCampaignManager::claimSend()/releaseSendClaim() forment bien une réservation atomique compare-and-swap, alignée sur CollectionManager/LookCompletionManager",
    ];
}
