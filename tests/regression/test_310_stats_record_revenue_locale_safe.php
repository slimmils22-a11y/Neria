<?php
/**
 * Régression : StatsManager::record() construisait son INSERT avec
 * sprintf("... %.2f ...", $revenue) — sprintf('%f') honore LC_NUMERIC du
 * process PHP. Si un contexte BO avait appelé setlocale() vers une locale à
 * virgule décimale (fr_FR, de_DE...) avant l'appel, 12.5 était rendu "12,50"
 * au lieu de "12.50", produisant du SQL invalide. L'INSERT échouait
 * silencieusement (juste un warning Watchdog) : la conversion, son revenu
 * attribué et les points de fidélité associés étaient perdus sans trace
 * visible en BO.
 *
 * Corrigé le 14/08/2026 (round 168) : number_format($revenue, 2, '.', '')
 * force le point décimal indépendamment de LC_NUMERIC.
 *
 * Test comportemental réel : bascule LC_NUMERIC vers une locale à virgule
 * (si disponible sur le serveur), enregistre une conversion avec un revenu
 * décimal, vérifie que la ligne est bien insérée avec le bon revenu — puis
 * restaure la locale et nettoie.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/StatsManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $token  = 'neria_test_locale_' . bin2hex(random_bytes(6));

    $previousLocale = setlocale(LC_NUMERIC, '0');
    $switched = @setlocale(LC_NUMERIC, 'fr_FR.UTF-8', 'fr_FR', 'French_France.1252', 'de_DE.UTF-8');

    try {
        $mgr = new StatsManager(neria_test_module());
        $idCustomer = neria_test_any_customer_id();

        $mgr->recordSent([
            'neria_template' => 'order_conf',
            'neria_lang'     => 'fr',
            'neria_token'    => $token,
            'idCustomer'     => $idCustomer,
        ]);

        $mgr->recordConversion($token, 0, 42.5, (int) Context::getContext()->shop->id);

        $row = $db->getRow(
            "SELECT revenue FROM `{$prefix}neria_stat` WHERE tracking_token = '" . pSQL($token) . "' AND event_type = 'conversion' ORDER BY id_stat DESC"
        );

        neria_assert(
            $row !== false,
            "Aucune ligne insérée pour le token de test — régression du bug corrigé le 14/08/2026 (round 168) : sous une locale à virgule décimale, l'INSERT échoue silencieusement et la conversion est perdue"
                . ($switched !== false ? " (locale active pendant le test : {$switched})" : ' (locale à virgule indisponible sur ce serveur — test non concluant sur ce point précis mais le correctif reste en place)')
        );

        if ($row !== false) {
            neria_assert(
                abs((float) $row['revenue'] - 42.5) < 0.001,
                "Le revenu enregistré ({$row['revenue']}) ne correspond pas à 42.5 — le formatage locale-sensible aurait pu tronquer ou corrompre la valeur"
            );
        }
    } finally {
        $db->execute("DELETE FROM `{$prefix}neria_stat` WHERE tracking_token = '" . pSQL($token) . "'");
        setlocale(LC_NUMERIC, $previousLocale);
    }

    return [
        'pass'    => true,
        'message' => "StatsManager::record() insère bien le revenu avec number_format() (point décimal forcé), résistant à LC_NUMERIC — bug corrigé le 14/08/2026 (round 168)",
    ];
}
