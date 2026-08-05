<?php
/**
 * Régression : SeasonalCampaignManager::runDueCampaigns() doit filtrer /
 * écrire id_shop dans neria_behavioral_sent, comme tous les autres
 * appelants de cette table (BehavioralCronManager, ManualSendManager,
 * QueueManager).
 *
 * Bug réel corrigé le 05/08/2026 (round 57) : le SELECT de déduplication
 * annuelle et l'INSERT IGNORE qui la pose ignoraient tous deux id_shop.
 * Toute ligne tombait alors sur le défaut id_shop=1 de la colonne (voir
 * sql/install.sql, table 12) quelle que soit la vraie boutique. Sur une
 * install multi-boutiques à client partagé, purger la boutique 1 (RGPD)
 * supprimait au passage la dédup de TOUTES les autres boutiques (double
 * envoi la même année), et purger une autre boutique ne nettoyait jamais
 * ces lignes mal étiquetées.
 *
 * Non vérifiable en conditions multi-boutiques réelles sur cet environnement
 * de dev (une seule boutique configurée, id_shop=1) — même limite que
 * test_37/test_40. Vérifie donc au niveau du code source que id_shop reste
 * bien présent dans les deux requêtes (garde-fou structurel), plutôt qu'un
 * comportement observable ici.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SeasonalCampaignManager.php');
    neria_assert($src !== false, 'Impossible de lire src/SeasonalCampaignManager.php');

    neria_assert(
        strpos($src, "AND ref_id      = {\$year}\n                       AND id_shop     = \" . (int) \$this->idShop") !== false,
        "runDueCampaigns() ne filtre plus id_shop dans le SELECT de déduplication annuelle — régression du bug corrigé le 05/08/2026 (round 57)"
    );

    neria_assert(
        strpos($src, "(id_customer, template, ref_id, id_shop, sent_at)") !== false
        && strpos($src, "VALUES ({\$idCustomer}, '\" . pSQL(\$sentKey) . \"', {\$year}, \" . (int) \$this->idShop . \", NOW())") !== false,
        "runDueCampaigns() n'écrit plus id_shop dans l'INSERT IGNORE de neria_behavioral_sent — régression du bug corrigé le 05/08/2026 (round 57)"
    );

    return ['pass' => true, 'message' => "runDueCampaigns() reste scopé par id_shop dans sa déduplication annuelle (SELECT + INSERT)"];
}
