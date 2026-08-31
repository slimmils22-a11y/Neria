<?php
/**
 * Régression : SeasonalCampaignManager::getEligibleCustomers() ne filtrait
 * QUE sur les critères de ciblage (genre/langue/âge/segment) — jamais sur
 * `customer.newsletter`, le flag natif PrestaShop que le client décoche
 * lui-même sur son compte (ou qui est décoché en BO). Seul
 * PreferencesManager::isAllowed() (catégorie de préférence Neria dédiée,
 * opt-in PAR DÉFAUT si aucune ligne n'existe pour ce client) était revérifié
 * juste avant l'envoi.
 *
 * Bug identifié le 31/08/2026 (round 259, audit "désynchronisation entre
 * canaux de désabonnement Neria et signal natif PrestaShop") : un client
 * qui décoche la case native "Newsletter" sur son compte, SANS jamais être
 * passé par le lien/centre de préférences Neria (donc sans ligne
 * neria_preferences pour lui), reste opt-in par défaut côté Neria et
 * continue de recevoir les campagnes saisonnières malgré sa désinscription
 * explicite via ce canal légitime. `CalendarManager::getEligibleCustomers()`
 * (moteur jumeau) croise pourtant déjà correctement ce même flag
 * (`c.newsletter = 1`).
 *
 * Corrigé le 31/08/2026 (round 259) : `AND c.newsletter = 1` ajouté à
 * `getEligibleCustomers()`, alignant ce moteur sur CalendarManager.
 *
 * Test comportemental réel : deux clients de test réels, seule différence
 * `newsletter` (1 vs 0), ciblage identique (aucun segment/genre/langue/âge
 * restrictif) — après correctif, seul le client newsletter=1 doit
 * apparaître dans getEligibleCustomers().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/SeasonalCampaignManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;

    $emailSubscribed   = 'regtest498-sub-' . uniqid() . '@example.test';
    $emailUnsubscribed = 'regtest498-unsub-' . uniqid() . '@example.test';

    $mgr = new SeasonalCampaignManager(neria_test_module());

    $insertCustomer = function (string $email, int $newsletter) use ($db, $prefix, $idShop): int {
        $db->execute(
            "INSERT INTO {$prefix}customer
                (id_shop, id_shop_group, id_lang, firstname, lastname, email, passwd, newsletter, active, deleted, is_guest, date_add, date_upd)
             VALUES
                ({$idShop}, 1, 1, 'Regtest', '498', '" . pSQL($email) . "', 'x', {$newsletter}, 1, 0, 0, NOW(), NOW())"
        );
        return (int) $db->Insert_ID();
    };

    $idSubscribed   = $insertCustomer($emailSubscribed, 1);
    $idUnsubscribed = $insertCustomer($emailUnsubscribed, 0);

    try {
        neria_assert($idSubscribed > 0 && $idUnsubscribed > 0, "jeu de test invalide : l'INSERT des clients de test a échoué");

        $campaign = [
            'target_segment' => '',
            'target_gender'  => 0,
            'target_lang'    => '',
            'min_age'        => 0,
            'max_age'        => 0,
        ];

        $customers = $mgr->getEligibleCustomers($campaign);
        $ids       = array_map(fn($c) => (int) $c['id_customer'], $customers);

        neria_assert(
            in_array($idSubscribed, $ids, true),
            "SeasonalCampaignManager::getEligibleCustomers() exclut à tort un client avec newsletter=1 — jeu de test invalide ou régression du filtrage de ciblage de base"
        );
        neria_assert(
            !in_array($idUnsubscribed, $ids, true),
            "SeasonalCampaignManager::getEligibleCustomers() inclut encore un client avec newsletter=0 (désinscrit via le canal natif PrestaShop) — régression du bug corrigé le 31/08/2026 (round 259) : une campagne saisonnière serait de nouveau envoyée à un client ayant explicitement décoché la case Newsletter sur son compte"
        );

        return [
            'pass'    => true,
            'message' => "SeasonalCampaignManager::getEligibleCustomers() respecte désormais customer.newsletter, alignée sur CalendarManager — bug corrigé le 31/08/2026 (round 259)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}customer WHERE email IN ('" . pSQL($emailSubscribed) . "', '" . pSQL($emailUnsubscribed) . "')");
    }
}
