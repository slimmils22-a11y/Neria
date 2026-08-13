<?php
/**
 * Régression : les 4 boutons BO "rafraîchissement forcé" d'une API tierce
 * (Postmaster/PageSpeed/Search Console/SEO Semrush-Moz) ignoraient
 * totalement le TTL du cache normal (c'est leur rôle), mais rien
 * n'empêchait un double-clic ou une resoumission de renvoyer le même POST
 * plusieurs fois de suite — redéclenchant à chaque fois un appel réel à
 * l'API tierce quel que soit l'état de quota du moment, amplifiant le
 * risque de la boucle de PostmasterManager::fetchDomainStats() (round 160)
 * si le compte est déjà en limitation.
 *
 * Corrigé le 09/08/2026 (round 160) : ajout de Neria::neriaForceRefreshAllowed(),
 * un débit minimal (60s par défaut) entre deux clics autorisés sur une
 * même action, indépendant du cache normal.
 *
 * Test comportemental réel : appelle neriaForceRefreshAllowed() (privée,
 * via Reflection) deux fois de suite pour la même clé — le 1er appel doit
 * réussir (true), le 2e doit échouer (false, appel trop rapproché).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $module = neria_test_module();
    $key    = 'regtest_' . random_int(100000, 999999);

    // Nettoie tout résidu d'un run précédent pour cette clé fabriquée
    // aléatoirement (collision quasi impossible, mais par prudence).
    Configuration::deleteByName('NERIA_FORCE_REFRESH_' . strtoupper($key));

    try {
        $ref = new ReflectionMethod($module, 'neriaForceRefreshAllowed');
        $ref->setAccessible(true);

        $first = $ref->invoke($module, $key, 60);
        neria_assert(
            $first === true,
            "neriaForceRefreshAllowed() a refusé le premier appel (aucun historique) — régression du bug corrigé le 09/08/2026 (round 160)"
        );

        $second = $ref->invoke($module, $key, 60);
        neria_assert(
            $second === false,
            "neriaForceRefreshAllowed() a autorisé un 2e appel immédiat après le premier — régression du bug corrigé le 09/08/2026 (round 160) : un double-clic redéclencherait de nouveau un appel réel à l'API tierce sans aucun débit minimal"
        );

        return [
            'pass'    => true,
            'message' => "Neria::neriaForceRefreshAllowed() applique bien un débit minimal entre deux clics sur un bouton de rafraîchissement forcé — bug corrigé le 09/08/2026 (round 160)",
        ];
    } finally {
        Configuration::deleteByName('NERIA_FORCE_REFRESH_' . strtoupper($key));
    }
}
