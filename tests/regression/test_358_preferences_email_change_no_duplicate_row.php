<?php
/**
 * Régression : PreferencesManager::saveByCustomer() clé sur (id_shop,
 * id_customer, email, category) — pour un client IDENTIFIÉ, l'email vient
 * du token du lien reçu par mail et peut différer d'un envoi à l'autre
 * (changement d'adresse entre deux visites du centre de préférences). Sans
 * nettoyage, chaque email différent créait une NOUVELLE ligne au lieu de
 * mettre à jour l'existante, et getByCustomer() (sans ORDER BY) pouvait
 * alors retourner une ancienne préférence obsolète selon l'ordre physique
 * MySQL — y compris un opt-out que le client croyait avoir remplacé.
 *
 * Corrigé le 16/08/2026 (round 178) : saveByCustomer() supprime désormais
 * toute ligne existante pour ce (id_shop, id_customer) avec un email
 * DIFFÉRENT avant d'écrire la nouvelle — un client identifié n'a plus
 * jamais qu'un seul jeu de préférences, quel que soit l'email utilisé pour
 * y accéder. getByCustomer() trie aussi désormais explicitement pour un
 * comportement déterministe en cas de données legacy dupliquées.
 *
 * Test comportemental réel : sauvegarde une préférence "catégorie
 * désactivée" pour un client via l'email A, puis sauvegarde à nouveau via
 * l'email B (même id_customer) avec la catégorie RÉACTIVÉE — getByCustomer()
 * doit refléter le dernier état (réactivé), pas l'ancien opt-out de A.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PreferencesManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;
    $idCustomer = 999993; // client fictif, isolé des vraies données

    $mgr = new PreferencesManager($module);
    $category = 'newsletter';

    $db->execute("DELETE FROM {$prefix}neria_preferences WHERE id_shop = {$idShop} AND id_customer = {$idCustomer}");

    try {
        // Email A : catégorie désactivée (opt-out).
        $mgr->saveByCustomer($idCustomer, 'regtest358-a@example.invalid', [$category => 0]);

        $rowCountAfterA = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_preferences WHERE id_shop = {$idShop} AND id_customer = {$idCustomer}"
        );

        // Email B (même client, adresse différente) : catégorie réactivée.
        $mgr->saveByCustomer($idCustomer, 'regtest358-b@example.invalid', [$category => 1]);

        $rowCountAfterB = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_preferences WHERE id_shop = {$idShop} AND id_customer = {$idCustomer}"
        );

        neria_assert(
            $rowCountAfterB === $rowCountAfterA,
            "saveByCustomer() avec un email différent crée de NOUVELLES lignes au lieu de mettre à jour les existantes (obtenu {$rowCountAfterB} lignes au lieu de {$rowCountAfterA}) — régression du bug corrigé le 16/08/2026 (round 178) : un client identifié changeant d'email entre deux visites du centre de préférences accumulerait de nouveau des lignes dupliquées"
        );

        $prefs = $mgr->getByCustomer($idCustomer);
        neria_assert(
            isset($prefs[$category]) && $prefs[$category] === 1,
            "getByCustomer() renvoie {$category}=" . var_export($prefs[$category] ?? null, true) . " au lieu de 1 (dernier état sauvegardé via l'email B) — régression du bug corrigé le 16/08/2026 (round 178) : une ancienne préférence obsolète (opt-out via l'email A) pourrait de nouveau l'emporter selon l'ordre physique MySQL"
        );

        return [
            'pass'    => true,
            'message' => "PreferencesManager::saveByCustomer() ne duplique plus de ligne quand un client identifié change d'email entre deux sauvegardes — bug corrigé le 16/08/2026 (round 178)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_preferences WHERE id_shop = {$idShop} AND id_customer = {$idCustomer}");
    }
}
