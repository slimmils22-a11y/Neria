<?php
/**
 * Régression (décision du 24/09/2026 après P6c) : delivery_attempt_failed affichait « État du colis : <nom du
 * transporteur> » — libellé et valeur incohérents (constat réel à l'envoi manuel sur ps-test).
 *
 * Corrigé (option B) : l'état du colis est un champ éditable saisi par l'opérateur ({delivery_status}), le
 * transporteur de la commande a sa propre ligne « Transporteur : … » (nouvelle clé delivery_attempt_carrier,
 * 19 langues), masquée quand aucun transporteur n'est connu.
 *
 * Test comportemental : champs éditables du modèle, rendu réel HTML et texte avec/sans transporteur, envoi
 * planifié refusé si l'état du colis n'est pas saisi, présence de la clé dans les 19 langues du fichier de données.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationInstaller.php';
    $module = neria_test_module();
    AdminTranslator::setLang('fr');
    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $template = 'delivery_attempt_failed';

    // Clé présente dans les 19 langues du fichier de données
    $data = json_decode((string) file_get_contents(_PS_MODULE_DIR_ . 'neria/data/translations.json'), true);
    neria_assert(is_array($data) && isset($data[$template]), 'translations.json illisible');
    neria_assert(count($data[$template]) === 19, 'Le modèle ne compte pas 19 langues');
    foreach ($data[$template] as $iso => $keys) {
        neria_assert(!empty($keys['delivery_attempt_carrier']), "Clé delivery_attempt_carrier absente en « {$iso} » — régression de la décision du 24/09/2026");
    }
    (new TranslationInstaller($module))->importTemplate(_PS_MODULE_DIR_ . 'neria/data/translations.json', $template);

    // Champs éditables
    $mgr = new ManualSendManager($module);
    $editable = $mgr->getEditableVars($template);
    neria_assert(in_array('delivery_status', $editable, true) && in_array('pickup_point_address', $editable, true), 'Champs éditables : ' . json_encode($editable));
    neria_assert(!in_array('carrier_name', $editable, true), '{carrier_name} ne doit pas être demandé à l\'opérateur (variable automatique)');

    // Rendu réel
    $renderer = new EmailRenderer($module);
    $idLang = (int) Language::getIdByIso('fr') ?: (int) Configuration::get('PS_LANG_DEFAULT');
    $iso = (string) Language::getIsoById($idLang);
    $render = static function (array $vars) use ($renderer, $idLang, $iso, $template): array {
        $params = [
            'template' => $template, 'idLang' => $idLang, 'subject' => '', 'to' => 'regtest854@example.com', 'toName' => 'Regtest',
            'templateVars' => $vars + ['{firstname}' => 'Test', '{lastname}' => 'Regtest', '{shop_name}' => 'Shop', '{shop_url}' => 'https://shop.example/', '{history_url}' => 'https://shop.example/h', '{order_name}' => 'REF854', '{pickup_point_address}' => 'Relais 854'],
        ];
        $renderer->processEmailParams($params);
        $file = _PS_MODULE_DIR_ . 'neria/mails/' . $iso . '/' . $params['template'] . '.html';
        $out = [(string) @file_get_contents($file), (string) @file_get_contents(preg_replace('/\.html$/', '.txt', $file))];
        @unlink($file);
        @unlink(preg_replace('/\.html$/', '.txt', $file));
        return $out;
    };
    [$html, $txt] = $render(['{delivery_status}' => 'STATUT-854', '{carrier_name}' => 'Transporteur854']);
    foreach (['html' => $html, 'texte' => $txt] as $kind => $body) {
        neria_assert(strpos($body, 'STATUT-854') !== false, "Version {$kind} : l'état du colis saisi est absent");
        neria_assert(strpos($body, 'Transporteur854') !== false, "Version {$kind} : le transporteur est absent");
        neria_assert(strpos($body, 'Transporteur :') !== false, "Version {$kind} : le libellé « Transporteur : » est absent");
    }
    neria_assert(preg_match('/État du colis[^<\n]*:[^<\n]*STATUT-854/u', strip_tags($html)) === 1, "L'état du colis n'est plus rendu sous son libellé « État du colis »");
    [$htmlNo, $txtNo] = $render(['{delivery_status}' => 'STATUT-854', '{carrier_name}' => '']);
    neria_assert(strpos($htmlNo, 'Transporteur :') === false && strpos($txtNo, 'Transporteur :') === false, 'La ligne transporteur est affichée sans transporteur');

    // Envoi planifié : état du colis obligatoire
    $order = $db->getRow("SELECT reference FROM {$prefix}orders ORDER BY id_order DESC");
    neria_assert(is_array($order) && !empty($order['reference']), 'Jeu de test invalide : aucune commande');
    $email = 'regtest854-' . uniqid() . '@example.com';
    $res = $mgr->scheduleManual($template, $email, (string) $order['reference'], '', ['pickup_point_address' => 'Relais'], date('Y-m-d H:i:s', strtotime('+1 day')));
    neria_assert(($res['ok'] ?? true) === false && strpos((string) ($res['message'] ?? ''), 'delivery_status') !== false, "L'envoi sans état du colis n'est pas refusé : " . json_encode($res, JSON_UNESCAPED_UNICODE));

    return [
        'pass'    => true,
        'message' => "delivery_attempt_failed : l'état du colis est saisi par l'opérateur (obligatoire), le transporteur a sa propre ligne « Transporteur : » (masquée sans transporteur), clé traduite en 19 langues — décision du 24/09/2026",
    ];
}
