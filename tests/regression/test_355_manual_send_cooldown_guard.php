<?php
/**
 * Régression : ManualSendManager::send() revérifie explicitement bounce/
 * blacklist/préférences avant Mail::Send() — précisément parce que
 * Mail::Send() du cœur PrestaShop retourne TOUJOURS true quand le hook
 * actionEmailSendBefore annule l'envoi — mais oubliait CooldownManager
 * (Mode Silence). Un marchand envoyant manuellement un template déjà parti
 * pour ce même destinataire dans la fenêtre de cooldown voyait "email
 * envoyé avec succès" alors que le hook le bloquait silencieusement — et
 * pour first_anniversary/relationship_anniversary, une ligne
 * neria_behavioral_sent était quand même insérée comme si l'envoi avait
 * réellement eu lieu.
 *
 * Corrigé le 16/08/2026 (round 178) : un garde-fou Cooldown explicite a été
 * ajouté à send(), symétrique aux gardes bounce/blacklist/préférences déjà
 * en place.
 *
 * Test comportemental réel : active le Mode Silence, insère une ligne
 * neria_stat 'sent' réelle pour un client existant + template de test dans
 * la fenêtre de cooldown, puis appelle ManualSendManager::send() pour ce
 * même client/template — doit être bloqué AVANT tout appel Mail::Send()
 * (ok=false, message cooldown), pas un faux "envoyé".
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
    // Round 308 : 'return_slip' remplacé par 'vip' — 'return_slip' n'a
    // jamais fait partie de ManualSendManager::WAVE1_TEMPLATES (isSendable()
    // le rejette AVANT d'atteindre le garde-fou cooldown), donc ce test
    // passait bien à cause du message "Template non autorisé", jamais
    // grâce au garde-fou round 178 qu'il est censé vérifier — découvert en
    // vérifiant la falsifiabilité du garde-fou symétrique de
    // scheduleManual() (round 308, test_584) : désactiver le garde-fou
    // cooldown ne faisait PAS échouer ce test.
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

        $mgr    = new ManualSendManager($module);
        $result = $mgr->send($template, $email, '', '', []);

        // Assertion précise sur le CONTENU du message (pas seulement
        // ok===false) : send() a plusieurs autres garde-fous (bounce,
        // blacklist, préférences, isSendable()) qui peuvent aussi renvoyer
        // ok=false pour une tout autre raison — seul le message de blocage
        // cooldown prouve que c'est bien CE garde-fou précis qui a été
        // atteint et déclenché.
        neria_assert(
            $result['ok'] === false && str_contains((string) $result['message'], 'Mode Silence'),
            "ManualSendManager::send() n'est pas bloqué par le Mode Silence alors qu'un envoi identique existe dans la fenêtre de cooldown (message obtenu : '" . ($result['message'] ?? '?') . "') — régression du bug corrigé le 16/08/2026 (round 178) : CooldownManager n'était jamais revérifié avant Mail::Send(), qui retourne toujours true même quand le hook bloque réellement l'envoi"
        );

        return [
            'pass'    => true,
            'message' => "ManualSendManager::send() revérifie bien le Mode Silence (CooldownManager) avant Mail::Send() — bug corrigé le 16/08/2026 (round 178)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE template = '" . pSQL($template) . "' AND id_customer = " . (int) $customerRow['id_customer'] . " AND date_add >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
        if ($originalEnabled !== false && $originalEnabled !== null) {
            Configuration::updateValue(ConfigManager::KEY_COOLDOWN_ENABLED, $originalEnabled);
        }
        if ($originalMinutes !== false && $originalMinutes !== null) {
            Configuration::updateValue(ConfigManager::KEY_COOLDOWN_MINUTES, $originalMinutes);
        }
    }
}
