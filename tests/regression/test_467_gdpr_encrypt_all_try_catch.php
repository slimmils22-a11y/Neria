<?php
/**
 * Régression round 226 (28/08/2026) : l'action BO 'gdpr_encrypt_all' de
 * neria.php n'était pas protégée par un try/catch, contrairement à son
 * action sœur 'gdpr_purge' juste au-dessus dans le même fichier.
 * encryptExistingRecords() boucle par lots sur neria_stat sans plafond de
 * temps ; un timeout PHP ou une erreur DB en cours de boucle faisait donc
 * remonter une page d'erreur fatale PrestaShop générique au lieu du
 * message BO propre (neria_error), sans indiquer combien de lignes avaient
 * réellement été chiffrées avant l'échec.
 *
 * Corrigé le 28/08/2026 (round 226) : try/catch(\Throwable) ajouté, à
 * l'identique du pattern gdpr_purge, avec journalisation Watchdog et
 * message neria_error clair en cas d'échec.
 *
 * Test structurel : vérifie que le bloc gdpr_encrypt_all contient bien un
 * try/catch avec le message d'échec dédié.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $posAction = strpos($src, "'neria_action') === 'gdpr_encrypt_all'");
    neria_assert($posAction !== false, "Action gdpr_encrypt_all introuvable — jeu de test invalide");
    $body = substr($src, $posAction, 2000);

    neria_assert(
        strpos($body, 'try {') !== false && strpos($body, 'catch (\Throwable $e)') !== false,
        "L'action gdpr_encrypt_all n'est plus protégée par un try/catch — régression du bug corrigé le 28/08/2026 (round 226) : un timeout/erreur DB en cours de boucle referait remonter une page d'erreur fatale générique au lieu du message BO propre"
    );
    neria_assert(
        strpos($body, 'watchdog.gdpr_encrypt_retroactive_failed') !== false,
        "L'action gdpr_encrypt_all ne journalise plus d'alerte Watchdog dédiée en cas d'échec — régression du bug corrigé le 28/08/2026 (round 226)"
    );
    neria_assert(
        strpos($body, "'neria_error'") !== false,
        "L'action gdpr_encrypt_all n'assigne plus de message neria_error en cas d'échec — régression du bug corrigé le 28/08/2026 (round 226)"
    );

    // La clé de traduction du message d'échec doit exister dans les 19
    // langues d'admin_translations.json.
    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(
        json_last_error() === JSON_ERROR_NONE && isset($translations['watchdog.gdpr_encrypt_retroactive_failed']),
        "Clé de traduction watchdog.gdpr_encrypt_retroactive_failed manquante dans data/admin_translations.json"
    );
    $langs = $translations['watchdog.gdpr_encrypt_retroactive_failed'];
    neria_assert(
        count($langs) === 19,
        "watchdog.gdpr_encrypt_retroactive_failed ne couvre pas les 19 langues (trouvé : " . count($langs) . ")"
    );

    return [
        'pass'    => true,
        'message' => "L'action gdpr_encrypt_all est bien protégée par un try/catch, comme gdpr_purge — message d'échec traduit dans les 19 langues",
    ];
}
