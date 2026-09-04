<?php
/**
 * Régression : SegmentManager::recomputeAll() doit journaliser une erreur
 * Watchdog quand l'INSERT...ON DUPLICATE KEY UPDATE échoue réellement,
 * au lieu de journaliser inconditionnellement un succès.
 *
 * Bug réel corrigé le 09/08/2026 (round 148) : le résultat de execute()
 * était totalement ignoré (aucun appel wd()->error() nulle part dans tout
 * le fichier), et le log de succès était émis inconditionnellement à
 * partir d'un simple Affected_Rows() non fiable en cas d'échec. Un échec
 * SQL réel laissait neria_segment non recalculé, sans trace exploitable.
 *
 * Test structurel (même limite d'environnement _PS_DEBUG_SQL_ que les
 * autres tests de ce round) : vérifie que recomputeAll() capture bien le
 * résultat de execute() et journalise une erreur Watchdog sur échec.
 *
 * Fenêtre élargie à 10000 (round 181) : le recomptage réel de $affected
 * (correctif round 181, cf. test_370) a repoussé le bloc if ($execOk ===
 * false) plus loin dans la méthode.
 * Fenêtre élargie à 11500 (round 300) : la déduction des conversions
 * remboursées à ≥90% (round 300, sous-requêtes corrélées order_slip/
 * orders dans le SQL de recomputeAll()) a de nouveau repoussé ce bloc.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SegmentManager.php');
    neria_assert($src !== false, 'Impossible de lire src/SegmentManager.php');

    $posFn = strpos($src, 'public function recomputeAll(): int');
    neria_assert($posFn !== false, 'recomputeAll() introuvable — jeu de test invalide');
    $body = substr($src, $posFn, 11500);

    neria_assert(
        strpos($body, '$execOk = $this->db->execute($sql);') !== false,
        "recomputeAll() ne capture plus le resultat reel de execute() — regression du bug corrige le 09/08/2026 (round 148)"
    );
    neria_assert(
        strpos($body, 'if ($execOk === false) {') !== false,
        "recomputeAll() ne teste plus explicitement un echec SQL — regression du bug corrige le 09/08/2026 (round 148)"
    );
    neria_assert(
        strpos($body, 'segment_recompute_sql_failed') !== false,
        "recomputeAll() ne journalise plus d'erreur Watchdog sur un echec SQL — regression du bug corrige le 09/08/2026 (round 148)"
    );

    return [
        'pass'    => true,
        'message' => "SegmentManager::recomputeAll() journalise bien une erreur Watchdog sur un echec SQL reel",
    ];
}
