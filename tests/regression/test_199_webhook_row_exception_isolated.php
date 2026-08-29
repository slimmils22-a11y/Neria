<?php
/**
 * Régression : WebhookManager::processQueue() doit isoler chaque ligne du
 * lot dans son propre try/catch, comme sa jumelle
 * QueueManager::processSingle().
 *
 * Bug réel corrigé le 09/08/2026 (round 144) : le corps du foreach
 * (calcul de séquence, UPDATE attempts, appel fire(), UPDATE status)
 * tournait directement dans la boucle sans try/catch local — seul un
 * try/finally global entourait tout le lot, sans catch. Une exception sur
 * UNE ligne (perte de connexion DB transitoire, échec d'écriture Watchdog
 * dans fire()) remontait hors du foreach et TOUTES les lignes suivantes du
 * lot restaient silencieusement non traitées pour ce passage.
 *
 * Test structurel assumé explicitement : simuler une vraie exception au
 * milieu du traitement d'une ligne réelle nécessiterait de faire échouer
 * une requête SQL ou l'écriture Watchdog à un instant précis — non
 * reproductible de façon fiable sans mocker la couche DB. Vérifie donc que
 * le foreach contient bien un try/catch enveloppant tout le corps de
 * traitement d'une ligne (de l'incrément d'attempts au bilan du statut),
 * avec un log Watchdog en cas d'exception plutôt qu'une propagation.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WebhookManager.php');
    neria_assert($src !== false, 'Impossible de lire src/WebhookManager.php');

    $posMethod = strpos($src, 'public function processQueue(): void');
    neria_assert($posMethod !== false, 'processQueue() introuvable — jeu de test invalide');

    $posForeach = strpos($src, 'foreach ($rows as $row) {', $posMethod);
    neria_assert($posForeach !== false, 'la boucle de traitement du lot est introuvable — jeu de test invalide');

    $loopBody = substr($src, $posForeach, 5400);

    $posTry = strpos($loopBody, 'try {');
    $posFire = strpos($loopBody, '$ok = $this->fire($url, $secret, $payload);');
    $posCatch = strpos($loopBody, 'catch (\Throwable $e) {');

    neria_assert(
        $posTry !== false && $posFire !== false && $posTry < $posFire,
        "processQueue() n'entoure plus l'envoi (fire()) d'un try par ligne — régression du bug corrigé le 09/08/2026 (round 144)"
    );
    neria_assert(
        $posCatch !== false && $posCatch > $posFire,
        "processQueue() n'a plus de catch par ligne après l'envoi — régression du bug corrigé le 09/08/2026 (round 144) : une exception sur une ligne interromprait de nouveau le traitement de tout le reste du lot au lieu de continuer sur les lignes suivantes"
    );

    $catchBody = substr($loopBody, $posCatch, 1100);
    neria_assert(
        strpos($catchBody, 'watchdog.webhook_row_exception') !== false,
        "le catch par ligne ne journalise plus l'exception via Watchdog — régression du bug corrigé le 09/08/2026 (round 144)"
    );

    return [
        'pass'    => true,
        'message' => "WebhookManager::processQueue() isole bien chaque ligne du lot dans son propre try/catch, alignée sur QueueManager::processSingle()",
    ];
}
