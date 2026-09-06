<?php
/**
 * Régression : TranslationHistoryManager::record() persistait `date_add`
 * via `date('Y-m-d H:i:s')` (horloge PHP), alors que TranslationEngine::
 * update() écrit déjà `date_upd` via `NOW()` côté SQL pour cette même clé
 * — même piège horloge PHP/MySQL déjà corrigé plusieurs fois ailleurs dans
 * le module (rounds 303/305/307), jamais étendu ici. Si le serveur PHP et
 * le serveur MySQL n'ont pas le même fuseau horaire, une entrée
 * d'historique (date_add, horloge PHP) pouvait apparaître postérieure ou
 * antérieure à date_upd (horloge MySQL) pour un même flux d'édition BO
 * immédiat (update() suivi de record()), rendant l'ordre chronologique
 * affiché au marchand incohérent avec la réalité.
 *
 * Corrigé le 06/09/2026 (round 308) : date_add sourcé via `SELECT NOW()`
 * MySQL au lieu de date() PHP.
 *
 * Test comportemental réel : bascule le fuseau horaire PHP vers un
 * décalage extrême (Pacific/Kiritimati, UTC+14) AVANT d'appeler record(),
 * puis vérifie que date_add persisté reste proche de NOW() MySQL — pas
 * décalé de ~14h comme le serait date('Y-m-d H:i:s') sous ce fuseau PHP.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationHistoryManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();

    $template = 'regtest586_template';
    $lang     = 'fr';
    $key      = 'regtest586_key';

    $db->execute(
        "DELETE FROM {$prefix}neria_translation_history
         WHERE template_key = '" . pSQL($template) . "' AND lang_code = '" . pSQL($lang) . "' AND translation_key = '" . pSQL($key) . "'"
    );

    $originalTz = date_default_timezone_get();

    try {
        date_default_timezone_set('Pacific/Kiritimati');

        $mgr = new TranslationHistoryManager(neria_test_module());
        $mgr->record($template, $lang, $key, 'Ancienne valeur', 'Nouvelle valeur', 'regtest586@example.com');

        $mysqlNow = (string) $db->getValue('SELECT NOW()');

        date_default_timezone_set($originalTz);
    } catch (\Throwable $e) {
        date_default_timezone_set($originalTz);
        throw $e;
    }

    $row = $db->getRow(
        "SELECT date_add FROM {$prefix}neria_translation_history
         WHERE template_key = '" . pSQL($template) . "' AND lang_code = '" . pSQL($lang) . "' AND translation_key = '" . pSQL($key) . "'
         ORDER BY id_history DESC"
    );

    try {
        neria_assert($row !== false, "aucune entrée d'historique trouvée après record() — jeu de test invalide");

        $diff = abs(strtotime($mysqlNow) - strtotime((string) $row['date_add']));
        neria_assert(
            $diff <= 10,
            "date_add ('{$row['date_add']}') est décalé de {$diff}s par rapport à NOW() MySQL ('{$mysqlNow}') — régression du bug corrigé le 06/09/2026 (round 308) : date_add redevenu sourcé via l'horloge PHP (sensible au fuseau du serveur applicatif) au lieu de MySQL"
        );

        return [
            'pass'    => true,
            'message' => "TranslationHistoryManager::record() persiste bien date_add via l'horloge MySQL (NOW()), insensible au fuseau horaire du serveur applicatif PHP — bug corrigé le 06/09/2026 (round 308)",
        ];
    } finally {
        $db->execute(
            "DELETE FROM {$prefix}neria_translation_history
             WHERE template_key = '" . pSQL($template) . "' AND lang_code = '" . pSQL($lang) . "' AND translation_key = '" . pSQL($key) . "'"
        );
    }
}
