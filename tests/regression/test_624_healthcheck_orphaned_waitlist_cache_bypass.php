<?php
/**
 * Régression : HealthCheckManager::checkOrphanedWaitlistClaims() comptait
 * les claims orphelins (claim_started_at posé depuis plus d'1h sans
 * notified_at) sans désactiver le cache SQL de PrestaShop (paramètre
 * $use_cache, true par défaut) — contrairement à sa méthode sœur
 * checkOrphanedVoucherReservations() juste au-dessus, corrigée pour ce même
 * piège au round 223. Un résultat de cache SQL périmé pouvait faire sauter
 * silencieusement le nettoyage automatique, laissant des clients bloqués
 * indéfiniment sans notification de retour en stock ni nouvel essai possible.
 *
 * Corrigé le 07/09/2026 (round 317) : $use_cache=false.
 *
 * Vérification structurelle ciblée : confirme que le 2e argument `false`
 * est bien présent dans l'appel réel.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php');
    neria_assert($src !== false, 'Impossible de lire src/HealthCheckManager.php');

    // strrpos() (pas strpos()) : le garde-fou round 317 correspondant vit
    // dans ce même fichier et cite littéralement cette même signature de
    // méthode comme critère de recherche — piège auto-référentiel déjà
    // documenté round 246, la vraie définition est la DERNIÈRE occurrence.
    $posFn = strrpos($src, 'private function checkOrphanedWaitlistClaims(): array');
    neria_assert($posFn !== false, 'checkOrphanedWaitlistClaims() introuvable — jeu de test invalide');

    $body = substr($src, $posFn, 1200);

    neria_assert(
        strpos($body, 'claim_started_at` IS NOT NULL') !== false,
        "checkOrphanedWaitlistClaims() a changé de forme inattendue — jeu de test invalide"
    );

    // Cible le code réel, juste après la requête SQL — pas un strpos('false')
    // en texte libre qui matcherait aussi le commentaire explicatif
    // ("$use_cache=false") ajouté juste au-dessus — même piège d'auto-
    // collision déjà rencontré rounds 246/312/315/316.
    $posQuery = strpos($body, 'DATE_SUB(NOW(), INTERVAL 1 HOUR)');
    neria_assert($posQuery !== false, 'jeu de test invalide (requête introuvable)');
    $tail = substr($body, $posQuery, 60);
    neria_assert(
        strpos($tail, 'false') !== false,
        "HealthCheckManager::checkOrphanedWaitlistClaims() n'appelle plus getValue() avec \$use_cache=false — régression du bug corrigé le 07/09/2026 (round 317)"
    );

    return [
        'pass'    => true,
        'message' => "HealthCheckManager::checkOrphanedWaitlistClaims() contourne bien le cache SQL PrestaShop (\$use_cache=false) — bug corrigé le 07/09/2026 (round 317)",
    ];
}
