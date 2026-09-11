<?php
/**
 * Régression : `QueueManager::processSingle()` réservait une ligne
 * (`status='sending'`) sans jamais rafraîchir `send_at` — colonne pourtant
 * comparée en tête de `processQueue()` (`send_at <= NOW() - 10 MINUTE`) pour
 * détecter une ligne restée bloquée à `'sending'` après un crash du
 * process. Pour un envoi comportemental différé (jusqu'à ~24h via
 * `nextOccurrence()`), `send_at` est déjà largement dans le passé au moment
 * où la ligne est sélectionnée (`send_at <= NOW()` est la condition même de
 * sélection) — la condition de nettoyage `send_at <= NOW() - 10 MINUTE`
 * était donc déjà VRAIE dès l'instant de la réservation, pas seulement
 * après 10 minutes. Un crash du process juste après la réservation (avant
 * l'écriture du statut final) rendait la ligne IMMÉDIATEMENT
 * re-sélectionnable au tout prochain passage du cron au lieu d'attendre les
 * 10 minutes annoncées par le commentaire du code — si `Mail::Send()` avait
 * réussi juste avant le crash, l'email repartait aussitôt en double.
 *
 * Bug identifié le 11/09/2026 (round 336, audit PropensityScoreManager/
 * QueueManager).
 *
 * Corrigé le 11/09/2026 (round 336) : la réservation atomique de
 * `processSingle()` met désormais aussi à jour `send_at = NOW()` — la
 * fenêtre de 10 minutes ne peut plus être immédiatement satisfaite dès la
 * réservation, elle reflète maintenant vraiment le délai depuis la
 * réservation elle-même.
 *
 * Test comportemental réel : insère une ligne `neria_queue` avec
 * `send_at` délibérément 2 jours dans le passé (simulant un envoi
 * comportemental différé de longue date) et un `ref_id` de produit
 * ghost_cart inexistant — `processSingle()` sort alors très tôt via
 * `markQueueFailed('product_unavailable')`, AVANT tout envoi réel
 * (Mail::Send() jamais atteint, comportement testable sans réseau), sans
 * que `markQueueFailed()` lui-même ne touche `send_at` (vérifié par
 * lecture du code : seuls `status`/`error` sont modifiés). Le `send_at`
 * observable après l'appel ne peut donc provenir QUE de la réservation
 * elle-même — s'il est encore 2 jours dans le passé, le correctif a
 * régressé.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    // ── Vérification structurelle du correctif ────────────────────────
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/QueueManager.php');
    neria_assert($src !== false, 'Impossible de lire src/QueueManager.php');
    $posFn = strpos($src, 'private function processSingle(array $row): bool');
    neria_assert($posFn !== false, 'processSingle() introuvable — jeu de test invalide');
    $body = substr($src, $posFn, 2600);
    neria_assert(
        strpos($body, "SET attempts = attempts + 1, status = \\'sending\\', send_at = NOW()") !== false,
        "processSingle() ne rafraîchit plus send_at=NOW() lors de la réservation atomique — régression du bug corrigé le 11/09/2026 (round 336) : la fenêtre de 10 minutes de récupération après crash (processQueue()) redeviendrait immédiatement satisfaite dès la réservation pour tout envoi différé, risquant un double envoi après crash"
    );

    // ── Vérification comportementale du chemin réel ───────────────────
    require_once _PS_MODULE_DIR_ . 'neria/src/QueueManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;
    $module = neria_test_module();
    $table  = $prefix . 'neria_queue';

    $fakeProductId = 999999991; // garanti inexistant

    $db->execute(
        "INSERT INTO {$table}
            (id_customer, id_shop, id_lang, template, recipient_email, recipient_name,
             vars_json, ref_id, send_at, status, attempts, created_at)
         VALUES
            (0, {$idShop}, " . (int) Configuration::get('PS_LANG_DEFAULT') . ", 'ghost_cart',
             'regtest686@example.com', 'Regtest686',
             '{}', {$fakeProductId}, DATE_SUB(NOW(), INTERVAL 2 DAY), 'pending', 0, NOW())"
    );
    $id = (int) $db->Insert_ID();
    neria_assert($id > 0, "Insert_ID() n'a pas renvoyé d'id valide — jeu de test invalide");

    try {
        $row = $db->getRow("SELECT * FROM {$table} WHERE id_neria_queue = {$id}");
        neria_assert($row !== false, "Ligne fraîchement insérée introuvable — jeu de test invalide");

        $mgr = new QueueManager($module);
        $ref = new ReflectionMethod(QueueManager::class, 'processSingle');
        $ref->setAccessible(true);
        $result = $ref->invoke($mgr, $row);

        neria_assert(
            $result === false,
            "processSingle() n'est pas sorti par le chemin 'product_unavailable' attendu (résultat=" . var_export($result, true) . ") — jeu de test invalide, la logique ghost_cart a peut-être changé"
        );

        $after = $db->getRow("SELECT status, error, send_at, TIMESTAMPDIFF(SECOND, send_at, NOW()) AS age_sec FROM {$table} WHERE id_neria_queue = {$id}");
        neria_assert($after !== false, "Ligne introuvable après processSingle() — jeu de test invalide");
        neria_assert(
            $after['status'] === 'failed' && $after['error'] === 'blocked_by_product_unavailable',
            "processSingle() n'a pas terminé sur status='failed'/error='blocked_by_product_unavailable' comme attendu (status={$after['status']}, error={$after['error']}) — jeu de test invalide"
        );

        $ageSec = (int) $after['age_sec'];
        neria_assert(
            $ageSec >= 0 && $ageSec < 120,
            "send_at n'a pas été rafraîchi à NOW() lors de la réservation (écart observé : {$ageSec}s, attendu < 120s) — régression du bug corrigé le 11/09/2026 (round 336) : send_at serait resté figé sur la date de planification d'origine (2 jours dans le passé)"
        );

        return [
            'pass'    => true,
            'message' => "QueueManager::processSingle() rafraîchit désormais send_at=NOW() lors de la réservation atomique — la fenêtre de récupération après crash de processQueue() (10 min) reflète bien le délai depuis la réservation, pas la date de planification d'origine — bug corrigé le 11/09/2026 (round 336)",
        ];
    } finally {
        $db->execute("DELETE FROM {$table} WHERE id_neria_queue = {$id}");
    }
}
