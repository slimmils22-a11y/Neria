<?php
/**
 * Régression : WebhookManager::trigger()/processQueue()/sendTest() doivent
 * résoudre NERIA_WEBHOOK_URL/NERIA_WEBHOOK_SECRET/NERIA_WEBHOOK_EVENTS via
 * Configuration::get(..., null, null, $this->idShop), pas le contexte
 * d'exécution ambiant.
 *
 * Bug réel corrigé le 08/08/2026 (round 116) : même piège que rounds
 * 111-115. neria.php boucle sur toutes les boutiques actives et réassigne
 * Context::getContext()->shop = new \Shop($idShopWebhook) avant d'instancier
 * WebhookManager — ce qui met bien à jour $this->idShop (propriété d'objet,
 * correctement scopée, comme le prouvent les requêtes SQL WHERE id_shop =
 * $this->idShop dans le même fichier), mais PAS Shop::$context_id_shop, la
 * variable statique interne dont dépend Configuration::get() sans $idShop
 * explicite. Sur une install multi-boutiques où chaque boutique a sa propre
 * URL/secret webhook (Zapier boutique A, Make.com boutique B), les
 * événements de la boutique B étaient livrés à l'URL et signés avec le
 * secret HMAC de la boutique ambiante réelle (typiquement la première
 * visitée) — fuite de données cross-shop vers le mauvais système tiers, et
 * l'intégration de la boutique B ne recevait jamais ses propres événements.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WebhookManager.php');
    neria_assert($src !== false, 'Impossible de lire src/WebhookManager.php');

    $countUrl    = substr_count($src, "\\Configuration::get(self::CONFIG_URL, null, null, \$this->idShop)");
    $countSecret = substr_count($src, "\\Configuration::get(self::CONFIG_SECRET, null, null, \$this->idShop)");
    $countEvents = substr_count($src, "\\Configuration::get(self::CONFIG_EVENTS, null, null, \$this->idShop)");

    neria_assert(
        $countUrl === 3,
        "WebhookManager résout CONFIG_URL via \$this->idShop à seulement {$countUrl} emplacement(s) au lieu de 3 (trigger() + processQueue() + sendTest()) — régression du bug corrigé le 08/08/2026 (round 116)"
    );
    neria_assert(
        $countSecret === 2,
        "WebhookManager résout CONFIG_SECRET via \$this->idShop à seulement {$countSecret} emplacement(s) au lieu de 2 (processQueue() + sendTest()) — régression du bug corrigé le 08/08/2026 (round 116)"
    );
    neria_assert(
        $countEvents === 1,
        "WebhookManager::trigger() ne résout plus CONFIG_EVENTS via \$this->idShop — régression du bug corrigé le 08/08/2026 (round 116)"
    );

    return [
        'pass'    => true,
        'message' => "WebhookManager résout bien CONFIG_URL/CONFIG_SECRET/CONFIG_EVENTS via \$this->idShop dans trigger()/processQueue()/sendTest(), pas le contexte d'exécution ambiant",
    ];
}
