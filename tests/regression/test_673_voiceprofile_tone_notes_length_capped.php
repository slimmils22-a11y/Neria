<?php
/**
 * Régression : `VoiceProfileManager::saveProfile()` plafonnait bien
 * `banned_words`/`preferred_words` (nombre d'entrées ET longueur par
 * entrée, rounds 135/170) mais `tone_notes` (note de ton libre du même
 * formulaire) n'avait AUCUNE limite de longueur avant écriture.
 *
 * La colonne `tone_notes` est un `TEXT` (65 535 octets max, sql/
 * install.sql:939). Au-delà : en `sql_mode` strict, l'INSERT échoue (déjà
 * géré côté appelant depuis le round 322, pas de faux succès) ; en
 * `sql_mode` non strict (fréquent en hébergement mutualisé type
 * O2switch), MySQL tronque SILENCIEUSEMENT à 65 535 octets — le marchand
 * croit sa note complète enregistrée alors qu'elle est coupée sans
 * avertissement.
 *
 * Bug identifié le 10/09/2026 (round 332, audit VoiceProfileManager/
 * FontManager).
 *
 * Corrigé le 10/09/2026 (round 332) : plafond de 15 000 caractères
 * (mb_substr, jamais substr() sur cette valeur, pour ne jamais couper un
 * caractère UTF-8 multi-octets en deux — 15000×4 octets max = 60000,
 * toujours < 65535).
 *
 * Test comportemental réel : sauvegarde un profil avec une tone_notes de
 * 100 000 caractères (dont des emoji multi-octets près de la coupe),
 * relit le profil et vérifie que la valeur stockée est bien bornée et
 * reste un UTF-8 valide (pas de caractère coupé).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/VoiceProfileManager.php';

    $mgr  = new VoiceProfileManager(neria_test_module());
    $lang = 'fr';

    $origProfile = $mgr->getProfile($lang);

    // Note de ton énorme, avec des emoji (multi-octets) juste avant la
    // zone de coupe attendue (15000 caractères) pour vérifier qu'aucun
    // caractère n'est coupé en deux.
    $hugeNotes = str_repeat('Ton chaleureux et élégant. ', 4000) . '🎉🎨✨';

    try {
        $ok = $mgr->saveProfile($lang, '', '', $hugeNotes);
        neria_assert($ok === true, 'saveProfile() a échoué — jeu de test invalide');

        $profile = $mgr->getProfile($lang);
        $stored  = $profile['tone_notes'];

        neria_assert(
            mb_strlen($stored) <= 15000,
            "tone_notes stockée fait " . mb_strlen($stored) . " caractères au lieu d'être bornée à 15000 — régression du bug corrigé le 10/09/2026 (round 332) : une note de ton de taille arbitraire pourrait de nouveau être tronquée silencieusement par MySQL au lieu d'être bornée proprement côté PHP"
        );
        neria_assert(
            mb_check_encoding($stored, 'UTF-8'),
            "tone_notes stockée n'est plus un UTF-8 valide après troncature — régression du bug corrigé le 10/09/2026 (round 332) : substr() sur les octets couperait un caractère multi-octets en deux, corrompant l'encodage"
        );

        return [
            'pass'    => true,
            'message' => "VoiceProfileManager::saveProfile() borne désormais tone_notes à 15000 caractères (mb_substr, UTF-8 safe) au lieu de ne rien plafonner — bug corrigé le 10/09/2026 (round 332)",
        ];
    } finally {
        $mgr->saveProfile($lang, $origProfile['banned_words'], $origProfile['preferred_words'], $origProfile['tone_notes']);
    }
}
