<?php
/**
 * Régression : si modules/neria/ est supprimé par FTP sans passer par
 * "Désinstaller" (contournement fréquent en pratique), la ligne ps_module
 * reste active mais les tables SQL restent en base avec un schéma
 * potentiellement ancien. Une réinstallation ultérieure ré-exécute
 * install.sql (CREATE TABLE IF NOT EXISTS — sans effet si la table existe
 * déjà) SANS repasser par la chaîne des upgrade-X.Y.php, laissant des
 * colonnes récentes manquantes — erreurs SQL "Unknown column" en prod dès
 * les premiers crons.
 *
 * Corrigé le 13/08/2026 (round 161) : install() appelle désormais
 * Module::needUpgrade()+runUpgradeModule() après un succès, réconciliant
 * automatiquement un éventuel schéma orphelin — même geste que l'action BO
 * manuelle "repair_module_version", simplement automatique. Sans effet sur
 * une install réellement neuve.
 *
 * Test structurel (simuler une vraie install() re-déclencherait
 * parent::install() sur le module réellement installé de l'environnement
 * de test — bien trop risqué) : vérifie que install() appelle bien
 * needUpgrade()+runUpgradeModule() après un succès, entouré d'un try/catch
 * qui ne bloque jamais le retour `true` de install().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $posFn = strpos($src, 'public function install(): bool');
    neria_assert($posFn !== false, 'install() introuvable — jeu de test invalide');
    // Round 257 : fenêtre élargie 2000→3700 — l'ajout de la détection de
    // l'échec silencieux d'upgrade (garde-fou round 257) a repoussé le
    // catch(\Throwable) plus loin dans le corps de install().
    $body = substr($src, $posFn, 3700);

    neria_assert(
        strpos($body, 'if ($ok) {') !== false,
        'Structure de install() inattendue — jeu de test invalide'
    );

    $afterOk = substr($body, strpos($body, 'if ($ok) {'));

    neria_assert(
        strpos($afterOk, '\Module::needUpgrade($this)') !== false && strpos($afterOk, '$this->runUpgradeModule()') !== false,
        "install() ne réconcilie plus le schéma via needUpgrade()/runUpgradeModule() après un succès — régression du bug corrigé le 13/08/2026 (round 161) : une réinstallation par-dessus des tables orphelines à schéma ancien ne serait plus rattrapée"
    );

    neria_assert(
        strpos($afterOk, 'try {') !== false && strpos($afterOk, 'catch (\Throwable $e)') !== false,
        "La réconciliation de schéma post-install n'est plus protégée par un try/catch — un échec de runUpgradeModule() pourrait faire échouer install() alors que l'installation elle-même a réussi"
    );

    return [
        'pass'    => true,
        'message' => "install() réconcilie bien un schéma orphelin via needUpgrade()/runUpgradeModule() après un succès, sans jamais bloquer le retour — bug corrigé le 13/08/2026 (round 161)",
    ];
}
