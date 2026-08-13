<?php
/**
 * Régression : PostmasterManager::fetchDomainStats() bouclait sur 7 jours,
 * confondant "pas de données ce jour-là" (réponse JSON valide, tableau vide)
 * et "échec réseau/HTTP réel" (429/quota Google inclus — apiGet() retourne
 * null dans les deux cas) sous un même `continue` immédiat vers le jour
 * suivant. Un seul appel en 429/quota déclenchait jusqu'à 6 tentatives
 * supplémentaires SANS AUCUN DÉLAI, aggravant activement la pénalité de
 * quota au lieu de s'arrêter — contrairement au patron déjà correct utilisé
 * pour DeepL dans neria.php (arrêt immédiat au premier échec de lot).
 *
 * Corrigé le 09/08/2026 (round 160) : seul `null` (erreur réelle,
 * apiGet()) interrompt désormais la boucle (`break`) ; un tableau vide
 * mais valide (cas légitime "pas de données ce jour") continue bien à
 * essayer le jour suivant (`continue`).
 *
 * Test structurel (apiGet() effectue un vrai appel réseau à l'API Google,
 * non invocable dans ce jeu de tests) : vérifie que fetchDomainStats()
 * distingue bien `$stat === null` (break) de `empty($stat['domainReputation'])`
 * (continue).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/PostmasterManager.php');
    neria_assert($src !== false, 'Impossible de lire PostmasterManager.php');

    $posFn = strpos($src, 'private function fetchDomainStats(');
    neria_assert($posFn !== false, 'fetchDomainStats() introuvable — jeu de test invalide');
    $body = substr($src, $posFn, 1800);

    neria_assert(
        strpos($body, 'if ($stat === null) {') !== false && strpos($body, 'break;') !== false,
        "fetchDomainStats() n'interrompt plus la boucle sur une erreur réelle (\$stat === null) — régression du bug corrigé le 09/08/2026 (round 160) : un 429/quota redéclencherait jusqu'à 6 appels immédiats supplémentaires"
    );
    neria_assert(
        strpos($body, "empty(\$stat['domainReputation'])") !== false && strpos($body, 'continue;') !== false,
        "fetchDomainStats() ne continue plus vers le jour suivant sur une absence légitime de données — régression potentielle du bug corrigé le 09/08/2026 (round 160)"
    );

    return [
        'pass'    => true,
        'message' => "PostmasterManager::fetchDomainStats() distingue bien une erreur réelle (arrêt immédiat) d'une absence légitime de données (jour suivant) — bug corrigé le 09/08/2026 (round 160)",
    ];
}
