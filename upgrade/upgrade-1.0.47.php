<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Upgrade 1.0.47
 *
 * Migre les lignes historiques de `neria_loyalty_rewards` écrites sous
 * l'ancienne sentinelle `id_shop = 0` (mode cumul transversal, portée
 * TOUTE L'INSTALLATION — round 350/351 : ce module étant vendu à de
 * multiples commerçants via PrestaShop Addons, ce mode ne doit désormais
 * agréger que le GROUPE de boutiques, pas l'installation entière) vers la
 * nouvelle ancre de groupe (le plus petit id_shop du groupe de boutiques
 * n°1, choisi comme référence par défaut — voir note ci-dessous).
 *
 * Idempotent : ne touche que les lignes encore à `id_shop = 0` ; une
 * ré-exécution ne fait rien de plus.
 *
 * Note sur les installations multi-groupes PRÉEXISTANTES : la sentinelle 0
 * ne conservait aucune information sur le groupe d'origine réel de chaque
 * bon — si un marchand avait DÉJÀ plusieurs groupes de boutiques
 * indépendants avec le cumul transversal activé AVANT cette version, les
 * points/bons de groupes différents étaient déjà fusionnés dans le même
 * total (c'est précisément le bug corrigé). Cette migration les rattache
 * tous au groupe n°1 par défaut (comportement inchangé pour le cas
 * standard, très largement majoritaire, d'une installation à un seul
 * groupe de boutiques) ; un marchand ayant réellement plusieurs groupes
 * ET ayant déjà émis des bons en cumul transversal devra vérifier
 * manuellement la répartition après mise à jour (cas jugé suffisamment
 * rare pour ne pas justifier une heuristique de reconstruction plus
 * complexe, nécessairement approximative de toute façon).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_47(Neria $module): bool
{
    $db = Db::getInstance();
    $prefix = _DB_PREFIX_;

    $rewardsTable = $prefix . 'neria_loyalty_rewards';
    $exists = $db->executeS("SHOW TABLES LIKE '" . pSQL($rewardsTable) . "'");
    if (!is_array($exists) || empty($exists)) {
        return true;
    }

    $anchorShopId = (int) $db->getValue(
        "SELECT MIN(s.id_shop) FROM `{$prefix}shop` s
         WHERE s.id_shop_group = (
             SELECT id_shop_group FROM `{$prefix}shop` WHERE id_shop = 1
         )"
    );
    if ($anchorShopId <= 0) {
        // Jeu de test invalide/donnée exotique (pas de boutique #1) — ne
        // rien migrer plutôt que d'écrire une valeur incohérente.
        return true;
    }

    $ok = $db->execute(
        "UPDATE `{$rewardsTable}` SET id_shop = {$anchorShopId} WHERE id_shop = 0"
    );

    if (!$ok && class_exists('WatchdogManager')) {
        (new WatchdogManager($module))->error(
            'Migration upgrade 1.0.47 (id_shop=0 -> ancre de groupe) échouée sur neria_loyalty_rewards — les anciens bons en cumul transversal restent sous la sentinelle 0, potentiellement invisibles pour la vérification anti-doublon désormais scopée par groupe.',
            '', 'upgrade-1.0.47'
        );
    }

    Configuration::updateGlobalValue('NERIA_INSTALLED_VERSION', $module->version);

    return true;
}
