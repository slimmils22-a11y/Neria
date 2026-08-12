<?php
/**
 * Régression : EmailRenderer::renderWithVars() (utilisée par
 * CustomerEmailHistoryManager::buildPreviewHtml() pour rejouer l'aperçu
 * d'un email archivé depuis l'historique client BO) doit échapper toute
 * variable de texte libre avant injection HTML — exactement comme
 * compileNeriaTemplate() (vrai pipeline d'envoi, durci au round 148).
 *
 * Bug réel corrigé le 09/08/2026 (round 149) : buildCompiledHtml() (pipeline
 * de compilation utilisé par renderWithVars()) n'échappait JAMAIS ses
 * $extraReplacements, contrairement à compileNeriaTemplate(). Or
 * renderWithVars() rejoue le SNAPSHOT BRUT des variables d'un envoi passé
 * (rendered_vars), incluant les mêmes variables de texte libre BO
 * concernées par le bug XSS du round 148 (ex. {reply}, {apology_reason}).
 * Le HTML résultant est renvoyé directement au navigateur
 * (neria.php::maybeOutputHistoryFileResponse(), Content-Type: text/html) :
 * une charge XSS stockée à l'envoi d'origine s'exécutait dans le contexte
 * de session BO de tout employé consultant l'historique de ce client,
 * potentiellement bien après et par une personne différente de celle
 * ayant introduit la charge.
 *
 * Découverte annexe pendant ce correctif : la liste noire de fragments
 * HTML légitimes (self::HTML_SAFE_RAW_KEYS, extraite en constante
 * partagée ce round) oubliait 3 clés réellement utilisées par de vrais
 * templates de production — {shipped_items} (order_partial_shipped.html),
 * {messages} (forward_msg.html), {virtualProducts} (download_product.html,
 * injectée par le cœur PrestaShop) — régression silencieuse depuis le
 * round 148 (ces 3 templates affichaient du HTML échappé au lieu du
 * contenu formaté). Ajoutées à la liste au passage.
 *
 * Test comportemental réel : appelle EmailRenderer::renderWithVars()
 * (méthode publique) avec une charge XSS dans une variable de texte libre,
 * vérifie qu'elle ressort échappée — puis vérifie qu'un fragment HTML
 * légitime nouvellement couvert ({shipped_items}) ressort intact.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    $module = neria_test_module();
    $renderer = new EmailRenderer($module);

    $payload = '<img src=x onerror=alert(document.cookie)>';
    $html = $renderer->renderWithVars('reply_msg', 'fr', [
        'firstname' => 'Test',
        'reply'     => $payload,
    ]);

    neria_assert($html !== null, "renderWithVars('reply_msg') a renvoye null — jeu de test invalide");
    neria_assert(
        strpos($html, $payload) === false,
        "la charge XSS brute apparait telle quelle dans le HTML produit par renderWithVars() — regression du bug corrige le 09/08/2026 (round 149) : buildCompiledHtml() n'echapperait de nouveau plus ses \$extraReplacements"
    );
    neria_assert(
        strpos($html, '<img src=x onerror=') === false,
        "le tag <img> actif de la charge XSS n'a pas ete neutralise par renderWithVars() — regression du bug corrige le 09/08/2026 (round 149) : le renvoi d'historique redeviendrait un vecteur XSS stocke execute cote BO"
    );
    neria_assert(
        strpos($html, htmlspecialchars($payload, ENT_QUOTES, 'UTF-8')) !== false,
        "la charge XSS n'apparait pas sous sa forme echappee attendue — le mecanisme d'echappement semble avoir change de comportement"
    );

    // Contre-preuve : {shipped_items}, oublié de la liste noire d'origine
    // (round 148), corrigé au passage ce round — ne doit PAS être échappé.
    $shippedHtml = '<p>Réf. NER-TEST — Colis 1/2 — Colissimo 6A1234567890</p>';
    $htmlShipped = $renderer->renderWithVars('order_partial_shipped', 'fr', [
        'firstname'      => 'Test',
        'order_name'     => 'NR-TEST999',
        'shipped_items'  => $shippedHtml,
    ]);
    neria_assert($htmlShipped !== null, "renderWithVars('order_partial_shipped') a renvoye null — jeu de test invalide");
    neria_assert(
        strpos($htmlShipped, '&lt;p') === false && strpos($htmlShipped, 'NER-TEST') !== false,
        "le fragment HTML legitime {shipped_items} a ete echappe a tort — regression de la liste noire incomplete corrigee le 09/08/2026 (round 149)"
    );

    return [
        'pass'    => true,
        'message' => "EmailRenderer::renderWithVars() echappe bien toute variable de texte libre (meme mecanisme que l'envoi reel), sans casser les fragments HTML legitimes desormais complets ({shipped_items}/{messages}/{virtualProducts} inclus)",
    ];
}
