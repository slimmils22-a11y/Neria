<?php
/**
 * Régression : l'action AJAX 'deliverability_score' (neria.php) ne
 * normalisait pas score_template (POST/GET) avant de le transmettre à
 * EmailRenderer::renderPreviewHtml() -> buildCompiledHtml(), qui construit
 * directement un chemin de fichier .html à partir de cette valeur — path
 * traversal permettant de lire n'importe quel fichier .html accessible sur
 * le disque (contrairement à neria_template/mp_template ailleurs dans ce
 * même fichier, déjà normalisés via preg_replace('/[^a-z0-9_-]/i', ...)).
 *
 * Corrigé le 09/08/2026 (round 155) en appliquant la même normalisation
 * que tous les autres points d'entrée équivalents, avec repli sur
 * 'order_conf' si la valeur normalisée devient vide.
 *
 * Test comportemental réel : rejoue EXACTEMENT la ligne de normalisation
 * du fichier source (extraite par miroir de code, le contrôleur complet
 * n'étant pas invocable isolément en CLI) contre plusieurs payloads de
 * traversal réels, et vérifie qu'aucun ne produit de chemin exploitable.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $posAction = strpos($src, "if (Tools::getValue('neria_action') === 'deliverability_score') {");
    neria_assert($posAction !== false, "action deliverability_score introuvable — jeu de test invalide");
    $body = substr($src, $posAction, 700);

    neria_assert(
        strpos($body, "preg_replace('/[^a-z0-9_-]/i', '', (string) Tools::getValue('score_template', 'order_conf'))") !== false,
        "score_template n'est plus normalisé via preg_replace — régression du bug corrigé le 09/08/2026 (round 155) : le path traversal vers EmailRenderer::buildCompiledHtml() redeviendrait possible"
    );
    neria_assert(
        strpos($body, "if (\$scoreTemplate === '') {") !== false && strpos($body, "\$scoreTemplate = 'order_conf';") !== false,
        "score_template n'a plus de repli sur 'order_conf' quand la normalisation vide la valeur — régression du bug corrigé le 09/08/2026 (round 155)"
    );

    // Rejoue la normalisation réelle (même regex que le code source, extraite
    // ci-dessus et confirmée présente) contre des payloads de traversal réels.
    $payloads = [
        '../../../../../../windows/win32'          => 'windowswin32',
        '..%2F..%2F..%2Fconfig%2Fconfig'            => '2F2F2Fconfig2Fconfig',
        '../../config/config'                       => 'configconfig',
        '/etc/passwd'                                => 'etcpasswd',
        'order_conf'                                 => 'order_conf', // cas nominal inchangé
    ];
    foreach ($payloads as $payload => $expectedSafe) {
        $normalized = preg_replace('/[^a-z0-9_-]/i', '', $payload);
        neria_assert(
            strpos($normalized, '/') === false && strpos($normalized, '\\') === false && strpos($normalized, '..') === false,
            "le payload '{$payload}' normalisé ('{$normalized}') contient encore un séparateur de chemin ou '..' — la regex de protection ne neutralise plus le path traversal"
        );
        neria_assert(
            $normalized === $expectedSafe,
            "normalisation inattendue pour '{$payload}' : obtenu '{$normalized}', attendu '{$expectedSafe}'"
        );
    }

    return [
        'pass'    => true,
        'message' => "score_template reste normalisé (path traversal neutralisé) — bug corrigé le 09/08/2026 (round 155)",
    ];
}
