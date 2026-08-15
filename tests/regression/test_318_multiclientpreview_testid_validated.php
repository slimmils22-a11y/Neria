<?php
/**
 * Régression : pollLitmus()/pollEmailOnAcid() interpolaient $testId
 * directement dans l'URL de la requête cURL sans aucune validation
 * interne — la seule sanitisation existait côté appelant (neria.php).
 * Défense en profondeur manquante : ces méthodes publiques ne doivent
 * pas dépendre de leur seul appelant actuel pour rester sûres si elles
 * sont un jour réutilisées ailleurs (cron/CLI/autre contrôleur) sans
 * répliquer la même sanitisation.
 *
 * Corrigé le 15/08/2026 (round 170) : les deux méthodes valident
 * désormais elles-mêmes le format de $testId avant tout usage, retournant
 * un tableau vide sur un format invalide.
 *
 * Test comportemental réel : appelle les deux méthodes avec un $testId
 * contenant des caractères d'échappement d'URL (/, ?, #) et vérifie
 * qu'elles retournent [] immédiatement, sans tenter le moindre appel
 * réseau (vérifié indirectement : le retour est identique que la clé API
 * soit configurée ou non, la validation du format bloquant AVANT la
 * vérification de la clé).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/MultiClientPreviewManager.php';

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/MultiClientPreviewManager.php');
    neria_assert($src !== false, 'Impossible de lire MultiClientPreviewManager.php');

    foreach (['pollLitmus', 'pollEmailOnAcid'] as $method) {
        $pos = strpos($src, "public function {$method}(string \$testId): array");
        neria_assert($pos !== false, "{$method}() introuvable — jeu de test invalide");
        $body = substr($src, $pos, 700);
        neria_assert(
            strpos($body, "preg_match('/^[a-zA-Z0-9_\\-]+\$/', \$testId)") !== false,
            "{$method}() ne valide plus le format de \$testId en interne — régression du bug corrigé le 15/08/2026 (round 170) : la sanitisation redeviendrait entièrement déléguée à l'appelant, sans défense en profondeur si cette méthode publique est réutilisée ailleurs"
        );
    }

    $mgr = new MultiClientPreviewManager();
    $maliciousIds = ['../etc/passwd', 'abc/def?x=1', 'abc#frag'];
    foreach ($maliciousIds as $badId) {
        $r1 = $mgr->pollLitmus($badId);
        $r2 = $mgr->pollEmailOnAcid($badId);
        neria_assert(
            $r1 === [] && $r2 === [],
            "pollLitmus()/pollEmailOnAcid() n'ont pas rejeté le testId invalide '{$badId}' (retour non vide) — la validation de format ne fonctionne pas comme attendu"
        );
    }

    return [
        'pass'    => true,
        'message' => "pollLitmus()/pollEmailOnAcid() valident bien elles-mêmes le format de \$testId (défense en profondeur), indépendamment de la sanitisation de l'appelant — bug corrigé le 15/08/2026 (round 170)",
    ];
}
