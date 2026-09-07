<?php
/**
 * Régression : CustomerEmailHistoryManager::getShopAverageOpenRate() lisait
 * la moyenne d'ouverture boutique via Db::getRow($sql) sans désactiver le
 * cache SQL de PrestaShop (paramètre $use_cache, true par défaut) — même
 * famille de bug que les rounds 210-223 (cache SQL non contourné sur une
 * lecture fraîcheur-critique). Le texte SQL de cette requête est identique
 * d'un appel à l'autre pour la même boutique (aucun paramètre variable dans
 * le SQL lui-même, donc clé de cache PrestaShop identique) : une ouverture
 * qui vient tout juste de se produire (tracking pixel) n'était donc pas
 * reflétée immédiatement dans la moyenne boutique affichée en comparaison
 * du badge d'engagement individuel du client, tant que le cache de requête
 * PrestaShop n'expirait pas.
 *
 * Corrigé le 06/09/2026 (round 313) : `$this->db->getRow($sql, false)` —
 * $use_cache=false, résultat toujours frais.
 *
 * Vérification structurelle ciblée (comme pour les rounds précédents sur ce
 * même pattern de cache SQL, ex. round 292/table de correspondance
 * Db::getRow()/getValue()) : confirme que le 2e argument `false` est bien
 * présent dans l'appel réel, à l'intérieur du corps de la méthode.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CustomerEmailHistoryManager.php');
    neria_assert($src !== false, 'Impossible de lire src/CustomerEmailHistoryManager.php');

    $posFn = strpos($src, 'public function getShopAverageOpenRate(): float');
    neria_assert($posFn !== false, 'getShopAverageOpenRate() introuvable — jeu de test invalide');

    $body = substr($src, $posFn, 1800);

    neria_assert(
        strpos($body, '$this->db->getRow($sql, false)') !== false,
        "getShopAverageOpenRate() n'appelle plus \$this->db->getRow(\$sql, false) dans son propre corps — régression du bug corrigé le 06/09/2026 (round 313) : la moyenne d'ouverture boutique redeviendrait potentiellement périmée (cache SQL PrestaShop non contourné) jusqu'à expiration du cache de requête"
    );

    // Vérification comportementale complémentaire, légère : la méthode doit
    // toujours renvoyer un flottant valide (0.0 à 100.0) sur les données
    // réelles de l'install de test — garde-fou de non-régression de
    // signature/type en plus du contrôle structurel ci-dessus.
    require_once _PS_MODULE_DIR_ . 'neria/src/CustomerEmailHistoryManager.php';
    $mgr  = new CustomerEmailHistoryManager(neria_test_module());
    $rate = $mgr->getShopAverageOpenRate();
    neria_assert(
        is_float($rate) && $rate >= 0.0 && $rate <= 100.0,
        "getShopAverageOpenRate() renvoie une valeur invalide (" . var_export($rate, true) . ") — jeu de test invalide ou régression de signature"
    );

    return [
        'pass'    => true,
        'message' => "CustomerEmailHistoryManager::getShopAverageOpenRate() contourne bien le cache SQL PrestaShop (\$use_cache=false) pour rester toujours à jour — bug corrigé le 06/09/2026 (round 313)",
    ];
}
