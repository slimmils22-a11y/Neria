<?php
/**
 * Régression : neria.php::runBackgroundJobs() doit instancier
 * SeasonalCampaignManager DANS une boucle sur Shop::getShops(), avec
 * bascule du contexte — pas une seule fois, sinon seule la boutique du
 * premier visiteur front du jour reçoit les campagnes saisonnières.
 *
 * Bug réel corrigé le 06/08/2026 (round 77) : même défaut que
 * CalendarManager (round 76). SeasonalCampaignManager capture
 * $this->idShop dans son constructeur et scope TOUTES ses requêtes
 * (campagnes actives, ciblage clients, déduplication) sur cette seule
 * boutique. Une boutique à faible trafic pouvait ne JAMAIS être "la
 * première" du jour et ne recevoir aucune campagne saisonnière,
 * indéfiniment.
 *
 * Non vérifiable en conditions multi-boutiques réelles sur cet
 * environnement de dev (une seule boutique configurée) — même limite que
 * test_37/test_40/test_58/test_80. Vérifie donc au niveau du code source
 * que la boucle par boutique est bien en place.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $pos = strpos($src, "if (class_exists('SeasonalCampaignManager')) {");
    neria_assert($pos !== false, "bloc SeasonalCampaignManager introuvable dans runBackgroundJobs()");

    $block = substr($src, $pos, 2000);

    neria_assert(
        strpos($block, '\Shop::getShops(true, null, true)') !== false,
        "runBackgroundJobs() ne boucle plus sur Shop::getShops() pour SeasonalCampaignManager — régression du bug corrigé le 06/08/2026 (round 77) : seule la boutique du premier visiteur front du jour recevrait de nouveau les campagnes saisonnières"
    );
    neria_assert(
        strpos($block, 'foreach ($shopsSeasonal as $idShopSeasonal) {') !== false
        && strpos($block, 'new \Shop((int) $idShopSeasonal)') !== false
        && strpos($block, 'new SeasonalCampaignManager($this)') !== false,
        "runBackgroundJobs() n'instancie plus SeasonalCampaignManager à l'intérieur de la boucle par boutique avec bascule du contexte — régression du bug corrigé le 06/08/2026 (round 77)"
    );

    return ['pass' => true, 'message' => "runBackgroundJobs() instancie bien SeasonalCampaignManager dans une boucle sur toutes les boutiques actives"];
}
