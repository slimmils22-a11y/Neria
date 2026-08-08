<?php
/**
 * Régression : MultiClientPreviewManager::submitToLitmus()/
 * submitToEmailOnAcid() doivent capturer curl_error() et journaliser via
 * Watchdog en cas d'échec transport, comme leurs pendants
 * pollLitmus()/pollEmailOnAcid() (déjà corrigés à un round antérieur).
 *
 * Bug réel corrigé le 08/08/2026 (round 134) : contrairement au polling,
 * les méthodes submitTo*() ne récupéraient jamais curl_error($ch). En cas
 * d'échec DNS/TLS/timeout, curl_exec() renvoie false et le code HTTP vaut
 * 0 : le message retourné était "Litmus HTTP 0 — " (chaîne vide, aucune
 * cause exploitable), et rien n'était journalisé dans Watchdog —
 * incohérent avec le pattern déjà en place pour le polling.
 *
 * Test structurel : vérifie que curl_error($ch) est bien capturé et que le
 * log Watchdog est bien présent dans les deux méthodes submitTo*().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/MultiClientPreviewManager.php');
    neria_assert($src !== false, 'Impossible de lire src/MultiClientPreviewManager.php');

    foreach (['submitToLitmus', 'submitToEmailOnAcid'] as $method) {
        $posMethod = strpos($src, 'function ' . $method . '(');
        neria_assert($posMethod !== false, "{$method}() introuvable — régression du bug corrigé le 08/08/2026");

        $nextMethod = strpos($src, "\n    public function ", $posMethod + 20);
        if ($nextMethod === false) {
            $nextMethod = strpos($src, "\n    private function ", $posMethod + 20);
        }
        $body = $nextMethod !== false
            ? substr($src, $posMethod, $nextMethod - $posMethod)
            : substr($src, $posMethod, 2200);

        neria_assert(
            strpos($body, '$curlErr  = curl_error($ch);') !== false || strpos($body, '$curlErr = curl_error($ch);') !== false,
            "{$method}() ne capture plus curl_error(\$ch) — régression du bug corrigé le 08/08/2026 (round 134) : un échec transport redeviendrait indiscernable d'une simple erreur HTTP inexploitable"
        );
        neria_assert(
            strpos($body, 'WatchdogManager') !== false && strpos($body, 'multipreview_poll_failed') !== false,
            "{$method}() ne journalise plus l'échec via Watchdog — régression du bug corrigé le 08/08/2026 (round 134)"
        );
    }

    return [
        'pass'    => true,
        'message' => "submitToLitmus()/submitToEmailOnAcid() capturent bien curl_error() et journalisent l'échec transport via Watchdog, cohérent avec pollLitmus()/pollEmailOnAcid()",
    ];
}
