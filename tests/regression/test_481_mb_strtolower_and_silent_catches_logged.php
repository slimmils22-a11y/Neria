<?php
/**
 * Régression round 246 (30/08/2026) — 3 correctifs indépendants du même
 * round, regroupés dans un seul test structurel (les 3 scénarios réels sont
 * difficiles à déclencher de façon fiable sans fragiliser la suite : le
 * "problème du I turc" ne se reproduit pas sur ce PHP Windows de
 * développement — strtolower() sur Windows n'implémente pas la table de
 * conversion locale-sensible de glibc/Linux, contrairement à la cible de
 * production (hébergement O2switch, Linux) — et forcer une VRAIE exception
 * interne dans LoyaltyManager::awardPoints()/WaitlistManager::notifyProduct()
 * nécessiterait de casser délibérément l'état DB, risque de collatéral sur
 * les tests suivants. Test structurel assumé explicitement, même schéma que
 * test_199/test_78).
 *
 * 1. AdminTranslator::setLang()/currentLang() (x2) et
 *    TranslationEngine::normalizeLang() utilisaient strtolower() (pas
 *    mb_strtolower()) sur des codes langue — strtolower() est sensible à
 *    setlocale(LC_CTYPE, ...) : sous une locale turque positionnée par un
 *    AUTRE module/le serveur dans le même process PHP-FPM, strtolower('IT')
 *    peut retourner 'ıt' (i sans point) au lieu de 'it', cassant
 *    silencieusement in_array(..., true) contre SUPPORTED_LANGS.
 *    Corrigé : mb_strtolower() (jamais sensible à la locale) aux 4 sites.
 *
 * 2. StatsManager::logEvent() avalait silencieusement (catch \Throwable vide,
 *    sans AUCUNE journalisation) tout échec de LoyaltyManager::awardPoints()
 *    survenant AVANT checkAndReward() (qui, lui, journalise déjà ses propres
 *    échecs) — un client pouvait perdre des points de fidélité sans
 *    qu'aucune trace n'existe nulle part.
 *    Corrigé : $this->watchdog()->warning(...) dans le catch.
 *
 * 3. HealthCheckManager::checkWaitlistBacklog() (auto-réparation) avalait de
 *    même silencieusement tout échec de WaitlistManager::notifyProduct()
 *    pour un produit du backlog — le rapport final affichait juste "X
 *    notifiés sur Y produits" sans indiquer LEQUEL avait échoué.
 *    Corrigé : $this->watchdog->warning(...) dans le catch, avec id_product.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    // ── Partie A : mb_strtolower() aux 4 sites (AdminTranslator x3,
    //    TranslationEngine x1) ──
    $atSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/AdminTranslator.php');
    neria_assert($atSrc !== false, 'Impossible de lire AdminTranslator.php');
    $atSrc = str_replace("\r", '', $atSrc);
    neria_assert(
        substr_count($atSrc, 'mb_strtolower(') >= 3,
        "AdminTranslator.php ne compte plus au moins 3 occurrences de mb_strtolower() — régression du bug corrigé le 30/08/2026 (round 246)"
    );
    neria_assert(
        strpos($atSrc, 'strtolower(trim($lang))') === false
            && strpos($atSrc, "strtolower((string) \\Tools::getValue('neria_bo_lang'))") === false
            && strpos($atSrc, 'strtolower((string) $context->language->iso_code)') === false,
        "AdminTranslator.php contient de nouveau un strtolower() brut (sans mb_) sur un code langue — régression du bug corrigé le 30/08/2026 (round 246)"
    );

    $teSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/TranslationEngine.php');
    neria_assert($teSrc !== false, 'Impossible de lire TranslationEngine.php');
    $teSrc = str_replace("\r", '', $teSrc);
    $posNorm = strpos($teSrc, 'private function normalizeLang(string $lang): string');
    neria_assert($posNorm !== false, 'normalizeLang() introuvable — jeu de test invalide');
    $normBody = substr($teSrc, $posNorm, 900);
    neria_assert(
        strpos($normBody, 'mb_strtolower(') !== false,
        "TranslationEngine::normalizeLang() n'utilise plus mb_strtolower() — régression du bug corrigé le 30/08/2026 (round 246)"
    );

    // ── Partie B : StatsManager journalise l'échec d'attribution de points ──
    $smSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/StatsManager.php');
    neria_assert($smSrc !== false, 'Impossible de lire StatsManager.php');
    $smSrc = str_replace("\r", '', $smSrc);
    $posAward = strpos($smSrc, 'awardPoints($idCustomer, $idStat, $event)');
    neria_assert($posAward !== false, "l'appel à awardPoints() est introuvable — jeu de test invalide");
    $awardCatchBody = substr($smSrc, $posAward, 900);
    neria_assert(
        strpos($awardCatchBody, "'watchdog.stats_award_points_failed'") !== false
            && strpos($awardCatchBody, '$this->watchdog()->warning(') !== false,
        "StatsManager n'journalise plus l'échec d'attribution des points de fidélité dans son catch — régression du bug corrigé le 30/08/2026 (round 246) : un client pourrait de nouveau perdre des points sans aucune trace"
    );

    // ── Partie C : HealthCheckManager journalise l'échec de notification
    //    waitlist dans l'auto-réparation ──
    $hcmSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php');
    neria_assert($hcmSrc !== false, 'Impossible de lire HealthCheckManager.php');
    $hcmSrc = str_replace("\r", '', $hcmSrc);
    $posWl = strpos($hcmSrc, 'private function checkWaitlistBacklog(): array');
    neria_assert($posWl !== false, 'checkWaitlistBacklog() introuvable — jeu de test invalide');
    $wlBody = substr($hcmSrc, $posWl, 5200);
    neria_assert(
        strpos($wlBody, "'watchdog.waitlist_autofix_product_failed'") !== false
            && strpos($wlBody, '$this->watchdog->warning(') !== false,
        "checkWaitlistBacklog() ne journalise plus l'échec de notification d'un produit — régression du bug corrigé le 30/08/2026 (round 246) : un client en liste d'attente pourrait de nouveau ne jamais être notifié, sans aucune trace"
    );

    return [
        'pass'    => true,
        'message' => "Les 3 correctifs du round 246 sont bien présents : mb_strtolower() aux 4 sites de résolution de langue, journalisation Watchdog de l'échec d'attribution de points fidélité (StatsManager), journalisation Watchdog de l'échec de notification waitlist (HealthCheckManager)",
    ];
}
