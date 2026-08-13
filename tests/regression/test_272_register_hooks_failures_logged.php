<?php
/**
 * Régression : registerHooks() appelait registerHook() pour chaque hook de
 * self::HOOKS sans jamais lire sa valeur de retour, puis renvoyait
 * systématiquement true. Un échec réel (résidu ps_hook_module orphelin
 * d'une install FTP mal nettoyée, panne DB transitoire) passait ainsi
 * complètement inaperçu — install() réussissait intégralement en
 * apparence alors qu'un hook métier critique (ex: actionEmailSendBefore)
 * pouvait n'être jamais réellement enregistré, sans la moindre trace.
 *
 * Corrigé le 13/08/2026 (round 162) : les échecs sont désormais collectés
 * et journalisés via module->log() (jamais bloquant pour install() — un
 * hook légitimement absent sur une vieille version de PrestaShop ne doit
 * pas faire échouer l'installation).
 *
 * Test structurel (ré-exécuter registerHook() en conditions réelles sur
 * l'environnement de test partagé, ou simuler un vrai échec DB, est trop
 * risqué pour l'instance module active) : vérifie que registerHooks() lit
 * bien le retour de chaque registerHook() et journalise les échecs.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $posFn = strpos($src, 'private function registerHooks(): bool');
    neria_assert($posFn !== false, 'registerHooks() introuvable — jeu de test invalide');
    $body = substr($src, $posFn, 1600);

    neria_assert(
        strpos($body, 'if (!$this->registerHook($hook)) {') !== false,
        "registerHooks() ne lit plus le retour de registerHook() — régression du bug corrigé le 13/08/2026 (round 162) : un échec réel redeviendrait indétectable"
    );
    neria_assert(
        strpos($body, '$failed[] = $hook;') !== false && strpos($body, "if (\$failed) {") !== false,
        "registerHooks() ne collecte/journalise plus les échecs — régression du bug corrigé le 13/08/2026 (round 162)"
    );

    return [
        'pass'    => true,
        'message' => "registerHooks() lit bien le retour de chaque registerHook() et journalise les échecs sans bloquer install() — bug corrigé le 13/08/2026 (round 162)",
    ];
}
