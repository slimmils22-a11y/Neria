<?php
/**
 * Régression : SeoApiManager::getReport() appelait runCheck() sans filet
 * dès que le cache de 24h expirait — si l'appel API échouait (panne
 * transitoire, rate-limit Semrush/Moz), runCheck() retournait null SANS
 * jamais toucher CONFIG_CACHE/CONFIG_CACHE_TIME, alors que le PRÉCÉDENT
 * rapport valide restait accessible via getCachedReport(). Le widget BO
 * passait alors de "hier : bonnes données" à "rien du tout" pour une
 * simple panne passagère, au lieu d'un repli gracieux sur les dernières
 * données connues (obsolètes mais informatives).
 *
 * Corrigé le 15/08/2026 (round 171) : sur échec de runCheck(), getReport()
 * retombe désormais sur le dernier rapport en cache (si son domaine
 * correspond toujours à celui de la boutique), au lieu de null.
 *
 * Test structurel : vérifie la présence du repli sur getCachedReport()
 * après l'appel raté à runCheck().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SeoApiManager.php');
    neria_assert($src !== false, 'Impossible de lire SeoApiManager.php');

    $posFn = strpos($src, 'public function getReport(): ?array');
    neria_assert($posFn !== false, 'getReport() introuvable — jeu de test invalide');
    $body = substr($src, $posFn, 1700);

    neria_assert(
        strpos($body, '$fresh = $this->runCheck();') !== false
        && strpos($body, '$stale = $this->getCachedReport();') !== false,
        "getReport() ne retombe plus sur le dernier rapport en cache après un échec de runCheck() — régression du bug corrigé le 15/08/2026 (round 171) : une panne transitoire de l'API provoquerait de nouveau un black-out complet du widget SEO au lieu d'un repli sur les dernières données connues"
    );
    neria_assert(
        strpos($body, "(\$stale['domain'] ?? null) === \$currentDomain") !== false,
        "Le repli sur le cache obsolète ne revalide plus le domaine — régression potentielle : les données d'un ancien domaine (migration) pourraient être affichées à tort"
    );

    return [
        'pass'    => true,
        'message' => "SeoApiManager::getReport() retombe bien sur le dernier rapport valide en cache (domaine revalidé) plutôt que de retourner null sur une panne transitoire — bug corrigé le 15/08/2026 (round 171)",
    ];
}
