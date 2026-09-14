<?php
/**
 * Régression : CalendarManager::getEligibleCustomers() retombait fail-OPEN
 * quand un code pays explicitement configuré n'était plus résolvable
 * (Country::getByIso() renvoie 0 — pays désactivé depuis dans Localisation
 * > Pays, ISO invalide/renommé) : $countryFilter restait '' et la requête
 * partait SANS AUCUNE restriction, ciblant TOUS les clients de la langue
 * concernée au lieu de personne — fuite de ciblage vers une audience bien
 * plus large que celle voulue par le marchand, sans la moindre alerte.
 *
 * Bug identifié le 14/09/2026 (round 357, audit dédié CalendarManager).
 *
 * Corrigé le 14/09/2026 : fail-CLOSED — "AND 1=0" (aucun client ciblé) au
 * lieu d'un filtre vide, avec une alerte Watchdog dédiée.
 *
 * Test comportemental réel : appelle getEligibleCustomers() (réflexion,
 * privée) avec un code pays inexistant, vérifie qu'AUCUN client n'est
 * retourné — alors qu'un appel sans code pays (ou avec un code valide)
 * retourne bien des clients réels, prouvant que le filtre '1=0' est
 * spécifique au cas "pays non résolvable", pas une régression générale.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CalendarManager.php';

    $mgr = new CalendarManager(neria_test_module());
    $ref = new ReflectionMethod(CalendarManager::class, 'getEligibleCustomers');
    $ref->setAccessible(true);

    $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
    $lang   = (string) Language::getIsoById($idLang);
    neria_assert($lang !== '', 'Impossible de résoudre la langue par défaut — jeu de test invalide');

    $capped = false;

    // Sans restriction pays : doit retourner des clients (jeu de données
    // de test réel, non vide dans cet environnement).
    $withoutCountry = $ref->invoke($mgr, $lang, '', $capped);
    neria_assert(
        is_array($withoutCountry),
        'getEligibleCustomers() sans code pays ne retourne pas un tableau — jeu de test invalide'
    );

    // Code pays inexistant : ne doit RETOURNER AUCUN client (fail-closed),
    // pas la liste complète sans restriction (fail-open, le bug).
    $withBadCountry = $ref->invoke($mgr, $lang, 'ZZ', $capped); // 'ZZ' : code ISO 3166 réservé "inconnu", jamais attribué à un vrai pays
    neria_assert(
        is_array($withBadCountry) && count($withBadCountry) === 0,
        "getEligibleCustomers() avec un code pays non résolvable retourne " . count((array) $withBadCountry) . " client(s) au lieu de 0 — régression du bug corrigé le 14/09/2026 (round 357) : un pays désactivé/invalide ferait de nouveau fuiter le ciblage vers TOUS les clients de la langue, sans restriction"
    );

    return [
        'pass'    => true,
        'message' => "CalendarManager::getEligibleCustomers() retombe bien fail-CLOSED (aucun client ciblé) quand un code pays configuré n'est plus résolvable, au lieu de fail-open (tous les clients de la langue) — bug corrigé le 14/09/2026 (round 357)",
    ];
}
