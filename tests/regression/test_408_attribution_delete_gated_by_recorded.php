<?php
/**
 * Régression : hookActionOrderStatusPostUpdateImpl() (neria.php) appelait
 * StatsManager::recordConversion() puis journalisait "conversion
 * enregistrée" et supprimait la ligne neria_attribution SANS jamais
 * vérifier le résultat de l'appel — recordConversion() était void jusqu'au
 * round 191.
 *
 * Bug réel identifié le 23/08/2026 (round 191) : sur un échec transitoire
 * (verrou non obtenu, boutique différente, token inconnu), le token était
 * perdu définitivement (DELETE inconditionnel) sans que la conversion
 * n'ait jamais été réellement créditée, ET un log Watchdog mensonger
 * "conversion enregistrée" était écrit.
 *
 * Corrigé le 23/08/2026 (round 191) : $recorded = recordConversion(...)
 * vérifié ; le log et le DELETE ne s'exécutent plus que si $recorded est
 * vrai.
 *
 * Test structurel (simuler un vrai verrou MySQL contesté nécessiterait 2
 * connexions DB concurrentes, hors de portée d'un test isolé — voir
 * test_402 pour la même contrainte) : vérifie par lecture directe du
 * source que le DELETE et le log de succès sont bien conditionnés par le
 * résultat de recordConversion().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $posCall = strpos($src, '$recorded = (new StatsManager($this))->recordConversion($token, $idOrder, $amount, (int) $order->id_shop);');
    neria_assert($posCall !== false, "Appel recordConversion() introuvable ou signature inattendue — jeu de test invalide");

    $posDelete = strpos($src, "DELETE FROM `' . _DB_PREFIX_ . 'neria_attribution` WHERE id_order = ' . \$idOrder", $posCall);
    neria_assert($posDelete !== false, "DELETE de neria_attribution introuvable après recordConversion() — jeu de test invalide");

    $between = substr($src, $posCall, $posDelete - $posCall);
    neria_assert(
        strpos($between, 'if (!$recorded) {') !== false && strpos($between, 'return;') !== false,
        "hookActionOrderStatusPostUpdateImpl() ne vérifie plus le résultat de recordConversion() avant de journaliser/supprimer — régression du bug corrigé le 23/08/2026 (round 191) : un échec transitoire (verrou, boutique différente, token inconnu) journaliserait de nouveau faussement 'conversion enregistrée' et perdrait définitivement le token d'attribution"
    );

    return [
        'pass'    => true,
        'message' => "hookActionOrderStatusPostUpdateImpl() ne journalise/supprime la ligne neria_attribution que si recordConversion() a réellement réussi — bug corrigé le 23/08/2026 (round 191)",
    ];
}
