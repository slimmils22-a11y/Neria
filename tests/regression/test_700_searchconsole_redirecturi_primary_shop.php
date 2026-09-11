<?php
/**
 * Régression : `SearchConsoleManager::getRedirectUri()` résolvait le
 * domaine via `Tools::getShopDomainSsl(true)`, qui reflète la boutique du
 * CONTEXTE BO courant — incohérent avec le caractère volontairement
 * GLOBAL de cette connexion OAuth (round 185 : une seule connexion Search
 * Console pour toute l'installation, jamais scopée par boutique). Sur une
 * installation multi-boutiques à domaines différents, un client OAuth
 * Google enregistré pour le domaine de la boutique principale rejetait la
 * connexion ("redirect_uri_mismatch") dès que l'admin cliquait "Connecter"
 * depuis le contexte d'une AUTRE boutique — sans message d'erreur
 * exploitable côté Neria (l'écran d'erreur Google générique ne mentionne
 * pas Neria).
 *
 * Bug identifié le 11/09/2026 (round 338, audit SeasonalCampaignManager/
 * SearchConsoleManager), corrigé le 12/09/2026 hors round (à la demande
 * de l'utilisateur, option A retenue après discussion des deux options) :
 * `getRedirectUri()` résout désormais le domaine via
 * `ShopUrl::getMainShopDomainSSL(1)` (boutique #1, toujours présente,
 * créée à l'installation de PrestaShop) — stable quel que soit le
 * contexte BO d'où l'admin initie la connexion.
 *
 * Test structurel + comportemental réel : vérifie que le code utilise
 * bien `ShopUrl::getMainShopDomainSSL(1)` (id explicite) et non plus
 * `Tools::getShopDomainSsl(true)` comme chemin primaire ; puis vérifie
 * que l'URI retournée correspond exactement à ce que produirait
 * `ShopUrl::getMainShopDomainSSL(1)` + le protocole résolu depuis
 * PS_SSL_ENABLED, indépendamment du contexte boutique ambiant.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    // ── Vérification structurelle du correctif ────────────────────────
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SearchConsoleManager.php');
    neria_assert($src !== false, 'Impossible de lire src/SearchConsoleManager.php');

    $posFn = strpos($src, 'public function getRedirectUri(): string');
    neria_assert($posFn !== false, 'getRedirectUri() introuvable — jeu de test invalide');
    $body = substr($src, $posFn, 1400);

    neria_assert(
        strpos($body, '\ShopUrl::getMainShopDomainSSL(1)') !== false,
        "getRedirectUri() n'utilise plus ShopUrl::getMainShopDomainSSL(1) (boutique canonique) — régression du correctif du 12/09/2026 (hors round, suite round 338) : le redirect_uri OAuth redeviendrait dépendant du contexte BO courant au lieu d'être stable pour cette connexion volontairement globale"
    );
    neria_assert(
        strpos($body, '$domain = \ShopUrl::getMainShopDomainSSL(1);') !== false,
        "getRedirectUri() ne capture plus le domaine canonique dans une variable dédiée en tête de méthode — régression du correctif du 12/09/2026 (hors round, suite round 338)"
    );

    // ── Vérification comportementale du chemin réel ───────────────────
    require_once _PS_MODULE_DIR_ . 'neria/src/SearchConsoleManager.php';

    $module = neria_test_module();
    $mgr    = new SearchConsoleManager($module);

    $expectedDomain = ShopUrl::getMainShopDomainSSL(1);
    neria_assert($expectedDomain !== '' && $expectedDomain !== null, "ShopUrl::getMainShopDomainSSL(1) ne renvoie rien — jeu de test invalide (boutique #1 absente ?)");
    $expectedProtocol = Tools::getProtocol((bool) Configuration::get('PS_SSL_ENABLED'));
    $expectedUri = $expectedProtocol . $expectedDomain . __PS_BASE_URI__ . 'index.php?fc=module&module=neria&controller=oauthsc';

    $actualUri = $mgr->getRedirectUri();
    neria_assert(
        $actualUri === $expectedUri,
        "getRedirectUri() renvoie '{$actualUri}', attendu '{$expectedUri}' (domaine de la boutique #1, pas celui du contexte BO courant) — régression du correctif du 12/09/2026 (hors round, suite round 338)"
    );

    return [
        'pass'    => true,
        'message' => "SearchConsoleManager::getRedirectUri() résout désormais le domaine de la boutique #1 (canonique) via ShopUrl::getMainShopDomainSSL(1), stable quel que soit le contexte BO d'où l'admin initie la connexion OAuth — correctif du 12/09/2026 (hors round, suite round 338)",
    ];
}
