<?php
/**
 * Régression : ManualSendManager::scheduleManual() reprenait fidèlement
 * TOUS les autres garde-fous de send() (bounce, contexte commande,
 * blacklist, préférences, variables personnalisées manquantes, conflit
 * anniversaire) — y compris le raisonnement explicite du round 178 sur le
 * garde-fou préférences ("le marchand doit savoir dès la PLANIFICATION que
 * son envoi ne partira jamais réellement le jour J") — mais n'appelait
 * jamais CooldownManager::isDuplicate(), contrairement à send() (round
 * 178, test_355). Un marchand planifiant un envoi manuel pour un
 * destinataire déjà servi dans la fenêtre de cooldown voyait la
 * planification acceptée avec succès, puis QueueManager::processQueue()
 * bloquait silencieusement l'envoi réel le jour J via le hook central —
 * sans que le marchand n'ait jamais été prévenu au moment où il aurait pu
 * agir différemment.
 *
 * Corrigé le 06/09/2026 (round 308) : même garde-fou Cooldown explicite
 * que send(), ajouté juste après le garde-fou préférences.
 *
 * Test comportemental réel : active le Mode Silence, insère une ligne
 * neria_stat 'sent' réelle dans la fenêtre de cooldown pour un client
 * existant + template de test, puis appelle scheduleManual() pour ce même
 * client/template avec une date future valide — doit être refusé (ok=false,
 * message cooldown), pas silencieusement accepté dans la queue.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();

    $customerRow = $db->getRow(
        "SELECT id_customer, email FROM {$prefix}customer WHERE active = 1 AND deleted = 0"
    );
    neria_assert($customerRow !== false, 'Aucun client actif trouvé — jeu de test invalide');
    $email = (string) $customerRow['email'];

    $config = new ConfigManager($module);
    $originalEnabled = Configuration::get(ConfigManager::KEY_COOLDOWN_ENABLED);
    $originalMinutes = Configuration::get(ConfigManager::KEY_COOLDOWN_MINUTES);
    $template = 'vip'; // template réel de WAVE1_TEMPLATES (isSendable() === true)

    try {
        $config->set(ConfigManager::KEY_COOLDOWN_ENABLED, 1);
        $config->set(ConfigManager::KEY_COOLDOWN_MINUTES, 60);

        $db->execute(
            "INSERT INTO {$prefix}neria_stat
                (id_shop, template, lang, tracking_token, event_type, id_customer, date_add)
             VALUES
                (" . (int) Context::getContext()->shop->id . ", '" . pSQL($template) . "', 'fr', '" . bin2hex(random_bytes(16)) . "', 'sent', " . (int) $customerRow['id_customer'] . ", NOW())"
        );

        $sendAt = (new DateTime('+2 days'))->format('Y-m-d H:i:s');
        $mgr    = new ManualSendManager($module);
        $result = $mgr->scheduleManual($template, $email, '', '', [], $sendAt);

        // Assertion précise sur le CONTENU du message (pas seulement
        // ok===false) : scheduleManual() a plusieurs autres garde-fous
        // (bounce, commande, blacklist, préférences, variables manquantes)
        // qui peuvent aussi renvoyer ok=false pour une tout autre raison —
        // seul le message de blocage cooldown prouve que c'est bien CE
        // garde-fou précis qui a été atteint et déclenché.
        neria_assert(
            $result['ok'] === false && str_contains((string) $result['message'], 'Mode Silence'),
            "ManualSendManager::scheduleManual() n'est pas bloqué par le Mode Silence alors qu'un envoi identique existe dans la fenêtre de cooldown (message obtenu : '" . ($result['message'] ?? '?') . "') — régression du bug corrigé le 06/09/2026 (round 308) : CooldownManager n'était jamais revérifié à la planification, contrairement à send() (round 178)"
        );

        return [
            'pass'    => true,
            'message' => "ManualSendManager::scheduleManual() revérifie bien le Mode Silence (CooldownManager) à la planification, même garde-fou que send() (round 178) — bug corrigé le 06/09/2026 (round 308)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE template = '" . pSQL($template) . "' AND id_customer = " . (int) $customerRow['id_customer'] . " AND date_add >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
        $db->execute("DELETE FROM {$prefix}neria_queue WHERE template = '" . pSQL($template) . "' AND recipient_email = '" . pSQL($email) . "' AND ref_id = 0");
        if ($originalEnabled !== false && $originalEnabled !== null) {
            Configuration::updateValue(ConfigManager::KEY_COOLDOWN_ENABLED, $originalEnabled);
        }
        if ($originalMinutes !== false && $originalMinutes !== null) {
            Configuration::updateValue(ConfigManager::KEY_COOLDOWN_MINUTES, $originalMinutes);
        }
    }
}
