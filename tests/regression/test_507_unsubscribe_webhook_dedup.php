<?php
/**
 * Régression : `controllers/front/unsubscribe.php::processUnsubscribe()`
 * appelait `WebhookManager::trigger('unsubscribed', ...)` sans aucune
 * protection contre le REJEU du même lien légitime au-delà du throttle
 * DB/CPU existant (5 requêtes/10s, round 247). Le désabonnement lui-même
 * est idempotent, mais `WebhookManager::trigger()` mettait inconditionnel-
 * lement un nouvel événement en file à chaque appel : un rechargement de
 * la page de confirmation, un retry réseau du client mail sur le POST
 * one-click (RFC 8058), ou un scanner de sécurité d'entreprise qui pré-
 * visite le lien List-Unsubscribe, provoquait autant de notifications
 * 'unsubscribed' sortantes vers le webhook configuré par le marchand —
 * problématique pour un service tiers (CRM, plateforme externe) non
 * idempotent.
 *
 * Corrigé le 01/09/2026 (round 265) : ajout d'une fenêtre de déduplication
 * APCu de 24h, dédiée à CET appel (indépendante du throttle DB existant,
 * clé distincte `neria_unsub_webhook_` vs `neria_unsub_rl_`), fail-open si
 * APCu indisponible (même convention que le throttle existant et que le
 * reste du module).
 *
 * Test structurel : l'extension APCu est confirmée absente de cet
 * environnement de dev/test (`function_exists('apcu_enabled')` → false),
 * donc la logique de déduplication ne peut être vérifiée que par
 * inspection du code (comme `test_482` pour le throttle voisin), pas par
 * exécution comportementale réelle.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/controllers/front/unsubscribe.php');
    neria_assert($src !== false, 'Impossible de lire controllers/front/unsubscribe.php');

    // La clé de dédup webhook doit être distincte de la clé du throttle DB
    // existant, pour ne pas partager/écraser son compteur.
    neria_assert(
        strpos($src, "'neria_unsub_webhook_' . md5(\$token)") !== false,
        "controllers/front/unsubscribe.php n'utilise plus de clé APCu dédiée 'neria_unsub_webhook_' pour la déduplication du webhook — régression du bug corrigé le 01/09/2026 (round 265)"
    );

    neria_assert(
        strpos($src, "'neria_unsub_rl_' . md5(\$ip . '|' . \$token)") !== false,
        "controllers/front/unsubscribe.php n'a plus le throttle DB/CPU existant (round 247) — jeu de test invalide ou régression connexe"
    );

    // La fenêtre de dédup doit être 24h (86400s), plus longue que le
    // throttle DB (10s) puisqu'elle protège un rejeu ESPACÉ, pas une rafale.
    neria_assert(
        strpos($src, 'apcu_store($webhookKey, 1, 86400)') !== false,
        "controllers/front/unsubscribe.php n'a plus la fenêtre de déduplication webhook de 24h attendue — régression du bug corrigé le 01/09/2026 (round 265)"
    );

    // Le déclenchement du webhook doit être conditionné par l'absence de
    // notification déjà envoyée pour ce token.
    neria_assert(
        strpos($src, 'if ($ok && !$webhookAlreadyNotified && class_exists(\'WebhookManager\'))') !== false,
        "controllers/front/unsubscribe.php ne conditionne plus l'appel WebhookManager::trigger('unsubscribed', ...) par \$webhookAlreadyNotified — régression du bug corrigé le 01/09/2026 (round 265) : un rejeu du lien re-déclencherait le webhook sortant sans limite"
    );

    // Fail-open : la nouvelle protection doit rester dans le même bloc
    // conditionnel apcu_enabled() que le reste du module (jamais bloquant
    // si APCu est indisponible, comme c'est le cas dans cet environnement).
    $webhookBlockStart = strpos($src, '$webhookAlreadyNotified = false;');
    neria_assert($webhookBlockStart !== false, "controllers/front/unsubscribe.php : bloc de déduplication webhook introuvable");
    $webhookBlockGuard = substr($src, $webhookBlockStart, 200);
    neria_assert(
        strpos($webhookBlockGuard, "function_exists('apcu_enabled') && apcu_enabled()") !== false,
        "controllers/front/unsubscribe.php : la déduplication webhook n'est plus protégée par function_exists('apcu_enabled') && apcu_enabled() — un environnement sans APCu planterait au lieu de fail-open"
    );

    return [
        'pass'    => true,
        'message' => "controllers/front/unsubscribe.php déduplique désormais le webhook 'unsubscribed' sur une fenêtre de 24h (clé APCu distincte du throttle DB, fail-open si APCu indisponible) — bug corrigé le 01/09/2026 (round 265)",
    ];
}
