<?php
/**
 * Régression : neria.php — l'action BO 'quote_add' n'appliquait aucune
 * validation sur quote_total (montant négatif accepté, DECIMAL(10,2)
 * l'acceptant sans erreur SQL, faussant tout reporting agrégé et l'email
 * de relance affichant ce montant au client B2B) ni sur expiry_date
 * (aucun Validate::isDate() — une valeur non convertible en DATE valide
 * provoquait un échec INSERT silencieux en sql_mode strict, jamais
 * vérifié avant ce round, contrairement à quote_mark_won/lost/delete et
 * lifespan_add/delete qui vérifient déjà leur effet réel).
 *
 * Corrigé le 08/09/2026 (round 322) :
 * - $quoteTotal < 0 et !Validate::isDate($expiryDate) rejettent désormais
 *   la soumission (neria_error, msg.quote_invalid_amount_or_date).
 * - Le retour de Db::execute() (INSERT) est désormais vérifié — un échec
 *   assigne neria_error (msg.quote_save_failed) au lieu d'un succès
 *   inconditionnel.
 *
 * Test structurel (code inline dans le contrôleur admin, nécessitant un
 * contexte AdminController/Employee complet pour être invoqué réellement
 * — même limitation documentée par test_454) : vérifie la présence du
 * garde-fou dans le code source.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $srcRaw = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($srcRaw !== false, 'Impossible de lire neria.php');
    $src = str_replace("\r", '', $srcRaw);

    $posAction = strpos($src, "Tools::getValue('neria_action') === 'quote_add'");
    neria_assert($posAction !== false, "Action 'quote_add' introuvable — jeu de test invalide");

    $body = substr($src, $posAction, 5500);

    neria_assert(
        strpos($body, 'Validate::isDate($expiryDate)') !== false,
        "neria.php::quote_add ne valide plus expiry_date via Validate::isDate() — régression du bug corrigé le 08/09/2026 (round 322) : une date non convertible provoquerait de nouveau un échec INSERT silencieux"
    );
    neria_assert(
        strpos($body, '$quoteTotal < 0') !== false,
        "neria.php::quote_add ne rejette plus un quote_total négatif — régression du bug corrigé le 08/09/2026 (round 322)"
    );
    neria_assert(
        strpos($body, "AdminTranslator::t('msg.quote_invalid_amount_or_date')") !== false,
        "neria.php::quote_add n'assigne plus neria_error pour un montant/date invalide — régression du bug corrigé le 08/09/2026 (round 322)"
    );
    neria_assert(
        strpos($body, '$quoteAdded') !== false
        && strpos($body, "AdminTranslator::t('msg.quote_save_failed')") !== false,
        "neria.php::quote_add ne vérifie plus le retour réel de l'INSERT — régression du bug corrigé le 08/09/2026 (round 322) : un succès s'afficherait de nouveau même si l'écriture a échoué"
    );

    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    foreach (['msg.quote_invalid_amount_or_date', 'msg.quote_save_failed'] as $key) {
        neria_assert(
            isset($translations[$key]) && count($translations[$key]) === 19,
            "clé {$key} manquante ou incomplète dans admin_translations.json (19 langues attendues)"
        );
    }

    return [
        'pass'    => true,
        'message' => "neria.php::quote_add valide désormais quote_total (>=0) et expiry_date (Validate::isDate) et vérifie l'effet réel de l'INSERT avant d'afficher un succès — bug corrigé le 08/09/2026 (round 322)",
    ];
}
