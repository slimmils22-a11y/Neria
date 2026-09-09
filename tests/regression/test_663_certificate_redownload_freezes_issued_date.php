<?php
/**
 * Régression : `CertificateManager::generatePdf()` calculait TOUJOURS la
 * date "certifiée" imprimée sur le PDF (libellé
 * `certificate_pdf_label_certified_date`) via
 * `\NeriaTools::formatDate('now', $lang)` — y compris depuis `redownload()`,
 * qui n'avait aucun moyen de lui transmettre la VRAIE `date_issued`
 * enregistrée en base à l'émission. Un employé cliquant sur
 * "Retélécharger" des mois après l'émission voyait le PDF afficher la date
 * du jour du clic au lieu de la date réelle d'émission — une divergence
 * directe avec l'intention documentée round 301 pour la signature figée
 * ("valeur probante de document daté").
 *
 * Bug identifié le 09/09/2026 (round 330, audit CertificateManager).
 *
 * Corrigé le 09/09/2026 (round 330) : nouveau paramètre
 * `$frozenIssuedDate` sur `generatePdf()` (même principe que
 * `$frozenSigPath`, round 301) — `redownload()` transmet désormais
 * `$row['date_issued']`, `issue()` continue de passer `null` (certificat
 * pas encore inséré, 'now' reste correct à l'émission). `generatePdf()`
 * retourne aussi désormais `issued_str` (traçabilité, comme `sig_path`).
 *
 * Test comportemental réel : émet un vrai certificat (TCPDF), rétro-date
 * `date_issued` en base à une date fixe du passé, appelle `redownload()`
 * et vérifie via la clé `issued_str` renvoyée que le PDF regénéré affiche
 * bien la date FIGÉE d'origine, pas la date du jour du test.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CertificateManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();

    $orderRow = $db->getRow(
        "SELECT o.id_order, o.id_customer FROM {$prefix}orders o
         INNER JOIN {$prefix}customer c ON c.id_customer = o.id_customer
         WHERE c.active = 1 AND c.deleted = 0"
    );
    $prodRow = $db->getRow("SELECT id_product FROM {$prefix}product");
    neria_assert(
        $orderRow !== false && $prodRow !== false,
        "jeu de test invalide : aucune commande/produit disponible en base de test"
    );
    $idOrder   = (int) $orderRow['id_order'];
    $idProduct = (int) $prodRow['id_product'];

    $idCertificate = null;

    try {
        $mgr = new CertificateManager(neria_test_module());
        $err = $mgr->issue($idOrder, $idProduct, 0, '', 'Note regtest663', false);
        neria_assert($err === '', "CertificateManager::issue() a échoué : {$err}");

        $certRow = $db->getRow(
            "SELECT id_certificate FROM {$prefix}neria_certificate
             WHERE id_order = {$idOrder} AND id_product = {$idProduct} AND artisan_note = 'Note regtest663'
             ORDER BY id_certificate DESC"
        );
        neria_assert($certRow !== false, "aucun certificat trouvé après issue() — jeu de test invalide");
        $idCertificate = (int) $certRow['id_certificate'];

        // Rétro-date la date_issued à une date fixe et ancienne, sans
        // rapport avec la date du jour du test.
        $frozenDate = '2020-01-15 10:00:00';
        $db->execute(
            "UPDATE {$prefix}neria_certificate SET date_issued = '{$frozenDate}' WHERE id_certificate = {$idCertificate}"
        );

        $result = $mgr->redownload($idCertificate);
        neria_assert(
            !isset($result['error']),
            'redownload() a échoué : ' . ($result['error'] ?? '?')
        );
        neria_assert(
            isset($result['issued_str']),
            "redownload()/generatePdf() ne renvoie plus la clé 'issued_str' — jeu de test invalide ou régression de traçabilité"
        );

        // Résout la MÊME langue que redownload()/generatePdf() (via la
        // méthode privée resolveCertificateLang()) plutôt que de supposer
        // 'fr' en dur — le format de date rendu par NeriaTools::formatDate()
        // dépend de la langue résolue (ex. jj/mm/aaaa vs jj.mm.aaaa).
        $order    = new \Order($idOrder);
        $customer = new \Customer((int) $order->id_customer);
        $refMethod = new \ReflectionMethod('CertificateManager', 'resolveCertificateLang');
        $refMethod->setAccessible(true);
        $resolvedLang = $refMethod->invoke($mgr, $order, (int) $customer->id_lang);

        $expectedFrozen = \NeriaTools::formatDate($frozenDate, $resolvedLang);
        $wrongToday      = \NeriaTools::formatDate('now', $resolvedLang);

        neria_assert(
            $result['issued_str'] === $expectedFrozen,
            "redownload() affiche 'issued_str' = '{$result['issued_str']}' au lieu de la date FIGÉE d'émission '{$expectedFrozen}' — régression du bug corrigé le 09/09/2026 (round 330) : la date certifiée changerait de nouveau à chaque retéléchargement"
        );
        // Contre-épreuve explicite : si le bug réapparaît, issued_str vaudrait
        // la date du jour du test, pas la date figée.
        neria_assert(
            $result['issued_str'] !== $wrongToday || $wrongToday === $expectedFrozen,
            "redownload() affiche la date du jour ('{$wrongToday}') au lieu de la date figée d'émission — régression du bug corrigé le 09/09/2026 (round 330)"
        );

        return [
            'pass'    => true,
            'message' => "CertificateManager::redownload() affiche bien la date FIGÉE d'émission (date_issued) sur le PDF regénéré, pas la date du jour du retéléchargement — bug corrigé le 09/09/2026 (round 330)",
        ];
    } finally {
        if ($idCertificate !== null) {
            $db->execute("DELETE FROM {$prefix}neria_certificate WHERE id_certificate = {$idCertificate}");
        }
    }
}
