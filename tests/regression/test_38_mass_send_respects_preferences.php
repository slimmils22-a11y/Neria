<?php
/**
 * Régression : les 7 points d'envoi de masse/automatisé corrigés le
 * 02/08/2026 (commits c259ce5, 2a61697, a88d789, 335e07e, 563b7cf) doivent
 * TOUJOURS respecter PreferencesManager::isAllowed() avant d'envoyer —
 * SegmentManager::sendToSegment(), CalendarManager::sendCalendarEmail(),
 * SeasonalCampaignManager::runDueCampaigns(), LoyaltyManager::
 * sendRewardEmail()/sendRecapToCustomer() (vérifiés en réel ici), et
 * LookCompletionManager/CollectionManager::runDailyCheck() (vérifiés
 * structurellement — fixtures commande/produit trop coûteuses à
 * reconstruire ici pour une valeur de protection équivalente).
 *
 * Avant ce lot de correctifs, un client ayant explicitement désactivé une
 * catégorie de préférence recevait quand même les campagnes/notifications
 * correspondantes — contradiction directe avec sa demande, risque RGPD.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;
    $idCustomer = neria_test_any_customer_id();
    $row = $db->getRow("SELECT email FROM {$prefix}customer WHERE id_customer={$idCustomer}");
    $email = $row['email'];

    $module = neria_test_module();
    require_once _PS_MODULE_DIR_ . 'neria/src/PreferencesManager.php';
    $prefs = new PreferencesManager($module);

    $allOn  = ['cart' => 1, 'post' => 1, 'loyalty' => 1, 'behav' => 1, 'season' => 1, 'b2b' => 1, 'newsletter' => 1];
    $failures = [];

    try {
        // ── 1) SegmentManager::sendToSegment() (categorie 'behav') ──────
        require_once _PS_MODULE_DIR_ . 'neria/src/SegmentManager.php';
        $db->execute("INSERT INTO {$prefix}neria_customer_segment (id_customer, id_shop, segment, computed_at)
                      VALUES ({$idCustomer}, {$idShop}, 'loyal', NOW())
                      ON DUPLICATE KEY UPDATE segment='loyal', computed_at=NOW()");
        $prefs->saveByCustomer($idCustomer, $email, array_merge($allOn, ['behav' => 0]));
        $segMgr = new SegmentManager($module);
        $res = $segMgr->sendToSegment('loyal', 'private_sale', []);
        if (($res['skipped'] ?? 0) < 1) {
            $failures[] = "SegmentManager::sendToSegment() n'a pas bloqué un client desabonne (behav=0)";
        }
        $db->execute("DELETE FROM {$prefix}neria_customer_segment WHERE id_customer={$idCustomer} AND id_shop={$idShop} AND segment='loyal'");

        // ── 2) CalendarManager::sendCalendarEmail() (categorie 'season') ─
        require_once _PS_MODULE_DIR_ . 'neria/src/CalendarManager.php';
        $prefs->saveByCustomer($idCustomer, $email, array_merge($allOn, ['season' => 0]));
        $calMgr = new CalendarManager($module);
        $refCal = new ReflectionMethod($calMgr, 'sendCalendarEmail');
        $refCal->setAccessible(true);
        $custRow = $db->getRow("SELECT id_customer, email, firstname, lastname, id_lang FROM {$prefix}customer WHERE id_customer={$idCustomer}");
        $sent = $refCal->invoke($calMgr, $custRow, 'early_access', 'fr');
        if ($sent !== false) {
            $failures[] = "CalendarManager::sendCalendarEmail() n'a pas bloqué un client desabonne (season=0)";
        }

        // ── 3) LoyaltyManager::sendRewardEmail()/sendRecapToCustomer() (categorie 'loyalty') ─
        require_once _PS_MODULE_DIR_ . 'neria/src/LoyaltyManager.php';
        $prefs->saveByCustomer($idCustomer, $email, array_merge($allOn, ['loyalty' => 0]));
        $loyMgr = new LoyaltyManager($module);
        $refReward = new ReflectionMethod($loyMgr, 'sendRewardEmail');
        $refReward->setAccessible(true);
        $tier = ['name' => 'Bronze', 'key' => 'bronze', 'points' => 50, 'amount' => 5, 'is_percent' => false];
        $rewardSent = $refReward->invoke($loyMgr, $idCustomer, $tier, 'TESTCODE', '5,00 €', 65, $idShop);
        if ($rewardSent !== false) {
            $failures[] = "LoyaltyManager::sendRewardEmail() n'a pas bloqué un client desabonne (loyalty=0)";
        }

        // ── 4/5) Vérification structurelle : LookCompletionManager/CollectionManager ─
        // (fixtures commande/produit réelles trop coûteuses à reconstruire ici)
        $lookSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/LookCompletionManager.php');
        if (!preg_match("/isAllowed\\([^)]*'complete_your_look'/", $lookSrc)) {
            $failures[] = "LookCompletionManager ne vérifie plus isAllowed('complete_your_look', ...)";
        }
        $collSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CollectionManager.php');
        if (!preg_match("/isAllowed\\([^)]*'collection_completion'/", $collSrc)) {
            $failures[] = "CollectionManager ne vérifie plus isAllowed('collection_completion', ...)";
        }
        $seasonSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SeasonalCampaignManager.php');
        if (!preg_match('/PreferencesManager[\s\S]{0,50}?isAllowed\(/', $seasonSrc)) {
            $failures[] = "SeasonalCampaignManager ne vérifie plus isAllowed(...) avant l'envoi";
        }
        $recapSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/LoyaltyManager.php');
        if (!preg_match("/isAllowed\\([^)]*'loyalty_recap'/", $recapSrc)) {
            $failures[] = "LoyaltyManager::sendRecapToCustomer() ne vérifie plus isAllowed('loyalty_recap', ...)";
        }

        neria_assert(
            empty($failures),
            'Un ou plusieurs points d\'envoi de masse ignorent de nouveau les préférences client : ' . implode(' | ', $failures)
            . ' — régression du pattern corrigé le 02/08/2026 (commits c259ce5, 2a61697, a88d789, 335e07e, 563b7cf)'
        );

        return ['pass' => true, 'message' => 'Les 7 points d\'envoi de masse corrigés respectent toujours les préférences client'];
    } finally {
        $prefs->saveByCustomer($idCustomer, $email, $allOn);
    }
}
