<?php
/**
 * Régression : GdprAuditManager::encryptExistingRecords() doit rester
 * scopé par boutique, comme le reste du fichier (auditRetention(),
 * purgeTable(), auditEncryption()).
 *
 * Bug réel corrigé le 09/08/2026 (round 144) : contrairement à tout le
 * reste de ce fichier, cette méthode ne filtrait jamais id_shop sur
 * neria_stat — un marchand déclenchant "Chiffrer les enregistrements
 * existants" depuis sa boutique chiffrait aussi les lignes de TOUTES les
 * autres boutiques, effet de bord cross-boutique non maîtrisé.
 *
 * Test comportemental réel : pose une ligne neria_stat en clair pour une
 * boutique "étrangère" simulée (id_shop courant + 1000) et une pour la
 * boutique courante, appelle encryptExistingRecords(), vérifie que SEULE
 * la ligne de la boutique courante a été chiffrée.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/GdprAuditManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/CryptoManager.php';

    if (!class_exists('CryptoManager') || !\CryptoManager::isAvailable()) {
        return [
            'pass'    => true,
            'message' => 'Test ignoré : clé de chiffrement NERIA_ENCRYPTION_KEY non disponible sur cet environnement',
        ];
    }

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShopOwn   = (int) \Context::getContext()->shop->id;
    $idShopOther = $idShopOwn + 1000;

    $token = 'regtest_round144_' . uniqid();

    $db->execute(
        "INSERT INTO {$prefix}neria_stat
            (id_shop, template, lang, country_code, id_customer, id_order, ref_scope, tracking_token, event_type, is_mpp, abtest_variant, rendered_vars, revenue, ip_address, user_agent, date_add)
         VALUES ({$idShopOwn}, 'vip', 'fr', '', 0, 0, '', '{$token}_own', 'sent', 0, '', 'plain_value_own', 0, '', '', NOW())"
    );
    $idOwn = (int) $db->Insert_ID();

    $db->execute(
        "INSERT INTO {$prefix}neria_stat
            (id_shop, template, lang, country_code, id_customer, id_order, ref_scope, tracking_token, event_type, is_mpp, abtest_variant, rendered_vars, revenue, ip_address, user_agent, date_add)
         VALUES ({$idShopOther}, 'vip', 'fr', '', 0, 0, '', '{$token}_other', 'sent', 0, '', 'plain_value_other', 0, '', '', NOW())"
    );
    $idOther = (int) $db->Insert_ID();

    try {
        neria_assert($idOwn > 0 && $idOther > 0, 'jeu de test invalide : INSERT échoué');

        $mgr = new GdprAuditManager(_PS_MODULE_DIR_ . 'neria');
        $mgr->encryptExistingRecords();

        $valOwn = (string) $db->getValue("SELECT rendered_vars FROM {$prefix}neria_stat WHERE id_stat = {$idOwn}");
        $valOther = (string) $db->getValue("SELECT rendered_vars FROM {$prefix}neria_stat WHERE id_stat = {$idOther}");

        neria_assert(
            strpos($valOwn, 'ENC:') === 0,
            "la ligne de la boutique courante n'a pas été chiffrée (valeur : '{$valOwn}') — jeu de test invalide"
        );
        neria_assert(
            $valOther === 'plain_value_other',
            "la ligne de la boutique étrangère (id_shop={$idShopOther}) a été chiffrée par une action déclenchée depuis la boutique courante (id_shop={$idShopOwn}) — régression du bug corrigé le 09/08/2026 (round 144) : encryptExistingRecords() n'est de nouveau plus scopé par boutique"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_stat IN ({$idOwn}, {$idOther})");
    }

    return [
        'pass'    => true,
        'message' => "GdprAuditManager::encryptExistingRecords() reste bien scopé par boutique",
    ];
}
