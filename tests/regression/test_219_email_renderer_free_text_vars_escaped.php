<?php
/**
 * Régression : EmailRenderer::render() doit échapper TOUTE variable de
 * texte libre (pas seulement une liste blanche figée de 5 clés) avant
 * injection dans le HTML compilé — sinon toute nouvelle variable de texte
 * libre ajoutée par un futur template (ou déjà existante mais oubliée de
 * la liste) reste un vecteur XSS stocké.
 *
 * Bug réel corrigé le 09/08/2026 (round 148) : le durcissement défensif ne
 * couvrait que {firstname}/{lastname}/{message}/{comment}/{gift_message}.
 * Or ManualSendManager::send()/scheduleManual() injectent SANS AUCUN
 * filtre toute clé de champ texte saisie via l'envoi manuel BO — {reply}
 * (reply_msg.html), {apology_reason} (white_glove_apology.html),
 * {alteration_status} (alteration_update.html) en sont des exemples
 * concrets, découverts non couverts. Un employé BO (compte compromis ou
 * malveillant) pouvait ainsi livrer du HTML/JS actif dans la boîte mail
 * d'un vrai client via ces templates.
 *
 * Test comportemental réel : invoque EmailRenderer::compileNeriaTemplate()
 * (méthode privée, via Reflection — c'est le VRAI pipeline emprunté par
 * processEmailParams()/applyNeriaRendering() pour chaque email réellement
 * envoyé, contrairement à renderWithVars()/buildCompiledHtml() qui servent
 * l'aperçu BO et le renvoi d'historique — un pipeline de compilation
 * distinct et déjà correctement protégé ailleurs) avec une charge XSS dans
 * {reply}, lit le fichier HTML compilé réellement écrit sur disque et
 * vérifie qu'elle en ressort échappée — puis vérifie qu'un fragment HTML
 * volontairement pré-construit ({products}, sur order_conf) continue de
 * ressortir intact (non-régression : la bascule liste blanche → liste
 * noire ne doit pas casser les blocs HTML légitimes).
 */
require_once __DIR__ . '/bootstrap.php';

function neria_test_compile(EmailRenderer $renderer, string $template, array $templateVars): ?string
{
    $method = new ReflectionMethod(EmailRenderer::class, 'compileNeriaTemplate');
    $method->setAccessible(true);
    $outFile = $method->invoke($renderer, $template, 'fr', 'fr', $templateVars, true, true);
    if ($outFile === null || !file_exists($outFile)) {
        return null;
    }
    return file_get_contents($outFile);
}

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    $module = neria_test_module();
    $renderer = new EmailRenderer($module);

    $payload = '<img src=x onerror=alert(document.cookie)>';
    $html = neria_test_compile($renderer, 'reply_msg', [
        '{firstname}' => 'Test',
        '{reply}'     => $payload,
    ]);

    neria_assert($html !== null, "compileNeriaTemplate('reply_msg') n'a pas produit de fichier — jeu de test invalide");

    neria_assert(
        strpos($html, $payload) === false,
        "la charge XSS brute apparait telle quelle dans le HTML compile de reply_msg — regression du bug corrige le 09/08/2026 (round 148) : {reply} n'est de nouveau plus echappe"
    );
    // Le point critique n'est pas que le mot "onerror" disparaisse (il reste
    // legitimement present en texte echappe), mais que le tag ne soit PLUS
    // structurellement un element <img> actif : le "<" et le ">" doivent
    // etre neutralises, empechant tout navigateur/client mail de l'interpreter
    // comme une balise HTML reelle.
    neria_assert(
        strpos($html, '<img src=x onerror=') === false,
        "le tag <img> actif de la charge XSS n'a pas ete neutralise dans reply_msg — regression du bug corrige le 09/08/2026 (round 148) : {reply} serait de nouveau interprete comme du HTML actif par le client mail"
    );
    neria_assert(
        strpos($html, htmlspecialchars($payload, ENT_QUOTES, 'UTF-8')) !== false,
        "la charge XSS n'apparait pas sous sa forme echappee attendue dans reply_msg — le mecanisme d'echappement semble avoir change de comportement"
    );

    // Contre-preuve : un fragment HTML legitime pre-construit (tableau
    // produits) ne doit PAS etre echappe (regression sur order_conf sinon).
    $htmlProducts = '<p>Réf. NER-TEST — Produit × 1 — 10,00 €</p>';
    $htmlOrder = neria_test_compile($renderer, 'order_conf', [
        '{firstname}'           => 'Test',
        '{order_name}'          => 'NR-TEST999',
        '{products}'            => $htmlProducts,
        '{delivery_block_html}' => '<p>1 rue de Test, 75000 Paris</p>',
        '{invoice_block_html}'  => '<p>1 rue de Test, 75000 Paris</p>',
    ]);
    neria_assert($htmlOrder !== null, "compileNeriaTemplate('order_conf') n'a pas produit de fichier — jeu de test invalide");
    // Le contenu HTML brut de {products} peut etre réencapsulé (attributs de
    // style ajoutés par le formatage propre au tableau produits) mais ne
    // doit JAMAIS ressortir échappé en entités HTML — sinon la bascule
    // liste blanche/liste noire du round 148 aurait cassé le rendu du
    // tableau produits pour tous les templates de commande.
    neria_assert(
        strpos($htmlOrder, 'NER-TEST') !== false && strpos($htmlOrder, '&lt;p') === false && strpos($htmlOrder, 'Produit') !== false,
        "le bloc HTML legitime {products} a ete echappe a tort (entites &lt;/&gt; detectees) — la bascule liste blanche/liste noire du round 148 a casse le rendu du tableau produits"
    );

    return [
        'pass'    => true,
        'message' => "EmailRenderer::compileNeriaTemplate() echappe bien toute variable de texte libre (pas seulement une liste blanche figee), sans casser les fragments HTML legitimes deja pre-construits",
    ];
}
