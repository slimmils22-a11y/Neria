<?php
/**
 * Régression : WebhookManager::retryOne() ne devait remettre en file
 * ('pending') qu'un webhook réellement status='failed'. Deux failles
 * cumulées le rendaient contournable :
 *  - le SELECT de contrôle (status='failed') était appelé sans
 *    $use_cache=false, donc sous cache SQL BO actif un résultat périmé
 *    pouvait indiquer 'failed' alors que le webhook est déjà 'done'
 *    (livré avec succès) ;
 *  - l'UPDATE qui suit ne revérifiait PAS status='failed' dans son
 *    propre WHERE, se reposant entièrement sur ce SELECT.
 *
 * Corrigé le 26/08/2026 (round 213) : $use_cache=false sur le SELECT +
 * défense en profondeur, l'UPDATE filtre désormais aussi sur
 * status='failed'.
 *
 * Test comportemental réel : un webhook status='done' seedé en base ne
 * doit JAMAIS être requeue par retryOne() (même en cas de cache SQL
 * périmé, garanti désormais par le filtre de l'UPDATE lui-même) ; un
 * webhook status='failed' réel doit toujours être requeue normalement.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;

    require_once _PS_MODULE_DIR_ . 'neria/src/WebhookManager.php';
    $wh = new WebhookManager($module);

    $idDone = $idFailed = 0;

    try {
        $db->execute(
            "INSERT INTO `{$prefix}neria_webhook_queue`
                (id_shop, event, payload, status, attempts, date_add)
             VALUES ({$idShop}, 'round213_test', '{}', 'done', 0, NOW())"
        );
        $idDone = (int) $db->Insert_ID();

        $db->execute(
            "INSERT INTO `{$prefix}neria_webhook_queue`
                (id_shop, event, payload, status, attempts, date_add)
             VALUES ({$idShop}, 'round213_test', '{}', 'failed', 1, NOW())"
        );
        $idFailed = (int) $db->Insert_ID();

        neria_assert($idDone > 0 && $idFailed > 0, 'Insertion des lignes de test webhook a échoué');

        $resultDone = $wh->retryOne($idDone);
        $statusAfterDone = (string) $db->getValue(
            "SELECT status FROM `{$prefix}neria_webhook_queue` WHERE id_webhook = {$idDone}",
            false
        );
        neria_assert(
            $resultDone === false && $statusAfterDone === 'done',
            "retryOne() sur un webhook status='done' n'a pas été bloqué (résultat=" . var_export($resultDone, true) . ", status en base='{$statusAfterDone}') — régression du bug corrigé le 26/08/2026 (round 213) : un webhook déjà livré pourrait être relancé, causant une livraison dupliquée vers un système tiers"
        );

        $resultFailed = $wh->retryOne($idFailed);
        $statusAfterFailed = (string) $db->getValue(
            "SELECT status FROM `{$prefix}neria_webhook_queue` WHERE id_webhook = {$idFailed}",
            false
        );
        neria_assert(
            $resultFailed === true && $statusAfterFailed === 'pending',
            "retryOne() sur un webhook status='failed' réel n'a pas fonctionné normalement (résultat=" . var_export($resultFailed, true) . ", status en base='{$statusAfterFailed}') — le correctif du round 213 aurait cassé le cas nominal"
        );

        return [
            'pass'    => true,
            'message' => "WebhookManager::retryOne() bloque bien un webhook 'done' et requeue normalement un webhook 'failed' réel — bug corrigé le 26/08/2026 (round 213)",
        ];
    } finally {
        if ($idDone > 0) {
            $db->execute("DELETE FROM `{$prefix}neria_webhook_queue` WHERE id_webhook = {$idDone}");
        }
        if ($idFailed > 0) {
            $db->execute("DELETE FROM `{$prefix}neria_webhook_queue` WHERE id_webhook = {$idFailed}");
        }
    }
}
