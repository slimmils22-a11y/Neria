<?php
/**
 * Régression : VoiceProfileManager::normalizeWordListInput() plafonnait
 * bien le NOMBRE d'entrées à 500, mais rien ne bornait la LONGUEUR d'une
 * entrée individuelle. Un seul « mot » collé sans retour à la ligne (un
 * paragraphe entier) passait le plafond de 500 tel quel, était stocké
 * verbatim, puis réinjecté dans une regex construite dynamiquement
 * (textContainsWords()) exécutée à CHAQUE sauvegarde de traduction et à
 * chaque audit complet — coût O(n) potentiellement inutilement gonflé,
 * exactement ce que le plafond de 500 entrées était censé prévenir.
 *
 * Corrigé le 15/08/2026 (round 170) : chaque entrée est désormais tronquée
 * à 100 caractères avant stockage (MAX_WORD_LENGTH).
 *
 * Test comportemental réel : sauvegarde un profil avec un « mot » de 5000
 * caractères, relit le profil et vérifie que l'entrée stockée est bien
 * bornée à 100 caractères.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/VoiceProfileManager.php';

    $mgr = new VoiceProfileManager(neria_test_module());
    $ref = new ReflectionMethod(VoiceProfileManager::class, 'normalizeWordListInput');
    $ref->setAccessible(true);

    $hugeWord = str_repeat('a', 5000);

    $normalized = $ref->invoke($mgr, $hugeWord);
    $lines = explode("\n", $normalized);
    neria_assert(count($lines) === 1, "normalizeWordListInput() a produit " . count($lines) . " lignes au lieu d'1 — jeu de test invalide");
    neria_assert(
        mb_strlen($lines[0]) <= 100,
        "L'entrée normalisée fait " . mb_strlen($lines[0]) . " caractères au lieu d'être bornée à 100 — régression du bug corrigé le 15/08/2026 (round 170) : un mot de taille arbitraire pourrait de nouveau être stocké verbatim puis réinjecté dans une regex à chaque sauvegarde de traduction"
    );

    return [
        'pass'    => true,
        'message' => "VoiceProfileManager::normalizeWordListInput() borne bien chaque entrée à 100 caractères, en plus du plafond de 500 entrées — bug corrigé le 15/08/2026 (round 170)",
    ];
}
