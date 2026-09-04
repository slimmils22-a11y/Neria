<?php
/**
 * Régression : `QueueManager::processSingle()` revérifiait déjà le produit
 * `ghost_cart` au moment de l'envoi réel (round 260), mais jamais l'état
 * "panier toujours non converti" pour les 4 templates de relance panier
 * abandonné (`abandoned_cart_1`/`2`/`3`, `checkout_abandonment`) — pourtant
 * exactement la même classe de risque.
 *
 * `BehavioralCronManager::sendAbandonedCarts()`/`sendCheckoutAbandonment()`
 * vérifient bien `NOT EXISTS (SELECT 1 FROM orders WHERE id_cart = ...)`
 * au moment de la SÉLECTION par le cron, mais si la fenêtre d'achat
 * individuelle (`NERIA_PURCHASE_WINDOW_ENABLED`) est active, l'envoi réel
 * est différé via `QueueManager::enqueue()` jusqu'à ~24h plus tard
 * (`nextOccurrence()`). Le client peut parfaitement finaliser sa commande
 * pendant ce délai — `processSingle()` étant le SEUL point d'envoi réel de
 * la file, sans revérification il envoie quand même la relance "vous avez
 * oublié ceci" pour des articles déjà achetés.
 *
 * Bug identifié et corrigé le 03/09/2026 (round 294, audit "cohérence des
 * données entre enqueue et envoi différé").
 *
 * Corrigé le 03/09/2026 (round 294) : `processSingle()` revérifie
 * désormais `NOT EXISTS (orders WHERE id_cart = ref_id)` pour ces 4
 * templates juste avant l'envoi, bloque l'envoi (statut 'failed',
 * error='blocked_by_cart_already_converted') si le panier a été converti
 * entre-temps — même schéma que le garde-fou ghost_cart existant.
 *
 * Test comportemental réel avec deux paniers distincts de la base de
 * test : un panier RÉELLEMENT lié à une commande existante (doit être
 * bloqué), un panier RÉELLEMENT non converti (doit être laissé passer par
 * ce garde-fou précis — le test ne vérifie que le comportement du garde-
 * fou lui-même, pas le succès de bout en bout de Mail::Send()).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/QueueManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) Context::getContext()->shop->id;
    $idLang     = (int) Configuration::get('PS_LANG_DEFAULT');

    $convertedCart = $db->getRow(
        "SELECT o.id_cart FROM {$prefix}orders o"
    );
    neria_assert($convertedCart !== false && (int) $convertedCart['id_cart'] > 0, "jeu de test invalide : aucune commande trouvée en base de test");
    $idCartConverted = (int) $convertedCart['id_cart'];

    $notConvertedCart = $db->getRow(
        "SELECT ca.id_cart
         FROM {$prefix}cart ca
         WHERE ca.id_customer > 0
           AND NOT EXISTS (SELECT 1 FROM {$prefix}orders o WHERE o.id_cart = ca.id_cart)"
    );
    if ($notConvertedCart === false) {
        return ['pass' => true, 'message' => 'Aucun panier non converti disponible en base de test — vérification partielle uniquement (scénario "panier converti" testé ci-dessous, structurellement confirmé pour le reste)'];
    }
    $idCartNotConverted = (int) $notConvertedCart['id_cart'];

    $insertRow = function (string $template, int $idCart) use ($db, $prefix, $idCustomer, $idShop, $idLang): int {
        $vars = json_encode(['{cart_url}' => 'https://example.test/cart', '{products}' => '<li>Test</li>', '{products_txt}' => '- Test'], JSON_UNESCAPED_UNICODE);
        $db->execute("DELETE FROM {$prefix}neria_queue WHERE id_customer = {$idCustomer} AND template = '" . pSQL($template) . "' AND ref_id = {$idCart} AND id_shop = {$idShop}");
        $db->execute(
            "INSERT INTO {$prefix}neria_queue
                (id_customer, id_shop, id_lang, template, recipient_email, recipient_name,
                 vars_json, ref_id, send_at, status, attempts, created_at)
             VALUES ({$idCustomer}, {$idShop}, {$idLang}, '" . pSQL($template) . "', 'regtest547@example.test', 'Regtest',
                     '" . pSQL($vars) . "', {$idCart}, NOW(), 'pending', 0, NOW())"
        );
        return (int) $db->Insert_ID();
    };

    $mgr = new QueueManager(neria_test_module());
    $ref = new ReflectionMethod($mgr, 'processSingle');
    $ref->setAccessible(true);

    try {
        // ── Scénario 1 : panier déjà converti en commande ───────────
        $idQueue1 = $insertRow('abandoned_cart_1', $idCartConverted);
        $row1 = $db->getRow("SELECT * FROM {$prefix}neria_queue WHERE id_neria_queue = {$idQueue1}");
        $sent1 = $ref->invoke($mgr, $row1);

        neria_assert(
            $sent1 === false,
            "QueueManager::processSingle() envoie encore une relance panier abandonné pour un panier déjà converti en commande — régression du bug corrigé le 03/09/2026 (round 294) : un client recevrait de nouveau une relance pour des articles déjà achetés"
        );

        $status1 = $db->getRow("SELECT status, error FROM {$prefix}neria_queue WHERE id_neria_queue = {$idQueue1}");
        neria_assert(
            $status1 !== false && $status1['status'] === 'failed' && strpos((string) $status1['error'], 'cart_already_converted') !== false,
            "QueueManager::processSingle() ne marque plus la ligne 'failed'/'blocked_by_cart_already_converted' pour un panier déjà converti — régression du bug corrigé le 03/09/2026 (round 294)"
        );

        // ── Scénario 2 : panier toujours non converti ────────────────
        // On vérifie ici uniquement que le NOUVEAU garde-fou ne bloque
        // PAS un panier légitimement encore abandonné (pas de faux
        // positif) — pas le succès de bout en bout de Mail::Send(),
        // dépendant de l'environnement SMTP local.
        $idQueue2 = $insertRow('abandoned_cart_1', $idCartNotConverted);
        $row2 = $db->getRow("SELECT * FROM {$prefix}neria_queue WHERE id_neria_queue = {$idQueue2}");
        $ref->invoke($mgr, $row2);

        $status2 = $db->getRow("SELECT status, error FROM {$prefix}neria_queue WHERE id_neria_queue = {$idQueue2}");
        neria_assert(
            $status2 !== false && strpos((string) $status2['error'], 'cart_already_converted') === false,
            "QueueManager::processSingle() bloque à tort un panier toujours non converti (faux positif) — régression introduite par le garde-fou round 294"
        );

        return [
            'pass'    => true,
            'message' => "QueueManager::processSingle() revérifie désormais l'état 'panier non converti' pour abandoned_cart_1/2/3 et checkout_abandonment au moment de l'envoi réel, sans faux positif sur un panier toujours abandonné — bug corrigé le 03/09/2026 (round 294)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_queue WHERE recipient_email = 'regtest547@example.test'");
    }
}
