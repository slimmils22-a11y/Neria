<?php
/**
 * Régression (P10, 25/09/2026, constatée sur ps-test en multi-boutique réel) : la file d'envoi traite en un
 * seul passage les lignes de TOUTES les boutiques sous le contexte ambiant de la première. Pour un client de
 * la boutique 2, la ligne « envoyé » de neria_stat était enregistrée id_shop = 1 (contexte ambiant) et
 * id_customer = 0 (Customer::customerExists() cherche dans la boutique ambiante) : statistiques attribuées à
 * la mauvaise boutique, historique client vide, ouvertures/clics rattachés à personne.
 *
 * Corrigé : StatsManager::recordSent() utilise $params['idShop'] (toujours fourni par Mail::Send()) pour
 * id_shop et pour retrouver le client ; les ouvertures et clics héritent de l'id_shop de la ligne « envoyé ».
 *
 * Test comportemental : deux comptes de même adresse dans deux boutiques différentes ; l'envoi « boutique 2 »
 * doit être rattaché au compte de la boutique 2 avec id_shop = 2, l'ouverture qui suit hérite de id_shop = 2.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $module = neria_test_module();
    $db = neria_test_db();
    $p = neria_test_prefix();
    $ambient = (int) Context::getContext()->shop->id;
    $otherShop = $ambient + 41;
    $email = 'regtest864-' . uniqid() . '@example.com';
    $token = 't864' . bin2hex(random_bytes(6));
    $ids = [];

    try {
        foreach ([$ambient, $otherShop] as $shop) {
            $c = new Customer();
            $c->email = $email;
            $c->firstname = 'Regtest';
            $c->lastname = 'Testeur';
            $c->passwd = Tools::hash('Regtest864!');
            $c->id_shop = $shop;
            $c->id_shop_group = 1;
            $c->id_lang = 1;
            $c->active = 1;
            neria_assert($c->add(true, false), "Création du compte de test (boutique {$shop}) impossible");
            $ids[$shop] = (int) $c->id;
        }

        $oldUrl = Configuration::getGlobalValue('NERIA_WEBHOOK_URL');
        $oldEvents = Configuration::getGlobalValue('NERIA_WEBHOOK_EVENTS');
        Configuration::updateGlobalValue('NERIA_WEBHOOK_URL', 'https://example.com/regtest864');
        Configuration::updateGlobalValue('NERIA_WEBHOOK_EVENTS', '["email_sent"]');
        $db->execute("DELETE FROM {$p}neria_webhook_queue WHERE payload LIKE '%" . pSQL($token) . "%'");

        $mgr = new StatsManager($module);
        $mgr->recordSent([
            'to' => $email, 'neria_template' => 'vip', 'neria_lang' => 'fr', 'neria_token' => $token,
            'idShop' => $otherShop, 'templateVars' => ['{firstname}' => 'Regtest'],
        ]);
        $row = $db->getRow("SELECT id_shop, id_customer FROM {$p}neria_stat WHERE tracking_token = '" . pSQL($token) . "' AND event_type = 'sent'");
        neria_assert(is_array($row), "Aucune ligne « envoyé » enregistrée");
        neria_assert((int) $row['id_shop'] === $otherShop, "id_shop enregistré = {$row['id_shop']} au lieu de {$otherShop} (boutique de l'envoi) — régression du correctif P10");
        neria_assert((int) $row['id_customer'] === $ids[$otherShop], "id_customer enregistré = {$row['id_customer']} au lieu du compte de la boutique {$otherShop} ({$ids[$otherShop]})");

        $hook = $db->getRow("SELECT id_shop FROM {$p}neria_webhook_queue WHERE payload LIKE '%" . pSQL($token) . "%'");
        neria_assert(is_array($hook) && (int) $hook['id_shop'] === $otherShop, "Le webhook « email_sent » n'est pas rattaché à la boutique de l'envoi : " . json_encode($hook));

        $mgr->recordOpen($token);
        $open = $db->getRow("SELECT id_shop, id_customer FROM {$p}neria_stat WHERE tracking_token = '" . pSQL($token) . "' AND event_type = 'open'");
        neria_assert(is_array($open) && (int) $open['id_shop'] === $otherShop, "L'ouverture n'hérite pas de la boutique de l'envoi : " . json_encode($open));

        // Sans idShop transmis : repli sur la boutique ambiante (comportement historique).
        $token2 = 't864' . bin2hex(random_bytes(6));
        $mgr->recordSent(['to' => $email, 'neria_template' => 'vip', 'neria_lang' => 'fr', 'neria_token' => $token2, 'templateVars' => []]);
        $row2 = $db->getRow("SELECT id_shop, id_customer FROM {$p}neria_stat WHERE tracking_token = '" . pSQL($token2) . "'");
        neria_assert((int) $row2['id_shop'] === $ambient && (int) $row2['id_customer'] === $ids[$ambient], "Repli sur la boutique ambiante cassé : " . json_encode($row2));
    } finally {
        if (isset($oldUrl)) {
            Configuration::updateGlobalValue('NERIA_WEBHOOK_URL', $oldUrl);
            Configuration::updateGlobalValue('NERIA_WEBHOOK_EVENTS', $oldEvents);
        }
        $db->execute("DELETE FROM {$p}neria_webhook_queue WHERE payload LIKE '%t864%'");
        $db->execute("DELETE FROM {$p}neria_stat WHERE tracking_token LIKE 't864%'");
        foreach ($ids as $id) {
            $db->execute("DELETE FROM {$p}customer WHERE id_customer = " . (int) $id);
        }
    }

    return [
        'pass'    => true,
        'message' => "La ligne « envoyé » (et l'ouverture qui suit) porte la boutique réelle de l'envoi et le compte client de CETTE boutique — corrigé le 25/09/2026 (P10)",
    ];
}
