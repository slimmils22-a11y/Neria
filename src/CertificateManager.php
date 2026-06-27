<?php
/**
 * CertificateManager — Certificats d'authenticité dynamiques
 *
 * Génère un PDF signé (TCPDF + signature manuscrite Neria) et l'envoie
 * au client par email. Déclenchement manuel depuis la fiche commande PS.
 *
 * @author  Neria
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class CertificateManager
{
    // ── Config keys ───────────────────────────────────────────────
    const CFG_ENABLED       = 'NERIA_CERT_ENABLED';
    const CFG_SERIAL_PREFIX = 'NERIA_CERT_SERIAL_PREFIX';
    const CFG_TITLE         = 'NERIA_CERT_TITLE';
    const CFG_SUBTITLE      = 'NERIA_CERT_SUBTITLE';
    const CFG_BODY          = 'NERIA_CERT_BODY';
    const CFG_QR_ENABLED    = 'NERIA_CERT_QR_ENABLED';
    const CFG_QR_URL        = 'NERIA_CERT_QR_URL';

    const TABLE = 'neria_certificate';

    /** @var \Module */
    private $module;
    /** @var \Db */
    private $db;
    /** @var int */
    private $idShop;

    public function __construct(\Module $module)
    {
        $this->module = $module;
        $this->db     = \Db::getInstance();
        $this->idShop = (int) \Context::getContext()->shop->id;
    }

    // ============================================================
    // ÉMISSION D'UN CERTIFICAT
    // ============================================================

    /**
     * Génère le PDF et l'envoie par email au client.
     * Retourne '' si succès, message d'erreur sinon.
     *
     * @param int    $idOrder
     * @param int    $idProduct
     * @param int    $idOrderDetail
     * @param string $serialNumber  Vide = auto-généré
     * @param string $artisanNote   Note libre de l'artisan
     * @param bool   $sendEmail     Envoyer ou juste générer
     */
    public function issue(
        int    $idOrder,
        int    $idProduct,
        int    $idOrderDetail,
        string $serialNumber = '',
        string $artisanNote  = '',
        bool   $sendEmail    = true
    ): string {
        // ── Commande & client ─────────────────────────────────────
        $order = new \Order($idOrder);
        if (!\Validate::isLoadedObject($order)) {
            if (class_exists('WatchdogManager')) {
                (new \WatchdogManager($this->module))->error(
                    sprintf('Certificat impossible : commande #%d introuvable', $idOrder),
                    '', 'CertificateManager'
                );
            }
            return 'Commande introuvable.';
        }

        $customer = new \Customer((int) $order->id_customer);
        $customerName = trim($customer->firstname . ' ' . $customer->lastname);
        $customerEmail = $customer->email;

        // ── Produit ───────────────────────────────────────────────
        $product = new \Product($idProduct, false, \Context::getContext()->language->id);
        $productName = $product->name ?: 'Produit #' . $idProduct;

        // ── Numéro de série ───────────────────────────────────────
        if ($serialNumber === '') {
            $serialNumber = $this->generateSerial();
        }

        // Vérifie unicité
        if ($this->serialExists($serialNumber)) {
            return 'Ce numéro de série existe déjà : ' . $serialNumber;
        }

        // ── Génère le PDF ─────────────────────────────────────────
        $pdfResult = $this->generatePdf(
            $serialNumber, $order, $customerName, $productName, $artisanNote
        );
        if (isset($pdfResult['error'])) {
            if (class_exists('WatchdogManager')) {
                (new \WatchdogManager($this->module))->error(
                    sprintf('Certificat PDF échoué (commande #%d) : %s', $idOrder, $pdfResult['error']),
                    '', 'CertificateManager'
                );
            }
            return $pdfResult['error'];
        }

        $pdfContent = $pdfResult['content'];
        $pdfPath    = $pdfResult['path'];

        // ── Sauvegarde en DB ──────────────────────────────────────
        $this->db->insert(self::TABLE, [
            'id_shop'         => $this->idShop,
            'id_order'        => $idOrder,
            'id_product'      => $idProduct,
            'id_order_detail' => $idOrderDetail,
            'serial_number'   => pSQL($serialNumber),
            'customer_name'   => pSQL($customerName),
            'product_name'    => pSQL($productName),
            'artisan_note'    => pSQL($artisanNote),
            'pdf_path'        => pSQL($pdfPath),
            'emailed'         => $sendEmail ? 0 : 0,
            'date_issued'     => date('Y-m-d H:i:s'),
            'date_add'        => date('Y-m-d H:i:s'),
        ]);

        $idCertificate = (int) $this->db->Insert_ID();

        // ── Envoi email ───────────────────────────────────────────
        if ($sendEmail) {
            $err = $this->sendCertificateEmail(
                $customerEmail, $customerName, $productName,
                $serialNumber, $pdfContent, $order
            );
            if ($err !== '') {
                if (class_exists('WatchdogManager')) {
                    (new \WatchdogManager($this->module))->error(
                        sprintf('Certificat email échoué (commande #%d, %s) : %s', $idOrder, $customerEmail, $err),
                        '', 'CertificateManager'
                    );
                }
                return $err;
            }

            $this->db->update(self::TABLE, ['emailed' => 1],
                '`id_certificate` = ' . $idCertificate);
        }

        // ── Log Watchdog ──────────────────────────────────────────
        if (class_exists('WatchdogManager')) {
            (new WatchdogManager($this->module))->info(
                'Certificat émis : ' . $serialNumber . ' — ' . $productName
                . ' (commande #' . $idOrder . ', client : ' . $customerEmail . ')',
                '', 'CertificateManager'
            );
        }

        return '';
    }

    /**
     * Retélécharge le PDF d'un certificat déjà émis.
     * Retourne le contenu binaire du PDF ou '' si erreur.
     */
    public function redownload(int $idCertificate): array
    {
        $row = $this->db->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE `id_certificate` = ' . $idCertificate
        );
        if (!$row) {
            return ['error' => 'Certificat introuvable.'];
        }

        $order    = new \Order((int) $row['id_order']);
        $result   = $this->generatePdf(
            $row['serial_number'],
            $order,
            $row['customer_name'],
            $row['product_name'],
            $row['artisan_note'] ?? ''
        );
        return $result;
    }

    // ============================================================
    // GÉNÉRATION PDF
    // ============================================================

    private function generatePdf(
        string $serial,
        \Order $order,
        string $customerName,
        string $productName,
        string $artisanNote
    ): array {
        $tcpdfPath = _PS_ROOT_DIR_ . '/vendor/tecnickcom/tcpdf/tcpdf.php';
        if (!file_exists($tcpdfPath)) {
            return ['error' => 'TCPDF introuvable.'];
        }
        require_once $tcpdfPath;

        $shopName   = (string) \Configuration::get('PS_SHOP_NAME');
        $shopDomain = \Tools::getShopDomainSsl(true);
        $dateStr    = (new \DateTime($order->date_add))->format('d/m/Y');
        $issuedStr  = date('d/m/Y');

        $title    = (string) \Configuration::get(self::CFG_TITLE)    ?: 'Certificat d\'Authenticité';
        $subtitle = (string) \Configuration::get(self::CFG_SUBTITLE) ?: 'Document officiel émis par ' . $shopName;
        $bodyText = (string) \Configuration::get(self::CFG_BODY)     ?: 'Ce certificat atteste que la pièce décrite ci-dessus est authentique et a été fabriquée artisanalement par ' . $shopName . '.';
        $qrEnabled = (bool) \Configuration::get(self::CFG_QR_ENABLED);
        $qrBaseUrl = (string) \Configuration::get(self::CFG_QR_URL) ?: $shopDomain;

        // Signature manuscrite
        $sigPath  = '';
        $sigRow   = $this->db->getRow(
            'SELECT `image_path` FROM `' . _DB_PREFIX_ . 'neria_signature`
             WHERE `is_active` = 1 AND `id_shop` = ' . $this->idShop . '
             ORDER BY `date_upd` DESC'
        );
        if ($sigRow && !empty($sigRow['image_path'])) {
            $candidate = _PS_MODULE_DIR_ . 'neria/' . ltrim($sigRow['image_path'], '/');
            if (file_exists($candidate)) {
                $sigPath = $candidate;
            }
        }

        // Logo boutique
        $logoPath = '';
        $psLogo   = _PS_IMG_DIR_ . \Configuration::get('PS_LOGO');
        if (file_exists($psLogo)) {
            $logoPath = $psLogo;
        }

        // ── PDF ───────────────────────────────────────────────────
        try {
            $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
            $pdf->SetCreator('Neria');
            $pdf->SetAuthor($shopName);
            $pdf->SetTitle($title . ' — ' . $serial);
            $pdf->SetMargins(20, 20, 20);
            $pdf->SetAutoPageBreak(true, 20);
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->AddPage();

            // ── Fond luxe (rectangle or pâle) ────────────────────
            $pdf->SetFillColor(250, 246, 240);
            $pdf->Rect(10, 10, 190, 277, 'F');

            // ── Bordure dorée ─────────────────────────────────────
            $pdf->SetDrawColor(179, 139, 89);
            $pdf->SetLineWidth(0.8);
            $pdf->Rect(12, 12, 186, 273);
            $pdf->SetLineWidth(0.3);
            $pdf->Rect(14, 14, 182, 269);

            $y = 22;

            // ── Logo ──────────────────────────────────────────────
            if ($logoPath !== '') {
                $pdf->Image($logoPath, 85, $y, 40, 0, '', '', '', false, 300);
                $y += 28;
            } else {
                $pdf->SetFont('helvetica', 'B', 16);
                $pdf->SetTextColor(26, 26, 46);
                $pdf->SetXY(20, $y);
                $pdf->Cell(170, 10, $shopName, 0, 1, 'C');
                $y += 14;
            }

            // ── Filet or ─────────────────────────────────────────
            $pdf->SetDrawColor(179, 139, 89);
            $pdf->SetLineWidth(0.5);
            $pdf->Line(30, $y, 180, $y);
            $y += 6;

            // ── Titre ─────────────────────────────────────────────
            $pdf->SetFont('times', 'B', 22);
            $pdf->SetTextColor(26, 26, 46);
            $pdf->SetXY(20, $y);
            $pdf->Cell(170, 10, mb_strtoupper($title), 0, 1, 'C');
            $y += 12;

            // ── Sous-titre ────────────────────────────────────────
            $pdf->SetFont('times', 'I', 11);
            $pdf->SetTextColor(100, 80, 40);
            $pdf->SetXY(20, $y);
            $pdf->Cell(170, 8, $subtitle, 0, 1, 'C');
            $y += 14;

            // ── Tableau des informations ──────────────────────────
            $fields = [
                'Produit'          => $productName,
                'Numéro de série'  => $serial,
                'Date de commande' => $dateStr,
                'Certifié le'      => $issuedStr,
                'Propriétaire'     => $customerName,
                'Commande'         => '#' . (int) $order->id,
            ];

            foreach ($fields as $label => $value) {
                $pdf->SetFont('helvetica', 'B', 9);
                $pdf->SetTextColor(130, 100, 50);
                $pdf->SetXY(25, $y);
                $pdf->Cell(50, 7, mb_strtoupper($label), 0, 0, 'L');

                $pdf->SetFont('helvetica', '', 10);
                $pdf->SetTextColor(26, 26, 46);
                $pdf->SetXY(75, $y);
                $pdf->Cell(115, 7, $value, 0, 1, 'L');

                // Filet léger sous chaque ligne
                $pdf->SetDrawColor(220, 200, 170);
                $pdf->SetLineWidth(0.2);
                $pdf->Line(25, $y + 7, 185, $y + 7);
                $y += 9;
            }

            $y += 6;

            // ── Note de l'artisan ─────────────────────────────────
            if ($artisanNote !== '') {
                $pdf->SetFont('times', 'I', 10);
                $pdf->SetTextColor(80, 60, 30);
                $pdf->SetXY(25, $y);
                $pdf->MultiCell(160, 6, '"' . $artisanNote . '"', 0, 'C');
                $y = $pdf->GetY() + 6;
            }

            // ── Corps du texte ────────────────────────────────────
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->SetXY(25, $y);
            $pdf->MultiCell(160, 5, $bodyText, 0, 'C');
            $y = $pdf->GetY() + 10;

            // ── QR code (optionnel) ───────────────────────────────
            if ($qrEnabled) {
                $qrUrl = rtrim($qrBaseUrl, '/') . '?cert=' . urlencode($serial);
                $pdf->write2DBarcode($qrUrl, 'QRCODE,H', 25, $y, 28, 28);

                $pdf->SetFont('helvetica', '', 7);
                $pdf->SetTextColor(150, 150, 150);
                $pdf->SetXY(55, $y + 8);
                $pdf->Cell(130, 5, 'Scannez ce QR code pour vérifier l\'authenticité de ce certificat', 0, 0, 'L');
                $pdf->SetXY(55, $y + 14);
                $pdf->Cell(130, 5, $qrUrl, 0, 0, 'L');
                $y += 32;
            }

            // ── Signature ─────────────────────────────────────────
            $pdf->SetDrawColor(179, 139, 89);
            $pdf->SetLineWidth(0.5);
            $pdf->Line(30, $y, 180, $y);
            $y += 4;

            if ($sigPath !== '') {
                $pdf->Image($sigPath, 80, $y, 50, 0);
                $y += 22;
            }

            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->SetTextColor(100, 80, 40);
            $pdf->SetXY(20, $y);
            $pdf->Cell(170, 6, $shopName . ' — Signature officielle', 0, 1, 'C');

            // ── Pied de page ──────────────────────────────────────
            $pdf->SetFont('helvetica', '', 7);
            $pdf->SetTextColor(180, 160, 130);
            $pdf->SetXY(20, 270);
            $pdf->Cell(170, 5, 'Ce document est un certificat officiel émis par ' . $shopName . ' via Neria Luxury Email Suite.', 0, 0, 'C');

            $pdfContent = $pdf->Output('certificate_' . $serial . '.pdf', 'S');

        } catch (\Throwable $e) {
            return ['error' => 'Erreur PDF : ' . $e->getMessage()];
        }

        // Sauvegarde optionnelle sur disque
        $dir  = _PS_MODULE_DIR_ . 'neria/certificates/';
        $file = 'cert_' . preg_replace('/[^a-z0-9_\-]/i', '_', $serial) . '.pdf';
        $path = '';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (is_dir($dir)) {
            file_put_contents($dir . $file, $pdfContent);
            $path = 'certificates/' . $file;
        }

        return ['content' => $pdfContent, 'path' => $path, 'filename' => 'certificat_' . $serial . '.pdf'];
    }

    // ============================================================
    // ENVOI EMAIL
    // ============================================================

    private function sendCertificateEmail(
        string $to,
        string $customerName,
        string $productName,
        string $serial,
        string $pdfContent,
        \Order $order
    ): string {
        $shopName   = (string) \Configuration::get('PS_SHOP_NAME');
        $shopDomain = \Tools::getShopDomainSsl(true);
        $fromEmail  = (string) \Configuration::get('PS_SHOP_EMAIL')
                   ?: 'noreply@' . parse_url($shopDomain, PHP_URL_HOST);
        $subject    = 'Votre certificat d\'authenticité — ' . $productName;

        $body = '<!DOCTYPE html><html><head><meta charset="utf-8"></head>'
            . '<body style="font-family:serif;background:#f5f0e8;margin:0;padding:30px;">'
            . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:4px;'
            .   'border:1px solid #d4b896;overflow:hidden;">'
            . '<div style="background:#1a1a2e;padding:24px;text-align:center;">'
            .   '<p style="color:#b38b59;font-size:11px;letter-spacing:.15em;text-transform:uppercase;margin:0;">'
            .     $shopName . '</p>'
            .   '<h1 style="color:#fff;font-size:18px;margin:8px 0 0;font-family:serif;">Certificat d\'Authenticité</h1>'
            . '</div>'
            . '<div style="padding:30px;">'
            .   '<p style="color:#555;font-size:14px;line-height:1.7;">Bonjour ' . htmlspecialchars($customerName) . ',</p>'
            .   '<p style="color:#555;font-size:14px;line-height:1.7;">Veuillez trouver ci-joint le certificat d\'authenticité de votre <strong>' . htmlspecialchars($productName) . '</strong>.</p>'
            .   '<div style="background:#faf6f0;border:1px solid #e8d8c0;border-radius:6px;padding:16px 20px;margin:20px 0;">'
            .     '<p style="margin:0;font-size:12px;color:#888;text-transform:uppercase;letter-spacing:.08em;">Numéro de série</p>'
            .     '<p style="margin:6px 0 0;font-size:18px;font-family:monospace;color:#1a1a2e;font-weight:700;">' . htmlspecialchars($serial) . '</p>'
            .   '</div>'
            .   '<p style="color:#999;font-size:12px;line-height:1.6;">Ce document certifie l\'authenticité de votre pièce et constitue un document officiel. Conservez-le précieusement.</p>'
            . '</div>'
            . '<div style="background:#f5f0e8;padding:16px;text-align:center;border-top:1px solid #e8d8c0;">'
            .   '<p style="margin:0;font-size:11px;color:#aaa;">' . $shopName . ' · ' . $shopDomain . '</p>'
            . '</div>'
            . '</div></body></html>';

        $boundary  = '----=_NeriaCert_' . md5(uniqid());
        $headers   = "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n"
                   . "From: {$shopName} <{$fromEmail}>\r\nX-Mailer: Neria-Certificate/1.0\r\n";

        $filename  = 'certificat_' . preg_replace('/[^a-z0-9_\-]/i', '_', $serial) . '.pdf';
        $message   = "--{$boundary}\r\n"
                   . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
                   . $body . "\r\n"
                   . "--{$boundary}\r\n"
                   . "Content-Type: application/pdf; name=\"{$filename}\"\r\n"
                   . "Content-Transfer-Encoding: base64\r\n"
                   . "Content-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n"
                   . chunk_split(base64_encode($pdfContent)) . "\r\n"
                   . "--{$boundary}--";

        $sent = @mail($to, $subject, $message, $headers);
        return $sent ? '' : 'mail() a retourné false.';
    }

    // ============================================================
    // LISTE & STATS
    // ============================================================

    public function getAll(int $limit = 100, int $offset = 0): array
    {
        return $this->db->executeS(
            'SELECT c.*, o.reference AS order_ref
             FROM `' . _DB_PREFIX_ . self::TABLE . '` c
             LEFT JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_order` = c.`id_order`
             WHERE c.`id_shop` = ' . $this->idShop . '
             ORDER BY c.`date_issued` DESC
             LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
        ) ?: [];
    }

    public function countAll(): int
    {
        return (int) $this->db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE `id_shop` = ' . $this->idShop
        );
    }

    public function getByOrder(int $idOrder): array
    {
        return $this->db->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE `id_order` = ' . $idOrder . '
             ORDER BY `date_issued` DESC'
        ) ?: [];
    }

    public function delete(int $idCertificate): void
    {
        $row = $this->db->getRow(
            'SELECT `pdf_path` FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE `id_certificate` = ' . $idCertificate
        );
        if ($row && $row['pdf_path']) {
            $file = _PS_MODULE_DIR_ . 'neria/' . $row['pdf_path'];
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        $this->db->delete(self::TABLE, '`id_certificate` = ' . $idCertificate);
    }

    // ============================================================
    // HELPERS
    // ============================================================

    private function generateSerial(): string
    {
        $prefix = (string) \Configuration::get(self::CFG_SERIAL_PREFIX) ?: 'CERT';
        $year   = date('Y');
        $last   = (int) $this->db->getValue(
            'SELECT MAX(`id_certificate`) FROM `' . _DB_PREFIX_ . self::TABLE . '`'
        );
        return strtoupper($prefix) . '-' . $year . '-' . str_pad($last + 1, 6, '0', STR_PAD_LEFT);
    }

    private function serialExists(string $serial): bool
    {
        return (bool) $this->db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE `serial_number` = \'' . pSQL($serial) . '\''
        );
    }

    public static function createTable(): bool
    {
        return \Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
                `id_certificate`  INT(11)      NOT NULL AUTO_INCREMENT,
                `id_shop`         INT(11)      NOT NULL DEFAULT 1,
                `id_order`        INT(11)      NOT NULL,
                `id_product`      INT(11)      NOT NULL,
                `id_order_detail` INT(11)      NOT NULL DEFAULT 0,
                `serial_number`   VARCHAR(100) NOT NULL,
                `customer_name`   VARCHAR(255) NOT NULL DEFAULT "",
                `product_name`    VARCHAR(255) NOT NULL DEFAULT "",
                `artisan_note`    TEXT         DEFAULT NULL,
                `pdf_path`        VARCHAR(500) DEFAULT NULL,
                `emailed`         TINYINT(1)   NOT NULL DEFAULT 0,
                `date_issued`     DATETIME     NOT NULL,
                `date_add`        DATETIME     NOT NULL,
                PRIMARY KEY (`id_certificate`),
                UNIQUE KEY `uq_serial` (`serial_number`),
                KEY `idx_order`   (`id_order`),
                KEY `idx_shop`    (`id_shop`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
