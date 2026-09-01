<?php
/**
 * Régression : `views/templates/admin/abtest.tpl` codait en dur le symbole
 * `€` à 3 endroits (CA variante A, CA variante B, colonne historique
 * "revenue_a / revenue_b") au lieu d'utiliser `{$currency_symbol}` — déjà
 * assigné à Smarty par `neria.php` (`'currency_symbol' => $this->context
 * ->currency->sign ?? '€'`) et correctement utilisé partout ailleurs dans
 * le module (`stats.tpl`, 4 occurrences).
 *
 * Bug identifié le 01/09/2026 (round 265, audit "incohérence de devise
 * entre calcul et affichage"). Une boutique configurée en USD/GBP/toute
 * devise non-euro affichait quand même "€" sur l'écran A/B Testing (CA par
 * variante, historique des tests), alors que `stats.tpl` — la page sœur —
 * affiche bien le symbole réel de la devise de la boutique.
 *
 * Corrigé le 01/09/2026 (round 265) : les 3 occurrences remplacées par
 * `{$currency_symbol}`, cohérent avec `stats.tpl`. `currency_symbol` est
 * assigné INCONDITIONNELLEMENT dans `neria.php` (pas seulement quand
 * l'onglet actif est 'stats'), et `abtest.tpl` est rendu via le même
 * moteur Smarty partagé (`renderTemplate()` → `$this->context->smarty->
 * fetch()`), donc la variable y est bien disponible — vérifié en lisant
 * le code réel avant correctif.
 *
 * Test structurel (rendre réellement une page BO Smarty complète est hors
 * périmètre sûr d'un test isolé — même contrainte que test_251/test_253
 * sur ce même fichier) : vérifie qu'aucun symbole `€` codé en dur ne
 * subsiste dans abtest.tpl, et que `{$currency_symbol}` y apparaît bien
 * aux 4 emplacements attendus (2 blocs de variante + 2 sur la ligne
 * d'historique "revenue_a / revenue_b").
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/views/templates/admin/abtest.tpl');
    neria_assert($src !== false, 'Impossible de lire views/templates/admin/abtest.tpl');

    neria_assert(
        strpos($src, '€') === false,
        "views/templates/admin/abtest.tpl contient de nouveau un symbole '€' codé en dur — régression du bug corrigé le 01/09/2026 (round 265) : une boutique en devise non-euro afficherait de nouveau '€' sur l'écran A/B Testing malgré une devise réelle différente"
    );

    $count = substr_count($src, '{$currency_symbol}');
    neria_assert(
        $count === 4,
        "views/templates/admin/abtest.tpl n'utilise plus {\$currency_symbol} aux 4 emplacements attendus (trouvé {$count}) — régression du bug corrigé le 01/09/2026 (round 265)"
    );

    // Vérification complémentaire : neria.php assigne bien currency_symbol
    // de façon inconditionnelle (pas seulement pour l'onglet 'stats'), donc
    // toujours disponible quand abtest.tpl est rendu.
    $neriaSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($neriaSrc !== false, 'Impossible de lire neria.php');
    neria_assert(
        strpos($neriaSrc, "'currency_symbol'  => \$this->context->currency->sign ?? '€',") !== false,
        "neria.php n'assigne plus currency_symbol à Smarty au point attendu — jeu de test invalide ou régression connexe"
    );

    return [
        'pass'    => true,
        'message' => "views/templates/admin/abtest.tpl affiche désormais le symbole de devise réel de la boutique ({\$currency_symbol}) au lieu d'un '€' codé en dur, cohérent avec stats.tpl — bug corrigé le 01/09/2026 (round 265)",
    ];
}
