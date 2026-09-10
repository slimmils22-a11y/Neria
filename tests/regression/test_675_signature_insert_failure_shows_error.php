<?php
/**
 * Régression : le handler `generate_signature` de `neria.php` supprimait
 * l'ancien fichier PNG de signature (`SignatureGenerator::delete()`),
 * désactivait TOUTES les lignes `neria_signature` existantes
 * (`UPDATE ... SET is_active = 0`), puis insérait la nouvelle ligne SANS
 * JAMAIS vérifier le retour de `Db::insert()` — un message de succès
 * (`msg.saved`) était affiché inconditionnellement juste après.
 *
 * Bug identifié le 10/09/2026 (round 333, audit ChecklistManager/
 * AcademyProgressManager, redirigé sur SignatureGenerator). Risque déjà
 * partiellement pointé en commentaire round 262 ("le code ne vérifie que
 * $path... pas le succès de cet INSERT") mais jamais corrigé.
 *
 * Scénario concret : si cet INSERT échoue (contrainte, verrou concurrent,
 * disque plein), plus AUCUNE ligne `is_active=1` ne reste en base pour
 * cette boutique — l'ancien fichier PNG fonctionnel a déjà été supprimé —
 * tout en affichant "succès" au marchand. Tous les emails envoyés ensuite
 * perdent silencieusement leur signature.
 *
 * Corrigé le 10/09/2026 (round 333) : retour de `Db::insert()` capturé et
 * vérifié — message d'erreur dédié (`msg.signature_save_failed`) + alerte
 * Watchdog si l'INSERT échoue, au lieu du succès inconditionnel.
 *
 * Test structurel (le handler est une action BO complète — dispatch
 * d'action admin, GET_LOCK MySQL, formulaire — impraticable à invoquer
 * isolément en CLI sans monter tout ce contexte, même limite déjà
 * acceptée pour d'autres handlers de ce fichier, cf. test_264) : vérifie
 * que le retour de l'INSERT est bien capturé et conditionne l'affichage
 * du succès/de l'erreur, et que les 19 langues de la nouvelle traduction
 * d'erreur sont bien présentes.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $posInsert = strpos($src, "\$inserted = \$db->insert('neria_signature', [");
    neria_assert(
        $posInsert !== false,
        "neria.php ne capture plus le retour de Db::insert('neria_signature', ...) dans une variable — régression du bug corrigé le 10/09/2026 (round 333) : le succès de l'écriture en base ne serait de nouveau jamais vérifié"
    );

    $body = substr($src, $posInsert, 2800);
    neria_assert(
        strpos($body, 'if ($inserted) {') !== false
            && strpos($body, "AdminTranslator::t('msg.signature_save_failed')") !== false,
        "neria.php n'affiche plus d'erreur dédiée quand l'INSERT de neria_signature échoue — régression du bug corrigé le 10/09/2026 (round 333) : un message de succès inconditionnel redeviendrait affiché même si aucune signature active ne reste réellement en base"
    );
    neria_assert(
        strpos($body, "watchdog.signature_save_failed") !== false,
        "neria.php ne journalise plus l'échec de l'INSERT via Watchdog — régression du bug corrigé le 10/09/2026 (round 333)"
    );

    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(is_array($translations), 'admin_translations.json illisible ou invalide');
    $locales = ['fr','en','de','it','es','pt','br','ar','ja','ko','zh','tw','ru','tr','sv','no','da','nl','gb'];
    foreach (['msg.signature_save_failed', 'watchdog.signature_save_failed'] as $key) {
        neria_assert(isset($translations[$key]), "Clé de traduction '{$key}' absente de admin_translations.json");
        foreach ($locales as $l) {
            neria_assert(
                !empty($translations[$key][$l]),
                "Traduction '{$key}' manquante ou vide pour la locale '{$l}'"
            );
        }
    }

    return [
        'pass'    => true,
        'message' => "neria.php vérifie désormais le retour de Db::insert('neria_signature', ...) et affiche une erreur dédiée (+ alerte Watchdog) au lieu d'un succès inconditionnel — bug corrigé le 10/09/2026 (round 333)",
    ];
}
