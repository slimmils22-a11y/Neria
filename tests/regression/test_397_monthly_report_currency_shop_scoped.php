<?php
/**
 * Régression : MonthlyReportManager::renderHtml()/resolveRecVars() résolvaient
 * la devise via Currency::getDefaultCurrency() (sans idShop), qui retombe sur
 * Configuration::get('PS_CURRENCY_DEFAULT') SANS idShop explicite — donc sur
 * Shop::$context_id_shop (contexte boutique STATIQUE). La boucle multi-
 * boutique de checkAndSend() ne fait que réassigner
 * Context::getContext()->shop (round 165), jamais Shop::setContext() — ce
 * contexte statique restait donc celui du visiteur front ayant déclenché le
 * hook, pas forcément la boutique DU RAPPORT en cours de rendu.
 *
 * Bug réel identifié le 23/08/2026 (round 188) : sur une install
 * multi-boutiques à devises différentes, le CA du rapport mensuel d'une
 * boutique pouvait s'afficher avec le symbole/format de la devise d'une
 * AUTRE boutique.
 *
 * Corrigé le 23/08/2026 (round 188) : Configuration::get('PS_CURRENCY_DEFAULT',
 * null, null, $this->idShop) explicite dans les 2 méthodes.
 *
 * Test structurel (renderHtml()/resolveRecVars() sont privées et nécessitent
 * un jeu de données complet de rapport — hors de portée d'un test isolé sans
 * fixture complète) : vérifie par lecture directe du source que les 2
 * résolutions de devise passent bien $this->idShop.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/MonthlyReportManager.php');
    neria_assert($src !== false, 'Impossible de lire src/MonthlyReportManager.php');

    $needle = "Configuration::get('PS_CURRENCY_DEFAULT', null, null, \$this->idShop)";
    $count = substr_count($src, $needle);

    neria_assert(
        $count >= 2,
        "MonthlyReportManager ne résout plus PS_CURRENCY_DEFAULT avec \$this->idShop qu'à {$count} endroit(s) (attendu >= 2 : renderHtml() + resolveRecVars()) — régression du bug corrigé le 23/08/2026 (round 188) : le CA du rapport d'une boutique pourrait de nouveau s'afficher avec la devise d'une autre boutique sur une install multi-boutiques"
    );

    // On ne vérifie que l'usage RÉEL (affectation), pas les commentaires qui
    // mentionnent légitimement Currency::getDefaultCurrency() en prose pour
    // expliquer le bug corrigé.
    $codeUsage = substr_count($src, '= \Currency::getDefaultCurrency();');
    neria_assert(
        $codeUsage === 0,
        "MonthlyReportManager utilise de nouveau Currency::getDefaultCurrency() (non scopé par boutique) — régression du bug corrigé le 23/08/2026 (round 188)"
    );

    return [
        'pass'    => true,
        'message' => "MonthlyReportManager::renderHtml()/resolveRecVars() résolvent bien PS_CURRENCY_DEFAULT avec \$this->idShop explicite — bug corrigé le 23/08/2026 (round 188)",
    ];
}
