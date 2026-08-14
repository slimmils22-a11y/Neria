<?php
/**
 * Régression d'infrastructure (round 169) : HealthCheckManager::
 * checkKnownRegressionsGuard() n'avait JAMAIS été rejoué dans son
 * ENSEMBLE depuis sa création — chaque round ne testait par corruption
 * que ses PROPRES nouvelles vérifications, jamais l'agrégat complet.
 * Résultat : 12 vérifications (rounds ~95 à 159) avaient dérivé en
 * silence — fenêtres substr()/preg_match() trop courtes pour des
 * fichiers qui ont grossi depuis, ou chaînes littérales rendues
 * obsolètes par des refactors légitimes ultérieurs (ex. FontManager::
 * sanitizeColor() qui a gagné un 2e paramètre au round 159, cassant la
 * chaîne exacte que le garde-fou du round 129 recherchait) — le
 * garde-fou global affichait un statut ERROR permanent sans que
 * personne ne le remarque, ce qui aurait masqué une VRAIE nouvelle
 * régression noyée dans une liste de faux positifs.
 *
 * Un cas particulier était un vrai bug du garde-fou lui-même (pas une
 * dérive de fenêtre) : le comptage <div>/</div> de send.tpl comptait
 * aussi le texte de commentaires Smarty {* ... *} documentant d'anciens
 * bugs en prose ("simple <div onclick>", "une liste de <div>..."),
 * gonflant artificiellement le nombre d'ouvertures. Corrigé en
 * retirant les commentaires avant comptage — ce qui a révélé un second
 * cas, cette fois sur navigation.tpl : celui-ci laisse volontairement
 * .neria-bo-wrap non refermé par design (neria.php::getContent() le
 * referme en dehors du template), désormais explicitement exclu.
 *
 * Corrigé le 14/08/2026 (round 169) : les 12 vérifications recalibrées
 * (fenêtres élargies ou chaînes mises à jour), chacune revalidée
 * individuellement par corruption ciblée + restauration.
 *
 * Test d'infrastructure réel : exécute checkKnownRegressionsGuard() dans
 * son ENSEMBLE (pas seulement une vérification isolée) et vérifie que
 * son statut est bien 'ok' — GARDE-FOU DU GARDE-FOU, empêchant cette
 * classe de dérive silencieuse de se reproduire à l'avenir.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php';

    $hcm = new HealthCheckManager(neria_test_module());
    $ref = new ReflectionMethod(HealthCheckManager::class, 'checkKnownRegressionsGuard');
    $ref->setAccessible(true);
    $result = $ref->invoke($hcm);

    neria_assert(
        ($result['status'] ?? '') === 'ok',
        "checkKnownRegressionsGuard() n'est plus 'ok' en l'absence de toute corruption — "
            . "une ou plusieurs vérifications ont dérivé (fenêtre trop courte / chaîne obsolète après refactor légitime) "
            . "ou une VRAIE régression est réapparue. Détail : " . ($result['detail'] ?? '(vide)')
    );

    return [
        'pass'    => true,
        'message' => "HealthCheckManager::checkKnownRegressionsGuard() est bien 'ok' en agrégat complet (toutes vérifications confondues) — garde-fou d'infrastructure ajouté au round 169 pour empêcher toute nouvelle dérive silencieuse",
    ];
}
