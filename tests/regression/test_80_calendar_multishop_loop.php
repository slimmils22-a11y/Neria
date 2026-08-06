<?php
/**
 * Régression : neria.php::runBackgroundJobs() doit instancier
 * CalendarManager DANS une boucle sur Shop::getShops(), avec bascule du
 * contexte — pas une seule fois, sinon seule la boutique du premier
 * visiteur front du jour reçoit les emails calendaires.
 *
 * Bug réel corrigé le 06/08/2026 (round 76) : CalendarManager capture
 * $this->idShop dans son constructeur et scope TOUTES ses requêtes
 * (clients éligibles, throttle "déjà envoyé") sur cette seule boutique —
 * même défaut déjà corrigé au round 49 pour Segment/Churn/Propensity dans
 * BehavioralCronManager::run(). Sans boucle par boutique, les boutiques
 * autres que celle du premier visiteur du jour ne recevaient JAMAIS
 * aucune campagne calendaire, aucun jour.
 *
 * Non vérifiable en conditions multi-boutiques réelles sur cet
 * environnement de dev (une seule boutique configurée) — même limite que
 * test_37/test_40/test_58. Vérifie donc au niveau du code source que la
 * boucle par boutique est bien en place.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $pos = strpos($src, "if (class_exists('CalendarManager')) {");
    neria_assert($pos !== false, "bloc CalendarManager introuvable dans runBackgroundJobs()");

    $block = substr($src, $pos, 1400);

    neria_assert(
        strpos($block, '\Shop::getShops(true, null, true)') !== false,
        "runBackgroundJobs() ne boucle plus sur Shop::getShops() pour CalendarManager — régression du bug corrigé le 06/08/2026 (round 76) : seule la boutique du premier visiteur front du jour recevrait de nouveau les emails calendaires"
    );
    neria_assert(
        strpos($block, 'foreach ($shopsCalendar as $idShopCalendar) {') !== false
        && strpos($block, 'new \Shop((int) $idShopCalendar)') !== false
        && strpos($block, 'new CalendarManager($this)') !== false,
        "runBackgroundJobs() n'instancie plus CalendarManager à l'intérieur de la boucle par boutique avec bascule du contexte — régression du bug corrigé le 06/08/2026 (round 76)"
    );

    return ['pass' => true, 'message' => "runBackgroundJobs() instancie bien CalendarManager dans une boucle sur toutes les boutiques actives"];
}
