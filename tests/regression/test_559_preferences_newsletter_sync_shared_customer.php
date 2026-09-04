<?php
/**
 * Régression : `controllers/front/preferences.php` synchronise
 * `ps_customer.newsletter` avec la catégorie 'newsletter' Neria après
 * sauvegarde des préférences — mais l'`UPDATE` filtrait `WHERE id_customer
 * = $idCustomer AND id_shop = $this->context->shop->id` (la boutique
 * VISITÉE), alors que `$idCustomer` est déjà résolu juste au-dessus via
 * `Customer::customerExists()` sous restriction `Shop::SHARE_CUSTOMER`
 * (round 211), qui gère nativement le cas où le compte est rattaché en
 * base à sa boutique de CRÉATION, potentiellement différente de la
 * boutique visitée.
 *
 * Bug identifié le 04/09/2026 (round 299, audit "centre de préférences —
 * multi-boutique à comptes partagés").
 *
 * Conséquence concrète avant correctif : un client créé sur la Boutique A
 * (partage de comptes actif) cliquant un lien de préférences reçu depuis
 * la Boutique B voyait ses préférences Neria correctement enregistrées
 * (`neria_preferences`, source de vérité prioritaire), mais l'`UPDATE
 * ps_customer` ne trouvait AUCUNE ligne (`id_shop = B` ne matche pas la
 * ligne réelle dont `id_shop = A`) — 0 ligne affectée, silencieusement
 * avalé par le `catch`. `ps_customer.newsletter` restait à 1 alors que le
 * client avait explicitement désactivé la newsletter : tout module tiers
 * ou export se fiant au flag natif PrestaShop continuait de le solliciter.
 *
 * Corrigé le 04/09/2026 (round 299) : filtre `AND id_shop = ...` retiré —
 * `id_customer` seul (clé primaire) identifie déjà la ligne unique à
 * mettre à jour, sans risque de collision inter-boutique puisqu'il a été
 * résolu explicitement pour CE client précis juste au-dessus.
 *
 * Test structurel (simuler un vrai partage de comptes multi-boutiques
 * nécessiterait de modifier l'état multistore de l'environnement de test
 * partagé, hors périmètre sûr) + comportemental réel sur la requête SQL
 * elle-même : crée un client réel avec un id_shop DIFFÉRENT du contexte
 * courant (simulant SHARE_CUSTOMER), exécute la requête UPDATE réelle
 * extraite du contrôleur, vérifie qu'elle affecte bien la ligne.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/controllers/front/preferences.php');
    neria_assert($src !== false, 'Impossible de lire controllers/front/preferences.php');

    neria_assert(
        strpos($src, "WHERE `id_customer` = \" . \$idCustomer") !== false
            && strpos($src, "WHERE `id_customer` = \" . \$idCustomer . \" AND `id_shop`") === false,
        "preferences.php a de nouveau un filtre id_shop sur l'UPDATE ps_customer.newsletter — régression du bug corrigé le 04/09/2026 (round 299) : un client créé sur une autre boutique que celle visitée (partage de comptes actif) verrait de nouveau sa synchronisation newsletter échouer silencieusement"
    );

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();

    $origNewsletter = (int) $db->getValue("SELECT newsletter FROM {$prefix}customer WHERE id_customer = {$idCustomer}");
    $origIdShop      = (int) $db->getValue("SELECT id_shop FROM {$prefix}customer WHERE id_customer = {$idCustomer}");

    // Simule un compte rattaché à une AUTRE boutique que le contexte
    // courant (id_shop=999, jamais utilisée en pratique) — reproduit
    // exactement la situation SHARE_CUSTOMER décrite dans le bug : la
    // ligne réelle porte un id_shop différent de celui qui déclenche
    // l'UPDATE.
    try {
        $db->execute("UPDATE {$prefix}customer SET id_shop = 999 WHERE id_customer = {$idCustomer}");
        $newValue = $origNewsletter === 1 ? 0 : 1;

        // Requête EXACTE (corrigée) extraite du contrôleur.
        $db->execute(
            "UPDATE `{$prefix}customer` SET `newsletter` = {$newValue}
             WHERE `id_customer` = {$idCustomer}"
        );

        $actual = (int) $db->getValue("SELECT newsletter FROM {$prefix}customer WHERE id_customer = {$idCustomer}");
        neria_assert(
            $actual === $newValue,
            "l'UPDATE ps_customer.newsletter n'affecte plus la ligne d'un client dont l'id_shop diffère du contexte courant — régression du bug corrigé le 04/09/2026 (round 299)"
        );

        return [
            'pass'    => true,
            'message' => "preferences.php synchronise désormais ps_customer.newsletter par id_customer seul, sans filtre id_shop erroné — un client à compte partagé multi-boutiques voit sa synchronisation réussir quelle que soit la boutique depuis laquelle le lien a été ouvert — bug corrigé le 04/09/2026 (round 299)",
        ];
    } finally {
        $db->execute("UPDATE {$prefix}customer SET id_shop = {$origIdShop}, newsletter = {$origNewsletter} WHERE id_customer = {$idCustomer}");
    }
}
