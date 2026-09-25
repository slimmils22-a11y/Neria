<?php
/**
 * Régression (audit P9 du 25/09/2026 sur data/translations.json — 118 modèles × 19 langues, 21 755 chaînes) :
 * invariants de qualité des textes d'e-mails, pour qu'une future correction de traduction ne les casse pas.
 *   1. mêmes clés et aucune chaîne vide dans les 19 langues, par rapport au français ;
 *   2. mêmes variables {…} que le français (hors salutations dear_customer, qui varient légitimement selon la
 *      langue : honorifiques japonais/coréen, formules nominatives) ;
 *   3. formule d'appel française jamais « Madame, Monsieur {nom} » (13 modèles, dont birthday, envoyaient
 *      « Madame, Monsieur Durand, » — agrammatical) ;
 *   4. aucune adresse informelle (tutoiement) en fr/de/es/it/pt/ru/tr/nl (règle de la marque : vouvoiement
 *      partout ; le suédois/norvégien/danois n'ont pas de vouvoiement d'usage et sont exclus) — trouvés le
 *      25/09 : it « il tuo indirizzo » (return_slip), tr « Sana » (fathers_day).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $langs = ['fr', 'en', 'de', 'it', 'es', 'pt', 'br', 'ar', 'ja', 'ko', 'zh', 'tw', 'ru', 'tr', 'sv', 'no', 'da', 'nl', 'gb'];
    $data = json_decode((string) file_get_contents(_PS_MODULE_DIR_ . 'neria/data/translations.json'), true);
    neria_assert(is_array($data) && count($data) > 100, 'translations.json illisible');

    $informal = [
        'fr' => '/\b(tu|toi|ton|ta|tes)\b/iu',
        'de' => '/\b(du|dir|dich|dein|deine|deiner|deinem|deinen)\b/iu',
        'ru' => '/\b(ты|тебя|тебе|тобой|твой|твоя|твоё|твои|твоих|твоей)\b/u',
        'es' => '/\b(tú|ti|contigo|tus|tuyo|tuya)\b/iu',
        'it' => '/\b(tu|tuo|tua|tuoi|tue)\b/iu',
        'tr' => '/\b(sen|senin|sana|seni|sende)\b/iu',
        'nl' => '/\b(jij|jouw|jou)\b/iu',
        'pt' => '/\b(tu|teu|tua|teus|tuas|contigo)\b/iu',
    ];
    $problems = [];
    foreach ($data as $template => $byLang) {
        $ref = $byLang['fr'] ?? [];
        if (count($byLang) !== 19) {
            $problems[] = "{$template} : " . count($byLang) . ' langues au lieu de 19';
            continue;
        }
        foreach ($langs as $lang) {
            $cur = $byLang[$lang] ?? [];
            if (array_diff_key($ref, $cur) !== []) {
                $problems[] = "{$template}.{$lang} : clés manquantes " . implode(',', array_slice(array_keys(array_diff_key($ref, $cur)), 0, 3));
            }
            foreach ($cur as $key => $value) {
                if (!is_string($value) || trim($value) === '') {
                    $problems[] = "{$template}.{$lang}.{$key} : vide";
                    continue;
                }
                if ($key !== 'dear_customer' && isset($ref[$key]) && is_string($ref[$key])) {
                    preg_match_all('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', $ref[$key], $a);
                    preg_match_all('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', $value, $b);
                    $diff = array_merge(array_diff($a[0], $b[0]), array_diff($b[0], $a[0]));
                    if ($diff) {
                        $problems[] = "{$template}.{$lang}.{$key} : variables différentes " . implode(',', array_unique($diff));
                    }
                }
                if (isset($informal[$lang])) {
                    $plain = (string) preg_replace('/\{[^}]*\}|<[^>]+>/u', '', $value);
                    if (preg_match($informal[$lang], $plain, $m) === 1) {
                        $problems[] = "{$template}.{$lang}.{$key} : adresse informelle « {$m[0]} »";
                    }
                }
            }
        }
        $fr = (string) ($byLang['fr']['dear_customer'] ?? '');
        if (preg_match('/^Madame, Monsieur\s*\{/u', $fr) === 1) {
            $problems[] = "{$template}.fr.dear_customer : « {$fr} » (formule agrammaticale)";
        }
    }
    // Exceptions connues et assumées : variable d'exemple / nom de variable de démonstration dans un texte d'aide
    $problems = array_values(array_filter($problems, static function (string $p): bool {
        return strpos($p, 'waitlist_available.') !== 0 || strpos($p, 'days_waited_plural') === false;
    }));
    neria_assert($problems === [], count($problems) . ' défaut(s) dans translations.json : ' . implode(' | ', array_slice($problems, 0, 8)));

    return [
        'pass'    => true,
        'message' => "translations.json : 118 modèles × 19 langues cohérents (clés, variables, aucune chaîne vide), formule d'appel française correcte, aucun tutoiement en fr/de/es/it/pt/ru/tr/nl — audit du 25/09/2026",
    ];
}
