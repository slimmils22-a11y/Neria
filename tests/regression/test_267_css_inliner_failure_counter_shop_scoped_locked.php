<?php
/**
 * Régression : 2 bugs du compteur d'échecs silencieux de CssInliner
 * corrigés le 09/08/2026 (round 160) :
 * - Compteur global (NERIA_CSS_INLINE_FAILURES, sans idShop) : en
 *   multi-boutique, un échec d'inlining sur la boutique A déclenchait un
 *   warning Health Check visible aussi côté boutique B, et consulter/
 *   reset ce contrôle pour B effaçait silencieusement le compteur réel
 *   de A.
 * - Cycle lecture-modification-écriture non protégé : deux échecs quasi
 *   simultanés (cron d'envoi en masse) pouvaient tous deux lire la même
 *   valeur avant que l'un des deux n'écrive, perdant un incrément (lost
 *   update), sous-estimant le nombre réel d'échecs affiché au marchand.
 *
 * Corrigé par une clé suffixée par idShop et un GET_LOCK non bloquant
 * autour du cycle lecture-modification-écriture.
 *
 * Test structurel (forcer CssInliner::process() à lever une exception
 * réelle nécessiterait de corrompre l'environnement DOMDocument/PCRE,
 * risqué pour le reste de la suite) : vérifie que le bloc catch écrit
 * bien vers une clé scopée par idShop, protégée par GET_LOCK, et que
 * HealthCheckManager::checkCssInlinerSilentFailures() lit/reset bien la
 * même clé scopée.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $ci = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CssInliner.php');
    neria_assert($ci !== false, 'Impossible de lire CssInliner.php');

    $posCatch = strpos($ci, 'catch (\Throwable $e) {');
    neria_assert($posCatch !== false, 'Bloc catch introuvable dans CssInliner::inline() — jeu de test invalide');
    $body = substr($ci, $posCatch, 1900);

    neria_assert(
        strpos($body, "'NERIA_CSS_INLINE_FAILURES_' . \$idShop") !== false,
        "CssInliner::inline() n'écrit plus le compteur d'échecs via une clé scopée par idShop — régression du bug corrigé le 09/08/2026 (round 160) : le compteur redeviendrait partagé/incohérent entre boutiques"
    );
    neria_assert(
        strpos($body, "GET_LOCK('neria_css_inline_failures_") !== false && strpos($body, "RELEASE_LOCK('neria_css_inline_failures_") !== false,
        "CssInliner::inline() n'a plus de verrou sur le cycle lecture-modification-écriture du compteur — régression du bug corrigé le 09/08/2026 (round 160) : un incrément pourrait de nouveau être perdu sous charge concurrente"
    );

    $hcm = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php');
    neria_assert($hcm !== false, 'Impossible de lire HealthCheckManager.php');
    $posFn = strpos($hcm, 'private function checkCssInlinerSilentFailures(): array');
    neria_assert($posFn !== false, 'checkCssInlinerSilentFailures() introuvable — jeu de test invalide');
    $hcmBody = substr($hcm, $posFn, 500);
    neria_assert(
        strpos($hcmBody, "'NERIA_CSS_INLINE_FAILURES_' . \$this->idShop") !== false,
        "HealthCheckManager::checkCssInlinerSilentFailures() ne lit/reset plus la clé scopée par idShop — régression du bug corrigé le 09/08/2026 (round 160) : ne relirait plus jamais le compteur désormais scopé écrit par CssInliner"
    );

    return [
        'pass'    => true,
        'message' => "CssInliner::inline() écrit bien le compteur d'échecs via une clé scopée par idShop, protégée par GET_LOCK, cohérente avec la lecture de HealthCheckManager — bugs corrigés le 09/08/2026 (round 160)",
    ];
}
