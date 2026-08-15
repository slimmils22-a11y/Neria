<?php
/**
 * Régression : EmailRenderer::voucherRateFromCode() recherchait le
 * cart_rule correspondant à un code SANS filtrer par boutique — PrestaShop
 * n'impose pas l'unicité globale d'un code de bon entre boutiques d'une
 * même install multi-shop. Deux bons identiques (même code) créés
 * indépendamment sur 2 boutiques avec des taux de réduction différents
 * faisaient calculer {discount} à partir du PREMIER cart_rule trouvé en
 * base, potentiellement celui d'une AUTRE boutique que celle du
 * destinataire — le code {voucher_code} restait correct, seul le
 * taux/montant affiché dans l'intro de l'email était faux.
 *
 * Corrigé le 15/08/2026 (round 176) : la requête préfère désormais le
 * cart_rule associé à $idShop (nouveau 3e paramètre de la méthode, threadé
 * depuis fixNewsletterVoucherVars() jusqu'à
 * applyNeriaRendering()/resolveShopId()) — SANS exclure les cart_rule non
 * restreints (cart_rule_shop ne contient une ligne QUE pour les cart_rule
 * explicitement restreints à des boutiques ; la grande majorité des bons
 * réels n'y ont AUCUNE ligne). Un premier essai en INNER JOIN aurait exclu
 * à tort la quasi-totalité des bons réels — corrigé en LEFT JOIN + repli
 * explicite sur "non restreint" avant tout commit (voir le test
 * ci-dessous, qui aurait échoué sur ce premier essai).
 *
 * Test : comportemental réel (un vrai CartRule NON restreint — le cas
 * réel le plus courant — doit toujours être retrouvé) + structurel (le
 * critère de préférence id_shop reste bien présent dans la requête SQL —
 * une vraie collision de code entre 2 boutiques distinctes n'est pas
 * reproductible de façon fiable dans cette suite mono-boutique).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';

    $code = 'REGTEST347-' . strtoupper(substr(uniqid(), -8));
    $cartRule = new CartRule();
    $langs = Language::getLanguages(false);
    $names = [];
    foreach ($langs as $l) {
        $names[(int) $l['id_lang']] = $code;
    }
    $cartRule->name              = $names;
    $cartRule->code              = $code;
    $cartRule->quantity          = 1;
    $cartRule->quantity_per_user = 1;
    $cartRule->active            = 1;
    $cartRule->date_from         = date('Y-m-d H:i:s');
    $cartRule->date_to           = date('Y-m-d H:i:s', strtotime('+30 days'));
    $cartRule->reduction_percent = 15.0;
    $cartRule->reduction_amount  = 0;
    $ok = $cartRule->add();
    neria_assert($ok && $cartRule->id > 0, "jeu de test invalide : la création du CartRule de test a échoué");

    try {
        $renderer = new EmailRenderer(neria_test_module());
        $ref = new ReflectionMethod(EmailRenderer::class, 'voucherRateFromCode');
        $ref->setAccessible(true);

        $idShop = (int) Context::getContext()->shop->id;
        $rate   = $ref->invoke($renderer, $code, 'fr', $idShop);

        neria_assert(
            $rate !== '' && strpos((string) $rate, '15') !== false,
            "voucherRateFromCode('{$code}', 'fr', {$idShop}) ne retrouve plus un cart_rule NON restreint (le cas le plus courant en pratique — aucune ligne cart_rule_shop) — régression : un INNER JOIN sur cart_rule_shop exclurait à tort la quasi-totalité des bons réels. Obtenu : " . var_export($rate, true)
        );

        // Un id_shop différent doit AUSSI retrouver ce même cart_rule NON
        // restreint (disponible sur toutes les boutiques) — ce n'est que
        // face à un DOUBLON explicitement restreint qu'une préférence de
        // boutique s'applique.
        $rateOtherShop = $ref->invoke($renderer, $code, 'fr', 999994);
        neria_assert(
            $rateOtherShop !== '',
            "voucherRateFromCode() ne retrouve plus un cart_rule NON restreint depuis une autre boutique (999994) — un bon disponible sur toutes les boutiques ne devrait jamais être exclu par ce filtre"
        );
    } finally {
        $cartRule->delete();
    }

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php');
    neria_assert($src !== false, 'Impossible de lire src/EmailRenderer.php');
    neria_assert(
        strpos($src, 'NOT EXISTS') !== false && strpos($src, "crs.`id_shop` = ' . \$idShop") !== false,
        "voucherRateFromCode() ne préfère plus le cart_rule associé à \$idShop tout en acceptant les cart_rule non restreints (NOT EXISTS sur cart_rule_shop) — régression du bug corrigé le 15/08/2026 (round 176)"
    );

    return [
        'pass'    => true,
        'message' => "EmailRenderer::voucherRateFromCode() préfère bien le cart_rule associé à id_shop sans exclure les bons non restreints (cas le plus courant) — bug corrigé le 15/08/2026 (round 176)",
    ];
}
