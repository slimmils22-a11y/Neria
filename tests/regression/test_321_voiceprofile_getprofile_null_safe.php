<?php
/**
 * Régression : VoiceProfileManager::getProfile() castait directement
 * (string) $row['banned_words'] (idem preferred_words/tone_notes) sans
 * garde contre NULL. Les colonnes sont TEXT DEFAULT NULL en base ;
 * saveProfile() écrit toujours une chaîne non-null, mais une ligne
 * insérée/modifiée hors de ce chemin (édition directe en base, migration
 * future) laisserait ces colonnes NULL, et (string) null émet un warning
 * de dépréciation sous PHP 8.1+ (pollution des logs/error tracking).
 *
 * Corrigé le 15/08/2026 (round 170) : ?? '' ajouté avant chaque cast.
 *
 * Test comportemental réel : insère directement une ligne avec les 3
 * colonnes texte à NULL (contournant saveProfile(), comme le ferait une
 * édition manuelle en base), appelle getProfile() et vérifie qu'aucun
 * warning n'est émis et que le résultat contient bien des chaînes vides.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/VoiceProfileManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $lang   = 'zz'; // langue fictive — jamais utilisée en conditions réelles
    $idShop = (int) Context::getContext()->shop->id;

    $db->execute("DELETE FROM `{$prefix}neria_voice_profile` WHERE lang = '" . pSQL($lang) . "' AND id_shop = {$idShop}");

    try {
        $ok = $db->execute(
            "INSERT INTO `{$prefix}neria_voice_profile` (id_shop, lang, banned_words, preferred_words, tone_notes, date_upd)
             VALUES ({$idShop}, '" . pSQL($lang) . "', NULL, NULL, NULL, NOW())"
        );
        neria_assert($ok, "Impossible d'insérer une ligne de test avec colonnes NULL — jeu de test invalide : " . $db->getMsgError());

        $mgr = new VoiceProfileManager(neria_test_module());

        $errorTriggered = false;
        set_error_handler(function () use (&$errorTriggered) {
            $errorTriggered = true;
            return true;
        }, E_DEPRECATED | E_WARNING);

        $profile = $mgr->getProfile($lang);

        restore_error_handler();

        neria_assert(
            !$errorTriggered,
            "getProfile() a déclenché un warning/deprecation sur des colonnes NULL — régression du bug corrigé le 15/08/2026 (round 170) : le cast (string) direct sans ?? '' redeviendrait exposé à une ligne insérée hors de saveProfile()"
        );
        neria_assert(
            $profile['banned_words'] === '' && $profile['preferred_words'] === '' && $profile['tone_notes'] === '',
            "getProfile() n'a pas renvoyé des chaînes vides pour des colonnes NULL en base"
        );
    } finally {
        $db->execute("DELETE FROM `{$prefix}neria_voice_profile` WHERE lang = '" . pSQL($lang) . "' AND id_shop = {$idShop}");
    }

    return [
        'pass'    => true,
        'message' => "VoiceProfileManager::getProfile() gère bien des colonnes NULL sans warning (?? '' avant cast) — bug corrigé le 15/08/2026 (round 170)",
    ];
}
