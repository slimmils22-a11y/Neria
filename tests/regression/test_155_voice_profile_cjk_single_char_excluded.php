<?php
/**
 * Régression : VoiceProfileManager::textContainsWords() ne doit plus
 * matcher un mot CJK d'un seul caractère par simple sous-chaîne — trop de
 * faux positifs (un idéogramme isolé matche n'importe quel mot de
 * plusieurs caractères qui le contient).
 *
 * Bug réel corrigé le 08/08/2026 (round 135) : pour les langues sans
 * séparateur entre mots (chinois/japonais/coréen), la détection basculait
 * sur mb_stripos() sans aucune notion de longueur minimale — bannir un
 * idéogramme courant (ex. « 日 », jour) déclenchait une alerte sur
 * pratiquement tout texte japonais contenant ce caractère dans un mot sans
 * rapport (« 明日 » demain, « 日本 » Japon), rendant la fonctionnalité
 * inutilisable dès qu'un mot CJK d'un seul caractère était banni.
 *
 * Test comportemental réel : vérifie qu'un mot CJK d'un seul caractère
 * n'est plus détecté (même s'il apparaît littéralement dans le texte),
 * alors qu'un mot CJK de 2+ caractères continue d'être détecté
 * normalement par sous-chaîne.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/VoiceProfileManager.php';

    // Mot d'un seul caractère japonais (日 = "jour/soleil") — présent dans
    // « 明日 » (demain) sans rapport sémantique avec le mot banni lui-même.
    $foundSingleChar = VoiceProfileManager::textContainsWords('明日はいい天気です', ['日']);
    neria_assert(
        $foundSingleChar === [],
        "textContainsWords() détecte encore un mot CJK d'un seul caractère par sous-chaîne — régression du bug corrigé le 08/08/2026 (round 135) : les faux positifs massifs sur CJK réapparaîtraient"
    );

    // Mot de 2 caractères — doit rester détecté normalement (pas de
    // sur-correction qui désactiverait totalement la détection CJK).
    $foundTwoChar = VoiceProfileManager::textContainsWords('この商品は最高です', ['最高']);
    neria_assert(
        $foundTwoChar === ['最高'],
        "textContainsWords() ne détecte plus un mot CJK de 2+ caractères — régression : la correction du round 135 aurait dû se limiter aux mots d'un seul caractère"
    );

    return [
        'pass'    => true,
        'message' => "VoiceProfileManager::textContainsWords() ignore bien les mots CJK d'un seul caractère (trop de faux positifs par sous-chaîne), tout en continuant à détecter normalement les mots/expressions CJK de 2+ caractères",
    ];
}
