<?php
/**
 * Régression : neria.php::install() appelle Module::runUpgradeModule()
 * (cœur PrestaShop) pour réconcilier un schéma orphelin (round 161), mais
 * n'entourait le résultat que d'un try/catch(\Throwable). Or
 * Module::runUpgradeModule() (classes/module/Module.php, cœur, non
 * surchargé) ne lève JAMAIS d'exception sur l'échec d'un script
 * upgrade-X.php : il désactive silencieusement le module ($this->disable(),
 * ligne ~605 du cœur) et retourne un simple tableau `success=false`. Le
 * catch ne voyait donc jamais cet échec — install() retournait true, PS
 * affichait "installé avec succès", et le module restait en réalité
 * désactivé (plus aucun hook, plus aucun email) SANS UNE SEULE LIGNE DE
 * LOG exploitable.
 *
 * Bug identifié le 31/08/2026 (round 257, audit "cycle de vie install/
 * uninstall/upgrade"). Corrigé le 31/08/2026 (round 257) : le résultat de
 * runUpgradeModule() est désormais lu ; si `success` est vide ET
 * `version_fail` renseigné (signature exacte d'un échec d'upgrade détecté
 * par le cœur PS), une ligne de log niveau Erreur (3) est écrite, avec le
 * numéro de version fautive et l'action BO de réparation à utiliser.
 *
 * Test structurel (simuler une VRAIE désactivation par le cœur PS
 * nécessiterait un script upgrade-X.php cassé exécuté en conditions
 * réelles sur l'environnement de test partagé — hors périmètre sûr d'un
 * test isolé, même contrainte que test_417/test_385) : vérifie que le
 * bloc de réconciliation de install() lit bien le résultat de
 * runUpgradeModule() et loggue explicitement l'échec silencieux, au lieu
 * de le laisser filer dans le try/catch(Throwable) qui ne le voit jamais.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $posInstall = strpos($src, 'public function install(): bool');
    neria_assert($posInstall !== false, "install() introuvable — jeu de test invalide");

    $posUninstall = strpos($src, "// DÉSINSTALLATION", $posInstall);
    neria_assert($posUninstall !== false && $posUninstall > $posInstall, "Section suivante introuvable — jeu de test invalide");

    $installBody = substr($src, $posInstall, $posUninstall - $posInstall);

    neria_assert(
        strpos($installBody, '$result = $this->runUpgradeModule();') !== false,
        "install() n'assigne plus le retour de runUpgradeModule() — régression du bug corrigé le 31/08/2026 (round 257) : impossible de détecter un échec silencieux d'upgrade sans lire ce résultat"
    );
    neria_assert(
        strpos($installBody, "empty(\$result['success']) && !empty(\$result['version_fail'])") !== false,
        "install() ne vérifie plus success/version_fail après runUpgradeModule() — régression du bug corrigé le 31/08/2026 (round 257)"
    );

    $posCheck = strpos($installBody, "empty(\$result['success'])");
    $posCatch = strpos($installBody, 'catch (\Throwable $e)');
    $checkBlock = ($posCheck !== false && $posCatch !== false && $posCatch > $posCheck)
        ? substr($installBody, $posCheck, $posCatch - $posCheck)
        : '';
    neria_assert(
        $checkBlock !== '' && strpos($checkBlock, '$this->log(') !== false,
        "install() ne loggue plus l'échec silencieux d'upgrade détecté — régression du bug corrigé le 31/08/2026 (round 257) : le module resterait désactivé sans aucune trace exploitable"
    );
    neria_assert(
        preg_match('/\$this->log\(\s*\'install\(\).*?,\s*3\s*\);/s', $checkBlock) === 1,
        "install() ne loggue plus l'échec d'upgrade au niveau Erreur (severity 3) — régression du bug corrigé le 31/08/2026 (round 257)"
    );

    return [
        'pass'    => true,
        'message' => "neria.php::install() détecte désormais et loggue explicitement (severity 3) l'échec silencieux d'un script upgrade-X.php par Module::runUpgradeModule() (cœur PS, qui désactive le module sans jamais lever d'exception) — bug corrigé le 31/08/2026 (round 257)",
    ];
}
