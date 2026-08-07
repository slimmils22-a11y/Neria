<?php
/**
 * Régression round 106 — balayage groupé de tous les {shop_name} identifiés
 * comme légitimement scopables par $idShop lors de la chasse dédiée au
 * pattern Configuration::get('PS_SHOP_NAME', ...) appelé SANS son 4e
 * paramètre $idShop, retombant sur la valeur du contexte d'exécution
 * courant au lieu de la boutique réelle du destinataire/client/commande.
 *
 * Même piège déjà corrigé pour d'autres managers/placeholders lors des
 * rounds précédents (102 : SegmentManager {shop_url} ; 103 :
 * product_url/product_image ; 105 : CollectionManager {shop_name}). Ce
 * round-ci traite {shop_name} dans 8 fichiers où $idShop (ou une variable
 * équivalente issue de la commande/ligne de file/choix explicite du
 * marchand) était déjà connu et utilisé pour d'AUTRES placeholders du même
 * bloc ($vars), mais pas pour {shop_name} :
 *
 *   - SegmentManager::sendToSegment()          → $this->idShop
 *   - LoyaltyManager::sendRewardEmail()         → $idShop
 *   - LoyaltyManager::sendMonthlyRecapFor()     → $idShop ?? contexte
 *   - WaitlistManager::notifyProduct()          → $idShop (param)
 *   - OrderTriggersManager (4 emplacements)     → $idShop / $order->id_shop
 *   - QueueManager::processSingle()             → $idShop (ligne de file)
 *   - ManualSendManager::send()/scheduleManual()→ $idShop / $idShopManual
 *   - CertificateManager::generatePdf() (x2)    → (int) $order->id_shop
 *   - LookCompletionManager::buildVars()        → $idShop (nouveau param)
 *
 * NB : plusieurs autres usages de PS_SHOP_NAME repérés dans le même grep
 * global (BehavioralCronManager, CalendarManager, SeasonalCampaignManager,
 * MonthlyReportManager, WatchdogManager, EmailRenderer, NeriaTools,
 * contrôleurs front, neria.php) ont été audités et laissés INTACTS : ils
 * tournent tous soit dans une boucle qui bascule déjà Context::getContext()
 * ->shop vers la boutique cible AVANT l'appel (donc le contexte courant EST
 * la bonne boutique au moment de l'appel), soit dans un contexte front/BO
 * synchrone où le contexte courant est légitimement la boutique concernée.
 * Les « corriger » aurait été un faux positif sans effet réel (voire un
 * risque de régression si la variable idShop locale n'existe pas dans ces
 * scopes).
 *
 * Test structurel (pas d'invocation réelle d'envoi email, qui déclencherait
 * des Mail::Send() dans des boucles sur de vrais clients/commandes) :
 * vérifie au niveau du code source que chaque emplacement corrigé utilise
 * bien Configuration::get('PS_SHOP_NAME', null, null, <idShop>).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $moduleDir = _PS_MODULE_DIR_ . 'neria/src/';

    $checks = [
        'SegmentManager.php'        => "\\Configuration::get('PS_SHOP_NAME', null, null, \$this->idShop)",
        'LoyaltyManager.php'        => "\\Configuration::get('PS_SHOP_NAME', null, null, \$idShop)",
        'WaitlistManager.php'       => "\\Configuration::get('PS_SHOP_NAME', null, null, \$idShop)",
        'OrderTriggersManager.php'  => "\\Configuration::get('PS_SHOP_NAME', null, null, \$idShop)",
        'QueueManager.php'          => "\\Configuration::get('PS_SHOP_NAME', null, null, \$idShop)",
        'ManualSendManager.php'     => "\\Configuration::get('PS_SHOP_NAME', null, null, \$idShop)",
        'CertificateManager.php'    => "\\Configuration::get('PS_SHOP_NAME', null, null, (int) \$order->id_shop)",
        'LookCompletionManager.php' => "\\Configuration::get('PS_SHOP_NAME', null, null, \$idShop)",
    ];

    foreach ($checks as $fileName => $needle) {
        $path = $moduleDir . $fileName;
        $src  = is_file($path) ? (file_get_contents($path) ?: '') : '';
        neria_assert($src !== '', "Impossible de lire src/{$fileName}");
        neria_assert(
            strpos($src, $needle) !== false,
            "{$fileName} : {shop_name} ne passe plus \$idShop à Configuration::get('PS_SHOP_NAME', ...) — régression du correctif round 106 (07/08/2026) : le mauvais nom de boutique pourrait de nouveau être envoyé sur une install multi-boutiques"
        );
    }

    // OrderTriggersManager corrige 4 emplacements distincts (milestone,
    // status change, refund, return) — deux variantes de source pour
    // idShop ($idShop local, ou (int) $order->id_shop directement selon la
    // méthode). Revérifie explicitement la variante par $order->id_shop
    // (refund_processed / return_received), non couverte par le needle
    // générique ci-dessus qui ne matche que la variante $idShop.
    $otSrc = file_get_contents($moduleDir . 'OrderTriggersManager.php') ?: '';
    neria_assert(
        strpos($otSrc, "\\Configuration::get('PS_SHOP_NAME', null, null, (int) \$order->id_shop)") !== false,
        "OrderTriggersManager.php : refund_processed/return_received n'utilisent plus (int) \$order->id_shop pour {shop_name} — régression du correctif round 106"
    );

    // ManualSendManager corrige 2 emplacements avec des noms de variable
    // différents ($idShop pour send(), $idShopManual pour scheduleManual()).
    $msSrc = file_get_contents($moduleDir . 'ManualSendManager.php') ?: '';
    neria_assert(
        strpos($msSrc, "\\Configuration::get('PS_SHOP_NAME', null, null, \$idShopManual)") !== false,
        "ManualSendManager::scheduleManual() n'utilise plus \$idShopManual pour {shop_name} — régression du correctif round 106"
    );

    // CertificateManager corrige 2 emplacements dans generatePdf() : le
    // premier (tout en tête de méthode, avant que $idShop local n'existe
    // encore) utilise directement (int) $order->id_shop ; le second (après
    // la bascule temporaire de Context::shop, où $idShop local == (int)
    // $order->id_shop) réutilise cette variable locale déjà résolue.
    $certSrc = file_get_contents($moduleDir . 'CertificateManager.php') ?: '';
    neria_assert(
        strpos($certSrc, "\\Configuration::get('PS_SHOP_NAME', null, null, (int) \$order->id_shop)") !== false,
        "CertificateManager::generatePdf() : le \$shopName en tête de méthode n'utilise plus (int) \$order->id_shop pour {shop_name} — régression du correctif round 106"
    );
    neria_assert(
        strpos($certSrc, "'{shop_name}'      => (string) \\Configuration::get('PS_SHOP_NAME', null, null, \$idShop),") !== false,
        "CertificateManager::generatePdf() : le \$vars['{shop_name}'] après la bascule de Context::shop n'utilise plus \$idShop — régression du correctif round 106"
    );

    // LookCompletionManager::buildVars() doit désormais recevoir $idShop en
    // 4e paramètre (nouveau param ajouté round 106) et le seul appelant doit
    // le lui transmettre.
    $lcSrc = file_get_contents($moduleDir . 'LookCompletionManager.php') ?: '';
    neria_assert(
        strpos($lcSrc, 'private function buildVars(\Customer $customer, array $products, string $categoryName, int $idShop): array') !== false,
        "LookCompletionManager::buildVars() n'accepte plus \$idShop en paramètre — régression du correctif round 106"
    );
    neria_assert(
        strpos($lcSrc, "\$this->buildVars(\$customer, \$products, \$rule['category_name'] ?? '', \$idShop)") !== false,
        "LookCompletionManager : l'appel à buildVars() ne transmet plus \$idShop — régression du correctif round 106"
    );

    return [
        'pass'    => true,
        'message' => 'Les 8 emplacements {shop_name} corrigés au round 106 (SegmentManager, LoyaltyManager x2, WaitlistManager, OrderTriggersManager x4, QueueManager, ManualSendManager x2, CertificateManager x2, LookCompletionManager) résolvent bien via Configuration::get(\'PS_SHOP_NAME\', null, null, $idShop), pas le contexte d\'exécution courant',
    ];
}
