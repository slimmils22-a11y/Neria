<?php
/**
 * Régression : SeasonalCampaignManager::runDueCampaigns() n'avait pas de
 * garde-fous bounce/blacklist/cooldown avant Mail::Send() — même piège
 * Mail::Send()===true déjà corrigé pour CollectionManager (round 180) mais
 * jamais porté ici. La réservation annuelle (claimSend()) n'était libérée
 * que si Mail::Send() renvoyait false, jamais sur un blocage silencieux du
 * hook.
 *
 * Bug réel identifié le 23/08/2026 (round 195) : un client bloqué au
 * moment d'une campagne saisonnière (Noël, Black Friday...) était marqué
 * "déjà envoyé" pour TOUTE l'année, même après la levée du blocage.
 *
 * Corrigé le 23/08/2026 (round 195) : les 3 garde-fous ajoutés AVANT
 * Mail::Send(), avec releaseSendClaim() sur chaque blocage.
 *
 * Test structurel (une vraie fixture campagne/client nécessiterait un
 * jeu de données complet, hors périmètre d'un test isolé — même contrainte
 * que test_365/test_404/test_414 pour les fichiers jumeaux) : vérifie que
 * les 3 garde-fous précèdent bien Mail::Send() et libèrent la réservation.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SeasonalCampaignManager.php');
    neria_assert($src !== false, 'Impossible de lire src/SeasonalCampaignManager.php');

    $posMailSend = strpos($src, '$ok = \Mail::Send(');
    neria_assert($posMailSend !== false, "Appel Mail::Send() introuvable dans runDueCampaigns() — jeu de test invalide");

    $posBounce = strpos($src, "\\BounceManager::isBounced(\$customer['email'])");
    $posBlacklist = strpos($src, "BlacklistManager(\$this->idShop))->isBlacklisted(\$template");
    $posCooldown = strpos($src, "CooldownManager())->isDuplicate(\$customer['email'], \$template");

    neria_assert(
        $posBounce !== false && $posBounce < $posMailSend,
        "SeasonalCampaignManager::runDueCampaigns() ne vérifie plus BounceManager avant Mail::Send() — régression du bug corrigé le 23/08/2026 (round 195)"
    );
    neria_assert(
        $posBlacklist !== false && $posBlacklist < $posMailSend,
        "SeasonalCampaignManager::runDueCampaigns() ne vérifie plus BlacklistManager avant Mail::Send() — régression du bug corrigé le 23/08/2026 (round 195)"
    );
    neria_assert(
        $posCooldown !== false && $posCooldown < $posMailSend,
        "SeasonalCampaignManager::runDueCampaigns() ne vérifie plus CooldownManager avant Mail::Send() — régression du bug corrigé le 23/08/2026 (round 195)"
    );

    $guardsBlock = substr($src, $posBounce, $posMailSend - $posBounce);
    neria_assert(
        substr_count($guardsBlock, 'releaseSendClaim($idCustomer, $sentKey, $year)') === 3,
        "Les 3 garde-fous ajoutés ne libèrent plus systématiquement la réservation annuelle sur blocage — régression du bug corrigé le 23/08/2026 (round 195) : un envoi bloqué exclurait de nouveau le client de cette campagne pour toute l'année"
    );

    return [
        'pass'    => true,
        'message' => "SeasonalCampaignManager::runDueCampaigns() vérifie bien bounce/blacklist/cooldown avant Mail::Send(), en libérant la réservation sur blocage — bug corrigé le 23/08/2026 (round 195)",
    ];
}
