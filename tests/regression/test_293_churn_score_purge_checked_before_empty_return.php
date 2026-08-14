<?php
/**
 * Régression : ChurnScoreManager::recomputeAll() retournait 0
 * immédiatement quand $rows était vide (boutique jeune où le filtre
 * "historique insuffisant" élimine tous les clients), SANS jamais
 * vérifier $sqlOk (résultat du DELETE de purge) ni journaliser d'erreur —
 * alors que le commentaire du round 148 promet explicitement que $sqlOk
 * sert à tracer ces échecs. Un DELETE réellement en échec (verrou
 * concurrent, table corrompue) restait donc invisible dans ce cas précis.
 *
 * Corrigé le 14/08/2026 (round 166) : le check `if (!$sqlOk)` est
 * désormais fait AVANT le retour anticipé `if (empty($rows)) return 0;`,
 * pas seulement sur le chemin normal (rows non vide).
 *
 * Test structurel : vérifie que le check de $sqlOk apparaît bien AVANT le
 * retour anticipé sur $rows vide dans le code source.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ChurnScoreManager.php');
    neria_assert($src !== false, 'Impossible de lire src/ChurnScoreManager.php');

    $posFn = strpos($src, 'public function recomputeAll(): int');
    neria_assert($posFn !== false, 'recomputeAll() introuvable — jeu de test invalide');

    $posSqlOk = strpos($src, '$sqlOk = $this->db->execute($purgeSql);', $posFn);
    neria_assert($posSqlOk !== false, '$sqlOk = $this->db->execute($purgeSql) introuvable — jeu de test invalide');

    $posEmptyReturn = strpos($src, 'if (empty($rows)) {', $posSqlOk);
    neria_assert($posEmptyReturn !== false, 'Retour anticipé sur $rows vide introuvable — jeu de test invalide');

    $posSqlOkCheck = strpos($src, 'if (!$sqlOk) {', $posSqlOk);
    neria_assert(
        $posSqlOkCheck !== false && $posSqlOkCheck < $posEmptyReturn,
        "recomputeAll() ne vérifie plus \$sqlOk AVANT le retour anticipé sur \$rows vide — régression du bug corrigé le 14/08/2026 (round 166) : un échec de purge redeviendrait invisible quand aucun client n'est éligible au recalcul"
    );

    return [
        'pass'    => true,
        'message' => "ChurnScoreManager::recomputeAll() vérifie bien \$sqlOk avant tout retour anticipé, y compris sur \$rows vide — bug corrigé le 14/08/2026 (round 166)",
    ];
}
