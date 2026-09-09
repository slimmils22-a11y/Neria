<?php
/**
 * Régression : `CertificateManager::generateSerial()` construisait le
 * préfixe d'année du numéro de série via `date('Y')` (horloge PHP), alors
 * que `date_issued`/`date_add` (mêmes lignes de code, même émission) sont
 * sourcés via l'horloge MySQL depuis le round 307 — piège déjà corrigé
 * rounds 303/305/307 ailleurs dans ce même fichier, jamais étendu à
 * `generateSerial()`.
 *
 * Bug identifié le 09/09/2026 (round 330, audit CertificateManager).
 *
 * Scénario concret : hébergement mutualisé où PHP et MySQL n'ont pas le
 * même fuseau horaire (cas déjà documenté round 307 pour justifier ce
 * même correctif sur date_issued). Un certificat émis dans la fenêtre de
 * bascule d'année (ex. 23h50 Europe/Paris le 31/12, déjà 00h00 UTC le
 * 01/01) obtenait un préfixe d'année ("CERT-2025-...") qui ne
 * correspondait plus à `date_issued` ("2026-01-01..."), lui sourcé via
 * l'horloge MySQL — incohérence visible par le client scannant le QR
 * (page de traçabilité), qui verrait un numéro de série "2025" associé à
 * une date "2026".
 *
 * Corrigé le 09/09/2026 (round 330) : `$year` désormais sourcé via
 * `SELECT YEAR(NOW())` MySQL, comme `date_issued`/`date_add`.
 *
 * Test structurel (un vrai basculement d'année ne peut pas être simulé de
 * façon fiable côté PHP sans dépendre de la date calendaire réelle du jour
 * du test — même limite déjà acceptée pour d'autres correctifs d'horloge
 * de ce fichier, ex. test_582 qui vérifie l'ÉCART avec NOW() MySQL plutôt
 * qu'un vrai rollover) : vérifie que `generateSerial()` lit bien l'année
 * via `SELECT YEAR(NOW())` et plus via `date('Y')`.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CertificateManager.php');
    neria_assert($src !== false, 'Impossible de lire CertificateManager.php');

    $posFn = strpos($src, 'private function generateSerial(int $offset = 0, ?int $idShop = null): string');
    neria_assert($posFn !== false, 'generateSerial() introuvable — jeu de test invalide');
    $body = substr($src, $posFn, 1400);

    neria_assert(
        strpos($body, "SELECT YEAR(NOW())") !== false,
        "generateSerial() ne lit plus l'année via SELECT YEAR(NOW()) MySQL — régression du bug corrigé le 09/09/2026 (round 330) : le préfixe d'année du numéro de série redeviendrait sourcé via l'horloge PHP, pouvant diverger de date_issued (horloge MySQL) autour d'un changement d'année sur un hébergement où PHP et MySQL n'ont pas le même fuseau horaire"
    );
    neria_assert(
        strpos($body, "\$year = date('Y')") === false && strpos($body, '$year = date("Y")') === false,
        "generateSerial() utilise de nouveau \$year = date('Y') (horloge PHP) pour l'année du numéro de série — régression du bug corrigé le 09/09/2026 (round 330)"
    );

    // Vérification comportementale complémentaire : sur le jour réel du
    // test (pas de bascule d'année en cours), le préfixe généré doit tout
    // de même correspondre à YEAR(NOW()) MySQL — garantit que le code
    // exécuté produit bien une valeur cohérente, pas seulement que le
    // texte source contient la bonne requête.
    require_once _PS_MODULE_DIR_ . 'neria/src/CertificateManager.php';
    $db = neria_test_db();
    $mysqlYear = (string) (int) $db->getValue('SELECT YEAR(NOW())');

    $ref = new \ReflectionClass('CertificateManager');
    $method = $ref->getMethod('generateSerial');
    $method->setAccessible(true);
    $mgr = new CertificateManager(neria_test_module());
    $serial = $method->invoke($mgr, 0, (int) \Context::getContext()->shop->id);

    neria_assert(
        strpos($serial, '-' . $mysqlYear . '-') !== false,
        "generateSerial() a produit '{$serial}', qui ne contient pas l'année MySQL courante ('{$mysqlYear}') — régression du bug corrigé le 09/09/2026 (round 330)"
    );

    return [
        'pass'    => true,
        'message' => "CertificateManager::generateSerial() sourcé désormais via YEAR(NOW()) MySQL (comme date_issued/date_add depuis le round 307), pas date('Y') PHP — bug corrigé le 09/09/2026 (round 330)",
    ];
}
