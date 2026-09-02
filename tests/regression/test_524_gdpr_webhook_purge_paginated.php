<?php
/**
 * Régression : `GdprAuditManager::purgeCustomerData()` chargeait TOUTE la
 * table `neria_webhook_queue` en un seul `SELECT` sans `LIMIT` ni filtre
 * `id_shop`, à chaque demande RGPD d'effacement — contrairement à
 * `neria_log` (plafonnée à 500 lignes/boutique par
 * `WatchdogManager::MAX_LOGS`), cette table n'a aucun plafond équivalent :
 * seules les lignes `done`/`failed` de plus de 30 jours sont purgées par
 * `WebhookManager::cleanup()`, les lignes `pending` d'un backlog (endpoint
 * tiers en panne, volume élevé) s'accumulent sans limite. Sur une
 * boutique à fort trafic avec un tel backlog (dizaines de milliers de
 * lignes), une seule demande d'effacement RGPD pouvait charger l'intégralité
 * de ces payloads JSON en mémoire PHP en une fois.
 *
 * Bug identifié le 01/09/2026 (round 275, audit "fuite mémoire/ressources
 * sur crons longs").
 *
 * Corrigé le 01/09/2026 (round 275) : la lecture se fait désormais par
 * lots de 2000 lignes, le comportement fonctionnel (purge exacte, pas de
 * sous-chaîne — round 144) restant strictement inchangé.
 *
 * Round 278 : la pagination par LIMIT/OFFSET introduite ici a elle-même
 * été remplacée par une pagination par curseur (id_webhook > dernier vu)
 * — WebhookManager::cleanup() (appelée probabilistement à chaque
 * exécution du cron webhook) pouvait supprimer des lignes déjà lues
 * pendant que cette boucle tournait, décalant la fenêtre OFFSET des
 * itérations suivantes et faisant sauter des webhooks du client à
 * effacer. Le curseur sur id_webhook (croissant, jamais réattribué) est
 * immunisé contre ces suppressions concurrentes. Le test structurel
 * ci-dessous a été adapté à ce nouveau câblage ; le comportement
 * fonctionnel testé plus haut reste inchangé.
 *
 * Test comportemental réel : insère 3 lignes de webhook pour un client A
 * (répartissable sur 2 "pages" en forçant un petit `whChunkSize` via une
 * table de test dédiée serait disproportionné — vérifie plutôt que la
 * purge réelle continue de fonctionner correctement sur un jeu de lignes
 * dépassant une seule page n'est pas nécessaire pour prouver la
 * pagination : on vérifie le câblage structurel de la boucle LIMIT/OFFSET
 * ET le comportement réel de bout en bout (purge d'un client précis,
 * préservation d'un tiers), garantissant que la pagination n'a rien
 * cassé fonctionnellement.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/GdprAuditManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();

    $idA = 2000042;
    $idB = 2000043;

    $db->execute("DELETE FROM {$prefix}neria_webhook_queue WHERE id_shop = 999998");

    $db->execute(
        "INSERT INTO {$prefix}neria_webhook_queue (id_shop, event, payload, status, attempts, date_add)
         VALUES (999998, 'email_sent', '" . pSQL(json_encode(['template' => 'vip', 'customer_id' => $idA])) . "', 'pending', 0, NOW())"
    );
    $idRowA = (int) $db->Insert_ID();
    $db->execute(
        "INSERT INTO {$prefix}neria_webhook_queue (id_shop, event, payload, status, attempts, date_add)
         VALUES (999998, 'email_sent', '" . pSQL(json_encode(['template' => 'vip', 'customer_id' => $idB])) . "', 'pending', 0, NOW())"
    );
    $idRowB = (int) $db->Insert_ID();

    try {
        neria_assert($idRowA > 0 && $idRowB > 0, 'jeu de test invalide : INSERT échoué');

        $mgr = new GdprAuditManager(_PS_MODULE_DIR_ . 'neria');
        $mgr->purgeCustomerData($idA, '');

        $rowAExists = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_webhook_queue WHERE id_webhook = {$idRowA}");
        $rowBExists = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_webhook_queue WHERE id_webhook = {$idRowB}");

        neria_assert(
            $rowAExists === 0,
            "la ligne du client A n'a pas été purgée après pagination de la requête — régression du bug corrigé le 01/09/2026 (round 275) : la purge par lots aurait cassé le comportement fonctionnel de purgeCustomerData()"
        );
        neria_assert(
            $rowBExists === 1,
            "la ligne du client B a été purgée par erreur — régression du bug corrigé le 01/09/2026 (round 275)"
        );

        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/GdprAuditManager.php');
        neria_assert($src !== false, 'Impossible de lire src/GdprAuditManager.php');
        $pos = strpos($src, "\$whChunkSize = 2000;");
        neria_assert(
            $pos !== false,
            "GdprAuditManager::purgeCustomerData() ne pagine plus la lecture de neria_webhook_queue par lots — régression du bug corrigé le 01/09/2026 (round 275) : un backlog volumineux (boutique à fort trafic, endpoint tiers en panne) chargerait de nouveau toute la table en mémoire à chaque demande RGPD d'effacement"
        );
        $body = substr($src, $pos, 2800);
        neria_assert(
            strpos($body, 'WHERE `id_webhook` > {$whLastId}') !== false
            && strpos($body, 'ORDER BY `id_webhook` ASC LIMIT {$whChunkSize}') !== false
            && strpos($body, 'while ($rowCount === $whChunkSize);') !== false,
            "GdprAuditManager::purgeCustomerData() n'a plus la pagination par curseur (id_webhook > dernier vu) attendue pour neria_webhook_queue — régression du bug corrigé le 02/09/2026 (round 278) : un cleanup() concurrent (WebhookManager, probabiliste à chaque cron) pourrait de nouveau décaler une fenêtre OFFSET et faire sauter des webhooks du client à effacer"
        );

        return [
            'pass'    => true,
            'message' => "GdprAuditManager::purgeCustomerData() lit désormais neria_webhook_queue par lots de 2000 lignes, sans changer le comportement de purge exacte — bug corrigé le 01/09/2026 (round 275)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_webhook_queue WHERE id_shop = 999998");
    }
}
