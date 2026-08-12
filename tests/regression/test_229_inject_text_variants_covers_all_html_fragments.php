<?php
/**
 * Régression : EmailRenderer::injectTextVariants() doit dériver la variante
 * texte ({xxx_txt}) de TOUS les fragments HTML légitimes connus
 * (self::HTML_SAFE_RAW_KEYS), pas seulement {messages} — sans jamais
 * écraser une variante déjà fournie (notamment par le cœur PrestaShop
 * lui-même pour ses templates natifs order_conf/new_order/download_product).
 *
 * Bug réel corrigé le 09/08/2026 (round 149) : $htmlKeys ne contenait que
 * '{messages}'. Pour un template Neria propre (pas natif PS) réutilisant un
 * de ces noms de variable HTML sans fournir lui-même sa variante texte,
 * le destinataire aurait vu le HTML brut (balises <p>/<br>) dans la version
 * texte de l'email.
 *
 * Test comportemental réel : appelle EmailRenderer::injectTextVariants()
 * (via Reflection, méthode privée) avec un jeu de variables ne fournissant
 * PAS les variantes _txt, vérifie qu'elles sont bien dérivées pour
 * {products}/{delivery_block_html} — puis vérifie qu'une variante _txt déjà
 * présente (simulant le cas order_conf, fournie par PS core) n'est jamais
 * écrasée.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    $module = neria_test_module();
    $renderer = new EmailRenderer($module);
    $method = new ReflectionMethod(EmailRenderer::class, 'injectTextVariants');
    $method->setAccessible(true);

    // Cas 1 : {products} sans variante texte fournie -> doit etre derivee.
    $vars1 = [
        '{products}' => '<p>Réf. NER-001 — Montre × 1</p><p>Réf. NER-002 — Bracelet × 1</p>',
    ];
    $method->invokeArgs($renderer, [&$vars1]);
    neria_assert(
        isset($vars1['{products_txt}']) && strpos($vars1['{products_txt}'], '<p>') === false,
        "injectTextVariants() ne derive plus {products_txt} a partir de {products} — regression du bug corrige le 09/08/2026 (round 149)"
    );

    // Cas 2 : {delivery_block_html} -> {delivery_block_txt} (mapping suffixe explicite)
    $vars2 = [
        '{delivery_block_html}' => '<p>12 rue de Test<br>75000 Paris</p>',
    ];
    $method->invokeArgs($renderer, [&$vars2]);
    neria_assert(
        isset($vars2['{delivery_block_txt}']) && strpos($vars2['{delivery_block_txt}'], '<') === false && strpos($vars2['{delivery_block_txt}'], '75000 Paris') !== false,
        "injectTextVariants() ne derive plus {delivery_block_txt} a partir de {delivery_block_html} — regression du bug corrige le 09/08/2026 (round 149)"
    );

    // Cas 3 : contre-preuve — une variante deja fournie (comme le fait PS
    // core pour order_conf) ne doit JAMAIS etre ecrasee.
    $vars3 = [
        '{delivery_block_html}' => '<p>Ne doit pas etre utilise</p>',
        '{delivery_block_txt}'  => 'Valeur fournie par PS core, a preserver telle quelle',
    ];
    $method->invokeArgs($renderer, [&$vars3]);
    neria_assert(
        $vars3['{delivery_block_txt}'] === 'Valeur fournie par PS core, a preserver telle quelle',
        "injectTextVariants() ecrase a tort une variante _txt deja fournie — regression : casserait order_conf/new_order dont le contenu texte est fourni nativement par PrestaShop"
    );

    return [
        'pass'    => true,
        'message' => "EmailRenderer::injectTextVariants() derive bien la variante texte de tous les fragments HTML legitimes connus, sans jamais ecraser une variante deja fournie",
    ];
}
