<?php
/**
 * Régression : neria.php::runBackgroundJobs() doit instancier
 * DomainReputationManager DANS une boucle sur Shop::getShops(), avec
 * bascule du contexte — pas une seule fois, sinon seule la boutique du
 * contexte courant voit sa réputation de domaine rafraîchie.
 *
 * Bug réel corrigé le 06/08/2026 (round 79) : 4e occurrence du même défaut
 * que CalendarManager (76), SeasonalCampaignManager (77) et WebhookManager
 * (78). DomainReputationManager capture $this->idShop dans son constructeur
 * et scope tout son cache (throttle 24h + rapport SPF/DKIM/DMARC/RBL) sur
 * cette seule boutique. Les autres boutiques gardaient un cache figé
 * indéfiniment, sans jamais être alertées d'une dégradation réelle.
 *
 * Non vérifiable en conditions multi-boutiques réelles sur cet
 * environnement de dev (une seule boutique configurée) — même limite que
 * test_80/test_81/test_82. Vérifie donc au niveau du code source que la
 * boucle par boutique est bien en place.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $pos = strpos($src, "// ── Réputation de domaine (rafraîchissement auto 24h)");
    neria_assert($pos !== false, "bloc réputation de domaine introuvable dans runBackgroundJobs()");

    $block = substr($src, $pos, 1600);

    neria_assert(
        strpos($block, '\Shop::getShops(true, null, true)') !== false,
        "runBackgroundJobs() ne boucle plus sur Shop::getShops() pour DomainReputationManager — régression du bug corrigé le 06/08/2026 (round 79) : seule la boutique du contexte courant verrait de nouveau sa réputation de domaine rafraîchie"
    );
    neria_assert(
        strpos($block, 'foreach ($shopsDR as $idShopDR) {') !== false
        && strpos($block, 'new \Shop((int) $idShopDR)') !== false
        && strpos($block, 'new DomainReputationManager($this)') !== false,
        "runBackgroundJobs() n'instancie plus DomainReputationManager à l'intérieur de la boucle par boutique avec bascule du contexte — régression du bug corrigé le 06/08/2026 (round 79)"
    );

    return ['pass' => true, 'message' => "runBackgroundJobs() instancie bien DomainReputationManager dans une boucle sur toutes les boutiques actives"];
}
