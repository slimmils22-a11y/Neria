<?php
/**
 * Régression : DeliverabilityScorer::getDnsStatus() annonçait (commentaire
 * du fichier) le même budget DNS que DomainReputationManager::checkDkim(),
 * mais la deadline n'était en réalité vérifiée que dans la boucle des
 * sélecteurs DKIM — jamais avant les appels SPF/DMARC eux-mêmes.
 * DomainReputationManager::checkSpf()/checkDmarc() honorent pourtant bien
 * la deadline depuis le round 165 (13/08/2026).
 *
 * Corrigé le 26/08/2026 (round 218) : contrôle de la deadline ajouté
 * avant les appels SPF et DMARC, comme dans la référence.
 *
 * Test structurel : vérifie la présence des 2 nouveaux contrôles de
 * deadline dans le code source.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/DeliverabilityScorer.php');
    neria_assert($src !== false, 'Impossible de lire src/DeliverabilityScorer.php');

    $posMethod = strpos($src, 'private function getDnsStatus(string $domain): array');
    neria_assert($posMethod !== false, 'getDnsStatus() introuvable — jeu de test invalide');

    $posSpf   = strpos($src, "// SPF : enregistrement TXT commençant par \"v=spf1\"");
    $posDmarc = strpos($src, '// DMARC : TXT sur _dmarc.<domaine>');
    neria_assert($posSpf !== false && $posDmarc !== false, 'Blocs SPF/DMARC introuvables — jeu de test invalide');

    $beforeSpf   = substr($src, $posMethod, $posSpf - $posMethod);
    $beforeDmarc = substr($src, $posSpf, $posDmarc - $posSpf);

    neria_assert(
        substr_count($beforeSpf, 'if (microtime(true) >= $deadline) {') >= 1,
        "DeliverabilityScorer::getDnsStatus() ne vérifie plus la deadline avant l'appel SPF — régression du bug corrigé le 26/08/2026 (round 218) : une résolution DNS bloquante sur SPF pourrait de nouveau dépasser le budget promis"
    );
    neria_assert(
        substr_count($beforeDmarc, 'if (microtime(true) >= $deadline) {') >= 1,
        "DeliverabilityScorer::getDnsStatus() ne vérifie plus la deadline avant l'appel DMARC — régression du bug corrigé le 26/08/2026 (round 218)"
    );

    return [
        'pass'    => true,
        'message' => "DeliverabilityScorer::getDnsStatus() vérifie bien la deadline DNS avant les appels SPF et DMARC, comme DomainReputationManager — bug corrigé le 26/08/2026 (round 218)",
    ];
}
