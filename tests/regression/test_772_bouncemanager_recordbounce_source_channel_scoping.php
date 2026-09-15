<?php
/**
 * Régression : BounceManager::recordBounce() doit choisir la portée
 * (`id_shop`) d'écriture selon le CANAL source, pas uniquement selon le
 * réglage marchand NERIA_BOUNCE_CROSS_SHOP_ENABLED :
 *   - source 'imap'   : TOUJOURS id_shop=0 (global), quel que soit le
 *     réglage — la boîte Return-Path partagée n'a aucun signal fiable
 *     de boutique d'origine par message.
 *   - source 'webhook'/'manual' : respecte le réglage — id_shop=0 si le
 *     partage cross-shop est activé, id_shop=$this->idShop (boutique
 *     ambiante) sinon.
 *
 * Correctif du 15/09/2026 (bloc d de la feuille de route Addons, 2e
 * arbitrage produit).
 *
 * Test comportemental réel : enregistre 3 bounces réels (un par canal)
 * dans le même contexte boutique et vérifie la colonne `id_shop`
 * réellement écrite en base pour chacun, sous les 2 valeurs du réglage.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/BounceManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $realIdShop = (int) Context::getContext()->shop->id;

    $emailImap    = 'regtest772-imap-'    . uniqid() . '@example.com';
    $emailWebhook = 'regtest772-webhook-' . uniqid() . '@example.com';
    $emailManual  = 'regtest772-manual-'  . uniqid() . '@example.com';
    $emailShared  = 'regtest772-shared-'  . uniqid() . '@example.com';

    $wasCrossShop = Configuration::getGlobalValue('NERIA_BOUNCE_CROSS_SHOP_ENABLED');

    $cleanup = function () use ($db, $prefix, $emailImap, $emailWebhook, $emailManual, $emailShared) {
        $db->execute(
            "DELETE FROM {$prefix}neria_bounces WHERE email IN (
                '" . pSQL($emailImap) . "', '" . pSQL($emailWebhook) . "',
                '" . pSQL($emailManual) . "', '" . pSQL($emailShared) . "'
             )"
        );
    };
    $cleanup();

    try {
        $mgr = new BounceManager(neria_test_module());

        // Réglage désactivé (scopé, valeur par défaut) : webhook/manual
        // écrivent en id_shop=$realIdShop, imap reste global (0).
        Configuration::updateGlobalValue('NERIA_BOUNCE_CROSS_SHOP_ENABLED', 0);

        $mgr->recordBounce($emailImap, 'hard', 'regtest772 imap', 'imap');
        $idShopImap = (int) $db->getValue("SELECT id_shop FROM {$prefix}neria_bounces WHERE email = '" . pSQL($emailImap) . "'");
        neria_assert(
            $idShopImap === 0,
            "recordBounce() source='imap' n'écrit plus id_shop=0 (obtenu : {$idShopImap}) — régression du correctif du 15/09/2026 : le canal IMAP (sans signal fiable de boutique) écrirait à tort une portée scopée"
        );

        $mgr->recordBounce($emailWebhook, 'hard', 'regtest772 webhook', 'webhook');
        $idShopWebhook = (int) $db->getValue("SELECT id_shop FROM {$prefix}neria_bounces WHERE email = '" . pSQL($emailWebhook) . "'");
        neria_assert(
            $idShopWebhook === $realIdShop,
            "recordBounce() source='webhook' avec le partage désactivé n'écrit plus id_shop={$realIdShop} (obtenu : {$idShopWebhook}) — régression du correctif du 15/09/2026"
        );

        $mgr->recordBounce($emailManual, 'hard', 'regtest772 manual', 'manual');
        $idShopManual = (int) $db->getValue("SELECT id_shop FROM {$prefix}neria_bounces WHERE email = '" . pSQL($emailManual) . "'");
        neria_assert(
            $idShopManual === $realIdShop,
            "recordBounce() source='manual' avec le partage désactivé n'écrit plus id_shop={$realIdShop} (obtenu : {$idShopManual}) — régression du correctif du 15/09/2026"
        );

        // Réglage activé (partagé) : webhook/manual écrivent désormais
        // en id_shop=0 aussi ; imap reste global dans tous les cas.
        Configuration::updateGlobalValue('NERIA_BOUNCE_CROSS_SHOP_ENABLED', 1);

        $mgr->recordBounce($emailShared, 'hard', 'regtest772 shared', 'webhook');
        $idShopShared = (int) $db->getValue("SELECT id_shop FROM {$prefix}neria_bounces WHERE email = '" . pSQL($emailShared) . "'");
        neria_assert(
            $idShopShared === 0,
            "recordBounce() source='webhook' avec NERIA_BOUNCE_CROSS_SHOP_ENABLED=1 n'écrit plus id_shop=0 (obtenu : {$idShopShared}) — régression du correctif du 15/09/2026 : le réglage marchand 'partagé' ne serait plus respecté"
        );

        return [
            'pass'    => true,
            'message' => "BounceManager::recordBounce() applique bien la portée id_shop selon le canal source (imap toujours global) et NERIA_BOUNCE_CROSS_SHOP_ENABLED (webhook/manual) — correctif du 15/09/2026 (bloc d)",
        ];
    } finally {
        Configuration::updateGlobalValue('NERIA_BOUNCE_CROSS_SHOP_ENABLED', $wasCrossShop !== false ? $wasCrossShop : 0);
        $cleanup();
    }
}
