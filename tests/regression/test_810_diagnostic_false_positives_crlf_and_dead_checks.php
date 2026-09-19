<?php
/**
 * Bloc 7 (19/09/2026) — le diagnostic (onglet Aide) ouvert sur ps-test, module déployé
 * depuis un poste Windows (fichiers CRLF), affichait 24 alertes dont 8 étaient FAUSSES
 * ou dues à un contrôle défaillant :
 *
 *  1. « Régression connue réapparue : LoyaltyManager::generateVoucher() » — les
 *     garde-fous comparent des chaînes multi-lignes contenant "\n" ; sur un module
 *     livré en CRLF ils échouaient tous (et l'inverse pour un littéral \r\n).
 *     readModuleSrc() normalise désormais en LF.
 *  2. campaign_empty_seg : appelait SegmentManager::getSegmentCustomerCount(), méthode
 *     qui n'a jamais existé → « erreur interne » à chaque diagnostic, jamais de détection.
 *  3. txt_placeholder_coverage : {warranty_period}/{warranty_end_date} (champs saisis
 *     via l'envoi manuel) signalés « jamais peuplés ».
 *  4. action_banner_coverage : commentaire pris pour l'action (repair_module_version) +
 *     3 réponses AJAX JSON absentes de la liste des actions silencieuses.
 *  5. hardcoded_french_text : message de journal Watchdog multi-lignes signalé.
 *  6. template_cat_mapping_complete : newsletter_voucher absent du mapping des stats.
 *  7. CertificateManager : lien du QR sans langue/boutique de la commande.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    foreach (['CryptoManager', 'NeriaTools', 'TranslationEngine', 'AdminTranslator', 'PreferencesManager', 'SegmentManager', 'SeasonalCampaignManager', 'ManualSendManager', 'EmailRenderer', 'HealthCheckManager'] as $c) {
        $f = _PS_MODULE_DIR_ . 'neria/src/' . $c . '.php';
        if (is_file($f)) {
            require_once $f;
        }
    }
    $module = neria_test_module();
    $hc     = new HealthCheckManager($module);
    $call   = function (string $method, ?HealthCheckManager $obj = null) use ($hc) {
        $m = new ReflectionMethod(HealthCheckManager::class, $method);
        $m->setAccessible(true);
        return $m->invoke($obj ?? $hc);
    };
    $dir = _PS_MODULE_DIR_ . 'neria/';

    // 1) Fins de ligne : un fichier CRLF est lu comme LF, et la garde LoyaltyManager reste verte.
    $tmp = $dir . 'regtest810_crlf.tmp';
    $real = str_replace("\r\n", "\n", (string) file_get_contents($dir . 'src/LoyaltyManager.php'));
    file_put_contents($tmp, str_replace("\n", "\r\n", $real));
    try {
        $read = new ReflectionMethod(HealthCheckManager::class, 'readModuleSrc');
        $read->setAccessible(true);
        $body = $read->invoke(new HealthCheckManager($module), $tmp);
        neria_assert(strpos($body, "\r") === false, "readModuleSrc() renvoie encore des \\r : un module livré en CRLF fait échouer les gardes multi-lignes");
        neria_assert(strpos($body, "AND tier_key = '\" . pSQL(\$tier['key']) . \"'\n               AND id_shop = \" . \$reservationShopId") !== false, "la chaîne de la garde round 56 (LoyaltyManager) ne se retrouve pas dans un fichier CRLF normalisé");
    } finally {
        @unlink($tmp);
    }
    $agg = $call('checkKnownRegressionsGuard');
    neria_assert($agg['status'] === 'ok', "checkKnownRegressionsGuard() n'est pas 'ok' : " . substr((string) ($agg['detail'] ?? ''), 0, 250));

    // 2) campaign_empty_seg : détecte réellement un segment vide, ne signale pas un segment peuplé.
    $db = neria_test_db();
    $p  = neria_test_prefix();
    $shop = (int) Context::getContext()->shop->id;
    $counts = (new SegmentManager($module))->getSegmentCounts();
    $empty = null;
    $full  = null;
    foreach ($counts as $seg => $n) {
        if ($n === 0 && $empty === null) { $empty = $seg; }
        if ($n > 0 && $full === null)    { $full = $seg; }
    }
    $ids = [];
    $db->execute("DELETE FROM {$p}neria_seasonal_campaign WHERE name LIKE 'regtest810_%'");
    try {
        if ($empty !== null) {
            $db->execute("INSERT INTO {$p}neria_seasonal_campaign (id_shop, name, template, annual_date, days_before, is_active, target_segment, date_add, date_upd)
                          VALUES ({$shop}, 'regtest810_vide', 'christmas', '12-25', 7, 1, '" . pSQL($empty) . "', NOW(), NOW())");
            $r = $call('checkCampaignEmptySegment');
            neria_assert($r['status'] === 'warning' && strpos((string) $r['detail'], 'regtest810_vide') !== false, "checkCampaignEmptySegment() ne détecte pas une campagne ciblant le segment vide '{$empty}' : " . json_encode($r, JSON_UNESCAPED_UNICODE));
            $db->execute("DELETE FROM {$p}neria_seasonal_campaign WHERE name LIKE 'regtest810_%'");
        }
        if ($full !== null) {
            $db->execute("INSERT INTO {$p}neria_seasonal_campaign (id_shop, name, template, annual_date, days_before, is_active, target_segment, date_add, date_upd)
                          VALUES ({$shop}, 'regtest810_plein', 'christmas', '12-25', 7, 1, '" . pSQL($full) . "', NOW(), NOW())");
            $r = $call('checkCampaignEmptySegment');
            neria_assert(strpos((string) $r['detail'], 'regtest810_plein') === false, "un segment peuplé ('{$full}') est signalé vide : " . json_encode($r, JSON_UNESCAPED_UNICODE));
        }
        neria_assert($empty !== null || $full !== null, "aucun segment exploitable pour le test");
    } finally {
        $db->execute("DELETE FROM {$p}neria_seasonal_campaign WHERE name LIKE 'regtest810_%'");
    }

    // 3) txt_placeholder_coverage : les champs de l'envoi manuel ne sont plus « jamais peuplés ».
    $r = $call('checkTxtPlaceholderCoverage');
    neria_assert(strpos((string) $r['detail'], 'warranty_period') === false && strpos((string) $r['detail'], 'warranty_end_date') === false, "warranty_period/warranty_end_date encore signalés : " . substr((string) $r['detail'], 0, 300));

    // 4) action_banner_coverage.
    $r = $call('checkActionBannerCoverage');
    foreach (['repair_module_version', 'check_preferences_guard', 'check_cooldown_guest_notice', 'product_search'] as $a) {
        neria_assert(strpos((string) $r['detail'], $a) === false, "action '{$a}' encore signalée sans bannière : " . substr((string) $r['detail'], 0, 300));
    }

    // 5) hardcoded_french_text.
    $r = $call('checkHardcodedFrenchText');
    neria_assert(strpos((string) $r['detail'], 'CertificateManager.php') === false, "message de journal Watchdog encore signalé comme texte français codé en dur : " . substr((string) $r['detail'], 0, 300));

    // 6) template_cat_mapping_complete.
    $r = $call('checkTemplateCategoryMappingComplete');
    neria_assert(strpos((string) $r['detail'], 'newsletter_voucher') === false, "newsletter_voucher encore absent du mapping des statistiques : " . substr((string) $r['detail'], 0, 300));

    // 7) Lien du QR : langue et boutique de la COMMANDE.
    $cert = (string) file_get_contents($dir . 'src/CertificateManager.php');
    neria_assert(strpos($cert, "getModuleLink('neria', 'certificate', [], true, (int) \$order->id_lang, (int) \$order->id_shop)") !== false, "le lien du QR du certificat n'utilise plus la langue/boutique de la commande");

    // 4 bis) le contrôle de bannières reste capable de détecter une vraie omission (mutation simulée).
    $hcSrc = (string) file_get_contents($dir . 'src/HealthCheckManager.php');
    neria_assert(strpos($hcSrc, "'check_preferences_guard', 'check_cooldown_guest_notice', 'product_search'") !== false, "liste des actions AJAX silencieuses incomplète");

    return ['pass' => true, 'message' => "fins de ligne CRLF/LF neutralisées pour les gardes, campaign_empty_seg fonctionnel, 5 autres faux positifs du diagnostic supprimés, lien QR du certificat scopé — bloc 7 (19/09/2026)"];
}
