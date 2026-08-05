<?php
/**
 * Régression (préventive, balayage large) : aucune requête SQL du module ne
 * doit contenir d'entités HTML échappées par erreur ("&lt;", "&gt;", "&amp;")
 * à la place des opérateurs/caractères réels.
 *
 * Origine : round 48 (commit 15d0130) a introduit "&lt;="/"&gt;=" littéraux
 * dans une requête SQL de MonthlyReportManager::getRevenueByTemplate(),
 * corrigé au round 50 (commit da351f9) — voir test_41 pour le test
 * d'exécution réelle sur CE cas précis. Ce test-ci balaie TOUT src/ et
 * neria.php pour détecter la même classe d'erreur ailleurs, y compris dans
 * du code jamais exercé par les tests d'exécution existants.
 *
 * Faux positifs possibles : un commentaire PHP mentionnant littéralement
 * "&lt;" dans sa prose (ex. pour documenter du HTML) — le motif ci-dessous
 * se limite volontairement au contexte d'une ligne de requête SQL
 * reconnaissable (contient un mot-clé SQL ou un nom de colonne suivi de
 * l'entité), pas à n'importe quelle occurrence du texte dans le fichier.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $moduleDir = _PS_MODULE_DIR_ . 'neria/';
    $files = array_merge(
        [$moduleDir . 'neria.php'],
        glob($moduleDir . 'src/*.php')
    );

    $offenders = [];

    foreach ($files as $file) {
        $src = file_get_contents($file);
        if ($src === false) {
            continue;
        }

        foreach (explode("\n", $src) as $i => $line) {
            // Ne regarde que les lignes qui ressemblent à un fragment de
            // requête SQL (mot-clé SQL courant OU comparaison sur une colonne
            // style `alias.colonne`) ET qui contiennent une entité HTML
            // d'opérateur de comparaison — évite de flaguer un commentaire
            // parlant de HTML en toute légitimité.
            $looksLikeSql = (bool) preg_match('/\b(SELECT|WHERE|AND|OR|HAVING|JOIN)\b/i', $line)
                || (bool) preg_match('/\b[a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*\s*(&lt;|&gt;|&amp;)/i', $line);

            if ($looksLikeSql && preg_match('/&(lt|gt|amp);/', $line)) {
                $offenders[] = basename($file) . ':' . ($i + 1) . ' — ' . trim($line);
            }
        }
    }

    neria_assert(
        empty($offenders),
        "Entité(s) HTML détectée(s) dans du SQL apparent : " . implode(' | ', array_slice($offenders, 0, 5))
        . (count($offenders) > 5 ? ' (+' . (count($offenders) - 5) . ' autres)' : '')
    );

    return ['pass' => true, 'message' => 'Aucune entité HTML détectée dans une requête SQL sur ' . count($files) . ' fichiers balayés'];
}
