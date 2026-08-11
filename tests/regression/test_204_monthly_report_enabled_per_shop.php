<?php
/**
 * Régression : MonthlyReportManager::checkAndSend() doit revérifier
 * CONFIG_ENABLED PAR BOUTIQUE, dans la boucle multi-boutique — pas via un
 * "fast exit" global avant la boucle.
 *
 * Bug réel corrigé le 09/08/2026 (round 145) : Configuration::get(self::
 * CONFIG_ENABLED) était lu AVANT la boucle sur les boutiques, sans idShop.
 * Il résolvait donc la valeur de la boutique du VISITEUR ayant déclenché le
 * hook, pas de chaque boutique itérée. Si cette boutique avait le rapport
 * désactivé, toute la boucle était court-circuitée avant même de
 * commencer — les autres boutiques (activées, rapport dû) n'atteignaient
 * jamais leur propre vérification, silencieusement.
 *
 * Test structurel assumé explicitement : Configuration::get()/updateValue()
 * du cœur PS ignorent silencieusement tout idShop explicite en
 * installation mono-boutique (cf. leçon rounds 141/142/144) — impossible de
 * distinguer par le comportement, dans CET environnement, un appel scopé
 * d'un appel non scopé. Vérifie donc que le check CONFIG_ENABLED est bien
 * positionné APRÈS le début de la boucle foreach ($shops as $idShop), avec
 * idShop explicite, pas avant.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/MonthlyReportManager.php');
    neria_assert($src !== false, 'Impossible de lire src/MonthlyReportManager.php');

    $posMethod = strpos($src, 'public function checkAndSend(): void');
    neria_assert($posMethod !== false, 'checkAndSend() introuvable — jeu de test invalide');

    $posLoop = strpos($src, 'foreach ($shops as $idShop) {', $posMethod);
    neria_assert($posLoop !== false, 'boucle multi-boutique introuvable — jeu de test invalide');

    $posEnabledCheck = strpos($src, "\Configuration::get(self::CONFIG_ENABLED, null, null, \$idShop)", $posMethod);
    neria_assert(
        $posEnabledCheck !== false,
        "checkAndSend() ne revérifie plus CONFIG_ENABLED avec idShop explicite — régression du bug corrigé le 09/08/2026 (round 145)"
    );
    neria_assert(
        $posEnabledCheck > $posLoop,
        "le check CONFIG_ENABLED n'est plus positionné APRÈS le début de la boucle multi-boutique — régression du bug corrigé le 09/08/2026 (round 145) : une boutique désactivée pourrait de nouveau court-circuiter la vérification de toutes les autres boutiques"
    );

    // Vérifie qu'aucun CODE (pas un commentaire) ne fait un `return` global
    // basé sur CONFIG_ENABLED avant la boucle.
    $preLoop = substr($src, $posMethod, $posLoop - $posMethod);
    neria_assert(
        strpos($preLoop, 'if (!(int) \Configuration::get(self::CONFIG_ENABLED)) {') === false,
        "un check global CONFIG_ENABLED (sans idShop) est réapparu avant la boucle — régression du bug corrigé le 09/08/2026 (round 145)"
    );

    return [
        'pass'    => true,
        'message' => "MonthlyReportManager::checkAndSend() revérifie bien CONFIG_ENABLED par boutique, dans la boucle, plutôt qu'un fast-exit global avant",
    ];
}
