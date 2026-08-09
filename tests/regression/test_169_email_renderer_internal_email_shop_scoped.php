<?php
/**
 * Régression : EmailRenderer::isInternalEmail() doit résoudre
 * PS_SHOP_EMAIL via l'idShop explicite du destinataire (comme la requête
 * employee_shop juste en dessous), pas via le contexte d'exécution ambiant.
 *
 * Bug réel corrigé le 08/08/2026 (round 138) : $idShop était calculé APRÈS
 * la comparaison PS_SHOP_EMAIL, qui utilisait donc Configuration::get()
 * sans id_shop explicite — incohérent avec la requête employee_shop juste
 * en dessous, déjà scopée. Sur une install multi-boutiques où le contexte
 * reste figé sur la boutique A pendant le traitement d'un email pour la
 * boutique B, PS_SHOP_EMAIL de A était comparée à tort à l'adresse du
 * destinataire de B.
 *
 * Test comportemental réel : deux boutiques avec des PS_SHOP_EMAIL
 * différentes, vérifie qu'isInternalEmail() résout bien l'email de LA
 * BOUTIQUE demandée via $params['idShop'], pas celle du contexte ambiant.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';

    $shops = Db::getInstance()->executeS('SELECT id_shop FROM `' . _DB_PREFIX_ . 'shop` WHERE active = 1 ORDER BY id_shop ASC');
    neria_assert(is_array($shops) && count($shops) >= 1, 'Aucune boutique active trouvée pour le test');

    $idShopA = (int) $shops[0]['id_shop'];
    $idShopB = count($shops) > 1 ? (int) $shops[1]['id_shop'] : $idShopA;

    $originalEmailA = (string) Configuration::get('PS_SHOP_EMAIL', null, null, $idShopA);
    $originalEmailB = (string) Configuration::get('PS_SHOP_EMAIL', null, null, $idShopB);
    $originalContext = Shop::getContextShopID(true);

    try {
        Configuration::updateValue('PS_SHOP_EMAIL', 'shop-a-round138@example.com', false, null, $idShopA);
        if ($idShopB !== $idShopA) {
            Configuration::updateValue('PS_SHOP_EMAIL', 'shop-b-round138@example.com', false, null, $idShopB);
        }

        // Contexte ambiant reste sur A, mais on demande explicitement
        // l'email interne pour la boutique B via $params['idShop'].
        Context::getContext()->shop = new Shop($idShopA);

        $renderer = new EmailRenderer(neria_test_module());
        $method = new ReflectionMethod(EmailRenderer::class, 'isInternalEmail');
        $method->setAccessible(true);

        if ($idShopB !== $idShopA) {
            $resultA = $method->invoke($renderer, ['to' => 'shop-a-round138@example.com', 'idShop' => $idShopA]);
            neria_assert($resultA === true, "isInternalEmail() n'a pas détecté l'email interne de la boutique A — jeu de test invalide");

            $resultCrossed = $method->invoke($renderer, ['to' => 'shop-a-round138@example.com', 'idShop' => $idShopB]);
            neria_assert(
                $resultCrossed === false,
                "isInternalEmail() classe l'email de la boutique A comme interne à la boutique B — régression du bug corrigé le 08/08/2026 (round 138) : PS_SHOP_EMAIL redeviendrait résolu via le contexte ambiant au lieu de \$params['idShop']"
            );

            $resultB = $method->invoke($renderer, ['to' => 'shop-b-round138@example.com', 'idShop' => $idShopB]);
            neria_assert(
                $resultB === true,
                "isInternalEmail() ne détecte plus l'email interne de la boutique B via son idShop explicite — régression du bug corrigé le 08/08/2026 (round 138)"
            );
        } else {
            $result = $method->invoke($renderer, ['to' => 'shop-a-round138@example.com', 'idShop' => $idShopA]);
            neria_assert($result === true, "isInternalEmail() n'a pas résolu l'email interne attendu sur boutique unique");
        }
    } finally {
        Configuration::updateValue('PS_SHOP_EMAIL', $originalEmailA, false, null, $idShopA);
        if ($idShopB !== $idShopA) {
            Configuration::updateValue('PS_SHOP_EMAIL', $originalEmailB, false, null, $idShopB);
        }
        Context::getContext()->shop = new Shop($originalContext);
    }

    return [
        'pass'    => true,
        'message' => "EmailRenderer::isInternalEmail() résout bien PS_SHOP_EMAIL via l'idShop explicite du destinataire, cohérent avec la requête employee_shop juste en dessous",
    ];
}
