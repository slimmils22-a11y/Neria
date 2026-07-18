<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Upgrade 1.0.28
 *
 * neria_waitlist avait une clé unique (id_customer, id_product) sans
 * id_shop. En multi-boutique (clients partagés entre boutiques), un client
 * inscrit sur la boutique 1 puis inscrit à nouveau au même produit sur la
 * boutique 2 voyait sa 2e inscription ÉCRASER silencieusement la première
 * (ON DUPLICATE KEY UPDATE sur register()) SANS même mettre à jour
 * id_shop — la ligne restait attribuée à la boutique 1, alors que le client
 * croyait être inscrit pour la boutique 2. De plus,
 * WaitlistManager::notifyProduct()/isRegistered()/unregister() n'étaient
 * pas non plus filtrées par id_shop, causant un risque de fuite d'email
 * cross-boutique (un réapprovisionnement sur la boutique 2 pouvait notifier
 * un inscrit de la boutique 1). Vérifié en réel avec un vrai 2e shop
 * multi-boutique créé pour ce test.
 *
 * La clé inclut désormais id_shop pour permettre une inscription distincte
 * par boutique.
 *
 * Aucun nettoyage de doublons nécessaire avant la migration : l'ancienne
 * clé (déjà unique sur id_customer+id_product) garantit qu'aucune ligne
 * existante ne peut entrer en collision sur la nouvelle clé plus large.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_28(Neria $module): bool
{
    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $ok     = true;

    $hasOldKey = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$prefix}neria_waitlist'
          AND INDEX_NAME   = 'uq_customer_product'
    ");
    if ($hasOldKey) {
        $ok = $ok && $db->execute("ALTER TABLE `{$prefix}neria_waitlist` DROP INDEX `uq_customer_product`");
    }

    $hasNewKey = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$prefix}neria_waitlist'
          AND INDEX_NAME   = 'uq_customer_product_shop'
    ");
    if (!$hasNewKey) {
        $ok = $ok && $db->execute("
            ALTER TABLE `{$prefix}neria_waitlist`
            ADD UNIQUE KEY `uq_customer_product_shop` (`id_customer`, `id_product`, `id_shop`)
        ");
    }

    return $ok && $module->importTranslations();
}
