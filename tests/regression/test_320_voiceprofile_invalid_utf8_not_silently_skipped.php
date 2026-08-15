<?php
/**
 * Régression : VoiceProfileManager::textContainsWords() utilise le
 * modificateur /u pour matcher correctement les scripts non-latins
 * (arabe, CJK, cyrillique). Mais preg_match() avec /u retourne `false`
 * (pas 0) si le texte contient de l'UTF-8 invalide (ligne issue d'un
 * import legacy ou d'une donnée corrompue) — le `@` masquait le warning
 * et `false === 1` valait silencieusement `false`, donc "mot non
 * trouvé" : la ligne ENTIÈRE échappait à l'audit (bannis ET préférés)
 * sans aucune trace, alors qu'entries_scanned continuait de s'incrémenter
 * côté appelant comme si elle avait bien été vérifiée — fausse confiance
 * du marchand dans la couverture réelle de l'audit.
 *
 * Corrigé le 15/08/2026 (round 170) : nettoyage préventif de l'UTF-8
 * invalide (round-trip mb_convert_encoding UTF-8→UTF-8, qui remplace les
 * séquences invalides) avant tout matching, plutôt que de laisser
 * l'échec du moteur PCRE avaler silencieusement toute la ligne.
 *
 * Test comportemental réel : construit un texte contenant un mot banni
 * valide entouré d'octets UTF-8 volontairement invalides, vérifie que le
 * mot banni est bien détecté malgré la corruption locale du texte.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/VoiceProfileManager.php';

    // Octet UTF-8 invalide isolé (0x80 est un octet de continuation sans
    // tête valide) inséré au milieu d'un texte par ailleurs valide,
    // contenant un mot banni clairement identifiable.
    $bannedWord = 'motinterditxyz';
    $text = "Bonjour, ceci contient \x80 le mot {$bannedWord} dans la phrase.";

    $found = VoiceProfileManager::textContainsWords($text, [$bannedWord]);

    neria_assert(
        in_array($bannedWord, $found, true),
        "textContainsWords() n'a pas détecté '{$bannedWord}' dans un texte contenant de l'UTF-8 invalide — régression du bug corrigé le 15/08/2026 (round 170) : une ligne avec de l'UTF-8 corrompu échapperait de nouveau silencieusement à tout l'audit (bannis ET préférés), sans que rien ne le signale au marchand"
    );

    return [
        'pass'    => true,
        'message' => "VoiceProfileManager::textContainsWords() détecte bien les mots bannis même dans un texte contenant de l'UTF-8 invalide — bug corrigé le 15/08/2026 (round 170)",
    ];
}
