<?php
/**
 * Régression : WebhookManager::processQueue() incrémentait `attempts` en
 * base AVANT d'appeler fire(). Si une exception survenait précisément à la
 * 3e tentative (ex: échec d'écriture Watchdog dans fire() lui-même — cas
 * explicitement anticipé par le commentaire du round 144), le catch par
 * ligne se contentait de logger l'erreur sans jamais passer `status` à
 * 'failed'. La ligne restait `status='pending'`/`attempts=MAX_ATTEMPTS` à
 * vie : invisible au batch suivant (filtre `attempts < MAX_ATTEMPTS`) ET
 * jamais purgée par cleanup() (qui ne traite que `status IN ('done','failed')`)
 * — fuite permanente en base et événement webhook perdu sans jamais
 * déclencher l'alerte de fin de tentatives.
 *
 * Corrigé le 13/08/2026 (round 163) : le catch force désormais
 * `status = 'failed'` si le nombre de tentatives a atteint MAX_ATTEMPTS,
 * avec un repli sur `(int) $row['attempts'] + 1` si l'exception est
 * survenue avant même le calcul de $attempts.
 *
 * Test structurel (déclencher une vraie exception PRÉCISÉMENT au moment
 * du dernier essai nécessiterait de casser Watchdog en plein vol, trop
 * risqué pour l'instance de test partagée — cf. même limitation que
 * test_198 pour cleanup()) : vérifie la présence et le bon positionnement
 * du garde-fou dans le bloc catch.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WebhookManager.php');
    neria_assert($src !== false, 'Impossible de lire src/WebhookManager.php');

    $posMethod = strpos($src, 'public function processQueue(): void');
    neria_assert($posMethod !== false, 'processQueue() introuvable — jeu de test invalide');

    // Round 144 en a déjà un ('catch (\Throwable $e) {' apparaît plusieurs
    // fois dans le fichier, ex: fire()) — cible précisément celui DANS le
    // foreach de processQueue(), pas le premier trouvé dans le fichier.
    $posForeach = strpos($src, 'foreach ($rows as $row) {', $posMethod);
    neria_assert($posForeach !== false, 'foreach ($rows as $row) introuvable dans processQueue() — jeu de test invalide');
    $posCatch = strpos($src, 'catch (\Throwable $e) {', $posForeach);
    neria_assert($posCatch !== false, 'Bloc catch introuvable dans le foreach de processQueue() — jeu de test invalide');
    $body = substr($src, $posCatch, 2800);

    neria_assert(
        strpos($body, '$attemptsAfterException') !== false,
        "Le catch de processQueue() ne calcule plus le nombre de tentatives effectif après exception — régression du bug corrigé le 13/08/2026 (round 163)"
    );
    neria_assert(
        strpos($body, "if (\$attemptsAfterException >= self::MAX_ATTEMPTS) {") !== false,
        "Le catch de processQueue() ne vérifie plus si le nombre de tentatives a atteint MAX_ATTEMPTS — régression du bug corrigé le 13/08/2026 (round 163)"
    );
    neria_assert(
        strpos($body, "SET `status` = 'failed'") !== false,
        "Le catch de processQueue() ne force plus status='failed' sur la dernière tentative — régression du bug corrigé le 13/08/2026 (round 163) : une ligne pourrait de nouveau rester 'pending'/attempts=MAX_ATTEMPTS à vie, fuite permanente en base et événement webhook perdu"
    );

    return [
        'pass'    => true,
        'message' => "WebhookManager::processQueue() force bien status='failed' si une exception survient à la dernière tentative — bug corrigé le 13/08/2026 (round 163)",
    ];
}
