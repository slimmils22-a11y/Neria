<?php
/**
 * Régression : les handlers BO `save_social`/`save_design`/`save_typography`/
 * `save_custom_vars` (`neria.php`) ignoraient la valeur de retour de
 * `ConfigManager::saveSocialConfig()`/`saveDesignConfig()`/
 * `saveTypographyConfig()`/`saveCustomVariables()` — chacune de ces
 * méthodes accumule un booléen `$success = $success && $this->set(...)`
 * en bouclant sur plusieurs champs, mais le retour final n'était jamais
 * vérifié par l'appelant. Un échec ponctuel de `Configuration::updateValue()`
 * en cours de boucle (ex. incident DB transitoire) laissait un groupe de
 * réglages PARTIELLEMENT sauvegardé (certains champs écrits, d'autres
 * non — la logique `&&` court-circuitait même les champs suivants dès
 * le premier échec), tout en affichant "Enregistré" au marchand sans
 * aucun signal du problème.
 *
 * Bug identifié le 02/09/2026 (round 288, audit "échec partiel des
 * handlers BO save_xxx sans retour arrière").
 *
 * Corrigé le 02/09/2026 (round 288) : les 4 handlers vérifient désormais
 * le booléen retourné et affichent `msg.config_save_partial_failed`
 * (nouvelle clé, 19 locales) au lieu de `msg.saved` en cas d'échec.
 *
 * Test structurel : simuler un vrai échec de Configuration::updateValue()
 * en plein milieu d'une boucle nécessiterait de faire échouer une
 * écriture SQL à un instant précis — non reproductible de façon fiable
 * sans mocker la couche DB (même choix que d'autres tests de cette
 * série, ex. test_527/test_538). Vérifie que chaque appel est bien
 * conditionné sur son retour, avec un message d'erreur dédié en cas
 * d'échec.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $handlers = [
        'save_social'      => "if ((new ConfigManager(\$this))->saveSocialConfig(\$socialData)) {",
        'save_typography'  => "if ((new ConfigManager(\$this))->saveTypographyConfig(\$typoData)) {",
        'save_custom_vars' => "if ((new ConfigManager(\$this))->saveCustomVariables(\$varsData)) {",
    ];

    foreach ($handlers as $action => $needle) {
        $pos = strpos($src, "Tools::getValue('neria_action') === '{$action}'");
        neria_assert($pos !== false, "handler {$action} introuvable — jeu de test invalide");

        $posNeedle = strpos($src, $needle, $pos);
        neria_assert(
            $posNeedle !== false && $posNeedle - $pos < 2000,
            "le handler {$action} ne vérifie plus la valeur de retour de ConfigManager avant d'afficher 'Enregistré' — régression du bug corrigé le 02/09/2026 (round 288) : un échec partiel de sauvegarde (Configuration::updateValue() en échec en cours de boucle) afficherait de nouveau 'Enregistré' au marchand sans aucun signal"
        );

        $body = substr($src, $posNeedle, 400);
        neria_assert(
            strpos($body, "AdminTranslator::t('msg.config_save_partial_failed')") !== false,
            "le handler {$action} n'affiche plus msg.config_save_partial_failed en cas d'échec — régression du bug corrigé le 02/09/2026 (round 288)"
        );
    }

    // save_design a une structure légèrement différente (imbriquée avec
    // le contrôle d'upload du logo) — vérifiée séparément.
    $posDesign = strpos($src, "Tools::getValue('neria_action') === 'save_design'");
    neria_assert($posDesign !== false, 'handler save_design introuvable — jeu de test invalide');
    $posSaved = strpos($src, '$designConfigSaved = $designMgr->saveDesignConfig($designData);', $posDesign);
    neria_assert(
        $posSaved !== false && $posSaved - $posDesign < 2000,
        "le handler save_design ne capture plus le retour de saveDesignConfig() — régression du bug corrigé le 02/09/2026 (round 288)"
    );
    $designBody = substr($src, $posSaved, 900);
    neria_assert(
        strpos($designBody, 'if ($designConfigSaved) {') !== false
            && strpos($designBody, "AdminTranslator::t('msg.config_save_partial_failed')") !== false,
        "le handler save_design ne conditionne plus 'Enregistré' sur \$designConfigSaved — régression du bug corrigé le 02/09/2026 (round 288)"
    );

    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(is_array($translations), 'admin_translations.json illisible ou invalide');
    $locales = ['fr','en','de','it','es','pt','br','ar','ja','ko','zh','tw','ru','tr','sv','no','da','nl','gb'];
    foreach ($locales as $l) {
        neria_assert(
            isset($translations['msg.config_save_partial_failed'][$l]) && $translations['msg.config_save_partial_failed'][$l] !== '',
            "la clé msg.config_save_partial_failed est absente ou vide pour la locale '{$l}' dans admin_translations.json"
        );
    }

    return [
        'pass'    => true,
        'message' => "Les handlers save_social/save_design/save_typography/save_custom_vars vérifient désormais le retour de ConfigManager et signalent un échec partiel au lieu d'afficher 'Enregistré' à tort — bug corrigé le 02/09/2026 (round 288)",
    ];
}
