<?php
/**
 * Régression : CustomerEmailHistoryManager::computeEngagementBadge() ne
 * gardait que le cas $total === 0 (badge 'new'). Dès qu'un client avait 1
 * seul email envoyé (même reçu il y a quelques minutes, pas encore
 * ouvert), le taux d'ouverture calculé (0%) le classait directement en
 * badge "Inactif" — identique au badge d'un client ayant reçu des
 * dizaines d'emails jamais ouverts, alors que la donnée n'est pas
 * significative.
 *
 * Bug identifié le 31/08/2026 (round 257, audit "ratios/scores trompeurs
 * sur petit échantillon"). Corrigé le 31/08/2026 (round 257) : nouveau
 * seuil MIN_BADGE_SAMPLE=3 ; en dessous, niveau 'insufficient_data' (badge
 * neutre dédié, template + traduction ajoutés) au lieu d'un niveau
 * rate_open-dépendant trompeur.
 *
 * Test comportemental réel : appelle la VRAIE méthode publique
 * computeEngagementBadge() avec 1 puis 2 emails non ouverts (doit
 * retourner 'insufficient_data', pas 'inactive'), et avec 3 emails
 * ouverts (doit retourner un niveau normal, non-régression du seuil).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CustomerEmailHistoryManager.php';

    $mgr = new CustomerEmailHistoryManager(neria_test_module());

    $oneUnopened = [
        ['opened' => false, 'opened_at' => null, 'sent_at' => date('Y-m-d H:i:s')],
    ];
    $badge1 = $mgr->computeEngagementBadge($oneUnopened);
    neria_assert(
        $badge1['level'] === 'insufficient_data',
        "computeEngagementBadge() avec 1 seul email non ouvert renvoie le niveau '{$badge1['level']}' au lieu de 'insufficient_data' — régression du bug corrigé le 31/08/2026 (round 257) : un client venant tout juste de recevoir son 1er email serait de nouveau étiqueté 'Inactif' comme un client à l'historique conséquent"
    );

    $twoUnopened = array_fill(0, 2, ['opened' => false, 'opened_at' => null, 'sent_at' => date('Y-m-d H:i:s')]);
    $badge2 = $mgr->computeEngagementBadge($twoUnopened);
    neria_assert(
        $badge2['level'] === 'insufficient_data',
        "computeEngagementBadge() avec 2 emails non ouverts renvoie le niveau '{$badge2['level']}' au lieu de 'insufficient_data' — régression du bug corrigé le 31/08/2026 (round 257)"
    );

    // Non-régression : 3 emails, tous ouverts, doivent toujours donner le
    // niveau normal 'very_engaged' — le seuil ne doit pas masquer un vrai
    // signal dès qu'il y a assez de données.
    $threeOpened = array_fill(0, 3, ['opened' => true, 'opened_at' => date('Y-m-d H:i:s'), 'sent_at' => date('Y-m-d H:i:s')]);
    $badge3 = $mgr->computeEngagementBadge($threeOpened);
    neria_assert(
        $badge3['level'] === 'very_engaged',
        "computeEngagementBadge() avec 3 emails tous ouverts renvoie le niveau '{$badge3['level']}' au lieu de 'very_engaged' — le seuil MIN_BADGE_SAMPLE (round 257) ne devrait pas masquer un vrai signal sur un échantillon suffisant"
    );

    // Non-régression : 0 email doit toujours donner 'new', pas 'insufficient_data'.
    $badge0 = $mgr->computeEngagementBadge([]);
    neria_assert(
        $badge0['level'] === 'new',
        "computeEngagementBadge() avec 0 email renvoie le niveau '{$badge0['level']}' au lieu de 'new' — régression sur le cas déjà géré \$total === 0"
    );

    return [
        'pass'    => true,
        'message' => "CustomerEmailHistoryManager::computeEngagementBadge() renvoie désormais 'insufficient_data' (badge neutre dédié) en dessous de MIN_BADGE_SAMPLE=3 emails, au lieu d'un badge 'Inactif' trompeur basé sur 1-2 emails — bug corrigé le 31/08/2026 (round 257)",
    ];
}
