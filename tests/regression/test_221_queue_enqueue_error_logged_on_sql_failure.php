<?php
/**
 * Régression : QueueManager::enqueue() doit distinguer un VRAI échec SQL
 * (execute() === false) d'un simple doublon ignoré par INSERT IGNORE
 * (Affected_Rows() === 0 mais execute() a réussi), et journaliser une
 * erreur Watchdog dans le premier cas — jamais dans le second.
 *
 * Bug réel corrigé le 09/08/2026 (round 148) : le résultat de execute()
 * était totalement ignoré, contrairement à sa jumelle enqueueAt() (même
 * fichier) qui capture déjà correctement $ok = execute() && Affected_Rows()
 * > 0. Un échec SQL réel (verrou, erreur SQL) était silencieusement traité
 * comme un doublon anodin, sans aucune trace Watchdog exploitable.
 *
 * Test structurel (même limite d'environnement que les tests d'AbTestManager
 * du round 147 : _PS_DEBUG_SQL_ actif sur ce dev fait lever une exception
 * PHP plutôt que de faire retourner false à Db::execute() sur une vraie
 * erreur SQL) : vérifie que enqueue() capture bien $execOk et journalise une
 * erreur Watchdog quand $execOk === false, distinct du cas doublon ignoré.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/QueueManager.php');
    neria_assert($src !== false, 'Impossible de lire src/QueueManager.php');

    $posFn = strpos($src, 'public function enqueue(');
    $posEnd = strpos($src, 'public function enqueueAt(', $posFn);
    neria_assert($posFn !== false && $posEnd !== false, 'enqueue() introuvable — jeu de test invalide');
    $body = substr($src, $posFn, $posEnd - $posFn);

    neria_assert(
        strpos($body, '$execOk = $this->db->execute(') !== false,
        "enqueue() ne capture plus le resultat reel de execute() dans \$execOk — regression du bug corrige le 09/08/2026 (round 148)"
    );
    neria_assert(
        strpos($body, 'if ($execOk === false) {') !== false,
        "enqueue() ne distingue plus un echec SQL reel d'un doublon ignore — regression du bug corrige le 09/08/2026 (round 148)"
    );
    neria_assert(
        strpos($body, 'queue_scheduling_failed') !== false,
        "enqueue() ne journalise plus d'erreur Watchdog sur un echec SQL reel — regression du bug corrige le 09/08/2026 (round 148) : un echec de planification redeviendrait invisible"
    );

    return [
        'pass'    => true,
        'message' => "QueueManager::enqueue() distingue bien un echec SQL reel d'un doublon ignore, et journalise une erreur Watchdog sur le premier cas",
    ];
}
