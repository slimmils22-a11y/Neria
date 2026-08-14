<?php
/**
 * Régression : HealthCheckManager::checkWaitlistBacklog() — même bug que
 * WaitlistManager::notifyProduct() (round 167) : le SUM sur stock_available
 * filtrait `id_shop = $row['id_shop']`, jamais atteint en mode "stock
 * partagé" entre boutiques d'un groupe (la quantité réelle est stockée sur
 * id_shop=0/id_shop_group=X dans ce mode). Ce garde-fou ne détectait donc
 * JAMAIS de backlog pour une boutique en stock partagé, restant bloqué
 * sur "OK" alors que des inscrits attendaient réellement un produit
 * disponible.
 *
 * Corrigé le 14/08/2026 (round 167) : même bascule id_shop=0/id_shop_group=X
 * que WaitlistManager::notifyProductLocked().
 *
 * Test structurel : vérifie que checkWaitlistBacklog() applique bien la
 * même bascule stock partagé.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php');
    neria_assert($src !== false, 'Impossible de lire HealthCheckManager.php');

    $posFn = strpos($src, 'private function checkWaitlistBacklog(): array');
    neria_assert($posFn !== false, 'checkWaitlistBacklog() introuvable — jeu de test invalide');
    $body = substr($src, $posFn, 2900);

    neria_assert(
        strpos($body, '->getGroup()->share_stock') !== false,
        "checkWaitlistBacklog() ne vérifie plus le mode stock partagé du groupe de boutiques — régression du bug corrigé le 14/08/2026 (round 167) : ce garde-fou ne détecterait de nouveau jamais de backlog pour une boutique en stock partagé"
    );
    neria_assert(
        strpos($body, 'id_shop = 0 AND id_shop_group = ') !== false,
        "checkWaitlistBacklog() ne bascule plus vers id_shop=0/id_shop_group=X en mode partagé — régression du bug corrigé le 14/08/2026 (round 167)"
    );

    return [
        'pass'    => true,
        'message' => "HealthCheckManager::checkWaitlistBacklog() gère bien le mode stock partagé, cohérent avec WaitlistManager::notifyProduct() — bug corrigé le 14/08/2026 (round 167)",
    ];
}
