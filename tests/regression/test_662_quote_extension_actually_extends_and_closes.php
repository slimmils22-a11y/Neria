<?php
/**
 * Régression : BehavioralCronManager::sendQuoteExpiryReminders() (section 3
 * « offre de prolongation ») envoyait un email `quote_extension_offer`
 * affichant explicitement au client B2B "{new_expiry_date}" = expiry_date +
 * 7 jours, mais le système ne réalisait jamais cette prolongation :
 * `status` passait directement à `'expired'` et `expiry_date` n'était
 * jamais modifiée — une promesse concrète (date précise) envoyée au client
 * que rien côté marchand ne matérialisait jamais (aucune action BO ne
 * permettait non plus de la matérialiser manuellement). La valeur ENUM
 * `'extended'` existait dans le schéma depuis l'origine (sql/install.sql)
 * mais n'était jamais assignée nulle part dans le code.
 *
 * Corrigé le 09/09/2026 (round 329, hors round) :
 *   - Section 3 : passe désormais réellement à status='extended' ET
 *     repousse expiry_date de 7 jours (DATE_ADD), exactement ce que
 *     l'email promet.
 *   - Nouvelle section 4 : clôture finale (status='expired', sans nouvel
 *     email) des devis 'extended' dont la NOUVELLE échéance est elle
 *     aussi dépassée — sans quoi un devis prolongé resterait "en jeu"
 *     indéfiniment dans getQuoteStats(). Aucune boucle possible : la
 *     section 3 ne cible que status='active', donc un devis 'extended' ne
 *     peut jamais recevoir une 2e offre de prolongation.
 *   - getQuoteStats() : 'extended' compté avec 'active' dans
 *     quotes_active (encore une opportunité ouverte), pas dans
 *     quotes_lost.
 *
 * Test comportemental réel : 3 devis de test — (A) actif et expiré depuis
 * hier avec sent_day=1 (candidat à la prolongation), (B) déjà 'extended'
 * dont la nouvelle échéance est dépassée (candidat à la clôture), (C)
 * déjà 'extended' dont la nouvelle échéance N'EST PAS dépassée (doit
 * rester 'extended', pas de clôture prématurée). Appelle
 * sendQuoteExpiryReminders() une fois, vérifie les 3 états finaux, puis
 * vérifie getQuoteStats().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $module     = neria_test_module();
    $idShop     = (int) Context::getContext()->shop->id;
    $idCustomer = neria_test_any_customer_id();
    $ref        = 'REGTEST662_' . uniqid();

    $wasEnabled = Configuration::getGlobalValue('NERIA_QUOTE_REMINDERS_ENABLED');
    Configuration::updateGlobalValue('NERIA_QUOTE_REMINDERS_ENABLED', 1);

    $insertQuote = function (string $ref, string $status, string $expiryExpr, int $sent48h, int $sentDay, int $sentExt) use ($db, $prefix, $idShop, $idCustomer): int {
        $db->execute(
            "INSERT INTO {$prefix}neria_quote
                (id_shop, id_customer, quote_ref, quote_total, id_currency, expiry_date,
                 status, sent_48h, sent_day, sent_extension, date_add, date_upd)
             VALUES ({$idShop}, {$idCustomer}, '{$ref}', 500.00, 1, {$expiryExpr},
                     '" . pSQL($status) . "', {$sent48h}, {$sentDay}, {$sentExt}, NOW(), NOW())"
        );
        return (int) $db->Insert_ID();
    };

    $refA = $ref . '_A';
    $refB = $ref . '_B';
    $refC = $ref . '_C';

    try {
        // A : active, expirée hier, sent_day=1 déjà -> candidate à la section 3.
        $idA = $insertQuote($refA, 'active', 'DATE_SUB(CURDATE(), INTERVAL 1 DAY)', 1, 1, 0);
        // B : déjà 'extended', nouvelle échéance DÉPASSÉE -> candidate à la section 4.
        $idB = $insertQuote($refB, 'extended', 'DATE_SUB(CURDATE(), INTERVAL 1 DAY)', 1, 1, 1);
        // C : déjà 'extended', nouvelle échéance PAS ENCORE dépassée -> doit rester 'extended'.
        $idC = $insertQuote($refC, 'extended', 'DATE_ADD(CURDATE(), INTERVAL 3 DAY)', 1, 1, 1);

        $mgr = new BehavioralCronManager($module);
        $mgr->sendQuoteExpiryReminders();

        $rowA = $db->getRow("SELECT status, expiry_date FROM {$prefix}neria_quote WHERE id_quote = {$idA}");
        neria_assert(
            $rowA['status'] === 'extended',
            "Devis A (actif, expiré, sent_day=1) n'est pas passé à 'extended' après sendQuoteExpiryReminders() (obtenu '{$rowA['status']}') — régression du bug corrigé le 09/09/2026 (round 329) : le système ne réaliserait plus la prolongation promise par l'email"
        );
        // expiry_date devait être hier ; +7 jours = dans 6 jours (>= aujourd'hui).
        $newExpiry = new DateTime($rowA['expiry_date']);
        $today     = new DateTime('today');
        neria_assert(
            $newExpiry > $today,
            "Devis A : expiry_date ({$rowA['expiry_date']}) n'a pas été repoussée de 7 jours comme promis par l'email — régression du bug corrigé le 09/09/2026 (round 329)"
        );

        $rowB = $db->getRow("SELECT status FROM {$prefix}neria_quote WHERE id_quote = {$idB}");
        neria_assert(
            $rowB['status'] === 'expired',
            "Devis B ('extended', nouvelle échéance dépassée) n'est pas passé à 'expired' — régression du bug corrigé le 09/09/2026 (round 329) : un devis prolongé resterait indéfiniment 'en jeu' sans jamais être clôturé"
        );

        $rowC = $db->getRow("SELECT status FROM {$prefix}neria_quote WHERE id_quote = {$idC}");
        neria_assert(
            $rowC['status'] === 'extended',
            "Devis C ('extended', nouvelle échéance PAS dépassée) a été clôturé prématurément (obtenu '{$rowC['status']}') — la clôture finale ne doit agir que sur une échéance réellement dépassée"
        );

        // getQuoteStats() : A et C doivent être comptés dans quotes_active
        // (encore en jeu), B dans quotes_lost (clôturé).
        $stats = $mgr->getQuoteStats();
        $activeCount = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_quote WHERE quote_ref IN ('{$refA}','{$refC}') AND id_shop = {$idShop} AND status IN ('active','extended')"
        );
        neria_assert($activeCount === 2, 'jeu de test invalide : A/C ne sont plus en active/extended');
        neria_assert(
            $stats['quotes_active'] >= 2,
            "getQuoteStats() ne compte plus les devis 'extended' dans quotes_active — régression du bug corrigé le 09/09/2026 (round 329) : un devis réellement prolongé basculerait à tort en 'quotes_lost' dès l'envoi de l'email de prolongation"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_quote WHERE quote_ref IN ('{$refA}','{$refB}','{$refC}')");
        Configuration::updateGlobalValue('NERIA_QUOTE_REMINDERS_ENABLED', $wasEnabled);
    }

    return [
        'pass'    => true,
        'message' => "sendQuoteExpiryReminders() prolonge réellement un devis (status='extended', expiry_date+7j) au lieu de le marquer 'expired' sans jamais tenir la promesse envoyée au client, puis le clôture correctement une fois la NOUVELLE échéance dépassée — bug corrigé le 09/09/2026 (round 329)",
    ];
}
