<?php
/**
 * Régression : les 4 requêtes UPDATE de WaitlistManager::notifyProductLocked()
 * (réclamation CAS, confirmation notified_at, libération de réclamation×2)
 * filtraient par id_customer + id_product + id_shop, mais jamais
 * id_product_attribute —
 * alors que le SELECT initial de la méthode scope bien par déclinaison
 * précise (round 167) et que la table a UNE ligne PAR déclinaison
 * (uq_customer_product_attr_shop = id_customer, id_product,
 * id_product_attribute, id_shop).
 *
 * Bug réel identifié le 23/08/2026 (round 187) : un client inscrit sur DEUX
 * déclinaisons différentes du même produit (ex. taille S et taille L) a deux
 * lignes distinctes en base. Le réassort de la taille S déclenchait les
 * UPDATE ci-dessus SANS filtre déclinaison — ils matchaient AUSSI la ligne
 * taille L (jamais réassortie, filtrée hors de $rows plus haut dans la
 * méthode), la marquant à tort notified_at alors qu'aucun email n'a jamais
 * été envoyé pour cette déclinaison précise : le client perd silencieusement
 * sa notification de retour en stock pour la taille L.
 *
 * Corrigé le 23/08/2026 (round 187) : id_product_attribute ajouté aux 4
 * clauses WHERE (réclamation CAS, confirmation notified_at, libération de
 * réclamation sur échec d'envoi, libération de réclamation sur exception).
 *
 * Test structurel : notifyProductLocked() nécessite un vrai objet Product
 * chargé (constructeur PrestaShop, cover, contexte boutique) — hors de
 * portée d'un test isolé sans fixture catalogue complète. On vérifie donc,
 * par lecture directe du source, que chaque UPDATE de la méthode contient
 * bien "id_product_attribute" dans sa clause WHERE.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WaitlistManager.php');
    neria_assert($src !== false, 'Impossible de lire src/WaitlistManager.php');

    $posStart = strpos($src, 'private function notifyProductLocked(');
    neria_assert($posStart !== false, 'notifyProductLocked() introuvable — jeu de test invalide');

    $posEnd = strpos($src, "\n    /**", $posStart + 50);
    if ($posEnd === false) {
        $posEnd = strlen($src);
    }
    $methodSrc = substr($src, $posStart, $posEnd - $posStart);

    $updateCount = substr_count($methodSrc, 'UPDATE `{$this->prefix}');
    neria_assert($updateCount >= 4, "notifyProductLocked() ne contient plus que {$updateCount} UPDATE (attendu >= 4) — jeu de test invalide, la méthode a été restructurée");

    // Chaque bloc UPDATE...WHERE doit contenir id_product_attribute. On
    // découpe le source en segments par occurrence de "UPDATE `{$this->prefix}"
    // et on vérifie chaque segment jusqu'au ";" fermant l'appel execute().
    $offset = 0;
    $checked = 0;
    while (($pos = strpos($methodSrc, 'UPDATE `{$this->prefix}', $offset)) !== false) {
        $endPos = strpos($methodSrc, ');', $pos);
        neria_assert($endPos !== false, 'UPDATE sans fin détectable — jeu de test invalide');
        $block = substr($methodSrc, $pos, $endPos - $pos);
        neria_assert(
            strpos($block, 'id_product_attribute') !== false,
            "Une requête UPDATE de notifyProductLocked() ne filtre plus par id_product_attribute (bloc débutant à l'offset {$pos} de la méthode) — régression du bug corrigé le 23/08/2026 (round 187) : un client inscrit sur plusieurs déclinaisons du même produit perdrait de nouveau silencieusement sa notification pour les déclinaisons non réassorties"
        );
        $checked++;
        $offset = $endPos + 2;
    }

    neria_assert($checked >= 4, "seulement {$checked} bloc(s) UPDATE vérifié(s) (attendu >= 4) — jeu de test invalide");

    return [
        'pass'    => true,
        'message' => "Les {$checked} requêtes UPDATE de WaitlistManager::notifyProductLocked() filtrent bien par id_product_attribute — bug corrigé le 23/08/2026 (round 187)",
    ];
}
