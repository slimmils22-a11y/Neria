<?php
/**
 * Régression : NERIA_STATS_CACHE doit toujours être écrit/lu avec l'id_shop
 * explicite (5e argument de Configuration::updateValue()/get()).
 *
 * Bug réel corrigé le 02/08/2026 (commit 92d2ed0) : cette clé était écrite/lue
 * comme une valeur GLOBALE (sans scope boutique) — sur une install
 * multi-boutiques, un employé consultant les stats de la Boutique A puis
 * basculant sur la Boutique B dans la fenêtre de cache (30 min) récupérait
 * les chiffres de A affichés comme siens.
 *
 * Non vérifiable en conditions multi-boutiques réelles sur cet environnement
 * de dev (Shop::isFeatureActive() = false, une seule boutique configurée) —
 * PrestaShop lui-même ramène tout enregistrement "scopé boutique" vers la
 * ligne globale tant que le multi-boutiques n'est pas activé. Ce test vérifie
 * donc au niveau du code source que l'argument id_shop reste bien présent
 * (garde-fou structurel, comme test_34), plutôt qu'un comportement observable
 * ici. À rejouer manuellement sur une install multi-boutiques réelle si
 * possible.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/StatsManager.php');
    neria_assert($src !== false, 'Impossible de lire src/StatsManager.php');

    // computeReports() : Configuration::updateValue('NERIA_STATS_CACHE', ..., false, null, $this->idShop)
    neria_assert(
        (bool) preg_match(
            "/updateValue\\(\\s*'NERIA_STATS_CACHE'[\\s\\S]{0,200}?\\\$this->idShop/",
            $src
        ),
        "computeReports() n'écrit plus NERIA_STATS_CACHE avec \$this->idShop — régression du bug de cache cross-boutique corrigé le 02/08/2026 (commit 92d2ed0)"
    );

    // getCachedReports() : Configuration::get('NERIA_STATS_CACHE', null, null, $this->idShop)
    neria_assert(
        (bool) preg_match(
            "/Configuration::get\\(\\s*'NERIA_STATS_CACHE'[\\s\\S]{0,80}?\\\$this->idShop/",
            $src
        ),
        "getCachedReports() ne lit plus NERIA_STATS_CACHE avec \$this->idShop — régression du bug de cache cross-boutique corrigé le 02/08/2026 (commit 92d2ed0)"
    );

    return ['pass' => true, 'message' => 'NERIA_STATS_CACHE reste toujours scopé par id_shop dans computeReports()/getCachedReports()'];
}
