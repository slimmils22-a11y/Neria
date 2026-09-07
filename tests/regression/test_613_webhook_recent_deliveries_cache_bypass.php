<?php
/**
 * Régression : WebhookManager::getRecentDeliveries() appelait Db::executeS()
 * sans désactiver le cache SQL de PrestaShop (paramètre $use_cache, true
 * par défaut) — même famille de bug que les rounds 210-223. Cet onglet BO
 * affiche le statut de livraison en temps réel (status/attempts/
 * last_attempt) ; un marchand rafraîchissant la page juste après un
 * passage de processQueue() pouvait voir un statut périmé.
 *
 * Corrigé le 07/09/2026 (round 314) : $use_cache=false.
 *
 * Vérification structurelle ciblée : confirme que le 3e argument `false`
 * est bien présent dans l'appel réel, à l'intérieur du corps de la
 * méthode.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WebhookManager.php');
    neria_assert($src !== false, 'Impossible de lire src/WebhookManager.php');

    $posFn = strpos($src, 'public function getRecentDeliveries(int $limit = 10): array');
    neria_assert($posFn !== false, 'getRecentDeliveries() introuvable — jeu de test invalide');

    $body = substr($src, $posFn, 1200);

    neria_assert(
        strpos($body, '), true, false);') !== false,
        "WebhookManager::getRecentDeliveries() n'appelle plus executeS() avec \$use_cache=false — régression du bug corrigé le 07/09/2026 (round 314) : l'onglet BO 'livraisons récentes' pourrait de nouveau afficher un statut périmé après un passage de processQueue()"
    );

    return [
        'pass'    => true,
        'message' => "WebhookManager::getRecentDeliveries() contourne bien le cache SQL PrestaShop (\$use_cache=false) — bug corrigé le 07/09/2026 (round 314)",
    ];
}
