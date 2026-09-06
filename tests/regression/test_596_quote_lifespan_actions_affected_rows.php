<?php
/**
 * Régression : les actions BO `quote_mark_won`/`quote_mark_lost`/
 * `quote_delete`/`lifespan_delete` (neria.php) exécutaient un UPDATE/
 * DELETE scopé `id_shop` sans jamais vérifier Affected_Rows() —
 * Db::execute() renvoie true même à 0 ligne affectée (id inexistant ou
 * appartenant à une autre boutique). Le message de succès s'affichait
 * donc inconditionnellement dès que l'ID fourni était numériquement
 * positif — même pattern que restore_translation/restore_variant_b/
 * add_calendar_event (round 310), retrouvé dans une zone différente du
 * fichier.
 *
 * Corrigé le 06/09/2026 (round 311) : Affected_Rows() vérifié après
 * chacune de ces 4 requêtes ; neria_error (msg.quote_not_found /
 * msg.lifespan_not_found) assigné si 0 ligne affectée.
 *
 * Test structurel : vérifie la présence du garde-fou Affected_Rows()
 * pour chacune des 4 actions.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $actions = [
        'quote_mark_won'  => "AdminTranslator::t('msg.quote_not_found')",
        'quote_mark_lost' => "AdminTranslator::t('msg.quote_not_found')",
        'quote_delete'    => "AdminTranslator::t('msg.quote_not_found')",
        'lifespan_delete' => "AdminTranslator::t('msg.lifespan_not_found')",
    ];

    foreach ($actions as $action => $errorCall) {
        $pos = strpos($src, "Tools::getValue('neria_action') === '{$action}'");
        neria_assert($pos !== false, "action {$action} introuvable — jeu de test invalide");
        $body = substr($src, $pos, 1200);
        neria_assert(
            strpos($body, 'Affected_Rows()') !== false,
            "{$action} ne vérifie plus Affected_Rows() après son UPDATE/DELETE — régression du bug corrigé le 06/09/2026 (round 311) : le message de succès s'afficherait de nouveau même pour un ID inexistant ou d'une autre boutique"
        );
        neria_assert(
            strpos($body, $errorCall) !== false,
            "{$action} n'assigne plus de neria_error dédié pour le cas 0 ligne affectée — régression du bug corrigé le 06/09/2026 (round 311)"
        );
    }

    // Vérifie que les 2 nouvelles clés de traduction existent (19 langues).
    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    foreach (['msg.quote_not_found', 'msg.lifespan_not_found'] as $key) {
        neria_assert(
            isset($translations[$key]) && count($translations[$key]) === 19,
            "clé {$key} manquante ou incomplète dans admin_translations.json (19 langues attendues)"
        );
    }

    return [
        'pass'    => true,
        'message' => "quote_mark_won/quote_mark_lost/quote_delete/lifespan_delete vérifient bien Affected_Rows() avant d'afficher un message de succès — bug corrigé le 06/09/2026 (round 311)",
    ];
}
