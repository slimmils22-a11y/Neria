<?php
/**
 * Régression : le chemin de secours fallback_subject/{shop_name} dans
 * EmailRenderer doit résoudre PS_SHOP_NAME via resolveShopId($params),
 * pas via le contexte d'exécution ambiant.
 *
 * Bug réel corrigé le 08/08/2026 (round 138) : $subject (repli sur
 * PS_SHOP_NAME) et {shop_name} du chemin fallback (email client-facing,
 * pas un aperçu BO) utilisaient Configuration::get() sans id_shop
 * explicite, incohérent avec le reste de la méthode.
 *
 * Test structurel : vérifie que le chemin fallback résout bien
 * PS_SHOP_NAME avec l'idShop explicite ($idShopFallback, calculé via
 * resolveShopId($params)).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php');
    neria_assert($src !== false, 'Impossible de lire src/EmailRenderer.php');

    neria_assert(
        strpos($src, '$idShopFallback = $this->resolveShopId($params);') !== false,
        "Le chemin fallback d'EmailRenderer ne calcule plus \$idShopFallback via resolveShopId(\$params) — régression du bug corrigé le 08/08/2026 (round 138)"
    );
    neria_assert(
        strpos($src, "\\Configuration::get('PS_SHOP_NAME', null, null, \$idShopFallback)") !== false,
        "Le chemin fallback d'EmailRenderer ne résout plus PS_SHOP_NAME avec l'idShop explicite — régression du bug corrigé le 08/08/2026 (round 138) : le sujet/nom de boutique d'un email de secours pourrait de nouveau refléter la mauvaise boutique"
    );

    return [
        'pass'    => true,
        'message' => "Le chemin fallback d'EmailRenderer résout bien PS_SHOP_NAME via l'idShop explicite du destinataire (resolveShopId(\$params))",
    ];
}
