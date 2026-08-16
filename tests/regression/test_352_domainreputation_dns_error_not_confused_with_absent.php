<?php
/**
 * Régression : checkSpf()/checkDkim()/checkDmarc()/checkBlacklists()
 * traitaient une erreur DNS réseau/résolveur (dns_get_record() === false)
 * exactement comme un NXDOMAIN légitime (tableau vide, `?: []`) — une panne
 * DNS temporaire produisait un rapport "aucun SPF/DKIM/DMARC, 0 RBL hit" au
 * lieu d'un échec, mis en cache 24h, pouvant déclencher une fausse alerte
 * critique (grade F) ou au contraire masquer un vrai blacklistage.
 * checkBlacklists() incrémentait en plus `checked` même sur une erreur
 * réseau — une panne DNS totale donnait `checked === count(RBL_LIST)` avec
 * `hits=[]`, accordant les 25 points RBL pleins dans computeScore() alors
 * qu'aucune requête n'avait réellement abouti.
 *
 * Corrigé le 15/08/2026 (round 177) : chaque méthode distingue désormais
 * `false` (erreur réseau, drapeau 'dns_error') de `[]` (NXDOMAIN légitime),
 * computeScore() applique un score neutre (ni plein ni nul) sur dns_error,
 * comme il le fait déjà pour timed_out. checkBlacklists() ne compte plus
 * une requête échouée dans `checked`, faisant naturellement retomber
 * 'timed_out' à vrai (réutilisation du mécanisme existant) sur une panne
 * DNS totale ou partielle.
 *
 * Test : comportemental réel (un vrai domaine avec DNS fonctionnel — pas de
 * dns_error attendu, sanity check) + structurel (le drapeau dns_error et sa
 * prise en compte dans computeScore() restent bien présents — une panne DNS
 * réelle n'est pas reproductible de façon fiable dans cette suite).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/DomainReputationManager.php';

    $mgr = new DomainReputationManager(neria_test_module());

    $refSpf = new ReflectionMethod(DomainReputationManager::class, 'checkSpf');
    $refSpf->setAccessible(true);
    $result = $refSpf->invoke($mgr, 'google.com', null);

    neria_assert(is_array($result), "checkSpf() ne renvoie plus un tableau — jeu de test invalide");

    if ($result['found'] === false) {
        neria_assert(
            array_key_exists('dns_error', $result),
            "checkSpf() ne renvoie plus de clé 'dns_error' quand found=false — jeu de test invalide ou régression"
        );
        neria_assert(
            empty($result['dns_error']),
            "checkSpf('google.com') signale une erreur DNS sur un domaine dont la résolution DNS fonctionne normalement dans cet environnement — soit un vrai problème réseau local (à ignorer pour ce test), soit une régression du drapeau dns_error"
        );
    }

    $refBl = new ReflectionMethod(DomainReputationManager::class, 'checkBlacklists');
    $refBl->setAccessible(true);
    $blResult = $refBl->invoke($mgr, '8.8.8.8', null);
    neria_assert(
        is_array($blResult) && array_key_exists('timed_out', $blResult),
        "checkBlacklists() ne renvoie plus de clé 'timed_out' — jeu de test invalide"
    );

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/DomainReputationManager.php');
    neria_assert($src !== false, 'Impossible de lire src/DomainReputationManager.php');

    neria_assert(
        strpos($src, "\$dnsError = (\$raw === false);") !== false,
        "checkSpf()/checkDmarc() ne distinguent plus une erreur DNS (false) d'un NXDOMAIN légitime ([]) — régression du bug corrigé le 15/08/2026 (round 177)"
    );
    neria_assert(
        strpos($src, "if (\$result === false) {\n                continue;\n            }") !== false,
        "checkBlacklists() compte de nouveau une requête RBL en échec réseau dans 'checked' — régression du bug corrigé le 15/08/2026 (round 177) : une panne DNS totale accorderait de nouveau les 25 points RBL pleins dans computeScore()"
    );
    neria_assert(
        strpos($src, "elseif (!empty(\$spf['dns_error']))") !== false
        && strpos($src, "elseif (!empty(\$dmarc['dns_error']))") !== false
        && strpos($src, "!empty(\$dkim['timed_out']) || !empty(\$dkim['dns_error'])") !== false,
        "computeScore() n'applique plus de score neutre sur une erreur DNS (SPF/DKIM/DMARC) — régression du bug corrigé le 15/08/2026 (round 177) : une panne DNS transitoire serait de nouveau traitée comme un domaine confirmé sans protection"
    );

    return [
        'pass'    => true,
        'message' => "DomainReputationManager distingue bien une erreur DNS réseau d'un NXDOMAIN légitime, avec score neutre associé dans computeScore() — bug corrigé le 15/08/2026 (round 177)",
    ];
}
