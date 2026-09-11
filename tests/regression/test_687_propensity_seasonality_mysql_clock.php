<?php
/**
 * Régression : `PropensityScoreManager::recalculateAll()` (chemin batch) et
 * `scoreSeasonality()` (chemin par client) déterminaient le mois courant
 * via `date('n')` (horloge PHP), puis comparaient ce mois à `MONTH(date_add)`
 * calculé côté MySQL — même piège explicitement déjà corrigé au round 303
 * dans ce même fichier pour les calculs de récence/fréquence
 * (`TIMESTAMPDIFF(... NOW())` côté SQL), mais jamais porté à ce calcul de
 * saisonnalité.
 *
 * Bug identifié le 11/09/2026 (round 336, audit PropensityScoreManager/
 * QueueManager) : si le serveur PHP et le serveur MySQL n'ont pas le même
 * fuseau horaire, `$currentMonth` (PHP) et `MONTH(date_add)` (MySQL, sur
 * des commandes insérées via `NOW()` MySQL) pouvaient se décaler près d'un
 * changement de mois, faisant compter les commandes récentes dans le
 * mauvais mois, silencieusement, sans aucune trace Watchdog.
 *
 * Corrigé le 11/09/2026 (round 336) : `MONTH(NOW())` calculé côté SQL dans
 * les deux méthodes, insensible au fuseau horaire PHP.
 *
 * Test comportemental réel (même méthode que test_614, round 314) : appelle
 * la méthode privée `scoreSeasonality()` via réflexion pour un vrai client
 * ayant une commande réellement insérée CE mois-ci (`date_add = NOW()`
 * MySQL), sous deux fuseaux PHP radicalement différents (Europe/Paris puis
 * Pacific/Kiritimati, UTC+14 — susceptible de faire croire à PHP qu'on est
 * déjà dans le mois suivant), et vérifie que le score retourné est
 * IDENTIQUE dans les deux cas — preuve que le calcul ne dépend plus de
 * l'horloge PHP.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PropensityScoreManager.php';

    // ── Vérification structurelle du correctif (les 2 sites) ──────────
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/PropensityScoreManager.php');
    neria_assert($src !== false, 'Impossible de lire src/PropensityScoreManager.php');
    neria_assert(
        substr_count($src, 'MONTH(date_add) = MONTH(NOW())') === 2,
        "PropensityScoreManager n'utilise plus MONTH(NOW()) côté SQL aux 2 emplacements attendus (recalculateAll() + scoreSeasonality()) — régression du bug corrigé le 11/09/2026 (round 336) : le calcul de saisonnalité redeviendrait sensible à un décalage de fuseau horaire entre serveur PHP et serveur MySQL"
    );
    neria_assert(
        strpos($src, '$currentMonth = (int) date') === false,
        "PropensityScoreManager assigne encore \$currentMonth via date() PHP — régression partielle du bug corrigé le 11/09/2026 (round 336)"
    );

    // ── Vérification comportementale : indépendance au fuseau PHP ─────
    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idShop     = (int) Context::getContext()->shop->id;
    $module     = neria_test_module();
    $idCustomer = neria_test_any_customer_id();

    $ref = new ReflectionMethod(PropensityScoreManager::class, 'scoreSeasonality');
    $ref->setAccessible(true);
    $mgr = new PropensityScoreManager($module);

    // Insère une vraie commande valide datée du mois courant (NOW() MySQL),
    // pour garantir qu'il existe au moins 1 commande "in month" à détecter.
    $db->execute(
        "INSERT INTO {$prefix}orders
            (id_shop, id_shop_group, id_customer, id_carrier, id_lang, id_cart, id_currency, current_state,
             payment, conversion_rate, total_paid, total_paid_tax_incl, total_paid_tax_excl,
             total_paid_real, total_products, total_products_wt, valid, date_add, date_upd, reference)
         SELECT id_shop, id_shop_group, {$idCustomer}, id_carrier, id_lang, id_cart, id_currency, current_state,
             payment, conversion_rate, total_paid, total_paid_tax_incl, total_paid_tax_excl,
             total_paid_real, total_products, total_products_wt, 1, NOW(), NOW(), 'RT687TEST'
         FROM {$prefix}orders WHERE id_customer = {$idCustomer} LIMIT 1"
    );
    $newOrderId = (int) $db->Insert_ID();
    neria_assert($newOrderId > 0, "Insertion de la commande de test a échoué — jeu de test invalide (client sans commande existante à dupliquer ?)");

    $originalTz = date_default_timezone_get();

    try {
        date_default_timezone_set('Europe/Paris');
        $scoreParis = $ref->invoke($mgr, $idCustomer);

        date_default_timezone_set('Pacific/Kiritimati'); // UTC+14
        $scoreKiritimati = $ref->invoke($mgr, $idCustomer);

        neria_assert(
            is_float($scoreParis) && is_float($scoreKiritimati),
            "scoreSeasonality() ne renvoie plus un float — jeu de test invalide"
        );
        neria_assert(
            abs($scoreParis - $scoreKiritimati) < 0.0001,
            "scoreSeasonality() renvoie des scores différents selon le fuseau horaire PHP (Europe/Paris={$scoreParis}, Pacific/Kiritimati={$scoreKiritimati}) — régression du bug corrigé le 11/09/2026 (round 336) : le calcul dépend encore de date('n') PHP au lieu de MONTH(NOW()) MySQL"
        );

        return [
            'pass'    => true,
            'message' => "PropensityScoreManager::scoreSeasonality()/recalculateAll() utilisent désormais MONTH(NOW()) côté SQL — le score de saisonnalité est identique quel que soit le fuseau horaire PHP (vérifié Europe/Paris vs Pacific/Kiritimati) — bug corrigé le 11/09/2026 (round 336)",
        ];
    } finally {
        date_default_timezone_set($originalTz);
        $db->execute("DELETE FROM {$prefix}orders WHERE id_order = {$newOrderId}");
    }
}
