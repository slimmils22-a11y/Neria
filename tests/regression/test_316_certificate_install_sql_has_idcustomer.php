<?php
/**
 * Régression : `upgrade-1.0.39.php` ajoute la colonne `id_customer` à
 * `neria_certificate` (nécessaire pour que GdprAuditManager::
 * purgeCustomerData() purge directement par client, indépendamment de la
 * survie de la commande liée — art. 17 RGPD), mais `sql/install.sql`
 * n'avait jamais été resynchronisé avec cet ajout. Sur toute installation
 * FRAÎCHE (pas une mise à jour depuis ≤1.0.38), `neria_certificate` était
 * créée SANS `id_customer` : la requête de purge échouait contre une
 * colonne inconnue, tandis que purgeCustomerData() retournait quand même
 * un total, donnant au marchand l'illusion d'une purge réussie alors que
 * le certificat (nom client en clair + référence commande) survivait.
 *
 * Corrigé le 15/08/2026 (round 170) : `id_customer` ajouté directement
 * dans `sql/install.sql` (même position/type/index qu'`upgrade-1.0.39.php`
 * ajoute rétroactivement), pour que les installs fraîches et les
 * installs mises à jour convergent sur le même schéma.
 *
 * Test structurel : vérifie la présence de la colonne dans le bloc
 * CREATE TABLE de install.sql, PUIS vérifie réellement que la syntaxe SQL
 * est valide en la créant sous un nom de table temporaire (jamais touché
 * par le reste de l'application) et en inspectant son schéma réel via
 * DESCRIBE, avant de la supprimer.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $sql = file_get_contents(_PS_MODULE_DIR_ . 'neria/sql/install.sql');
    neria_assert($sql !== false, 'Impossible de lire install.sql');

    $posTable = strpos($sql, 'CREATE TABLE IF NOT EXISTS `PREFIX_neria_certificate`');
    neria_assert($posTable !== false, 'Bloc CREATE TABLE de neria_certificate introuvable — jeu de test invalide');
    $posSemi = strpos($sql, ";", $posTable);
    neria_assert($posSemi !== false, 'Fin du bloc CREATE TABLE introuvable — jeu de test invalide');
    $block = substr($sql, $posTable, $posSemi - $posTable);

    neria_assert(
        strpos($block, '`id_customer`') !== false,
        "install.sql ne définit plus id_customer sur neria_certificate — régression du bug corrigé le 15/08/2026 (round 170) : toute installation fraîche recréerait une table sans cette colonne, cassant purgeCustomerData() et laissant les certificats survivre à une demande d'effacement RGPD"
    );

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $table  = $prefix . 'neria_test316_scratch';

    $scratchSql = str_replace('`PREFIX_neria_certificate`', "`{$table}`", $block);

    $db->execute("DROP TABLE IF EXISTS `{$table}`");
    try {
        $ok = $db->execute($scratchSql);
        neria_assert($ok, 'La syntaxe SQL du bloc modifié est invalide : ' . $db->getMsgError());

        $desc = $db->executeS("SHOW COLUMNS FROM `{$table}` LIKE 'id_customer'");
        neria_assert(
            is_array($desc) && count($desc) === 1,
            "La colonne id_customer n'existe pas réellement dans le schéma créé — le texte SQL était présent mais la colonne n'a pas été créée"
        );
    } finally {
        $db->execute("DROP TABLE IF EXISTS `{$table}`");
    }

    return [
        'pass'    => true,
        'message' => "install.sql crée bien neria_certificate avec id_customer dès l'installation fraîche, convergent avec upgrade-1.0.39.php — bug corrigé le 15/08/2026 (round 170)",
    ];
}
