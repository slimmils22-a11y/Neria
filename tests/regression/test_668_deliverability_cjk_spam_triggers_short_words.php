<?php
/**
 * Régression : le filtre de longueur minimale `mb_strlen($trigger) >= 4`
 * (`getSubjectSpamTriggers()`, et les critères sujet/corps de `score()`),
 * ajouté pour éviter les faux positifs sur des fragments latins de 2-3
 * lettres (ex. "off" matchant dans un mot innocent), neutralisait
 * silencieusement la quasi-totalité du dictionnaire anti-spam CJK/coréen :
 * en chinois/japonais/coréen, les mots pleins font naturellement 2
 * caractères ('免费' = gratuit, '当選' = gagnant, '할인' = remise, etc.) — un
 * script qui ne s'écrit pas avec des espaces, où le problème de "fragment
 * de sous-chaîne dans un mot innocent" qui justifiait le seuil de 4 ne se
 * pose pas de la même façon. Un sujet d'email ENTIÈREMENT rédigé en
 * chinois/japonais/coréen ne déclenchait donc plus AUCUN des déclencheurs
 * pourtant explicitement traduits dans ces langues (19 langues ciblées par
 * le module) — la couverture CJK était en réalité inopérante.
 *
 * Bug identifié le 10/09/2026 (round 331, audit DeliverabilityScorer/
 * DomainReputationManager).
 *
 * Corrigé le 10/09/2026 (round 331) : nouvelle méthode
 * `triggerMeetsMinLength()` — seuil abaissé à 2 caractères pour les
 * scripts CJK/Hiragana/Katakana/Hangul (détectés via une plage Unicode),
 * seuil de 4 conservé pour le reste (latin, cyrillique, arabe).
 *
 * Test comportemental réel : vérifie que getSubjectSpamTriggers() renvoie
 * désormais bien des déclencheurs CJK de 2 caractères, et que score()
 * détecte réellement un sujet 100% chinois contenant '折扣'+'限时' (remise +
 * temps limité) comme technical_issues incluant 'hidden_text' est un autre
 * sujet — ici on vérifie via le score/pénalité du critère sujet.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/DeliverabilityScorer.php';

    $scorer = new DeliverabilityScorer();

    // ── 1. getSubjectSpamTriggers() doit désormais inclure des mots CJK de 2 caractères ──
    $triggers = $scorer->getSubjectSpamTriggers();
    $hasCjkShort = false;
    foreach ($triggers as $t) {
        if (mb_strlen($t) === 2 && preg_match('/[\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7A3}]/u', $t)) {
            $hasCjkShort = true;
            break;
        }
    }
    neria_assert(
        $hasCjkShort,
        "getSubjectSpamTriggers() ne renvoie plus aucun déclencheur CJK de 2 caractères — régression du bug corrigé le 10/09/2026 (round 331) : le seuil mb_strlen>=4 neutraliserait de nouveau la quasi-totalité du dictionnaire chinois/japonais/coréen"
    );

    // Contre-épreuve : un fragment latin de 2-3 lettres doit rester exclu
    // (le garde-fou original round X reste actif pour les scripts latins).
    $hasShortLatin = false;
    foreach ($triggers as $t) {
        if (mb_strlen($t) < 4 && preg_match('/^[a-zA-Z]+$/', $t)) {
            $hasShortLatin = true;
            break;
        }
    }
    neria_assert(
        !$hasShortLatin,
        "getSubjectSpamTriggers() réintroduit un fragment latin < 4 caractères — régression du garde-fou anti-faux-positif d'origine"
    );

    // ── 2. score() détecte réellement un sujet 100% CJK contenant des déclencheurs courts ──
    $htmlClean = '<html><body><p>Contenu neutre sans rapport avec le sujet.</p></body></html>';
    $resultCjk = $scorer->score($htmlClean, '折扣促销限时');
    $foundHiddenTrigger = false;
    foreach ($resultCjk['criteria'] as $c) {
        if (($c['penalty'] ?? 0) < 0 && strpos($c['detail'] ?? '', '折扣') !== false) {
            $foundHiddenTrigger = true;
        }
    }
    // Recherche plus robuste : le score global doit être pénalisé par
    // rapport à un sujet neutre équivalent (preuve indirecte que le
    // déclencheur a bien été détecté).
    $resultNeutral = $scorer->score($htmlClean, '订单更新通知');
    neria_assert(
        $resultCjk['score'] < $resultNeutral['score'],
        "score() ne pénalise plus un sujet 100% chinois contenant des déclencheurs spam courts ('折扣'/'促销'/'限时') par rapport à un sujet neutre équivalent — régression du bug corrigé le 10/09/2026 (round 331) : score CJK={$resultCjk['score']}, score neutre={$resultNeutral['score']}"
    );

    return [
        'pass'    => true,
        'message' => "DeliverabilityScorer détecte désormais les déclencheurs spam CJK/coréen de 2 caractères ('折扣', '当選', '할인'...), auparavant neutralisés par le seuil mb_strlen>=4 conçu pour les scripts latins — bug corrigé le 10/09/2026 (round 331)",
    ];
}
