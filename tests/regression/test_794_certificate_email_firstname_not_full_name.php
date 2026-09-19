<?php
/**
 * Bloc 5 (19/09/2026) : CertificateManager::sendCertificateEmail() passait le
 * NOM COMPLET (« Slim Test ») à la variable {firstname} : la Salutation
 * horaire affichait « Good evening, Slim Test, » dans l'email de certificat
 * réellement reçu (repéré sur ps-test). Corrigé : le prénom réel du client de
 * la commande est utilisé ({customer_name} garde le nom complet).
 *
 * Test structural (l'envoi réel appelle Mail::Send(), non interceptable de
 * façon fiable ici — la preuve comportementale est l'email reçu sur ps-test) :
 * la variable {firstname} est alimentée par le prénom du client, jamais par
 * $customerName, et {customer_name} garde le nom complet.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CertificateManager.php');
    neria_assert($src !== '', "CertificateManager.php introuvable");

    neria_assert(
        preg_match('/\'\{firstname\}\'\s*=>\s*\$firstname,/', $src) === 1,
        "{firstname} n'est plus alimenté par \$firstname (prénom du client)"
    );
    neria_assert(
        preg_match('/\'\{firstname\}\'\s*=>\s*\$customerName/', $src) === 0,
        "{firstname} reçoit de nouveau le nom complet (\$customerName) — salutation « Good evening, Prénom Nom, »"
    );
    neria_assert(
        preg_match('/\$firstname\s*=\s*trim\(\(string\)\s*\(new \\\\Customer\(\(int\)\s*\$order->id_customer\)\)->firstname\)/', $src) === 1,
        "le prénom n'est plus lu depuis le client de la commande"
    );
    neria_assert(
        preg_match('/\'\{customer_name\}\'\s*=>\s*\$customerName,/', $src) === 1,
        "{customer_name} doit garder le nom complet"
    );

    return ['pass' => true, 'message' => "l'email de certificat utilise le prénom du client dans la salutation, plus le nom complet — bloc 5 (19/09/2026)"];
}
