<?php
/**
 * Régression round 250 (31/08/2026) — 3 correctifs indépendants du même
 * round, regroupés dans un seul test :
 *
 * 1. WatchdogManager::sendImmediateAlert()/sendDailyDigestIfDue() utilisent
 *    mail() natif PHP (pas Mail::Send() du cœur PS, volontairement --
 *    "pour fonctionner même si PS Mail::Send est cassé") et construisent
 *    leurs propres en-têtes. Sans encodage RFC 2047 (mb_encode_mimeheader()),
 *    un sujet non-ASCII (traduction non-latine, PS_SHOP_NAME accentué)
 *    partait en octets UTF-8 bruts dans l'en-tête SMTP -- affichage
 *    "??????"/mojibake côté client mail.
 *
 * 2. NeriaErrorHandler::register() utilisait `private static bool
 *    $registered` pour son garde d'idempotence -- une propriété STATIQUE
 *    DE CLASSE survit pour toute la durée de vie du WORKER PHP-FPM, pas
 *    seulement de la requête courante (contrairement à
 *    register_shutdown_function() lui-même, réinitialisé par PHP à chaque
 *    requête). Sur un hébergement mutualisé, le filet de sécurité contre
 *    les erreurs fatales ne s'installait alors qu'à la 1ère requête
 *    traitée par un worker, puis JAMAIS PLUS pour toutes les suivantes.
 *
 * 3. CryptoManager::logDecryptFailure() utilisait `static $loggedReasons`
 *    (variable statique LOCALE à la méthode) pour son throttle par motif
 *    distinct (round 172) -- même défaut de portée que le point 2 : un
 *    motif d'échec de déchiffrement se retrouvait banni de journalisation
 *    À VIE sur un worker après le premier incident, même pour une AUTRE
 *    boutique traitée ensuite par ce même worker.
 *
 * Corrigé le 31/08/2026 (round 250) : mb_encode_mimeheader() pour le
 * point 1 ; $GLOBALS (garanti frais à chaque requête PHP-FPM, tout en
 * restant partagé au sein d'UNE MÊME requête) pour les points 2 et 3.
 *
 * Test réel (partie A) : démontre sur un sujet arabe réel que
 * mb_encode_mimeheader() produit bien un en-tête RFC 2047 conforme
 * (=?UTF-8?B?...?=), alors que le sujet brut contient des octets non-ASCII
 * qui ne le seraient pas.
 *
 * Test réel (partie B) : démontre le mécanisme exact de la correction pour
 * NeriaErrorHandler/CryptoManager -- réinitialise $GLOBALS (simulant la
 * fraîcheur garantie d'une NOUVELLE requête PHP-FPM), appelle
 * register()/logDecryptFailure(), vérifie que le comportement redevient
 * actif (pas de no-op permanent hérité d'une requête précédente).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    // ── Partie A : démonstration réelle RFC 2047 ──
    $arabicSubject = 'تنبيه Neria : خطأ حرج'; // sujet non-ASCII plausible (langue BO arabe)
    $encoded = mb_encode_mimeheader($arabicSubject, 'UTF-8', 'B', "\r\n");
    neria_assert(
        strpos($encoded, '=?UTF-8?B?') !== false,
        "mb_encode_mimeheader() ne produit pas un en-tête RFC 2047 conforme (=?UTF-8?B?...?=) sur un sujet non-ASCII — comportement inattendu de la fonction elle-même"
    );
    // Le sujet brut, lui, ne doit PAS être ASCII-safe (démontre le défaut visé).
    neria_assert(
        $arabicSubject !== mb_convert_encoding($arabicSubject, 'ASCII', 'UTF-8') || preg_match('/[^\x00-\x7F]/', $arabicSubject) === 1,
        "jeu de test invalide : le fixture arabe ne contient pas de caractère non-ASCII"
    );

    $wdmSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WatchdogManager.php');
    neria_assert($wdmSrc !== false, 'Impossible de lire WatchdogManager.php');
    neria_assert(
        substr_count(str_replace("\r", '', $wdmSrc), "mb_encode_mimeheader(\$subject, 'UTF-8', 'B', \"\\r\\n\")") === 2,
        "WatchdogManager n'encode plus le sujet en RFC 2047 sur ses 2 sites mail() natif — régression du bug corrigé le 31/08/2026 (round 250)"
    );

    // ── Partie B : démonstration réelle du mécanisme $GLOBALS ──
    require_once _PS_MODULE_DIR_ . 'neria/src/NeriaErrorHandler.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/CryptoManager.php';

    // Simule la fin d'une requête précédente ayant déjà appelé register().
    $GLOBALS['__neria_error_handler_registered'] = true;
    // Simule le début d'une NOUVELLE requête PHP-FPM (fraîcheur garantie
    // par $GLOBALS, contrairement à l'ancienne propriété static de classe
    // qui aurait survécu telle quelle, rendant register() un no-op permanent).
    unset($GLOBALS['__neria_error_handler_registered']);
    NeriaErrorHandler::register();
    neria_assert(
        !empty($GLOBALS['__neria_error_handler_registered']),
        "NeriaErrorHandler::register() ne repose plus sur un marqueur scopé par requête — régression du bug corrigé le 31/08/2026 (round 250) : sur un worker PHP-FPM réutilisé, le filet de sécurité contre les erreurs fatales ne se réinstallerait plus jamais après la première requête"
    );

    // Même démonstration pour CryptoManager : simule un motif déjà journalisé
    // par une requête PRÉCÉDENTE (autre boutique, même worker), puis une
    // NOUVELLE requête (reset $GLOBALS) doit pouvoir journaliser ce même
    // motif à nouveau -- sans quoi une boutique B ne serait jamais alertée
    // d'un incident réel juste parce qu'une boutique A a eu le même motif
    // d'échec avant elle sur ce worker.
    $testReason = 'regtest485-motif-fictif';
    $GLOBALS['__neria_crypto_logged_decrypt_reasons'] = [$testReason => true];
    unset($GLOBALS['__neria_crypto_logged_decrypt_reasons']);
    $ref = new ReflectionMethod('CryptoManager', 'logDecryptFailure');
    $ref->setAccessible(true);
    $ref->invoke(null, $testReason);
    neria_assert(
        isset($GLOBALS['__neria_crypto_logged_decrypt_reasons'][$testReason]),
        "CryptoManager::logDecryptFailure() n'utilise plus \$GLOBALS pour son throttle par motif — régression du bug corrigé le 31/08/2026 (round 250)"
    );
    unset($GLOBALS['__neria_crypto_logged_decrypt_reasons']);

    return [
        'pass'    => true,
        'message' => "Les 3 correctifs du round 250 sont bien opérants : mb_encode_mimeheader() produit un sujet RFC 2047 conforme (démontré sur fixture arabe), et NeriaErrorHandler::register()/CryptoManager::logDecryptFailure() redeviennent actifs après réinitialisation de \$GLOBALS (simulant une nouvelle requête PHP-FPM)",
    ];
}
