<?php
/**
 * Régression : LicenseManager::isEmailSendingAllowed() ne comparait jamais
 * le domaine enregistré dans le jeton de licence au domaine réellement
 * exécuté — seul checkDomainChange() (déclenché par un cron) le faisait,
 * en PLANIFIANT une revalidation réseau asynchrone SANS jamais bloquer
 * l'envoi entre-temps.
 *
 * Bug réel identifié le 25/08/2026 (round 206) : copier
 * NERIA_LICENSE_TOKEN/NERIA_LICENSE_KEY vers une installation non
 * licenciée (clone de config, staging→prod dupliqué) permettait l'envoi
 * d'emails immédiatement et en continu tant que le cron Neria de cette
 * installation ne tournait pas — la signature Ed25519 du jeton copié
 * reste cryptographiquement valide (elle signe le contenu du jeton, pas
 * le domaine d'exécution réel).
 *
 * Corrigé le 25/08/2026 (round 206) : nouvelle méthode privée
 * isDomainMismatch() appelée par isEmailSendingAllowed(), qui refuse
 * explicitement (return false, sans repli sur le délai de grâce) quand le
 * domaine du jeton diverge du domaine courant sur la boutique par défaut.
 *
 * Test comportemental réel : impossible de forger un jeton signé
 * valide en test (le module ne contient pas la clé privée Ed25519 — voir
 * docblock du fichier), donc ce test cible directement isDomainMismatch()
 * via réflexion, qui encapsule toute la logique de décision (le seul point
 * qui ne peut PAS être testé de bout en bout sans clé privée serveur).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/LicenseManager.php';

    $module = neria_test_module();
    $mgr = new LicenseManager($module);
    $ref = new ReflectionMethod('LicenseManager', 'isDomainMismatch');
    $ref->setAccessible(true);

    // Domaine vide dans le jeton (jamais activé côté serveur avec cette
    // info, ou ancien jeton pré-round-206) : jamais de mismatch — pas de
    // donnée pour comparer, ne doit jamais bloquer sur une absence.
    neria_assert(
        $ref->invoke($mgr, '') === false,
        "LicenseManager::isDomainMismatch('') devrait retourner false (rien à comparer) — jeu de test invalide ou régression"
    );

    // Domaine du jeton identique au domaine courant réel de l'environnement
    // de test : pas de mismatch.
    $refCurrentDomain = new ReflectionMethod('LicenseManager', 'currentDomain');
    $refCurrentDomain->setAccessible(true);
    $realDomain = $refCurrentDomain->invoke($mgr);
    neria_assert(
        $ref->invoke($mgr, $realDomain) === false,
        "LicenseManager::isDomainMismatch() signale à tort un mismatch alors que le domaine du jeton correspond exactement au domaine courant — régression du bug corrigé le 25/08/2026 (round 206)"
    );

    // Domaine du jeton clairement différent : mismatch détecté (scénario
    // de licence copiée sur un autre domaine).
    neria_assert(
        $ref->invoke($mgr, 'un-tout-autre-domaine-jamais-enregistre.example') === true,
        "LicenseManager::isDomainMismatch() ne détecte plus un domaine réellement différent — régression du bug corrigé le 25/08/2026 (round 206) : un jeton copié vers un autre domaine ne serait plus jamais détecté comme illégitime"
    );

    return [
        'pass'    => true,
        'message' => "LicenseManager::isDomainMismatch() détecte bien une divergence de domaine, permettant à isEmailSendingAllowed() de refuser explicitement l'envoi sur un jeton copié vers un domaine non enregistré — bug corrigé le 25/08/2026 (round 206)",
    ];
}
