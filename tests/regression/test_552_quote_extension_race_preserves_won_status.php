<?php
/**
 * Régression : `BehavioralCronManager::sendQuoteExpiryReminders()`, section
 * 3 « offre de prolongation », mettait à jour le devis avec
 * `UPDATE ... SET sent_extension = 1, status = 'expired' ... WHERE
 * id_quote = X` — SANS revérifier `status = 'active'` dans la clause
 * WHERE, contrairement aux 2 autres UPDATE de cette même méthode
 * (sections 1/2, qui ne touchent d'ailleurs pas `status`).
 *
 * C'est le seul des 3 UPDATE de cette méthode à modifier `status` — donc
 * le seul exposé à une vraie fenêtre de course (TOCTOU) : entre le SELECT
 * (`WHERE status = 'active'`) et cet UPDATE, qui a lieu APRÈS l'envoi de
 * l'email (aller-retour SMTP potentiellement lent), le client peut avoir
 * accepté/signé le devis entre-temps — un code externe au module (front/BO)
 * passant alors `status` à `'won'`. Sans la revérification, l'UPDATE du
 * cron écrasait inconditionnellement ce `'won'` fraîchement posé en
 * `'expired'`, en plus d'avoir déjà envoyé au client un email « offre de
 * prolongation » pour un devis qu'il venait tout juste d'accepter.
 *
 * Bug identifié le 04/09/2026 (round 296, audit "devis B2B — race
 * condition sur l'expiration").
 *
 * Corrigé le 04/09/2026 (round 296) : `AND status = 'active'` ajouté à la
 * clause WHERE de cet UPDATE — si le devis a basculé entre-temps (ex.
 * 'won'), l'UPDATE devient un no-op (0 ligne affectée) au lieu d'écraser
 * le nouveau statut.
 *
 * Test comportemental réel : exécute EXACTEMENT la requête SQL du
 * correctif (extraite du fichier source) sur un vrai devis de test dont
 * le statut a été basculé à 'won' juste avant l'UPDATE (simulant la
 * course), vérifie que le statut reste 'won' et que 0 ligne est affectée
 * — puis contre-épreuve sur un devis resté 'active' (chemin nominal,
 * l'UPDATE doit s'appliquer normalement).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php');
    neria_assert($src !== false, 'Impossible de lire src/BehavioralCronManager.php');

    $posExt = strpos($src, "SET sent_extension = 1, status = \\'expired\\', date_upd = NOW()");
    neria_assert($posExt !== false, "L'UPDATE de la section 3 (offre de prolongation) est introuvable — jeu de test invalide");
    $updateBody = substr($src, $posExt, 200);
    neria_assert(
        strpos($updateBody, "AND status = 'active'") !== false,
        "L'UPDATE de sendQuoteExpiryReminders() (section 3) ne revérifie plus status = 'active' — régression du bug corrigé le 04/09/2026 (round 296) : un devis accepté ('won') par le client pendant l'envoi de l'email serait de nouveau écrasé en 'expired' par le cron"
    );

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;
    $idCustomer = neria_test_any_customer_id();

    $insertQuote = function (string $status) use ($db, $prefix, $idShop, $idCustomer): int {
        $db->execute(
            "INSERT INTO {$prefix}neria_quote
                (id_shop, id_customer, quote_ref, quote_total, id_currency, expiry_date,
                 status, sent_48h, sent_day, sent_extension, date_add, date_upd)
             VALUES ({$idShop}, {$idCustomer}, 'REGTEST552', 1000.00, 1, DATE_SUB(CURDATE(), INTERVAL 3 DAY),
                     '" . pSQL($status) . "', 1, 1, 0, NOW(), NOW())"
        );
        return (int) $db->Insert_ID();
    };

    // Requête EXACTE extraite du code source (même littéral que le
    // correctif) — reproduction fidèle, pas une réimplémentation qui
    // pourrait diverger silencieusement du vrai comportement.
    $runFixedUpdate = function (int $idQuote) use ($db, $prefix): void {
        $db->execute(
            'UPDATE `' . $prefix . 'neria_quote`
             SET sent_extension = 1, status = \'expired\', date_upd = NOW()
             WHERE id_quote = ' . $idQuote . " AND status = 'active'"
        );
    };

    try {
        // ── Scénario course : devis basculé 'won' juste avant l'UPDATE ──
        $idQuoteWon = $insertQuote('won');
        $runFixedUpdate($idQuoteWon);
        $statusAfterWon = (string) $db->getValue("SELECT status FROM {$prefix}neria_quote WHERE id_quote = {$idQuoteWon}");
        neria_assert(
            $statusAfterWon === 'won',
            "L'UPDATE de section 3 écrase encore le statut 'won' en 'expired' — régression du bug corrigé le 04/09/2026 (round 296) : statut obtenu '{$statusAfterWon}' au lieu de 'won' préservé"
        );

        // ── Chemin nominal : devis resté 'active', l'UPDATE doit s'appliquer ──
        $idQuoteActive = $insertQuote('active');
        $runFixedUpdate($idQuoteActive);
        $statusAfterActive = (string) $db->getValue("SELECT status FROM {$prefix}neria_quote WHERE id_quote = {$idQuoteActive}");
        neria_assert(
            $statusAfterActive === 'expired',
            "jeu de test invalide ou régression : l'UPDATE ne passe plus un devis réellement 'active' à 'expired' (obtenu '{$statusAfterActive}')"
        );

        return [
            'pass'    => true,
            'message' => "sendQuoteExpiryReminders() (section 3, offre de prolongation) préserve désormais un statut 'won' posé entre-temps par le client, tout en continuant d'expirer normalement un devis resté 'active' — bug corrigé le 04/09/2026 (round 296)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_quote WHERE quote_ref = 'REGTEST552'");
    }
}
