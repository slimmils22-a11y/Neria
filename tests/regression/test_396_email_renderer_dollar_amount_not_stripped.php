<?php
/**
 * Régression : EmailRenderer::buildCompiledHtml() (2 occurrences, compilation
 * réelle + variante avec journalisation Watchdog) utilisait preg_replace()
 * pour injecter le contenu du bloc neria_content dans le layout, en passant
 * ce contenu comme argument REMPLACEMENT (2e paramètre) plutôt que via
 * preg_replace_callback().
 *
 * Bug réel identifié le 23/08/2026 (round 188) : preg_replace() interprète
 * tout '$' suivi d'un chiffre dans la chaîne de remplacement comme une
 * rétro-référence ($1, $2, ${1}...) et le remplace par une chaîne vide
 * faute de groupe capturé correspondant dans le motif de recherche. Tout
 * '$' suivi d'un chiffre présent dans le HTML compilé du bloc (ex. un prix
 * "$50", un montant "$1 000", tout texte contenant "$" + chiffre) était
 * donc silencieusement tronqué à CHAQUE compilation d'email.
 *
 * Corrigé le 23/08/2026 (round 188) : preg_replace_callback() avec une
 * closure retournant le contenu du bloc verbatim.
 *
 * Test comportemental réel : compile un template dont le contenu core
 * contient littéralement "$50" et vérifie que la chaîne survit intacte
 * dans le HTML compilé final.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';

    $themeDir = _PS_MODULE_DIR_ . 'neria/mails/themes/neria_global/core/';
    $tmpTemplate = '__test_396_dollar_amount__';
    $tmpPath = $themeDir . $tmpTemplate . '.html';

    $originalExists = file_exists($tmpPath);
    $backup = $originalExists ? file_get_contents($tmpPath) : null;

    // Contenu volontairement truffé de séquences '$' + chiffre, comme un
    // prix en dollars déjà résolu par le moteur de rendu en amont.
    file_put_contents(
        $tmpPath,
        "{extends file='layout.html'}\n{block name='neria_content'}<p>Save \$50 today, only \$1 000 left in stock ($2 shipping)!</p>{/block}"
    );

    try {
        $module = neria_test_module();
        $renderer = new EmailRenderer($module);

        $refConfig = new ReflectionProperty(EmailRenderer::class, 'config');
        $refConfig->setAccessible(true);
        $configMgr = $refConfig->getValue($renderer);
        $design = $configMgr->getDesignConfig();

        $ref = new ReflectionMethod(EmailRenderer::class, 'buildCompiledHtml');
        $ref->setAccessible(true);
        $html = $ref->invoke($renderer, $tmpTemplate, 'fr', $design);

        neria_assert($html !== null, 'jeu de test invalide : buildCompiledHtml() a retourné null');
        neria_assert(
            strpos($html, 'Save $50 today') !== false,
            "\"Save \$50 today\" a disparu/été tronqué du HTML compilé — régression du bug corrigé le 23/08/2026 (round 188) : preg_replace() traiterait de nouveau le contenu du bloc comme un motif de rétro-référence"
        );
        neria_assert(
            strpos($html, 'only $1 000 left') !== false,
            "\"only \$1 000 left\" a disparu/été tronqué du HTML compilé — régression du bug corrigé le 23/08/2026 (round 188)"
        );
        neria_assert(
            strpos($html, '($2 shipping)') !== false,
            "\"(\$2 shipping)\" a disparu/été tronqué du HTML compilé — régression du bug corrigé le 23/08/2026 (round 188)"
        );
    } finally {
        if ($originalExists) {
            file_put_contents($tmpPath, $backup);
        } elseif (file_exists($tmpPath)) {
            unlink($tmpPath);
        }
    }

    return [
        'pass'    => true,
        'message' => "EmailRenderer::buildCompiledHtml() ne tronque plus les séquences \$+chiffre du contenu compilé (preg_replace_callback) — bug corrigé le 23/08/2026 (round 188)",
    ];
}
