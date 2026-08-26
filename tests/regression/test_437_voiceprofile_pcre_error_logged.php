<?php
/**
 * Régression : VoiceProfileManager::textContainsWords() masquait TOUTE
 * erreur PCRE via @preg_match(), pas seulement le cas UTF-8 invalide déjà
 * corrigé au round 170 (pré-nettoyage mb_convert_encoding()).
 *
 * Bug réel identifié le 25/08/2026 (round 207) : une erreur
 * PREG_BACKTRACK_LIMIT_ERROR/PREG_RECURSION_LIMIT_ERROR (plausible sur un
 * texte volumineux — CGV, fiche produit longue) fait retourner false à
 * preg_match(), "false === 1" vaut silencieusement false ("mot non
 * trouvé"), et auditTranslations() incrémente quand même entries_scanned
 * — donnant l'illusion qu'une entrée a été vérifiée alors qu'aucun mot
 * banni n'a pu être réellement testé sur elle.
 *
 * Corrigé le 25/08/2026 (round 207) : toute erreur PCRE (preg_last_error()
 * !== PREG_NO_ERROR) est désormais journalisée via error_log() (méthode
 * statique, pas d'accès à WatchdogManager) plutôt que silencieusement
 * avalée.
 *
 * Test structurel (le régime PCRE2 actuel de PHP ne se laisse pas forcer
 * de façon fiable en erreur réelle via ini_set('pcre.backtrack_limit'/
 * 'pcre.recursion_limit') sur ce pattern précis — vérifié empiriquement,
 * pas de fixture fiable disponible) + comportemental sur le cas nominal
 * (found/not-found), pour garantir que le correctif n'a pas cassé le
 * comportement de base.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/VoiceProfileManager.php';

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/VoiceProfileManager.php');
    neria_assert($src !== false, 'Impossible de lire src/VoiceProfileManager.php');

    $posMethod = strpos($src, 'public static function textContainsWords(');
    neria_assert($posMethod !== false, 'textContainsWords() introuvable — jeu de test invalide');

    neria_assert(
        strpos($src, '$matchResult = @preg_match($pattern, $plainText);', $posMethod) !== false,
        "textContainsWords() ne capture plus le résultat de preg_match() dans une variable — jeu de test à adapter"
    );
    neria_assert(
        strpos($src, 'elseif ($matchResult === false && preg_last_error() !== PREG_NO_ERROR) {', $posMethod) !== false,
        "VoiceProfileManager::textContainsWords() ne journalise plus les erreurs PCRE non liées à l'UTF-8 — régression du bug corrigé le 25/08/2026 (round 207) : un audit de traduction échouerait de nouveau silencieusement sur un texte volumineux, sans aucune trace"
    );
    neria_assert(
        strpos($src, "error_log('[Neria] VoiceProfileManager::textContainsWords()", $posMethod) !== false,
        "VoiceProfileManager::textContainsWords() n'utilise plus error_log() pour signaler l'erreur PCRE"
    );

    // Comportement nominal préservé (mot trouvé / mot absent) — le
    // correctif ne doit rien changer au cas normal.
    $found = VoiceProfileManager::textContainsWords(
        'Ce texte contient le mot cliquez-ici en plein milieu.',
        ['cliquez-ici', 'absent-du-texte']
    );
    neria_assert(
        in_array('cliquez-ici', $found, true),
        "textContainsWords() ne détecte plus un mot réellement présent — comportement de base cassé par ce correctif"
    );
    neria_assert(
        !in_array('absent-du-texte', $found, true),
        "textContainsWords() détecte à tort un mot absent du texte — comportement de base cassé par ce correctif"
    );

    return [
        'pass'    => true,
        'message' => "VoiceProfileManager::textContainsWords() journalise bien toute erreur PCRE (pas seulement l'UTF-8 invalide), sans régression sur le cas nominal — bug corrigé le 25/08/2026 (round 207)",
    ];
}
