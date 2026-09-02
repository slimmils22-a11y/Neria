<?php
/**
 * Régression : `LicenseManager` journalisait la révocation confirmée par
 * le serveur ET l'alerte d'expiration proactive (15 jours avant échéance)
 * via `wd()->warning()` — un niveau qui ne déclenche JAMAIS
 * `sendImmediateAlert()` (contrairement à `error()`/`critical()`, cf.
 * `WatchdogManager::warning()` vs `error()`). Une révocation démarre
 * pourtant un compte à rebours de seulement `GRACE_REVOKED_DAYS` (7 jours)
 * avant l'arrêt COMPLET de tous les envois email du module — sans alerte
 * immédiate, seul le digest quotidien (opt-in, désactivé par défaut) ou
 * une consultation manuelle du BO pouvait révéler l'incident. Le marchand
 * découvrait alors l'extinction totale des envois seulement après coup,
 * sans avoir été prévenu à temps pour agir dans le délai de grâce — un
 * impact bien plus grave qu'un simple check SEO/PageSpeed en échec,
 * pourtant déjà correctement escaladé en `error()` dans ces managers
 * (`PageSpeedManager::fetchStrategy()` 403 -> `error()`, round 171).
 *
 * Bug identifié le 01/09/2026 (round 276, audit "expiration silencieuse
 * de clés API tierces").
 *
 * Corrigé le 01/09/2026 (round 276) : les deux appels passés en `error()`,
 * déclenchant désormais `sendImmediateAlert()`.
 *
 * Test structurel : reproduire un vrai cycle révocation/expiration
 * nécessiterait de simuler une réponse serveur de licence complète, hors
 * périmètre sûr d'un test isolé (secret partagé, signature, état global
 * `CONFIG_REVOKED_AT`/`CONFIG_EXPIRY_WARNED_FOR` persistant). Vérifie que
 * le code source utilise bien `error()` (pas `warning()`) pour ces 2
 * événements précis.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/LicenseManager.php');
    neria_assert($src !== false, 'Impossible de lire src/LicenseManager.php');

    $posRevoked = strpos($src, "\\Configuration::updateGlobalValue(self::CONFIG_REVOKED_AT, time());");
    neria_assert($posRevoked !== false, "le bloc de détection de révocation est introuvable — jeu de test invalide");
    $revokedBody = substr($src, $posRevoked, 1200);
    neria_assert(
        strpos($revokedBody, '$this->wd()->error(') !== false
            && strpos($revokedBody, "\\WatchdogManager::i18nMsg('watchdog.license_revoked')") !== false,
        "LicenseManager ne journalise plus une révocation confirmée via wd()->error() — régression du bug corrigé le 01/09/2026 (round 276) : sendImmediateAlert() ne serait de nouveau jamais déclenché, le marchand ne serait alerté qu'après l'extinction totale des envois (7 jours après la révocation), pas à temps pour agir"
    );
    neria_assert(
        strpos($revokedBody, '$this->wd()->warning(') === false,
        "LicenseManager utilise de nouveau wd()->warning() pour la révocation confirmée — régression du bug corrigé le 01/09/2026 (round 276)"
    );

    $posExpiring = strpos($src, "\\Configuration::updateGlobalValue(self::CONFIG_EXPIRY_WARNED_FOR, \$expires);");
    neria_assert($posExpiring !== false, "le bloc d'alerte d'expiration est introuvable — jeu de test invalide");
    $expiringBody = substr($src, $posExpiring, 900);
    neria_assert(
        strpos($expiringBody, '$this->wd()->error(') !== false
            && strpos($expiringBody, "\\WatchdogManager::i18nMsg('watchdog.license_expiring_soon',") !== false,
        "LicenseManager ne journalise plus l'alerte d'expiration proactive via wd()->error() — régression du bug corrigé le 01/09/2026 (round 276) : cette alerte, censée être PROACTIVE (15 jours avant échéance), redeviendrait reléguée au même canal opt-in que le reste"
    );
    neria_assert(
        strpos($expiringBody, '$this->wd()->warning(') === false,
        "LicenseManager utilise de nouveau wd()->warning() pour l'alerte d'expiration proactive — régression du bug corrigé le 01/09/2026 (round 276)"
    );

    return [
        'pass'    => true,
        'message' => "LicenseManager déclenche désormais une alerte email immédiate (error()) sur révocation confirmée et sur expiration proche, au lieu du seul digest opt-in — bug corrigé le 01/09/2026 (round 276)",
    ];
}
