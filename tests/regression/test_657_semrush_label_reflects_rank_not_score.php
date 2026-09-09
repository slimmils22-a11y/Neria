<?php
/**
 * Régression : le libellé "stats.seo_score_auto" affiché en BO à côté de
 * $sr.authority_score (SeoApiManager::fetchSemrush(), champ Semrush 'Rk')
 * disait "Score auto." — or 'Rk' est le RANG mondial du domaine dans
 * l'export Semrush 'domain_ranks' (plus petit = mieux classé, échelle non
 * bornée, potentiellement plusieurs millions), pas un score borné 0-100
 * comme le Domain Authority Moz affiché juste à côté dans le même onglet.
 * Le libellé induisait le marchand en erreur sur la nature et l'échelle
 * de la valeur affichée (ex. "2400000" sous "Score auto." sans aucune
 * indication que plus petit = mieux classé).
 *
 * Corrigé le 09/09/2026 (round 327) : libellé renommé en "Rang Semrush"
 * (et équivalents dans les 19 langues), sans changer la donnée elle-même
 * (aucun accès réseau disponible pour confirmer/implémenter le bon
 * endpoint Semrush "Authority Score" — hors scope de ce correctif, voir
 * mémoire projet).
 *
 * Test structurel : vérifie que le libellé ne contient plus "score" (fr)
 * et que la clé conserve ses 19 langues.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(
        isset($translations['stats.seo_score_auto']) && count($translations['stats.seo_score_auto']) === 19,
        "clé stats.seo_score_auto manquante ou incomplète (19 langues attendues)"
    );

    neria_assert(
        stripos($translations['stats.seo_score_auto']['fr'], 'score') === false,
        "le libellé français de stats.seo_score_auto contient encore 'score' — régression du bug corrigé le 09/09/2026 (round 327) : le champ Semrush 'Rk' (rang, pas score borné 0-100) redeviendrait affiché sous un libellé trompeur"
    );
    neria_assert(
        stripos($translations['stats.seo_score_auto']['fr'], 'rang') !== false,
        "le libellé français de stats.seo_score_auto ne mentionne plus 'rang' — régression du bug corrigé le 09/09/2026 (round 327)"
    );

    return [
        'pass'    => true,
        'message' => "Le libellé BO du champ Semrush 'Rk' reflète bien un rang ('Rang Semrush'), pas un score borné trompeur — bug corrigé le 09/09/2026 (round 327)",
    ];
}
