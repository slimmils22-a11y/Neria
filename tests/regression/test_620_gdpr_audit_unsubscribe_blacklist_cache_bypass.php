<?php
/**
 * Régression : GdprAuditManager::auditUnsubscribe() comptait la taille de
 * la blacklist de désabonnement sans désactiver le cache SQL de PrestaShop
 * (paramètre $use_cache, true par défaut) — même famille de bug que le
 * reste de ce fichier (rounds 210-223/302). Chiffre purement informatif,
 * mais incohérent avec la discipline appliquée partout ailleurs dans ce
 * même fichier pour ce pattern précis.
 *
 * Corrigé le 07/09/2026 (round 315) : $use_cache=false.
 *
 * Vérification structurelle ciblée : confirme que le 2e argument `false`
 * est bien présent dans l'appel réel.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/GdprAuditManager.php');
    neria_assert($src !== false, 'Impossible de lire src/GdprAuditManager.php');

    $posFn = strpos($src, 'neria_blacklist` WHERE `id_shop` = " . $this->idShop');
    neria_assert($posFn !== false, 'Comptage neria_blacklist introuvable dans auditUnsubscribe() — jeu de test invalide');

    // Fenêtre étroite centrée sur le code réel (le commentaire explicatif
    // se trouve avant $posFn ici, donc hors de portée).
    $body = substr($src, $posFn, 90);

    neria_assert(
        strpos($body, 'false') !== false,
        "GdprAuditManager::auditUnsubscribe() n'appelle plus getValue() avec \$use_cache=false pour le comptage de la blacklist — régression du bug corrigé le 07/09/2026 (round 315)"
    );

    return [
        'pass'    => true,
        'message' => "GdprAuditManager::auditUnsubscribe() contourne bien le cache SQL PrestaShop (\$use_cache=false) pour le comptage de la blacklist — bug corrigé le 07/09/2026 (round 315)",
    ];
}
