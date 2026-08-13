<?php
/**
 * Régression : 4 bugs de LicenseManager corrigés le 09/08/2026 (round 160) :
 * (1) Aucun throttle sur les échecs réseau — CONFIG_LAST_CHECK n'est
 *     renseigné que sur succès (storeToken()), donc figé pendant toute une
 *     panne serveur ; une fois CACHE_TTL (24h) expiré pendant cette panne,
 *     CHAQUE page vue redéclenchait un appel curl bloquant (jusqu'à 10s).
 *     Corrigé par CONFIG_LAST_ATTEMPT (mis à jour sur succès ET échec) +
 *     RETRY_BACKOFF (15 min).
 * (2) Vider CONFIG_KEY seul (sans passer par le désinstall) laissait
 *     CONFIG_LAST_CHECK d'une activation antérieure figer indéfiniment
 *     isWithinGracePeriod() sur "grâce illimitée", sans jamais se
 *     resynchroniser (validateLicense() ne revalide plus tant que
 *     CONFIG_KEY est vide). Corrigé en purgeant CONFIG_LAST_CHECK/
 *     REVOKED_AT/TOKEN dès que CONFIG_KEY est trouvée vide.
 * (3) Une réponse serveur ok:true sans champ 'valid' (malformée/tronquée)
 *     était traitée comme une vraie révocation (empty() ne distingue pas
 *     absence de false explicite). Corrigé via array_key_exists('valid', ...).
 * (4) Cycle lecture-modification-écriture de CONFIG_LAST_CHECK non protégé
 *     sous concurrence. Corrigé par un GET_LOCK non bloquant.
 *
 * Test comportemental réel (bug 2, seul testable sans appel réseau réel) :
 * vide CONFIG_KEY avec un CONFIG_LAST_CHECK résiduel d'activation
 * antérieure, appelle validateLicense(), vérifie que CONFIG_LAST_CHECK est
 * bien purgé (isWithinGracePeriod() ne resterait plus figé indéfiniment).
 * Test structurel pour les bugs 1/3/4 (appel réseau réel non invocable
 * dans ce jeu de tests) : vérifie la présence du code des correctifs.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/LicenseManager.php';

    // ── Partie 1 : structurel — bugs 1, 3, 4 ─────────────────────────────
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/LicenseManager.php');
    neria_assert($src !== false, 'Impossible de lire LicenseManager.php');

    neria_assert(
        strpos($src, "const CONFIG_LAST_ATTEMPT = 'NERIA_LICENSE_LAST_ATTEMPT';") !== false
        && strpos($src, 'const RETRY_BACKOFF       = 900;') !== false
        && strpos($src, 'self::CONFIG_LAST_ATTEMPT') !== false,
        "LicenseManager n'a plus de throttle indépendant sur les échecs réseau (CONFIG_LAST_ATTEMPT/RETRY_BACKOFF) — régression du bug corrigé le 09/08/2026 (round 160) : une panne serveur prolongée redeviendrait un incident de performance sur chaque page vue"
    );
    neria_assert(
        strpos($src, "array_key_exists('valid', \$response) && empty(\$response['valid'])") !== false,
        "LicenseManager ne distingue plus une vraie révocation (valid:false explicite) d'une réponse malformée sans champ 'valid' — régression du bug corrigé le 09/08/2026 (round 160)"
    );
    neria_assert(
        strpos($src, "GET_LOCK('neria_license_validate'") !== false && strpos($src, "RELEASE_LOCK('neria_license_validate')") !== false,
        "LicenseManager::validateLicense() n'a plus de verrou sur le cycle lecture-modification-écriture — régression du bug corrigé le 09/08/2026 (round 160)"
    );

    // ── Partie 2 : comportemental réel — bug 2 ───────────────────────────
    $savedKey        = Configuration::get(LicenseManager::CONFIG_KEY);
    $savedLastCheck  = Configuration::get(LicenseManager::CONFIG_LAST_CHECK);
    $savedRevokedAt  = Configuration::get(LicenseManager::CONFIG_REVOKED_AT);
    $savedToken      = Configuration::get(LicenseManager::CONFIG_TOKEN);

    try {
        Configuration::updateValue(LicenseManager::CONFIG_KEY, '');
        Configuration::updateGlobalValue(LicenseManager::CONFIG_LAST_CHECK, time() - 100000); // résidu d'activation antérieure

        $lm = new LicenseManager(neria_test_module());
        $lm->validateLicense();

        $lastCheckAfter = (int) Configuration::get(LicenseManager::CONFIG_LAST_CHECK);
        neria_assert(
            $lastCheckAfter === 0,
            "LicenseManager::validateLicense() n'a pas purgé CONFIG_LAST_CHECK résiduel quand CONFIG_KEY est vide — régression du bug corrigé le 09/08/2026 (round 160) : isWithinGracePeriod() resterait figé sur \"grâce illimitée\" indéfiniment sans jamais se resynchroniser"
        );

        return [
            'pass'    => true,
            'message' => "LicenseManager purge bien l'état résiduel (CONFIG_LAST_CHECK) quand CONFIG_KEY devient vide hors désinstallation, et conserve son throttle réseau/verrou/distinction de révocation — bugs corrigés le 09/08/2026 (round 160)",
        ];
    } finally {
        if ($savedKey !== false && $savedKey !== null) { Configuration::updateValue(LicenseManager::CONFIG_KEY, $savedKey); }
        if ($savedLastCheck !== false && $savedLastCheck !== null && (int) $savedLastCheck > 0) {
            Configuration::updateGlobalValue(LicenseManager::CONFIG_LAST_CHECK, (int) $savedLastCheck);
        }
        if ($savedRevokedAt !== false && $savedRevokedAt !== null) { Configuration::updateGlobalValue(LicenseManager::CONFIG_REVOKED_AT, $savedRevokedAt); }
        if ($savedToken !== false && $savedToken !== null) { Configuration::updateGlobalValue(LicenseManager::CONFIG_TOKEN, $savedToken); }
    }
}
