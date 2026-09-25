<?php
/**
 * Régression (P13/matrice FC-004/FC-005, 25/09/2026, constatée sur ps-test / PrestaShop 9) : les callbacks OAuth
 * (Google Postmaster : controllers/front/oauth.php ; Search Console : oauthsc.php) répondaient TOUJOURS HTTP 500 :
 * Tools::redirectAdmin() appelle sanitizeAdminUrl(), qui exige la constante _PS_ADMIN_DIR_ absente hors back-office.
 * La connexion à Postmaster / Search Console ne pouvait donc jamais aboutir.
 *
 * Corrigé : redirectBack() (redirection directe vers l'URL de retour enregistrée avec le state, validée en http(s) ;
 * sinon accueil de la boutique). Comportement réel vérifié sur ps-test (302 vers l'URL de retour avec le message).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    neria_test_module();
    foreach (['oauth', 'oauthsc'] as $c) {
        $src = str_replace("\r\n", "\n", (string) file_get_contents(_PS_MODULE_DIR_ . "neria/controllers/front/{$c}.php"));
        neria_assert(!str_contains($src, 'redirectAdmin('), "{$c}.php rappelle Tools::redirectAdmin() (erreur fatale hors back-office sur PrestaShop 9)");
        neria_assert(substr_count($src, '$this->redirectBack(') >= 4, "{$c}.php n'utilise plus redirectBack() pour ses redirections");
        neria_assert(str_contains($src, "private function redirectBack(string \$url): void"), "{$c}.php : redirectBack() absent");
        neria_assert(str_contains($src, "preg_match('#^https?://#i', \$url) === 1"), "{$c}.php : l'URL de retour n'est plus limitée à http(s)");
    }

    return ['pass' => true, 'message' => "Les callbacks OAuth Postmaster/Search Console n'utilisent plus Tools::redirectAdmin() (HTTP 500 permanent sur PrestaShop 9) — corrigé le 25/09/2026"];
}
