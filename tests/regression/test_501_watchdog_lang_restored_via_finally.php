<?php
/**
 * Régression : WatchdogManager::sendImmediateAlert()/
 * sendDailyDigestIfDueLocked() basculaient temporairement
 * AdminTranslator::setLang() (propriété STATIQUE, partagée pour tout le
 * process PHP) SANS try/finally — une exception survenant entre le
 * basculement et la restauration (ex. échec SQL sur la requête de comptage
 * du digest, corruption de données passée à un helper de formatage)
 * laissait la langue bloquée sur celle DE LA BOUTIQUE pour le reste du
 * process/de la requête, affectant potentiellement tous les rendus Neria
 * suivants (BO comme front) — même famille de bug déjà corrigée pour
 * MonthlyReportManager/CertificateManager/ManualSendManager (round 239,
 * voir test_475), jamais portée à WatchdogManager alors qu'il est le point
 * d'échec le plus visité du module (wd()->error()/critical() appelés
 * depuis presque tous les autres Manager).
 *
 * Bug identifié le 31/08/2026 (round 261, audit "restauration d'état
 * temporaire sautée sur exception"). Corrigé le 31/08/2026 (round 261) :
 * les deux méthodes encadrent désormais leur bloc de construction (sujet +
 * corps HTML) par un try/finally garantissant AdminTranslator::setLang()
 * restauré même sur exception. Même correctif appliqué par cohérence à
 * ABTestManager (site secondaire, risque plus faible mais même pattern).
 *
 * Test comportemental réel : déclenche un VRAI envoi d'alerte immédiate
 * (Mailpit local), avec la langue de contexte positionnée sur une valeur
 * connue AVANT l'appel, et vérifie qu'elle est bien identique APRÈS —
 * complété par une vérification structurelle du try/finally dans le code
 * source pour les 2 méthodes (une exception RÉELLE au milieu du rendu
 * HTML/sujet n'est pas raisonnablement déclenchable sans mocker le SGBD ou
 * corrompre des données partagées avec d'autres tests, hors périmètre sûr
 * d'un test isolé — même contrainte que test_475).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/WatchdogManager.php';

    $module = neria_test_module();
    $wd     = new WatchdogManager($module);
    $idShop = (int) Context::getContext()->shop->id;

    $emailKey     = 'NERIA_ALERT_EMAIL';
    $immediateKey = 'NERIA_ALERT_IMMEDIATE_ENABLED';
    $lastSentKey  = 'NERIA_WD_ALERT_LAST_SENT_' . $idShop; // best-effort, valeur réelle relue ci-dessous si différente

    $origEmail     = (string) Configuration::getGlobalValue($emailKey);
    $origImmediate = Configuration::getGlobalValue($immediateKey);

    $refClass  = new ReflectionClass(WatchdogManager::class);
    $cfgConst  = $refClass->getConstant('CFG_ALERT_LAST_SENT');
    $lastSentRealKey = $cfgConst . '_' . $idShop;
    $origLastSent = Configuration::getGlobalValue($lastSentRealKey);

    $originalLang = AdminTranslator::currentLang();

    try {
        Configuration::updateGlobalValue($emailKey, 'regtest501@example.test');
        Configuration::updateGlobalValue($immediateKey, '1');
        Configuration::updateGlobalValue($lastSentRealKey, 0);

        $knownLang = 'fr';
        AdminTranslator::setLang($knownLang);

        $method = new ReflectionMethod(WatchdogManager::class, 'sendImmediateAlert');
        $method->setAccessible(true);
        $method->invoke($wd, WatchdogManager::LEVEL_ERROR, 'Message de test round 261', 'regtest_template', 'RegtestClass');

        neria_assert(
            AdminTranslator::currentLang() === $knownLang,
            "WatchdogManager::sendImmediateAlert() n'a pas restauré AdminTranslator::currentLang() ('" . AdminTranslator::currentLang() . "' au lieu de '{$knownLang}') après son appel — régression du bug corrigé le 31/08/2026 (round 261)"
        );

        // Vérification structurelle des try/finally (WatchdogManager x2 +
        // ABTestManager, ce dernier au risque plus faible mais même
        // pattern) — voir docblock pour la justification du choix de ne
        // pas forcer une exception réelle ici.
        $wdSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WatchdogManager.php');
        neria_assert($wdSrc !== false, "Impossible de lire src/WatchdogManager.php");

        $posImmediate = strpos($wdSrc, 'private function sendImmediateAlert(string $level, string $message, string $template, string $class): void');
        $posDigestLocked = strpos($wdSrc, 'private function sendDailyDigestIfDueLocked(): void');
        neria_assert($posImmediate !== false && $posDigestLocked !== false && $posDigestLocked > $posImmediate, "Structure de WatchdogManager inattendue — jeu de test invalide");

        $immediateBody = substr($wdSrc, $posImmediate, $posDigestLocked - $posImmediate);
        neria_assert(
            strpos($immediateBody, 'try {') !== false && strpos($immediateBody, '} finally {') !== false
                && strpos($immediateBody, 'AdminTranslator::setLang($prevLang);') !== false,
            "WatchdogManager::sendImmediateAlert() n'encadre plus son rendu par un try/finally restaurant AdminTranslator::setLang() — régression du bug corrigé le 31/08/2026 (round 261)"
        );

        $digestBody = substr($wdSrc, $posDigestLocked, 10500);
        neria_assert(
            strpos($digestBody, 'try {') !== false && strpos($digestBody, '} finally {') !== false
                && strpos($digestBody, 'AdminTranslator::setLang($prevLang);') !== false,
            "WatchdogManager::sendDailyDigestIfDueLocked() n'encadre plus son rendu par un try/finally restaurant AdminTranslator::setLang() — régression du bug corrigé le 31/08/2026 (round 261)"
        );

        $abtSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ABTestManager.php');
        neria_assert($abtSrc !== false, "Impossible de lire src/ABTestManager.php");
        neria_assert(
            strpos($abtSrc, "\\AdminTranslator::setLang(\\WatchdogManager::shopLang(\$this->idShop));\n        try {") !== false
                && strpos($abtSrc, "} finally {\n            \\AdminTranslator::setLang(\$prevLang);\n        }") !== false,
            "ABTestManager n'encadre plus son bloc de libellés Watchdog par un try/finally restaurant AdminTranslator::setLang() — régression du bug corrigé le 31/08/2026 (round 261)"
        );

        return [
            'pass'    => true,
            'message' => "WatchdogManager::sendImmediateAlert()/sendDailyDigestIfDueLocked() (et ABTestManager par cohérence) restaurent désormais AdminTranslator::setLang() via un try/finally garanti même sur exception — bug corrigé le 31/08/2026 (round 261)",
        ];
    } finally {
        AdminTranslator::setLang($originalLang);
        Configuration::updateGlobalValue($emailKey, $origEmail);
        if ($origImmediate === false || $origImmediate === null) {
            Configuration::deleteByName($immediateKey);
        } else {
            Configuration::updateGlobalValue($immediateKey, $origImmediate);
        }
        if ($origLastSent === false || $origLastSent === null) {
            Configuration::deleteByName($lastSentRealKey);
        } else {
            Configuration::updateGlobalValue($lastSentRealKey, $origLastSent);
        }
    }
}
