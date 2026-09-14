<?php
/**
 * Régression : SeasonalCampaignManager::create()/update() n'appliquaient
 * AUCUNE validation serveur sur `annual_date` — seul un pattern HTML côté
 * navigateur (seasonal.tpl) contrôlait le format, trivialement contournable
 * par un POST direct. Une valeur syntaxiquement plausible mais
 * calendairement impossible ('04-31', '02-30', '13-99') était acceptée
 * silencieusement : neria.php affichait un succès, mais runDueCampaigns()
 * compare date('m-d', $targetTs) (qui ne peut jamais produire une date
 * impossible) à cette valeur — la campagne ne se déclenchait alors plus
 * JAMAIS, sans log ni alerte pour le marchand qui la croit active.
 *
 * Bug identifié le 14/09/2026 (round 359, audit dédié SeasonalCampaignManager).
 *
 * Corrigé le 14/09/2026 : normalizeAnnualDate() valide le format MM-DD
 * ET le calendrier (checkdate() sur une année bissextile de référence pour
 * autoriser 02-29) avant écriture ; à défaut, repli sur '01-01' — jamais
 * d'échec silencieux, jamais de date invalide persistée.
 *
 * Test comportemental réel : create() avec une date invalide ('04-31')
 * doit persister '01-01' (repli), pas la valeur brute ; une date valide
 * ('12-25') doit être conservée telle quelle.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/SeasonalCampaignManager.php';

    $module = neria_test_module();
    $mgr    = new SeasonalCampaignManager($module);

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $table  = $prefix . 'neria_seasonal_campaign';

    $idInvalid = 0;
    $idValid   = 0;

    try {
        $idInvalid = $mgr->create([
            'name'        => 'RegTest763 invalide',
            'template'    => '',
            'annual_date' => '04-31',
        ]);
        neria_assert($idInvalid > 0, 'create() a échoué — jeu de test invalide');

        $storedInvalid = (string) $db->getValue("SELECT annual_date FROM `{$table}` WHERE id_campaign = {$idInvalid}");
        neria_assert(
            $storedInvalid === '01-01',
            "create() a persisté '{$storedInvalid}' pour une date calendairement impossible ('04-31') au lieu de replier sur '01-01' — régression du bug corrigé le 14/09/2026 (round 359) : cette campagne ne se déclencherait plus jamais, silencieusement"
        );

        $idValid = $mgr->create([
            'name'        => 'RegTest763 valide',
            'template'    => '',
            'annual_date' => '12-25',
        ]);
        neria_assert($idValid > 0, 'create() a échoué pour une date valide — jeu de test invalide');

        $storedValid = (string) $db->getValue("SELECT annual_date FROM `{$table}` WHERE id_campaign = {$idValid}");
        neria_assert(
            $storedValid === '12-25',
            "create() n'a pas conservé une date calendairement valide ('12-25'), stocké '{$storedValid}' — normalizeAnnualDate() rejette à tort une entrée légitime"
        );

        // update() doit appliquer la même normalisation.
        $mgr->update($idValid, [
            'name'        => 'RegTest763 valide',
            'template'    => '',
            'annual_date' => '02-30',
        ]);
        $storedAfterUpdate = (string) $db->getValue("SELECT annual_date FROM `{$table}` WHERE id_campaign = {$idValid}");
        neria_assert(
            $storedAfterUpdate === '01-01',
            "update() a persisté '{$storedAfterUpdate}' pour une date calendairement impossible ('02-30') au lieu de replier sur '01-01' — régression du bug corrigé le 14/09/2026 (round 359)"
        );

        return [
            'pass'    => true,
            'message' => "SeasonalCampaignManager::create()/update() valident désormais annual_date côté serveur (format + calendrier réel) — bug corrigé le 14/09/2026 (round 359)",
        ];
    } finally {
        if ($idInvalid > 0) {
            $mgr->delete($idInvalid);
        }
        if ($idValid > 0) {
            $mgr->delete($idValid);
        }
    }
}
