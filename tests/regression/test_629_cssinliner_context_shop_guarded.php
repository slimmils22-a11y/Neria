<?php
/**
 * Régression : CssInliner::inline() (bloc catch, filet de sécurité du
 * compteur d'échecs silencieux) lisait `(int) \Context::getContext()->shop
 * ->id` sans aucune protection — contrairement à NeriaErrorHandler::
 * currentShopId(), qui protège le même besoin (résoudre l'id_shop en
 * contexte dégradé) via un try/catch avec repli explicite.
 *
 * En contexte dégradé (Context::getContext()->shop non initialisé, par ex.
 * un cron ne l'ayant pas encore réassigné au moment précis où l'exception
 * DOMDocument est levée), l'accès direct produit $idShop=0 silencieusement
 * (PHP 8 : lire ->id sur null renvoie null, (int)null=0) — le compteur
 * d'échecs silencieux se retrouve scopé sur une "boutique 0" fictive,
 * invisible dans le Health Check de la vraie boutique concernée par
 * l'échec d'inlining.
 *
 * Corrigé le 08/09/2026 (round 320) : accès protégé par try/catch avec
 * repli sur idShop=1, même motif que NeriaErrorHandler::currentShopId().
 *
 * Vérification structurelle ciblée : confirme que le bloc catch de
 * CssInliner::inline() protège désormais l'accès au contexte boutique.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CssInliner.php');
    neria_assert($src !== false, 'Impossible de lire src/CssInliner.php');

    $posInline = strpos($src, 'public static function inline(string $html): string');
    neria_assert($posInline !== false, 'CssInliner::inline() introuvable — jeu de test invalide');

    $body = substr($src, $posInline, 2300);

    neria_assert(
        strpos($body, "\$idShop = 1;") !== false
        && strpos($body, 'try {') !== false
        && strpos($body, 'catch (\Throwable $ctxErr)') !== false,
        "CssInliner::inline() n'entoure plus l'accès à Context::getContext()->shop->id d'un try/catch avec repli sur idShop=1 — régression du bug corrigé le 08/09/2026 (round 320) : un contexte boutique non initialisé scoperait de nouveau le compteur d'échecs silencieux sur une boutique 0 fictive, invisible dans le Health Check"
    );

    return [
        'pass'    => true,
        'message' => "CssInliner::inline() protège désormais l'accès au contexte boutique dans son filet de sécurité (try/catch, repli sur idShop=1) — bug corrigé le 08/09/2026 (round 320)",
    ];
}
