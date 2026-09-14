<?php
/**
 * Régression : PreferencesManager::getByCustomer() branche INVITÉ
 * (id_customer<=0) doit trier ses résultats par `id_preference ASC`, comme
 * la branche client identifié — même défense en profondeur documentée pour
 * cette dernière (round 178) mais jamais portée à la branche invité.
 *
 * Bug identifié le 14/09/2026 (round 355, audit multi-agents, confiance
 * moyenne) : sans tri explicite, des lignes dupliquées pour la même
 * catégorie (données legacy antérieures à la contrainte UNIQUE
 * uq_shop_customer_email_cat, ou import direct hors saveByCustomer())
 * laissaient l'ordre physique MySQL (non garanti) décider quelle ligne
 * l'emporte dans la boucle d'agrégation, au lieu de systématiquement la
 * plus RÉCENTE — un invité désabonné via une ligne récente pouvait
 * réapparaître comme abonné si une ancienne ligne (subscribed=1) était
 * relue en premier.
 *
 * Corrigé le 14/09/2026 : ORDER BY id_preference ASC ajouté à la branche
 * invité.
 *
 * Note : la contrainte UNIQUE uq_shop_customer_email_cat empêche désormais
 * toute vraie duplication via saveByCustomer() (le bug ne peut survenir que
 * sur des données legacy antérieures à cette contrainte), donc pas de
 * reproduction comportementale de la duplication elle-même possible dans cet
 * environnement de test — vérification structurelle du tri, plus un test
 * comportemental réel confirmant que getByCustomer() invité continue de
 * fonctionner correctement en l'absence de duplication.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PreferencesManager.php';

    // ── Partie 1 : structurel — ORDER BY présent sur la branche invité ──
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/PreferencesManager.php');
    neria_assert($src !== false, 'Impossible de lire src/PreferencesManager.php');

    $posMethod = strpos($src, 'public function getByCustomer(int $idCustomer, string $email = \'\'): array');
    neria_assert($posMethod !== false, 'getByCustomer() introuvable — jeu de test invalide');

    $posGuestBranch = strpos($src, 'if ($idCustomer <= 0) {', $posMethod);
    neria_assert($posGuestBranch !== false, 'Branche invité introuvable — jeu de test invalide');

    $posIdentifiedBranch = strpos($src, "AND `id_customer`= {$idCustomer}", $posMethod) ?: strpos($src, 'AND `id_customer`', $posGuestBranch + 500);
    $guestBranchBody = substr($src, $posGuestBranch, 1100);

    neria_assert(
        strpos($guestBranchBody, 'ORDER BY `id_preference` ASC') !== false,
        "PreferencesManager::getByCustomer() branche invité ne trie plus par id_preference ASC — régression du bug corrigé le 14/09/2026 (round 355) : une ligne obsolète pourrait de nouveau l'emporter selon l'ordre physique MySQL sur des données legacy dupliquées"
    );

    // ── Partie 2 : comportemental réel — getByCustomer() invité fonctionne ──
    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;
    $email  = 'regtest748-guest@example.invalid';

    $mgr = new PreferencesManager($module);
    $category = 'newsletter';

    $db->execute("DELETE FROM {$prefix}neria_preferences WHERE id_shop = {$idShop} AND id_customer = 0 AND email = '" . pSQL($email) . "'");

    try {
        $mgr->saveByCustomer(0, $email, [$category => 0]);

        $prefs = $mgr->getByCustomer(0, $email);
        neria_assert(
            isset($prefs[$category]) && $prefs[$category] === 0,
            "getByCustomer() invité renvoie {$category}=" . var_export($prefs[$category] ?? null, true) . " au lieu de 0 (opt-out sauvegardé) — comportement de base cassé"
        );

        $mgr->saveByCustomer(0, $email, [$category => 1]);
        $prefs2 = $mgr->getByCustomer(0, $email);
        neria_assert(
            isset($prefs2[$category]) && $prefs2[$category] === 1,
            "getByCustomer() invité renvoie {$category}=" . var_export($prefs2[$category] ?? null, true) . " au lieu de 1 (dernier état réactivé) — le tri déterministe ne reflète pas la dernière écriture"
        );

        return [
            'pass'    => true,
            'message' => "PreferencesManager::getByCustomer() branche invité trie désormais par id_preference ASC (défense en profondeur contre des données legacy dupliquées), cohérence avec la branche client identifié — bug corrigé le 14/09/2026 (round 355)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_preferences WHERE id_shop = {$idShop} AND id_customer = 0 AND email = '" . pSQL($email) . "'");
    }
}
