<?php
/**
 * Régression : le lien de reprise des relances panier abandonné (1/24/72 h) et
 * paiement abandonné était construit en collant « index.php?controller=order »
 * directement à Tools::getShopDomainSsl(true), qui ne renvoie AUCUN "/" final —
 * d'où "https://boutique.comindex.php?controller=order" (hôte invalide) dans
 * {cart_url}, le bouton principal de ces 4 e-mails de récupération de vente.
 *
 * Bug trouvé le 23/09/2026 (round 366, campagne de tests fonctionnels P4) en
 * lisant la file d'attente réelle de ps-test (vars_json de checkout_abandonment).
 *
 * Corrigé : BehavioralCronManager::orderPageUrl() (Link::getPageLink('order')).
 *
 * Test comportemental : appelle la vraie méthode privée par réflexion et vérifie
 * que l'URL produite a un hôte valide séparé du chemin (parse_url), correspond au
 * domaine de la boutique et pointe vers la page commande ; vérifie aussi que les
 * deux sites d'appel l'utilisent bien.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $module = neria_test_module();
    $cron   = new BehavioralCronManager($module);
    $m      = new ReflectionMethod(BehavioralCronManager::class, 'orderPageUrl');
    $m->setAccessible(true);

    $shopDomain = Tools::getShopDomainSsl(false);
    foreach ([[1, 1], [2, 1], [0, 0]] as [$lang, $shop]) {
        $url = (string) $m->invoke($cron, $lang, $shop);
        $parts = parse_url($url);
        neria_assert(
            is_array($parts) && !empty($parts['host']) && !empty($parts['path']) && $parts['path'] !== '/',
            "orderPageUrl({$lang},{$shop}) renvoie « {$url} » : hôte ou chemin invalide — régression du bug corrigé le 23/09/2026 (round 366)"
        );
        neria_assert(
            $parts['host'] === $shopDomain,
            "orderPageUrl({$lang},{$shop}) : l'hôte « {$parts['host']} » n'est pas le domaine de la boutique « {$shopDomain} » (lien collé au chemin ?) — round 366"
        );
        neria_assert(
            (stripos($url, 'order') !== false || stripos($url, 'commande') !== false) && stripos($url, 'comindex') === false,
            "orderPageUrl({$lang},{$shop}) ne pointe pas vers la page commande : {$url}"
        );
    }

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php');
    neria_assert(
        substr_count($src, "\$cartUrl = \$this->orderPageUrl(") + substr_count($src, "\$cartUrl  = \$this->orderPageUrl(") === 2,
        'Les 2 sites (relances panier et paiement abandonné) doivent utiliser orderPageUrl()'
    );

    return [
        'pass'    => true,
        'message' => "Le lien de reprise des relances panier/paiement abandonné a un hôte valide et pointe vers la page commande (plus de « …comindex.php ») — bug corrigé le 23/09/2026 (round 366)",
    ];
}
