<?php
/**
 * Régression : Neria::searchCustomersForHistory() (recherche client dans
 * l'historique d'emails BO) n'échappait pas les métacaractères LIKE (%, _)
 * avant pSQL(), contrairement à ManualSendManager::searchCustomers() qui
 * applique déjà ce correctif.
 *
 * Bug réel identifié le 24/08/2026 (round 203) : un "_" dans la recherche
 * matche n'importe quel caractère en SQL LIKE — chercher "Alph_ville"
 * retournait alors à tort le client "Alphaville" (le "_" agissant comme
 * wildcard sur le "a"), polluant silencieusement les résultats affichés à
 * l'employé BO au lieu de ne renvoyer que des correspondances où "_" est
 * un caractère littéral.
 *
 * Corrigé le 24/08/2026 (round 203) : addcslashes($query, '%_') appliqué
 * avant pSQL(), même correctif que ManualSendManager::searchCustomers().
 *
 * Test comportemental réel : seed un client "Alphaville", recherche avec
 * "Alph_ville" (underscore à la place du "a") et vérifie qu'il ne matche
 * PAS — le "_" doit être traité comme caractère littéral, pas wildcard.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $module = neria_test_module();
    $ref = new ReflectionMethod('Neria', 'searchCustomersForHistory');
    $ref->setAccessible(true);

    $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
    $email  = 'round203alphaville@example.test';
    $idCustomer = (int) Customer::customerExists($email, true);

    try {
        if (!$idCustomer) {
            $c = new Customer();
            $c->firstname = 'Alphaville';
            $c->lastname  = 'Roundtest';
            $c->email     = $email;
            $c->passwd    = Tools::hash('round203test');
            $c->id_lang   = $idLang;
            $c->add();
            $idCustomer = (int) $c->id;
        }

        // Contrôle : la recherche exacte trouve bien le client.
        $exact = $ref->invoke($module, 'Alphaville');
        neria_assert(
            in_array($idCustomer, array_column($exact, 'id'), true),
            "La recherche exacte 'Alphaville' devrait trouver le client seedé — jeu de test invalide"
        );

        // "_" à la place du "a" : ne doit PAS matcher via wildcard une fois
        // l'échappement en place — LIKE '%Alph_ville%' matcherait
        // "Alphaville" si "_" n'est pas échappé (wildcard 1-caractère).
        $withWildcard = $ref->invoke($module, 'Alph_ville');
        neria_assert(
            !in_array($idCustomer, array_column($withWildcard, 'id'), true),
            "Neria::searchCustomersForHistory() n'échappe plus les métacaractères LIKE — régression du bug corrigé le 24/08/2026 (round 203) : la recherche 'Alph_ville' matche à tort 'Alphaville' via le wildcard '_' non échappé"
        );
    } finally {
        if ($idCustomer) {
            $c = new Customer($idCustomer);
            if (Validate::isLoadedObject($c)) { $c->delete(); }
        }
    }

    return [
        'pass'    => true,
        'message' => "Neria::searchCustomersForHistory() échappe bien les métacaractères LIKE avant pSQL() — bug corrigé le 24/08/2026 (round 203)",
    ];
}
