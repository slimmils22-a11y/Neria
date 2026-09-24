<?php
/**
 * Régression (constat réel du 24/09/2026, P6 sur ps-test) : SegmentManager::preflightCheck() ne comptait pas
 * les adresses en rebond. Sur un segment de 3 destinataires dont 1 désabonné et 1 en rebond dur, le contrôle à
 * blanc annonçait « 1 client(s) sur 3 » ignoré alors que l'envoi réel en ignorait 2 (sendToSegment() écarte
 * les rebonds via explicitSendBlockReason()).
 *
 * Corrigé : le contrôle à blanc ajoute un avertissement non bloquant (msg.segment_some_bounced) avec le nombre
 * exact d'adresses en rebond parmi les destinataires autorisés.
 *
 * Test comportemental : client réel placé dans un segment, adresse enregistrée en rebond dur ; le message exact
 * attendu figure dans les avertissements, et disparaît quand le rebond est supprimé.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/SegmentManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/BounceManager.php';
    $module = neria_test_module();
    AdminTranslator::setLang('fr');

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) Context::getContext()->shop->id;
    $email      = (string) $db->getValue("SELECT email FROM {$prefix}customer WHERE id_customer = {$idCustomer}");
    neria_assert($email !== '', 'Client de test introuvable — jeu de test invalide');

    $mgr = new SegmentManager($module);
    try {
        $db->execute("DELETE FROM {$prefix}neria_customer_segment WHERE id_customer = {$idCustomer} AND id_shop = {$idShop}");
        $db->execute(
            "INSERT INTO {$prefix}neria_customer_segment (id_shop, id_customer, segment, total_sent, total_opens, total_clicks, total_conversions, computed_at)
             VALUES ({$idShop}, {$idCustomer}, 'ghost', 5, 3, 1, 0, NOW())"
        );

        $clean = $mgr->preflightCheck('ghost', 'win_back');
        neria_assert($clean['recipient_count'] > 0, "Le client de test n'apparaît pas dans le segment — jeu de test invalide");
        $marker = AdminTranslator::tVars('msg.segment_some_bounced', ['skipped' => 1, 'total' => $clean['recipient_count']]);
        neria_assert(!in_array($marker, $clean['issues'], true), "Avertissement de rebond affiché alors qu'aucune adresse n'est en rebond");

        (new BounceManager($module))->addManualBounce($email, 'hard');
        $after = $mgr->preflightCheck('ghost', 'win_back');
        neria_assert(
            in_array($marker, $after['issues'], true),
            "Le contrôle à blanc ne signale pas l'adresse en rebond (attendu « {$marker} », obtenu " . json_encode($after['issues'], JSON_UNESCAPED_UNICODE) . ") — régression du bug corrigé le 24/09/2026"
        );
        neria_assert($after['ok'] === true, "Un rebond ne doit pas rendre le contrôle à blanc bloquant (l'envoi continue pour les autres)");
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_bounces WHERE email = '" . pSQL(mb_strtolower($email)) . "'");
        $db->execute("DELETE FROM {$prefix}neria_customer_segment WHERE id_customer = {$idCustomer} AND id_shop = {$idShop}");
    }

    return [
        'pass'    => true,
        'message' => "Le contrôle à blanc d'une campagne segment signale les adresses en rebond avec le nombre exact, sans bloquer l'envoi — bug corrigé le 24/09/2026",
    ];
}
