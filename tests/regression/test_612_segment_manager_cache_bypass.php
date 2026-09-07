<?php
/**
 * Régression : SegmentManager::getSegmentCounts()/getCustomersBySegment()
 * appelaient Db::executeS() sans désactiver le cache SQL de PrestaShop
 * (paramètre $use_cache, true par défaut) — même famille de bug que les
 * rounds 210-223. getCustomersBySegment() alimente directement
 * sendToSegment() : la liste des destinataires d'une campagne pouvait donc
 * ne pas refléter un recomputeAll() ou une purge RGPD (round 289) venant
 * de tourner juste avant, si le cache de requête PrestaShop n'avait pas
 * encore expiré.
 *
 * Corrigé le 07/09/2026 (round 314) : $use_cache=false sur les deux appels.
 *
 * Vérification structurelle ciblée : confirme que le 3e argument `false`
 * est bien présent dans les deux appels réels, à l'intérieur du corps des
 * méthodes concernées.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SegmentManager.php');
    neria_assert($src !== false, 'Impossible de lire src/SegmentManager.php');

    $posCounts = strpos($src, 'public function getSegmentCounts(): array');
    neria_assert($posCounts !== false, 'getSegmentCounts() introuvable — jeu de test invalide');
    $bodyCounts = substr($src, $posCounts, 2200);
    neria_assert(
        strpos($bodyCounts, 'GROUP BY s.segment"') !== false && strpos($bodyCounts, 'true, false') !== false,
        "SegmentManager::getSegmentCounts() n'appelle plus executeS() avec \$use_cache=false — régression du bug corrigé le 07/09/2026 (round 314) : le badge de comptage par segment pourrait de nouveau afficher un chiffre périmé juste après un recomputeAll()"
    );

    $posCustomers = strpos($src, 'public function getCustomersBySegment(');
    neria_assert($posCustomers !== false, 'getCustomersBySegment() introuvable — jeu de test invalide');
    $bodyCustomers = substr($src, $posCustomers, 3200);
    neria_assert(
        strpos($bodyCustomers, 'LIMIT %d OFFSET %d",') !== false && strpos($bodyCustomers, '), true, false);') !== false,
        "SegmentManager::getCustomersBySegment() n'appelle plus executeS() avec \$use_cache=false — régression du bug corrigé le 07/09/2026 (round 314) : sendToSegment() pourrait de nouveau envoyer une campagne à une liste de destinataires périmée (recomputeAll()/purge RGPD non reflétés)"
    );

    return [
        'pass'    => true,
        'message' => "SegmentManager::getSegmentCounts()/getCustomersBySegment() contournent bien le cache SQL PrestaShop (\$use_cache=false) — bug corrigé le 07/09/2026 (round 314)",
    ];
}
