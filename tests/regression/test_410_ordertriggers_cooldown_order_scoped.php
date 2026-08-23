<?php
/**
 * Régression : OrderTriggersManager::explicitSendBlockReason() appelait
 * CooldownManager::isDuplicate() SANS $idOrder/$refScope — un pré-contrôle
 * NON scopé par commande, alors que le vrai contrôle exécuté au moment de
 * Mail::Send() (hook actionEmailSendBefore, neria.php) EST scopé par
 * commande via {id_order}/{cooldown_scope}, injectés dans les vars juste
 * après chaque appel à cette méthode.
 *
 * Bug réel identifié le 23/08/2026 (round 192) : un client avec 2 commandes
 * déclenchant le même template dans la même fenêtre de cooldown (ex.
 * expédition partielle de 2 commandes lors d'un traitement d'entrepôt
 * groupé) voyait la 2e commande bloquée à tort par ce pré-contrôle — il
 * matchait l'envoi de la 1re commande (même client+template+boutique, sans
 * filtre commande) — alors que le vrai hook, scopé par commande, l'aurait
 * autorisée. Le client ne recevait jamais la notification légitime de la
 * 2e commande.
 *
 * Corrigé le 23/08/2026 (round 192) : $idOrder transmis à
 * explicitSendBlockReason() (nouveau paramètre optionnel) et à
 * isDuplicate().
 *
 * Test comportemental réel : seed un événement 'sent' en base pour le
 * template order_partial_shipped, commande A, dans la fenêtre de cooldown.
 * explicitSendBlockReason() appelée pour la commande B (même client/
 * template/boutique) ne doit PAS retourner 'cooldown'.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/OrderTriggersManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;
    $idCustomer = neria_test_any_customer_id();

    neria_assert($idCustomer > 0, 'jeu de test invalide : aucun client actif trouvé');

    $email = 'client.round192@example.test';
    $template = 'order_partial_shipped';
    $idOrderA = 8880001;
    $idOrderB = 8880002;
    $tokenA = bin2hex(random_bytes(16));

    Configuration::updateGlobalValue('NERIA_COOLDOWN_ENABLED', 1);
    Configuration::updateGlobalValue('NERIA_COOLDOWN_MINUTES', 60);

    $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token = '" . pSQL($tokenA) . "'");

    try {
        // Envoi 'sent' déjà enregistré pour la commande A, scopé par
        // id_order = A, il y a 5 minutes (dans la fenêtre de 60 minutes).
        $db->execute(
            "INSERT INTO {$prefix}neria_stat
                (id_shop, template, lang, id_customer, id_order, tracking_token, event_type, date_add)
             VALUES
                ({$idShop}, '" . pSQL($template) . "', 'fr', {$idCustomer}, {$idOrderA}, '" . pSQL($tokenA) . "', 'sent', DATE_SUB(NOW(), INTERVAL 5 MINUTE))"
        );

        $mgr = new OrderTriggersManager($module);
        $ref = new ReflectionMethod(OrderTriggersManager::class, 'explicitSendBlockReason');
        $ref->setAccessible(true);

        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $reason = $ref->invoke($mgr, $template, $email, $idCustomer, $idShop, $idLang, $idOrderB);

        neria_assert(
            $reason === null,
            "explicitSendBlockReason() pour la commande B retourne '{$reason}' (bloquée) à cause d'un envoi 'sent' enregistré pour une commande A DIFFÉRENTE du même client — régression du bug corrigé le 23/08/2026 (round 192) : le pré-contrôle cooldown redeviendrait non scopé par commande, bloquant à tort des notifications légitimes"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token = '" . pSQL($tokenA) . "'");
    }

    return [
        'pass'    => true,
        'message' => "OrderTriggersManager::explicitSendBlockReason() scope bien son pré-contrôle cooldown par commande (\$idOrder) — bug corrigé le 23/08/2026 (round 192)",
    ];
}
