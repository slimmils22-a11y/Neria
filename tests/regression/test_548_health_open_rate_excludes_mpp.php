<?php
/**
 * Régression : `HealthCheckManager::checkOpenRate7d()` et
 * `checkEngagementTrend()` comptaient `event_type = 'open'` SANS filtrer
 * `is_mpp = 0` — contrairement à absolument tout le reste du module
 * (SegmentManager, MonthlyReportManager, ChurnScoreManager,
 * PropensityScoreManager, CustomerEmailHistoryManager, ClvManager,
 * StatsManager), qui excluent tous systématiquement les pré-chargements
 * automatiques Apple Mail Privacy Protection de leurs comptages
 * d'ouverture.
 *
 * Bug identifié et corrigé le 03/09/2026 (round 294, audit "calculs
 * numériques/statistiques du tableau de bord BO").
 *
 * Conséquence concrète avant correctif : sur une boutique avec une forte
 * proportion d'abonnés Apple Mail (MPP préchargé systématiquement à la
 * réception, indépendamment d'une ouverture humaine réelle), ces deux
 * alertes de santé — dont le rôle est justement de prévenir le marchand
 * d'un problème de délivrabilité caché (domaine en spam, DKIM cassé,
 * pixel de tracking défaillant) — voyaient leur taux d'ouverture gonflé
 * artificiellement à la hausse, masquant silencieusement un problème réel
 * au lieu de l'exposer.
 *
 * Test comportemental réel : 50 emails envoyés récemment (seuil minimum
 * requis pour que ces contrôles s'activent), dont les seules "ouvertures"
 * enregistrées sont des pré-chargements MPP (is_mpp=1), aucune vraie
 * ouverture. Avec le correctif, le taux d'ouverture réel doit être 0%
 * (statut ERROR, alerte critique) et non ~80% (statut OK, alerte masquée).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idShop     = (int) Context::getContext()->shop->id;
    $idCustomer = neria_test_any_customer_id();
    $token      = 'regtest548-' . uniqid();

    $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token LIKE '" . pSQL($token) . "%'");

    try {
        for ($i = 0; $i < 50; $i++) {
            $tok = $token . '-' . $i;
            $db->execute(
                "INSERT INTO {$prefix}neria_stat
                    (id_shop, template, lang, id_customer, tracking_token, event_type, is_mpp, date_add)
                 VALUES ({$idShop}, 'newsletter', 'fr', {$idCustomer}, '{$tok}', 'sent', 0, DATE_SUB(NOW(), INTERVAL 2 DAY))"
            );
            // 40/50 "ouvertures" enregistrées, mais TOUTES des
            // pré-chargements MPP (is_mpp=1) — aucune ouverture humaine
            // réelle. Sans le correctif : rate = 40/50*100 = 80% (OK).
            // Avec le correctif : rate = 0/50*100 = 0% (ERROR).
            if ($i < 40) {
                $db->execute(
                    "INSERT INTO {$prefix}neria_stat
                        (id_shop, template, lang, id_customer, tracking_token, event_type, is_mpp, date_add)
                     VALUES ({$idShop}, 'newsletter', 'fr', {$idCustomer}, '{$tok}', 'open', 1, DATE_SUB(NOW(), INTERVAL 2 DAY))"
                );
            }
        }

        $hcm = new HealthCheckManager(neria_test_module());
        $ref = new ReflectionMethod(HealthCheckManager::class, 'checkOpenRate7d');
        $ref->setAccessible(true);
        $result = $ref->invoke($hcm);

        neria_assert(
            ($result['status'] ?? '') === 'error',
            "HealthCheckManager::checkOpenRate7d() compte encore les ouvertures MPP (statut '" . ($result['status'] ?? '?') . "' au lieu de 'error') — régression du bug corrigé le 03/09/2026 (round 294) : un taux d'ouverture réel critique (0%) redeviendrait masqué par les pré-chargements Apple MPP (affiché ~80%, statut OK)"
        );

        return [
            'pass'    => true,
            'message' => "HealthCheckManager::checkOpenRate7d() exclut désormais les pré-chargements Apple MPP du taux d'ouverture — une alerte de délivrabilité critique n'est plus masquée à tort — bug corrigé le 03/09/2026 (round 294)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token LIKE '" . pSQL($token) . "%'");
    }
}
