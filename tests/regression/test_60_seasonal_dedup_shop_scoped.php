<?php
/**
 * Régression : SeasonalCampaignManager::claimSend()/releaseSendClaim()
 * doivent filtrer/écrire id_shop dans neria_behavioral_sent, comme tous
 * les autres appelants de cette table (BehavioralCronManager,
 * ManualSendManager, QueueManager).
 *
 * Bug réel corrigé le 05/08/2026 (round 57) : le SELECT de déduplication
 * annuelle et l'INSERT IGNORE qui la posait ignoraient tous deux id_shop.
 * Toute ligne tombait alors sur le défaut id_shop=1 de la colonne (voir
 * sql/install.sql, table 12) quelle que soit la vraie boutique. Sur une
 * install multi-boutiques à client partagé, purger la boutique 1 (RGPD)
 * supprimait au passage la dédup de TOUTES les autres boutiques (double
 * envoi la même année), et purger une autre boutique ne nettoyait jamais
 * ces lignes mal étiquetées.
 *
 * Round 143 : le SELECT COUNT(*) + INSERT IGNORE après coup a été remplacé
 * par une réservation atomique claimSend()/releaseSendClaim() (voir
 * test_189) — ce test est mis à jour pour vérifier le scoping id_shop sur
 * ce nouveau helper plutôt que sur l'ancien code disparu.
 *
 * Non vérifiable en conditions multi-boutiques réelles sur cet environnement
 * de dev (une seule boutique configurée, id_shop=1) — même limite que
 * test_37/test_40. Vérifie donc au niveau du code source que id_shop reste
 * bien présent, plutôt qu'un comportement observable ici.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SeasonalCampaignManager.php');
    neria_assert($src !== false, 'Impossible de lire src/SeasonalCampaignManager.php');

    $posClaim = strpos($src, 'private function claimSend(int $idCustomer, string $sentKey, int $year): bool');
    neria_assert($posClaim !== false, "claimSend() introuvable — régression du bug corrigé le 09/08/2026 (round 143) : la réservation atomique anti-doublon a disparu");
    $claimBody = substr($src, $posClaim, 500);

    neria_assert(
        strpos($claimBody, '(id_customer, template, ref_id, id_shop, sent_at)') !== false
        && strpos($claimBody, "VALUES ({\$idCustomer}, '\" . pSQL(\$sentKey) . \"', {\$year}, \" . (int) \$this->idShop . \", NOW())") !== false,
        "claimSend() n'écrit plus id_shop dans l'INSERT IGNORE de neria_behavioral_sent — régression du bug corrigé le 05/08/2026 (round 57)"
    );

    $posRelease = strpos($src, 'private function releaseSendClaim(int $idCustomer, string $sentKey, int $year): void');
    neria_assert($posRelease !== false, "releaseSendClaim() introuvable — régression du bug corrigé le 09/08/2026 (round 143)");
    $releaseBody = substr($src, $posRelease, 400);
    neria_assert(
        strpos($releaseBody, "' AND `id_shop` = ' . (int) \$this->idShop") !== false,
        "releaseSendClaim() ne filtre plus id_shop — régression du bug corrigé le 05/08/2026 (round 57) : libérerait la réservation d'une autre boutique"
    );

    return ['pass' => true, 'message' => "claimSend()/releaseSendClaim() restent scopés par id_shop"];
}
