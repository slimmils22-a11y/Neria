<?php
/**
 * Régression (audit P9 du 25/09/2026) : NeriaTools::formatDate() écrivait les dates japonaises, coréennes et
 * chinoises avec un zéro devant le mois et le jour (« 2026年09月05日 », « 2026년 09월 05일 »), ce qui ne s'écrit pas
 * ainsi dans ces langues (« 2026年9月5日 ») — visible dans les e-mails d'anniversaire, de bon, de garantie…
 *
 * Corrigé : formats n/j (sans zéro) pour ja, ko, zh, tw.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    neria_test_module();
    $expected = [
        'ja' => '2026年9月5日', 'ko' => '2026년 9월 5일', 'zh' => '2026年9月5日', 'tw' => '2026年9月5日',
        'fr' => '05/09/2026', 'en' => '09/05/2026', 'de' => '05.09.2026',
    ];
    foreach ($expected as $lang => $text) {
        $got = NeriaTools::formatDate('2026-09-05', $lang);
        neria_assert($got === $text, "formatDate('2026-09-05', '{$lang}') = « {$got} » au lieu de « {$text} » — régression du correctif du 25/09/2026");
    }
    neria_assert(NeriaTools::formatDate('2026-12-13 08:07:00', 'ja', true) === '2026年12月13日 08:07', 'Date et heure japonaises incorrectes : ' . NeriaTools::formatDate('2026-12-13 08:07:00', 'ja', true));

    return [
        'pass'    => true,
        'message' => "Les dates japonaises, coréennes et chinoises s'écrivent sans zéro devant le mois et le jour (2026年9月5日), les autres langues sont inchangées — corrigé le 25/09/2026",
    ];
}
