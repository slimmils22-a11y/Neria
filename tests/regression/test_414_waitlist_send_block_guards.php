<?php
/**
 * Régression : WaitlistManager::notifyProduct() n'avait aucun garde-fou
 * bounce/blacklist/préférences/cooldown avant Mail::Send() — même piège
 * Mail::Send()===true déjà corrigé pour CollectionManager (round 180),
 * LookCompletionManager (round 190), QueueManager/OrderTriggersManager
 * (round 178/192), mais jamais étendu ici. Pire : notified_at était marqué
 * DÉFINITIVEMENT sur un envoi bloqué (aucune contrainte UNIQUE ne protège
 * contre un nouvel essai, mais notified_at lui-même ferme la porte pour
 * toujours dans la logique métier).
 *
 * Bug réel identifié le 23/08/2026 (round 194) : un client dont l'adresse
 * est en bounce/blacklist au moment du réassort voyait sa ligne marquée
 * notified_at de façon permanente — il ne recevait plus jamais la
 * notification "de retour en stock" pour ce produit, même après la levée
 * du blocage.
 *
 * Corrigé le 23/08/2026 (round 194) : les 4 garde-fous ajoutés AVANT le
 * bloc Mail::Send(), avec libération de claim_started_at sur chaque
 * blocage (permet un nouvel essai au prochain réassort/passage cron).
 *
 * Test structurel (une vraie fixture produit/stock/réassort nécessiterait
 * un catalogue réel complet, hors périmètre d'un test isolé — même
 * contrainte que test_365/test_404 pour les fichiers jumeaux) : vérifie
 * que les 4 garde-fous précèdent bien Mail::Send() et libèrent la
 * réservation.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WaitlistManager.php');
    neria_assert($src !== false, 'Impossible de lire src/WaitlistManager.php');

    $posMailSend = strpos($src, '$mailed = \Mail::Send(');
    neria_assert($posMailSend !== false, "Appel Mail::Send() introuvable dans notifyProduct() — jeu de test invalide");

    $posBounce = strpos($src, "\\BounceManager::isBounced(\$row['email'])");
    $posBlacklist = strpos($src, "BlacklistManager(\$idShop))->isBlacklisted('waitlist_available'");
    $posPreferences = strpos($src, "PreferencesManager(\$this->module))->isAllowed(\$idCustomer, 'waitlist_available'");
    $posCooldown = strpos($src, "CooldownManager())->isDuplicate(\$row['email'], 'waitlist_available'");

    neria_assert(
        $posBounce !== false && $posBounce < $posMailSend,
        "WaitlistManager::notifyProduct() ne vérifie plus BounceManager avant Mail::Send() — régression du bug corrigé le 23/08/2026 (round 194)"
    );
    neria_assert(
        $posBlacklist !== false && $posBlacklist < $posMailSend,
        "WaitlistManager::notifyProduct() ne vérifie plus BlacklistManager avant Mail::Send() — régression du bug corrigé le 23/08/2026 (round 194)"
    );
    neria_assert(
        $posPreferences !== false && $posPreferences < $posMailSend,
        "WaitlistManager::notifyProduct() ne vérifie plus PreferencesManager avant Mail::Send() — régression du bug corrigé le 23/08/2026 (round 194)"
    );
    neria_assert(
        $posCooldown !== false && $posCooldown < $posMailSend,
        "WaitlistManager::notifyProduct() ne vérifie plus CooldownManager avant Mail::Send() — régression du bug corrigé le 23/08/2026 (round 194)"
    );

    $guardsBlock = substr($src, $posBounce, $posMailSend - $posBounce);
    neria_assert(
        substr_count($guardsBlock, 'SET claim_started_at = NULL') === 4,
        "Les 4 garde-fous ajoutés ne libèrent plus systématiquement claim_started_at sur blocage — régression du bug corrigé le 23/08/2026 (round 194) : un envoi bloqué laisserait de nouveau la réservation figée sans possibilité de nouvel essai"
    );

    return [
        'pass'    => true,
        'message' => "WaitlistManager::notifyProduct() revérifie bien bounce/blacklist/préférences/cooldown avant Mail::Send(), en libérant la réservation sur blocage — bug corrigé le 23/08/2026 (round 194)",
    ];
}
