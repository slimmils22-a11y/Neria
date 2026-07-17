<?php
/**
 * Régression : le hook universel doit toujours consulter PreferencesManager
 * pour tout template catégorisé — le bug le plus significatif de la session
 * du 17/07/2026 (commit bda05f0) : le centre de préférences n'était
 * réellement appliqué que pour BehavioralCronManager, tous les autres
 * émetteurs l'ignoraient totalement.
 *
 * Teste PreferencesManager::isAllowed() directement (déterministe, non
 * affecté par le cooldown/bounce/historique d'envoi réel de la boutique de
 * dev) + vérifie par motif de code que le hook universel consulte toujours
 * ce garde-fou avant tout envoi catégorisé.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $email = (string) $db->getValue("SELECT email FROM {$prefix}customer WHERE id_customer={$idCustomer}");

    $db->execute("DELETE FROM {$prefix}neria_preferences WHERE id_customer={$idCustomer} AND id_shop=1 AND category='season'");
    $db->execute("INSERT INTO {$prefix}neria_preferences (id_shop, id_customer, email, category, subscribed, date_upd)
        VALUES (1, {$idCustomer}, '{$email}', 'season', 0, NOW())");

    try {
        require_once _PS_MODULE_DIR_ . 'neria/src/PreferencesManager.php';
        $pm = new PreferencesManager(neria_test_module());

        neria_assert(
            $pm->isAllowed($idCustomer, 'christmas') === false,
            "isAllowed('christmas') devrait être false pour un client opt-out de la catégorie 'season'"
        );
        neria_assert(
            $pm->isAllowed($idCustomer, 'quote_expiry_48h') === true,
            "isAllowed('quote_expiry_48h') (catégorie b2b, toujours abonné) devrait rester true — faux positif du garde-fou"
        );

        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
        neria_assert(
            str_contains($src, 'PreferencesManager::TEMPLATE_CAT') && str_contains($src, '->isAllowed('),
            "hookActionEmailSendBeforeImpl() ne consulte plus PreferencesManager — régression du bug corrigé le 17/07/2026 (commit bda05f0), le centre de préférences redeviendrait cosmétique pour ~40 templates"
        );

        return ['pass' => true, 'message' => 'Application des préférences RGPD toujours centralisée et opérante'];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_preferences WHERE id_customer={$idCustomer} AND id_shop=1 AND category='season'");
    }
}
