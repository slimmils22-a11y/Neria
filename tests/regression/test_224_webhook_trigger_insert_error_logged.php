<?php
/**
 * Régression : WebhookManager::trigger() doit journaliser une erreur
 * Watchdog si l'INSERT de mise en file de l'événement échoue réellement.
 *
 * Bug réel corrigé le 09/08/2026 (round 148) : le résultat de execute()
 * n'était jamais capturé ni journalisé (ni succès ni échec), contrairement
 * à processQueue() (même fichier) qui gère méticuleusement ses propres
 * échecs (secret illisible, verrou MySQL). Un échec SQL réel sur cet
 * INSERT faisait qu'un événement webhook n'était jamais mis en file, sans
 * aucune trace exploitable.
 *
 * Test structurel (même limite d'environnement _PS_DEBUG_SQL_ que les
 * autres tests de ce round) : vérifie que trigger() capture bien le
 * résultat de l'INSERT et journalise une erreur Watchdog sur échec.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WebhookManager.php');
    neria_assert($src !== false, 'Impossible de lire src/WebhookManager.php');

    $posFn = strpos($src, 'public function trigger(string $event, array $data): void');
    neria_assert($posFn !== false, 'trigger() introuvable — jeu de test invalide');
    // Fenêtre élargie 3300→3700 (round 305) : le commentaire expliquant le
    // réordonnancement d'array_merge() (clés système en dernier, cf.
    // garde-fou round 305) a repoussé les littéraux plus loin.
    $body = substr($src, $posFn, 3700);

    neria_assert(
        strpos($body, '$ok = $this->db->execute(sprintf(') !== false,
        "trigger() ne capture plus le resultat reel de l'INSERT — regression du bug corrige le 09/08/2026 (round 148)"
    );
    neria_assert(
        strpos($body, 'if ($ok === false) {') !== false,
        "trigger() ne teste plus explicitement un echec SQL — regression du bug corrige le 09/08/2026 (round 148)"
    );
    neria_assert(
        strpos($body, 'webhook_trigger_insert_failed') !== false,
        "trigger() ne journalise plus d'erreur Watchdog sur un echec SQL — regression du bug corrige le 09/08/2026 (round 148) : un evenement webhook perdu redeviendrait invisible"
    );

    return [
        'pass'    => true,
        'message' => "WebhookManager::trigger() journalise bien une erreur Watchdog quand l'INSERT de mise en file echoue reellement",
    ];
}
