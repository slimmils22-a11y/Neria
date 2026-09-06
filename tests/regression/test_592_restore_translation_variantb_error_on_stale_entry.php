<?php
/**
 * Régression : les actions BO `restore_translation` et `restore_variant_b`
 * (neria.php) assignaient `neria_success` (« Enregistré ») INCONDITIONNELLEMENT
 * après leur bloc de traitement — même quand `id_history` était invalide,
 * absent, ou pointait vers un AUTRE template/langue/variante que celui/celle
 * actuellement affiché (protection IDOR déjà en place, qui fait alors
 * échouer silencieusement la restauration réelle sans jamais l'exécuter).
 *
 * Scénario concret : un employé change d'onglet (template ou langue
 * différent) sans recharger la page, clique sur "Restaurer" avec un
 * id_history obsolète — aucune écriture n'a lieu (ni update() ni record()),
 * et pourtant le message "Enregistré" s'affichait, laissant croire à tort
 * que la traduction/variante B avait été restaurée.
 *
 * Corrigé le 06/09/2026 (round 310) : neria_success déplacé À L'INTÉRIEUR
 * de la condition de correspondance réelle ; un nouveau message
 * neria_error (msg.restore_entry_not_found) est assigné dans tous les
 * autres cas (id_history absent/invalide, entrée introuvable, ou
 * template/langue/variante ne correspondant plus).
 *
 * Test structurel : vérifie que neria_success est bien positionné DANS le
 * bloc de correspondance réelle (pas après sa fermeture), et qu'un
 * neria_error existe pour le cas de non-correspondance, pour les deux
 * actions.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    // ── restore_translation ──────────────────────────────────────────
    $posRT = strpos($src, "if (\$tradAction === 'restore_translation' && class_exists('TranslationHistoryManager')) {");
    neria_assert($posRT !== false, 'restore_translation introuvable — jeu de test invalide');
    $bodyRT = substr($src, $posRT, 4000);

    $posSuccessRT = strpos($bodyRT, "smarty->assign('neria_success', AdminTranslator::t('msg.saved'));");
    $posWatchdogRT = strpos($bodyRT, "'watchdog.translation_field_restored'");
    neria_assert($posWatchdogRT !== false, 'log Watchdog de restauration introuvable dans restore_translation — jeu de test invalide');
    neria_assert(
        $posSuccessRT !== false && $posSuccessRT > $posWatchdogRT,
        "restore_translation : neria_success n'est plus positionné APRÈS le log Watchdog de restauration réelle (donc DANS le bloc de correspondance) — régression du bug corrigé le 06/09/2026 (round 310) : un id_history obsolète afficherait de nouveau 'Enregistré' sans avoir rien restauré"
    );
    neria_assert(
        substr_count($bodyRT, "AdminTranslator::t('msg.restore_entry_not_found')") >= 1,
        "restore_translation n'assigne plus neria_error pour une entrée d'historique obsolète/introuvable — régression du bug corrigé le 06/09/2026 (round 310)"
    );

    // ── restore_variant_b ─────────────────────────────────────────────
    $posRVB = strpos($src, "if (\$tradAction === 'restore_variant_b' && class_exists('TranslationHistoryManager')) {");
    neria_assert($posRVB !== false, 'restore_variant_b introuvable — jeu de test invalide');
    $bodyRVB = substr($src, $posRVB, 3600);

    $posSuccessRVB = strpos($bodyRVB, "smarty->assign('neria_success', AdminTranslator::t('msg.saved'));");
    $posRecordRVB  = strpos($bodyRVB, "'variantb_' . \$tplKey,");
    neria_assert($posRecordRVB !== false, "appel record() de restore_variant_b introuvable — jeu de test invalide");
    neria_assert(
        $posSuccessRVB !== false && $posSuccessRVB > $posRecordRVB,
        "restore_variant_b : neria_success n'est plus positionné APRÈS le record() de restauration réelle (donc DANS le bloc de correspondance) — régression du bug corrigé le 06/09/2026 (round 310) : un id_history obsolète afficherait de nouveau 'Enregistré' sans avoir rien restauré"
    );
    neria_assert(
        substr_count($bodyRVB, "AdminTranslator::t('msg.restore_entry_not_found')") >= 1,
        "restore_variant_b n'assigne plus neria_error pour une entrée d'historique obsolète/introuvable — régression du bug corrigé le 06/09/2026 (round 310)"
    );

    // Vérifie que la nouvelle clé de traduction existe bien dans les 19 langues.
    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(
        isset($translations['msg.restore_entry_not_found']) && count($translations['msg.restore_entry_not_found']) === 19,
        "clé msg.restore_entry_not_found manquante ou incomplète dans admin_translations.json (19 langues attendues)"
    );

    return [
        'pass'    => true,
        'message' => "restore_translation et restore_variant_b n'affichent plus 'Enregistré' quand aucune restauration réelle n'a eu lieu — bug corrigé le 06/09/2026 (round 310)",
    ];
}
