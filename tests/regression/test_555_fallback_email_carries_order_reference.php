<?php
/**
 * Régression : `EmailRenderer::sendFallbackEmail()` construisait un email de
 * secours totalement générique quand le rendu d'un template échoue — sans
 * jamais réinjecter `{id_order}`/`{order_name}`, pourtant présents dans
 * `$params['templateVars']` d'origine pour tout email lié à une commande
 * précise (`order_conf` et autres). Un client dont le rendu échouait
 * PRÉCISÉMENT sur SA commande (variable produit manquante, devise mal
 * formée sur une déclinaison...) recevait un email vague sans aucun moyen
 * de relier ce message à son achat — le pire moment pour perdre cette
 * info métier critique.
 *
 * Bug identifié le 04/09/2026 (round 298, audit "fallback email et fenêtre
 * d'achat individuelle").
 *
 * Corrigé le 04/09/2026 (round 298) : `{fallback_order_ref}`/
 * `{fallback_order_ref_txt}` construits à partir de
 * `$params['templateVars']['{id_order}']`/`['{order_name}']` quand
 * présents, injectés dans le template `neria_fallback.html`/`.txt` —
 * vides (aucune ligne affichée) si le template en échec n'était pas lié à
 * une commande.
 *
 * Test comportemental réel : appelle `sendFallbackEmail()` (via réflexion)
 * avec des `templateVars` contenant `{id_order}`/`{order_name}`, inspecte
 * le fichier HTML/TXT réellement compilé sur disque et vérifie qu'il
 * contient bien la référence de commande.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';

    $renderer = new EmailRenderer(neria_test_module());
    $ref = new ReflectionMethod(EmailRenderer::class, 'sendFallbackEmail');
    $ref->setAccessible(true);

    $params = [
        'to'           => 'regtest555@example.test',
        'toName'       => 'Regtest Client',
        'template'     => 'order_conf',
        'templateVars' => [
            '{id_order}'   => 555555,
            '{order_name}' => 'NER-REGTEST-555',
        ],
    ];

    $dir = _PS_MODULE_DIR_ . 'neria/mails/fr/';
    $before = glob($dir . 'neria_fallback__*.html') ?: [];

    $result = $ref->invoke($renderer, $params, new RuntimeException('regtest forced failure'));

    $after = glob($dir . 'neria_fallback__*.html') ?: [];
    $newFiles = array_diff($after, $before);

    try {
        neria_assert(
            $result === true,
            "sendFallbackEmail() a échoué (résultat false) — jeu de test invalide ou régression sans rapport avec ce bug"
        );
        neria_assert(
            count($newFiles) === 1,
            "aucun nouveau fichier de secours compilé trouvé — jeu de test invalide"
        );

        $htmlFile = reset($newFiles);
        $htmlContent = file_get_contents($htmlFile);
        neria_assert(
            $htmlContent !== false && strpos($htmlContent, 'NER-REGTEST-555') !== false,
            "l'email de secours compilé ne contient plus la référence de commande ({order_name}) — régression du bug corrigé le 04/09/2026 (round 298) : un client dont le rendu échoue sur sa propre commande recevrait de nouveau un message générique sans aucun moyen de relier l'email à son achat"
        );

        $txtFile = str_replace('.html', '.txt', $htmlFile);
        if (file_exists($txtFile)) {
            $txtContent = file_get_contents($txtFile);
            neria_assert(
                $txtContent !== false && strpos($txtContent, 'NER-REGTEST-555') !== false,
                "la variante texte brut de l'email de secours ne contient plus la référence de commande — régression du bug corrigé le 04/09/2026 (round 298)"
            );
            @unlink($txtFile);
        }

        return [
            'pass'    => true,
            'message' => "EmailRenderer::sendFallbackEmail() réinjecte désormais {order_name}/{id_order} dans l'email de secours quand le template en échec était lié à une commande — bug corrigé le 04/09/2026 (round 298)",
        ];
    } finally {
        foreach ($newFiles as $f) {
            @unlink($f);
        }
    }
}
