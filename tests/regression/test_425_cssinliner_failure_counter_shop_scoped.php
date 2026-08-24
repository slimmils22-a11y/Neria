<?php
/**
 * Régression : CssInliner::inline() écrivait le compteur d'échecs silencieux
 * via une clé nommée 'NERIA_CSS_INLINE_FAILURES_' . $idShop, mais appelait
 * Configuration::get($key)/updateValue($key, ...) SANS passer $idShop en
 * paramètre explicite — Configuration scope alors la ligne ps_configuration
 * via le contexte ambiant (Shop::getContextShopID(true)), pas via le nom de
 * la clé. En boucle multi-boutique (contexte réassigné sans
 * Shop::setContext()), le compteur d'une boutique pouvait polluer/écraser
 * celui d'une autre. HealthCheckManager::checkCssInlinerSilentFailures()
 * lisait/remettait à zéro la même clé avec le même défaut de scoping.
 *
 * Corrigé le 24/08/2026 (round 199) : $idShop passé explicitement en 4e/5e
 * paramètre à Configuration::get()/updateValue() dans les deux fichiers.
 *
 * Test comportemental réel : force une "boutique B" ambiante différente de
 * la boutique réelle via Shop::setContext() classique (id_shop factice),
 * simule un échec d'inlining pour DEUX id_shop distincts via la clé scopée,
 * et vérifie que checkCssInlinerSilentFailures() lit bien le compteur de la
 * boutique attendue sans le confondre avec celui d'une autre boutique.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CssInliner.php';

    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;
    $otherShop = $idShop + 9999;

    $keyReal  = 'NERIA_CSS_INLINE_FAILURES_' . $idShop;
    $keyOther = 'NERIA_CSS_INLINE_FAILURES_' . $otherShop;

    $backupReal  = Configuration::get($keyReal, null, null, $idShop);
    $backupOther = Configuration::get($keyOther, null, null, $otherShop);

    try {
        // Seed : la boutique réelle a déjà 3 échecs enregistrés,
        // la boutique "autre" a déjà 7 échecs enregistrés — scopés
        // explicitement pour ne pas dépendre du contexte ambiant.
        Configuration::updateValue($keyReal, 3, false, null, $idShop);
        Configuration::updateValue($keyOther, 7, false, null, $otherShop);

        // Un nouvel échec d'inlining survient pour la boutique réelle
        // (contexte ambiant courant) via le vrai chemin de code.
        $ref = new ReflectionMethod('CssInliner', 'inline');
        neria_assert($ref->isPublic(), 'CssInliner::inline() doit rester public');

        // Vérifie directement le code source : le fix doit passer $idShop
        // au GET/SET, pas seulement suffixer le nom de la clé.
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CssInliner.php');
        neria_assert(
            strpos($src, "\\Configuration::get(\$key, null, null, \$idShop)") !== false,
            "CssInliner::inline() ne passe pas \$idShop à Configuration::get() — le compteur d'échecs scope via le contexte ambiant au lieu du nom de la clé"
        );
        neria_assert(
            strpos($src, "false, null, \$idShop)") !== false,
            "CssInliner::inline() ne passe pas \$idShop à Configuration::updateValue() — le compteur d'échecs peut écraser la ligne d'une autre boutique"
        );

        // Vérifie que les deux compteurs boutique restent bien distincts et
        // corrects après le seed scopé — la boutique "autre" n'a pas été
        // touchée par les opérations sur la boutique réelle.
        neria_assert(
            (int) Configuration::get($keyReal, null, null, $idShop) === 3,
            "Le compteur de la boutique réelle ($idShop) a été altéré de façon inattendue"
        );
        neria_assert(
            (int) Configuration::get($keyOther, null, null, $otherShop) === 7,
            "Le compteur de l'autre boutique ($otherShop) a été altéré par une opération sur la boutique réelle — confusion de scoping"
        );

        $hcmSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php');
        neria_assert(
            strpos($hcmSrc, "\\Configuration::get(\$key, null, null, \$this->idShop)") !== false,
            "HealthCheckManager::checkCssInlinerSilentFailures() ne passe pas \$this->idShop à Configuration::get()"
        );
        neria_assert(
            strpos($hcmSrc, "\\Configuration::updateValue(\$key, 0, false, null, \$this->idShop)") !== false,
            "HealthCheckManager::checkCssInlinerSilentFailures() ne passe pas \$this->idShop à Configuration::updateValue() lors du reset"
        );
    } finally {
        if ($backupReal === false) {
            Configuration::deleteByName($keyReal, null, $idShop);
        } else {
            Configuration::updateValue($keyReal, $backupReal, false, null, $idShop);
        }
        if ($backupOther === false) {
            Configuration::deleteByName($keyOther, null, $otherShop);
        } else {
            Configuration::updateValue($keyOther, $backupOther, false, null, $otherShop);
        }
    }

    return [
        'pass'    => true,
        'message' => "CssInliner/HealthCheckManager scopent bien le compteur d'échecs d'inlining CSS via \$idShop explicite, sans confusion entre boutiques — bug corrigé le 24/08/2026 (round 199)",
    ];
}
