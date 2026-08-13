<?php
/**
 * Régression : MonthlyReportManager::checkAndSend() modifie $this->idShop
 * à chaque itération de sa boucle multi-boutique pour scoper les requêtes
 * SQL, mais ne le restaurait jamais à la fin (seul
 * Context::getContext()->shop l'était). Si la même instance est réutilisée
 * après checkAndSend() dans la même requête, toute méthode privée
 * dépendant de $this->idShop opérait silencieusement sur la DERNIÈRE
 * boutique itérée plutôt que la boutique réellement voulue.
 *
 * Corrigé le 13/08/2026 (round 165) : $originalIdShop sauvegardé avant la
 * boucle et restauré dans le même bloc que Context::getContext()->shop.
 *
 * Test structurel (déclencher checkAndSend() en réel enverrait de vrais
 * rapports mensuels — trop invasif pour l'environnement de test partagé) :
 * vérifie que $this->idShop est bien sauvegardé avant la boucle et
 * restauré au même endroit que le contexte boutique.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/MonthlyReportManager.php');
    neria_assert($src !== false, 'Impossible de lire MonthlyReportManager.php');

    $posFn = strpos($src, 'public function checkAndSend(): void');
    neria_assert($posFn !== false, 'checkAndSend() introuvable — jeu de test invalide');
    $body = substr($src, $posFn, 4600);

    neria_assert(
        strpos($body, '$originalIdShop = $this->idShop;') !== false,
        "checkAndSend() ne sauvegarde plus \$this->idShop avant la boucle multi-boutique — régression du bug corrigé le 13/08/2026 (round 165)"
    );

    $posOriginalShopRestore = strpos($body, '\Context::getContext()->shop = $originalShop;');
    neria_assert($posOriginalShopRestore !== false, "Restauration de Context::getContext()->shop introuvable — jeu de test invalide");
    $posIdShopRestore = strpos($body, '$this->idShop = $originalIdShop;', $posOriginalShopRestore);

    neria_assert(
        $posIdShopRestore !== false && $posIdShopRestore < $posOriginalShopRestore + 60,
        "checkAndSend() ne restaure plus \$this->idShop juste après Context::getContext()->shop — régression du bug corrigé le 13/08/2026 (round 165) : une réutilisation de l'instance après checkAndSend() opérerait silencieusement sur la dernière boutique itérée"
    );

    return [
        'pass'    => true,
        'message' => "MonthlyReportManager::checkAndSend() restaure bien \$this->idShop après sa boucle multi-boutique — bug corrigé le 13/08/2026 (round 165)",
    ];
}
