<?php
/**
 * Régression : HealthCheckManager::checkBounceRate() comptait le
 * numérateur (bounces des dernières 24h) sur TOUTES les boutiques de
 * l'installation, alors que le dénominateur (envois des dernières 24h)
 * était déjà scopé à la boutique courante — round dédié 15/16-09-2026
 * (bloc A, premier audit ciblé de HealthCheckManager.php après 360 rounds
 * où ce fichier n'avait jamais été audité pour lui-même).
 *
 * Sur une installation multi-boutiques, une boutique B avec un fort
 * volume de bounces faussait à tort le taux calculé et affiché pour une
 * boutique A totalement indépendante commercialement — même famille de
 * bug déjà corrigée à de nombreuses reprises ailleurs dans le module
 * (scoping id_shop manquant).
 *
 * Corrigé : le numérateur consulte désormais id_shop IN (0, $idShop),
 * même sémantique que BounceManager::isBounced() (0 = bounce global,
 * bloque partout ; N = scopé à la boutique N).
 *
 * Test comportemental réel : insère un volume de bounces massif sur une
 * boutique FICTIVE (id_shop distinct), suffisant pour faire basculer le
 * taux en ERROR si le bug était présent, puis vérifie que le contrôle de
 * la boutique réelle reste OK (aucune pollution cross-boutique). Vérifie
 * ensuite qu'un bounce RÉEL sur la boutique courante est bien détecté
 * (le filtre ne masque pas les vrais bounces de la boutique).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php';

    $db        = neria_test_db();
    $prefix    = neria_test_prefix();
    $realShop  = (int) Context::getContext()->shop->id;
    $fakeShop  = 999997774;

    $cleanup = function () use ($db, $prefix, $fakeShop) {
        $db->execute("DELETE FROM {$prefix}neria_bounces WHERE email LIKE 'regtest774%'");
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token LIKE 'regtest774%'");
        $db->execute("DELETE FROM {$prefix}neria_bounces WHERE id_shop = {$fakeShop}");
    };
    $cleanup();

    try {
        // 25 envois RÉELS sur la boutique courante (>= 20 = seuil d'activation
        // du calcul de taux dans checkBounceRate()), 0 bounce pour l'instant
        // sur cette boutique => taux attendu 0% => OK.
        for ($i = 0; $i < 25; $i++) {
            $db->execute(
                "INSERT INTO {$prefix}neria_stat
                    (id_shop, template, lang, id_customer, tracking_token, event_type, date_add)
                 VALUES ({$realShop}, 'order_conf', 'fr', 0, 'regtest774_sent_{$i}', 'sent', NOW())"
            );
        }

        // Pollution massive : 50 bounces actifs sur une boutique FICTIVE —
        // si le numérateur n'était pas scopé (bug), 50/25 = 200% => ERROR
        // remonterait à tort pour la boutique RÉELLE, alors qu'aucun de ces
        // bounces ne la concerne.
        for ($i = 0; $i < 50; $i++) {
            $db->execute(
                "INSERT INTO {$prefix}neria_bounces
                    (email, id_shop, type, bounce_count, last_bounce_at, status, date_add)
                 VALUES ('regtest774-fake-{$i}@example.com', {$fakeShop}, 'hard', 1, NOW(), 'active', NOW())"
            );
        }

        $hc = new HealthCheckManager(neria_test_module());
        $method = new ReflectionMethod(HealthCheckManager::class, 'checkBounceRate');
        $method->setAccessible(true);

        $result = $method->invoke($hc);
        neria_assert(
            $result['status'] === 'ok',
            "checkBounceRate() renvoie '{$result['status']}' pour la boutique réelle alors que 50 bounces d'une AUTRE boutique (fictive) ne devraient pas l'affecter — régression du scoping id_shop corrigé le round HealthCheckManager (bloc A) : détail obtenu = " . ($result['detail'] ?? '?')
        );

        // Vérifie que le filtre ne masque pas non plus un VRAI bounce de la
        // boutique courante (2 bounces / 25 envois = 8% >= seuil warning 2%).
        $db->execute(
            "INSERT INTO {$prefix}neria_bounces
                (email, id_shop, type, bounce_count, last_bounce_at, status, date_add)
             VALUES ('regtest774-real1@example.com', {$realShop}, 'hard', 1, NOW(), 'active', NOW())"
        );
        $db->execute(
            "INSERT INTO {$prefix}neria_bounces
                (email, id_shop, type, bounce_count, last_bounce_at, status, date_add)
             VALUES ('regtest774-real2@example.com', {$realShop}, 'hard', 1, NOW(), 'active', NOW())"
        );
        $resultAfterReal = $method->invoke($hc);
        neria_assert(
            $resultAfterReal['status'] !== 'ok',
            "checkBounceRate() reste 'ok' après 2 vrais bounces (8%) sur la boutique courante — le filtre id_shop masquerait à tort les bounces RÉELS de cette boutique, pas seulement ceux des autres"
        );

        return [
            'pass'    => true,
            'message' => "HealthCheckManager::checkBounceRate() isole bien son numérateur par boutique (filet global id_shop=0 + scoping N), sans masquer les vrais bounces de la boutique consultée — bug corrigé round HealthCheckManager (bloc A, 16/09/2026)",
        ];
    } finally {
        $cleanup();
    }
}
