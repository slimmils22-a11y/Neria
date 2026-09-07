<?php
/**
 * Régression : ABTestManager::getVariantBValue() lisait
 * neria_abtest_translation sans désactiver le cache SQL de PrestaShop
 * (paramètre $use_cache, true par défaut) — même famille de bug que les
 * rounds 210-213, déjà corrigée juste plus bas dans ce même fichier pour
 * copyVariantBToDefault() sur la même table. Cette méthode est appelée par
 * EmailRenderer à CHAQUE rendu d'email pour la variante B ; une correction
 * de texte via saveVariantBTranslations() pendant qu'un cron d'envoi tourne
 * pouvait rester invisible pour les emails restant à traiter dans le même
 * process.
 *
 * Corrigé le 07/09/2026 (round 315) : $use_cache=false.
 *
 * Vérification structurelle ciblée : confirme que le 2e argument `false`
 * est bien présent dans l'appel réel, à l'intérieur du corps de la
 * méthode.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ABTestManager.php');
    neria_assert($src !== false, 'Impossible de lire src/ABTestManager.php');

    $posFn = strpos($src, 'function getVariantBValue(');
    neria_assert($posFn !== false, 'getVariantBValue() introuvable — jeu de test invalide (signature déplacée ?)');

    $body = substr($src, $posFn, 1700);

    neria_assert(
        strpos($body, 'translation_key') !== false && strpos($body, 'pSQL($key)') !== false,
        "ABTestManager::getVariantBValue() a changé de forme inattendue — jeu de test invalide"
    );

    // Cible le code réel (juste après la fin de la requête SQL), pas un
    // strpos('false') en texte libre qui matcherait aussi le commentaire
    // explicatif ci-dessus ("$use_cache=false") — même piège d'auto-collision
    // déjà rencontré rounds 246/312.
    $posKey = strpos($body, 'pSQL($key)');
    neria_assert($posKey !== false, 'jeu de test invalide');
    $tail = substr($body, $posKey, 40);
    neria_assert(
        strpos($tail, 'false') !== false,
        "ABTestManager::getVariantBValue() n'appelle plus getValue() avec \$use_cache=false — régression du bug corrigé le 07/09/2026 (round 315)"
    );

    return [
        'pass'    => true,
        'message' => "ABTestManager::getVariantBValue() contourne bien le cache SQL PrestaShop (\$use_cache=false) — bug corrigé le 07/09/2026 (round 315)",
    ];
}
