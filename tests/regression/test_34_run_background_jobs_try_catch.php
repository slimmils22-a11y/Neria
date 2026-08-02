<?php
/**
 * Régression : chaque instanciation de Manager dans neria.php::runBackgroundJobs()
 * doit être protégée par un try/catch — sans ça, une exception transitoire (perte
 * DB, deadlock GET_LOCK) dans UN job casse tous les jobs suivants de la même
 * requête (rapport mensuel, réputation domaine, comportemental, upsell, fidélité,
 * campagnes saisonnières...), pour le visiteur front qui l'a déclenchée
 * (hookDisplayHeader, fallback sans cron serveur).
 *
 * Bug réel trouvé le 02/08/2026 (commit 6ee5c0e) : les jobs HealthCheckManager et
 * CalendarManager n'étaient pas protégés, contrairement à tous les autres jobs de
 * la même méthode.
 *
 * Utilise le tokenizer PHP (token_get_all) plutôt qu'une regex sur le texte brut :
 * une regex ne peut pas fiablement déterminer si un appel se trouve à l'intérieur
 * d'un bloc try{} imbriqué dans des if/class_exists() — le tokenizer, si.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $source = file_get_contents(dirname(__DIR__, 2) . '/neria.php');
    if ($source === false) {
        throw new RuntimeException('Impossible de lire neria.php');
    }

    $tokens = token_get_all($source);

    // Isole les tokens de la méthode runBackgroundJobs() par comptage d'accolades.
    $inMethod   = false;
    $depth      = 0;
    $methodToks = [];
    $count      = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $tok = $tokens[$i];

        if (!$inMethod) {
            if (is_array($tok) && $tok[0] === T_STRING && $tok[1] === 'runBackgroundJobs') {
                $inMethod = true;
            }
            continue;
        }

        if ($tok === '{') {
            $depth++;
        } elseif ($tok === '}') {
            $depth--;
            if ($depth === 0) {
                $methodToks[] = $tok;
                break;
            }
        }
        $methodToks[] = $tok;
    }

    if (empty($methodToks)) {
        throw new RuntimeException('runBackgroundJobs() introuvable dans neria.php — méthode renommée/déplacée ?');
    }

    // Pile des accolades ouvertes, avec un flag "ouverte par try".
    $braceStack  = [];
    $lastReal    = null; // dernier token significatif (hors espaces/commentaires)
    $unprotected = [];

    foreach ($methodToks as $tok) {
        if (is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        if ($tok === '{') {
            $braceStack[] = (is_array($lastReal) && $lastReal[0] === T_TRY);
        } elseif ($tok === '}') {
            array_pop($braceStack);
        } elseif (is_array($tok) && $tok[0] === T_NEW) {
            // Le prochain token significatif est le nom de classe — vérifié juste après.
        } elseif (is_array($tok) && $tok[0] === T_STRING
            && substr($tok[1], -7) === 'Manager'
            && is_array($lastReal) && $lastReal[0] === T_NEW
        ) {
            $protected = in_array(true, $braceStack, true);
            if (!$protected) {
                $unprotected[] = $tok[1] . ' (ligne ' . $tok[2] . ')';
            }
        }

        $lastReal = $tok;
    }

    neria_assert(
        empty($unprotected),
        'Instanciation(s) de Manager non protégée(s) par try/catch dans runBackgroundJobs() : '
        . implode(', ', $unprotected)
        . ' — régression du bug HealthCheckManager/CalendarManager corrigé le 02/08/2026 (commit 6ee5c0e)'
    );

    return ['pass' => true, 'message' => 'Tous les jobs de runBackgroundJobs() sont protégés par try/catch'];
}
