<?php
/**
 * Régression : PageSpeedManager::runCheck() ne doit effacer
 * CONFIG_LAST_ERROR/CONFIG_LAST_ERROR_AT que si mobile ET desktop ont
 * réussi — jamais si une seule des deux stratégies a réussi.
 *
 * Bug réel corrigé le 09/08/2026 (round 150) : fetchStrategy() effaçait
 * lui-même CONFIG_LAST_ERROR à chaque succès individuel. Comme mobile puis
 * desktop sont appelés séquentiellement dans le même runCheck(), un échec
 * mobile (timeout fréquent — émulation réseau throttlée, souvent plus
 * lente que desktop) suivi d'un succès desktop effaçait l'erreur que
 * mobile venait d'enregistrer : le rapport global (une seule stratégie en
 * échec ne fait pas échouer runCheck()) était mis en cache sans aucune
 * trace exploitable de la cause.
 *
 * Test structurel (pas d'appel réseau réel dans cet environnement de test) :
 * vérifie que le clear de CONFIG_LAST_ERROR a été déplacé de
 * fetchStrategy() vers runCheck(), conditionné à la réussite des DEUX
 * stratégies.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/PageSpeedManager.php');
    neria_assert($src !== false, 'Impossible de lire src/PageSpeedManager.php');

    $posFetch = strpos($src, 'private function fetchStrategy(string $url, string $key, string $strategy): ?array');
    neria_assert($posFetch !== false, 'fetchStrategy() introuvable — jeu de test invalide');
    $fetchBody = substr($src, $posFetch, 2200);
    neria_assert(
        strpos($fetchBody, 'CONFIG_LAST_ERROR') === false,
        "fetchStrategy() efface/ecrit de nouveau CONFIG_LAST_ERROR sur son propre succes — regression du bug corrige le 09/08/2026 (round 150) : le succes d'une strategie effacerait de nouveau l'erreur enregistree par l'echec de l'autre"
    );

    $posRun = strpos($src, 'public function runCheck(): ?array');
    neria_assert($posRun !== false, 'runCheck() introuvable — jeu de test invalide');
    $runBody = substr($src, $posRun, 2600);
    neria_assert(
        strpos($runBody, 'if ($mobile !== null && $desktop !== null) {') !== false,
        "runCheck() ne conditionne plus l'effacement de CONFIG_LAST_ERROR a la reussite des DEUX strategies — regression du bug corrige le 09/08/2026 (round 150)"
    );
    neria_assert(
        strpos($runBody, "\$this->cacheKey(self::CONFIG_LAST_ERROR)") !== false,
        "runCheck() n'efface plus CONFIG_LAST_ERROR du tout — regression : une erreur resolue resterait affichee indefiniment"
    );

    return [
        'pass'    => true,
        'message' => "PageSpeedManager::runCheck() n'efface CONFIG_LAST_ERROR que si mobile ET desktop ont reussi, plus jamais sur le succes d'une seule strategie",
    ];
}
