<?php
/**
 * Bloc 4 (18/09/2026) : save_seasonal_campaign acceptait une date annuelle
 * impossible ('13-45', ou '25-12' saisi en JJ-MM comme dans la plupart des
 * pays) avec le message « Campagne créée », puis normalizeAnnualDate() la
 * remplaçait en silence par '01-01' : la campagne partait à TOUS les clients
 * le 1er janvier. Trouvé en créant une vraie campagne sur ps-test.
 *
 * Corrigé : le contrôleur refuse la date (message msg.seasonal_invalid_date,
 * 19 langues) et n'enregistre rien.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $dir = _PS_MODULE_DIR_ . 'neria/';
    require_once $dir . 'src/SegmentManager.php';
    require_once $dir . 'src/SeasonalCampaignManager.php';

    foreach (['12-25', '02-29', '01-01', '06-28'] as $ok) {
        neria_assert(SeasonalCampaignManager::isValidAnnualDate($ok), "'{$ok}' est une date valide (2000 est bissextile)");
    }
    foreach (['25-12', '13-45', '04-31', '02-30', '1-5', '', 'abcd', '12/25'] as $bad) {
        neria_assert(!SeasonalCampaignManager::isValidAnnualDate($bad), "'{$bad}' doit être refusée");
    }

    $src = (string) file_get_contents($dir . 'neria.php');
    neria_assert(
        strpos($src, "SeasonalCampaignManager::isValidAnnualDate((string) \$data['annual_date'])") !== false
        && strpos($src, "AdminTranslator::t('msg.seasonal_invalid_date')") !== false,
        "neria.php : save_seasonal_campaign ne valide plus la date avant enregistrement"
    );

    $data = json_decode((string) file_get_contents($dir . 'data/admin_translations.json'), true);
    $tr   = $data['msg.seasonal_invalid_date'] ?? [];
    neria_assert(count($tr) >= 19, "msg.seasonal_invalid_date incomplète (" . count($tr) . " langues)");
    foreach ($tr as $lang => $v) {
        neria_assert(trim((string) $v) !== '', "msg.seasonal_invalid_date vide pour '{$lang}'");
    }

    return ['pass' => true, 'message' => "une date de campagne saisonnière impossible est refusée avec un message traduit (19 langues), plus remplacée en silence par le 1er janvier — bloc 4 (18/09/2026)"];
}
