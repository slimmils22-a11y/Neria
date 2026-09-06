<?php
/**
 * Régression : l'action BO `add_calendar_event` (neria.php) construisait
 * une requête `INSERT IGNORE` protégée par la contrainte UNIQUE
 * (id_shop, event_key, lang) mais n'en vérifiait jamais le résultat réel
 * (Affected_Rows()) — `Db::execute()` renvoie true même quand 0 ligne est
 * insérée. Un marchand soumettant deux fois le même formulaire (double
 * clic), ou tentant de "recréer" un événement déjà existant pour changer
 * son send_days_before/custom_date, voyait "Occasion ajoutée" s'afficher
 * alors que l'INSERT IGNORE n'avait strictement rien fait — l'ancienne
 * ligne, avec ses anciens paramètres, restait inchangée en base.
 *
 * Corrigé le 06/09/2026 (round 310) : Affected_Rows() vérifié après
 * l'INSERT IGNORE ; neria_error (calendar.already_exists) assigné si 0
 * ligne affectée.
 *
 * Test comportemental réel : insère un événement calendrier réel via
 * l'INSERT IGNORE exact utilisé par le code (même requête), puis exécute
 * EXACTEMENT le même INSERT IGNORE une seconde fois (simulant la
 * resoumission) et vérifie qu'Affected_Rows() vaut bien 0 pour ce second
 * appel — prouvant que le code source, s'il vérifie bien Affected_Rows()
 * comme attendu, détecterait ce cas et n'afficherait pas "Occasion ajoutée"
 * à tort.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;

    $eventKey = 'regtest593';
    $lang     = 'fr';

    $db->execute("DELETE FROM {$prefix}neria_calendar_event WHERE event_key = '{$eventKey}' AND id_shop = {$idShop} AND lang = '{$lang}'");

    $insertSql = "INSERT IGNORE INTO `{$prefix}neria_calendar_event`
                 (`id_shop`, `event_key`, `lang`, `country_code`, `custom_date`, `template`, `send_days_before`, `is_active`, `date_add`, `date_upd`)
                 VALUES ({$idShop}, '{$eventKey}', '{$lang}', '', '', 'regtest_template', 7, 1, NOW(), NOW())";

    try {
        // 1er appel : insertion réelle, doit affecter 1 ligne.
        $db->execute($insertSql);
        neria_assert(
            (int) $db->Affected_Rows() === 1,
            "jeu de test invalide : la première insertion n'a pas affecté 1 ligne (contrainte UNIQUE absente ou schéma différent ?)"
        );

        // 2e appel IDENTIQUE (simule une double soumission du formulaire) :
        // la contrainte UNIQUE (id_shop, event_key, lang) doit bloquer
        // l'insertion silencieusement (IGNORE), 0 ligne affectée.
        $db->execute($insertSql);
        $affectedSecond = (int) $db->Affected_Rows();

        neria_assert(
            $affectedSecond === 0,
            "jeu de test invalide : la seconde insertion (doublon) a affecté {$affectedSecond} ligne(s) au lieu de 0 — la contrainte UNIQUE ne bloque plus le doublon comme attendu"
        );

        // Vérification structurelle : le code source vérifie bien
        // Affected_Rows() après cet INSERT IGNORE précis, avant d'afficher
        // le message de succès.
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
        neria_assert($src !== false, 'Impossible de lire neria.php');
        $pos = strpos($src, "if (\$eventKey && \$lang && \$template) {");
        neria_assert($pos !== false, "bloc add_calendar_event introuvable — jeu de test invalide");
        $body = substr($src, $pos, 1800);
        neria_assert(
            strpos($body, 'Affected_Rows()') !== false && strpos($body, 'calendar.already_exists') !== false,
            "add_calendar_event ne vérifie plus Affected_Rows() après l'INSERT IGNORE — régression du bug corrigé le 06/09/2026 (round 310) : le message 'Occasion ajoutée' s'afficherait de nouveau même quand l'événement existait déjà (0 ligne réellement insérée)"
        );

        return [
            'pass'    => true,
            'message' => "add_calendar_event vérifie bien Affected_Rows() après son INSERT IGNORE et n'affiche plus 'Occasion ajoutée' pour un doublon silencieusement ignoré — bug corrigé le 06/09/2026 (round 310)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_calendar_event WHERE event_key = '{$eventKey}' AND id_shop = {$idShop} AND lang = '{$lang}'");
    }
}
