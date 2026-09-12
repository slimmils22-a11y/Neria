<?php
/**
 * Régression : WaitlistManager::notifyProductLocked() doit bypasser le
 * cache SQL ($use_cache=false) pour les 2 lectures de stock (quantité
 * totale du produit, quantité par déclinaison) — même pattern déjà
 * appliqué à isRegistered() (round 223) et $stillRegistered (round 212)
 * dans ce même fichier, pour exactement ce risque.
 *
 * Bug identifié le 12/09/2026 (round 346, audit QueueManager/
 * WaitlistManager/BounceManager) : hookActionUpdateQuantity peut appeler
 * notifyProduct() plusieurs fois pour LE MÊME produit/boutique dans la
 * MÊME requête HTTP (import de stock en masse, plusieurs mouvements
 * successifs). Sans le bypass, un appel ultérieur avec un texte SQL
 * identique pouvait relire une quantité mise en cache par le 1er appel,
 * périmée par rapport au stock réel déjà modifié entre les deux dans
 * cette même requête.
 *
 * Corrigé le 12/09/2026 (round 346) : $use_cache=false ajouté aux 2 lectures.
 *
 * Test structurel uniquement (le cache SQL PrestaShop — _PS_CACHE_ENABLED_
 * — est désactivé dans cet environnement de dev/test, rendant toute preuve
 * comportementale de péremption irréalisable ici ; vérifié empiriquement,
 * même limite que pour d'autres correctifs de cache de cette série).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WaitlistManager.php');
    neria_assert($src !== false, 'Impossible de lire src/WaitlistManager.php');

    $posAvailable = strpos($src, '$availableQty = (int) $this->db->getValue(');
    neria_assert($posAvailable !== false, "\$availableQty introuvable — jeu de test invalide");
    $bodyAvailable = substr($src, $posAvailable, 400);
    neria_assert(
        strpos($bodyAvailable, 'false') !== false,
        "WaitlistManager::notifyProductLocked() ne bypasse plus le cache SQL pour \$availableQty — régression du bug corrigé le 12/09/2026 (round 346) : un appel ultérieur dans la même requête HTTP (import de stock en masse) pourrait de nouveau relire une quantité périmée"
    );

    $posQty = strpos($src, '$qty = (int) $this->db->getValue(');
    neria_assert($posQty !== false, "\$qty (par déclinaison) introuvable — jeu de test invalide");
    $bodyQty = substr($src, $posQty, 400);
    neria_assert(
        strpos($bodyQty, 'false') !== false,
        "WaitlistManager::notifyProductLocked() ne bypasse plus le cache SQL pour \$qty par déclinaison — régression du bug corrigé le 12/09/2026 (round 346)"
    );

    return [
        'pass'    => true,
        'message' => "WaitlistManager bypasse désormais bien le cache SQL pour les 2 lectures de stock (produit total + par déclinaison) — bug corrigé le 12/09/2026 (round 346)",
    ];
}
