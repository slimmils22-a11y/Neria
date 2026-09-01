<?php
/**
 * Régression : `QueueManager::processSingle()` n'appelait AUCUN
 * `watchdog()->warning()/error()` quand `Mail::Send()` retournait `false`
 * SANS lever d'exception — seul le `catch (\Throwable $e)` déclenchait un
 * `error()`, un chemin quasiment mort pour une vraie panne SMTP puisque le
 * cœur PrestaShop capture lui-même `\Swift_SwiftException` en interne et
 * renvoie simplement `false` (mauvais mot de passe SMTP, port fermé,
 * connexion refusée...).
 *
 * Conséquence en cascade : `sendImmediateAlert()` n'est déclenché que par
 * `error()`/`critical()` (jamais par `info()`), et le digest quotidien ne
 * lit que les logs de niveau `warning|error|critical` (jamais `info`) — le
 * résumé de lot `queue_processed_summary` reste TOUJOURS en `info()` même
 * si tous les envois du lot ont échoué. Une panne SMTP totale et
 * permanente côté marchand pouvait ainsi se dérouler indéfiniment sans
 * jamais déclencher ni alerte immédiate ni entrée dans le digest
 * quotidien, malgré une infrastructure d'alerte déjà conçue pour survivre
 * à un SMTP marchand cassé (`mail()` natif, round 250).
 *
 * Bug identifié le 01/09/2026 (round 268, audit "distinction échec
 * temporaire/permanent dans les envois SMTP").
 *
 * Corrigé le 01/09/2026 (round 268) : un `watchdog()->warning()` explicite
 * est désormais émis sur le chemin `$sent === false`, avant l'appel à
 * `markFailedOrRetry()` — symétrique au `error()` déjà présent dans le
 * `catch`. Ce niveau `warning` suffit à déclencher l'inclusion dans le
 * digest quotidien (niveaux warning/error/critical), sans changer le
 * comportement de retry existant ni provoquer une alerte immédiate par
 * message individuel (réservée aux erreurs plus graves de type
 * exception).
 *
 * Test structurel : le comportement dépend de `Mail::Send()` retournant
 * `false` sans exception, ce qui nécessite de casser la configuration SMTP
 * réelle de l'environnement de test — hors périmètre sûr d'une
 * reproduction comportementale complète dans cette suite (même contrainte
 * que d'autres tests de cette série sur des chemins d'erreur best-effort
 * dépendant de l'infrastructure). Vérifie que le code source appelle bien
 * watchdog()->warning() avec la nouvelle clé de traduction sur ce chemin
 * précis, et que la clé existe dans les 19 locales.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/QueueManager.php');
    neria_assert($src !== false, 'Impossible de lire src/QueueManager.php');

    $retryPos = strpos($src, "\$this->markFailedOrRetry(\$id, (int) \$row['attempts'] + 1, 'Mail::Send() a retourné false.');");
    neria_assert($retryPos !== false, "l'appel markFailedOrRetry() sur le chemin \$sent === false est introuvable");

    // Le warning doit précéder markFailedOrRetry() sur CE chemin précis
    // (pas seulement exister quelque part dans le fichier, ex. dans le
    // catch plus bas) — fenêtre restreinte juste avant l'appel repéré.
    $before = substr($src, max(0, $retryPos - 700), 700);

    neria_assert(
        strpos($before, "WatchdogManager::i18nMsg('watchdog.queue_send_failed', ['email' => \$row['recipient_email'], 'id' => \$id])") !== false,
        "QueueManager::processSingle() n'émet plus de watchdog()->warning() avec la clé watchdog.queue_send_failed juste avant markFailedOrRetry() sur le chemin \$sent === false — régression du bug corrigé le 01/09/2026 (round 268) : une panne SMTP totale sans exception redeviendrait invisible côté digest/alerte Watchdog"
    );
    neria_assert(
        strpos($before, '$this->watchdog()->warning(') !== false,
        "QueueManager::processSingle() n'appelle plus watchdog()->warning() sur le chemin \$sent === false — régression du bug corrigé le 01/09/2026 (round 268)"
    );

    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(is_array($translations), 'admin_translations.json illisible ou invalide');
    $locales = ['fr','en','de','it','es','pt','br','ar','ja','ko','zh','tw','ru','tr','sv','no','da','nl','gb'];
    foreach ($locales as $l) {
        neria_assert(
            isset($translations['watchdog.queue_send_failed'][$l]) && $translations['watchdog.queue_send_failed'][$l] !== '',
            "La clé watchdog.queue_send_failed est absente ou vide pour la locale '{$l}' dans admin_translations.json"
        );
    }

    return [
        'pass'    => true,
        'message' => "QueueManager::processSingle() journalise désormais un warning Watchdog (inclus au digest quotidien) quand Mail::Send() échoue sans exception — bug corrigé le 01/09/2026 (round 268)",
    ];
}
