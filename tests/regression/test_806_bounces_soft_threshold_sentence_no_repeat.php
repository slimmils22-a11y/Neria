<?php
/**
 * Bloc 6 (19/09/2026) : dans l'onglet Bounces, la règle du seuil de soft bounce
 * est assemblée à partir de trois fragments (bounces.auto_block_body_pre + le
 * nombre + bounces.failures_unit + bounces.auto_block_body_post) ; le début du
 * dernier fragment reprenait déjà le nom : « bloquée après 3 échecs échecs
 * consécutifs », « blocked after 3 failures consecutive failures », « nach 3
 * Fehlschläge aufeinanderfolgenden Fehlschlägen », « 连续 3 次失败 次失败后 »…
 * dans les 19 langues.
 *
 * Corrigé : le nom n'est plus injecté par le template (il fait partie du
 * fragment de fin) ; ja et ko, dont le fragment de fin ne portait pas le
 * compteur, sont ajustés.
 *
 * Test : la VRAIE ligne du template est simulée dans les 19 langues (clés
 * remplacées par leurs traductions, seuil = 3) ; aucune répétition de mot
 * adjacente ne doit apparaître.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $dir = _PS_MODULE_DIR_ . 'neria/';
    $tpl = (string) file_get_contents($dir . 'views/templates/admin/bounces.tpl');
    $tr  = json_decode((string) file_get_contents($dir . 'data/admin_translations.json'), true);

    neria_assert(preg_match('/^.*bounces\.auto_block_body_pre.*$/m', $tpl, $m) === 1, "ligne du seuil de soft bounce introuvable dans bounces.tpl");
    $line = $m[0];

    $langs = ['fr', 'en', 'de', 'it', 'es', 'pt', 'br', 'gb', 'ar', 'ja', 'ko', 'zh', 'tw', 'ru', 'tr', 'sv', 'no', 'da', 'nl'];
    foreach ($langs as $lang) {
        $text = preg_replace_callback(
            "/\{neria_admin key='([a-z0-9_.]+)'\}/",
            static function (array $k) use ($tr, $lang): string {
                return (string) ($tr[$k[1]][$lang] ?? '');
            },
            $line
        );
        $text = str_replace('{$bounce_soft_threshold}', '3', (string) $text);
        $text = trim(html_entity_decode(strip_tags(str_replace('<br>', ' ', $text)), ENT_QUOTES, 'UTF-8'));
        neria_assert($text !== '', "[{$lang}] phrase du seuil vide");
        neria_assert(strpos($text, '3') !== false, "[{$lang}] le seuil (3) n'apparaît pas dans la phrase");

        neria_assert(
            preg_match('/(?<![\p{L}])(\p{L}{3,})\s+\1(?![\p{L}])/iu', $text, $rep) !== 1,
            "[{$lang}] mot répété dans la phrase du seuil (« " . ($rep[0] ?? '') . " ») : " . mb_substr($text, 0, 160)
        );
        neria_assert(
            preg_match('/(\p{Han}{2,4})\s*\1/u', $text, $rep) !== 1,
            "[{$lang}] séquence répétée dans la phrase du seuil (« " . ($rep[0] ?? '') . " »)"
        );
    }

    return ['pass' => true, 'message' => "la phrase du seuil de soft bounce se lit correctement dans les 19 langues (plus de nom répété) — bloc 6 (19/09/2026)"];
}
