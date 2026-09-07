<?php
/**
 * Régression : GdprAuditManager::auditEncryption() comptait les
 * enregistrements chiffrés/en clair sans désactiver le cache SQL de
 * PrestaShop (paramètre $use_cache, true par défaut) — contrairement à la
 * discipline appliquée systématiquement partout ailleurs dans ce même
 * fichier (auditRetention(), auditUnsubscribe(), purge log — rounds
 * 210-223/302). Un marchand cliquant "Chiffrer les enregistrements
 * existants" (encryptExistingRecords(), qui modifie réellement les lignes)
 * puis rechargeant l'onglet RGPD pouvait voir un grade/score encore basé
 * sur l'ancien décompte périmé.
 *
 * Corrigé le 07/09/2026 (round 315) : $use_cache=false sur les 2 lectures.
 *
 * Vérification structurelle ciblée : confirme que le 2e argument `false`
 * est bien présent sur les 2 appels réels, à l'intérieur du corps de la
 * méthode.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/GdprAuditManager.php');
    neria_assert($src !== false, 'Impossible de lire src/GdprAuditManager.php');

    $posFn = strpos($src, 'public function auditEncryption(): array');
    neria_assert($posFn !== false, 'auditEncryption() introuvable — jeu de test invalide');

    $body = substr($src, $posFn, 2500);

    neria_assert(
        strpos($body, 'totalVars') !== false && strpos($body, 'encrypted') !== false,
        "GdprAuditManager::auditEncryption() a changé de forme inattendue — jeu de test invalide"
    );

    // Cible le code réel, pas un strpos('false')/substr_count en texte libre
    // qui matcherait aussi le commentaire explicatif ci-dessus
    // ("$use_cache=false") — même piège d'auto-collision rounds 246/312.
    $posTotal = strpos($body, "AND `id_shop` = {\$this->idShop}\",");
    neria_assert($posTotal !== false, 'jeu de test invalide (1re requête introuvable)');
    $tail1 = substr($body, $posTotal, 70);
    $posEnc = strpos($body, "AND `id_shop` = {\$this->idShop}\",", $posTotal + 1);
    neria_assert($posEnc !== false, 'jeu de test invalide (2e requête introuvable)');
    $tail2 = substr($body, $posEnc, 70);
    neria_assert(
        strpos($tail1, 'false') !== false && strpos($tail2, 'false') !== false,
        "GdprAuditManager::auditEncryption() n'appelle plus getValue() avec \$use_cache=false sur les 2 lectures — régression du bug corrigé le 07/09/2026 (round 315)"
    );

    return [
        'pass'    => true,
        'message' => "GdprAuditManager::auditEncryption() contourne bien le cache SQL PrestaShop (\$use_cache=false) — bug corrigé le 07/09/2026 (round 315)",
    ];
}
