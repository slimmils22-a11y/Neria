<?php
/**
 * Régression : neria.php::runBackgroundJobs() doit instancier
 * WatchdogManager DANS une boucle sur Shop::getShops(), avec bascule du
 * contexte, avant d'appeler sendDailyDigestIfDue() — pas une seule fois,
 * sinon seule la boutique du contexte courant reçoit son digest quotidien.
 *
 * Bug réel corrigé le 01/09/2026 (round 266) : 5e occurrence du même
 * défaut que CalendarManager (round 76), SeasonalCampaignManager
 * (round 77), WebhookManager (round 78) et DomainReputationManager
 * (round 79, voir test_83). WatchdogManager capture $this->idShop dans son
 * constructeur et scope tout le digest quotidien (throttle
 * NERIA_DIGEST_LAST_SENT_{idShop}, sélection SQL WHERE id_shop = ...) sur
 * cette seule boutique. Sans boucle, seule la boutique du contexte ambiant
 * au moment de l'appel (celle du vrai cron serveur, ou celle du premier
 * visiteur front du jour) recevait son digest — les autres boutiques
 * accumulaient des erreurs/alertes en base sans jamais en informer le
 * marchand par email.
 *
 * Non vérifiable en conditions multi-boutiques réelles sur cet
 * environnement de dev (une seule boutique configurée) — même limite que
 * test_80/test_81/test_83. Vérifie donc au niveau du code source que la
 * boucle par boutique est bien en place.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $pos = strpos($src, '// ── Watchdog — digest quotidien (throttle interne 24h)');
    neria_assert($pos !== false, "bloc digest quotidien Watchdog introuvable dans runBackgroundJobs()");

    $block = substr($src, $pos, 1900);

    neria_assert(
        strpos($block, '\Shop::getShops(true, null, true)') !== false,
        "runBackgroundJobs() ne boucle plus sur Shop::getShops() pour le digest Watchdog — régression du bug corrigé le 01/09/2026 (round 266) : seule la boutique du contexte courant recevrait de nouveau son digest quotidien"
    );
    neria_assert(
        strpos($block, 'foreach ($shopsDigest as $idShopDigest) {') !== false
        && strpos($block, 'new \Shop((int) $idShopDigest)') !== false
        && strpos($block, 'new WatchdogManager($this)') !== false
        && strpos($block, '->sendDailyDigestIfDue();') !== false,
        "runBackgroundJobs() n'instancie plus WatchdogManager à l'intérieur de la boucle par boutique avec bascule du contexte avant sendDailyDigestIfDue() — régression du bug corrigé le 01/09/2026 (round 266)"
    );
    neria_assert(
        strpos($block, '\Context::getContext()->shop = $originalShopDigest;') !== false,
        "runBackgroundJobs() ne restaure plus le contexte boutique d'origine après la boucle du digest Watchdog — risquerait de laisser le contexte global sur la dernière boutique traitée pour le reste de la requête"
    );

    return ['pass' => true, 'message' => "runBackgroundJobs() instancie bien WatchdogManager dans une boucle sur toutes les boutiques actives avant sendDailyDigestIfDue()"];
}
