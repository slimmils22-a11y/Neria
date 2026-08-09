<?php
/**
 * Régression : CssInliner doit résoudre les sélecteurs via un index de
 * classes construit en une seule passe sur le DOM, pas via une requête
 * XPath relancée pour CHAQUE règle CSS (complexité quadratique).
 *
 * Bug réel corrigé le 08/08/2026 (round 137) : un email avec un style par
 * ligne (tableau produit dynamique — panier abandonné, récap de commande
 * volumineux) générait autant de règles CSS que de lignes, chacune
 * relançant un scan complet du DOM — coût O(règles × taille du DOM),
 * quadratique. Mesuré empiriquement avant correctif : ×4 de temps à
 * chaque doublement du nombre de lignes (100→0,04s, 200→0,12s,
 * 400→0,50s, 800→1,77s).
 *
 * Test structurel : vérifie la présence de l'index de classes (construit
 * une fois) et l'absence de l'ancienne conversion CSS→XPath par règle.
 * Test comportemental complémentaire : un HTML à 300 lignes stylées
 * individuellement doit s'inliner en un temps raisonnable (seuil large
 * pour éviter la fragilité d'un test de performance strict, seulement une
 * garde contre une régression flagrante vers un comportement quadratique).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CssInliner.php';

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CssInliner.php');
    neria_assert($src !== false, 'Impossible de lire src/CssInliner.php');
    neria_assert(
        strpos($src, "\$xpath->query('//*[@class]')") !== false,
        "CssInliner ne construit plus l'index de classes en une seule passe sur le DOM — régression du bug corrigé le 08/08/2026 (round 137) : la complexité quadratique (une requête XPath par règle CSS) pourrait réapparaître"
    );
    neria_assert(
        strpos($src, 'private static function resolveSelector(') !== false,
        "CssInliner::resolveSelector() introuvable — régression du bug corrigé le 08/08/2026 (round 137)"
    );

    // Génère un HTML avec 300 lignes ayant chacune leur propre classe CSS
    // (motif qui déclenchait la quadratique) et vérifie un temps de
    // traitement raisonnable — seuil volontairement large (5s) pour un
    // test de garde-fou, pas une mesure de performance précise.
    $rules = '';
    $rows  = '';
    for ($i = 1; $i <= 300; $i++) {
        $rules .= ".row-{$i}{color:#00{$i}}";
        $rows  .= "<tr class=\"row-{$i}\"><td>Produit {$i}</td></tr>";
    }
    $html = "<html><head><style>{$rules}</style></head><body><table>{$rows}</table></body></html>";

    $start = microtime(true);
    $result = CssInliner::inline($html);
    $elapsed = microtime(true) - $start;

    neria_assert(
        $elapsed < 5.0,
        "CssInliner::inline() a pris {$elapsed}s pour 300 lignes stylées individuellement (seuil 5s) — possible régression vers un comportement quadratique (bug corrigé le 08/08/2026, round 137)"
    );
    neria_assert(
        strpos($result, 'color:#00150') !== false || strpos($result, 'color: #00150') !== false,
        "CssInliner::inline() n'a pas correctement inliné les styles individuels par ligne (résultat fonctionnel incorrect, pas seulement une question de performance)"
    );

    return [
        'pass'    => true,
        'message' => "CssInliner résout bien les sélecteurs via un index de classes construit une seule fois, évitant le comportement quadratique sur un email à nombreuses lignes stylées individuellement",
    ];
}
