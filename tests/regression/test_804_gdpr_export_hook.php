<?php
/**
 * Bloc 6 (19/09/2026) : Neria n'était pas branché sur `actionExportGDPRData`,
 * le crochet du module RGPD de PrestaShop (psgdpr) pour le DROIT D'ACCÈS et la
 * PORTABILITÉ (art. 15 et 20). Une demande d'accès d'un client n'incluait donc
 * rien de ce que le module conserve à son sujet (historique d'envois avec
 * instantanés de variables, préférences, points de fidélité, certificats,
 * adresses en rebond…), alors que le tableau de bord RGPD annonce « conforme ».
 *
 * Corrigé : hookActionExportGDPRData() → GdprAuditManager::exportCustomerData().
 *
 * Test comportemental réel : données créées pour un client fictif ET pour un
 * autre client ; le crochet réel ne renvoie QUE celles du bon client, déchiffre
 * l'instantané, retire les secrets (jeton de suivi) ; et, si psgdpr est
 * installé, l'export de psgdpr contient bien une section Neria.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/GdprAuditManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/CryptoManager.php';
    $module = neria_test_module();
    $db     = neria_test_db();
    $p      = neria_test_prefix();

    $idA = 987654;
    $idB = 987655;
    $mail = 'regtest804@example.com';
    $tokA = 'regtest804-token-a';
    $tokB = 'regtest804-token-b';

    $cleanup = function () use ($db, $p, $idA, $idB, $mail): void {
        $db->execute("DELETE FROM {$p}neria_stat WHERE id_customer IN ({$idA},{$idB})");
        $db->execute("DELETE FROM {$p}neria_loyalty_points WHERE id_customer IN ({$idA},{$idB})");
        $db->execute("DELETE FROM {$p}neria_preferences WHERE email = '{$mail}'");
        $db->execute("DELETE FROM {$p}neria_bounces WHERE email = '{$mail}'");
    };
    $cleanup();

    try {
        $shop = (int) \Context::getContext()->shop->id;
        $snap = \CryptoManager::encrypt((string) json_encode(['{firstname}' => 'Alice', '{email}' => $mail]));
        foreach ([[$idA, $tokA, $snap], [$idB, $tokB, '']] as [$id, $tok, $vars]) {
            $db->execute("INSERT INTO {$p}neria_stat
                (id_shop, template, lang, country_code, id_customer, id_order, ref_scope, tracking_token, event_type, is_mpp, abtest_variant, rendered_vars, revenue, ip_address, user_agent, date_add)
                VALUES ({$shop}, 'regtest804', 'en', '', {$id}, 0, '', '" . pSQL($tok) . "', 'send', 0, '', '" . pSQL($vars) . "', 0, '', '', NOW())");
            $db->execute("INSERT INTO {$p}neria_loyalty_points (id_customer, id_stat, event_type, points, id_shop, date_add)
                          VALUES ({$id}, {$id}, 'open', 7, {$shop}, NOW())");
        }
        $db->execute("INSERT INTO {$p}neria_preferences (id_shop, id_customer, email, category, subscribed, date_upd)
                      VALUES ({$shop}, 0, '{$mail}', 'loyalty', 0, NOW())");
        $db->execute("INSERT INTO {$p}neria_bounces (email, id_shop, type, reason, source, bounce_count, last_bounce_at, status, date_add)
                      VALUES ('{$mail}', {$shop}, 'hard', 'regtest804', 'manual', 1, NOW(), 'active', NOW())");

        // 1) Le crochet doit être déclaré (installation neuve) ET enregistré (ce shop).
        $src = (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
        neria_assert(strpos($src, "'actionExportGDPRData',") !== false, "actionExportGDPRData absent de la liste des crochets installés (Neria::HOOKS)");
        neria_assert(method_exists($module, 'hookActionExportGDPRData'), "hookActionExportGDPRData() absent");

        // 2) Export réel pour le client A.
        $json = $module->hookActionExportGDPRData(['id' => $idA, 'email' => $mail, 'id_shop' => $shop]);
        $data = json_decode($json, true);
        neria_assert(is_array($data), "l'export n'est pas un JSON valide : " . substr($json, 0, 120));

        neria_assert(!empty($data['neria_stat']) && count($data['neria_stat']) === 1, "historique d'envois (neria_stat) du client absent ou pollué par un autre client");
        $row = $data['neria_stat'][0];
        neria_assert(!array_key_exists('tracking_token', $row), "le jeton de suivi (secret) ne doit pas être exporté");
        neria_assert(is_array($row['rendered_vars'] ?? null) && ($row['rendered_vars']['{firstname}'] ?? '') === 'Alice', "l'instantané chiffré n'est pas déchiffré dans l'export");
        neria_assert(!empty($data['neria_loyalty_points']) && (int) $data['neria_loyalty_points'][0]['points'] === 7, "points de fidélité absents de l'export");
        neria_assert(!empty($data['neria_preferences']) && $data['neria_preferences'][0]['email'] === $mail, "préférence retrouvée par email absente de l'export");
        neria_assert(!empty($data['neria_bounces']) && $data['neria_bounces'][0]['email'] === $mail, "adresse en rebond absente de l'export");

        // Aucune donnée de l'autre client.
        neria_assert(strpos($json, $tokB) === false && strpos($json, (string) $idB) === false, "des données d'un AUTRE client figurent dans l'export");

        // 3) Aucun résultat pour un client sans donnée (et jamais d'erreur).
        $none = json_decode($module->hookActionExportGDPRData(['id' => 111222333, 'email' => 'nobody-regtest804@example.com']), true);
        neria_assert($none === [] || $none === null || $none === [[]] || empty($none), "un client sans donnée Neria doit donner un export vide");

        // 4) Bout en bout : exactement l'appel de psgdpr pour ce module
        //    (Hook::exec ciblé sur l'id du module, résultat json_decode-é) —
        //    sans itérer sur les autres modules, dont certains exigent le
        //    conteneur Symfony absent en CLI.
        $registered = false;
        foreach ((array) \Hook::getHookModuleExecList('actionExportGDPRData') as $m) {
            if (($m['module'] ?? '') === 'neria') {
                $registered = true;
            }
        }
        neria_assert($registered, "Neria n'est pas enregistré sur le crochet actionExportGDPRData (registerHook non fait pour cette boutique)");
        $viaHook = json_decode((string) \Hook::exec('actionExportGDPRData', ['id' => $idA, 'email' => $mail, 'id_shop' => $shop], (int) $module->id));
        neria_assert(is_object($viaHook) && !empty($viaHook->neria_stat), "l'appel Hook::exec() ciblé sur Neria (comme psgdpr) ne renvoie pas les données");
    } finally {
        $cleanup();
    }

    return ['pass' => true, 'message' => "l'export RGPD (droit d'accès) contient les données Neria du client, déchiffrées, sans secret ni données d'un autre client — bloc 6 (19/09/2026)"];
}
