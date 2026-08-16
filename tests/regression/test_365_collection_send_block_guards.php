<?php
/**
 * Régression : CollectionManager::processCollection() n'avait pas de
 * garde-fous bounce/blacklist/cooldown avant Mail::Send() — même piège
 * Mail::Send()===true déjà corrigé pour ManualSendManager/QueueManager/
 * OrderTriggersManager/CustomerEmailHistoryManager/CertificateManager
 * (rounds 176-179) mais jamais étendu ici. Pire : la réservation
 * claimSend() (clé UNIQUE) n'était jamais libérée sur un envoi bloqué —
 * même un client débloqué plus tard ne recevait jamais son email de
 * complétion de collection.
 *
 * Corrigé le 16/08/2026 (round 180) : les 3 garde-fous ajoutés AVANT le
 * bloc Mail::Send(), avec releaseSendClaim() sur chaque blocage.
 *
 * Test structurel (une vraie fixture de collection/commandes/blacklist
 * nécessiterait une commande réelle complète, hors périmètre d'un test
 * isolé — voir test_254 pour la même contrainte sur ce fichier) : vérifie
 * que les 3 garde-fous précèdent bien Mail::Send() et libèrent la
 * réservation.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CollectionManager.php');
    neria_assert($src !== false, 'Impossible de lire src/CollectionManager.php');

    $posMailSend = strpos($src, '$mailed = \Mail::Send(');
    neria_assert($posMailSend !== false, "Appel Mail::Send() introuvable dans processCollection() — jeu de test invalide");

    $posBounce = strpos($src, "\\BounceManager::isBounced(\$customer->email)");
    $posBlacklist = strpos($src, "BlacklistManager(\$idShop))->isBlacklisted('collection_completion'");
    $posCooldown = strpos($src, "CooldownManager())->isDuplicate(\$customer->email, 'collection_completion'");

    neria_assert(
        $posBounce !== false && $posBounce < $posMailSend,
        "CollectionManager::processCollection() ne revérifie plus BounceManager avant Mail::Send() — régression du bug corrigé le 16/08/2026 (round 180)"
    );
    neria_assert(
        $posBlacklist !== false && $posBlacklist < $posMailSend,
        "CollectionManager::processCollection() ne revérifie plus BlacklistManager avant Mail::Send() — régression du bug corrigé le 16/08/2026 (round 180)"
    );
    neria_assert(
        $posCooldown !== false && $posCooldown < $posMailSend,
        "CollectionManager::processCollection() ne revérifie plus CooldownManager avant Mail::Send() — régression du bug corrigé le 16/08/2026 (round 180)"
    );

    $guardsBlock = substr($src, $posBounce, $posMailSend - $posBounce);
    neria_assert(
        substr_count($guardsBlock, 'releaseSendClaim($colId, $idCustomer, $idShop)') === 3,
        "Les 3 garde-fous ajoutés ne libèrent plus systématiquement releaseSendClaim() sur blocage — régression du bug corrigé le 16/08/2026 (round 180) : un envoi bloqué laisserait de nouveau le client exclu à vie de cette notification"
    );

    return [
        'pass'    => true,
        'message' => "CollectionManager::processCollection() revérifie bien bounce/blacklist/cooldown avant Mail::Send(), en libérant la réservation sur blocage — bug corrigé le 16/08/2026 (round 180)",
    ];
}
