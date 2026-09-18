<?php
/**
 * Contenu : le libellé 'auto.trigger_segments' (panneau Automatisations)
 * annonçait "5 segments (Champions, Loyaux, À risque…)" alors que les 5
 * segments réels de l'onglet Segments sont Ambassadeur/Fidèle/Tiède/
 * Dormant/Fantôme — deux vocabulaires contradictoires dans le même BO.
 * Repéré via la revue manuelle du bloc 4 (18/09/2026). Corrigé : le libellé
 * ne nomme plus de segments, dans les 19 langues.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $path = _PS_MODULE_DIR_ . 'neria/data/admin_translations.json';
    $data = json_decode((string) file_get_contents($path), true);
    neria_assert(is_array($data) && isset($data['auto.trigger_segments']), "clé auto.trigger_segments introuvable");

    $bad = [];
    foreach ($data['auto.trigger_segments'] as $lang => $value) {
        if (strpos((string) $value, '(') !== false || strpos((string) $value, '（') !== false || strpos((string) $value, '…') !== false) {
            $bad[] = "{$lang}: {$value}";
        }
    }
    neria_assert(count($data['auto.trigger_segments']) >= 19, "moins de 19 langues pour auto.trigger_segments");
    neria_assert(empty($bad), "auto.trigger_segments réénumère des segments : " . implode(' | ', $bad));

    return ['pass' => true, 'message' => "auto.trigger_segments ne nomme plus de segments fantômes dans les 19 langues — bloc 4 (18/09/2026)"];
}
