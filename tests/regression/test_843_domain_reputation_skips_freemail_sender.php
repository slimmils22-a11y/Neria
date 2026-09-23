<?php
/**
 * Régression (constat réel du 23/09/2026, campagne P4) : avec une adresse expéditrice
 * gratuite (gmail.com…), DomainReputationManager auditait gmail.com lui-même : « score D »
 * (RBL en timeout) et ERREUR Watchdog trompeuse, sans rapport avec la boutique — les
 * enregistrements SPF/DKIM/DMARC de gmail.com appartiennent à Google, pas au marchand.
 *
 * Corrigé : un domaine de messagerie gratuite n'est plus audité ; le rapport porte
 * freemail=true, le Watchdog émet un AVERTISSEMENT explicite et le BO affiche une note.
 *
 * Test comportemental : expéditeur temporairement en gmail.com -> runFullCheck() rapide,
 * sans DNS, freemail=true, aucune erreur Watchdog domaine, contrôle santé en AVERTISSEMENT
 * qui cite le domaine ; expéditeur propre (domaine boutique) -> audit normal non freemail.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $p  = neria_test_prefix();
    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;

    foreach (['gmail.com', 'googlemail.com', 'yahoo.fr', 'hotmail.co.uk', 'outlook.com', 'orange.fr', 'GMX.de'] as $d) {
        neria_assert(DomainReputationManager::isFreemailDomain($d), "{$d} devrait être reconnu comme messagerie gratuite");
    }
    foreach (['example.com', 'ma-boutique.fr', 'gmail.maboutique.com', 'yahoo.mycompany.com', ''] as $d) {
        neria_assert(!DomainReputationManager::isFreemailDomain($d), "« {$d} » ne doit PAS être reconnu comme messagerie gratuite");
    }

    $origEmail   = Configuration::get('PS_SHOP_EMAIL', null, null, $idShop);
    $origSenders = Configuration::get('NERIA_SENDERS_JSON', null, null, $idShop);
    $origCache   = Configuration::get(DomainReputationManager::CONFIG_CACHE, null, null, $idShop);
    $origLast    = Configuration::get('NERIA_DOMAIN_REP_LAST_CHECK', null, null, $idShop);
    try {
        Configuration::updateValue('PS_SHOP_EMAIL', 'regtest843@gmail.com', false, null, $idShop);
        Configuration::updateValue('NERIA_SENDERS_JSON', '', false, null, $idShop);
        $before = (string) $db->getValue('SELECT NOW()');
        $t0 = microtime(true);
        $mgr = new DomainReputationManager($module);
        $report = $mgr->runFullCheck();
        $elapsed = microtime(true) - $t0;

        neria_assert(!empty($report['freemail']) && $report['domain'] === 'gmail.com', 'Le rapport doit porter freemail=true pour gmail.com');
        neria_assert($elapsed < 3.0, "Aucun audit DNS ne doit être lancé pour un domaine gratuit (durée " . round($elapsed, 1) . " s)");
        neria_assert(empty($report['blacklists']['hits']) && ($report['ip'] ?? null) === null, 'Aucune IP/liste noire ne doit être évaluée');

        $errors = (int) $db->getValue("SELECT COUNT(*) FROM {$p}neria_log WHERE level IN ('error','critical') AND date_add >= '" . pSQL($before) . "' AND message LIKE '%domain_reputation%'", false);
        neria_assert($errors === 0, "Une erreur Watchdog de réputation de domaine a été journalisée pour un expéditeur gratuit ({$errors}) — régression du bug corrigé le 23/09/2026 (round 369)");
        $warn = (int) $db->getValue("SELECT COUNT(*) FROM {$p}neria_log WHERE level='warning' AND date_add >= '" . pSQL($before) . "' AND message LIKE '%domain_reputation_freemail%'", false);
        neria_assert($warn >= 1, "L'avertissement watchdog.domain_reputation_freemail n'a pas été journalisé");

        $hc = new HealthCheckManager($module);
        $m = new ReflectionMethod(HealthCheckManager::class, 'checkDomainRepScore');
        $m->setAccessible(true);
        $res = $m->invoke($hc);
        neria_assert($res['status'] === HealthCheckManager::STATUS_WARNING, "checkDomainRepScore() doit être en AVERTISSEMENT pour un expéditeur gratuit (obtenu : {$res['status']})");
        neria_assert(strpos((string) $res['detail'], 'gmail.com') !== false, "Le message santé doit citer le domaine gmail.com : " . $res['detail']);
        neria_assert(strpos((string) $res['detail'], 'health.domain_rep_freemail') === false, 'Clé de traduction brute affichée');

        return [
            'pass'    => true,
            'message' => "Expéditeur gmail.com : aucun audit DNS, rapport freemail, avertissement Watchdog explicite (pas d'erreur « score D »), contrôle santé en avertissement qui cite le domaine — bug corrigé le 23/09/2026 (round 369)",
        ];
    } finally {
        foreach ([['PS_SHOP_EMAIL', $origEmail], ['NERIA_SENDERS_JSON', $origSenders], [DomainReputationManager::CONFIG_CACHE, $origCache], ['NERIA_DOMAIN_REP_LAST_CHECK', $origLast]] as [$k, $v]) {
            if ($v === false || $v === null) {
                Configuration::deleteByName($k);
            } else {
                Configuration::updateValue($k, $v, false, null, $idShop);
            }
        }
    }
}
