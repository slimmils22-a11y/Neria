<?php
/**
 * Régression : MonthlyReportManager::isDue()/markSent() doivent transmettre
 * $idShop en 4e argument à Configuration::get()/updateValue(), pas se fier
 * au contexte ambiant.
 *
 * Bug réel corrigé le 08/08/2026 (round 111) : checkAndSend() bascule
 * Context::getContext()->shop = new \Shop($idShop) avant chaque itération,
 * mais Configuration::get()/updateValue() sans $idShop explicite résolvent
 * la boutique via Shop::getContextShopID() → Shop::$context_id_shop, une
 * variable statique que seul Shop::setContext()/Shop::initialize() modifie
 * — jamais une simple réaffectation de Context::getContext()->shop. Le nom
 * de clé était déjà suffixé par boutique (NERIA_REPORT_LAST_SENT_3), mais la
 * ligne était en réalité lue/écrite sous l'id_shop RÉEL du visiteur ayant
 * déclenché le hook, pas celui de l'itération en cours — cassant la
 * déduplication entre boutiques (un rapport marqué "envoyé" pour la
 * boutique A sous couvert de l'id_shop ambiant B ne serait jamais retrouvé
 * en relisant sous l'id_shop A réel, provoquant un nouvel envoi en double).
 *
 * Non vérifiable en conditions multi-boutiques réelles sur cet environnement
 * de dev (une seule boutique configurée, Shop::isFeatureActive() renvoie
 * false, ce qui force Configuration::get()/updateValue() du cœur PS à
 * ignorer tout $idShop explicite reçu — même limite que test_37/40/60).
 * Vérifie donc au niveau du code source que $idShop est bien transmis aux
 * deux appels (garde-fou structurel), plutôt qu'un comportement observable
 * ici.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/MonthlyReportManager.php');
    neria_assert($src !== false, 'Impossible de lire src/MonthlyReportManager.php');

    neria_assert(
        strpos($src, "\$last   = (string) \\Configuration::get(\$key, null, null, \$idShop);") !== false,
        "isDue() ne transmet plus \$idShop à Configuration::get() — régression du bug corrigé le 08/08/2026 (round 111) : la lecture retomberait de nouveau sur l'id_shop du contexte ambiant, pas celui de la boutique en cours d'itération"
    );

    $posMarkSent = strpos($src, 'private function markSent(');
    neria_assert($posMarkSent !== false, "Méthode markSent() introuvable — jeu de test invalide");
    $block = substr($src, $posMarkSent, 500);

    neria_assert(
        strpos($block, 'sprintf(\'%04d-%02d\', $year, $month),') !== false
        && strpos($block, 'false,') !== false
        && strpos($block, 'null,') !== false
        && strpos($block, '$idShop') !== false
        && strpos($block, '\Configuration::updateValue(') !== false,
        "markSent() ne transmet plus \$idShop à Configuration::updateValue() (5 arguments attendus : \$key, valeur, \$html, \$idShopGroup, \$idShop) — régression du bug corrigé le 08/08/2026 (round 111)"
    );

    // Vérifie précisément la présence des 5 arguments dans l'appel (et pas
    // juste des sous-chaînes éparses qui pourraient matcher par coïncidence
    // ailleurs dans le bloc).
    neria_assert(
        preg_match('/\\\\Configuration::updateValue\(\s*\$key,\s*sprintf\(\'%04d-%02d\',\s*\$year,\s*\$month\),\s*false,\s*null,\s*\$idShop\s*\);/', $block) === 1,
        "L'appel à Configuration::updateValue() dans markSent() n'a plus la signature complète (\$key, valeur, false, null, \$idShop) — régression du bug corrigé le 08/08/2026 (round 111)"
    );

    return [
        'pass'    => true,
        'message' => "MonthlyReportManager::isDue()/markSent() transmettent bien \$idShop à Configuration::get()/updateValue(), plutôt que de se fier au contexte ambiant (Shop::\$context_id_shop, jamais modifié par une réaffectation de Context::getContext()->shop)",
    ];
}
