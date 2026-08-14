<?php
/**
 * Régression : WaitlistManager::notifyProduct() sommait le stock via
 * `WHERE id_product = X AND id_shop = $idShop`. En mode "stock partagé"
 * entre boutiques d'un même groupe (Shop::getGroup()->share_stock=1),
 * PrestaShop stocke la quantité réelle sur UNE SEULE ligne stock_available
 * avec id_shop=0/id_shop_group=X (cf. StockAvailable::addSqlShopRestriction()
 * dans le cœur PS) — jamais sur une ligne id_shop=$idShop. Le SUM
 * renvoyait donc systématiquement 0 dans ce mode, empêchant TOUT inscrit
 * d'être jamais notifié malgré du stock réellement disponible.
 *
 * Corrigé le 14/08/2026 (round 167) : bascule vers id_shop=0/id_shop_group=X
 * quand le groupe de la boutique a le stock partagé activé, comme le fait
 * le cœur PrestaShop.
 *
 * Test structurel (activer réellement le partage de stock modifierait un
 * réglage global de la boutique de test partagée par toute la suite — trop
 * invasif) : vérifie que la logique de bascule est bien présente et
 * cohérente avec celle du cœur PS.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WaitlistManager.php');
    neria_assert($src !== false, 'Impossible de lire WaitlistManager.php');

    $posFn = strpos($src, 'private function notifyProductLocked(int $idProduct, int $idShop): int');
    neria_assert($posFn !== false, 'notifyProductLocked() introuvable — jeu de test invalide');
    $body = substr($src, $posFn, 3600);

    neria_assert(
        strpos($body, '->getGroup()->share_stock') !== false,
        "notifyProductLocked() ne vérifie plus le mode stock partagé du groupe de boutiques — régression du bug corrigé le 14/08/2026 (round 167) : aucun inscrit ne serait de nouveau jamais notifié pour une boutique en stock partagé"
    );
    neria_assert(
        strpos($body, 'id_shop = 0 AND id_shop_group = ') !== false,
        "notifyProductLocked() ne bascule plus vers id_shop=0/id_shop_group=X en mode partagé — régression du bug corrigé le 14/08/2026 (round 167)"
    );

    return [
        'pass'    => true,
        'message' => "WaitlistManager::notifyProductLocked() gère bien le mode stock partagé entre boutiques d'un groupe — bug corrigé le 14/08/2026 (round 167)",
    ];
}
