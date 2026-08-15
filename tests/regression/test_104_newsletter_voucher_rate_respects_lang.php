<?php
/**
 * Régression : EmailRenderer::voucherRateFromCode() (et
 * fixNewsletterVoucherVars()) doivent formater le taux/montant du bon
 * ({discount}) selon la langue du DESTINATAIRE ($lang, résolue par
 * resolveEmailLang()), pas selon $context->language (contexte
 * d'exécution du process : cron/BO).
 *
 * Bug réel corrigé le 07/08/2026 (round 100) : voucherRateFromCode()
 * lisait directement Context::getContext()->language (branche pourcentage)
 * et Tools::getContextLocale() (branche montant) — même piège de "locale
 * figée du contexte d'exécution" que NeriaTools::displayPrice() corrigé au
 * round 99, mais ici réintroduit en contournant complètement ce correctif :
 * aucune langue n'était transmise depuis applyNeriaRendering() (qui a bien
 * $lang correctement résolu) jusqu'à fixNewsletterVoucherVars()/
 * voucherRateFromCode(). Un abonné newsletter dont la langue résolue par
 * Neria (auto-détection géo, ou envoi via BO/cron dans une autre langue)
 * diffère du contexte recevait {discount} formaté dans la MAUVAISE langue
 * ("12,5 %" au lieu de "12.5 %"), incohérent avec le reste de l'email.
 *
 * Test comportemental réel : vrai CartRule à 12,5% de réduction, vérifie
 * que voucherRateFromCode() retourne bien "12,5 %" pour $lang='fr' et
 * "12.5 %" pour $lang='en', indépendamment du contexte.
 *
 * Mis à jour le 15/08/2026 (round 176) : voucherRateFromCode() prend
 * désormais un 3e paramètre $idShop (correctif du scoping multi-boutique,
 * voir test_347) — la signature a changé, l'appel ci-dessous est ajusté en
 * conséquence sans changer l'objet de CE test (le formatage par langue).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';

    $idLangFr = (int) Language::getIdByIso('fr');
    $idLangEn = (int) Language::getIdByIso('en');
    if ($idLangFr <= 0 || $idLangEn <= 0) {
        return ['pass' => true, 'message' => 'Langues fr/en absentes de cette install de test — test ignoré (rien à vérifier)'];
    }

    $code = 'REGTEST104-' . strtoupper(substr(uniqid(), -8));
    $cartRule = new CartRule();
    $langs = Language::getLanguages(false);
    $names = [];
    foreach ($langs as $l) {
        $names[(int) $l['id_lang']] = $code;
    }
    $cartRule->name                    = $names;
    $cartRule->code                    = $code;
    $cartRule->quantity                = 1;
    $cartRule->quantity_per_user       = 1;
    $cartRule->active                  = 1;
    $cartRule->date_from               = date('Y-m-d H:i:s');
    $cartRule->date_to                 = date('Y-m-d H:i:s', strtotime('+30 days'));
    $cartRule->reduction_percent       = 12.5;
    $cartRule->reduction_amount        = 0;
    $cartRule->minimum_amount_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');

    $ok = $cartRule->add();
    neria_assert($ok && $cartRule->id > 0, "jeu de test invalide : la création du CartRule de test a échoué");

    try {
        $renderer = new EmailRenderer(neria_test_module());
        $ref = new ReflectionMethod(EmailRenderer::class, 'voucherRateFromCode');
        $ref->setAccessible(true);

        $idShop = (int) Context::getContext()->shop->id;
        $rateFr = $ref->invoke($renderer, $code, 'fr', $idShop);
        $rateEn = $ref->invoke($renderer, $code, 'en', $idShop);

        neria_assert(
            $rateFr !== $rateEn,
            "voucherRateFromCode() produit le MÊME formatage pour \$lang='fr' et 'en' (obtenu '{$rateFr}' pour les deux) — régression du bug corrigé le 07/08/2026 (round 100) : {discount} suivrait de nouveau silencieusement la langue du contexte d'exécution au lieu de celle du destinataire"
        );
        neria_assert(
            strpos($rateFr, ',') !== false,
            "le format FR attendu (virgule décimale) n'apparaît pas dans '{$rateFr}'"
        );
        neria_assert(
            strpos($rateEn, '.') !== false,
            "le format EN attendu (point décimal) n'apparaît pas dans '{$rateEn}'"
        );

        return [
            'pass'    => true,
            'message' => "EmailRenderer::voucherRateFromCode() formate bien {discount} selon la langue du destinataire (FR='{$rateFr}', EN='{$rateEn}')",
        ];
    } finally {
        $cartRule->delete();
    }
}
