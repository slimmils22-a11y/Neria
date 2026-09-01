<?php
/**
 * Régression : le jeton HMAC de désabonnement (`Neria::getUnsubscribeUrl()`
 * / `controllers/front/unsubscribe.php::processUnsubscribe()`) et le jeton
 * du centre de préférences (`PreferencesManager::tokenForEmail()` /
 * `controllers/front/preferences.php`) étaient signés directement avec
 * `_COOKIE_KEY_` — une constante PrestaShop régénérable par le marchand
 * (Paramètres avancés > Sécurité, ou migration/restauration qui change
 * `config/settings.inc.php`). Une rotation de cette clé invalidait alors
 * SILENCIEUSEMENT tout lien de désabonnement/préférences déjà envoyé dans
 * un email : le client cliquant sur un lien reçu avant la rotation
 * obtenait un échec sans recours ni explication, malgré une demande de
 * désabonnement légitime — risque de conformité RFC 8058/anti-spam.
 *
 * `NeriaTools::trackingSignKey()` avait déjà résolu exactement ce problème
 * pour les liens de tracking (round 155, préfère `NERIA_ENCRYPTION_KEY`,
 * propre au module et jamais affectée par une rotation PrestaShop), mais
 * ce correctif n'avait jamais été porté aux jetons de désabonnement/
 * préférences.
 *
 * Bug identifié le 01/09/2026 (round 269, audit "rotation de secrets
 * cryptographiques").
 *
 * Corrigé le 01/09/2026 (round 269) : `NeriaTools::trackingSignKey()`
 * rendue publique et réutilisée pour les 3 sites (génération dans
 * `Neria::getUnsubscribeUrl()`, vérification dans `unsubscribe.php`,
 * `PreferencesManager::tokenForEmail()` utilisée par `preferences.php`),
 * plus le self-test BO `HealthCheckManager::checkUnsubscribeUrl()` mis à
 * jour en cohérence pour éviter un faux positif.
 *
 * Test réel : génère une URL de désabonnement via
 * `Neria::getUnsubscribeUrl()`, extrait le token, et vérifie qu'il
 * correspond exactement au calcul via `NeriaTools::trackingSignKey()` —
 * PAS à un calcul basé sur `_COOKIE_KEY_` seul (qui échouerait si la clé a
 * été tournée, exactement le scénario du bug). Vérifie aussi que
 * `PreferencesManager::tokenForEmail()` produit le MÊME token pour le même
 * email (les 2 jetons doivent rester interchangeables/cohérents), et une
 * vérification structurelle des 4 sites concernés.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/NeriaTools.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/PreferencesManager.php';

    $module = neria_test_module();
    $email  = 'round269-token-test@example.com';

    $url = $module->getUnsubscribeUrl($email, 'fr');
    neria_assert($url !== '', "getUnsubscribeUrl() a renvoyé une URL vide pour un email valide — jeu de test invalide");

    $parsedQuery = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $parsedQuery);
    neria_assert(isset($parsedQuery['token']) && $parsedQuery['token'] !== '', "aucun token trouvé dans l'URL générée : {$url}");
    $actualToken = $parsedQuery['token'];

    $expectedToken = substr(hash_hmac('sha256', strtolower(trim($email)), NeriaTools::trackingSignKey()), 0, 32);
    neria_assert(
        hash_equals($expectedToken, $actualToken),
        "Neria::getUnsubscribeUrl() ne signe plus son token via NeriaTools::trackingSignKey() — régression du bug corrigé le 01/09/2026 (round 269) : une rotation de _COOKIE_KEY_ invaliderait de nouveau silencieusement tout lien de désabonnement déjà envoyé"
    );

    $prefsToken = PreferencesManager::tokenForEmail($email);
    neria_assert(
        hash_equals($expectedToken, $prefsToken),
        "PreferencesManager::tokenForEmail() ne produit plus le même token que Neria::getUnsubscribeUrl() pour le même email — les 2 jetons doivent rester cohérents (même clé de signature)"
    );

    // Vérification structurelle des 4 sites concernés.
    $neriaSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert(
        strpos($neriaSrc, "hash_hmac('sha256', \$email, \\NeriaTools::trackingSignKey())") !== false,
        "Neria::getUnsubscribeUrl() n'utilise plus NeriaTools::trackingSignKey() pour signer son token — régression du bug corrigé le 01/09/2026 (round 269)"
    );

    $unsubSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/controllers/front/unsubscribe.php');
    neria_assert(
        strpos($unsubSrc, "hash_hmac('sha256', Tools::strtolower(\$email), \\NeriaTools::trackingSignKey())") !== false,
        "unsubscribe.php::processUnsubscribe() n'utilise plus NeriaTools::trackingSignKey() pour vérifier le token — régression du bug corrigé le 01/09/2026 (round 269)"
    );

    $prefsManagerSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/PreferencesManager.php');
    neria_assert(
        strpos($prefsManagerSrc, "hash_hmac('sha256', strtolower(trim(\$email)), \\NeriaTools::trackingSignKey())") !== false,
        "PreferencesManager::tokenForEmail() n'utilise plus NeriaTools::trackingSignKey() — régression du bug corrigé le 01/09/2026 (round 269)"
    );

    $healthSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php');
    neria_assert(
        strpos($healthSrc, "hash_hmac('sha256', \$testEmail, \\NeriaTools::trackingSignKey())") !== false,
        "HealthCheckManager::checkUnsubscribeUrl() n'utilise plus NeriaTools::trackingSignKey() — régression du bug corrigé le 01/09/2026 (round 269) : ce self-test échouerait à tort (faux positif) après le correctif des 3 autres sites"
    );

    return [
        'pass'    => true,
        'message' => "les jetons de désabonnement/préférences survivent désormais à une rotation de _COOKIE_KEY_ (signés via NeriaTools::trackingSignKey(), cohérent entre les 4 sites) — bug corrigé le 01/09/2026 (round 269)",
    ];
}
