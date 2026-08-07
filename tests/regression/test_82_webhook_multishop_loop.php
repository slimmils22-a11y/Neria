<?php
/**
 * Régression : neria.php::runBackgroundJobs() doit instancier
 * WebhookManager DANS une boucle sur Shop::getShops(), avec bascule du
 * contexte — pas une seule fois, sinon seule la boutique du contexte
 * courant voit sa file de webhooks traitée.
 *
 * Bug réel corrigé le 06/08/2026 (round 78) : même défaut que
 * CalendarManager (round 76) et SeasonalCampaignManager (round 77).
 * WebhookManager capture $this->idShop dans son constructeur et
 * processQueue() filtre sa sélection SQL sur cette seule boutique. Les
 * webhooks en attente d'une boutique différente de celle du contexte
 * courant restaient indéfiniment 'pending', jamais traités.
 *
 * Non vérifiable en conditions multi-boutiques réelles sur cet
 * environnement de dev (une seule boutique configurée) — même limite que
 * test_37/test_40/test_58/test_80/test_81. Vérifie donc au niveau du code
 * source que la boucle par boutique est bien en place.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $pos = strpos($src, "// ── Queue webhook (toutes les 5 min)");
    neria_assert($pos !== false, "bloc queue webhook introuvable dans runBackgroundJobs()");

    $block = substr($src, $pos, 2600);

    neria_assert(
        strpos($block, '\Shop::getShops(true, null, true)') !== false,
        "runBackgroundJobs() ne boucle plus sur Shop::getShops() pour WebhookManager — régression du bug corrigé le 06/08/2026 (round 78) : seule la boutique du contexte courant verrait de nouveau sa file de webhooks traitée"
    );
    neria_assert(
        strpos($block, 'foreach ($shopsWebhook as $idShopWebhook) {') !== false
        && strpos($block, 'new \Shop((int) $idShopWebhook)') !== false
        && strpos($block, 'new WebhookManager($this)') !== false,
        "runBackgroundJobs() n'instancie plus WebhookManager à l'intérieur de la boucle par boutique avec bascule du contexte — régression du bug corrigé le 06/08/2026 (round 78)"
    );

    return ['pass' => true, 'message' => "runBackgroundJobs() instancie bien WebhookManager dans une boucle sur toutes les boutiques actives"];
}
