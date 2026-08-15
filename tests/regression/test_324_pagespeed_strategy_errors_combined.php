<?php
/**
 * Régression : PageSpeedManager::recordError() était appelée séparément par
 * chaque appel fetchStrategy() (mobile puis desktop) et ÉCRASAIT
 * systématiquement le message précédent — si mobile échouait pour une
 * raison (ex. timeout réseau, fréquent) et desktop pour une autre (ex. clé
 * invalide), seul le message desktop (écrit en dernier) survivait dans
 * CONFIG_LAST_ERROR, cachant complètement la cause réelle de l'échec
 * mobile au marchand/à HealthCheckManager.
 *
 * Corrigé le 15/08/2026 (round 171) : les erreurs par stratégie sont
 * désormais accumulées dans $strategyErrorParts (recordStrategyError())
 * puis combinées par runCheck() avant écriture finale dans CONFIG_LAST_ERROR.
 *
 * Test comportemental réel : appelle recordStrategyError() deux fois via
 * réflexion (mobile puis desktop, messages distincts), vérifie que les DEUX
 * messages sont bien conservés dans la propriété d'accumulation — ni l'un
 * ni l'autre n'est écrasé.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PageSpeedManager.php';

    $mgr = new PageSpeedManager(neria_test_module());

    $record = new ReflectionMethod(PageSpeedManager::class, 'recordStrategyError');
    $record->setAccessible(true);
    $prop = new ReflectionProperty(PageSpeedManager::class, 'strategyErrorParts');
    $prop->setAccessible(true);
    $prop->setValue($mgr, []);

    $record->invoke($mgr, 'mobile', 'erreur_timeout_mobile');
    $record->invoke($mgr, 'desktop', 'erreur_cle_invalide_desktop');

    $parts = $prop->getValue($mgr);

    neria_assert(
        count($parts) === 2,
        "recordStrategyError() n'accumule plus les deux messages (obtenu " . count($parts) . " au lieu de 2) — régression du bug corrigé le 15/08/2026 (round 171) : le message mobile écraserait de nouveau le message desktop ou inversement"
    );
    neria_assert(
        strpos(implode(' | ', $parts), 'erreur_timeout_mobile') !== false,
        "Le message d'erreur mobile a disparu de l'accumulation — régression du bug corrigé le 15/08/2026 (round 171)"
    );
    neria_assert(
        strpos(implode(' | ', $parts), 'erreur_cle_invalide_desktop') !== false,
        "Le message d'erreur desktop a disparu de l'accumulation — régression du bug corrigé le 15/08/2026 (round 171)"
    );

    // Nettoyage : évite de polluer un autre test qui lirait cette instance.
    $prop->setValue($mgr, []);

    return [
        'pass'    => true,
        'message' => "PageSpeedManager::recordStrategyError() conserve bien les messages mobile ET desktop sans écrasement mutuel — bug corrigé le 15/08/2026 (round 171)",
    ];
}
