<?php
/**
 * Régression (P8c, 26/09/2026, constatée dans le vrai back-office en anglais) : dans l'aperçu multi-clients, la liste des
 * anomalies (« Balises <style> supprimées »...) et la description de chaque client de messagerie (« Moteur Word — ... ») étaient
 * écrites en français quelle que soit la langue du back-office.
 *
 * Corrigé : 25 clés multipreview.issue_* / multipreview.support_* en 19 langues.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    neria_test_module();
    $langs = ['fr','en','de','it','es','pt','br','ar','ja','ko','zh','tw','ru','tr','sv','no','da','nl','gb'];
    $dict = json_decode((string) file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    $keys = [];
    foreach (['style_tags', 'link_css', 'background_image', 'border_radius', 'shadows', 'flex', 'gap', 'position', 'media_queries', 'inline_styles'] as $k) {
        $keys[] = 'multipreview.issue_' . $k;
    }
    foreach (array_keys(MultiClientPreviewManager::CLIENTS) as $client) {
        $keys[] = 'multipreview.support_' . $client;
    }
    neria_assert(count($keys) === 25, 'Nombre de clés attendu : 25, obtenu ' . count($keys));
    foreach ($keys as $key) {
        foreach ($langs as $l) {
            neria_assert(!empty($dict[$key][$l]), "Clé absente : {$key}/{$l}");
        }
        neria_assert($dict[$key]['ja'] !== $dict[$key]['fr'], "{$key} non traduit en japonais");
    }
    $main = (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    foreach (['Balises <style> supprimées', 'Liens <link> CSS externes supprimés', 'Attributs style="" en ligne supprimés'] as $fr) {
        neria_assert(!str_contains($main, $fr), "Libellé français littéral « {$fr} » de nouveau dans neria.php");
    }
    $mp = (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/src/MultiClientPreviewManager.php');
    neria_assert(str_contains($mp, 'self::supportLabel($client, (string) $info[\'support\'])'), "Le bandeau du client n'utilise plus supportLabel()");

    return ['pass' => true, 'message' => "Anomalies et descriptions des clients de l'aperçu multi-clients traduites en 19 langues (25 clés) — corrigé le 26/09/2026"];
}
