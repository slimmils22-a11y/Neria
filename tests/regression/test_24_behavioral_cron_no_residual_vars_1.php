<?php
/**
 * Régression : birthday, first_anniversary, reorder_reminder, win_back,
 * loyalty_reward_expiry ne doivent laisser AUCUNE variable non résolue
 * quand elles sont envoyées avec le jeu de variables réel que fournit
 * BehavioralCronManager (méthode privée send(), vars communes {firstname}/
 * {lastname}/{shop_name}/{history_url} + extraVars propres à chaque
 * sendXxx(), lues dans src/BehavioralCronManager.php le 02/08/2026).
 *
 * Même principe que test_23 : envoi réel via Mail::Send() puis vérification
 * qu'aucune ligne watchdog.residual_vars_stripped n'apparaît dans
 * ps_neria_log pour class='EmailRenderer' et le template concerné.
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

    // Vars communes fournies par BehavioralCronManager::send() à TOUS les templates.
    $common = [
        '{firstname}'   => $row['firstname'],
        '{lastname}'    => $row['lastname'],
        '{shop_name}'   => (string) Configuration::get('PS_SHOP_NAME'),
        '{history_url}' => 'https://example.test/historique',
    ];

    $templates = [
        // sendBirthdays()
        'birthday' => array_merge($common, [
            '{voucher_code}' => 'NERIA-BDAY-TEST99',
            '{shop_url}'     => 'https://example.test/',
        ]),
        // sendFirstAnniversaries() — extraVars vide, seulement $common
        'first_anniversary' => $common,
        // sendReorderReminders()
        'reorder_reminder' => array_merge($common, [
            '{product_name}' => 'Sac Camille',
            '{shop_url}'     => 'https://example.test/',
        ]),
        // sendWinBacks()
        'win_back' => array_merge($common, [
            '{shop_url}' => 'https://example.test/',
        ]),
        // sendRewardExpiryAlerts()
        'loyalty_reward_expiry' => array_merge($common, [
            '{reward_expiry_date}' => '15 août 2026',
            '{history_url}'        => 'https://example.test/historique',
        ]),
    ];

    $failures = [];

    foreach ($templates as $template => $vars) {
        Mail::Send(
            $idLang,
            $template,
            'Test régression — ' . $template,
            $vars,
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

        return ['pass' => true, 'message' => 'birthday/first_anniversary/reorder_reminder/win_back/loyalty_reward_expiry ne laissent aucune variable résiduelle avec un jeu de variables réaliste'];
    } finally {
        $db->execute(
            "DELETE FROM {$prefix}neria_log
             WHERE class = 'EmailRenderer' AND date_add >= '{$startedAt}'
               AND template IN ('birthday','first_anniversary','reorder_reminder','win_back','loyalty_reward_expiry')"
        );
    }
}
