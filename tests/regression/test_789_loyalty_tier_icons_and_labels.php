<?php
/**
 * Bloc 5 (19/09/2026) : dans le BO, les paliers de fidélité s'affichaient
 * avec 🏍 (moto), 🏌 (golfeur), 🏋 (haltérophile) — références numériques
 * &#127949;/&#127948;/&#127947; au lieu des médailles 🥉🥈🥇
 * (&#129353;/&#129352;/&#129351;). De plus, « Palier maximum atteint »,
 * « Bons reçus : » (Historique client) et « Erreur de recherche. »
 * (Traductions) étaient codés en dur en français dans les 19 langues.
 *
 * Test : chaque référence numérique &#N; des vues fidélité est décodée et
 * doit être une médaille ou un trophée ; les clés de traduction existent
 * dans les 19 langues et sont utilisées par les templates.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $dir = _PS_MODULE_DIR_ . 'neria/views/templates/admin/';
    $cfg = (string) file_get_contents($dir . 'configure.tpl');
    $his = (string) file_get_contents($dir . '_customer_history_content.tpl');
    $trs = (string) file_get_contents($dir . 'translations.tpl');

    // Zones d'icônes de palier uniquement (le reste des vues contient d'autres
    // entités légitimes, ex. 🔒).
    $allowed = [0x1F949, 0x1F948, 0x1F947, 0x1F3C6]; // 🥉 🥈 🥇 🏆
    $zones = [];
    if (preg_match('#\$tier\.key === \'bronze\'.*?\{/if\}#s', $cfg, $z)) {
        $zones['configure.tpl (paliers)'] = $z[0];
    }
    if (preg_match('#\{if \$tk === \'gold\'\}&\#[0-9]+;.*?\{/if\}#s', $his, $z)) {
        $zones['_customer_history_content.tpl (paliers)'] = $z[0];
    }
    neria_assert(count($zones) === 2, "zones d'icônes de palier introuvables — test invalide");
    $found = 0;
    foreach ($zones as $name => $src) {
        if (preg_match_all('/&#([0-9]{5,6});/', $src, $m)) {
            foreach ($m[1] as $n) {
                $found++;
                neria_assert(in_array((int) $n, $allowed, true), "{$name} : référence &#{$n}; (" . html_entity_decode("&#{$n};", ENT_QUOTES, 'UTF-8') . ") n'est ni une médaille ni un trophée — icône de palier incorrecte");
            }
        }
    }
    neria_assert($found >= 6, "trop peu d'icônes de palier trouvées ({$found}) — test invalide");

    neria_assert(strpos($his, "neria_admin key='history.loyalty_max_tier'") !== false, "libellé palier maximum de nouveau en dur");
    neria_assert(strpos($his, "neria_admin key='history.loyalty_rewards_received'") !== false, "libellé bons reçus de nouveau en dur");
    neria_assert(strpos($trs, "neria_admin key='translations.search_error'") !== false, "message d'erreur de recherche de nouveau en dur");
    neria_assert(strpos($his, 'Palier maximum atteint') === false && strpos($his, 'Bons reçus') === false, "texte français en dur réapparu");

    $data = json_decode((string) file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    foreach (['history.loyalty_max_tier', 'history.loyalty_rewards_received', 'translations.search_error'] as $k) {
        $tr = $data[$k] ?? [];
        neria_assert(count($tr) >= 19, "{$k} incomplète (" . count($tr) . " langues)");
        foreach ($tr as $lang => $v) {
            neria_assert(trim((string) $v) !== '', "{$k} vide pour '{$lang}'");
        }
    }

    return ['pass' => true, 'message' => "icônes de palier = médailles, libellés Historique client/Traductions traduits en 19 langues — bloc 5 (19/09/2026)"];
}
