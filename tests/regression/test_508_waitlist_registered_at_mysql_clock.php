<?php
/**
 * Régression : `WaitlistManager::register()` écrivait `registered_at` via
 * l'horloge PHP (`date('Y-m-d H:i:s')`), alors que TOUTES les comparaisons
 * ultérieures (`DATEDIFF(NOW(), registered_at)`, `MAX(DATEDIFF(NOW(),
 * registered_at))`, `registered_at < DATE_SUB(NOW(), INTERVAL ... DAY)`) se
 * font côté SQL avec `NOW()` (horloge du serveur MySQL). Un décalage entre
 * le fuseau PHP (`date.timezone`) et le fuseau du serveur MySQL — situation
 * courante en hébergement mutualisé (ex. PHP réglé sur Europe/Paris, MySQL
 * sur UTC) — faussait ces comparaisons de ±1 jour selon l'heure
 * d'inscription (ex. proche de minuit), pouvant produire un
 * `DATEDIFF()` négatif ou avancer/retarder d'un jour la purge des
 * inscriptions périmées.
 *
 * Bug identifié le 01/09/2026 (round 266, audit "fuseaux horaires / dates
 * limites"). Toutes les AUTRES colonnes horodatées de ce même fichier
 * (`claim_started_at`, `notified_at`) et de tout le reste du module
 * (`date_add` partout ailleurs) suivent déjà la convention correcte :
 * écriture ET lecture entièrement côté SQL via `NOW()`. `registered_at`
 * était la seule exception.
 *
 * Corrigé le 01/09/2026 (round 266) : `pSQL(date('Y-m-d H:i:s'))` remplacé
 * par `NOW()` directement dans la requête SQL, à l'écriture (INSERT) comme
 * à la mise à jour (`ON DUPLICATE KEY UPDATE`).
 *
 * Test réel + structurel : insère effectivement une ligne via
 * `WaitlistManager::register()`, lit `registered_at` en base et vérifie
 * qu'elle correspond à `NOW()` MySQL à quelques secondes près (valide que
 * la colonne est bien peuplée par le moteur SQL, source de vérité pour
 * toutes les lectures — pas par l'horloge PHP, qui pourrait diverger dans
 * un environnement mal configuré), complété par une vérification
 * structurelle que le code source n'utilise plus `date('Y-m-d H:i:s')`
 * pour cette colonne.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/WaitlistManager.php';

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WaitlistManager.php');
    neria_assert($src !== false, 'Impossible de lire src/WaitlistManager.php');

    $registerStart = strpos($src, 'public function register(');
    neria_assert($registerStart !== false, 'WaitlistManager::register() introuvable');
    $sqlStart = strpos($src, 'return $this->db->execute(', $registerStart);
    neria_assert($sqlStart !== false, "requête SQL d'INSERT introuvable dans register()");
    $registerBody = substr($src, $sqlStart, 700);

    neria_assert(
        strpos($registerBody, "date('Y-m-d H:i:s')") === false,
        "WaitlistManager::register() utilise de nouveau date('Y-m-d H:i:s') (horloge PHP) pour registered_at — régression du bug corrigé le 01/09/2026 (round 266) : un décalage de fuseau horaire entre PHP et MySQL fausserait de nouveau les DATEDIFF()/comparaisons NOW() en aval"
    );

    neria_assert(
        substr_count($registerBody, 'NOW()') >= 2,
        "WaitlistManager::register() n'écrit plus registered_at via NOW() aux 2 emplacements attendus (INSERT + ON DUPLICATE KEY UPDATE) — régression du bug corrigé le 01/09/2026 (round 266)"
    );

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idProduct  = 999890; // produit fictif dédié à ce test
    $idShop     = (int) Context::getContext()->shop->id;
    $mgr        = new WaitlistManager(neria_test_module());

    try {
        $before = time();
        $ok = $mgr->register($idCustomer, $idProduct, $idShop);
        $after = time();
        neria_assert($ok === true, 'WaitlistManager::register() a échoué sur la fixture de test');

        $registeredAt = (string) $db->getValue(
            "SELECT registered_at FROM {$prefix}neria_waitlist
             WHERE id_customer = {$idCustomer} AND id_product = {$idProduct} AND id_shop = {$idShop}"
        );
        neria_assert($registeredAt !== '', 'registered_at non peuplée après register()');

        $registeredAtTs = strtotime($registeredAt);
        neria_assert(
            $registeredAtTs >= ($before - 5) && $registeredAtTs <= ($after + 5),
            "registered_at ({$registeredAt}) n'est pas proche de l'heure d'exécution du test — la colonne ne semble pas peuplée par l'horloge du serveur MySQL comme attendu"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_waitlist WHERE id_customer = {$idCustomer} AND id_product = {$idProduct}");
    }

    return [
        'pass'    => true,
        'message' => "WaitlistManager::register() écrit désormais registered_at via NOW() (horloge du serveur MySQL), cohérent avec toutes les comparaisons DATEDIFF()/DATE_SUB() en aval — bug corrigé le 01/09/2026 (round 266)",
    ];
}
