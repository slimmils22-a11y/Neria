<?php
/**
 * Régression (P10, 25/09/2026, constatée sur ps-test) : EmailRenderer::voucherRateFromCode() affichait le montant
 * d'un bon avec la devise du CONTEXTE ambiant au lieu de celle du bon (reduction_currency) : un bon de 20 € de la
 * boutique 2 s'affichait « 20,00 $ » quand le processus tournait sous la boutique 1, et un contexte sans devise
 * provoquait un TypeError fatal.
 *
 * Corrigé : devise du bon, puis devise du contexte, puis devise par défaut de la boutique.
 *
 * Test comportemental : un bon de 20 en livres, contexte en euros → le texte contient « £ » et pas « € » ; contexte
 * sans devise → pas d'exception.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $module = neria_test_module();
    $db = neria_test_db();
    $p = neria_test_prefix();
    $idGbp = (int) Currency::getIdByIsoCode('GBP');
    $idEur = (int) Currency::getIdByIsoCode('EUR');
    if ($idGbp <= 0 || $idEur <= 0 || $idGbp === $idEur) {
        return ['pass' => true, 'message' => 'Devises GBP/EUR absentes de cette base — test non applicable'];
    }
    $ctx = Context::getContext();
    $oldCurrency = $ctx->currency;
    $code = 'REGTEST866' . strtoupper(bin2hex(random_bytes(3)));
    $ruleId = 0;

    try {
        $rule = new CartRule();
        $rule->code = $code;
        $rule->reduction_amount = 20;
        $rule->reduction_currency = $idGbp;
        $rule->reduction_tax = 1;
        $rule->active = 1;
        $rule->quantity = 1;
        $rule->quantity_per_user = 1;
        $rule->date_from = date('Y-m-d H:i:s');
        $rule->date_to = date('Y-m-d H:i:s', time() + 86400);
        foreach (Language::getLanguages(false) as $l) {
            $rule->name[(int) $l['id_lang']] = 'regtest866';
        }
        neria_assert($rule->add(), 'Création du bon de test impossible');
        $ruleId = (int) $rule->id;

        $renderer = new EmailRenderer($module);
        $rm = new ReflectionMethod($renderer, 'voucherRateFromCode');
        $rm->setAccessible(true);
        $idShop = (int) $ctx->shop->id;

        $ctx->currency = new Currency($idEur);
        $text = (string) $rm->invoke($renderer, $code, 'en', $idShop);
        neria_assert(str_contains($text, '£'), "Le bon en livres n'est pas affiché avec la devise du bon : « {$text} » — régression du correctif P10");
        neria_assert(!str_contains($text, '€'), "La devise du contexte (euro) a été utilisée à la place de celle du bon : « {$text} »");

        $ctx->currency = null;
        $text2 = (string) $rm->invoke($renderer, $code, 'en', $idShop);
        neria_assert($text2 !== '' && str_contains($text2, '£'), "Contexte sans devise : « {$text2} »");
    } finally {
        $ctx->currency = $oldCurrency;
        if ($ruleId > 0) {
            $db->execute("DELETE FROM {$p}cart_rule WHERE id_cart_rule = " . $ruleId);
        }
    }

    return [
        'pass'    => true,
        'message' => "Le montant d'un bon s'affiche dans la devise du bon (et non celle du contexte), sans erreur si le contexte n'a pas de devise — corrigé le 25/09/2026 (P10)",
    ];
}
