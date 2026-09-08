<?php
/**
 * Régression : VoiceProfileManager::sanitizeLang() ne faisait que retirer
 * les caractères non-alphabétiques, sans plafonner la longueur ni valider
 * le résultat contre la liste réelle des langues supportées
 * (TranslationEngine::SUPPORTED_LANGS). La colonne `lang` est un
 * VARCHAR(5) (sql/install.sql) — une valeur `trad_lang` manipulée en POST
 * (ex. 10+ lettres) passait intacte le filtre et provoquait soit un échec
 * INSERT silencieux (sql_mode strict, saveProfile() renvoie false — jamais
 * vérifié par neria.php avant ce round), soit une troncature à 5
 * caractères pouvant entrer en collision avec la clé UNIQUE
 * (id_shop, lang) d'une AUTRE langue légitime et l'écraser via
 * ON DUPLICATE KEY UPDATE (sql_mode non strict).
 *
 * Corrigé le 08/09/2026 (round 322) :
 * - VoiceProfileManager::sanitizeLang() valide désormais le résultat
 *   contre TranslationEngine::SUPPORTED_LANGS (retourne '' sinon).
 * - neria.php (action save_voice_profile) vérifie désormais le retour de
 *   saveProfile() et assigne neria_error (msg.voice_profile_save_failed)
 *   en cas d'échec, au lieu d'afficher un succès inconditionnel.
 *
 * Test comportemental réel : appelle saveProfile() avec une langue
 * manipulée hors liste ('frxxxxxxxx', 10+ lettres) et vérifie qu'elle est
 * bien rejetée (false, aucune écriture) — puis contre-épreuve avec une
 * langue valide ('fr') qui doit toujours fonctionner normalement.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/VoiceProfileManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationEngine.php';

    $module = neria_test_module();
    $mgr    = new VoiceProfileManager($module);

    // Langue manipulée hors liste, mais 100% alphabétique (passerait
    // l'ancien filtre preg_replace('/[^a-z]/i', '', ...) sans problème).
    $fakeLang = 'frxxxxxxxx';
    $result = $mgr->saveProfile($fakeLang, 'motbanni', 'motprefere', 'note de ton');
    neria_assert(
        $result === false,
        "VoiceProfileManager::saveProfile() accepte encore une langue hors liste ('{$fakeLang}') — régression du bug corrigé le 08/09/2026 (round 322) : la colonne VARCHAR(5) risquerait de nouveau une troncature/collision ou un échec INSERT silencieux"
    );

    // Contre-épreuve : une langue réellement supportée continue de
    // fonctionner normalement (pas de régression du chemin nominal).
    $savedProfile = $mgr->getProfile('fr');
    $prevBanned = $savedProfile['banned_words'] ?? '';
    $prevPreferred = $savedProfile['preferred_words'] ?? '';
    $prevTone = $savedProfile['tone_notes'] ?? '';

    try {
        $resultValid = $mgr->saveProfile('fr', 'motbanni_regtest634', 'motprefere_regtest634', 'note_regtest634');
        neria_assert(
            $resultValid === true,
            "VoiceProfileManager::saveProfile() rejette à tort une langue valide ('fr') — régression : le correctif round 322 bloquerait à tort le chemin nominal"
        );

        $reloaded = $mgr->getProfile('fr');
        neria_assert(
            $reloaded['banned_words'] === 'motbanni_regtest634',
            "saveProfile('fr', ...) n'a pas persisté correctement — jeu de test invalide"
        );
    } finally {
        // Restaure l'état précédent de la langue 'fr'.
        $mgr->saveProfile('fr', (string) $prevBanned, (string) $prevPreferred, (string) $prevTone);
    }

    return [
        'pass'    => true,
        'message' => "VoiceProfileManager::sanitizeLang() valide bien la langue contre TranslationEngine::SUPPORTED_LANGS, sans régression sur une langue valide — bug corrigé le 08/09/2026 (round 322)",
    ];
}
