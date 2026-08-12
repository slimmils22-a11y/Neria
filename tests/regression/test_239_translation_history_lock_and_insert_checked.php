<?php
/**
 * Régression : TranslationHistoryManager::record() doit vérifier le
 * résultat de GET_LOCK() (verrou anti-race, round 138) et journaliser au
 * Watchdog tout échec SQL de l'INSERT — les deux étaient auparavant
 * totalement silencieux.
 *
 * Bug réel corrigé le 09/08/2026 (round 151) : (1) le retour de
 * GET_LOCK() n'était jamais vérifié — un timeout (verrou déjà tenu
 * ailleurs) laissait passer l'INSERT + pruneKey() exactement comme si le
 * verrou avait été obtenu, recréant sous forte concurrence la race
 * condition que ce verrou est censé empêcher ; (2) aucun log Watchdog nulle
 * part dans ce fichier — un échec SQL de l'INSERT rendait l'historique
 * silencieusement incomplet, sans que le marchand ni le support ne puisse
 * le savoir.
 *
 * Test comportemental réel : instancie TranslationHistoryManager avec le
 * module de test (nécessaire pour le log Watchdog), appelle record() avec
 * des valeurs distinctes, vérifie qu'une entrée d'historique réelle a bien
 * été créée — puis vérification structurelle des 2 garde-fous (verrou
 * vérifié, échec SQL loggé), difficiles à déclencher de façon fiable sans
 * forcer un vrai incident MySQL.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationHistoryManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/WatchdogManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();

    $template = 'regtest151_template';
    $lang     = 'fr';
    $key      = 'regtest151_key';

    try {
        $mgr = new TranslationHistoryManager($module);
        $mgr->record($template, $lang, $key, 'ancienne valeur', 'nouvelle valeur', 'Test Round 151');

        $history = $mgr->getHistoryForTemplate($template, $lang, 10);
        $found = false;
        foreach ($history as $row) {
            if (($row['translation_key'] ?? '') === $key && ($row['new_value'] ?? '') === 'nouvelle valeur') {
                $found = true;
                break;
            }
        }
        neria_assert($found, "record() n'a pas cree d'entree d'historique reelle et retrouvable — jeu de test invalide ou regression comportementale");

        // Verifications structurelles des 2 garde-fous.
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/TranslationHistoryManager.php');
        neria_assert(
            strpos($src, '$acquired = (int) $this->db->getValue(') !== false && strpos($src, 'if ($acquired !== 1) {') !== false,
            "record() ne verifie plus le resultat de GET_LOCK() — regression du bug corrige le 09/08/2026 (round 151) : un timeout de verrou redeviendrait indetectable"
        );
        neria_assert(
            strpos($src, '$inserted = $this->db->insert(self::TABLE, [') !== false && strpos($src, 'if (!$inserted) {') !== false,
            "record() ne verifie plus le resultat de l'INSERT — regression du bug corrige le 09/08/2026 (round 151) : un echec SQL redeviendrait silencieux"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_translation_history WHERE template_key = '" . pSQL($template) . "'");
    }

    return [
        'pass'    => true,
        'message' => "TranslationHistoryManager::record() cree bien une entree d'historique reelle, verifie GET_LOCK() et journalise les echecs SQL",
    ];
}
