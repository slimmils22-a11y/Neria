<?php
/**
 * Régression : EmailRenderer::reformatProductsHtml() reconstruit chaque
 * ligne <tr> du tableau {products} (généré par le cœur PrestaShop) en
 * appliquant ses propres styles inline. Le template CŒUR
 * mails/_partials/order_conf_product_list.tpl produit, pour un produit
 * avec PLUSIEURS champs de personnalisation (gravure + police, message
 * cadeau + emballage...), une ligne <tr> supplémentaire dont le premier
 * <td> porte `colspan="3"` (donc 3 <td> PHYSIQUES = 5 colonnes LOGIQUES,
 * HTML parfaitement valide côté cœur PS — vérifié en lisant directement ce
 * template : {if count($product['customization']) > 1} ... <td colspan="3"
 * style="border:...">...</td>).
 *
 * reformatProductsHtml() ne reprenait PAS cet attribut `colspan` en
 * reconstruisant la ligne : elle ne comptait plus que 3 <td> SANS colspan
 * — désalignée avec les lignes produit normales à 5 colonnes du même
 * tableau, cassant visuellement la grille (notamment dans Outlook, dont le
 * moteur de rendu Word est très strict sur la cohérence des colonnes).
 *
 * Bug identifié le 31/08/2026 (round 261, audit "HTML mal formé dans blocs
 * email générés dynamiquement"). Vérification empirique IMPORTANTE
 * effectuée avant tout correctif (demandée explicitement par l'utilisateur
 * après qu'un premier passage de vérification avait jugé la piste initiale
 * d'un agent non confirmable sans investigation disproportionnée) : la
 * lecture directe du template cœur `order_conf_product_list.tpl` a montré
 * que l'hypothèse INITIALE de l'agent (une ligne "Total" glissant à
 * travers le filtre strpos($firstStyle,'border')) est FAUSSE — ce
 * sous-template ne contient AUCUNE ligne total, uniquement des lignes
 * produit. Le VRAI cas, découvert par cette même lecture, est celui décrit
 * ci-dessus (ligne de détail de personnalisation multiple, avec colspan).
 * Confirmé par un script scratch simulant le DOMDocument sur un extrait
 * réel du template cœur avant d'écrire ce correctif.
 *
 * Corrigé le 31/08/2026 (round 261) : le `colspan` de chaque <td> source
 * est désormais reporté sur le <td> reconstruit correspondant.
 *
 * Test comportemental réel : appelle la VRAIE méthode reformatProductsHtml()
 * (privée, via Reflection) sur un extrait fidèle du HTML réellement produit
 * par le template cœur PS (1 ligne produit normale à 5 colonnes + 1 ligne
 * de personnalisation multiple à colspan=3), vérifie que le contenu est
 * bien extrait ET que le colspan est bien reporté sur la ligne
 * reconstruite.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';

    // Extrait fidèle du HTML réellement produit par
    // mails/_partials/order_conf_product_list.tpl (cœur PrestaShop) pour un
    // produit simple, suivi d'une ligne de personnalisation multiple.
    $html = <<<HTML
<tr>
	<td style="border:1px solid #D6D4D4;"><table class="table"><tr><td width="5">&nbsp;</td><td><font size="2">REF1</font></td><td width="5">&nbsp;</td></tr></table></td>
	<td style="border:1px solid #D6D4D4;"><table class="table"><tr><td width="5">&nbsp;</td><td><font size="2"><strong>Bracelet gravé</strong></font></td><td width="5">&nbsp;</td></tr></table></td>
	<td style="border:1px solid #D6D4D4;"><table class="table"><tr><td width="5">&nbsp;</td><td align="right"><font size="2">45,00 &euro;</font></td><td width="5">&nbsp;</td></tr></table></td>
	<td style="border:1px solid #D6D4D4;"><table class="table"><tr><td width="5">&nbsp;</td><td align="right"><font size="2">1</font></td><td width="5">&nbsp;</td></tr></table></td>
	<td style="border:1px solid #D6D4D4;"><table class="table"><tr><td width="5">&nbsp;</td><td align="right"><font size="2">45,00 &euro;</font></td><td width="5">&nbsp;</td></tr></table></td>
</tr>
<tr>
	<td colspan="3" style="border:1px solid #D6D4D4;"><table class="table"><tr><td width="5">&nbsp;</td><td><font size="2">Gravure : Jean</font></td><td width="5">&nbsp;</td></tr></table></td>
	<td style="border:1px solid #D6D4D4;"><table class="table"><tr><td width="5">&nbsp;</td><td align="right"><font size="2">1</font></td><td width="5">&nbsp;</td></tr></table></td>
	<td style="border:1px solid #D6D4D4;"></td>
</tr>
HTML;

    $module   = neria_test_module();
    $renderer = new EmailRenderer($module);
    $ref      = new ReflectionMethod(EmailRenderer::class, 'reformatProductsHtml');
    $ref->setAccessible(true);

    $vars = ['{products}' => $html];
    $ref->invokeArgs($renderer, [&$vars]);

    $result = $vars['{products}'];
    neria_assert(
        $result !== $html && strpos($result, 'Bracelet gravé') !== false,
        "jeu de test invalide : reformatProductsHtml() n'a pas reconstruit le tableau (contenu produit introuvable dans le résultat)"
    );

    preg_match_all('/<tr>(.*?)<\/tr>/s', $result, $trMatches);
    neria_assert(count($trMatches[1]) === 2, "jeu de test invalide : nombre de lignes reconstruites inattendu (" . count($trMatches[1]) . " au lieu de 2)");

    [$productRow, $customizationRow] = $trMatches[1];

    preg_match_all('/<td\b/', $productRow, $prodTds);
    neria_assert(
        count($prodTds[0]) === 5,
        "jeu de test invalide : la ligne produit normale ne compte plus 5 <td> (" . count($prodTds[0]) . ")"
    );

    preg_match_all('/<td\b/', $customizationRow, $customTds);
    neria_assert(
        count($customTds[0]) === 3,
        "jeu de test invalide : la ligne de personnalisation ne compte plus 3 <td> physiques (" . count($customTds[0]) . ")"
    );

    neria_assert(
        (bool) preg_match('/<td colspan="3"/', $customizationRow),
        "EmailRenderer::reformatProductsHtml() ne reporte plus l'attribut colspan sur la ligne de personnalisation reconstruite — régression du bug corrigé le 31/08/2026 (round 261) : cette ligne (3 <td> physiques) serait de nouveau désalignée avec les lignes produit à 5 colonnes du même tableau, cassant visuellement la grille dans les clients mail stricts (Outlook)"
    );

    neria_assert(
        strpos($customizationRow, 'Gravure : Jean') !== false,
        "EmailRenderer::reformatProductsHtml() a perdu le contenu de la ligne de personnalisation en reportant le colspan — régression potentielle du correctif du 31/08/2026 (round 261)"
    );

    return [
        'pass'    => true,
        'message' => "EmailRenderer::reformatProductsHtml() reporte désormais le colspan des lignes de personnalisation multiple (produit par le template cœur PrestaShop) sur la ligne reconstruite, préservant l'alignement des colonnes du tableau {products} — bug corrigé le 31/08/2026 (round 261, vérifié empiriquement contre le template cœur order_conf_product_list.tpl)",
    ];
}
