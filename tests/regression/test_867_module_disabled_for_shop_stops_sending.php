<?php
/**
 * Régression (P10, 25/09/2026, constatée sur ps-test) : Neria désactivé pour UNE boutique (module_shop) continuait d'y
 * envoyer (file d'envoi et crons bouclent sur toutes les boutiques actives, sans contrôle du module par boutique).
 *
 * Corrigé : NeriaTools::activeShopIds() (boutiques actives où le module est activé) utilisé par les boucles de crons ;
 * QueueManager::processQueue() ne prend que les lignes de ces boutiques (les autres restent en attente).
 *
 * Test : comportement du helper sur la base de test (module activé sur la boutique courante → présente ; ligne module_shop
 * retirée en simulation multi-boutique impossible en mono-boutique → contrôle structurel des appelants).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;
    $ids = NeriaTools::activeShopIds();
    neria_assert(in_array($idShop, $ids, true), 'activeShopIds() ne contient pas la boutique courante : ' . json_encode($ids));
    neria_assert($ids === array_map('intval', array_values((array) Shop::getShops(true, null, true))) || Shop::isFeatureActive(), 'En mono-boutique, activeShopIds() doit égaler Shop::getShops()');

    $root = _PS_MODULE_DIR_ . 'neria/';
    $read = static function (string $f) use ($root): string {
        return str_replace("\r\n", "\n", (string) file_get_contents($root . $f));
    };
    $main = $read('neria.php');
    neria_assert(substr_count($main, '\NeriaTools::activeShopIds() ?:') >= 5, 'Les boucles de crons de neria.php n\'utilisent plus activeShopIds()');
    foreach (['src/BehavioralCronManager.php', 'src/LoyaltyManager.php', 'src/MonthlyReportManager.php'] as $f) {
        neria_assert(str_contains($read($f), '\NeriaTools::activeShopIds() ?:'), "{$f} n'utilise plus activeShopIds()");
    }
    neria_assert(str_contains($read('src/QueueManager.php'), "WHERE status = \'pending\'' . \$shopFilter391"), 'processQueue() ne filtre plus les boutiques où le module est activé');

    return ['pass' => true, 'message' => "Les crons et la file d'envoi ignorent les boutiques où le module est désactivé (lignes laissées en attente) — corrigé le 25/09/2026 (P10)"];
}
