<?php
/**
 * Régression : `LicenseManager::getStatusForDisplay()` calculait
 * `grace_days_left`/`in_grace_period` uniquement à partir des timestamps
 * (`expires`/`CONFIG_LAST_CHECK`/`revokedAt`), sans jamais consulter
 * `isDomainMismatch()` — contrairement à `isEmailSendingAllowed()` (round
 * 206) qui refuse EXPLICITEMENT tout repli sur la grâce en cas de mismatch
 * de domaine ("refus explicite, PAS de repli sur le délai de grâce — celui-
 * ci existe pour une panne réseau/serveur, pas pour couvrir une
 * réutilisation du jeton sur un domaine non enregistré").
 *
 * Bug identifié le 10/09/2026 (round 332, audit LicenseManager/
 * TranslationHistoryManager) : sur une installation clonée vers un autre
 * domaine (staging, migration) après expiration naturelle de la licence,
 * le statut retourné pouvait afficher `sending_allowed=false` (bloqué à
 * raison par le mismatch) MAIS `in_grace_period=true` avec un
 * `grace_days_left` positif — une combinaison de données internes
 * incohérente (même si les 2 seuls consommateurs actuels de ce statut, le
 * bandeau BO et neria.php, priorisent déjà `!sending_allowed` en premier et
 * n'exposaient donc pas ce texte contradictoire à l'utilisateur — un
 * correctif de cohérence de données, pas une régression visible avérée).
 *
 * Corrigé le 10/09/2026 (round 332) : `getStatusForDisplay()` détecte
 * désormais le mismatch de domaine (même logique que
 * `isEmailSendingAllowed()`) et force `grace_days_left = null` dans ce cas
 * ; nouvelle clé `domain_mismatch` exposée dans le tableau retourné.
 *
 * Test structurel (impossible de forger un jeton signé Ed25519 valide en
 * test — le module ne contient pas la clé privée, voir test_435) +
 * comportemental sur le chemin SANS token (le plus courant en test) :
 * vérifie la présence du garde-fou dans le code, et que le scénario du
 * round 300 (test_564, jeton expiré + LAST_CHECK, sans token donc sans
 * mismatch possible) reste inchangé — `domain_mismatch` doit valoir false.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/LicenseManager.php';

    // Vérification structurelle : la détection existe et suppprime bien
    // le calcul de grâce dans ce cas.
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/LicenseManager.php');
    neria_assert($src !== false, 'Impossible de lire src/LicenseManager.php');

    $posFn = strpos($src, 'public function getStatusForDisplay(): array');
    neria_assert($posFn !== false, 'getStatusForDisplay() introuvable — jeu de test invalide');
    // Fenêtre élargie 4300→4600 hors round (12/09/2026, suite round 341) :
    // commentaires de déchiffrement ajoutés (chiffrement au repos de
    // NERIA_LICENSE_KEY/_TOKEN) ont poussé les offsets de fin de méthode.
    $body = substr($src, $posFn, 4600);

    neria_assert(
        strpos($body, '$domainMismatch = $this->isDomainMismatch($cachedDomain);') !== false,
        "getStatusForDisplay() ne détecte plus le mismatch de domaine — régression du bug corrigé le 10/09/2026 (round 332) : le bandeau BO pourrait de nouveau afficher in_grace_period=true alors que l'envoi est bloqué par un domaine non enregistré"
    );
    neria_assert(
        strpos($body, 'if ($domainMismatch) {') !== false,
        "getStatusForDisplay() ne court-circuite plus le calcul de grace_days_left en cas de mismatch de domaine — régression du bug corrigé le 10/09/2026 (round 332)"
    );
    neria_assert(
        strpos($body, "'domain_mismatch'  => \$domainMismatch,") !== false,
        "getStatusForDisplay() n'expose plus la clé 'domain_mismatch' dans son tableau de retour — régression du bug corrigé le 10/09/2026 (round 332)"
    );

    // Vérification comportementale : sur le chemin SANS token (aucun
    // mismatch possible, faute de jeton à décoder), domain_mismatch doit
    // rester false et ne rien casser — même scénario que test_564 (round
    // 300, jeton expiré + LAST_CHECK), rejoué ici pour confirmer la
    // non-régression après l'ajout du nouveau garde-fou.
    $origKey       = (string) Configuration::get('NERIA_LICENSE_KEY');
    $origExpires   = (string) Configuration::get('NERIA_LICENSE_EXPIRES');
    $origRevokedAt = (string) Configuration::get('NERIA_LICENSE_REVOKED_AT');
    $origLastCheck = (string) Configuration::get('NERIA_LICENSE_LAST_CHECK');
    $origToken     = (string) Configuration::get('NERIA_LICENSE_TOKEN');

    try {
        Configuration::updateGlobalValue('NERIA_LICENSE_KEY', 'NERIA-TEST-TEST-TEST');
        Configuration::updateGlobalValue('NERIA_LICENSE_EXPIRES', time() - 86400);
        Configuration::deleteByName('NERIA_LICENSE_REVOKED_AT');
        Configuration::deleteByName('NERIA_LICENSE_TOKEN');
        Configuration::updateGlobalValue('NERIA_LICENSE_LAST_CHECK', time() - (30 * 86400));

        $mgr    = new LicenseManager(neria_test_module());
        $status = $mgr->getStatusForDisplay();

        neria_assert(
            array_key_exists('domain_mismatch', $status),
            "getStatusForDisplay() n'expose plus la clé 'domain_mismatch' — jeu de test invalide ou régression"
        );
        neria_assert(
            $status['domain_mismatch'] === false,
            "getStatusForDisplay() signale à tort un mismatch de domaine alors qu'aucun token n'est présent (rien à comparer) — comportement nominal cassé par l'ajout du garde-fou round 332"
        );
        neria_assert(
            $status['in_grace_period'] === true && $status['grace_days_left'] !== null,
            "getStatusForDisplay() ne détecte plus la période de grâce du 3e scénario (round 300, test_564) sur le chemin sans token — comportement nominal cassé par l'ajout du garde-fou round 332"
        );
    } finally {
        if ($origKey === '') { Configuration::deleteByName('NERIA_LICENSE_KEY'); } else { Configuration::updateGlobalValue('NERIA_LICENSE_KEY', $origKey); }
        if ($origExpires === '') { Configuration::deleteByName('NERIA_LICENSE_EXPIRES'); } else { Configuration::updateGlobalValue('NERIA_LICENSE_EXPIRES', $origExpires); }
        if ($origRevokedAt === '') { Configuration::deleteByName('NERIA_LICENSE_REVOKED_AT'); } else { Configuration::updateGlobalValue('NERIA_LICENSE_REVOKED_AT', $origRevokedAt); }
        if ($origLastCheck === '') { Configuration::deleteByName('NERIA_LICENSE_LAST_CHECK'); } else { Configuration::updateGlobalValue('NERIA_LICENSE_LAST_CHECK', $origLastCheck); }
        if ($origToken === '') { Configuration::deleteByName('NERIA_LICENSE_TOKEN'); } else { Configuration::updateGlobalValue('NERIA_LICENSE_TOKEN', $origToken); }
    }

    return [
        'pass'    => true,
        'message' => "LicenseManager::getStatusForDisplay() détecte désormais le mismatch de domaine et supprime le grace_days_left contradictoire dans ce cas, sans casser le scénario nominal (grâce round 300 sans token) — bug corrigé le 10/09/2026 (round 332)",
    ];
}
