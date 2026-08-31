<?php
/**
 * Régression : GdprAuditManager::purgeCustomerData() enchaînait 6 blocs de
 * suppression sur des tables différentes SANS transaction (`START
 * TRANSACTION`/`COMMIT`/`ROLLBACK`), et sans jamais vérifier le retour de
 * chaque `Db::execute()` (qui renvoie simplement `false` sur échec, sans
 * lever d'exception). Un échec SQL en cours de séquence (deadlock, verrou
 * transitoire — scénario réaliste : la purge automatique par ancienneté
 * `NERIA_GDPR_AUTO_PURGE_ENABLED` peut tourner en concurrence sur les MÊMES
 * tables `neria_certificate`/`neria_attribution`) laissait les tables
 * DÉJÀ traitées définitivement purgées, tandis que les tables suivantes ne
 * l'étaient jamais — ET la méthode retournait quand même un total "succès"
 * au marchand (aucune vérification du retour d'`execute()`), un droit à
 * l'effacement RGPD confirmé mais partiellement non honoré, sans trace.
 *
 * Bug identifié le 31/08/2026 (round 258, audit "écritures multi-tables non
 * atomiques"). Corrigé le 31/08/2026 (round 258) : toute la méthode est
 * désormais encadrée par une transaction explicite, avec un nouveau helper
 * `execOrFail()` qui lève une exception au premier `execute()` en échec —
 * ROLLBACK automatique de TOUT (y compris les DELETE des tables déjà
 * "traitées" plus tôt dans la séquence), l'exception remonte ensuite au
 * caller (`NeriaErrorHandler::wrapHookVoid()` côté `neria.php`, qui la
 * journalise) au lieu d'un faux succès silencieux.
 *
 * Test comportemental réel (fault injection authentique, pas un mock) :
 * ouvre une VRAIE seconde connexion MySQL (mysqli, indépendante de la
 * connexion PrestaShop), verrouille la ligne `neria_certificate` du client
 * de test via `SELECT ... FOR UPDATE` dans une transaction non validée —
 * reproduit fidèlement une contention réelle (deadlock/verrou transitoire)
 * sur EXACTEMENT la table documentée comme le point de collision avec la
 * purge automatique par ancienneté. Réduit `innodb_lock_wait_timeout` sur
 * la connexion principale pour que le DELETE bloqué échoue rapidement (~1s)
 * plutôt que d'attendre le délai par défaut (50s). Vérifie que
 * purgeCustomerData() lève bien une exception, ET que la ligne
 * `neria_bounces` (supprimée PLUS TÔT dans la séquence, avant le blocage
 * sur `neria_certificate`) est bien TOUJOURS PRÉSENTE après l'échec —
 * preuve que le ROLLBACK a bien annulé un DELETE déjà "exécuté" avec succès
 * plus tôt dans la même transaction, pas seulement empêché celui qui a
 * échoué.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/GdprAuditManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $testEmail  = 'regtest497-' . uniqid() . '@example.test';
    $fakeOrder  = 999777; // id_order fictif, garanti absent de ps_orders

    $db->execute("DELETE FROM {$prefix}neria_bounces WHERE email = '" . pSQL($testEmail) . "'");
    $db->execute("DELETE FROM {$prefix}neria_certificate WHERE id_order = {$fakeOrder}");

    $db->execute(
        "INSERT INTO {$prefix}neria_bounces (email, type, bounce_count, last_bounce_at, date_add)
         VALUES ('" . pSQL($testEmail) . "', 'hard', 1, NOW(), NOW())"
    );
    $db->execute(
        "INSERT INTO {$prefix}neria_certificate
            (id_shop, id_customer, id_order, id_product, serial_number, customer_name, product_name, date_issued, date_add)
         VALUES (1, {$idCustomer}, {$fakeOrder}, 1, 'REGTEST497-" . uniqid() . "', 'Regtest', 'Regtest', NOW(), NOW())"
    );
    $idCertificate = (int) $db->Insert_ID();

    $mysqli = null;

    try {
        neria_assert($idCertificate > 0, "jeu de test invalide : l'INSERT du certificat de test a échoué");
        $bounceExistsBefore = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_bounces WHERE email = '" . pSQL($testEmail) . "'");
        neria_assert($bounceExistsBefore === 1, "jeu de test invalide : le bounce de test n'a pas été inséré");

        // Verrou réel sur la ligne certificat via une SECONDE connexion
        // MySQL indépendante (pas la connexion PrestaShop) — reproduit une
        // vraie contention concurrente.
        $mysqli = @mysqli_connect(_DB_SERVER_, _DB_USER_, _DB_PASSWD_, _DB_NAME_);
        neria_assert($mysqli !== false, "jeu de test invalide : impossible d'ouvrir une seconde connexion MySQL pour le verrou (" . mysqli_connect_error() . ")");

        mysqli_query($mysqli, 'SET autocommit=0');
        mysqli_query($mysqli, 'START TRANSACTION');
        $lockResult = mysqli_query($mysqli, "SELECT * FROM {$prefix}neria_certificate WHERE id_certificate = {$idCertificate} FOR UPDATE");
        neria_assert($lockResult !== false, "jeu de test invalide : impossible de poser le verrou SELECT ... FOR UPDATE (" . mysqli_error($mysqli) . ")");

        // Délai d'attente de verrou réduit sur la connexion PrestaShop pour
        // que le test reste rapide (~1s au lieu des 50s par défaut).
        $db->execute('SET SESSION innodb_lock_wait_timeout = 1');

        $mgr = new GdprAuditManager(_PS_MODULE_DIR_ . 'neria');

        $threwException = false;
        try {
            $mgr->purgeCustomerData($idCustomer, $testEmail);
        } catch (\Throwable $e) {
            $threwException = true;
        }

        neria_assert(
            $threwException,
            "GdprAuditManager::purgeCustomerData() n'a pas levé d'exception malgré l'échec SQL forcé (verrou sur neria_certificate) — régression du bug corrigé le 31/08/2026 (round 258) : un échec silencieux serait de nouveau compté comme un succès"
        );

        // Libère le verrou pour pouvoir relire l'état réel des tables.
        mysqli_query($mysqli, 'ROLLBACK');
        mysqli_close($mysqli);
        $mysqli = null;

        $bounceExistsAfter = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_bounces WHERE email = '" . pSQL($testEmail) . "'");
        neria_assert(
            $bounceExistsAfter === 1,
            "GdprAuditManager::purgeCustomerData() a bien supprimé neria_bounces avant l'échec sur neria_certificate, mais le ROLLBACK ne l'a pas restauré — régression du bug corrigé le 31/08/2026 (round 258) : la purge ne serait plus atomique, un échec partiel laisserait certaines tables purgées et d'autres non"
        );

        $certificateStillExists = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_certificate WHERE id_certificate = {$idCertificate}");
        neria_assert(
            $certificateStillExists === 1,
            "jeu de test invalide : le certificat de test a disparu malgré l'échec attendu de son DELETE"
        );

        return [
            'pass'    => true,
            'message' => "GdprAuditManager::purgeCustomerData() est désormais atomique (START TRANSACTION/COMMIT/ROLLBACK) : un échec SQL réel forcé sur neria_certificate (verrou concurrent authentique) fait bien échouer TOUTE la purge, y compris annuler le DELETE de neria_bounces déjà exécuté plus tôt dans la même séquence — bug corrigé le 31/08/2026 (round 258)",
        ];
    } finally {
        if ($mysqli !== null) {
            @mysqli_query($mysqli, 'ROLLBACK');
            @mysqli_close($mysqli);
        }
        $db->execute('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        $db->execute("DELETE FROM {$prefix}neria_bounces WHERE email = '" . pSQL($testEmail) . "'");
        $db->execute("DELETE FROM {$prefix}neria_certificate WHERE id_order = {$fakeOrder}");
    }
}
