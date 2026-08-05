<?php
/**
 * Bootstrap PHPStan — définit les constantes PrestaShop utilisées partout
 * dans le module, pour que l'analyse statique ne les traite pas comme
 * indéfinies. Valeurs factices (jamais exécutées, juste typées).
 */
if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.1.0');
}
if (!defined('_PS_ROOT_DIR_')) {
    define('_PS_ROOT_DIR_', 'C:/laragon/www/shop');
}
if (!defined('_PS_MODULE_DIR_')) {
    define('_PS_MODULE_DIR_', _PS_ROOT_DIR_ . '/modules/');
}
if (!defined('_PS_BASE_URI_')) {
    define('_PS_BASE_URI_', '/');
}
if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}
if (!defined('_PS_ADMIN_DIR_')) {
    define('_PS_ADMIN_DIR_', _PS_ROOT_DIR_ . '/admin');
}
if (!defined('_THEME_DIR_')) {
    define('_THEME_DIR_', _PS_ROOT_DIR_ . '/themes/default/');
}
if (!defined('__PS_BASE_URI__')) {
    define('__PS_BASE_URI__', '/');
}
if (!defined('_COOKIE_KEY_')) {
    define('_COOKIE_KEY_', 'phpstan-fake-cookie-key');
}
if (!defined('_PS_IMG_DIR_')) {
    define('_PS_IMG_DIR_', _PS_ROOT_DIR_ . '/img/');
}
if (!defined('_PS_IMG_')) {
    define('_PS_IMG_', '/img/');
}
if (!function_exists('pSQL')) {
    function pSQL($string, $htmlOK = false)
    {
        return (string) $string;
    }
}

