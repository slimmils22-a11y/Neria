<?php
/**
 * Régression : ABTestManager::getVariantForEmail() retournait VARIANT_A
 * (au lieu de '') quand aucune clé de répartition n'était disponible
 * (email vide/malformé ET idCustomer=0) — cas normalement censé ne jamais
 * se produire au moment de l'envoi (email toujours connu, cf. docblock de
 * la méthode), mais atteignable si EmailRenderer::resolveABVariant() reçoit
 * un $params['to'] vide/malformé.
 *
 * Bug réel identifié le 23/08/2026 (round 189) : assigner systématiquement
 * VARIANT_A à ces cas limites gonflait artificiellement son volume et
 * faussait le calcul du "gagnant" — exactement le même biais que ce fichier
 * documente déjà avoir corrigé pour les invités (repli sur l'email plutôt
 * que id_customer).
 *
 * Corrigé le 23/08/2026 (round 189) : retourne '' (pas de test assigné,
 * cohérent avec le cas "aucun test actif pour ce template" traité juste
 * au-dessus dans la même méthode).
 *
 * Test comportemental réel : crée un test A/B actif pour un template, puis
 * appelle getVariantForEmail() avec idCustomer=0 et email=''.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ABTestManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;

    $template = 'test_403_round189';

    $now = date('Y-m-d H:i:s');
    $db->execute("DELETE FROM {$prefix}neria_abtest WHERE template = '" . pSQL($template) . "'");
    $db->execute(
        "INSERT INTO {$prefix}neria_abtest
            (id_shop, template, variant, variant_name, description, split_percent, is_active, date_add, date_upd)
         VALUES
            ({$idShop}, '" . pSQL($template) . "', 'A', 'Variante A', '', 50, 1, '{$now}', '{$now}'),
            ({$idShop}, '" . pSQL($template) . "', 'B', 'Variante B', '', 50, 1, '{$now}', '{$now}')"
    );

    try {
        $mgr = new ABTestManager($module);
        $variant = $mgr->getVariantForEmail($template, 0, '');

        neria_assert(
            $variant === '',
            "getVariantForEmail() retourne '{$variant}' au lieu de '' quand aucune clé de répartition n'est disponible (idCustomer=0, email='') — régression du bug corrigé le 23/08/2026 (round 189) : ce cas limite serait de nouveau systématiquement assigné à VARIANT_A, gonflant son volume et faussant le calcul du gagnant"
        );

        // Non-régression : avec une clé valide, le test reste bien assigné (A ou B).
        $variantValid = $mgr->getVariantForEmail($template, 0, 'client.round189@example.test');
        neria_assert(
            in_array($variantValid, ['A', 'B'], true),
            "getVariantForEmail() avec un email valide ne retourne plus 'A' ou 'B' (retourné : '{$variantValid}') — faux positif du correctif round 189"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_abtest WHERE template = '" . pSQL($template) . "'");
    }

    return [
        'pass'    => true,
        'message' => "ABTestManager::getVariantForEmail() retourne '' (pas de biais vers A) quand aucune clé de répartition n'est disponible — bug corrigé le 23/08/2026 (round 189)",
    ];
}
