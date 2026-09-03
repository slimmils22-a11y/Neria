<?php
/**
 * Régression : `SeasonalCampaignManager::runDueCampaigns()` ne
 * revérifiait jamais le statut `active`/`deleted` du CLIENT juste avant
 * `Mail::Send()` — `getEligibleCustomers()` filtre `active=1`/
 * `deleted=0` UNIQUEMENT au moment du SELECT initial, et n'a
 * explicitement AUCUN `LIMIT` (round 256 : "sans LIMIT sur
 * getEligibleCustomers(), un gros ciblage pouvait continuer à envoyer
 * pendant plusieurs minutes"). Un client désactivé en BO ou ayant
 * exercé son droit à l'effacement RGPD PENDANT l'exécution du lot
 * continuait de recevoir la campagne aux itérations suivantes, avec des
 * données déjà périmées en RAM (prénom/nom).
 *
 * Bug identifié le 02/09/2026 (round 286, audit "revalidation client
 * entre sélection cron et envoi").
 *
 * Corrigé le 02/09/2026 (round 286) : relecture fraîche de
 * `active`/`deleted` juste avant `Mail::Send()`, dans le même bloc que
 * les contrôles bounce/blacklist/cooldown déjà existants — sur
 * blocage, la réservation annuelle (`claimSend()`) est libérée comme
 * pour ces autres cas, pour ne pas exclure le client à vie de cette
 * campagne.
 *
 * Test structurel : reproduire un batch de plusieurs minutes avec une
 * désactivation client synchronisée en plein milieu nécessiterait un
 * scénario multi-process hors de portée raisonnable d'un test CLI
 * unitaire (voir test_68/test_532 pour la technique de verrou
 * concurrent — non applicable ici, ce n'est pas un verrou mais une
 * relecture d'état) — vérifie la présence du contrôle dans le code
 * source, avant l'appel Mail::Send(), avec libération de la
 * réservation.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SeasonalCampaignManager.php');
    neria_assert($src !== false, 'Impossible de lire src/SeasonalCampaignManager.php');

    $posMethod = strpos($src, 'public function runDueCampaigns(): int');
    neria_assert($posMethod !== false, 'runDueCampaigns() introuvable — jeu de test invalide');

    $posCheck = strpos($src, "SELECT `active`, `deleted` FROM `' . \$this->prefix . 'customer`", $posMethod);
    neria_assert($posCheck !== false, "SeasonalCampaignManager::runDueCampaigns() n'a plus la relecture active/deleted attendue — régression du bug corrigé le 02/09/2026 (round 286)");

    $posMail = strpos($src, '$ok = \Mail::Send(', $posMethod);
    neria_assert($posMail !== false, 'Appel Mail::Send() introuvable — jeu de test invalide');
    neria_assert(
        $posCheck < $posMail,
        "SeasonalCampaignManager::runDueCampaigns() ne revérifie plus active/deleted AVANT Mail::Send() — régression du bug corrigé le 02/09/2026 (round 286) : un client désactivé/GDPR-purgé en cours de lot recevrait de nouveau la campagne"
    );

    $body = substr($src, $posCheck, 600);
    neria_assert(
        strpos($body, 'releaseSendClaim($idCustomer, $sentKey, $year)') !== false
            && strpos($body, "(int) \$customerRow['active'] !== 1") !== false
            && strpos($body, "(int) \$customerRow['deleted'] !== 0") !== false,
        "SeasonalCampaignManager::runDueCampaigns() ne libère plus la réservation annuelle sur un client devenu inéligible — régression du bug corrigé le 02/09/2026 (round 286) : le client resterait exclu à vie de cette campagne pour l'année, même si son statut redevient valide plus tard"
    );

    return [
        'pass'    => true,
        'message' => "SeasonalCampaignManager::runDueCampaigns() relit désormais l'état active/deleted du client juste avant l'envoi, avec libération de la réservation sur blocage — bug corrigé le 02/09/2026 (round 286)",
    ];
}
