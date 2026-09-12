<?php
/**
 * Régression : ConfigManager::uploadLogo() doit vérifier le retour réel de
 * set() (NERIA_LOGO_PATH) avant d'annoncer un succès.
 *
 * Bug identifié le 12/09/2026 (round 345, audit ConfigManager/
 * NeriaErrorHandler) : le fichier était bien déplacé sur disque
 * (move_uploaded_file() réussi) mais le retour de set() (reflet de
 * Configuration::updateValue()) n'était jamais vérifié — uploadLogo()
 * renvoyait toujours $relativePath (donc "succès") même si l'écriture en
 * configuration échouait (verrou DB transitoire). Le marchand voyait "logo
 * mis à jour" en BO alors que NERIA_LOGO_PATH n'avait pas changé — les
 * emails continuaient d'afficher l'ancien logo, sans aucune trace.
 *
 * Test structurel (move_uploaded_file() exige un VRAI contexte d'upload
 * HTTP — is_uploaded_file() échoue systématiquement en CLI, rendant
 * l'appel complet à uploadLogo() avec un fichier simulé impraticable, même
 * limite que pour les autres méthodes de ce fichier basées sur $_FILES) +
 * comportemental réel sur le mécanisme sous-jacent que le correctif
 * utilise (ConfigManager::set()/get() pour NERIA_LOGO_PATH — round-trip
 * nominal, garantit que le correctif s'appuie sur un mécanisme sain).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    // ── Vérification structurelle du correctif ────────────────────────
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ConfigManager.php');
    neria_assert($src !== false, 'Impossible de lire src/ConfigManager.php');

    $posFn = strpos($src, 'public function uploadLogo(array $file)');
    neria_assert($posFn !== false, 'uploadLogo() introuvable — jeu de test invalide');

    $body = substr($src, $posFn, 3200);
    neria_assert(
        strpos($body, "if (!\$this->set(self::KEY_LOGO_PATH, \$relativePath)) {") !== false,
        "uploadLogo() ne vérifie plus le retour de set(self::KEY_LOGO_PATH, ...) — régression du bug corrigé le 12/09/2026 (round 345) : un échec réel de l'écriture en configuration afficherait de nouveau un succès trompeur"
    );
    neria_assert(
        strpos($body, "\\WatchdogManager::i18nMsg('watchdog.logo_upload_config_write_failed'") !== false,
        "uploadLogo() ne journalise plus d'alerte Watchdog en cas d'échec de set() — régression du bug corrigé le 12/09/2026 (round 345)"
    );

    // ── Vérification comportementale du mécanisme sous-jacent ─────────
    $module = neria_test_module();
    $mgr    = new ConfigManager($module);

    $original = $mgr->get(ConfigManager::KEY_LOGO_PATH);

    try {
        $testPath = 'data/signatures/round345_test_' . uniqid() . '.png';
        $ok = $mgr->set(ConfigManager::KEY_LOGO_PATH, $testPath);
        neria_assert($ok === true, "ConfigManager::set() a échoué de façon inattendue sur le chemin nominal — comportement nominal cassé");

        $reread = $mgr->get(ConfigManager::KEY_LOGO_PATH);
        neria_assert(
            $reread === $testPath,
            "ConfigManager::get() ne relit pas la valeur venant d'être écrite par set() (attendu '{$testPath}', obtenu '" . var_export($reread, true) . "') — le mécanisme write→read sous-jacent au correctif serait cassé"
        );

        return [
            'pass'    => true,
            'message' => "ConfigManager::uploadLogo() vérifie désormais le retour réel de set() avant d'annoncer un succès (garde-fou structurel confirmé), et le mécanisme set()/get() sous-jacent fonctionne correctement (garde-fou comportemental confirmé)",
        ];
    } finally {
        if ($original !== null && $original !== '') {
            $mgr->set(ConfigManager::KEY_LOGO_PATH, $original);
        } else {
            Configuration::deleteByName(ConfigManager::KEY_LOGO_PATH);
        }
    }
}
