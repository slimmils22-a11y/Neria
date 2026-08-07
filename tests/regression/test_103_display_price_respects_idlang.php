<?php
/**
 * Régression : NeriaTools::displayPrice($amount, $currency, $idLang) doit
 * réellement formater le montant selon $idLang, pas selon la langue du
 * contexte d'exécution courant (cron/BO de l'employé) — appelée avec
 * $idLang par MonthlyReportManager (rapport mensuel par destinataire),
 * CollectionManager ({missing_price}) et LookCompletionManager
 * ({productN_price}), toutes trois croyant obtenir un prix dans la langue
 * du CLIENT.
 *
 * Bug réel corrigé le 07/08/2026 (round 99) : displayPrice() réaffectait
 * $context->language avant d'appeler \Tools::displayPrice() nativement.
 * Mais Tools::displayPrice() (cœur PrestaShop) délègue à
 * Tools::getContextLocale(), qui retourne $context->getCurrentLocale() —
 * un objet Locale calculé UNE SEULE FOIS par Controller::init() (jamais en
 * CLI/cron) et JAMAIS recalculé quand $context->language est réaffecté en
 * cours de script. Le switch de langue était donc un no-op silencieux : un
 * rapport mensuel envoyé le même run à un destinataire FR et un
 * destinataire EN recevait le MÊME formatage de prix (celui de la langue du
 * cron/BO), pas celui de chaque destinataire — incohérence visible
 * directement dans l'email (ex. "1 234,56 €" envoyé à un client anglophone
 * au lieu de "€1,234.56").
 *
 * Vérifié empiriquement contre le cœur PrestaShop (classes/Tools.php::
 * getContextLocale(), classes/Context.php::getCurrentLocale()) avant
 * correction : currentLocale n'est jamais synchronisé avec $context->language
 * réaffecté manuellement.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/NeriaTools.php';

    $eur = new Currency(1);
    $idLangFr = (int) Language::getIdByIso('fr');
    $idLangEn = (int) Language::getIdByIso('en');

    if ($idLangFr <= 0 || $idLangEn <= 0) {
        return ['pass' => true, 'message' => 'Langues fr/en absentes de cette install de test — test ignoré (rien à vérifier)'];
    }

    // Contexte volontairement dans une 3e langue (DE si disponible, sinon la
    // langue par défaut) : reproduit le cas réel où le cron/BO déclencheur
    // n'est ni fr ni en, pour prouver que le résultat suit bien $idLang et
    // pas le contexte.
    $context = Context::getContext();
    $originalLang = $context->language;
    $idLangDe = (int) Language::getIdByIso('de');
    if ($idLangDe > 0 && $idLangDe !== $idLangFr && $idLangDe !== $idLangEn) {
        $context->language = new Language($idLangDe);
    }

    try {
        $priceFr = NeriaTools::displayPrice(1234.56, $eur, $idLangFr);
        $priceEn = NeriaTools::displayPrice(1234.56, $eur, $idLangEn);

        neria_assert(
            $priceFr !== $priceEn,
            "NeriaTools::displayPrice() produit le MÊME formatage pour \$idLang français et anglais (obtenu '{$priceFr}' pour les deux) — régression du bug corrigé le 07/08/2026 (round 99) : le switch de langue serait de nouveau un no-op silencieux, le prix étant formaté selon le contexte du cron/BO plutôt que selon chaque destinataire"
        );
        neria_assert(
            strpos($priceFr, ',') !== false,
            "le format FR attendu (virgule décimale) n'apparaît pas dans '{$priceFr}' — le prix n'est pas réellement formaté en français"
        );
        neria_assert(
            strpos($priceEn, '.') !== false,
            "le format EN attendu (point décimal) n'apparaît pas dans '{$priceEn}' — le prix n'est pas réellement formaté en anglais"
        );

        return [
            'pass'    => true,
            'message' => "NeriaTools::displayPrice() formate bien le prix selon \$idLang demandé (FR='{$priceFr}', EN='{$priceEn}'), indépendamment de la langue du contexte d'exécution",
        ];
    } finally {
        $context->language = $originalLang;
    }
}
