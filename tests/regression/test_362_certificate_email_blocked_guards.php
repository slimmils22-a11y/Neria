<?php
/**
 * Régression : CertificateManager::sendCertificateEmail() n'avait AUCUN
 * garde-fou bounce/blacklist/préférences/cooldown avant Mail::Send() —
 * même piège déjà corrigé pour ManualSendManager::send()/
 * QueueManager::processSingle()/OrderTriggersManager (round 178) mais
 * jamais étendu à l'envoi du certificat. Un certificat "émis" restait
 * marqué comme envoyé en base (emailed=1 dans issue()) même si l'adresse
 * est blacklistée/bounced/désabonnée — invisible, sans retry possible.
 *
 * Corrigé le 16/08/2026 (round 179, audit transversal de fin de série) :
 * les 4 mêmes garde-fous ont été ajoutés avant Mail::Send().
 *
 * Test structurel : vérifie la présence des 4 garde-fous dans
 * sendCertificateEmail() — un test comportemental réel nécessiterait une
 * fixture complète (commande + produit + génération TCPDF réelle), trop
 * lourde pour ce test unitaire ciblé sur le garde-fou lui-même (déjà
 * couvert indirectement par les tests existants de generatePdf()).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CertificateManager.php');
    neria_assert($src !== false, 'Impossible de lire src/CertificateManager.php');

    $posMethod = strpos($src, 'private function sendCertificateEmail(');
    neria_assert($posMethod !== false, "Méthode sendCertificateEmail() introuvable — jeu de test invalide");
    $posSend = strpos($src, '$sent = \Mail::Send(', $posMethod);
    neria_assert($posSend !== false, "Appel Mail::Send() introuvable dans sendCertificateEmail() — jeu de test invalide");
    $body = substr($src, $posMethod, $posSend - $posMethod);

    foreach (['BounceManager::isBounced($to)', 'BlacklistManager($idShop))->isBlacklisted(', 'PreferencesManager($this->module))->isAllowed(', 'CooldownManager())->isDuplicate('] as $marker) {
        neria_assert(
            strpos($body, $marker) !== false,
            "CertificateManager::sendCertificateEmail() ne contient plus le garde-fou attendu ('{$marker}') AVANT Mail::Send() — régression du bug corrigé le 16/08/2026 (round 179) : un certificat émis à une adresse bloquée serait de nouveau marqué comme envoyé avec succès"
        );
    }

    return [
        'pass'    => true,
        'message' => "CertificateManager::sendCertificateEmail() revérifie bien bounce/blacklist/préférences/cooldown avant Mail::Send() — bug corrigé le 16/08/2026 (round 179)",
    ];
}
