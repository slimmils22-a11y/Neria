<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Upgrade 1.0.41
 *
 * Round 167 : ajoute la colonne `id_product_attribute` à `neria_waitlist`
 * (0 = toute déclinaison confondue, comportement historique préservé pour
 * toutes les lignes existantes) et étend la contrainte UNIQUE en
 * conséquence — infrastructure nécessaire pour qu'un futur suivi par
 * déclinaison (taille/couleur) puisse notifier un client uniquement quand
 * la combinaison précise qu'il attend redevient disponible, plutôt que
 * n'importe quelle déclinaison du produit.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_41(Neria $module): bool
{
    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $table  = $prefix . 'neria_waitlist';

    // Round 167 (découvert en testant CE script) : "SHOW TABLES LIKE '...'"
    // casse sous ce couple MySQL/PDO dès que Db::getValue() lui ajoute
    // automatiquement "LIMIT 1" — MySQL rejette la syntaxe résultante
    // ("SHOW TABLES LIKE '...' LIMIT 1", erreur 1064). information_schema
    // n'a pas ce problème (déjà utilisé plus bas dans ce même script pour
    // vérifier la colonne/l'index) — cohérence + fiabilité.
    $tableExists = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'
    ");
    if (!$tableExists) {
        Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);
        return $module->importTranslations();
    }

    $hasColumn = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$table}'
          AND COLUMN_NAME  = 'id_product_attribute'
    ");
    $ok = true;
    if (!$hasColumn) {
        $ok = $ok && (bool) $db->execute("
            ALTER TABLE `{$table}`
            ADD COLUMN `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0
                COMMENT '0 = toute déclinaison confondue (comportement historique) ; sinon déclinaison précise attendue par le client'
                AFTER `id_product`
        ");
    }

    // La contrainte UNIQUE d'origine (id_customer, id_product, id_shop) ne
    // permettait qu'UNE ligne par produit et par client, quelle que soit la
    // déclinaison — incompatible avec un futur suivi par déclinaison (un
    // client pourrait vouloir s'inscrire séparément sur 2 tailles du même
    // produit). Même garde-fou "vérifie avant de recréer" que
    // upgrade-1.0.40.php pour les contraintes déjà en place.
    $hasOldKey = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$table}'
          AND INDEX_NAME   = 'uq_customer_product_shop'
    ");
    $hasNewKey = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$table}'
          AND INDEX_NAME   = 'uq_customer_product_attr_shop'
    ");
    // Round 167 (découvert en testant ce script deux fois de suite via
    // Module::needUpgrade()+runUpgradeModule(), même méthode que l'action BO
    // "repair_module_version") : runUpgradeModule() peut ré-exécuter TOUS
    // les scripts d'upgrade dans l'ordre à chaque appel, pas seulement les
    // scripts jamais exécutés. upgrade-1.0.40.php contient sa propre
    // logique de garde-fou "recrée uq_customer_product_shop si absente" —
    // si son propre check tourne APRÈS que ce script-ci a déjà supprimé
    // cette contrainte (remplacée par uq_customer_product_attr_shop), il la
    // recrée sans le savoir, laissant l'ancienne ET la nouvelle contrainte
    // coexister. Un simple if/elseif basé sur l'état constaté EN DÉBUT de
    // script ne suffit donc pas : les 2 actions (dropper l'ancienne,
    // garantir la nouvelle) sont désormais indépendantes et inconditionnelles
    // (chacune vérifie sa propre condition juste avant d'agir), robustes à
    // n'importe quel ordre de ré-exécution.
    if ($hasOldKey) {
        $ok = $ok && (bool) $db->execute("ALTER TABLE `{$table}` DROP KEY `uq_customer_product_shop`");
    }
    if (!$hasNewKey) {
        $ok = $ok && (bool) $db->execute("
            ALTER TABLE `{$table}`
            ADD UNIQUE KEY `uq_customer_product_attr_shop` (`id_customer`, `id_product`, `id_product_attribute`, `id_shop`)
        ");
    }

    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return $ok && $module->importTranslations();
}
