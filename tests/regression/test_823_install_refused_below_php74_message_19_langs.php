<?php
/**
 * Constat F-004 (suite, 21/09/2026) : sous PHP 7.2/7.3 (encore acceptés par PrestaShop 8.0/8.1) le module doit refuser
 * l'installation avec un message clair dans la langue du back-office, au lieu d'une erreur fatale.
 * (1) Neria::phpTooOldMessage() : 19 langues, version de PHP injectée, repli anglais. (2) install() contient le contrôle
 * de version. (3) Si PHP 7.2 / 7.3 sont disponibles (C:\tmp\php72 / php73 ou NERIA_PHP72), neria.php s'y analyse sans erreur.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    neria_test_module(); // charge la classe Neria
    $langs = ['fr', 'en', 'de', 'it', 'es', 'pt', 'br', 'ar', 'ja', 'ko', 'zh', 'tw', 'ru', 'tr', 'sv', 'no', 'da', 'nl', 'gb'];
    $seen = [];
    foreach ($langs as $l) {
        $m = Neria::phpTooOldMessage($l, '7.3.5');
        neria_assert(strpos($m, '7.3.5') !== false && strpos($m, '7.4') !== false, "message {$l} sans version de PHP ou sans « 7.4 » : {$m}");
        neria_assert(strpos($m, '%s') === false, "message {$l} : marqueur %s non remplacé");
        $seen[$m] = true;
    }
    neria_assert(count($seen) >= 17, 'messages identiques entre langues (copier-coller ?)');
    neria_assert(Neria::phpTooOldMessage('xx', '7.2.1') === Neria::phpTooOldMessage('en', '7.2.1'), 'langue inconnue : repli anglais attendu');

    $src = (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    $pos = strpos($src, 'public function install(): bool');
    neria_assert($pos !== false && strpos(substr($src, $pos, 900), "version_compare(PHP_VERSION, '7.4.0', '<')") !== false, "install() ne vérifie plus la version de PHP en premier");

    $checked = 0;
    foreach (['NERIA_PHP72' => 'C:/tmp/php72/php.exe', 'NERIA_PHP73' => 'C:/tmp/php73/php.exe'] as $env => $default) {
        $bin = getenv($env) ?: $default;
        if (is_file($bin)) {
            $out = [];
            exec(escapeshellarg($bin) . ' -l ' . escapeshellarg(_PS_MODULE_DIR_ . 'neria/neria.php') . ' 2>&1', $out, $rc);
            neria_assert($rc === 0, "neria.php ne s'analyse pas avec {$env} : " . implode(' ', $out));
            $checked++;
        }
    }
    return ['pass' => true, 'message' => "install() refuse PHP < 7.4 avec un message en 19 langues (version injectée, repli anglais), neria.php reste analysable par PHP 7.2 ({$checked} version(s) réellement testée(s))"];
}
