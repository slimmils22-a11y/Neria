<?php
/**
 * Régression : CertificateManager::issue() persistait `date_issued`/
 * `date_add` via `date('Y-m-d H:i:s')` (horloge PHP), alors que
 * getStats() borne ses fenêtres mensuelles (thisMonth/lastMonth/
 * trend_pct) via `NOW()`/`DATE_FORMAT(NOW(), ...)` côté MySQL — même
 * piège déjà corrigé rounds 303 (PropensityScoreManager) et 305
 * (BehavioralCronManager::sendWinBacks()), jamais étendu ici. Si le
 * serveur applicatif (PHP) et le serveur MySQL n'ont pas le même fuseau
 * horaire (cas fréquent en hébergement mutualisé, MySQL en UTC), un
 * certificat émis juste avant/après minuit pouvait se retrouver classé
 * dans le mauvais mois par rapport aux bornes NOW()-based de getStats().
 *
 * Corrigé le 06/09/2026 (round 307) : date_issued/date_add sourcés via
 * `SELECT NOW()` MySQL au lieu de date() PHP.
 *
 * Test comportemental réel : bascule le fuseau horaire PHP vers un
 * décalage extrême (Pacific/Kiritimati, UTC+14) AVANT d'émettre un
 * certificat, puis vérifie que `date_issued` persisté reste proche de
 * `NOW()` MySQL (horloge réelle du serveur DB, inchangée) — pas décalé de
 * ~14h comme le serait `date('Y-m-d H:i:s')` sous ce fuseau PHP.
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

    $originalTz = date_default_timezone_get();
    $idCertificate = null;

    try {
        // Fuseau PHP délibérément à +14h de UTC (le plus extrême existant) —
        // si date_issued était encore écrit via date() PHP, il serait décalé
        // de plusieurs heures par rapport à NOW() MySQL (resté sur le fuseau
        // réel du serveur DB, inchangé par ce réglage PHP local au process).
        date_default_timezone_set('Pacific/Kiritimati');

        $mgr = new CertificateManager(neria_test_module());
        $err = $mgr->issue($idOrder, $idProduct, 0, '', 'Note regtest582', false);
        neria_assert($err === '', "CertificateManager::issue() a échoué : {$err}");

        // NOW() MySQL lu juste après l'émission, DEPUIS LE MÊME PROCESS —
        // toujours sur l'horloge réelle du serveur DB, insensible au
        // date_default_timezone_set() ci-dessus (qui n'affecte que PHP).
        $mysqlNow = (string) $db->getValue('SELECT NOW()');

        date_default_timezone_set($originalTz);
    } catch (\Throwable $e) {
        date_default_timezone_set($originalTz);
        throw $e;
    }

    $certRow = $db->getRow(
        "SELECT id_certificate, date_issued, date_add FROM {$prefix}neria_certificate
         WHERE id_order = {$idOrder} AND id_product = {$idProduct} AND artisan_note = 'Note regtest582'
         ORDER BY id_certificate DESC"
    );
    neria_assert($certRow !== false, "aucun certificat trouvé après issue() — jeu de test invalide");
    $idCertificate = (int) $certRow['id_certificate'];

    try {
        $diffIssued = abs(strtotime($mysqlNow) - strtotime((string) $certRow['date_issued']));
        $diffAdd    = abs(strtotime($mysqlNow) - strtotime((string) $certRow['date_add']));

        neria_assert(
            $diffIssued <= 10,
            "date_issued ('{$certRow['date_issued']}') est décalé de {$diffIssued}s par rapport à NOW() MySQL ('{$mysqlNow}') — régression du bug corrigé le 06/09/2026 (round 307) : date_issued redevenu sourcé via l'horloge PHP (sensible au fuseau du serveur applicatif) au lieu de MySQL"
        );
        neria_assert(
            $diffAdd <= 10,
            "date_add ('{$certRow['date_add']}') est décalé de {$diffAdd}s par rapport à NOW() MySQL ('{$mysqlNow}') — régression du bug corrigé le 06/09/2026 (round 307)"
        );

        return [
            'pass'    => true,
            'message' => "CertificateManager::issue() persiste bien date_issued/date_add via l'horloge MySQL (NOW()), insensible au fuseau horaire du serveur applicatif PHP — bug corrigé le 06/09/2026 (round 307)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_certificate WHERE id_certificate = {$idCertificate}");
    }
}
