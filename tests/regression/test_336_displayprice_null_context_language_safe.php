<?php
/**
 * Régression : NeriaTools::displayPrice() émettait un warning PHP 8
 * "Attempt to read property 'id' on null" quand $context->language est
 * null (cas cron/CLI sans contexte langue complet) et qu'un $idLang
 * explicite est fourni — précisément le scénario que ce paramètre est
 * censé couvrir (cron d'envoi programmé, sans contexte front complet).
 *
 * Bug réel corrigé le 15/08/2026 (round 173) :
 * `(int) $lang->id !== (int) ($context->language->id ?? 0)` — le `??`
 * protège seulement le résultat final si `$context->language->id` lève une
 * erreur de type "undefined", mais PHP évalue D'ABORD `$context->language->id`
 * en accédant à la propriété ->id sur un objet potentiellement null AVANT
 * d'appliquer le ??, émettant le warning à chaque appel malgré la valeur de
 * repli 0 correctement récupérée ensuite. Pollution des logs PHP à chaque
 * envoi cron avec $idLang explicite (MonthlyReportManager,
 * BehavioralCronManager, etc.).
 *
 * Test comportemental réel : force $context->language à null, appelle
 * displayPrice() avec un $idLang valide, vérifie qu'AUCUN warning PHP n'est
 * émis (capturé via un gestionnaire d'erreurs dédié) et que le résultat
 * reste un prix formaté cohérent.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/NeriaTools.php';

    $context = \Context::getContext();
    $originalLanguage = $context->language;
    $context->language = null;

    $langs = \Language::getLanguages(true);
    neria_assert(!empty($langs), 'Aucune langue active trouvée — jeu de test invalide');
    $idLang = (int) $langs[0]['id_lang'];

    $currency = new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT'));

    $warnings = [];
    set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
        $warnings[] = $errstr;
        return true;
    }, E_WARNING | E_NOTICE | E_DEPRECATED);

    try {
        $result = NeriaTools::displayPrice(19.90, $currency, $idLang);
    } finally {
        restore_error_handler();
        $context->language = $originalLanguage;
    }

    $propertyWarnings = array_filter($warnings, static function (string $w): bool {
        return stripos($w, 'null') !== false || stripos($w, 'property') !== false;
    });

    neria_assert(
        empty($propertyWarnings),
        "NeriaTools::displayPrice() émet un warning PHP en accédant à \$context->language->id alors que \$context->language est null — régression du bug corrigé le 15/08/2026 (round 173). Warnings capturés : " . implode(' | ', $propertyWarnings)
    );

    neria_assert(
        is_string($result) && $result !== '',
        "NeriaTools::displayPrice() ne renvoie plus un prix formaté valide quand \$context->language est null (obtenu : " . var_export($result, true) . ')'
    );

    return [
        'pass'    => true,
        'message' => "NeriaTools::displayPrice() n'accède plus à une propriété sur \$context->language potentiellement null sans protection — bug corrigé le 15/08/2026 (round 173)",
    ];
}
