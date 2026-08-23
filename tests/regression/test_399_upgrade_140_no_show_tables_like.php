<?php
/**
 * Régression : upgrade-1.0.40.php utilisait encore "SHOW TABLES LIKE '...'"
 * à 2 endroits (backfill neria_behavioral_sent, boucle des "autres tables"
 * ambiguës), alors que ce même fichier documente (commentaire round 167,
 * fonction neria_upgrade_1_0_40_ensure_unique_key()) que ce motif plante
 * sous certains couples MySQL/PDO dès que Db::getValue() lui ajoute
 * automatiquement "LIMIT 1" (MySQL rejette "SHOW TABLES LIKE '...' LIMIT 1",
 * erreur 1064) — et utilise déjà information_schema pour s'en prémunir dans
 * cette même fonction.
 *
 * Bug réel identifié le 23/08/2026 (round 188) : sur une combinaison
 * MySQL/PDO affectée, le backfill id_shop de neria_behavioral_sent (objectif
 * n°1 documenté de ce script) aurait été silencieusement sauté (ou aurait fait
 * échouer l'upgrade), sur exactement les installations multi-boutiques
 * ciblées par ce correctif.
 *
 * Corrigé le 23/08/2026 (round 188) : les 2 occurrences remplacées par une
 * requête information_schema.TABLES, cohérente avec le reste du fichier.
 *
 * Test structurel : upgrade-1.0.40.php ne doit plus contenir aucune
 * occurrence de "SHOW TABLES LIKE".
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/upgrade/upgrade-1.0.40.php');
    neria_assert($src !== false, 'Impossible de lire upgrade/upgrade-1.0.40.php');

    // On ne compte que l'usage RÉEL (appel getValue()), pas les commentaires
    // qui mentionnent légitimement "SHOW TABLES LIKE" en prose pour expliquer
    // le bug historique (round 167) et le correctif (round 188).
    $codeCount = substr_count($src, "getValue(\"SHOW TABLES LIKE");
    neria_assert(
        $codeCount === 0,
        "upgrade-1.0.40.php contient encore {$codeCount} appel(s) réel(s) à \"SHOW TABLES LIKE\" via Db::getValue() — régression du bug corrigé le 23/08/2026 (round 188) : ce motif plante sous certains couples MySQL/PDO (LIMIT 1 auto-ajouté par Db::getValue()), pouvant faire échouer/sauter silencieusement le backfill id_shop sur une install multi-boutiques"
    );

    $infoSchemaCount = substr_count($src, 'information_schema.TABLES');
    neria_assert(
        $infoSchemaCount >= 3,
        "upgrade-1.0.40.php n'utilise information_schema.TABLES qu'à {$infoSchemaCount} endroit(s) (attendu >= 3 : neria_upgrade_1_0_40_ensure_unique_key() + \$bhExists + boucle \$otherTables) — jeu de test invalide ou correctif incomplet"
    );

    return [
        'pass'    => true,
        'message' => "upgrade-1.0.40.php n'utilise plus SHOW TABLES LIKE nulle part, uniquement information_schema.TABLES — bug corrigé le 23/08/2026 (round 188)",
    ];
}
