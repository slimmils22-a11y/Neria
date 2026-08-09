<?php
/**
 * Régression : Neria::abtestBelongsToShop() doit accepter un paramètre
 * $template optionnel, et l'action BO restore_variant_b doit l'utiliser
 * pour vérifier que id_abtest_b correspond bien au TEMPLATE affiché, pas
 * seulement à la boutique courante.
 *
 * Bug réel corrigé le 08/08/2026 (round 137) : abtestBelongsToShop()
 * vérifiait seulement id_shop, jamais que id_abtest_b correspond au
 * template actuellement affiché ($tplKey). Une requête POST avec un
 * id_history valide pour le template affiché mais un id_abtest_b pointant
 * vers le test A/B d'un AUTRE template actif sur la même boutique passait
 * ce contrôle sans problème : le contenu restauré (clé/valeur du template
 * affiché) s'écrivait alors dans neria_abtest_translation du mauvais test
 * A/B — corruption silencieuse de la variante B d'un template sans
 * rapport, via un vrai trou de contrôle serveur (pas seulement une
 * protection absente côté client).
 *
 * Test comportemental réel : crée deux tests A/B sur deux templates
 * différents de la même boutique, vérifie qu'abtestBelongsToShop() avec
 * le paramètre $template rejette bien un id_abtest appartenant à un AUTRE
 * template, alors qu'il l'acceptait avant ce correctif (id_shop seul).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;

    $tplA = 'neria_test_round137_tpl_a';
    $tplB = 'neria_test_round137_tpl_b';

    try {
        $db->execute(
            "INSERT INTO {$prefix}neria_abtest
                (id_shop, template, variant, variant_name, description, split_percent, is_active, date_add, date_upd)
             VALUES ({$idShop}, '{$tplA}', 'B', 'Test A', '', 50, 0, NOW(), NOW())"
        );
        $idAbtestA = (int) $db->Insert_ID();

        $db->execute(
            "INSERT INTO {$prefix}neria_abtest
                (id_shop, template, variant, variant_name, description, split_percent, is_active, date_add, date_upd)
             VALUES ({$idShop}, '{$tplB}', 'B', 'Test B', '', 50, 0, NOW(), NOW())"
        );
        $idAbtestB = (int) $db->Insert_ID();

        $module = neria_test_module();
        $method = new ReflectionMethod(get_class($module), 'abtestBelongsToShop');
        $method->setAccessible(true);

        // Sans filtre template (comportement legacy préservé pour les
        // autres appelants) : les deux tests appartiennent bien à la
        // boutique.
        neria_assert($method->invoke($module, $idAbtestA) === true, "abtestBelongsToShop() sans \$template a échoué sur un test A/B valide de la boutique — jeu de test invalide");

        // Avec le filtre template = tplA : le test A/B de tplB doit être
        // REJETÉ, celui de tplA accepté.
        neria_assert(
            $method->invoke($module, $idAbtestA, $tplA) === true,
            "abtestBelongsToShop(\$idAbtestA, '{$tplA}') rejette à tort un test A/B qui appartient réellement à ce template"
        );
        neria_assert(
            $method->invoke($module, $idAbtestB, $tplA) === false,
            "abtestBelongsToShop() accepte un id_abtest appartenant à un AUTRE template ('{$tplB}') alors qu'on filtre sur '{$tplA}' — régression du bug corrigé le 08/08/2026 (round 137) : restore_variant_b pourrait de nouveau écraser la variante B d'un template sans rapport"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_abtest WHERE template IN ('{$tplA}', '{$tplB}') AND id_shop = {$idShop}");
    }

    // Vérification structurelle complémentaire : l'action restore_variant_b
    // doit bien passer $tplKey à abtestBelongsToShop().
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert(
        strpos($src, 'abtestBelongsToShop($idAbtestB, $tplKey)') !== false,
        "L'action restore_variant_b n'appelle plus abtestBelongsToShop() avec le paramètre \$tplKey — régression du bug corrigé le 08/08/2026 (round 137)"
    );

    return [
        'pass'    => true,
        'message' => "Neria::abtestBelongsToShop() vérifie bien que id_abtest appartient au template demandé, et restore_variant_b l'utilise pour empêcher d'écraser la variante B d'un autre template",
    ];
}
