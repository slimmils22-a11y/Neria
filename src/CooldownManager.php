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
     * vraie commande dans la fenêtre de cooldown) n'est jamais bloqué.
     *
     * Pour les templates non liés à une commande mais tout de même scopés
     * par entité (ex. waitlist_available par produit, collection_completion
     * par collection), $refScope joue le même rôle qu'$idOrder : sans lui,
     * deux notifications légitimes sur deux entités différentes du même
     * client dans la fenêtre de cooldown se bloquaient mutuellement à tort.
     * $idOrder est prioritaire s'il est fourni (les deux ne sont normalement
     * jamais renseignés en même temps).
     *
     * Sans $idOrder NI $refScope, on retombe sur l'ancien comportement
     * (template + client + fenêtre), qui reste pertinent pour ces cas-là.
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
     * @param string $refScope      Portée générique (ex. "product:123"), si applicable et sans idOrder
     * @return bool true = doublon détecté, bloquer l'envoi
     */
    public function isDuplicate(string $toEmail, string $template, int $windowMinutes, int $idShop, int $idOrder = 0, string $refScope = ''): bool
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

        if ($idOrder > 0) {
            $scopeCondition = ' AND `id_order` = ' . (int) $idOrder;
        } elseif ($refScope !== '') {
            $scopeCondition = ' AND `ref_scope` = \'' . pSQL($refScope) . '\'';
        } else {
            $scopeCondition = '';
        }

        $count = (int) $this->db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_stat`
             WHERE `id_customer` = ' . (int) $idCustomer . '
               AND `id_shop`     = ' . (int) $idShop . '
               AND `template`    = \'' . pSQL($template) . '\'
               AND `event_type`  = \'sent\'' . $scopeCondition . '
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
