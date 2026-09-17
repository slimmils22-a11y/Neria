<?php
/**
 * Régression : HealthCheckManager::checkBehavioralSilence() comptait les
 * emails comportementaux envoyés (neria_behavioral_sent) et les clients
 * actifs (ps_customer) SANS filtrer par id_shop, alors que
 * neria_behavioral_sent porte explicitement cette colonne pour distinguer
 * les boutiques (install.sql, TABLE 12) — round dédié HealthCheckManager
 * (bloc A, 16/09/2026, premier audit ciblé de ce fichier après 360 rounds
 * où il n'avait jamais été audité pour lui-même).
 *
 * Sur une installation multi-boutiques, une boutique B qui envoie
 * normalement masquait un vrai silence anormal sur une boutique A du
 * même install (faux OK malgré un problème réel sur A).
 *
 * Corrigé : les deux requêtes filtrent désormais explicitement par
 * $this->idShop.
 *
 * Test comportemental réel : simule une activité comportementale massive
 * sur une boutique FICTIVE alors que la boutique réelle n'a RIEN envoyé
 * depuis 7 jours (avec suffisamment de clients actifs réels pour rendre
 * ce silence anormal) — vérifie que le contrôle détecte bien le silence
 * réel de la boutique courante malgré l'activité de l'autre boutique.
 * Vérifie ensuite qu'un vrai envoi sur la boutique courante fait
 * disparaître l'alerte.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php';

    $db       = neria_test_db();
    $prefix   = neria_test_prefix();
    $realShop = (int) Context::getContext()->shop->id;
    $fakeShop = 999997776;
    $module   = neria_test_module();

    $wasLastRun = (string) Configuration::getGlobalValue(HealthCheckManager::CRON_LAST_BEHAVIORAL);

    $db->execute("DELETE FROM {$prefix}neria_behavioral_sent WHERE id_shop = {$fakeShop}");
    $db->execute("DELETE FROM {$prefix}neria_behavioral_sent WHERE template = 'regtest776_marker'");

    try {
        $activeCustomers = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}customer WHERE active = 1 AND deleted = 0 AND id_shop = {$realShop}"
        );
        neria_assert($activeCustomers >= 10, "jeu de test invalide : moins de 10 clients actifs réels sur la boutique courante ({$activeCustomers}), le seuil de silence anormal ne serait jamais atteint");

        // Le cron doit être passé "récemment" (fenêtre 7 jours) pour ne pas
        // sortir en OK dès la 1re condition ("cron non exécuté").
        Configuration::updateGlobalValue(HealthCheckManager::CRON_LAST_BEHAVIORAL, date('Y-m-d H:i:s'));

        // Activité massive sur une boutique FICTIVE — si le comptage n'était
        // pas scopé (bug), ces envois masqueraient à tort le silence RÉEL de
        // la boutique courante.
        for ($i = 0; $i < 10; $i++) {
            $db->execute(
                "INSERT INTO {$prefix}neria_behavioral_sent (id_customer, template, ref_id, id_shop, sent_at)
                 VALUES (1, 'birthday', {$i}, {$fakeShop}, NOW())"
            );
        }

        $hc = new HealthCheckManager($module);
        $method = new ReflectionMethod(HealthCheckManager::class, 'checkBehavioralSilence');
        $method->setAccessible(true);

        $result = $method->invoke($hc);
        neria_assert(
            $result['status'] === 'warning',
            "checkBehavioralSilence() renvoie '{$result['status']}' alors que la boutique courante n'a RIEN envoyé depuis 7 jours (10 envois réels appartiennent à une AUTRE boutique fictive) — régression du scoping id_shop, détail obtenu = " . ($result['detail'] ?? '?')
        );

        // Un envoi RÉEL sur la boutique courante doit faire disparaître
        // l'alerte — le filtre ne doit pas non plus masquer une vraie
        // activité de CETTE boutique.
        $db->execute(
            "INSERT INTO {$prefix}neria_behavioral_sent (id_customer, template, ref_id, id_shop, sent_at)
             VALUES (1, 'regtest776_marker', 1, {$realShop}, NOW())"
        );
        $resultAfterReal = $method->invoke($hc);
        neria_assert(
            $resultAfterReal['status'] === 'ok',
            "checkBehavioralSilence() reste en alerte après un vrai envoi comportemental sur la boutique courante — jeu de test invalide ou régression"
        );

        return [
            'pass'    => true,
            'message' => "HealthCheckManager::checkBehavioralSilence() détecte bien un silence comportemental propre à la boutique courante, sans être masqué par l'activité d'une AUTRE boutique de l'installation — bug corrigé round HealthCheckManager (bloc A, 16/09/2026)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_behavioral_sent WHERE id_shop = {$fakeShop}");
        $db->execute("DELETE FROM {$prefix}neria_behavioral_sent WHERE template = 'regtest776_marker'");
        if ($wasLastRun !== '') {
            Configuration::updateGlobalValue(HealthCheckManager::CRON_LAST_BEHAVIORAL, $wasLastRun);
        }
    }
}
