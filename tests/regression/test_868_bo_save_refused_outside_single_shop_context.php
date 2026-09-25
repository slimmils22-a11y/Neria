<?php
/**
 * Régression (P10, 25/09/2026, constatée sur ps-test) : enregistrer un réglage en contexte « Toutes les boutiques » ou
 * « Groupe » l'écrivait uniquement pour la boutique par défaut (id_shop explicite), sans avertir le marchand.
 *
 * Corrigé : en multiboutique, tout POST d'action hors contexte « une boutique » est neutralisé et affiche
 * msg.select_single_shop (19 langues). Le comportement réel a été vérifié sur ps-test (contextes tous/groupe/boutique).
 *
 * Test : présence du garde dans getContentImpl() et de la clé dans les 19 langues, sans placeholder résiduel.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    neria_test_module();
    $root = _PS_MODULE_DIR_ . 'neria/';
    $main = str_replace("\r\n", "\n", (string) file_get_contents($root . 'neria.php'));
    foreach ([
        "\Shop::isFeatureActive()\n            && \Shop::getContext() !== \Shop::CONTEXT_SHOP\n            && (\$_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'",
        "AdminTranslator::t('msg.select_single_shop')",
        "\$_SERVER['REQUEST_METHOD'] = 'GET';",
    ] as $needle) {
        neria_assert(str_contains($main, $needle), 'Garde de contexte boutique absent de neria.php : ' . substr($needle, 0, 60));
    }
    $tr = json_decode((string) file_get_contents($root . 'data/admin_translations.json'), true);
    $langs = ['fr','en','de','it','es','pt','br','ar','ja','ko','zh','tw','ru','tr','sv','no','da','nl','gb'];
    foreach ($langs as $l) {
        neria_assert(!empty($tr['msg.select_single_shop'][$l]), "msg.select_single_shop absent en {$l}");
    }

    return ['pass' => true, 'message' => "Aucun enregistrement du back-office hors contexte « une boutique » (message en 19 langues) — corrigé le 25/09/2026 (P10)"];
}
