<?php
/**
 * Régression : AdminTranslator::tLang() n'effectuait aucune validation du
 * paramètre $iso contre TranslationEngine::SUPPORTED_LANGS avant de
 * l'utiliser comme clé de tableau — contrairement à setLang(), qui valide
 * bien via in_array(...SUPPORTED_LANGS). Écart entre le contrat documenté
 * (docblock : "$iso doit faire partie de SUPPORTED_LANGS") et
 * l'implémentation. Sans impact de sécurité (simple lookup de tableau),
 * mais une clé orpheline dans le dictionnaire JSON (langue retirée de
 * SUPPORTED_LANGS mais restée dans translations.json) aurait pu être
 * retournée silencieusement pour un code langue qui n'est plus
 * officiellement supporté.
 *
 * Corrigé le 15/08/2026 (round 177) : un $iso hors SUPPORTED_LANGS retombe
 * désormais explicitement sur FALLBACK_LANG avant toute tentative de
 * lookup.
 *
 * Test comportemental réel : appelle tLang() avec un code langue
 * manifestement invalide ('zz', jamais dans SUPPORTED_LANGS) et vérifie que
 * le résultat correspond bien au fallback (identique à un appel avec
 * FALLBACK_LANG), pas une éventuelle clé orpheline du dictionnaire.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/AdminTranslator.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationEngine.php';

    neria_assert(
        !in_array('zz', TranslationEngine::SUPPORTED_LANGS, true),
        "'zz' fait partie de SUPPORTED_LANGS — jeu de test invalide, choisir un autre code bidon"
    );

    // Clé réelle et stable du dictionnaire BO.
    $key = 'msg.module_description';

    $resultInvalid  = AdminTranslator::tLang($key, 'zz');
    $resultFallback = AdminTranslator::tLang($key, AdminTranslator::FALLBACK_LANG);

    neria_assert(
        $resultInvalid === $resultFallback,
        "AdminTranslator::tLang('{$key}', 'zz') renvoie '{$resultInvalid}' au lieu du texte de repli attendu ('{$resultFallback}') — régression du bug corrigé le 15/08/2026 (round 177) : un code langue non supporté ne retombe plus explicitement sur FALLBACK_LANG"
    );

    neria_assert(
        $resultInvalid !== '' && $resultInvalid !== $key,
        "AdminTranslator::tLang() avec un iso invalide renvoie une chaîne vide ou la clé brute ('{$resultInvalid}') — le repli ne fonctionne pas correctement"
    );

    return [
        'pass'    => true,
        'message' => "AdminTranslator::tLang() retombe bien sur FALLBACK_LANG pour un code langue non supporté — bug corrigé le 15/08/2026 (round 177)",
    ];
}
