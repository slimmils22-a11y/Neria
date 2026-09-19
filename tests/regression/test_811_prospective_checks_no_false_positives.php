<?php
/**
 * Bloc 7 (19/09/2026) — audit des 6 alertes « prospectives » du diagnostic (onglet Aide),
 * toutes des FAUX POSITIFS permanents affichés à tous les marchands :
 *
 *  1. displayPrice sans langue : un COMMENTAIRE citant « NeriaTools::displayPrice($idLang) » lu comme appel.
 *  2. client-par-email sans id_shop : `id_customer`` des tables neria_* pris pour la table client.
 *  3. Currency::getDefaultCurrency() : 2 commentaires + 1 repli ultime volontaire.
 *  4. clés UNIQUE sans id_shop : clés déjà remplacées par un upgrade ultérieur + id_order seul.
 *  5. REQUEST_URI non échappé : assigns intermédiaires suivis d'un |escape:'html'.
 *
 * Chaque contrôle est exécuté sur un faux module temporaire : les cas légitimes ne sont plus
 * signalés ET une vraie fuite l'est toujours (sinon le correctif aurait tué le contrôle).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    foreach (['CryptoManager', 'NeriaTools', 'TranslationEngine', 'AdminTranslator', 'HealthCheckManager'] as $c) {
        require_once _PS_MODULE_DIR_ . 'neria/src/' . $c . '.php';
    }
    $name = 'regtest811';
    $root = _PS_MODULE_DIR_ . $name;
    $dirs = [$root, $root . '/src', $root . '/upgrade', $root . '/views', $root . '/views/templates', $root . '/views/templates/admin'];
    foreach ($dirs as $d) {
        @mkdir($d, 0777, true);
    }
    $write = function (string $rel, string $body) use ($root): void {
        file_put_contents($root . '/' . $rel, $body);
    };
    $run = function (string $method) use ($name): array {
        $fake = clone neria_test_module();
        $fake->name = $name;
        $m = new ReflectionMethod(HealthCheckManager::class, $method);
        $m->setAccessible(true);
        return $m->invoke(new HealthCheckManager($fake));
    };
    $detail = static fn(array $r): string => (string) ($r['detail'] ?? '');

    try {
        // ── 1. displayPrice ────────────────────────────────────────────────
        $write('src/Good.php', "<?php\nclass Good {\n // NeriaTools::displayPrice(\$idLang) — commentaire, pas un appel\n function f(\$c){ return \\NeriaTools::displayPrice(1.0, \$c, 1); }\n}\n");
        $r = $run('checkDisplayPriceMissingLang');
        neria_assert($r['status'] === 'ok', "displayPrice : commentaire ou appel complet signalé : " . $detail($r));
        $write('src/Bad.php', "<?php\nclass Bad {\n function f(\$c){ return \\NeriaTools::displayPrice(1.0, \$c); }\n}\n");
        $r = $run('checkDisplayPriceMissingLang');
        neria_assert($r['status'] === 'warning' && strpos($detail($r), 'Bad.php') !== false, "displayPrice : un vrai appel sans langue n'est plus détecté : " . $detail($r));
        @unlink($root . '/src/Bad.php');

        // ── 2. client par email ───────────────────────────────────────────
        $write('src/Prefs.php', "<?php\nclass Prefs {\n function f(\$db,\$email){ return \$db->getRow(\"SELECT s FROM `ps_neria_prefs` WHERE `id_customer` = 0 AND `email` = '\" . pSQL(\$email) . \"'\"); }\n}\n");
        $r = $run('checkCustomerEmailLookupMissingShop');
        neria_assert($r['status'] === 'ok', "client-par-email : `id_customer` d'une table neria_* signalé : " . $detail($r));
        $write('src/BadCust.php', "<?php\nclass BadCust {\n function f(\$db,\$email){ return \$db->getRow(\"SELECT id_customer FROM `\" . _DB_PREFIX_ . \"customer` WHERE `email` = '\" . pSQL(\$email) . \"'\"); }\n}\n");
        $r = $run('checkCustomerEmailLookupMissingShop');
        neria_assert($r['status'] === 'warning' && strpos($detail($r), 'BadCust.php') !== false, "client-par-email : une vraie recherche sur la table customer sans id_shop n'est plus détectée : " . $detail($r));
        @unlink($root . '/src/BadCust.php');

        // ── 3. devise par défaut ──────────────────────────────────────────
        $write('src/Cur.php', "<?php\nclass Cur {\n // Currency::getDefaultCurrency() lit PS_CURRENCY_DEFAULT sans idShop (commentaire)\n function f(){\n  // repli ultime // default-currency-ok\n  return \\Currency::getDefaultCurrency();\n }\n}\n");
        $r = $run('checkDefaultCurrencyUsage');
        neria_assert($r['status'] === 'ok', "devise par défaut : commentaire ou repli marqué signalé : " . $detail($r));
        $write('src/CurBad.php', "<?php\nclass CurBad {\n function f(){ return \\Currency::getDefaultCurrency(); }\n}\n");
        $r = $run('checkDefaultCurrencyUsage');
        neria_assert($r['status'] === 'warning' && strpos($detail($r), 'CurBad.php') !== false, "devise par défaut : un vrai appel non marqué n'est plus détecté : " . $detail($r));
        @unlink($root . '/src/CurBad.php');

        // ── 4. clés UNIQUE ────────────────────────────────────────────────
        $write('upgrade/upgrade-1.0.1.php', "<?php\n\$sql = 'CREATE TABLE t (UNIQUE KEY `uq_old` (`id_customer`, `x`), UNIQUE KEY `uq_ord` (`id_order`))';\n");
        $write('upgrade/upgrade-1.0.2.php', "<?php\n\$db->execute('ALTER TABLE t DROP INDEX `uq_old`');\n");
        $r = $run('checkUpgradeUniqueKeyShopScope');
        neria_assert($r['status'] === 'ok', "clés UNIQUE : clé remplacée par un upgrade ultérieur ou id_order seul signalé : " . $detail($r));
        $write('upgrade/upgrade-1.0.3.php', "<?php\n\$sql = 'CREATE TABLE u (UNIQUE KEY `uq_live` (`id_customer`, `y`))';\n");
        $r = $run('checkUpgradeUniqueKeyShopScope');
        neria_assert($r['status'] === 'warning' && strpos($detail($r), 'upgrade-1.0.3.php') !== false, "clés UNIQUE : une vraie clé client sans id_shop et non remplacée n'est plus détectée : " . $detail($r));
        @unlink($root . '/upgrade/upgrade-1.0.3.php');

        // ── 5. REQUEST_URI ────────────────────────────────────────────────
        $write('views/templates/admin/ok.tpl', "{assign var=\"base\" value=\$smarty.server.REQUEST_URI|regex_replace:'/&a=[^&]*/':''}\n{assign var=\"base\" value=\$base|regex_replace:'/&b=[^&]*/':''|escape:'html'}\n<a href=\"{\$base}\">x</a>\n");
        $r = $run('checkTplRequestUriEscape');
        neria_assert($r['status'] === 'ok', "REQUEST_URI : chaîne d'assigns terminée par |escape signalée : " . $detail($r));
        $write('views/templates/admin/bad.tpl', "{assign var=\"raw\" value=\$smarty.server.REQUEST_URI}\n<a href=\"{\$raw}\">x</a>\n");
        $r = $run('checkTplRequestUriEscape');
        neria_assert($r['status'] === 'warning' && strpos($detail($r), 'bad.tpl') !== false, "REQUEST_URI : un assign jamais échappé n'est plus détecté : " . $detail($r));
    } finally {
        foreach ([$root . '/upgrade', $root . '/src', $root . '/views/templates/admin'] as $d) {
            foreach (glob($d . '/*') ?: [] as $f) {
                @unlink($f);
            }
        }
        foreach (array_reverse($dirs) as $d) {
            @rmdir($d);
        }
    }

    // Sur le vrai module, les 5 contrôles sont désormais propres.
    $real = function (string $method): array {
        $m = new ReflectionMethod(HealthCheckManager::class, $method);
        $m->setAccessible(true);
        return $m->invoke(new HealthCheckManager(neria_test_module()));
    };
    foreach (['checkDisplayPriceMissingLang', 'checkCustomerEmailLookupMissingShop', 'checkDefaultCurrencyUsage', 'checkUpgradeUniqueKeyShopScope', 'checkTplRequestUriEscape'] as $method) {
        $r = $real($method);
        neria_assert($r['status'] === 'ok', "{$method} n'est pas 'ok' sur le vrai module : " . substr($detail($r), 0, 300));
    }

    return ['pass' => true, 'message' => "5 contrôles prospectifs du diagnostic sans faux positif (commentaires, tables neria_*, replis marqués, clés remplacées, assigns échappés) et vraies fuites toujours détectées — bloc 7 (19/09/2026)"];
}
