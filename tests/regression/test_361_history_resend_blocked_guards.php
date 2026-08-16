<?php
/**
 * Régression : CustomerEmailHistoryManager::resend() n'avait AUCUN
 * garde-fou bounce/blacklist/préférences/cooldown avant Mail::Send() —
 * même piège déjà corrigé pour ManualSendManager::send() (round 178, renvoi
 * manuel BO équivalent) mais jamais étendu à ce renvoi-ci. Mail::Send() du
 * cœur PrestaShop retourne TOUJOURS true quand le hook actionEmailSendBefore
 * annule l'envoi : un employé renvoyant un email à un client blacklisté
 * voyait "renvoyé avec succès" alors que rien n'était réellement reparti.
 *
 * Corrigé le 16/08/2026 (round 179, audit transversal de fin de série) :
 * les 4 mêmes garde-fous que ManualSendManager::send() ont été ajoutés.
 *
 * Test comportemental réel : pose une règle de blacklist sur un template de
 * test, insère une vraie ligne neria_stat 'sent' pour ce template/client,
 * appelle resend() — doit être bloqué (ok=false) AVANT tout appel
 * Mail::Send().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CustomerEmailHistoryManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/BlacklistManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;

    $customerRow = $db->getRow(
        "SELECT id_customer, email FROM {$prefix}customer WHERE active = 1 AND deleted = 0"
    );
    neria_assert($customerRow !== false, 'Aucun client actif trouvé — jeu de test invalide');
    $idCustomer = (int) $customerRow['id_customer'];

    $template = 'regtest361_' . substr(uniqid(), -8);
    $blMgr = new BlacklistManager($idShop);

    try {
        $blMgr->add($template, '');

        $db->execute(
            "INSERT INTO {$prefix}neria_stat
                (id_shop, template, lang, tracking_token, event_type, id_customer, date_add)
             VALUES ({$idShop}, '" . pSQL($template) . "', 'fr', '" . bin2hex(random_bytes(16)) . "', 'sent', {$idCustomer}, NOW())"
        );

        $idStat = (int) $db->getValue(
            "SELECT id_stat FROM {$prefix}neria_stat WHERE template = '" . pSQL($template) . "' AND id_customer = {$idCustomer} ORDER BY id_stat DESC"
        );
        neria_assert($idStat > 0, "Ligne neria_stat de test introuvable après insertion — jeu de test invalide");

        $mgr    = new CustomerEmailHistoryManager($module);
        $result = $mgr->resend($idStat, $idCustomer);

        neria_assert(
            $result['ok'] === false,
            "CustomerEmailHistoryManager::resend() n'est pas bloqué pour un template blacklisté — régression du bug corrigé le 16/08/2026 (round 179) : aucun garde-fou bounce/blacklist/préférences/cooldown n'était revérifié avant Mail::Send(), qui retourne toujours true même quand le hook bloque réellement l'envoi"
        );
        neria_assert(
            $result['message_key'] === 'history.resend_blocked',
            "CustomerEmailHistoryManager::resend() renvoie message_key='{$result['message_key']}' au lieu de 'history.resend_blocked'"
        );

        return [
            'pass'    => true,
            'message' => "CustomerEmailHistoryManager::resend() revérifie bien bounce/blacklist/préférences/cooldown avant Mail::Send() — bug corrigé le 16/08/2026 (round 179)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE template = '" . pSQL($template) . "' AND id_customer = {$idCustomer}");
        $rules = $blMgr->getAll();
        foreach ($rules as $rule) {
            if ($rule['template'] === $template) {
                $blMgr->remove((int) $rule['id_blacklist']);
            }
        }
    }
}
