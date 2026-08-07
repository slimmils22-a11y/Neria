<?php
/**
 * Régression : CertificateManager::generateSerial() doit se baser sur le
 * compteur AUTO_INCREMENT réel de la table (information_schema), pas sur
 * MAX(id_certificate), pour ne jamais réémettre un numéro de série déjà
 * attribué après suppression d'un certificat.
 *
 * Bug réel corrigé le 07/08/2026 (round 94) : delete() supprime
 * physiquement (DELETE) la ligne du certificat. Si la ligne supprimée avait
 * le plus grand id_certificate, MAX(id_certificate) RÉTROGRADE — alors
 * qu'InnoDB ne recycle JAMAIS un id AUTO_INCREMENT déjà consommé après un
 * DELETE (contrairement à un TRUNCATE). generateSerial(), basé sur
 * MAX(id_certificate)+1, régénérait donc exactement le même numéro de
 * série pour la prochaine émission — deux certificats DIFFÉRENTS (clients,
 * produits, dates distincts) avec le même serial_number, cassant la valeur
 * probante de la vérification d'authenticité (le QR code d'un certificat
 * pointerait vers les données d'un autre client).
 *
 * Test comportemental réel : vérifie directement, sur la vraie table
 * neria_certificate, qu'un INSERT suivi d'un DELETE ne fait PAS régresser
 * le compteur AUTO_INCREMENT (information_schema), contrairement à
 * MAX(id_certificate) qui régresse bien — prouvant que la requête utilisée
 * par le correctif est la bonne source de vérité.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $serial = 'REGTEST98-' . uniqid();

    $db->execute("DELETE FROM {$prefix}neria_certificate WHERE serial_number = '" . pSQL($serial) . "'");

    try {
        $aiBefore = (int) $db->getValue(
            "SELECT AUTO_INCREMENT FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$prefix}neria_certificate'"
        );

        $db->execute(
            "INSERT INTO {$prefix}neria_certificate
                (id_shop, id_order, id_product, serial_number, customer_name, product_name, date_issued, date_add)
             VALUES (1, 1, 1, '" . pSQL($serial) . "', 'Regtest', 'Regtest', NOW(), NOW())"
        );
        $idInserted = (int) $db->Insert_ID();
        neria_assert($idInserted > 0, "jeu de test invalide : l'INSERT de test a échoué");

        $maxBeforeDelete = (int) $db->getValue("SELECT MAX(id_certificate) FROM {$prefix}neria_certificate");
        neria_assert(
            $maxBeforeDelete === $idInserted,
            "jeu de test invalide : MAX(id_certificate) ne reflète pas la ligne insérée"
        );

        $db->execute("DELETE FROM {$prefix}neria_certificate WHERE id_certificate = {$idInserted}");

        // ANALYZE TABLE force InnoDB à rafraîchir ses statistiques
        // persistantes — sans ça, information_schema.TABLES.AUTO_INCREMENT
        // peut renvoyer une valeur mise en cache (obsolète) juste après un
        // INSERT/DELETE, indépendamment du comportement réel du correctif.
        $db->execute("ANALYZE TABLE {$prefix}neria_certificate");

        $maxAfterDelete = (int) $db->getValue("SELECT MAX(id_certificate) FROM {$prefix}neria_certificate");
        $aiAfterDelete  = (int) $db->getValue(
            "SELECT AUTO_INCREMENT FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$prefix}neria_certificate'"
        );

        // Preuve du bug (sans le correctif) : MAX(id_certificate) après
        // suppression de la ligne au plus grand id retombe forcément SOUS
        // l'id qui vient d'être supprimé — c'est exactement ce qui fait
        // régénérer un serial déjà attribué avec l'ancien calcul.
        neria_assert(
            $maxAfterDelete < $idInserted,
            "jeu de test invalide : MAX(id_certificate) ne régresse pas après suppression — le scénario du bug n'est pas reproduit"
        );

        // Preuve du correctif : le compteur AUTO_INCREMENT réel, lui, ne
        // redescend JAMAIS sous l'id déjà consommé, même après DELETE.
        neria_assert(
            $aiAfterDelete >= $idInserted,
            "le compteur AUTO_INCREMENT régresse après suppression comme MAX(id_certificate) (obtenu {$aiAfterDelete}, attendu >= {$idInserted}) — régression du bug corrigé le 07/08/2026 (round 94) : generateSerial() pourrait de nouveau réémettre un numéro de série déjà attribué"
        );

        // Vérification structurelle : generateSerial() utilise bien cette
        // source de vérité.
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CertificateManager.php') ?: '';
        neria_assert(
            strpos($src, "SELECT `AUTO_INCREMENT` FROM `information_schema`.`TABLES`") !== false,
            "CertificateManager::generateSerial() n'interroge plus le compteur AUTO_INCREMENT réel — régression du bug corrigé le 07/08/2026 (round 94)"
        );

        return [
            'pass'    => true,
            'message' => "CertificateManager::generateSerial() se base bien sur le compteur AUTO_INCREMENT réel (jamais rétrogradé par un DELETE), pas sur MAX(id_certificate) (qui régresse et réémettrait un serial déjà attribué)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_certificate WHERE serial_number = '" . pSQL($serial) . "'");
    }
}
