<?php
/**
 * Régression round 238 (28/08/2026) : EmailRenderer compilait chaque email
 * dans un fichier PARTAGÉ par template+langue (mails/{iso}/{template}.html,
 * .txt) — écrit par applyNeriaRendering()/sendFallbackEmail()/
 * ensureInternalTemplateCompiled(), puis relu par Mail::Send() du cœur
 * PrestaShop dans la MÊME requête PHP pour construire l'email réel.
 *
 * Bug réel corrigé le 28/08/2026 (round 238) : deux envois concurrents du
 * même template dans la même langue (ex. deux commandes passées à
 * quelques millisecondes d'écart lors d'un pic de trafic, order_conf en
 * français) pouvaient s'écraser mutuellement entre l'écriture de l'un et
 * la lecture par Mail::Send() de l'autre — un client recevait alors un
 * email compilé avec les données (nom, token de tracking) d'un AUTRE
 * client, pas juste un fichier corrompu.
 *
 * Corrigé le 28/08/2026 (round 238) : le fichier compilé est désormais
 * nommé de façon UNIQUE par envoi ({template}__{hex16}.html/.txt), jamais
 * partagé, sur les 3 chemins de compilation réels (applyNeriaRendering,
 * sendFallbackEmail, ensureInternalTemplateCompiled). StatsManager
 * continue de fonctionner car il lit $params['neria_template'] (le VRAI
 * nom, posé par injectTrackingPixel() AVANT la mutation), jamais
 * $params['template'] (le nom PS-natif, désormais unique par envoi).
 *
 * Test comportemental réel : compile le MÊME template deux fois de suite
 * avec des variables DIFFÉRENTES (simule 2 envois concurrents) via
 * compileNeriaTemplate() (Reflection, le vrai pipeline d'envoi), avec un
 * $outputName distinct à chaque appel — vérifie que les 2 fichiers
 * coexistent sur disque SANS EFFACER le contenu l'un de l'autre. Vérifie
 * aussi, via processEmailParams() (l'entrée publique réelle), que
 * $params['template'] change bien à chaque appel (nom unique) tandis que
 * $params['neria_template'] reste le vrai nom du template (StatsManager
 * non affecté).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    $module   = neria_test_module();
    $renderer = new EmailRenderer($module);
    $mailsDir = _PS_MODULE_DIR_ . 'neria/mails/fr/';

    $method = new ReflectionMethod(EmailRenderer::class, 'compileNeriaTemplate');
    $method->setAccessible(true);

    $file1 = $method->invoke($renderer, 'reply_msg', 'fr', 'fr', ['{reply}' => 'MessageDeClientA'], true, true, 'regtest474_a');
    $file2 = $method->invoke($renderer, 'reply_msg', 'fr', 'fr', ['{reply}' => 'MessageDeClientB'], true, true, 'regtest474_b');

    try {
        neria_assert(
            $file1 !== null && $file2 !== null,
            "compileNeriaTemplate() avec \$outputName n'a pas produit les 2 fichiers attendus — jeu de test invalide"
        );
        neria_assert(
            $file1 !== $file2,
            "compileNeriaTemplate() avec 2 \$outputName différents produit le MÊME chemin de fichier — régression du bug corrigé le 28/08/2026 (round 238)"
        );

        $content1 = file_get_contents($file1);
        $content2 = file_get_contents($file2);
        neria_assert(
            strpos($content1, 'MessageDeClientA') !== false && strpos($content1, 'MessageDeClientB') === false,
            "le fichier compilé du 1er envoi contient les données du 2e envoi — régression du bug corrigé le 28/08/2026 (round 238) : deux envois concurrents mélangeraient de nouveau leurs données"
        );
        neria_assert(
            strpos($content2, 'MessageDeClientB') !== false && strpos($content2, 'MessageDeClientA') === false,
            "le fichier compilé du 2e envoi contient les données du 1er envoi — régression du bug corrigé le 28/08/2026 (round 238)"
        );

        // ── processEmailParams() : $params['template'] devient unique,
        // $params['neria_template'] reste le vrai nom (StatsManager).
        $params1 = [
            'template'     => 'reply_msg',
            'subject'      => 'Test',
            'idLang'       => (int) Configuration::get('PS_LANG_DEFAULT'),
            'templateVars' => ['{firstname}' => 'Test', '{reply}' => 'Bonjour'],
            'to'           => 'regtest474@example.com',
        ];
        $params2 = $params1;

        $renderer->processEmailParams($params1);
        $renderer->processEmailParams($params2);

        neria_assert(
            isset($params1['template'], $params2['template']),
            "processEmailParams() n'assigne plus \$params['template'] — jeu de test invalide"
        );
        neria_assert(
            $params1['template'] !== $params2['template'],
            "processEmailParams() assigne le MÊME \$params['template'] à 2 appels successifs — régression du bug corrigé le 28/08/2026 (round 238) : le fichier compilé redeviendrait partagé entre envois"
        );
        neria_assert(
            strpos((string) $params1['template'], 'reply_msg__') === 0,
            "\$params['template'] n'est plus préfixé par le vrai nom de template + suffixe unique — inattendu"
        );
        neria_assert(
            ($params1['neria_template'] ?? null) === 'reply_msg' && ($params2['neria_template'] ?? null) === 'reply_msg',
            "\$params['neria_template'] (lu par StatsManager::recordSent()) n'est plus le vrai nom de template — régression : les statistiques d'envoi seraient de nouveau enregistrées sous un nom de template incorrect"
        );

        return [
            'pass'    => true,
            'message' => "EmailRenderer compile bien chaque email dans un fichier UNIQUE par envoi (round 238) — plus de partage entre envois concurrents, StatsManager toujours alimenté avec le vrai nom de template",
        ];
    } finally {
        foreach ([$file1, $file2] as $f) {
            if ($f !== null && is_file($f)) {
                @unlink($f);
                $txt = substr($f, 0, -5) . '.txt';
                if (is_file($txt)) {
                    @unlink($txt);
                }
            }
        }
        foreach (glob($mailsDir . 'reply_msg__*.html') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($mailsDir . 'reply_msg__*.txt') ?: [] as $f) {
            @unlink($f);
        }
    }
}
