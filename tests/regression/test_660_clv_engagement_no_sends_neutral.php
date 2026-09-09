<?php
/**
 * Régression : ClvManager::getEngagementRate() retournait 0.0 quand aucun
 * email n'a été envoyé au client sur les 30 derniers jours (sent=0) — ce
 * taux tombant sous ENGAGEMENT_MEDIUM (0.20), computeClv()/assembleClv()
 * infligeaient alors la même pénalité -15% (engagementMult=0.85, label
 * 'low') qu'à un client ayant réellement reçu des dizaines d'emails jamais
 * ouverts. Aucune donnée ne permettait pourtant de qualifier ce client
 * "n'ouvre pas ses emails" — il n'en a simplement reçu aucun (client
 * jamais encore ciblé par une campagne, consentement pas encore donné).
 *
 * Bug réel : biaisait getTopCustomers() (classement CLV/Top marketing) en
 * sous-classant des clients à fort potentiel simplement parce qu'ils
 * n'ont pas encore été inclus dans une campagne email, et affichait un
 * badge "engagement: low" trompeur sur leur fiche.
 *
 * Corrigé le 09/09/2026 (round 328) : sent=0 retourne désormais
 * ENGAGEMENT_MEDIUM (mult neutre 1.00, label 'medium'), même principe que
 * ChurnScoreManager::MIN_SAMPLE_SENDS (round 257) — neutre par défaut
 * quand la donnée est absente, pas le pire cas extrapolé.
 *
 * Test comportemental réel : appelle getEngagementRate() via réflexion
 * pour un client de test SANS AUCUNE ligne neria_stat (sent=0 garanti),
 * vérifie qu'il retourne ENGAGEMENT_MEDIUM (pas 0.0).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ClvManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) Context::getContext()->shop->id;

    // Garantit sent=0 : aucune ligne neria_stat récente (30j) pour ce client.
    $db->execute(
        "DELETE FROM {$prefix}neria_stat WHERE id_customer = {$idCustomer} AND id_shop = {$idShop} AND date_add >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );

    $mgr = new ClvManager(neria_test_module());
    $ref = new ReflectionMethod(ClvManager::class, 'getEngagementRate');
    $ref->setAccessible(true);
    $rate = $ref->invoke($mgr, $idCustomer);

    $refConst = new ReflectionClassConstant(ClvManager::class, 'ENGAGEMENT_MEDIUM');
    $engagementMedium = $refConst->getValue();

    neria_assert(
        abs($rate - $engagementMedium) < 0.0001,
        "ClvManager::getEngagementRate() retourne {$rate} pour un client sans aucun email envoyé (sent=0), attendu ENGAGEMENT_MEDIUM ({$engagementMedium}) — régression du bug corrigé le 09/09/2026 (round 328) : une pénalité -15% (engagement 'low') serait de nouveau infligée à tort à un client simplement jamais ciblé par une campagne"
    );

    return [
        'pass'    => true,
        'message' => "ClvManager::getEngagementRate() retourne bien un taux neutre (ENGAGEMENT_MEDIUM) pour un client sans aucun email envoyé, au lieu de le pénaliser comme s'il n'ouvrait jamais ses emails — bug corrigé le 09/09/2026 (round 328)",
    ];
}
