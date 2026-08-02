<?php
/**
 * Régression : campagnes saisonnières ('christmas', 'halloween', 'valentine', 'ramadan', 'eid') ne doivent laisser
 * AUCUNE variable non résolue avec le jeu de variables réel construit par
 * SeasonalCampaignManager::run() (src/SeasonalCampaignManager.php, lu le
 * 02/08/2026) — toutes les campagnes saisonnières partagent EXACTEMENT le
 * même appel Mail::Send() avec le même jeu de vars, seul le nom du
 * template change ({$template = $campaign['template']}). Même principe
 * que test_23.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $row = $db->getRow("SELECT email, firstname, lastname, id_lang FROM {$prefix}customer WHERE id_customer={$idCustomer}");
    $idLang = (int) ($row['id_lang'] ?: Configuration::get('PS_LANG_DEFAULT'));
    $idShop = (int) Context::getContext()->shop->id;

    $startedAt = date('Y-m-d H:i:s');

    // Vars exactes fournies par SeasonalCampaignManager::run() au Mail::Send()
    $vars = [
        '{firstname}'     => $row['firstname'],
        '{lastname}'      => $row['lastname'],
        '{shop_name}'     => (string) Configuration::get('PS_SHOP_NAME'),
        '{shop_url}'      => 'https://example.test/',
        '{history_url}'   => 'https://example.test/historique',
        '{campaign_name}' => 'Campagne test',
        '{upsell_block}'  => '',
    ];

    $templates = [
        'christmas' => $vars,
        'halloween' => $vars,
        'valentine' => $vars,
        'ramadan' => $vars,
        'eid' => $vars,
    ];

    $failures = [];

    foreach ($templates as $template => $templateVars) {
        Mail::Send(
            $idLang,
            $template,
            'Test régression — ' . $template,
            $templateVars,
            (string) $row['email'],
            trim($row['firstname'] . ' ' . $row['lastname']),
            null, null, null, null,
            _PS_MODULE_DIR_ . 'neria/mails/',
            false,
            $idShop
        );

        $residual = $db->getRow(
            "SELECT message FROM {$prefix}neria_log
             WHERE class = 'EmailRenderer' AND template = '" . pSQL($template) . "'
               AND message LIKE '%residual_vars_stripped%'
               AND date_add >= '{$startedAt}'
             ORDER BY id_log DESC"
        );

        if ($residual) {
            $failures[] = "{$template}: " . $residual['message'];
        }
    }

    try {
        neria_assert(
            empty($failures),
            "Variable(s) non résolue(s) détectée(s) lors d'un envoi réaliste : " . implode(' | ', $failures)
        );

        return ['pass' => true, 'message' => 'christmas, halloween, valentine, ramadan, eid ne laissent aucune variable résiduelle avec un jeu de variables réaliste'];
    } finally {
        $db->execute(
            "DELETE FROM {$prefix}neria_log
             WHERE class = 'EmailRenderer' AND date_add >= '{$startedAt}'
               AND template IN ('christmas', 'halloween', 'valentine', 'ramadan', 'eid')"
        );
    }
}
