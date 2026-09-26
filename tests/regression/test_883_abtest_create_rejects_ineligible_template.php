<?php
/**
 * Régression (P8, 26/09/2026, constatée via le back-office de ps-test) : create_abtest acceptait n'importe quel identifiant de
 * modèle (ex. « welcome », non éligible) et répondait « Enregistré » en créant un test « actif » fantôme (jamais mesuré, archivé
 * ensuite dans l'historique). Seuls les modèles marketing de getEligibleTemplates() peuvent porter un test A/B.
 * Corrigé : un modèle hors liste est refusé (message d'erreur), rien n'est créé.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $module = neria_test_module();
    $eligible = (new \ABTestManager($module))->getEligibleTemplates();
    neria_assert(array_key_exists('abandoned_cart_1', $eligible), 'abandoned_cart_1 doit rester éligible');
    neria_assert(!array_key_exists('welcome', $eligible) && !array_key_exists('order_conf', $eligible), 'un modèle transactionnel est devenu éligible');
    $src = str_replace("\r\n", "\n", (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php'));
    $i = strpos($src, "=== 'create_abtest'");
    neria_assert($i !== false, 'action create_abtest introuvable');
    $block = substr($src, $i, 1800);
    neria_assert(str_contains($block, '!array_key_exists($tplKey, (new ABTestManager($this))->getEligibleTemplates())'), "create_abtest ne refuse plus un modèle hors liste d'éligibilité");

    return ['pass' => true, 'message' => 'create_abtest refuse un modèle non éligible (aucun test fantôme) — corrigé le 26/09/2026'];
}
