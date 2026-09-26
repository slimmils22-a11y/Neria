<?php
/**
 * Régression (P8c, 26/09/2026, constatée dans le vrai back-office de ps-test) : le bouton « Ouvrir » de la page d'urgence
 * (neria-emergency.php, protégée par jeton, conçue pour fonctionner même si PrestaShop est en panne) renvoyait HTTP 404 : le
 * modules/.htaccess de PrestaShop bloque tous les fichiers .php et le .htaccess de Neria ne levait le blocage que pour
 * getpreview.php. La page d'urgence n'était donc jamais accessible sur un Apache standard.
 *
 * Corrigé : la règle FilesMatch autorise aussi neria-emergency.php (accès toujours protégé par le jeton). Vérifié sur ps-test :
 * jeton valide -> page du journal ; jeton absent ou faux -> « Access denied ».
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    neria_test_module();
    $ht = str_replace("\r\n", "\n", (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/.htaccess'));
    neria_assert(str_contains($ht, '<FilesMatch "^(getpreview|neria-emergency)\.php$">'), "Le .htaccess de Neria n'autorise plus neria-emergency.php (HTTP 404 sur Apache)");
    neria_assert(is_file(_PS_MODULE_DIR_ . 'neria/neria-emergency.php'), 'neria-emergency.php absent du module');
    $emergency = (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/neria-emergency.php');
    neria_assert(str_contains($emergency, 'hash_equals'), "La page d'urgence n'est plus protégée par un jeton comparé en temps constant");

    return ['pass' => true, 'message' => "La page d'urgence est accessible (jeton requis) malgré le blocage des .php de modules/ — corrigé le 26/09/2026"];
}
