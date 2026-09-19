<?php
/**
 * Bloc 6 (19/09/2026) : le webhook de bounces entrant n'acceptait qu'UNE
 * authentification, l'en-tête `X-Neria-Signature` (HMAC hexadécimal du corps),
 * qu'AUCUN fournisseur annoncé (Mailgun, SendGrid, Postmark) n'envoie :
 * Mailgun signe dans le corps (HMAC de timestamp+token avec sa propre clé),
 * SendGrid signe en ECDSA, Postmark ne signe pas. Le secret étant obligatoire
 * (403 sans lui), le point d'entrée était INUTILISABLE avec les fournisseurs
 * pour lesquels le BO le documente. Constaté en envoyant de vraies requêtes
 * sur ps-test.
 *
 * Corrigé : BounceManager::authenticateWebhook() accepte (1) l'en-tête HMAC
 * (hex brut ou « sha256=<hex> »), (2) un jeton d'URL (?token=) dérivé du
 * secret — jamais le secret lui-même —, (3) la signature native Mailgun avec
 * fenêtre de ±15 min anti-rejeu.
 *
 * Test comportemental réel sur les vraies méthodes : chaque voie valide est
 * acceptée ; chaque variante invalide est rejetée ; secret absent = tout refusé.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/BounceManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/CryptoManager.php';
    $module = neria_test_module();
    $mgr    = new BounceManager($module);

    $secret = 'regtest805-secret-key';
    $old    = \Configuration::get(BounceManager::CFG_WEBHOOK_SECRET);
    $body   = '{"event":"hard_bounce","email":"regtest805@example.com"}';
    $payload = json_decode($body, true);

    try {
        // --- Sans secret : tout est refusé, y compris avec des entrées « vides ».
        \Configuration::updateValue(BounceManager::CFG_WEBHOOK_SECRET, '');
        neria_assert(BounceManager::getWebhookToken() === '', "un jeton existe alors qu'aucun secret n'est configuré");
        neria_assert($mgr->authenticateWebhook($body, $payload, '', '') === false, "accepté sans secret (entrées vides)");
        neria_assert($mgr->authenticateWebhook($body, $payload, hash_hmac('sha256', $body, ''), '') === false, "accepté sans secret (HMAC avec clé vide)");
        neria_assert(strpos(BounceManager::getWebhookUrlWithToken(), 'token=') === false, "l'URL contient un jeton sans secret");

        \Configuration::updateValue(BounceManager::CFG_WEBHOOK_SECRET, \CryptoManager::encrypt($secret));

        // --- 1. En-tête X-Neria-Signature.
        $hex = hash_hmac('sha256', $body, $secret);
        neria_assert($mgr->authenticateWebhook($body, $payload, $hex, '') === true, "en-tête HMAC (hex brut) refusé");
        neria_assert($mgr->authenticateWebhook($body, $payload, 'sha256=' . $hex, '') === true, "en-tête HMAC « sha256=<hex> » refusé");
        neria_assert($mgr->authenticateWebhook($body, $payload, 'deadbeef', '') === false, "signature erronée acceptée");
        neria_assert($mgr->authenticateWebhook($body . ' ', $payload, $hex, '') === false, "corps modifié accepté avec l'ancienne signature");

        // --- 2. Jeton d'URL.
        $token = BounceManager::getWebhookToken();
        neria_assert($token !== '' && $token !== $secret && strpos($token, $secret) === false, "le jeton d'URL révèle le secret");
        neria_assert(strpos(BounceManager::getWebhookUrlWithToken(), 'token=' . $token) !== false, "l'URL affichée ne contient pas le jeton");
        neria_assert($mgr->authenticateWebhook($body, $payload, '', $token) === true, "jeton d'URL valide refusé (SendGrid/Postmark)");
        neria_assert($mgr->authenticateWebhook($body, $payload, '', 'x' . $token) === false, "jeton d'URL erroné accepté");
        neria_assert($mgr->authenticateWebhook($body, $payload, '', $secret) === false, "le secret brut ne doit PAS servir de jeton d'URL");
        neria_assert($mgr->authenticateWebhook($body, $payload, '', '') === false, "aucune authentification acceptée");

        // --- 3. Signature native Mailgun.
        $ts  = (string) time();
        $tok = 'regtest805mailguntoken';
        $mg  = fn (string $t, string $k, string $s): array => ['signature' => ['timestamp' => $t, 'token' => $k, 'signature' => $s], 'event-data' => ['event' => 'failed', 'recipient' => 'regtest805@example.com']];
        $good = hash_hmac('sha256', $ts . $tok, $secret);
        $p    = $mg($ts, $tok, $good);
        neria_assert($mgr->authenticateWebhook((string) json_encode($p), $p, '', '') === true, "signature Mailgun valide refusée");
        $p = $mg($ts, $tok, strtoupper($good));
        neria_assert($mgr->authenticateWebhook((string) json_encode($p), $p, '', '') === true, "signature Mailgun en majuscules refusée");
        $p = $mg($ts, $tok, hash_hmac('sha256', $ts . 'autre', $secret));
        neria_assert($mgr->authenticateWebhook((string) json_encode($p), $p, '', '') === false, "signature Mailgun avec un autre jeton acceptée");
        $oldTs = (string) (time() - 3600);
        $p = $mg($oldTs, $tok, hash_hmac('sha256', $oldTs . $tok, $secret));
        neria_assert($mgr->authenticateWebhook((string) json_encode($p), $p, '', '') === false, "signature Mailgun périmée (rejeu, 1 h) acceptée");
        $p = $mg($ts, $tok, hash_hmac('sha256', $ts . $tok, 'mauvaise-cle'));
        neria_assert($mgr->authenticateWebhook((string) json_encode($p), $p, '', '') === false, "signature Mailgun avec une mauvaise clé acceptée");
        $p = $mg('abc', $tok, 'x');
        neria_assert($mgr->authenticateWebhook((string) json_encode($p), $p, '', '') === false, "horodatage non numérique accepté");
        $p = ['signature' => 'pas-un-tableau'];
        neria_assert($mgr->authenticateWebhook((string) json_encode($p), $p, '', '') === false, "signature de type inattendu acceptée");
    } finally {
        \Configuration::updateValue(BounceManager::CFG_WEBHOOK_SECRET, (string) $old);
    }

    // Le contrôleur utilise bien la nouvelle méthode et le BO affiche l'URL avec jeton.
    $ctl = (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/controllers/front/bounce.php');
    neria_assert(strpos($ctl, '->authenticateWebhook(') !== false && strpos($ctl, '->verifyWebhookSignature(') === false, "le contrôleur n'utilise plus authenticateWebhook()");
    $neria = (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert(strpos($neria, 'BounceManager::getWebhookUrlWithToken()') !== false, "le BO n'affiche plus l'URL avec jeton");

    return ['pass' => true, 'message' => "le webhook de bounces s'authentifie par en-tête HMAC, jeton d'URL ou signature Mailgun (rejeu refusé), jamais sans secret — bloc 6 (19/09/2026)"];
}
