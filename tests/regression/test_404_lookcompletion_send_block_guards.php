<?php
/**
 * Régression : LookCompletionManager::runDailyCheck() n'avait pas de
 * garde-fous bounce/blacklist/cooldown avant Mail::Send() — même piège
 * Mail::Send()===true déjà corrigé pour CollectionManager (round 180, cf.
 * test_365) mais jamais étendu ici. Pire : la réservation claimSend() (clé
 * UNIQUE uq_order) n'était jamais libérée sur un envoi bloqué — même un
 * client débloqué plus tard ne recevait jamais son email "complétez votre
 * look" pour cette commande.
 *
 * Corrigé le 23/08/2026 (round 190) : les 3 garde-fous ajoutés AVANT le
 * bloc Mail::Send(), avec releaseSendClaim() sur chaque blocage.
 *
 * Test structurel (une vraie fixture de commande/livraison/blacklist
 * nécessiterait une commande réelle complète, hors périmètre d'un test
 * isolé — même contrainte que test_365 pour CollectionManager) : vérifie
 * que les 3 garde-fous précèdent bien Mail::Send() et libèrent la
 * réservation.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/LookCompletionManager.php');
    neria_assert($src !== false, 'Impossible de lire src/LookCompletionManager.php');

    $posMailSend = strpos($src, '$mailed = \Mail::Send(');
    neria_assert($posMailSend !== false, "Appel Mail::Send() introuvable dans runDailyCheck() — jeu de test invalide");

    $posBounce = strpos($src, "\\BounceManager::isBounced(\$customer->email)");
    $posBlacklist = strpos($src, "BlacklistManager(\$idShop))->isBlacklisted('complete_your_look'");
    $posCooldown = strpos($src, "CooldownManager())->isDuplicate(\$customer->email, 'complete_your_look'");

    neria_assert(
        $posBounce !== false && $posBounce < $posMailSend,
        "LookCompletionManager::runDailyCheck() ne vérifie plus BounceManager avant Mail::Send() — régression du bug corrigé le 23/08/2026 (round 190)"
    );
    neria_assert(
        $posBlacklist !== false && $posBlacklist < $posMailSend,
        "LookCompletionManager::runDailyCheck() ne vérifie plus BlacklistManager avant Mail::Send() — régression du bug corrigé le 23/08/2026 (round 190)"
    );
    neria_assert(
        $posCooldown !== false && $posCooldown < $posMailSend,
        "LookCompletionManager::runDailyCheck() ne vérifie plus CooldownManager avant Mail::Send() — régression du bug corrigé le 23/08/2026 (round 190)"
    );

    $guardsBlock = substr($src, $posBounce, $posMailSend - $posBounce);
    neria_assert(
        substr_count($guardsBlock, 'releaseSendClaim($idOrder, $idCustomer)') === 3,
        "Les 3 garde-fous ajoutés ne libèrent plus systématiquement releaseSendClaim() sur blocage — régression du bug corrigé le 23/08/2026 (round 190) : un envoi bloqué laisserait de nouveau le client exclu à vie de cette notification pour cette commande"
    );

    return [
        'pass'    => true,
        'message' => "LookCompletionManager::runDailyCheck() revérifie bien bounce/blacklist/cooldown avant Mail::Send(), en libérant la réservation sur blocage — bug corrigé le 23/08/2026 (round 190)",
    ];
}
