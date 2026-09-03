<?php
/**
 * Régression : `WaitlistManager::notifyProduct()` notifie un client pour
 * LA DÉCLINAISON PRÉCISE qu'il attend (round 167 : un client n'est
 * notifié QUE si la combinaison exacte — `id_product_attribute` — est de
 * nouveau en stock, indépendamment des autres déclinaisons du même
 * produit) mais `{product_name}` n'affichait que le nom générique du
 * produit parent — un client attendant "Taille M, Rouge" ne pouvait pas
 * savoir SI c'était bien cette combinaison précise qui venait de revenir
 * en stock (une autre déclinaison pouvant rester épuisée), risquant de
 * re-sélectionner par erreur une déclinaison toujours indisponible.
 *
 * Même angle mort dans `BehavioralCronManager::buildCartProducts()`/
 * `buildCartProductsTxt()` (panier abandonné) : deux déclinaisons
 * différentes du même produit dans le même panier produisaient deux
 * lignes visuellement identiques ("× 1 T-shirt Basique" deux fois),
 * rendant impossible pour le client de savoir lesquelles étaient dans
 * son panier.
 *
 * Bug identifié le 03/09/2026 (round 292, audit "affichage des
 * déclinaisons produit dans les emails de recommandation").
 *
 * Corrigé le 03/09/2026 (round 292) : nouveau helper
 * `NeriaTools::getAttributeCombinationLabel()`, appelé dans
 * `WaitlistManager::notifyProduct()` (nom de déclinaison ajouté au nom
 * générique) et `BehavioralCronManager::buildCartProducts()`/
 * `buildCartProductsTxt()` (idem, par ligne de panier).
 *
 * Test comportemental réel sur le helper (fonction pure, testable
 * isolément avec une vraie déclinaison de la base de test si
 * disponible) + vérification structurelle que les 2 managers l'appliquent.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/NeriaTools.php';

    $idLang = (int) Configuration::get('PS_LANG_DEFAULT');

    // idProductAttribute <= 0 : toujours une chaîne vide (produit sans
    // déclinaison — comportement historique préservé).
    neria_assert(
        NeriaTools::getAttributeCombinationLabel(0, $idLang) === '',
        "NeriaTools::getAttributeCombinationLabel() ne renvoie plus '' pour id_product_attribute=0 — régression du bug corrigé le 03/09/2026 (round 292)"
    );

    // Recherche une vraie déclinaison en base de test, si disponible.
    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $realAttr = (int) $db->getValue(
        "SELECT id_product_attribute FROM {$prefix}product_attribute_combination"
    );
    if ($realAttr > 0) {
        $label = NeriaTools::getAttributeCombinationLabel($realAttr, $idLang);
        neria_assert(
            $label !== '' && strpos($label, ':') !== false,
            "NeriaTools::getAttributeCombinationLabel() n'a pas produit de libellé pour une vraie déclinaison (id_product_attribute={$realAttr}) — régression du bug corrigé le 03/09/2026 (round 292)"
        );
    }

    // Vérification structurelle : les 2 managers appliquent bien le helper.
    $wlSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WaitlistManager.php');
    neria_assert($wlSrc !== false, 'Impossible de lire src/WaitlistManager.php');
    neria_assert(
        strpos($wlSrc, 'NeriaTools::getAttributeCombinationLabel((int) $row[\'id_product_attribute\'], $idLang)') !== false,
        "WaitlistManager::notifyProduct() n'applique plus getAttributeCombinationLabel() — régression du bug corrigé le 03/09/2026 (round 292) : le nom de la déclinaison précise disparaîtrait de nouveau de l'email de retour en stock"
    );

    $bcmSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php');
    neria_assert($bcmSrc !== false, 'Impossible de lire src/BehavioralCronManager.php');
    $attrCallCount = substr_count($bcmSrc, 'NeriaTools::getAttributeCombinationLabel((int) $r[\'id_product_attribute\'], $idLangCart)');
    neria_assert(
        $attrCallCount === 2,
        "BehavioralCronManager applique getAttributeCombinationLabel() à {$attrCallCount} emplacement(s) au lieu de 2 attendus (buildCartProducts()/buildCartProductsTxt()) — régression du bug corrigé le 03/09/2026 (round 292) : deux déclinaisons différentes du même produit dans un panier abandonné redeviendraient indiscernables dans l'email de relance"
    );
    neria_assert(
        strpos($bcmSrc, 'cp.id_product_attribute') !== false,
        "BehavioralCronManager ne sélectionne plus cp.id_product_attribute dans les requêtes panier — régression du bug corrigé le 03/09/2026 (round 292)"
    );

    return [
        'pass'    => true,
        'message' => "NeriaTools::getAttributeCombinationLabel() ajoute désormais le nom de la déclinaison précise dans WaitlistManager (retour en stock) et BehavioralCronManager (panier abandonné) — bug corrigé le 03/09/2026 (round 292)",
    ];
}
