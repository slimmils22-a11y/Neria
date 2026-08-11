<?php
/**
 * Régression : WebhookManager::cleanup() doit pouvoir s'exécuter même
 * quand l'URL configurée est invalide ou que le secret est illisible.
 *
 * Bug réel corrigé le 09/08/2026 (round 144) : cleanup() n'était appelée
 * que dans le `finally` du bloc try de processQueue(), lui-même situé
 * APRÈS deux `return` précoces (URL invalide, secret illisible). Si la clé
 * de chiffrement maîtresse devenait illisible durablement, processQueue()
 * retournait systématiquement avant ce finally : cleanup() ne tournait
 * plus jamais pour cette boutique, et ps_neria_webhook_queue croissait
 * sans borne.
 *
 * Test structurel assumé explicitement : cleanup() est désormais appelée
 * de façon PROBABILISTE (1 chance sur 10, comme QueueManager::
 * purgeOldEntries()) — un test comportemental fiable nécessiterait des
 * dizaines d'appels pour observer l'effet avec confiance statistique, ou
 * de mocker random_int(), non disponible sans espace de noms dédié. Vérifie
 * donc au niveau du code source que l'appel à cleanup() est bien positionné
 * AVANT les `return` précoces de validation URL/secret.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WebhookManager.php');
    neria_assert($src !== false, 'Impossible de lire src/WebhookManager.php');

    $posMethod = strpos($src, 'public function processQueue(): void');
    neria_assert($posMethod !== false, 'processQueue() introuvable — jeu de test invalide');

    $posCleanupCall = strpos($src, 'if (random_int(1, 10) === 1) {', $posMethod);
    neria_assert($posCleanupCall !== false, 'appel probabiliste à cleanup() introuvable dans processQueue()');

    $posUrlReturn = strpos($src, "if (\$url === '' || !self::isPublicUrl(\$url)) {", $posMethod);
    $posSecretReturn = strpos($src, "if (\$secret === '') {", $posMethod);

    neria_assert($posUrlReturn !== false, 'le return précoce sur URL invalide est introuvable — jeu de test invalide');
    neria_assert($posSecretReturn !== false, 'le return précoce sur secret illisible est introuvable — jeu de test invalide');

    neria_assert(
        $posCleanupCall < $posUrlReturn,
        "l'appel à cleanup() n'est plus positionné avant le return précoce sur URL invalide — régression du bug corrigé le 09/08/2026 (round 144) : cleanup() redeviendrait inatteignable si l'URL configurée est durablement invalide"
    );
    neria_assert(
        $posCleanupCall < $posSecretReturn,
        "l'appel à cleanup() n'est plus positionné avant le return précoce sur secret illisible — régression du bug corrigé le 09/08/2026 (round 144) : cleanup() redeviendrait inatteignable si la clé de chiffrement maîtresse devient illisible durablement, ps_neria_webhook_queue croîtrait sans borne"
    );

    return [
        'pass'    => true,
        'message' => "WebhookManager::cleanup() est bien appelée avant les return précoces de validation URL/secret — reste atteignable même en cas de config invalide durable",
    ];
}
