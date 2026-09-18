<?php
/**
 * Régression : EmailRenderer::compileNeriaTemplate() (Salutation horaire
 * "Smart Salutation") concaténait {time_greeting} + ", " + {firstname} en
 * traitant {firstname} comme un vrai prénom court — sans savoir qu'en
 * l'absence de client identifié, injectFirstnameFallback() avait déjà
 * remplacé {firstname} par une PHRASE de politesse complète ("Dear Guest").
 *
 * Résultat : "Good evening" + ", " + "Dear Guest" + "," = "Good evening,
 * Dear Guest," — deux formules de politesse empilées, grammaticalement
 * cassé. Reproduit via un vrai envoi manuel (bloc 3, test end-to-end,
 * 18/09/2026) vers une adresse sans client PrestaShop associé.
 *
 * Corrigé : injectFirstnameFallback() marque désormais explicitement
 * templateVars['__firstname_is_fallback'] = true ; la Salutation horaire
 * ignore {firstname} dans ce cas (la salutation horaire seule, "Good
 * evening,", reste une formule complète et élégante).
 *
 * Test comportemental réel : compile le template "artisan_message" (celui
 * utilisé lors du test réel) avec templateVars simulant exactement l'état
 * post-fallback d'un envoi sans client identifié, vérifie que le HTML
 * compilé NE contient PAS la salutation cassée, et contient bien la
 * salutation horaire seule.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';

    $module = neria_test_module();
    $outputName = 'regtest781_salutation_check';
    $outFile = _PS_MODULE_DIR_ . 'neria/mails/en/' . $outputName . '.html';

    if (file_exists($outFile)) {
        unlink($outFile);
    }

    try {
        $renderer = new EmailRenderer($module);
        $method   = new ReflectionMethod(EmailRenderer::class, 'compileNeriaTemplate');
        $method->setAccessible(true);

        // Simule exactement l'état des templateVars après un envoi sans
        // client identifié : {firstname} contient la PHRASE de repli
        // (pas un prénom), marquée comme telle, et {time_greeting} est
        // déjà résolu (comme le ferait injectTimeGreeting()).
        $templateVars = [
            '{firstname}'               => 'Dear Guest',
            '__firstname_is_fallback'   => true,
            '{time_greeting}'           => 'Good evening',
            '{custom_message}'          => 'Message de test.',
            '{custom_message_txt}'      => 'Message de test.',
        ];

        $result = $method->invoke(
            $renderer,
            'artisan_message',
            'en',
            'en',
            $templateVars,
            false,
            false,
            $outputName
        );

        neria_assert($result !== null, "compileNeriaTemplate() a échoué (retour null) — jeu de test invalide");
        neria_assert(file_exists($outFile), "Fichier compilé introuvable : {$outFile}");

        $html = file_get_contents($outFile);

        neria_assert(
            strpos($html, 'Good evening, Dear Guest') === false,
            "Le HTML compilé contient encore la salutation cassée 'Good evening, Dear Guest' — régression du correctif bloc 3 (18/09/2026)"
        );
        neria_assert(
            strpos($html, 'Good evening,') !== false,
            "Le HTML compilé ne contient plus la salutation horaire seule 'Good evening,' — jeu de test invalide ou régression du mécanisme Smart Salutation lui-même"
        );

        return [
            'pass'    => true,
            'message' => "EmailRenderer ne concatène plus le prénom de repli après la salutation horaire — bug corrigé round bloc 3 (18/09/2026)",
        ];
    } finally {
        if (file_exists($outFile)) {
            unlink($outFile);
        }
    }
}
