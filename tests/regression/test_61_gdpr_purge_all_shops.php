<?php
/**
 * Régression : BehavioralCronManager::run() doit appeler
 * GdprAuditManager::purgeAllRegistryTables() À L'INTÉRIEUR de la boucle
 * foreach ($shops as $idShop), avant la restauration du contexte d'origine
 * — pas après.
 *
 * Bug réel corrigé le 05/08/2026 (round 58) : GdprAuditManager capture
 * $this->idShop du contexte à sa construction et scope toutes ses requêtes
 * de purge par cette boutique (comme SegmentManager/ChurnScoreManager/
 * PropensityScoreManager juste au-dessus, déjà dans la boucle). Un appel
 * unique après `\Context::getContext()->shop = $originalShop;` ne purgeait
 * donc jamais que la boutique du contexte d'origine — les autres boutiques
 * d'une install multi-boutiques dépassaient silencieusement leur rétention
 * RGPD configurée, indéfiniment.
 *
 * Non vérifiable en conditions multi-boutiques réelles sur cet environnement
 * de dev (une seule boutique configurée) — vérifie donc au niveau du code
 * source que l'appel reste bien positionné dans la boucle par boutique,
 * avant la restauration du contexte (garde-fou structurel, comme
 * test_37/test_40/test_60).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php');
    neria_assert($src !== false, 'Impossible de lire src/BehavioralCronManager.php');

    $posLoopStart = strpos($src, 'foreach ($shops as $idShop) {');
    $posPurgeCall = strpos($src, 'purgeAllRegistryTables()');
    $posRestore   = strpos($src, '\Context::getContext()->shop = $originalShop;');

    neria_assert($posLoopStart !== false, "foreach (\$shops as \$idShop) introuvable dans BehavioralCronManager::run()");
    neria_assert($posPurgeCall !== false, "purgeAllRegistryTables() introuvable dans BehavioralCronManager::run()");
    neria_assert($posRestore !== false, "restauration du contexte d'origine introuvable dans BehavioralCronManager::run()");

    neria_assert(
        $posPurgeCall > $posLoopStart && $posPurgeCall < $posRestore,
        "purgeAllRegistryTables() n'est plus appelée à l'intérieur de la boucle par boutique (avant la restauration du contexte d'origine) — régression du bug corrigé le 05/08/2026 (round 58) : seule la boutique du contexte d'origine serait de nouveau purgée"
    );

    return ['pass' => true, 'message' => "purgeAllRegistryTables() reste bien appelée dans la boucle par boutique, pour chaque boutique active"];
}
