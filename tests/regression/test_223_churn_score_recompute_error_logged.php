<?php
/**
 * Régression : ChurnScoreManager::recomputeAll() doit journaliser une
 * erreur Watchdog si la purge DELETE ou un lot INSERT échoue réellement,
 * et ne plus compter les lots échoués comme insérés.
 *
 * Bug réel corrigé le 09/08/2026 (round 148) : ni le DELETE de purge ni les
 * INSERT par lots de 50 ne vérifiaient jamais le résultat de execute()
 * (aucun wd()->error() nulle part dans tout le fichier), et le résumé
 * final était journalisé comme un succès inconditionnel basé sur un
 * simple count($chunk) plutôt que sur le résultat réel des requêtes. Un
 * échec en cours de boucle (verrou) laissait neria_churn_score partiellement
 * à jour, sans trace exploitable et avec un compteur "inserted" mensonger.
 *
 * Test structurel (même limite d'environnement _PS_DEBUG_SQL_ que les
 * autres tests de ce round) : vérifie que recomputeAll() accumule $sqlOk,
 * ne compte $inserted que sur un lot réellement réussi, et journalise une
 * erreur Watchdog en cas d'échec partiel.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ChurnScoreManager.php');
    neria_assert($src !== false, 'Impossible de lire src/ChurnScoreManager.php');

    $posFn = strpos($src, 'public function recomputeAll(): int');
    neria_assert($posFn !== false, 'recomputeAll() introuvable — jeu de test invalide');
    $posEnd = strpos($src, "\n    /**\n     * Calcule le score", $posFn);
    $body = $posEnd !== false ? substr($src, $posFn, $posEnd - $posFn) : substr($src, $posFn, 3000);

    neria_assert(
        strpos($body, '$sqlOk = $this->db->execute($purgeSql);') !== false,
        "recomputeAll() ne capture plus le resultat reel du DELETE de purge — regression du bug corrige le 09/08/2026 (round 148)"
    );
    neria_assert(
        strpos($body, '$chunkOk = $this->db->execute(') !== false && strpos($body, '$sqlOk = $sqlOk && $chunkOk;') !== false,
        "recomputeAll() ne capture/accumule plus le resultat reel de chaque lot INSERT — regression du bug corrige le 09/08/2026 (round 148)"
    );
    neria_assert(
        strpos($body, 'if ($chunkOk !== false) {') !== false,
        "recomputeAll() compte de nouveau un lot echoue comme insere — regression du bug corrige le 09/08/2026 (round 148) : le compteur \$inserted redeviendrait mensonger"
    );
    neria_assert(
        strpos($body, "if (!\$sqlOk) {") !== false && strpos($body, 'churn_score_partial_failure') !== false,
        "recomputeAll() ne journalise plus d'erreur Watchdog en cas d'echec partiel — regression du bug corrige le 09/08/2026 (round 148)"
    );

    return [
        'pass'    => true,
        'message' => "ChurnScoreManager::recomputeAll() accumule bien le resultat reel de ses requetes SQL et journalise une erreur en cas d'echec partiel",
    ];
}
