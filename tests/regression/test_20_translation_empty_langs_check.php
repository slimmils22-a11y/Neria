<?php
/** Régression : les checks Watchdog de traduction doivent détecter une clé vide dans une langue, pas seulement une clé absente. */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $path = _PS_MODULE_DIR_ . 'neria/data/admin_translations.json';
    $backup = file_get_contents($path);
    $d = json_decode($backup, true);
    $key = 'gdpr.local_law_note';

    neria_assert(isset($d[$key]), "clé de test '{$key}' absente du dictionnaire, jeu de test à ajuster");
    $origFr = $d[$key]['fr'];
    $d[$key]['fr'] = '';
    file_put_contents($path, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    try {
        require_once _PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php';
        $hcm = new HealthCheckManager(neria_test_module());
        $ref = new ReflectionMethod($hcm, 'checkAdminTranslationKeyUsage');
        $ref->setAccessible(true);
        $result = $ref->invoke($hcm);

        neria_assert(
            $result['status'] === 'warning',
            "status={$result['status']} après avoir vidé une langue sur une clé utilisée, attendu 'warning' — régression du renforcement du 17/07/2026 (commit 70875ae)"
        );

        return ['pass' => true, 'message' => 'checkAdminTranslationKeyUsage() détecte toujours les valeurs vides par langue'];
    } finally {
        file_put_contents($path, $backup);
    }
}
