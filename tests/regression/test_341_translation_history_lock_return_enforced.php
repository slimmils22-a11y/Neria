<?php
/**
 * Régression : TranslationHistoryManager::record() vérifiait bien le retour
 * de GET_LOCK() (round 151) et journalisait un warning en cas d'échec, mais
 * n'exécutait ensuite aucun `return` — l'INSERT + pruneKey() s'exécutaient
 * quand même, verrou obtenu ou non. La vérification du round 151 ne
 * protégeait donc RIEN en pratique : elle se contentait de loguer un
 * warning avant de laisser la race condition du round 138 (double insertion
 * + double purge de pruneKey() sous forte concurrence, dépassant
 * transitoirement MAX_PER_KEY) se reproduire dès que GET_LOCK() time-out
 * (3s) ou échoue.
 *
 * Corrigé le 15/08/2026 (round 175) : un `return` explicite suit désormais
 * le log du warning, alignant ce verrou sur le pattern systématique utilisé
 * partout ailleurs dans le projet (OrderTriggersManager, WaitlistManager,
 * WebhookManager : `if (... !== 1) { return; }`).
 *
 * Test structurel : vérifie que le bloc `if ($acquired !== 1)` se termine
 * bien par un `return;` avant le `try` qui contient l'INSERT.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/TranslationHistoryManager.php');
    neria_assert($src !== false, 'Impossible de lire src/TranslationHistoryManager.php');

    $posIf = strpos($src, 'if ($acquired !== 1) {');
    neria_assert($posIf !== false, "Bloc de vérification du verrou introuvable — jeu de test invalide");

    $posTry = strpos($src, 'try {', $posIf);
    neria_assert($posTry !== false, "Bloc try introuvable après la vérification du verrou — jeu de test invalide");

    $ifBlock = substr($src, $posIf, $posTry - $posIf);

    neria_assert(
        strpos($ifBlock, 'return;') !== false,
        "TranslationHistoryManager::record() n'exécute plus de return après un échec de GET_LOCK() — régression du bug corrigé le 15/08/2026 (round 175) : l'INSERT + pruneKey() s'exécuteraient de nouveau même sans verrou obtenu, recréant la race condition du round 138 malgré le warning journalisé"
    );

    return [
        'pass'    => true,
        'message' => "TranslationHistoryManager::record() interrompt bien l'exécution (return) quand GET_LOCK() échoue, le warning n'est plus une simple façade — bug corrigé le 15/08/2026 (round 175)",
    ];
}
