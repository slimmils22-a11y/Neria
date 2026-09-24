<?php
/**
 * Régression (constat réel du 24/09/2026, P6c — envoi manuel des 33 modèles sur ps-test, avertissements
 * « variables résiduelles supprimées » du Watchdog) : trois variables déclarées « automatiques » n'étaient
 * alimentées par aucun envoi :
 *   - {contact_url} : bouton de white_glove_apology, le lien partait vide (href="") ;
 *   - {carrier_name} : ligne de delivery_attempt_failed, valeur vide même avec une commande liée ;
 *   - {products} : corporate_order_confirm affichait son tableau (titre + en-têtes) sans aucune ligne.
 *
 * Corrigé : {contact_url} = page de contact de la boutique (sauf valeur explicite de l'appelant),
 * {carrier_name} = transporteur de la commande liée (envoi immédiat ET planifié), et le tableau produits de
 * corporate_order_confirm n'est rendu que s'il y a des produits.
 *
 * Test comportemental : rendu réel (processEmailParams) puis lecture du fichier compilé ; envoi planifié réel
 * d'un delivery_attempt_failed avec une vraie commande, lecture des variables mises en file.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php';
    $module = neria_test_module();
    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $idLang = (int) Language::getIdByIso('fr') ?: (int) Configuration::get('PS_LANG_DEFAULT');
    $iso = (string) Language::getIsoById($idLang);
    $renderer = new EmailRenderer($module);

    $render = static function (string $template, array $vars) use ($renderer, $idLang, $iso): string {
        $params = [
            'template' => $template, 'idLang' => $idLang, 'subject' => '',
            'to' => 'regtest851@example.com', 'toName' => 'Regtest',
            'templateVars' => $vars + ['{firstname}' => 'Test', '{lastname}' => 'Regtest', '{shop_name}' => 'Shop', '{shop_url}' => 'https://shop.example/', '{history_url}' => 'https://shop.example/h', '{order_name}' => 'REF851', '{id_order}' => 1, '{order_url}' => 'https://shop.example/o'],
        ];
        $renderer->processEmailParams($params);
        $file = _PS_MODULE_DIR_ . 'neria/mails/' . $iso . '/' . $params['template'] . '.html';
        $html = (string) @file_get_contents($file);
        @unlink($file);
        @unlink(preg_replace('/\.html$/', '.txt', $file));
        return $html;
    };

    // 1. {contact_url} : le bouton de white_glove_apology pointe sur la page de contact
    $contactUrl = (string) Context::getContext()->link->getPageLink('contact', true, $idLang);
    $html = $render('white_glove_apology', []);
    neria_assert($html !== '', 'Rendu de white_glove_apology vide');
    neria_assert(strpos($html, 'href=""') === false, "white_glove_apology contient un lien vide (href=\"\") — {contact_url} n'est plus alimenté — régression du bug corrigé le 24/09/2026");
    // Les liens sont enveloppés par le suivi des clics : l'adresse cible peut apparaître encodée
    $hasUrl = static function (string $h, string $url): bool {
        return strpos($h, $url) !== false || strpos($h, rawurlencode($url)) !== false || strpos($h, urlencode($url)) !== false;
    };
    neria_assert($hasUrl($html, $contactUrl), "Le bouton de white_glove_apology ne pointe pas sur la page de contact ({$contactUrl})");
    $htmlExplicit = $render('white_glove_apology', ['{contact_url}' => 'https://shop.example/mon-contact']);
    neria_assert($hasUrl($htmlExplicit, 'https://shop.example/mon-contact') && !$hasUrl($htmlExplicit, $contactUrl), "Une valeur explicite de {contact_url} est écrasée par la valeur par défaut");

    // 2. corporate_order_confirm : pas de tableau vide sans produits, tableau conservé avec produits
    $noProducts = $render('corporate_order_confirm', []);
    neria_assert($noProducts !== '' && strpos($noProducts, '<table class="neria-products-table"') === false, "corporate_order_confirm affiche un tableau produits vide sans aucun produit — régression du bug corrigé le 24/09/2026");
    $row = '<tr><td style="border-bottom:1px solid #ddd"><table><tr><td>REF-1</td></tr></table></td><td style="border-bottom:1px solid #ddd"><table><tr><td>Produit</td></tr></table></td><td style="border-bottom:1px solid #ddd"><table><tr><td>10,00 €</td></tr></table></td><td style="border-bottom:1px solid #ddd"><table><tr><td>1</td></tr></table></td><td style="border-bottom:1px solid #ddd"><table><tr><td>10,00 €</td></tr></table></td></tr>';
    $withProducts = $render('corporate_order_confirm', ['{products}' => $row]);
    neria_assert(strpos($withProducts, '<table class="neria-products-table"') !== false, "corporate_order_confirm ne rend plus son tableau quand des produits sont fournis");

    // 3. {carrier_name} : envoi planifié d'un delivery_attempt_failed avec une vraie commande
    $order = $db->getRow("SELECT o.reference, c.name AS carrier FROM {$prefix}orders o INNER JOIN {$prefix}carrier c ON c.id_carrier = o.id_carrier WHERE c.name <> '' ORDER BY o.id_order DESC");
    neria_assert(is_array($order) && !empty($order['reference']), "Jeu de test invalide : aucune commande avec transporteur nommé");
    $email = 'regtest851-' . uniqid() . '@example.com';
    $mgr = new ManualSendManager($module);
    $res = $mgr->scheduleManual('delivery_attempt_failed', $email, (string) $order['reference'], '', ['pickup_point_address' => 'Point relais test'], date('Y-m-d H:i:s', strtotime('+1 day')));
    try {
        neria_assert(($res['ok'] ?? false) === true, "scheduleManual() refuse un delivery_attempt_failed valide : " . json_encode($res, JSON_UNESCAPED_UNICODE));
        $json = (string) $db->getValue("SELECT vars_json FROM {$prefix}neria_queue WHERE recipient_email = '" . pSQL($email) . "'", false);
        $vars = json_decode($json, true);
        neria_assert(is_array($vars), 'Variables mises en file illisibles');
        neria_assert(
            ($vars['{carrier_name}'] ?? '') === $order['carrier'],
            "{carrier_name} vaut « " . ($vars['{carrier_name}'] ?? '(absent)') . " » au lieu du transporteur « {$order['carrier']} » de la commande — régression du bug corrigé le 24/09/2026"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_queue WHERE recipient_email = '" . pSQL($email) . "'");
    }

    return [
        'pass'    => true,
        'message' => "white_glove_apology pointe sur la page de contact, delivery_attempt_failed reçoit le transporteur de la commande, corporate_order_confirm n'affiche plus de tableau produits vide — bug corrigé le 24/09/2026",
    ];
}
