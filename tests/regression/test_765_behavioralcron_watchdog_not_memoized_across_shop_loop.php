<?php
/**
 * Régression : `BehavioralCronManager::watchdog()` mémoïsait son instance
 * `WatchdogManager` (`private ?\WatchdogManager $watchdog = null`), créée
 * au tout premier appel — ligne ~98 de `run()`, AVANT la boucle
 * multi-boutique qui bascule `Context::getContext()->shop` pour chaque
 * boutique active. `WatchdogManager::__construct()` capture
 * `Context::getContext()->shop->id` UNE SEULE FOIS à l'instanciation :
 * toutes les erreurs journalisées DANS les boucles par boutique
 * (`segment_recompute_failed`, `churn_recompute_failed`,
 * `gdpr_auto_purge_*`, `birthday_voucher_error`, etc.) étaient donc
 * enregistrées sous l'`id_shop` de la PREMIÈRE boutique traitée par le
 * cron, quelle que soit la boutique réellement concernée par l'erreur —
 * invisibles pour leurs propres marchands, polluant à tort le journal de
 * la première boutique.
 *
 * Bug identifié le 15/09/2026 (round 360, audit dédié WatchdogManager).
 *
 * Corrigé le 15/09/2026 : `watchdog()` ne mémoïse plus — reconstruit une
 * nouvelle instance à CHAQUE appel (le constructeur ne fait qu'une
 * affectation de propriétés, aucune requête, donc sans coût réel), captant
 * ainsi systématiquement la boutique ambiante réelle au moment de l'appel.
 *
 * Test comportemental réel : appelle la méthode privée watchdog() de
 * BehavioralCronManager deux fois, en changeant le contexte boutique
 * ambiant entre les deux appels (simulant exactement la boucle
 * multi-boutique de run()) — vérifie que les 2 instances retournées
 * reflètent bien 2 id_shop DIFFÉRENTS, pas la même valeur figée.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/WatchdogManager.php';

    $originalShop = \Context::getContext()->shop;

    try {
        $mgr = new BehavioralCronManager(neria_test_module());

        $refMethod = new ReflectionMethod(BehavioralCronManager::class, 'watchdog');
        $refMethod->setAccessible(true);

        $refIdShop = new ReflectionProperty(WatchdogManager::class, 'idShop');
        $refIdShop->setAccessible(true);

        // 1er appel avec la boutique ambiante réelle (id=1, seule boutique
        // de cet environnement de test).
        \Context::getContext()->shop = new \Shop(1);
        $wd1 = $refMethod->invoke($mgr);
        $idShop1 = $refIdShop->getValue($wd1);

        neria_assert(
            $idShop1 === 1,
            "watchdog() ne capture pas l'id_shop ambiant réel (attendu 1, obtenu {$idShop1}) — jeu de test invalide"
        );

        // Simule le changement de contexte opéré par la boucle multi-boutique
        // de run() (Context::getContext()->shop = new \Shop((int) $idShop))
        // avec une boutique fictive dont l'id est garanti différent.
        $fakeIdShop = 999997651;
        $fakeShop = new \Shop(1);
        $fakeShop->id = $fakeIdShop;
        \Context::getContext()->shop = $fakeShop;

        $wd2 = $refMethod->invoke($mgr);
        $idShop2 = $refIdShop->getValue($wd2);

        neria_assert(
            $idShop2 === $fakeIdShop,
            "watchdog() (2e appel, après changement du contexte boutique ambiant à {$fakeIdShop}) renvoie une instance avec id_shop={$idShop2} au lieu de {$fakeIdShop} — régression du bug corrigé le 15/09/2026 (round 360) : l'instance mémoïsée figerait la boutique du 1er appel pour toute la durée du run(), et toute erreur journalisée pendant le traitement de cette 2e boutique serait à tort attribuée à la 1ère"
        );

        return [
            'pass'    => true,
            'message' => "BehavioralCronManager::watchdog() reconstruit désormais bien une instance WatchdogManager par appel, reflétant la vraie boutique ambiante à chaque itération de la boucle multi-boutique — bug corrigé le 15/09/2026 (round 360)",
        ];
    } finally {
        \Context::getContext()->shop = $originalShop;
    }
}
