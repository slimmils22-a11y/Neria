<?php
/**
 * Régression : CalendarManager::setManualOverride()/getManualOverride()/
 * getDateSource()/getAllManualOverrides() doivent être scopés par boutique.
 *
 * Bug réel corrigé le 09/08/2026 (round 142) : la clé de config des
 * overrides manuels (NERIA_CAL_DATE_{EVENT}_{YEAR}) n'incluait aucun
 * suffixe boutique, contrairement à buildSentKey() (round 40, suffixe
 * SHOP{idShop}). Sans lui, Configuration::get()/updateValue() retombent sur
 * le static Shop::$context_id_shop, qui reste figé sur la boutique
 * d'origine pendant la boucle multi-boutique de
 * neria.php::runBackgroundJobs() (simple réassignation de Context->shop,
 * jamais Shop::setContext()) — un override saisi pour la Boutique B était
 * silencieusement ignoré si le cron avait démarré dans le contexte de la
 * Boutique A. getAllManualOverrides() mélangeait en plus les overrides de
 * TOUTES les boutiques dans le tableau BO d'une seule.
 *
 * Test comportemental réel : pose un override pour la boutique courante
 * (id_shop réel) ET un autre pour une boutique "étrangère" simulée via SQL
 * brut (même technique que test_179/180/181 — Configuration::updateValue()
 * ignore silencieusement tout $id_shop explicite sur une installation
 * mono-boutique), vérifie que getDateSource()/getManualOverride() (via
 * Reflection) ne voient QUE l'override de la boutique courante, et que
 * getAllManualOverrides() ne renvoie pas celui de la boutique étrangère.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CalendarManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();

    $eventKey    = 'neria_test_round142_evt';
    $year        = 2099; // hors plage réelle, pas de collision possible
    $idShopOwn   = (int) \Context::getContext()->shop->id;
    $idShopOther = $idShopOwn + 1000;

    $keyOwn   = 'NERIA_CAL_DATE_' . strtoupper($eventKey) . '_' . $year . '_SHOP' . $idShopOwn;
    $keyOther = 'NERIA_CAL_DATE_' . strtoupper($eventKey) . '_' . $year . '_SHOP' . $idShopOther;

    $calendar = new CalendarManager($module);

    try {
        $ok = $calendar->setManualOverride($eventKey, $year, '2099-06-15');
        neria_assert($ok === true, "setManualOverride() a échoué sur un jeu de données valide — jeu de test invalide");

        $db->execute(
            "INSERT INTO {$prefix}configuration (id_shop_group, id_shop, name, value, date_add, date_upd)
             VALUES (NULL, {$idShopOther}, '{$keyOther}', '2099-07-20', NOW(), NOW())"
        );

        // getDateSource() ne doit voir que l'override de la boutique courante
        $source = $calendar->getDateSource($eventKey, $year);
        neria_assert(
            $source === 'manuel',
            "getDateSource() ne détecte pas l'override de la boutique courante (source='{$source}') — jeu de test invalide ou clé mal scopée"
        );

        // getManualOverride() (privée) doit renvoyer la date de la boutique
        // courante, pas celle de la boutique étrangère
        $ref = new ReflectionMethod(CalendarManager::class, 'getManualOverride');
        $ref->setAccessible(true);
        $date = $ref->invoke($calendar, $eventKey, $year);
        neria_assert(
            $date instanceof \DateTime && $date->format('Y-m-d') === '2099-06-15',
            "getManualOverride() n'a pas renvoyé la date de la boutique courante (obtenu : " . ($date ? $date->format('Y-m-d') : 'null') . ") — régression du bug corrigé le 09/08/2026 (round 142)"
        );

        // getAllManualOverrides() ne doit pas remonter l'override de la boutique étrangère
        $all = $calendar->getAllManualOverrides();
        $foundOther = false;
        $foundOwn   = false;
        foreach ($all as $row) {
            if ($row['config_key'] === $keyOther) {
                $foundOther = true;
            }
            if ($row['config_key'] === $keyOwn) {
                $foundOwn = true;
            }
        }
        neria_assert(
            !$foundOther,
            "getAllManualOverrides() remonte l'override de la boutique étrangère (id_shop={$idShopOther}) — régression du bug corrigé le 09/08/2026 (round 142) : le tableau BO mélangerait de nouveau les overrides de toutes les boutiques"
        );
        neria_assert($foundOwn, "getAllManualOverrides() ne remonte pas l'override de la boutique courante — jeu de test invalide");
    } finally {
        $db->execute("DELETE FROM {$prefix}configuration WHERE name IN ('{$keyOwn}', '{$keyOther}')");
    }

    return [
        'pass'    => true,
        'message' => "CalendarManager scope bien ses overrides manuels par boutique (clé SHOP{idShop}) — plus de fuite ni d'ignorance cross-boutique",
    ];
}
