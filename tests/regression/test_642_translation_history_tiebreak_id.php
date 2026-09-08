<?php
/**
 * Régression : TranslationHistoryManager::getHistoryForTemplate() triait
 * uniquement par `ORDER BY date_add DESC`, sans départage sur `id_history`
 * — contrairement à pruneKey() (même fichier), qui départage explicitement
 * sur `id_history DESC` avec un commentaire documentant précisément
 * pourquoi : `date_add` a une résolution à la seconde, donc l'ordre entre
 * deux entrées insérées dans la même seconde (édition rapide de plusieurs
 * champs dans le même écran BO, import en masse) est indéterminé sans ce
 * départage.
 *
 * getHistoryForTemplate() alimente l'écran d'historique où le marchand
 * choisit QUELLE entrée restaurer (neria.php restore_translation/
 * restore_variant_b) — un ordre non déterministe pouvait afficher l'entrée
 * la plus récente EN DESSOUS d'une entrée plus ancienne, risquant de
 * tromper le marchand sur la version qu'il pense restaurer.
 *
 * Corrigé le 08/09/2026 (round 324) : `ORDER BY date_add DESC, id_history
 * DESC`, même départage que pruneKey().
 *
 * Test comportemental réel : insère 2 entrées avec un `date_add` IDENTIQUE
 * (simule la résolution à la seconde) mais des `id_history` croissants
 * (ordre d'insertion réel), puis vérifie que getHistoryForTemplate() les
 * renvoie bien dans l'ordre id_history DESC (la plus récente insérée en
 * premier), pas un ordre indéterminé.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationHistoryManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();

    $template = 'neria_test_round324_tiebreak';
    $lang     = 'fr';
    $idShop   = (int) Context::getContext()->shop->id;

    try {
        $sameTimestamp = date('Y-m-d H:i:s');
        $db->execute(
            "INSERT INTO {$prefix}neria_translation_history
                (id_shop, template_key, lang_code, translation_key, old_value, new_value, author, date_add)
             VALUES
                ({$idShop}, '{$template}', '{$lang}', 'subject', 'ancien1', 'nouveau1', 'test', '{$sameTimestamp}')"
        );
        $idFirst = (int) $db->Insert_ID();

        $db->execute(
            "INSERT INTO {$prefix}neria_translation_history
                (id_shop, template_key, lang_code, translation_key, old_value, new_value, author, date_add)
             VALUES
                ({$idShop}, '{$template}', '{$lang}', 'body', 'ancien2', 'nouveau2', 'test', '{$sameTimestamp}')"
        );
        $idSecond = (int) $db->Insert_ID();

        neria_assert($idSecond > $idFirst, 'Jeu de test invalide : id_history non croissant');

        $mgr = new TranslationHistoryManager($module);
        $history = $mgr->getHistoryForTemplate($template, $lang, 10);

        neria_assert(count($history) === 2, "getHistoryForTemplate() n'a pas renvoyé les 2 entrées attendues — jeu de test invalide");

        neria_assert(
            (int) $history[0]['id_history'] === $idSecond,
            "getHistoryForTemplate() renvoie id_history={$history[0]['id_history']} en premier au lieu de {$idSecond} (la plus récemment insérée, même date_add) — régression du bug corrigé le 08/09/2026 (round 324) : l'ordre entre deux entrées de la même seconde redeviendrait indéterminé, risquant de tromper le marchand sur la version qu'il restaure"
        );
        neria_assert(
            (int) $history[1]['id_history'] === $idFirst,
            "getHistoryForTemplate() ne renvoie pas id_history={$idFirst} en second — régression du bug corrigé le 08/09/2026 (round 324)"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_translation_history WHERE template_key = '{$template}'");
    }

    return [
        'pass'    => true,
        'message' => "TranslationHistoryManager::getHistoryForTemplate() départage bien sur id_history DESC quand date_add est identique, même tie-break que pruneKey() — bug corrigé le 08/09/2026 (round 324)",
    ];
}
