<?php
/**
 * Régression : EmailRenderer::injectCustomVars() utilisait empty() pour
 * décider si une variable était "déjà présente" dans templateVars — mais
 * son propre docblock promet explicitement de ne JAMAIS remplacer une
 * valeur déjà présente (respecte les fakeVars d'aperçu). empty() traite
 * aussi "0" et "" comme absents, contredisant ce comportement documenté :
 * une valeur légitime à "0" (compteur, code promo) déjà injectée en amont
 * était silencieusement écrasée par la variable personnalisée du marchand.
 *
 * Corrigé le 15/08/2026 (round 176) : array_key_exists() remplace empty(),
 * qui ne regarde que la présence de la clé, pas la "vérité" de sa valeur.
 *
 * Test comportemental réel : une variable personnalisée réelle en base
 * (table neria_custom_variable) avec templateVars['{clé}'] pré-rempli à
 * "0" — après injectCustomVars(), cette valeur "0" doit être préservée,
 * pas écrasée par la valeur de la variable personnalisée.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $key    = 'regtest176zero';

    $db->execute("DELETE FROM {$prefix}neria_custom_variable WHERE variable_key = '" . pSQL($key) . "'");

    try {
        $db->execute(
            "INSERT INTO {$prefix}neria_custom_variable (id_shop, variable_key, variable_value, description)
             VALUES (" . (int) Context::getContext()->shop->id . ", '" . pSQL($key) . "', 'valeur-marchand', '')"
        );

        $renderer = new EmailRenderer(neria_test_module());
        $ref      = new ReflectionMethod(EmailRenderer::class, 'injectCustomVars');
        $ref->setAccessible(true);

        $templateVars = ['{' . $key . '}' => '0'];
        $ref->invokeArgs($renderer, [&$templateVars]);

        neria_assert(
            $templateVars['{' . $key . '}'] === '0',
            "EmailRenderer::injectCustomVars() a écrasé une valeur '0' déjà présente dans templateVars par la variable personnalisée du marchand — régression du bug corrigé le 15/08/2026 (round 176) : empty() traiterait de nouveau '0' comme absent, contredisant le docblock qui promet de ne jamais remplacer une valeur déjà présente. Obtenu : " . var_export($templateVars['{' . $key . '}'], true)
        );

        return [
            'pass'    => true,
            'message' => "EmailRenderer::injectCustomVars() préserve bien une valeur '0' déjà présente dans templateVars (array_key_exists, pas empty) — bug corrigé le 15/08/2026 (round 176)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_custom_variable WHERE variable_key = '" . pSQL($key) . "'");
    }
}
