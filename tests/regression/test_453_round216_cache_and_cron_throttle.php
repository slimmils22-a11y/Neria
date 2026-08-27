<?php
/**
 * Régression round 216 (26/08/2026) — 5 correctifs distincts :
 *
 * 1. TranslationEngine::loadBlock() : $use_cache=false — un email envoyé
 *    juste après une modification en BO pouvait servir l'ancien texte
 *    sous cache SQL périmé.
 * 2. TranslationEngine::getAvailableTemplates()/getAvailableLangs() :
 *    $use_cache=false — listes déroulantes BO potentiellement obsolètes
 *    après un import.
 * 3. TranslationHistoryManager::getHistoryForTemplate()/getById() :
 *    $use_cache=false — écran d'historique BO potentiellement en retard.
 * 4. NeriaTools::getDiagnosticReport() : $use_cache=false sur les 2
 *    lectures — rapport de diagnostic potentiellement trompeur
 *    ("table absente"/"0 ligne" pour une table réellement présente).
 * 5. controllers/front/cron.php : limitation de débit par IP (comme
 *    track.php, round 164) sur les tentatives à token invalide — sans
 *    elle, chaque tentative déclenchait une écriture DB réelle
 *    (Configuration::updateGlobalValue), vecteur d'épuisement DB/CPU sans
 *    authentification.
 *
 * Test structurel : vérifie la présence de chaque garde-fou dans le code
 * source.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $base = _PS_MODULE_DIR_ . 'neria/';

    $te = file_get_contents($base . 'src/TranslationEngine.php');
    neria_assert($te !== false, 'Impossible de lire src/TranslationEngine.php');
    neria_assert(
        strpos($te, "ORDER BY `is_custom` ASC\",\n            true,\n            false\n        );") !== false,
        "TranslationEngine::loadBlock() n'a plus \$use_cache=false — régression du bug corrigé le 26/08/2026 (round 216)"
    );
    neria_assert(
        strpos($te, "ORDER BY `template` ASC\",\n            true,\n            false\n        );") !== false,
        "TranslationEngine::getAvailableTemplates() n'a plus \$use_cache=false — régression du bug corrigé le 26/08/2026 (round 216)"
    );
    neria_assert(
        strpos($te, "ORDER BY `lang` ASC\",\n            true,\n            false\n        );") !== false,
        "TranslationEngine::getAvailableLangs() n'a plus \$use_cache=false — régression du bug corrigé le 26/08/2026 (round 216)"
    );

    $thm = file_get_contents($base . 'src/TranslationHistoryManager.php');
    neria_assert($thm !== false, 'Impossible de lire src/TranslationHistoryManager.php');
    neria_assert(
        strpos($thm, "\$limit\n        ), true, false);") !== false,
        "TranslationHistoryManager::getHistoryForTemplate() n'a plus \$use_cache=false — régression du bug corrigé le 26/08/2026 (round 216)"
    );
    neria_assert(
        strpos($thm, "\$idHistory\n        ), false);") !== false,
        "TranslationHistoryManager::getById() n'a plus \$use_cache=false — régression du bug corrigé le 26/08/2026 (round 216)"
    );

    $nt = file_get_contents($base . 'src/NeriaTools.php');
    neria_assert($nt !== false, 'Impossible de lire src/NeriaTools.php');
    neria_assert(
        substr_count($nt, "pSQL(\$fullTable) . \"'\",\n                false\n            );") >= 1,
        "NeriaTools::getDiagnosticReport() n'a plus \$use_cache=false sur sa vérification d'existence de table — régression du bug corrigé le 26/08/2026 (round 216)"
    );
    neria_assert(
        strpos($nt, "SELECT COUNT(*) FROM `{\$fullTable}`\",\n                    false\n                );") !== false,
        "NeriaTools::getDiagnosticReport() n'a plus \$use_cache=false sur son COUNT de lignes — régression du bug corrigé le 26/08/2026 (round 216)"
    );

    $cron = file_get_contents($base . 'controllers/front/cron.php');
    neria_assert($cron !== false, 'Impossible de lire controllers/front/cron.php');
    neria_assert(
        strpos($cron, "\$key = 'neria_cron_rl_' . md5(\$ip);") !== false,
        "controllers/front/cron.php ne limite plus le débit par IP sur les tentatives à token invalide — régression du bug corrigé le 26/08/2026 (round 216)"
    );

    return [
        'pass'    => true,
        'message' => 'Round 216 : $use_cache=false sur les 6 lectures TranslationEngine/TranslationHistoryManager/NeriaTools et limitation de débit cron.php tous présents',
    ];
}
