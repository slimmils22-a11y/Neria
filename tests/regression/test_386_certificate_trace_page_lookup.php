<?php
/**
 * Nouvelle fonctionnalité (22/08/2026) : page de traçabilité publique
 * (front controller `certificate`, ciblée par le QR code du certificat PDF)
 * — montre l'artisane, la région et le temps de tissage de LA pièce précise
 * achetée, au lieu d'un certificat générique. S'appuie sur
 * CertificateManager::getBySerial(), qui doit retrouver ces 3 nouvelles
 * colonnes (artisan_name/region/weaving_duration) sans filtre id_shop (le
 * numéro de série est globalement unique, cf. generateSerial()) et renvoyer
 * [] pour un numéro de série inconnu — jamais une exception ni une ligne
 * partielle qui ferait planter le rendu Smarty de la page publique.
 *
 * Test comportemental réel : insère un certificat de test avec les 3
 * nouveaux champs renseignés, vérifie que getBySerial() les restitue
 * fidèlement, puis vérifie qu'un numéro de série inexistant renvoie bien un
 * tableau vide plutôt qu'une erreur.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $module = neria_test_module();
    neria_assert($module !== null, "jeu de test invalide : impossible d'instancier le module neria");

    if (!class_exists('CertificateManager')) {
        return ['pass' => false, 'message' => 'CertificateManager introuvable — jeu de test invalide'];
    }

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $serial = 'REGTEST386-' . uniqid();

    $hasColumns = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$prefix}neria_certificate'
          AND COLUMN_NAME  = 'artisan_name'
    ");
    neria_assert(
        $hasColumns,
        "la colonne `artisan_name` n'existe plus sur `neria_certificate` — régression de l'upgrade 1.0.42 (page de traçabilité)"
    );

    $db->execute("DELETE FROM {$prefix}neria_certificate WHERE serial_number = '" . pSQL($serial) . "'");

    try {
        $inserted = $db->execute(
            "INSERT INTO {$prefix}neria_certificate
                (id_shop, id_order, id_product, serial_number, customer_name, product_name,
                 artisan_name, region, weaving_duration, artisan_note, date_issued, date_add)
             VALUES (1, 1, 1, '" . pSQL($serial) . "', 'Regtest', 'Tapis Regtest',
                     'Fatima B.', 'Aures', '3 mois', 'Note de test', NOW(), NOW())"
        );
        neria_assert((bool) $inserted, "jeu de test invalide : l'INSERT de test a échoué");

        $mgr = new CertificateManager($module);

        $found = $mgr->getBySerial($serial);
        neria_assert(!empty($found), "getBySerial() ne retrouve pas un certificat pourtant existant par son numéro de série");
        neria_assert(
            $found['artisan_name'] === 'Fatima B.' && $found['region'] === 'Aures' && $found['weaving_duration'] === '3 mois',
            "getBySerial() ne restitue pas fidèlement artisan_name/region/weaving_duration — la page de traçabilité afficherait des informations tronquées ou vides"
        );

        $notFound = $mgr->getBySerial('REGTEST386-INEXISTANT-' . uniqid());
        neria_assert(
            $notFound === [],
            "getBySerial() ne renvoie pas un tableau vide pour un numéro de série inconnu — la page de traçabilité publique planterait au lieu d'afficher l'état « certificat introuvable »"
        );

        return [
            'pass'    => true,
            'message' => "CertificateManager::getBySerial() retrouve fidèlement artisan_name/region/weaving_duration pour un certificat existant, et renvoie [] pour un numéro de série inconnu — la page de traçabilité publique peut s'appuyer dessus sans risque de plantage",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_certificate WHERE serial_number = '" . pSQL($serial) . "'");
    }
}
