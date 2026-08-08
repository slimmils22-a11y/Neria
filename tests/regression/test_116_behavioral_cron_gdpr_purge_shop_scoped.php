<?php
/**
 * Régression : BehavioralCronManager::run() doit transmettre $idShop en 4e
 * argument à Configuration::get('NERIA_GDPR_AUTO_PURGE_ENABLED', ...), pas
 * se fier au contexte ambiant.
 *
 * Bug réel corrigé le 08/08/2026 (round 112) : la boucle par boutique
 * réassigne Context::getContext()->shop = new \Shop($idShop) avant chaque
 * itération (déjà correct pour GdprAuditManager lui-même, qui lit
 * $context->shop->id dans son constructeur), mais cette réassignation ne
 * modifie PAS Shop::$context_id_shop — la variable statique interne que
 * seul Shop::setContext() modifie et dont dépend Configuration::get() sans
 * $idShop explicite. Même piège déjà corrigé dans MonthlyReportManager
 * (round 111). Sur une install multi-boutiques où seule une des boutiques a
 * la purge RGPD automatique activée, le test relisait toujours le réglage
 * de la boutique ambiante réelle (typiquement la première visitée),
 * appliquant à tort ce même réglage à TOUTES les boutiques de la boucle —
 * la purge pouvait ne jamais s'exécuter pour une boutique qui l'a
 * explicitement activée (risque de conformité RGPD), ou s'exécuter sur une
 * boutique qui l'a désactivée (suppression de données non désirée).
 *
 * Non vérifiable en conditions multi-boutiques réelles sur cet environnement
 * de dev (une seule boutique configurée) — même limite que test_37/40/60/115.
 * Vérifie donc au niveau du code source que $idShop est bien transmis
 * (garde-fou structurel).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php');
    neria_assert($src !== false, 'Impossible de lire src/BehavioralCronManager.php');

    neria_assert(
        strpos($src, "\\Configuration::get('NERIA_GDPR_AUTO_PURGE_ENABLED', null, null, \$idShop)") !== false,
        "BehavioralCronManager::run() ne transmet plus \$idShop à Configuration::get('NERIA_GDPR_AUTO_PURGE_ENABLED', ...) — régression du bug corrigé le 08/08/2026 (round 112) : le réglage de purge RGPD retomberait de nouveau sur la boutique ambiante réelle plutôt que celle de l'itération en cours"
    );

    return [
        'pass'    => true,
        'message' => "BehavioralCronManager::run() transmet bien \$idShop à Configuration::get('NERIA_GDPR_AUTO_PURGE_ENABLED', ...), scopant correctement la purge RGPD automatique par boutique",
    ];
}
