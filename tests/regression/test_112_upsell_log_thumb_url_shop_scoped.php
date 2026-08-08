<?php
/**
 * Régression : UpsellManager::getLog() doit basculer temporairement
 * Context::getContext()->shop sur $idShop avant de générer thumb_url ET
 * product_url — Link::getImageLink() n'a pas de paramètre $idShop
 * (contrairement à getProductLink()) et résout systématiquement le
 * domaine/thème via le contexte global courant.
 *
 * Bug réel corrigé le 08/08/2026 (round 108) : le round 104 avait déjà
 * corrigé product_url (passage de $idShop en 6e argument à
 * getProductLink()), mais thumb_url, généré 10 lignes plus haut dans la
 * même boucle, appelait getImageLink() sans switcher le contexte —
 * incohérence "un lien scopé, l'autre pas" (même famille de bug que
 * WaitlistManager::notifyProduct(), round 103), passée inaperçue au round
 * 104 car getImageLink() n'a structurellement pas de paramètre $idShop à
 * simplement rajouter. Un admin multi-boutique consultant le journal
 * upsell d'une AUTRE boutique que celle active voyait product_url pointer
 * correctement vers la bonne boutique, mais thumb_url vers le
 * domaine/thème de la boutique du contexte d'exécution courant —
 * miniature cassée ou mauvais domaine.
 *
 * Test structurel (pas d'invocation complète de getLog() avec assertion
 * sur les URLs réelles, l'environnement de dev n'ayant qu'une seule
 * boutique — même limite que test_107) : vérifie que le switch de
 * contexte englobe bien les deux générations de lien dans la même boucle.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/UpsellManager.php');
    neria_assert($src !== false, 'Impossible de lire src/UpsellManager.php');

    $posGetLog = strpos($src, 'public function getLog(');
    neria_assert($posGetLog !== false, "Méthode getLog() introuvable — jeu de test invalide");

    $block = substr($src, $posGetLog, 3200);

    neria_assert(
        strpos($block, '$context->shop = new \Shop($idShop);') !== false,
        "UpsellManager::getLog() ne bascule plus temporairement Context::getContext()->shop sur \$idShop — régression du bug corrigé le 08/08/2026 (round 108) : thumb_url pourrait de nouveau pointer vers le domaine/thème de la mauvaise boutique"
    );

    // Le switch doit englober à la fois thumb_url (getImageLink) ET
    // product_url (getProductLink) — pas seulement l'un des deux. Recherche
    // des appels réels ($context->link->...), pas les mentions en
    // commentaire (qui précèdent le switch dans le texte).
    $posSwitch  = strpos($block, '$context->shop = new \Shop($idShop);');
    $posThumb   = strpos($block, '$context->link->getImageLink(');
    $posProduct = strpos($block, '$context->link->getProductLink(');
    neria_assert(
        $posSwitch !== false && $posThumb !== false && $posSwitch < $posThumb,
        "le switch de contexte n'englobe plus la génération de thumb_url — régression du bug corrigé le 08/08/2026 (round 108)"
    );
    neria_assert(
        $posSwitch !== false && $posProduct !== false && $posSwitch < $posProduct,
        "le switch de contexte n'englobe plus la génération de product_url"
    );

    return [
        'pass'    => true,
        'message' => "UpsellManager::getLog() bascule bien le contexte boutique avant de générer thumb_url ET product_url",
    ];
}
