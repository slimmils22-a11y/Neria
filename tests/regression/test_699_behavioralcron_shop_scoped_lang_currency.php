<?php
/**
 * Régression : `BehavioralCronManager` résolvait `PS_LANG_DEFAULT` sans
 * `$idShop` explicite à 4 endroits (`sendPostPurchase()`,
 * `sendLifespanReminders()` chemin par client, `sendGhostCarts()`, et
 * `send()` — méthode partagée par ~15 templates comportementaux), alors
 * que `run()` boucle sur chaque boutique en réassignant `Context->shop`
 * SANS jamais appeler `Shop::setContext()` (seule méthode qui met
 * vraiment à jour `Shop::$context_id_shop`) — piège déjà documenté et
 * corrigé pour `PS_SHOP_NAME`/`PS_CURRENCY_DEFAULT` dans ces mêmes
 * méthodes (round 187), jamais porté à `PS_LANG_DEFAULT`. Un client avec
 * `id_lang` NULL/0 en base (import, désinstallation d'une langue)
 * recevait alors un email dans la langue par défaut de la boutique
 * AMBIANTE réelle plutôt que celle de sa propre boutique.
 *
 * `generateBirthdayVoucher()` avait la même incohérence pour
 * `minimum_amount_currency` (non scopé) vs `reduction_currency` (scopé)
 * dans la même méthode — même famille que le correctif
 * LoyaltyManager::generateVoucher() (round 336).
 *
 * Bug identifié le 11/09/2026 (round 338, audit AdminTranslator/
 * BehavioralCronManager).
 *
 * Corrigé le 11/09/2026 (round 338) : `$idShop` explicite ajouté aux 4
 * résolutions PS_LANG_DEFAULT concernées, et à minimum_amount_currency.
 *
 * Test structurel sur les 5 sites (comportement non testable end-to-end
 * dans cet environnement de dev mono-boutique — Configuration::get()
 * ignore silencieusement tout idShop explicite quand
 * Shop::isFeatureActive()===false, même limite déjà documentée pour ce
 * piège ailleurs dans la série, cf. test_188/test_695).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php');
    neria_assert($src !== false, 'Impossible de lire src/BehavioralCronManager.php');

    $checks = [
        'sendPostPurchase()' => "\\Configuration::get('PS_LANG_DEFAULT', null, null, \$idShop)",
        'sendLifespanReminders() (par client)' => "\\Configuration::get('PS_LANG_DEFAULT', null, null, (int) \$customer['id_shop'])",
        'sendGhostCarts()' => "\\Configuration::get('PS_LANG_DEFAULT', null, null, (int) \$r['id_shop'])",
        'send()' => "\\Configuration::get('PS_LANG_DEFAULT', null, null, \$idShop);",
    ];
    foreach ($checks as $label => $needle) {
        neria_assert(
            substr_count($src, $needle) >= 1,
            "BehavioralCronManager::{$label} ne transmet plus \$idShop explicite à Configuration::get('PS_LANG_DEFAULT', ...) — régression du bug corrigé le 11/09/2026 (round 338) : un client avec id_lang NULL/0 recevrait de nouveau un email dans la langue de la boutique ambiante réelle plutôt que la sienne"
        );
    }

    $posFn = strpos($src, 'private function generateBirthdayVoucher(int $idCustomer, \ConfigManager $config, int $idShop, ?int $year = null): string');
    neria_assert($posFn !== false, 'generateBirthdayVoucher() introuvable — jeu de test invalide');
    $body = substr($src, $posFn, 4200);
    neria_assert(
        strpos($body, "\$cartRule->minimum_amount_currency = (int) \\Configuration::get('PS_CURRENCY_DEFAULT', null, null, \$idShop);") !== false,
        "BehavioralCronManager::generateBirthdayVoucher() ne scope plus minimum_amount_currency par \$idShop — régression du bug corrigé le 11/09/2026 (round 338) : incohérence de nouveau introduite avec reduction_currency de la même méthode"
    );

    return [
        'pass'    => true,
        'message' => "BehavioralCronManager transmet désormais \$idShop explicite pour PS_LANG_DEFAULT (4 sites) et minimum_amount_currency (generateBirthdayVoucher()), cohérent avec PS_SHOP_NAME/PS_CURRENCY_DEFAULT déjà scopés dans les mêmes méthodes (round 187/336) — bug corrigé le 11/09/2026 (round 338)",
    ];
}
