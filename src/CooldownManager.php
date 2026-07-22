<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — CooldownManager
 *
 * Mode Silence V1 : détecte les doublons d'envoi (même template, même client,
 * dans une fenêtre de temps configurable) et bloque le second envoi.
 * Fonctionne en pure lecture sur ps_neria_stat — pas de nouvelle table.
 *
 * V2 prévue : regroupement en email digest (queue + cron + template digest).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class CooldownManager
{
    // Templates exemptés du cooldown (user-triggered ou critiques)
    const BYPASS_TEMPLATES = [
        'password_query',
        'password_reset',
        'contact',
        'guest_to_customer',
        'account',
        'account_guest',
        'neria_fallback',
    ];

    /** @var \Db */
    private \Db $db;

    public function __construct()
    {
        $this->db = \Db::getInstance();
    }

    /**
     * Vérifie si un doublon existe pour ce template + destinataire dans la
     * fenêtre de cooldown.
     *
     * Si $idOrder est fourni (> 0, ex. order_conf/payment/shipped...), le
     * doublon n'est détecté que pour LA MÊME commande — un deuxième email du
     * même template pour une commande DIFFÉRENTE (client qui repasse une
     * vraie commande dans la fenêtre de cooldown) n'est jamais bloqué. Sans
     * id_order (templates non liés à une commande), on retombe sur l'ancien
     * comportement (template + client + fenêtre), qui reste pertinent pour
     * ces cas-là.
     *
     * $idShop obligatoire : sans ce filtre, un client partagé entre
     * boutiques (compte mutualisé) recevant le même template sur DEUX
     * boutiques différentes dans la fenêtre de cooldown voit le second
     * envoi — pourtant légitime, une commande réelle sur une autre
     * boutique — silencieusement bloqué comme "doublon" de l'envoi de
     * la première boutique. Même raisonnement que le scope id_shop
     * déjà appliqué partout ailleurs dans BehavioralCronManager.
     *
     * @param string $toEmail       Adresse email du destinataire
     * @param string $template      Nom du template (ex. "order_conf")
     * @param int    $windowMinutes Durée de la fenêtre en minutes
     * @param int    $idShop        Boutique à l'origine de cet envoi
     * @param int    $idOrder       ID de la commande liée à cet envoi, si applicable (0 = non lié)
     * @return bool true = doublon détecté, bloquer l'envoi
     */
    public function isDuplicate(string $toEmail, string $template, int $windowMinutes, int $idShop, int $idOrder = 0): bool
    {
        if (in_array($template, self::BYPASS_TEMPLATES, true)) {
            return false;
        }

        $toEmail = strtolower(trim($toEmail));
        if ($toEmail === '' || !\Validate::isEmail($toEmail)) {
            return false;
        }

        $idCustomer = $this->resolveCustomerId($toEmail);
        if ($idCustomer <= 0) {
            return false; // invités : pas de cooldown
        }

        $orderCondition = $idOrder > 0
            ? ' AND `id_order` = ' . (int) $idOrder
            : '';

        $count = (int) $this->db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_stat`
             WHERE `id_customer` = ' . (int) $idCustomer . '
               AND `id_shop`     = ' . (int) $idShop . '
               AND `template`    = \'' . pSQL($template) . '\'
               AND `event_type`  = \'sent\'' . $orderCondition . '
               AND `date_add`    > DATE_SUB(NOW(), INTERVAL ' . (int) $windowMinutes . ' MINUTE)'
        );

        return $count > 0;
    }

    private function resolveCustomerId(string $email): int
    {
        $id = (int) \Customer::customerExists($email, true);
        return $id > 0 ? $id : 0;
    }
}
