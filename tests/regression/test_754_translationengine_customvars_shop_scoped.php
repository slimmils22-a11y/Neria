<?php
/**
 * Régression : TranslationEngine::get()/resolveVariables()/loadCustomVars()
 * résolvaient TOUJOURS les variables personnalisées du marchand
 * ({maison_name}/{slogan}/etc.) via Context::getContext()->shop->id
 * (contexte AMBIANT du process), jamais la boutique réelle transmise par
 * l'appelant — même piège déjà corrigé 3 fois ailleurs dans ce module pour
 * des données analogues (EmailRenderer rounds 138/321, CertificateManager
 * round 212), jamais porté à TranslationEngine lui-même alors que
 * neria_custom_variable est explicitement stocké PAR boutique.
 *
 * Bug identifié le 14/09/2026 (round 357, audit dédié TranslationEngine).
 * Scénario concret : boutique A a {maison_name}="Maison Dupont", boutique B
 * a {maison_name}="Maison Martin". Un employé BO en contexte "Boutique B"
 * déclenche un envoi/document pour une commande de la boutique A — le texte
 * censé représenter A affichait "Maison Martin" au lieu de "Maison Dupont".
 *
 * Corrigé le 14/09/2026 : get() accepte désormais un ?int $idShop optionnel
 * (repli sur le contexte ambiant si null), propagé aux chemins d'envoi réel
 * (EmailRenderer, CertificateManager).
 *
 * Test comportemental réel : crée 2 variables personnalisées différentes
 * pour 2 boutiques fictives (même id_shop group, id_shop distincts sur la
 * table neria_custom_variable directement — pas besoin d'une vraie 2e
 * boutique PrestaShop pour tester cette résolution), ajoute une clé de
 * traduction contenant {maison_name}, vérifie que get() avec chaque
 * $idShop explicite retourne bien la valeur correspondant à CETTE
 * boutique, pas celle du contexte ambiant.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationEngine.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();

    $idShopA = 999997001; // boutiques fictives, isolées des vraies données
    $idShopB = 999997002;

    $table = $prefix . 'neria_custom_variable';

    try {
        $db->execute("DELETE FROM {$table} WHERE id_shop IN ({$idShopA}, {$idShopB}) AND variable_key = 'regtest754_var'");
        $db->execute("INSERT INTO {$table} (id_shop, variable_key, variable_value, description, date_add, date_upd)
                       VALUES ({$idShopA}, 'regtest754_var', 'Valeur Boutique A', '', NOW(), NOW())");
        $db->execute("INSERT INTO {$table} (id_shop, variable_key, variable_value, description, date_add, date_upd)
                       VALUES ({$idShopB}, 'regtest754_var', 'Valeur Boutique B', '', NOW(), NOW())");

        $engine = new TranslationEngine($module);
        $ref    = new ReflectionMethod(TranslationEngine::class, 'resolveVariables');
        $ref->setAccessible(true);

        $textWithVar = 'Texte contenant {regtest754_var} inline.';

        $resultA = $ref->invoke($engine, $textWithVar, $idShopA);
        neria_assert(
            str_contains($resultA, 'Valeur Boutique A'),
            "resolveVariables() avec \$idShop={$idShopA} explicite ne résout pas '{regtest754_var}' en 'Valeur Boutique A' (obtenu : '{$resultA}') — régression du bug corrigé le 14/09/2026 (round 357) : les variables personnalisées ne sont plus résolues par boutique"
        );

        $resultB = $ref->invoke($engine, $textWithVar, $idShopB);
        neria_assert(
            str_contains($resultB, 'Valeur Boutique B'),
            "resolveVariables() avec \$idShop={$idShopB} explicite ne résout pas '{regtest754_var}' en 'Valeur Boutique B' (obtenu : '{$resultB}') — régression du bug corrigé le 14/09/2026 (round 357)"
        );

        neria_assert(
            $resultA !== $resultB,
            "resolveVariables() renvoie le même résultat pour 2 boutiques ayant des variables personnalisées DIFFÉRENTES — la résolution shop-scopée est inopérante"
        );

        return [
            'pass'    => true,
            'message' => "TranslationEngine::resolveVariables()/loadCustomVars() résolvent désormais les variables personnalisées PAR boutique explicite (\$idShop), pas seulement via le contexte ambiant — bug corrigé le 14/09/2026 (round 357)",
        ];
    } finally {
        $db->execute("DELETE FROM {$table} WHERE id_shop IN ({$idShopA}, {$idShopB}) AND variable_key = 'regtest754_var'");
    }
}
