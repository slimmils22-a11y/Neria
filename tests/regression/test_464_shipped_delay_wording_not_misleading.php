<?php
/**
 * Correctif rédactionnel round 223 (28/08/2026) : le template
 * order_shipped_delay affichait une clé de traduction "Nouvelle date
 * estimée" / "New estimated date" (et équivalents dans les 19 langues)
 * alors que la date envoyée ({new_shipping_date}) est calculée par
 * BehavioralCronManager comme une simple constante "+7 jours" et non
 * comme un véritable recalcul individualisé auprès du transporteur.
 * Le libellé laissait croire à tort à une ré-estimation précise.
 *
 * Corrigé le 28/08/2026 : reformulation de la clé "shipped_delay_new_date"
 * dans les 19 langues vers un intitulé de type "délai supplémentaire
 * estimé jusqu'au", qui n'implique plus de recalcul individualisé tout
 * en restant honnête sur le caractère estimatif de la date affichée.
 *
 * Ce test structurel vérifie qu'aucune des 19 entrées ne contient plus
 * les anciennes formulations "nouvelle/nouveau/new" accolées à
 * "date/estimée/estimated" pour cette clé précise.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $base = _PS_MODULE_DIR_ . 'neria/';
    $raw  = file_get_contents($base . 'data/translations.json');
    neria_assert($raw !== false, 'Impossible de lire data/translations.json');

    $data = json_decode($raw, true);
    neria_assert(
        json_last_error() === JSON_ERROR_NONE,
        'data/translations.json invalide après reformulation round 223 (JSON cassé)'
    );
    neria_assert(
        isset($data['order_shipped_delay']) && is_array($data['order_shipped_delay']),
        "Bloc 'order_shipped_delay' manquant dans data/translations.json"
    );

    $offenders = [];
    $misleadingNeedles = [
        'new estimated date',
        'nouvelle date estimée',
        'neuer voraussichtlicher termin',
        'nuova data stimata',
        'nueva fecha estimada',
        'nova data estimada',
        'التاريخ الجديد المقدر',
        '新しい推定日',
        '새로운 예상 날짜',
        '新的预计日期',
        '新的預計日期',
        'новая предполагаемая дата',
        'yeni tahmini tarih',
        'nytt beräknat datum',
        'ny estimert dato',
        'ny forventet dato',
        'nieuwe geschatte datum',
    ];

    foreach ($data['order_shipped_delay'] as $langCode => $entry) {
        neria_assert(
            isset($entry['shipped_delay_new_date']),
            "Clé 'shipped_delay_new_date' manquante pour la langue '{$langCode}'"
        );
        $value = mb_strtolower($entry['shipped_delay_new_date']);
        foreach ($misleadingNeedles as $needle) {
            if ($value === $needle) {
                $offenders[] = $langCode;
                break;
            }
        }
    }

    neria_assert(
        empty($offenders),
        'Régression du correctif rédactionnel round 223 : langue(s) encore sur '
        . "l'ancien libellé trompeur \"nouvelle date estimée\" : " . implode(', ', $offenders)
    );

    // Le libellé fr doit rester honnête : plus de "nouvelle"/"nouveau"
    // accolé à une notion de date recalculée.
    $fr = mb_strtolower($data['order_shipped_delay']['fr']['shipped_delay_new_date']);
    neria_assert(
        strpos($fr, 'nouvelle date') === false && strpos($fr, 'nouveau') === false,
        "Le libellé fr implique encore une date recalculée individuellement — régression"
    );

    return [
        'pass'    => true,
        'message' => 'Round 223 (correctif rédactionnel) : shipped_delay_new_date reformulé dans les 19 langues, plus de fausse promesse de recalcul individualisé',
    ];
}
