<?php
/**
 * Régression : MonthlyReportManager::getRecipients() doit résoudre
 * CONFIG_RECIPIENTS et PS_SHOP_EMAIL via $this->idShop, comme toutes les
 * requêtes KPI du même fichier.
 *
 * Bug réel corrigé le 09/08/2026 (round 145) : getRecipients() ne
 * transmettait jamais $this->idShop à Configuration::get() — dans la
 * boucle multi-boutique de checkAndSend(), le rapport de la Boutique B
 * pouvait être envoyé aux destinataires configurés pour la Boutique A (ou
 * l'inverse), fuite d'information commerciale entre équipes gérant des
 * boutiques distinctes de la même installation.
 *
 * Test structurel assumé explicitement : Configuration::get() ignore
 * silencieusement tout idShop explicite en installation mono-boutique (cf.
 * leçon rounds 141/142/144) — impossible de distinguer par le comportement
 * dans cet environnement. Vérifie donc que les 2 lectures de
 * getRecipients() transmettent bien $this->idShop.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/MonthlyReportManager.php');
    neria_assert($src !== false, 'Impossible de lire src/MonthlyReportManager.php');

    $posMethod = strpos($src, 'private function getRecipients(): array');
    neria_assert($posMethod !== false, 'getRecipients() introuvable — jeu de test invalide');

    $body = substr($src, $posMethod, 1200);

    neria_assert(
        strpos($body, "\Configuration::get(self::CONFIG_RECIPIENTS, null, null, \$this->idShop)") !== false,
        "getRecipients() ne transmet plus \$this->idShop à Configuration::get(CONFIG_RECIPIENTS) — régression du bug corrigé le 09/08/2026 (round 145) : le rapport d'une boutique pourrait de nouveau partir aux destinataires configurés pour une autre boutique"
    );
    neria_assert(
        strpos($body, "\Configuration::get('PS_SHOP_EMAIL', null, null, \$this->idShop)") !== false,
        "getRecipients() ne transmet plus \$this->idShop au repli PS_SHOP_EMAIL — régression du bug corrigé le 09/08/2026 (round 145)"
    );

    return [
        'pass'    => true,
        'message' => "MonthlyReportManager::getRecipients() résout bien CONFIG_RECIPIENTS/PS_SHOP_EMAIL via \$this->idShop",
    ];
}
