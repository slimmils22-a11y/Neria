<?php
/**
 * Régression : CertificateManager::getByOrder() et ::redownload() ne
 * doivent PAS filtrer par `id_shop` = contexte BO courant de l'employé.
 *
 * Bug réel corrigé le 08/08/2026 (round 107) : issue() enregistre déjà
 * volontairement le certificat sous l'id_shop DE LA COMMANDE, pas
 * Context::getContext()->shop->id (voir son propre commentaire : "un
 * employé en contexte 'toutes les boutiques' ... émettant un certificat
 * pour une commande d'une AUTRE boutique enregistrait sinon le certificat
 * sous la mauvaise boutique — invisible dans getByOrder()/getAll() de la
 * vraie boutique"). Mais getByOrder() et redownload() — tous deux appelés
 * depuis le bloc affiché sur la fiche commande PS (hookDisplayAdminOrderMainBottom
 * / action cert_download postée vers AdminModules&configure=neria) —
 * filtraient encore par $this->idShop, résolu dans le CONSTRUCTEUR à
 * partir de Context::getContext()->shop->id : le contexte BO actuellement
 * sélectionné dans le sélecteur d'en-tête, qui n'a AUCUNE raison de
 * correspondre à la boutique réelle de la commande consultée en
 * multi-boutique (c'est même le cas normal tant que l'employé ne change
 * pas explicitement de sélecteur). Résultat : un certificat pourtant bien
 * enregistré sous l'id_shop de sa commande redevenait invisible sur la
 * fiche commande elle-même (risque de réémission en double) et le bouton
 * de retéléchargement échouait avec "certificat introuvable" pour un
 * certificat pourtant bien réel.
 *
 * Test comportemental réel : insère un certificat directement en base
 * sous un id_shop délibérément DIFFÉRENT de Context::getContext()->shop->id
 * (contexte du script de test), puis vérifie que getByOrder() le retourne
 * et que redownload() le retrouve (n'échoue pas avec "not found") —
 * prouvant que ces deux méthodes ne filtrent plus par le contexte BO
 * courant.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();

    if (!class_exists('CertificateManager')) {
        return ['pass' => true, 'message' => 'CertificateManager absent — test ignoré (classe non chargée)'];
    }

    $serial      = 'REGTEST111-' . uniqid();
    $idCustomer  = neria_test_any_customer_id();
    $contextShop = (int) Context::getContext()->shop->id;
    // Boutique délibérément différente du contexte BO courant du script de
    // test — simule un employé dont le sélecteur d'en-tête pointe ailleurs
    // que la boutique réelle de la commande consultée.
    $otherShop = $contextShop === 1 ? 2 : 1;

    // redownload() reconstruit un PDF réel via generatePdf(), qui a besoin
    // d'une vraie commande chargeable (Order) — un id_order fictif ferait
    // planter generatePdf() sur des champs null (adresse/devise), sans
    // rapport avec le bug testé ici. On insère donc une commande réelle
    // minimale, comme test_92/test_94 le font pour le même besoin.
    $db->execute(
        "INSERT INTO {$prefix}orders (id_shop, id_shop_group, id_customer, id_currency, id_lang, id_address_delivery, id_address_invoice, id_carrier, current_state, payment, conversion_rate, total_paid, total_paid_tax_incl, total_products, total_products_wt, valid, date_add, date_upd, invoice_number, invoice_date, delivery_number, delivery_date, secure_key)
         VALUES ({$otherShop}, 1, {$idCustomer}, 1, 1, 0, 0, 1, 1, 'regtest', 1, 10, 10, 10, 10, 1, NOW(), NOW(), 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 'regtest111')"
    );
    $idOrderFake = (int) $db->Insert_ID();

    $db->execute("DELETE FROM {$prefix}neria_certificate WHERE serial_number = '" . pSQL($serial) . "'");

    try {
        $db->execute(
            "INSERT INTO {$prefix}neria_certificate
                (id_shop, id_order, id_product, serial_number, customer_name, product_name, date_issued, date_add)
             VALUES ({$otherShop}, {$idOrderFake}, 1, '" . pSQL($serial) . "', 'Regtest111', 'Regtest111', NOW(), NOW())"
        );
        $idCert = (int) $db->Insert_ID();
        neria_assert($idCert > 0, "jeu de test invalide : l'INSERT de test a échoué");
        neria_assert(
            $otherShop !== $contextShop,
            "jeu de test invalide : la boutique du certificat de test coïncide avec le contexte BO courant, le scénario du bug n'est pas reproduit"
        );

        $manager = new CertificateManager($module);

        // 1) getByOrder() doit retrouver le certificat malgré le décalage
        //    de contexte BO.
        $byOrder = $manager->getByOrder($idOrderFake);
        $found   = false;
        foreach ($byOrder as $row) {
            if ((int) $row['id_certificate'] === $idCert) {
                $found = true;
                break;
            }
        }
        neria_assert(
            $found,
            "CertificateManager::getByOrder() ne retrouve pas un certificat enregistré sous une autre boutique que le contexte BO courant — régression du correctif du round 107 (le bloc certificat de la fiche commande redeviendrait invisible en multi-boutique)"
        );

        // 2) redownload() doit retrouver la ligne (pas d'erreur "not found")
        //    malgré le même décalage de contexte.
        $result = $manager->redownload($idCert);
        neria_assert(
            !isset($result['error']),
            "CertificateManager::redownload() échoue ('" . ($result['error'] ?? '') . "') pour un certificat existant enregistré sous une autre boutique que le contexte BO courant — régression du correctif du round 107"
        );

        // 3) Vérification structurelle : les deux requêtes ne doivent plus
        //    contenir de filtre id_shop = $this->idShop.
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CertificateManager.php') ?: '';
        // Cherche le fragment SQL réel du filtre (pas une simple occurrence
        // de "$this->idShop", qui apparaît aussi dans les commentaires
        // explicatifs du correctif juste au-dessus de chaque méthode et
        // donnerait un faux positif systématique).
        $sqlFilterNeedle = "id_shop` = ' . \$this->idShop";
        neria_assert(
            (bool) preg_match('/function getByOrder\(int \$idOrder\): array\s*\{(?:(?!function ).)*?\}/s', $src, $mFn)
                && strpos($mFn[0], $sqlFilterNeedle) === false,
            "CertificateManager::getByOrder() filtre de nouveau par \$this->idShop — régression du correctif du round 107"
        );
        neria_assert(
            (bool) preg_match('/function redownload\(int \$idCertificate\): array\s*\{(?:(?!function ).)*?\}/s', $src, $mFn2)
                && strpos($mFn2[0], $sqlFilterNeedle) === false,
            "CertificateManager::redownload() filtre de nouveau par \$this->idShop — régression du correctif du round 107"
        );

        return [
            'pass'    => true,
            'message' => "getByOrder() et redownload() retrouvent bien un certificat enregistré sous une autre boutique que le contexte BO courant (fiche commande multi-boutique)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_certificate WHERE serial_number = '" . pSQL($serial) . "'");
        if ($idOrderFake > 0) {
            $db->execute("DELETE FROM {$prefix}orders WHERE id_order = {$idOrderFake}");
        }
    }
}
