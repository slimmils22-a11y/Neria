<?php
/** Régression : WebhookManager::processQueue() doit rester protégé par GET_LOCK interne. */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WebhookManager.php');
    neria_assert(
        (bool) preg_match('/function processQueue.*?GET_LOCK/s', $src),
        "processQueue() n'a plus de GET_LOCK interne — régression de la race corrigée le 17/07/2026 (commit 3221a65), le bouton BO redeviendrait vulnérable au double traitement"
    );
    return ['pass' => true, 'message' => 'processQueue() toujours protégé par un verrou interne'];
}
