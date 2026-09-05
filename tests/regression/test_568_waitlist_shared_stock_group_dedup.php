<?php
/**
 * Régression : en mode "stock partagé" entre boutiques d'un même groupe
 * (Shop::getGroup()->share_stock=1), hookActionUpdateQuantityImpl()
 * (neria.php) boucle sur TOUTES les boutiques du groupe et appelait
 * WaitlistManager::notifyProduct() une fois PAR BOUTIQUE — chaque appel
 * relisait indépendamment le MÊME total de stock partagé (aucune
 * décrémentation entre deux boutiques du même groupe dans cette même
 * exécution synchrone du hook), notifiant jusqu'à availableQty inscrits
 * DIFFÉRENTS par boutique, soit jusqu'à N× la quantité réellement
 * disponible pour un groupe de N boutiques.
 *
 * Exemple concret : produit à 2 unités en stock partagé entre Boutique A
 * et Boutique B, 3 inscrits en attente sur chacune. Un réassort à 2 unités
 * notifiait les 2 premiers inscrits de A (disponible=2), PUIS, dans le
 * même hook, les 2 premiers inscrits de B (disponible recalculé à 2,
 * inchangé) — 4 clients recevaient la promesse "de retour en stock" pour
 * seulement 2 unités réellement disponibles.
 *
 * Corrigé le 05/09/2026 (round 302) :
 *   1. WaitlistManager::notifyProduct() détecte le mode stock partagé et
 *      traite en UN SEUL appel la file COMBINÉE de toutes les boutiques du
 *      groupe (verrou de groupe, SELECT ... WHERE id_shop IN (...)), en
 *      appliquant le plafond availableQty UNE seule fois sur le total réel.
 *   2. hookActionUpdateQuantityImpl() (neria.php) dédoublonne désormais les
 *      boutiques à stock partagé par groupe — un seul appel par groupe,
 *      pas un par boutique membre — pour ne jamais retraiter deux fois le
 *      même budget de stock partagé dans la même exécution du hook.
 *
 * Test structurel (activer réellement le partage de stock modifierait un
 * réglage global de la boutique de test partagée par toute la suite — trop
 * invasif, même contrainte que test_298) : vérifie la présence des 3
 * mécanismes ci-dessus dans le code source.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $wlSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WaitlistManager.php');
    neria_assert($wlSrc !== false, 'Impossible de lire src/WaitlistManager.php');

    $neriaSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($neriaSrc !== false, 'Impossible de lire neria.php');

    // 1) notifyProduct() résout un verrou et une liste de boutiques
    // DIFFÉRENTS selon share_stock — verrou de GROUPE (pas de boutique
    // isolée) et $shopIds = toutes les boutiques du groupe.
    $posNP = strpos($wlSrc, 'public function notifyProduct(');
    neria_assert($posNP !== false, 'notifyProduct() introuvable — jeu de test invalide');
    $npBody = substr($wlSrc, $posNP, 2700);
    neria_assert(
        strpos($npBody, "'neria_waitlist_notify_group_'") !== false,
        "WaitlistManager::notifyProduct() n'utilise plus de verrou nommé PAR GROUPE en mode stock partagé — régression du bug corrigé le 05/09/2026 (round 302) : deux boutiques du même groupe pourraient de nouveau être traitées séparément dans le même hook"
    );
    neria_assert(
        strpos($npBody, '\Shop::getShops(true, $idShopGroup, true)') !== false,
        "WaitlistManager::notifyProduct() ne résout plus la liste des boutiques du GROUPE à stock partagé — régression du bug corrigé le 05/09/2026 (round 302)"
    );

    // 2) notifyProductLocked() interroge désormais TOUTES les boutiques du
    // groupe en une seule requête (IN (...)), pas une seule boutique isolée.
    $posLocked = strpos($wlSrc, 'private function notifyProductLocked(int $idProduct, int $idShop, array $shopIds): int');
    neria_assert($posLocked !== false, "notifyProductLocked() introuvable avec la signature élargie \$shopIds — régression du bug corrigé le 05/09/2026 (round 302)");
    $lockedBody = substr($wlSrc, $posLocked, 800);
    neria_assert(
        strpos($lockedBody, 'AND w.id_shop IN ({$shopIdsList})') !== false,
        "WaitlistManager::notifyProductLocked() ne sélectionne plus les inscrits de TOUTES les boutiques du groupe en une seule requête — régression du bug corrigé le 05/09/2026 (round 302) : la file d'attente combinée du groupe à stock partagé ne serait de nouveau traitée que boutique par boutique"
    );

    // 3) hookActionUpdateQuantityImpl() (neria.php) dédoublonne par groupe
    // à stock partagé avant d'appeler notifyProduct() en boucle.
    $posHook = strpos($neriaSrc, 'private function hookActionUpdateQuantityImpl(array $params): void');
    neria_assert($posHook !== false, 'hookActionUpdateQuantityImpl() introuvable — jeu de test invalide');
    $hookBody = substr($neriaSrc, $posHook, 2800);
    neria_assert(
        strpos($hookBody, '$processedGroups302') !== false && strpos($hookBody, 'share_stock') !== false,
        "neria.php::hookActionUpdateQuantityImpl() ne dédoublonne plus les boutiques à stock partagé par groupe avant d'appeler notifyProduct() — régression du bug corrigé le 05/09/2026 (round 302) : un groupe à stock partagé pourrait de nouveau être traité une fois par boutique membre dans la même exécution du hook, sur-notifiant la file d'attente au-delà du stock réellement disponible"
    );

    return [
        'pass'    => true,
        'message' => "WaitlistManager traite bien la file d'attente combinée de tout un groupe à stock partagé en un seul appel (verrou + requête de groupe), et neria.php dédoublonne l'appel par groupe plutôt que par boutique membre — bug corrigé le 05/09/2026 (round 302)",
    ];
}
