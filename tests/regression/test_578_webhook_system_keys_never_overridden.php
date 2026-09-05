<?php
/**
 * Régression : WebhookManager::trigger() construisait son payload via
 * array_merge([clés système], $data) — array_merge() fait primer le
 * DERNIER tableau fourni en cas de clé identique. Une clé 'event'/
 * 'shop_id'/'timestamp' fournie par mégarde dans $data par un futur
 * appelant écraserait silencieusement l'événement/la boutique/
 * l'horodatage réels du payload envoyé au webhook externe.
 *
 * Corrigé le 05/09/2026 (round 305) : ordre inversé —
 * array_merge($data, [clés système]) — les clés système gagnent
 * désormais TOUJOURS, quel que soit le contenu de $data.
 *
 * Test comportemental réel : appelle trigger() avec un $data contenant
 * volontairement une clé 'event' différente de l'événement réel — vérifie
 * que le payload réellement mis en file conserve le VRAI événement, pas
 * celui injecté dans $data.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/WebhookManager.php';

    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $idShop = (int) Context::getContext()->shop->id;

    $originalUrl = Configuration::get('NERIA_WEBHOOK_URL', null, null, $idShop);
    $originalEvents = Configuration::get('NERIA_WEBHOOK_EVENTS', null, null, $idShop);
    Configuration::updateValue('NERIA_WEBHOOK_URL', 'https://example.test/regtest578-webhook', false, null, $idShop);
    Configuration::updateValue('NERIA_WEBHOOK_EVENTS', '[]', false, null, $idShop);

    $realEvent = 'regtest578_real_event';
    $db->execute("DELETE FROM {$prefix}neria_webhook_queue WHERE event = '{$realEvent}'");

    try {
        $mgr = new WebhookManager(neria_test_module());
        // 'event' injecté dans $data avec une valeur DIFFÉRENTE de
        // l'événement réel déclenché — simule un futur appelant maladroit.
        $mgr->trigger($realEvent, ['event' => 'fake_injected_event', 'foo' => 'bar']);

        $row = $db->getRow(
            "SELECT event, payload FROM {$prefix}neria_webhook_queue WHERE id_shop = {$idShop} ORDER BY id_webhook DESC"
        );
        neria_assert($row !== false, "Aucune ligne insérée dans neria_webhook_queue — jeu de test invalide");
        neria_assert(
            $row['event'] === $realEvent,
            "La colonne event de neria_webhook_queue vaut '{$row['event']}' au lieu de '{$realEvent}' — jeu de test invalide (colonne event, pas le payload JSON)"
        );

        $decoded = json_decode($row['payload'], true);
        neria_assert(is_array($decoded), "Le payload webhook n'est pas un JSON valide — jeu de test invalide");
        neria_assert(
            $decoded['event'] === $realEvent,
            "Le payload webhook contient event='{$decoded['event']}' au lieu de '{$realEvent}' — régression du bug corrigé le 05/09/2026 (round 305) : une clé 'event' fournie dans \$data écraserait de nouveau silencieusement l'événement réel dans le payload envoyé au webhook externe"
        );
        neria_assert(
            $decoded['foo'] === 'bar',
            "Le payload webhook a perdu la clé 'foo' de \$data — le réordonnancement d'array_merge() ne doit affecter QUE les clés système en collision, pas les autres données"
        );

        return [
            'pass'    => true,
            'message' => "WebhookManager::trigger() préserve bien l'événement/boutique/horodatage RÉELS dans le payload, même si \$data contient par mégarde une clé de même nom — bug corrigé le 05/09/2026 (round 305)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_webhook_queue WHERE event = '{$realEvent}'");
        Configuration::updateValue('NERIA_WEBHOOK_URL', (string) $originalUrl, false, null, $idShop);
        Configuration::updateValue('NERIA_WEBHOOK_EVENTS', (string) $originalEvents, false, null, $idShop);
    }
}
