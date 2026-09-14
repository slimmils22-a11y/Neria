<?php
/**
 * Régression : DomainReputationManager::runFullCheck() doit déclencher
 * l'alerte Watchdog (alertForReport()) pour LA BOUTIQUE COURANTE même
 * quand elle réutilise le rapport mutualisé d'une boutique sœur partageant
 * le même domaine d'envoi (round 299, findFreshReportForDomain()).
 *
 * Bug identifié le 14/09/2026 (round 352, audit multi-agents, angle
 * réputation de domaine) : seule runFullCheckLocked() (qui exécute
 * réellement la résolution DNS/RBL) déclenchait watchdog()->error/warning/
 * info() — watchdog() est scopé par $this->idShop. Une boutique
 * "suiveuse" réutilisant le rapport frais d'une boutique sœur affichait le
 * même grade critique (F/D) sur son tableau de bord sans JAMAIS recevoir
 * la moindre alerte Watchdog pour son propre id_shop.
 *
 * Corrigé en extrayant la logique d'alerte dans alertForReport(), appelée
 * aussi aux 2 points où un rapport partagé est réutilisé.
 *
 * Test comportemental réel : appelle alertForReport() via réflexion avec
 * un rapport de grade 'F' fictif, et vérifie qu'une ligne neria_log réelle
 * apparaît bien scopée sur l'id_shop du manager (pas celui d'une autre
 * boutique).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/DomainReputationManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;
    $marker = 'round352domrep' . time();

    $mgr = new DomainReputationManager(neria_test_module());
    $ref = new ReflectionMethod(DomainReputationManager::class, 'alertForReport');
    $ref->setAccessible(true);

    $fakeReport = [
        'domain'     => $marker . '.invalid',
        'score'      => 10,
        'grade'      => 'F',
        'blacklists' => ['hits' => ['spamhaus', 'barracuda']],
    ];

    $before = (int) $db->getValue(
        "SELECT COUNT(*) FROM {$prefix}neria_log WHERE id_shop = {$idShop} AND message LIKE '%{$marker}%'"
    );
    neria_assert($before === 0, 'Jeu de test invalide : résidu déjà présent avant exécution');

    try {
        $ref->invoke($mgr, $fakeReport);

        $after = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_log WHERE id_shop = {$idShop} AND message LIKE '%{$marker}%'"
        );
        neria_assert(
            $after > 0,
            "alertForReport() n'a écrit aucune entrée neria_log scopée sur l'id_shop du manager — régression du bug corrigé le 14/09/2026 (round 352)"
        );

        // Vérification structurelle complémentaire : les 2 sites de
        // réutilisation d'un rapport partagé dans runFullCheck() doivent
        // bien appeler alertForReport().
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/DomainReputationManager.php');
        neria_assert($src !== false, 'Impossible de lire src/DomainReputationManager.php');
        neria_assert(
            substr_count($src, '$this->alertForReport($shared);') === 2,
            "runFullCheck() n'appelle plus alertForReport(\$shared) aux 2 points de réutilisation d'un rapport partagé — régression du bug corrigé le 14/09/2026 (round 352) : une boutique suiveuse ne recevrait de nouveau aucune alerte pour son propre id_shop"
        );

        return [
            'pass'    => true,
            'message' => "DomainReputationManager::alertForReport() écrit bien une alerte Watchdog scopée sur l'id_shop du manager, et runFullCheck() l'appelle bien aux 2 points de réutilisation d'un rapport partagé — bug corrigé le 14/09/2026 (round 352)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_log WHERE message LIKE '%{$marker}%'");
    }
}
