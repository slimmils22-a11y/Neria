<?php
/**
 * Régression : NeriaTools::formatPriceWithIntl() doit mapper les iso_code
 * internes PS 'gb'/'br'/'tw' vers de vrais identifiants de locale ICU
 * ('en-GB'/'pt-BR'/'zh-TW') quand le champ `locale` de ps_lang est vide.
 *
 * Bug réel corrigé le 02/08/2026 (commit af86c15) : ces iso_code ne sont
 * pas des identifiants de locale ICU valides — NumberFormatter('gb', ...)
 * construisait une locale non standard et retombait sur des règles proches
 * de en-US/français (virgule décimale), produisant un prix mal formaté
 * pour ces langues.
 *
 * Ce test appelle directement formatPriceWithIntl() (extraite en méthode
 * publique testable le 02/08/2026, commit à suivre) plutôt que
 * displayPrice() : sur toute version PS actuelle (8, 9),
 * Tools::displayPrice() existe encore et court-circuite displayPrice()
 * avant d'atteindre ce code — le seul moyen de le vérifier en conditions
 * réelles aujourd'hui est d'appeler le chemin de repli en isolation.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/NeriaTools.php';

    if (!class_exists('NumberFormatter')) {
        return ['pass' => true, 'message' => 'Extension intl absente sur cet environnement — test ignoré (le repli manuel sans intl est un cas différent, déjà correct)'];
    }

    $currency = Currency::getDefaultCurrency();
    $failures = [];

    // Cas testés : iso_code interne PS -> attendu un séparateur décimal '.'
    // (style anglo-saxon en-GB/zh-TW) ou ' ' (style pt-BR usuel), PAS une
    // virgule française — signe que le mapping ICU a bien été appliqué.
    $cases = [
        'gb' => ['expect_no_comma_decimal' => true],
        'tw' => ['expect_no_comma_decimal' => true],
    ];

    $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
    $refIso    = new ReflectionProperty($lang, 'iso_code');
    $refLocale = new ReflectionProperty($lang, 'locale');
    $refIso->setAccessible(true);
    $refLocale->setAccessible(true);
    $originalIso    = $lang->iso_code;
    $originalLocale = $lang->locale;

    try {
        foreach ($cases as $iso => $expect) {
            $refIso->setValue($lang, $iso);
            $refLocale->setValue($lang, ''); // locale vide en base — force le repli sur iso_code

            $formatted = NeriaTools::formatPriceWithIntl(89.90, $currency, $lang);

            if ($formatted === null) {
                $failures[] = "{$iso}: formatPriceWithIntl() a retourné null";
                continue;
            }

            // Une virgule décimale ("89,90") signale que le mapping n'a pas
            // été appliqué (repli sur des règles proches du français/en-US
            // par défaut d'ICU face à un code 'gb'/'tw' non reconnu).
            $hasCommaDecimal = (bool) preg_match('/\d,\d{2}\b/', $formatted);
            if ($hasCommaDecimal) {
                $failures[] = "{$iso}: format='{$formatted}' — virgule décimale détectée, le mapping iso_code→locale ICU ne semble plus appliqué";
            }
        }

        neria_assert(
            empty($failures),
            implode(' | ', $failures) . ' — régression du bug de locale ICU corrigé le 02/08/2026 (commit af86c15)'
        );

        return ['pass' => true, 'message' => "formatPriceWithIntl() applique toujours la table de correspondance iso_code→locale ICU (gb→en-GB, tw→zh-TW)"];
    } finally {
        $refIso->setValue($lang, $originalIso);
        $refLocale->setValue($lang, $originalLocale);
    }
}
