<?php
/**
 * Régression : GoldenHourManager::computeRecommendations() calculait la
 * borne de fenêtre d'analyse via date('Y-m-d', strtotime("-{$days} days"))
 * (horloge PHP) comparée à date_add écrit par MySQL (NOW()) — même piège
 * horloge PHP/MySQL déjà corrigé ailleurs dans le module. Si le serveur
 * PHP et le serveur MySQL n'ont pas le même fuseau horaire, la fenêtre
 * calculée en PHP pouvait inclure/exclure une journée entière de sends en
 * bordure, biaisant sent_count/opened_count et donc le créneau "meilleure
 * heure" retenu.
 *
 * Corrigé le 06/09/2026 (round 309) : borne calculée entièrement côté SQL
 * via DATE_SUB(NOW(), INTERVAL {$days} DAY).
 *
 * Test comportemental réel : insère un lot de 10 paires sent+open juste À
 * L'INTÉRIEUR de la fenêtre (horloge MySQL, seule autorité désormais) et
 * un second lot de 10 paires juste À L'EXTÉRIEUR, puis vérifie que
 * computeRecommendations() comptabilise EXACTEMENT le lot intérieur
 * (total_opens=10, pas 0 ni 20) de façon IDENTIQUE sous deux fuseaux
 * horaires PHP extrêmes opposés (UTC+14 et UTC-12).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/GoldenHourManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;
    $days   = 5;
    $lang   = 'zz'; // code langue de test, jamais utilisé par de vraies données

    $db->execute("DELETE FROM {$prefix}neria_stat WHERE lang = '{$lang}'");

    $insertBatch = function (string $dateExpr) use ($db, $prefix, $idShop, $lang) {
        for ($i = 0; $i < 10; $i++) {
            $token = bin2hex(random_bytes(16));
            $db->execute(
                "INSERT INTO {$prefix}neria_stat (id_shop, template, lang, country_code, tracking_token, event_type, ip_address, user_agent, date_add)
                 VALUES ({$idShop}, 'regtest588', '{$lang}', '', '{$token}', 'sent', '', '', {$dateExpr})"
            );
            $db->execute(
                "INSERT INTO {$prefix}neria_stat (id_shop, template, lang, country_code, tracking_token, event_type, ip_address, user_agent, date_add)
                 VALUES ({$idShop}, 'regtest588', '{$lang}', '', '{$token}', 'open', '', '', {$dateExpr})"
            );
        }
    };

    // Lot A, juste À L'INTÉRIEUR de la fenêtre de {$days} jours (2h de marge).
    $insertBatch("DATE_SUB(NOW(), INTERVAL {$days} DAY) + INTERVAL 2 HOUR");
    // Lot B, juste À L'EXTÉRIEUR de la fenêtre (2h au-delà).
    $insertBatch("DATE_SUB(NOW(), INTERVAL {$days} DAY) - INTERVAL 2 HOUR");

    $originalTz = date_default_timezone_get();
    $mgr = new GoldenHourManager();
    $ref = new ReflectionMethod($mgr, 'computeRecommendations');
    $ref->setAccessible(true);

    try {
        // Vérification indépendante (hors compute) du compte réel attendu.
        $realOpens = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_stat
             WHERE lang = '{$lang}' AND event_type = 'open'
               AND date_add >= DATE_SUB(NOW(), INTERVAL {$days} DAY)",
            false
        );
        neria_assert($realOpens === 10, "jeu de test invalide : {$realOpens} ouvertures réelles dans la fenêtre attendue (10 attendues)");

        $results = [];
        foreach (['Pacific/Kiritimati', 'Etc/GMT+12'] as $tz) {
            date_default_timezone_set($tz);
            $rows = $ref->invoke($mgr, $days);
            date_default_timezone_set($originalTz);

            $totalOpens = 0;
            foreach ($rows as $r) {
                if (($r['lang'] ?? '') === $lang) {
                    $totalOpens += (int) ($r['total_opens'] ?? 0);
                }
            }
            $results[$tz] = $totalOpens;
        }

        foreach ($results as $tz => $totalOpens) {
            neria_assert(
                $totalOpens === 10,
                "GoldenHourManager::computeRecommendations() comptabilise {$totalOpens} ouverture(s) dans la fenêtre sous le fuseau PHP '{$tz}' (10 attendues, seul le lot intérieur devrait être inclus) — régression du bug corrigé le 06/09/2026 (round 309) : la borne de fenêtre dépend de nouveau de l'horloge PHP au lieu de MySQL"
            );
        }

        return [
            'pass'    => true,
            'message' => "GoldenHourManager::computeRecommendations() calcule sa fenêtre d'analyse entièrement côté SQL (DATE_SUB(NOW(), ...)), résultat identique et correct sous deux fuseaux horaires PHP extrêmes opposés — bug corrigé le 06/09/2026 (round 309)",
        ];
    } finally {
        date_default_timezone_set($originalTz);
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE lang = '{$lang}'");
    }
}
