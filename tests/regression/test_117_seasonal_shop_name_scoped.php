<?php
/**
 * Régression : SeasonalCampaignManager::runDueCampaigns() doit résoudre
 * {shop_name} via Configuration::get('PS_SHOP_NAME', null, null, $this->idShop),
 * pas le contexte d'exécution ambiant.
 *
 * Bug réel corrigé le 08/08/2026 (round 113) : neria.php boucle sur toutes
 * les boutiques actives et réassigne Context::getContext()->shop = new
 * \Shop($idShopSeasonal) avant d'instancier SeasonalCampaignManager — ce qui
 * met bien à jour $this->idShop (capturé au constructeur depuis
 * Context::getContext()->shop->id, donc correctement scopé, comme le
 * prouvent {shop_url}/{history_url} juste en-dessous dans le même bloc de
 * variables), mais PAS Shop::$context_id_shop, la variable statique interne
 * dont dépend Configuration::get() sans $idShop explicite (seul
 * Shop::setContext() la modifie). Même piège déjà corrigé pour PS_SHOP_NAME
 * dans 8 managers au round 106, et pour d'autres clés Configuration dans
 * MonthlyReportManager (round 111) et BehavioralCronManager (round 112).
 * Sur une install multi-boutiques à noms distincts, un client d'une
 * boutique B recevait un email de campagne saisonnière affichant le nom de
 * la boutique ambiante réelle (typiquement la première visitée), pas le
 * sien.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SeasonalCampaignManager.php');
    neria_assert($src !== false, 'Impossible de lire src/SeasonalCampaignManager.php');

    neria_assert(
        strpos($src, "\\Configuration::get('PS_SHOP_NAME', null, null, \$this->idShop)") !== false,
        "SeasonalCampaignManager::runDueCampaigns() ne résout plus {shop_name} via \$this->idShop — régression du bug corrigé le 08/08/2026 (round 113) : le nom de boutique retomberait de nouveau sur le contexte d'exécution ambiant plutôt que celle du client destinataire"
    );

    return [
        'pass'    => true,
        'message' => "SeasonalCampaignManager::runDueCampaigns() résout bien {shop_name} via \$this->idShop, pas le contexte d'exécution ambiant",
    ];
}
