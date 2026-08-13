<?php
/**
 * Régression : QueueManager loggait le message d'exception brut
 * ($e->getMessage()) dans Watchdog (watchdog.queue_send_error) et en base
 * (colonne `error` de ps_neria_queue, via un simple substr(...,500) sans
 * filtrage de contenu). Si l'exception provient d'un driver SMTP dont le
 * message inclut des identifiants ("Authentication failed for user X /
 * password Y") ou un en-tête Authorization, ceux-ci se retrouvaient
 * stockés en clair, consultables en BO.
 *
 * Corrigé le 13/08/2026 (round 164) : nouvelle méthode privée
 * sanitizeErrorMessage() retire les motifs password/passwd/pwd/pass/
 * secret/token/apikey/api_key=... et les en-têtes Authorization: ... avant
 * stockage (Watchdog ET colonne `error`), en plus du plafond de longueur
 * déjà existant.
 *
 * Test réel : appelle directement sanitizeErrorMessage() par Reflection
 * avec un message contenant un identifiant fictif, vérifie que le secret
 * est bien retiré et que le texte informatif environnant est préservé.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/QueueManager.php';
    neria_assert(class_exists('QueueManager'), 'Classe QueueManager introuvable');

    $ref = new ReflectionMethod('QueueManager', 'sanitizeErrorMessage');
    $ref->setAccessible(true);

    $raw = 'SMTP connect() failed: Authentication failed for user smtp@example.com password=Sup3rSecret123';
    $sanitized = $ref->invoke(null, $raw);

    neria_assert(
        strpos($sanitized, 'Sup3rSecret123') === false,
        "sanitizeErrorMessage() ne retire plus le secret d'un message d'erreur SMTP — régression du bug corrigé le 13/08/2026 (round 164) : un mot de passe pourrait de nouveau fuiter en clair dans les logs Watchdog/ps_neria_queue"
    );
    neria_assert(
        strpos($sanitized, 'SMTP connect() failed') !== false,
        "sanitizeErrorMessage() a supprimé le contexte informatif utile en plus du secret — le message devrait rester exploitable pour le diagnostic"
    );

    $rawAuthHeader = 'HTTP error: Authorization: Bearer abc123secrettoken rejected';
    $sanitizedAuth = $ref->invoke(null, $rawAuthHeader);
    neria_assert(
        strpos($sanitizedAuth, 'abc123secrettoken') === false,
        "sanitizeErrorMessage() ne retire plus les en-têtes Authorization: ... — régression du bug corrigé le 13/08/2026 (round 164)"
    );

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/QueueManager.php');
    neria_assert($src !== false, 'Impossible de lire QueueManager.php');
    neria_assert(
        strpos($src, 'self::sanitizeErrorMessage($e->getMessage())') !== false,
        "QueueManager n'appelle plus sanitizeErrorMessage() sur le message Watchdog — régression du bug corrigé le 13/08/2026 (round 164)"
    );
    neria_assert(
        strpos($src, 'self::sanitizeErrorMessage($error)') !== false,
        "markFailedOrRetry() n'appelle plus sanitizeErrorMessage() avant stockage en base — régression du bug corrigé le 13/08/2026 (round 164)"
    );

    return [
        'pass'    => true,
        'message' => "QueueManager::sanitizeErrorMessage() retire bien les identifiants/tokens des messages d'erreur avant stockage Watchdog et base — bug corrigé le 13/08/2026 (round 164)",
    ];
}
