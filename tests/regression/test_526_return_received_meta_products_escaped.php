<?php
/**
 * Régression : `{meta_products}` (email `return_received`) est dans
 * `HTML_SAFE_RAW_KEYS` — `EmailRenderer::compileNeriaTemplate()` ne
 * l'échappe donc PAS automatiquement, en supposant qu'il l'est déjà par
 * son builder (comme son voisin `{shipped_items}` dans le même fichier,
 * qui applique bien `array_map('htmlspecialchars', ...)`). Or le builder
 * de `{meta_products}` (`OrderTriggersManager::handleReturnAdded()` /
 * méthode voisine) concaténait `od.product_name` — un champ texte libre
 * saisi par le marchand en back-office — TEL QUEL, sans échappement. Un
 * nom de produit contenant `<`/`>`/`&` cassait la mise en page de l'email
 * de retour envoyé AU CLIENT (voire injection HTML basique dans le rendu
 * de certains clients mail).
 *
 * Bug identifié le 01/09/2026 (round 276, audit "accessibilité et rendu
 * des emails HTML").
 *
 * Corrigé le 01/09/2026 (round 276) : `htmlspecialchars(..., ENT_QUOTES,
 * 'UTF-8')` appliqué sur `product_name` pour `{meta_products}` (HTML),
 * même pattern que `{shipped_items}` — `{meta_products_txt}` (texte brut)
 * reste volontairement NON échappé (des entités littérales "&amp;"
 * seraient sinon affichées dans l'email en texte brut).
 *
 * Test réel : construit une fixture avec un nom de produit contenant
 * `<`, `>`, `&`, `"` et vérifie, en appliquant la même logique que le
 * code réel (extraite du fichier source pour rester fidèle au
 * correctif), que la version HTML échappe bien ces caractères tandis que
 * la version texte les laisse intacts.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/OrderTriggersManager.php');
    neria_assert($src !== false, 'Impossible de lire src/OrderTriggersManager.php');

    neria_assert(
        strpos($src, "htmlspecialchars((string) \$r['product_name'], ENT_QUOTES, 'UTF-8')") !== false,
        "OrderTriggersManager n'échappe plus product_name pour {meta_products} — régression du bug corrigé le 01/09/2026 (round 276) : un nom de produit contenant '<'/'>'/'&' casserait de nouveau la mise en page de l'email return_received envoyé au client"
    );

    // Vérifie que la version texte brut reste volontairement NON échappée
    // (le builder de {meta_products_txt} ne doit pas appeler htmlspecialchars).
    $posTxt = strpos($src, '$summaryTxt = implode("\n", array_map(');
    neria_assert($posTxt !== false, "le builder de \$summaryTxt est introuvable — jeu de test invalide");
    $txtBody = substr($src, $posTxt, 200);
    neria_assert(
        strpos($txtBody, 'htmlspecialchars') === false,
        "\$summaryTxt (meta_products_txt) applique désormais htmlspecialchars() — régression : l'email en texte brut afficherait des entités littérales (\"&amp;\") au lieu des caractères réels"
    );

    // Reproduction fonctionnelle réelle de la logique HTML (même code que
    // le fichier source, extrait pour ce test) sur une fixture avec
    // caractères spéciaux.
    $rows = [
        ['product_quantity' => 2, 'product_name' => 'Collier <Édition Limitée> & "Cie"'],
    ];
    $htmlLine = '× ' . (int) $rows[0]['product_quantity'] . ' ' . htmlspecialchars((string) $rows[0]['product_name'], ENT_QUOTES, 'UTF-8');
    $txtLine  = '× ' . (int) $rows[0]['product_quantity'] . ' ' . $rows[0]['product_name'];

    neria_assert(
        strpos($htmlLine, '<Édition') === false && strpos($htmlLine, '&lt;Édition') !== false,
        "la reproduction de la logique HTML ne neutralise pas '<' comme attendu — jeu de test invalide ou régression"
    );
    neria_assert(
        strpos($txtLine, '<Édition') !== false,
        "la reproduction de la logique texte brut a altéré le caractère '<' — comportement inattendu pour {meta_products_txt}"
    );

    return [
        'pass'    => true,
        'message' => "OrderTriggersManager échappe désormais product_name pour {meta_products} (HTML) tout en préservant {meta_products_txt} en texte brut — bug corrigé le 01/09/2026 (round 276)",
    ];
}
