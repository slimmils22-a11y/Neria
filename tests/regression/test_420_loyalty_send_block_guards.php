<?php
/**
 * Régression : LoyaltyManager::sendRecapToCustomer()/sendRewardEmail()
 * n'avaient pas de garde-fous bounce/blacklist avant Mail::Send() — seules
 * les préférences par catégorie étaient vérifiées.
 *
 * Bug réel identifié le 23/08/2026 (round 197) : sendRecapToCustomer()
 * gate le throttle mensuel global CONFIG_RECAP_LAST_SENT (posé une fois
 * pour tous les clients après la boucle appelante) — un client bloqué au
 * moment du cron mensuel ratait son récap ce mois-ci sans aucune alerte
 * Watchdog (le hook global bloque bien l'envoi réel mais Mail::Send()
 * renvoie toujours true). sendRewardEmail() a la même lacune, impact plus
 * faible (le bon d'achat est déjà accordé inconditionnellement avant
 * l'envoi) mais affecte la précision du log de succès.
 *
 * Corrigé le 23/08/2026 (round 197) : BounceManager::isBounced() et
 * BlacklistManager::isBlacklisted() ajoutés dans les 2 méthodes, avant
 * Mail::Send().
 *
 * Test structurel (une vraie fixture points/paliers/récap nécessiterait un
 * jeu de données complet, hors périmètre d'un test isolé) : vérifie que
 * les garde-fous précèdent bien Mail::Send() dans les 2 méthodes.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/LoyaltyManager.php');
    neria_assert($src !== false, 'Impossible de lire src/LoyaltyManager.php');

    // sendRewardEmail()
    $posReward = strpos($src, 'private function sendRewardEmail(');
    neria_assert($posReward !== false, 'sendRewardEmail() introuvable — jeu de test invalide');
    $posRewardMail = strpos($src, "return (bool) \\Mail::Send(", $posReward);
    neria_assert($posRewardMail !== false, 'Appel Mail::Send() introuvable dans sendRewardEmail() — jeu de test invalide');
    $posRewardBounce = strpos($src, "\\BounceManager::isBounced(\$customer->email)", $posReward);
    $posRewardBlacklist = strpos($src, "BlacklistManager(\$idShop))->isBlacklisted('loyalty_tier_upgrade'", $posReward);
    neria_assert(
        $posRewardBounce !== false && $posRewardBounce < $posRewardMail,
        "LoyaltyManager::sendRewardEmail() ne vérifie plus BounceManager avant Mail::Send() — régression du bug corrigé le 23/08/2026 (round 197)"
    );
    neria_assert(
        $posRewardBlacklist !== false && $posRewardBlacklist < $posRewardMail,
        "LoyaltyManager::sendRewardEmail() ne vérifie plus BlacklistManager avant Mail::Send() — régression du bug corrigé le 23/08/2026 (round 197)"
    );

    // sendRecapToCustomer()
    $posRecap = strpos($src, 'private function sendRecapToCustomer(');
    neria_assert($posRecap !== false, 'sendRecapToCustomer() introuvable — jeu de test invalide');
    $posRecapMail = strpos($src, '$sent = (bool) \Mail::Send(', $posRecap);
    neria_assert($posRecapMail !== false, 'Appel Mail::Send() introuvable dans sendRecapToCustomer() — jeu de test invalide');
    $posRecapBounce = strpos($src, "\\BounceManager::isBounced(\$customer->email)", $posRecap);
    $posRecapBlacklist = strpos($src, "BlacklistManager(\$realIdShop))->isBlacklisted('loyalty_recap'", $posRecap);
    neria_assert(
        $posRecapBounce !== false && $posRecapBounce < $posRecapMail,
        "LoyaltyManager::sendRecapToCustomer() ne vérifie plus BounceManager avant Mail::Send() — régression du bug corrigé le 23/08/2026 (round 197) : un client bloqué raterait de nouveau son récap mensuel sans alerte"
    );
    neria_assert(
        $posRecapBlacklist !== false && $posRecapBlacklist < $posRecapMail,
        "LoyaltyManager::sendRecapToCustomer() ne vérifie plus BlacklistManager avant Mail::Send() — régression du bug corrigé le 23/08/2026 (round 197)"
    );

    return [
        'pass'    => true,
        'message' => "LoyaltyManager::sendRewardEmail()/sendRecapToCustomer() vérifient bien bounce/blacklist avant Mail::Send() — bug corrigé le 23/08/2026 (round 197)",
    ];
}
