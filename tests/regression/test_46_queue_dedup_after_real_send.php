<?php
/**
 * Régression : la dédup neria_behavioral_sent pour un envoi routé par la
 * fenêtre d'achat individuelle (QueueManager) ne doit être posée QU'APRÈS
 * un envoi réellement réussi — pour TOUT template, pas seulement
 * first_anniversary/relationship_anniversary.
 *
 * Bug réel corrigé le 05/08/2026 (round 51) : BehavioralCronManager::send()
 * posait la dédup immédiatement à la mise en file (enqueue()), avant même
 * la tentative d'envoi. Si les 3 tentatives de QueueManager::processSingle()
 * échouaient définitivement (SMTP en panne), le template restait marqué
 * "déjà envoyé" pour de bon — le client ne recevait jamais l'email et le
 * cron ne le retentait plus jamais, silencieusement, pour n'importe quel
 * template comportemental passé par cette file (ghost_cart, win_back,
 * birthday...), pas seulement les anniversaires (seul cas déjà correct).
 *
 * Ce test appelle processSingle() (privée, via réflexion) avec une ligne de
 * file fabriquée pour un template NON-anniversaire, déclenche un VRAI envoi
 * via Mailpit (SMTP local de dev), et vérifie que neria_behavioral_sent est
 * bien peuplé après ce succès réel — la généralisation du round 51.
 *
 * Round 260 : ref_id doit désormais être un VRAI id_product actif — depuis
 * le correctif round 260 (péremption du prix/produit ghost_cart en file),
 * processSingle() réinstancie ce produit via ref_id pour ce template précis
 * et bloque l'envoi si Product::isLoadedObject() échoue. Un ref_id fabriqué
 * (ancien random_int(999001, 999999)) ferait désormais échouer ce test pour
 * une raison sans rapport avec son objet réel (dédup après envoi), pas une
 * régression du round 51.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/QueueManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $template   = 'ghost_cart';
    // Round 260 : doit être un VRAI produit actif (voir docblock ci-dessus).
    $refId      = (int) $db->getValue(
        "SELECT p.id_product FROM {$prefix}product p
         INNER JOIN {$prefix}product_shop ps ON ps.id_product = p.id_product AND ps.active = 1"
    );
    $idShop     = (int) Context::getContext()->shop->id;
    neria_assert($refId > 0, "jeu de test invalide : aucun produit actif trouvé pour fabriquer un ref_id réaliste");
    // Round 260 : id_lang volontairement DIFFÉRENT de Context::language
    // (id 1 dans ce bootstrap CLI) — processSingle() recalcule désormais
    // {product_price} via NeriaTools::displayPrice(..., $idLang), qui
    // bascule sur le chemin NumberFormatter (indépendant du conteneur
    // Symfony) uniquement quand $idLang diffère du contexte ; sinon il
    // retombe sur \Tools::displayPrice() natif, lequel requiert un
    // conteneur Symfony absent de ce bootstrap de test minimal (même
    // contrainte déjà contournée par test_103_display_price_respects_idlang).
    $idLangDiff = (int) $db->getValue(
        "SELECT id_lang FROM {$prefix}lang WHERE active = 1 AND id_lang != " . (int) Configuration::get('PS_LANG_DEFAULT')
    );
    $idLang     = $idLangDiff > 0 ? $idLangDiff : (int) Configuration::get('PS_LANG_DEFAULT');

    // Vide toute trace résiduelle d'un run précédent pour cette clé.
    $db->execute("DELETE FROM {$prefix}neria_behavioral_sent WHERE id_customer = {$idCustomer} AND template = '{$template}' AND ref_id = {$refId}");

    $db->execute(
        "INSERT INTO {$prefix}neria_queue
            (id_customer, id_shop, id_lang, template, recipient_email, recipient_name,
             vars_json, ref_id, send_at, status, attempts, created_at)
         VALUES ({$idCustomer}, {$idShop}, {$idLang}, '{$template}',
                 'regtest-46@example.com', 'Regtest',
                 '{}', {$refId}, NOW(), 'pending', 0, NOW())"
    );
    $idQueue = (int) $db->Insert_ID();

    try {
        $mgr = new QueueManager(neria_test_module());
        $ref = new ReflectionMethod($mgr, 'processSingle');
        $ref->setAccessible(true);

        $row = $db->getRow("SELECT * FROM {$prefix}neria_queue WHERE id_neria_queue = {$idQueue}");
        neria_assert($row !== false, "ligne de file introuvable juste après insertion");

        $sent = $ref->invoke($mgr, $row);
        neria_assert($sent === true, "processSingle() n'a pas réussi l'envoi réel via Mailpit — vérifier que le service SMTP local tourne (PS_MAIL_METHOD=2, localhost:1025)");

        $dedupCount = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_behavioral_sent
             WHERE id_customer = {$idCustomer} AND template = '{$template}' AND ref_id = {$refId}"
        );
        neria_assert(
            $dedupCount === 1,
            "neria_behavioral_sent n'a pas été peuplé après un envoi réel réussi pour un template NON-anniversaire — régression de la généralisation corrigée le 05/08/2026 (round 51) ; la dédup resterait de nouveau limitée à first_anniversary/relationship_anniversary"
        );

        return [
            'pass'    => true,
            'message' => 'QueueManager::processSingle() pose bien la dédup après succès réel, pour un template non-anniversaire',
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_queue WHERE id_neria_queue = {$idQueue}");
        $db->execute("DELETE FROM {$prefix}neria_behavioral_sent WHERE id_customer = {$idCustomer} AND template = '{$template}' AND ref_id = {$refId}");
    }
}
