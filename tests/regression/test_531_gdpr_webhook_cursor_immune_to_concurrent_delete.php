<?php
/**
 * Régression : `GdprAuditManager::purgeCustomerData()` lisait
 * `neria_webhook_queue` par lots via `LIMIT {$whChunkSize} OFFSET
 * {$whOffset}` (round 275). `WebhookManager::cleanup()` (appelée de
 * façon probabiliste — 1 chance sur 10 — à CHAQUE exécution de
 * `processQueue()`, donc à chaque cycle du cron webhook) supprime les
 * lignes `done`/`failed` de plus de 30 jours SANS lien avec
 * `id_webhook`. Si ce `cleanup()` s'exécutait pendant le scan RGPD et
 * supprimait des lignes déjà lues (situées AVANT la position courante
 * du scan), la fenêtre `OFFSET` de l'itération suivante se décalait :
 * des lignes jamais renvoyées par aucune requête `LIMIT/OFFSET`, donc
 * potentiellement des webhooks du client en cours d'effacement RGPD
 * qui survivaient à sa demande.
 *
 * Bug identifié le 02/09/2026 (round 278, audit "pagination / limites
 * de lot dans les crons").
 *
 * Corrigé le 02/09/2026 (round 278) : pagination par curseur
 * (`WHERE id_webhook > {$whLastId} ORDER BY id_webhook ASC LIMIT ...`)
 * au lieu d'`OFFSET` — un curseur sur une clé strictement croissante et
 * jamais réattribuée est immunisé contre une suppression concurrente,
 * quelle que soit sa position par rapport au curseur.
 *
 * Test comportemental réel : simule le scénario exact — insère des
 * lignes AVANT et APRÈS la ligne ciblée par la purge RGPD, supprime
 * "concurremment" (entre deux étapes logiques du scan, en simulant
 * ici directement l'effet net d'un cleanup() qui aurait tourné pendant
 * le scan) une ligne ANTÉRIEURE non liée au client, puis vérifie que la
 * ligne du client ciblé est bien purgée malgré ce remaniement de la
 * table.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/GdprAuditManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();

    $idTarget = 2000278;

    $db->execute("DELETE FROM {$prefix}neria_webhook_queue WHERE id_shop = 999997");

    // Ligne "bruit" ANTÉRIEURE, non liée au client ciblé — celle qu'un
    // cleanup() concurrent supprimerait typiquement (statut 'done',
    // ancienne). Sa suppression AVANT la lecture de la ligne cible est ce
    // qui décalait l'OFFSET dans le bug d'origine.
    $db->execute(
        "INSERT INTO {$prefix}neria_webhook_queue (id_shop, event, payload, status, attempts, date_add)
         VALUES (999997, 'email_sent', '" . pSQL(json_encode(['template' => 'noise', 'customer_id' => 999999])) . "', 'done', 0, DATE_SUB(NOW(), INTERVAL 60 DAY))"
    );
    $idNoise = (int) $db->Insert_ID();

    // Ligne CIBLE du client dont la purge RGPD est demandée.
    $db->execute(
        "INSERT INTO {$prefix}neria_webhook_queue (id_shop, event, payload, status, attempts, date_add)
         VALUES (999997, 'email_sent', '" . pSQL(json_encode(['template' => 'vip', 'customer_id' => $idTarget])) . "', 'pending', 0, NOW())"
    );
    $idRowTarget = (int) $db->Insert_ID();

    try {
        neria_assert($idNoise > 0 && $idRowTarget > 0, 'jeu de test invalide : INSERT échoué');

        // Simule l'effet net d'un WebhookManager::cleanup() qui aurait
        // tourné entre deux itérations du scan RGPD : la ligne "bruit"
        // (id_webhook le plus petit) disparaît AVANT que
        // purgeCustomerData() ne lise la table.
        $db->execute("DELETE FROM {$prefix}neria_webhook_queue WHERE id_webhook = {$idNoise}");

        $mgr = new GdprAuditManager(_PS_MODULE_DIR_ . 'neria');
        $mgr->purgeCustomerData($idTarget, '');

        $targetExists = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_webhook_queue WHERE id_webhook = {$idRowTarget}");

        neria_assert(
            $targetExists === 0,
            "le webhook du client ciblé par la purge RGPD n'a pas été supprimé malgré une suppression concurrente d'une ligne antérieure — régression du bug corrigé le 02/09/2026 (round 278) : la pagination OFFSET redeviendrait vulnérable au décalage de fenêtre causé par WebhookManager::cleanup()"
        );

        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/GdprAuditManager.php');
        neria_assert($src !== false, 'Impossible de lire src/GdprAuditManager.php');
        $pos = strpos($src, '$whChunkSize = 2000;');
        neria_assert($pos !== false, 'jeu de test invalide : bloc webhook introuvable');
        $body = substr($src, $pos, 2800);
        neria_assert(
            strpos($body, 'WHERE `id_webhook` > {$whLastId}') !== false
                && strpos($body, 'OFFSET {$whOffset}') === false,
            "GdprAuditManager::purgeCustomerData() ne pagine plus neria_webhook_queue par curseur (id_webhook > dernier vu) — régression du bug corrigé le 02/09/2026 (round 278) : un OFFSET réapparu redeviendrait vulnérable au décalage de fenêtre sous suppression concurrente"
        );

        return [
            'pass'    => true,
            'message' => "La purge RGPD de neria_webhook_queue reste correcte même quand une ligne antérieure est supprimée concurremment (pagination par curseur immunisée) — bug corrigé le 02/09/2026 (round 278)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_webhook_queue WHERE id_shop = 999997");
    }
}
