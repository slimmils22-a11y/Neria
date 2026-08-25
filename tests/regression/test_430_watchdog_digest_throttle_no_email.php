<?php
/**
 * Régression : WatchdogManager::sendDailyDigestIfDueLocked() ne posait
 * jamais le throttle CFG_DIGEST_LAST quand aucune adresse d'alerte valide
 * n'était configurée (ni CFG_ALERT_EMAIL, ni PS_SHOP_EMAIL valide) ALORS
 * qu'il existait des logs warning/error/critical dans les 24h.
 *
 * Bug réel identifié le 24/08/2026 (round 203) : cette branche était
 * atteinte à CHAQUE appel de sendDailyDigestIfDue() (déclenché depuis
 * hookDisplayHeader, donc potentiellement à chaque hit front) tant que la
 * situation persistait — ré-exécutant les 2 requêtes SQL de comptage en
 * boucle, sans throttle, précisément pendant un incident actif où la base
 * est déjà sous tension.
 *
 * Corrigé le 24/08/2026 (round 203) : le throttle est désormais posé même
 * dans cette branche, comme dans le cas "rien à signaler" (empty($rows)).
 *
 * Test comportemental réel : active le digest, invalide toute adresse
 * d'alerte, seed un log warning récent, appelle sendDailyDigestIfDue() et
 * vérifie que CFG_DIGEST_LAST a bien été mis à jour malgré l'absence
 * d'envoi.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/WatchdogManager.php';

    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;
    $mgr = new WatchdogManager($module);

    $backupDigestEnabled = Configuration::getGlobalValue('NERIA_ALERT_DIGEST_ENABLED');
    $backupAlertEmail    = Configuration::getGlobalValue('NERIA_ALERT_EMAIL');
    $backupShopEmail     = Configuration::get('PS_SHOP_EMAIL', null, null, $idShop);
    $backupDigestLast    = Configuration::getGlobalValue('NERIA_DIGEST_LAST_SENT_' . $idShop);

    try {
        // Digest activé, mais AUCUNE adresse d'alerte valide.
        Configuration::updateGlobalValue('NERIA_ALERT_DIGEST_ENABLED', 1);
        Configuration::updateGlobalValue('NERIA_ALERT_EMAIL', '');
        Configuration::updateValue('PS_SHOP_EMAIL', 'not-an-email', false, null, $idShop);
        // Throttle "périmé" pour forcer l'entrée dans sendDailyDigestIfDueLocked().
        Configuration::updateGlobalValue('NERIA_DIGEST_LAST_SENT_' . $idShop, time() - 90000);

        // Seed un log warning récent pour ce shop, condition nécessaire pour
        // atteindre la branche $email === '' (sinon empty($rows) court-circuite
        // déjà correctement avant, ce n'est pas la branche qu'on teste ici).
        Db::getInstance()->insert('neria_log', [
            'id_shop'  => $idShop,
            'level'    => 'warning',
            'class'    => 'test_430',
            'template' => '',
            'message'  => 'Test round 203 — digest throttle sans adresse',
            'date_add' => date('Y-m-d H:i:s'),
        ]);

        $mgr->sendDailyDigestIfDue();

        $newDigestLast = (int) Configuration::getGlobalValue('NERIA_DIGEST_LAST_SENT_' . $idShop);
        neria_assert(
            $newDigestLast > (time() - 60),
            "WatchdogManager::sendDailyDigestIfDueLocked() ne pose plus le throttle CFG_DIGEST_LAST quand aucune adresse d'alerte n'est configurée — régression du bug corrigé le 24/08/2026 (round 203) : les requêtes SQL de comptage seraient de nouveau ré-exécutées à chaque hit front pendant un incident actif"
        );
    } finally {
        Db::getInstance()->delete('neria_log', "class = 'test_430'");
        if ($backupDigestEnabled === false) {
            Configuration::deleteByName('NERIA_ALERT_DIGEST_ENABLED');
        } else {
            Configuration::updateGlobalValue('NERIA_ALERT_DIGEST_ENABLED', $backupDigestEnabled);
        }
        if ($backupAlertEmail === false) {
            Configuration::deleteByName('NERIA_ALERT_EMAIL');
        } else {
            Configuration::updateGlobalValue('NERIA_ALERT_EMAIL', $backupAlertEmail);
        }
        Configuration::updateValue('PS_SHOP_EMAIL', $backupShopEmail, false, null, $idShop);
        if ($backupDigestLast === false) {
            Configuration::deleteByName('NERIA_DIGEST_LAST_SENT_' . $idShop);
        } else {
            Configuration::updateGlobalValue('NERIA_DIGEST_LAST_SENT_' . $idShop, $backupDigestLast);
        }
    }

    return [
        'pass'    => true,
        'message' => "WatchdogManager pose bien le throttle du digest quotidien même sans adresse d'alerte configurée, évitant des requêtes SQL répétées à chaque hit front — bug corrigé le 24/08/2026 (round 203)",
    ];
}
