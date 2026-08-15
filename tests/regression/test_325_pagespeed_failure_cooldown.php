<?php
/**
 * Régression : PageSpeedManager::runCheck() n'écrivait jamais
 * CONFIG_CACHE_TIME sur un échec TOTAL (mobile ET desktop) — chaque appel
 * suivant à getReport() (chaque chargement de page BO) rappelait donc
 * l'API PageSpeed en direct sans aucun backoff, risquant d'épuiser le
 * quota gratuit pendant une panne/un rate-limit (429). Un 429 tombait de
 * plus dans le même seau générique que n'importe quelle autre erreur HTTP,
 * sans cooldown spécifique plus long.
 *
 * Corrigé le 15/08/2026 (round 171) : un échec total écrit désormais
 * CONFIG_LAST_ATTEMPT (+ CONFIG_LAST_ATTEMPT_RATE_LIMITED si l'échec est un
 * 429), et isInFailureCooldown() (appelée par getReport() avant tout appel
 * réseau) bloque les tentatives suivantes pendant FAILURE_COOLDOWN (15 min,
 * échec générique) ou RATE_LIMIT_COOLDOWN (1h, 429 spécifique).
 *
 * Test comportemental réel : pose un CONFIG_LAST_ATTEMPT récent (dans la
 * fenêtre de cooldown) et vérifie qu'isInFailureCooldown() retourne bien
 * true — pour le cooldown générique ET pour le cooldown rate-limit
 * (horodatage identique mais fenêtre plus longue). Vérifie aussi qu'un
 * horodatage ancien (hors fenêtre) ne bloque plus rien.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PageSpeedManager.php';

    $idShop = (int) Context::getContext()->shop->id;
    $keyAttempt = 'NERIA_PAGESPEED_LAST_ATTEMPT_' . $idShop;
    $keyRl      = 'NERIA_PAGESPEED_LAST_ATTEMPT_RL_' . $idShop;

    $mgr = new PageSpeedManager(neria_test_module());
    $ref = new ReflectionMethod(PageSpeedManager::class, 'isInFailureCooldown');
    $ref->setAccessible(true);

    try {
        // 1) Aucune tentative précédente -> pas de cooldown.
        Configuration::deleteByName($keyAttempt);
        Configuration::deleteByName($keyRl);
        neria_assert(
            $ref->invoke($mgr) === false,
            "isInFailureCooldown() retourne true sans aucune tentative précédente enregistrée — jeu de test invalide ou régression"
        );

        // 2) Échec récent, générique (pas rate-limit) -> cooldown actif (15 min).
        Configuration::updateValue($keyAttempt, (string) (time() - 60));
        Configuration::updateValue($keyRl, '0');
        neria_assert(
            $ref->invoke($mgr) === true,
            "isInFailureCooldown() ne bloque plus une nouvelle tentative 60s après un échec total récent — régression du bug corrigé le 15/08/2026 (round 171) : chaque page BO rappellerait de nouveau l'API en direct pendant une panne"
        );

        // 3) Échec récent MAIS ancien (au-delà du cooldown générique de 15 min,
        //    mais toujours dans l'heure) -> plus de cooldown générique, mais le
        //    cooldown rate-limit (1h) doit lui rester actif si le flag est posé.
        Configuration::updateValue($keyAttempt, (string) (time() - 1200)); // 20 min
        Configuration::updateValue($keyRl, '0');
        neria_assert(
            $ref->invoke($mgr) === false,
            "isInFailureCooldown() bloque encore après l'expiration du cooldown générique de 15 minutes"
        );
        Configuration::updateValue($keyRl, '1');
        neria_assert(
            $ref->invoke($mgr) === true,
            "isInFailureCooldown() ne distingue plus le cooldown rate-limit (1h) du cooldown générique (15 min) — régression du bug corrigé le 15/08/2026 (round 171) : un 429 (quota dépassé) ne bénéficierait plus d'un délai de récupération plus long"
        );

        // 4) Très ancien (au-delà même du cooldown rate-limit) -> plus de blocage.
        Configuration::updateValue($keyAttempt, (string) (time() - 7200)); // 2h
        neria_assert(
            $ref->invoke($mgr) === false,
            "isInFailureCooldown() bloque encore après l'expiration du cooldown rate-limit d'1h"
        );
    } finally {
        Configuration::deleteByName($keyAttempt);
        Configuration::deleteByName($keyRl);
    }

    return [
        'pass'    => true,
        'message' => "PageSpeedManager::isInFailureCooldown() bloque bien les tentatives après un échec total, avec un cooldown plus long spécifiquement pour un rate-limit (429) — bug corrigé le 15/08/2026 (round 171)",
    ];
}
