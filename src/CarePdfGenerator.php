<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Certificat d'entretien au format PDF (joint à l'email
 * `care_certificate`).
 *
 * Même identité visuelle que le certificat d'authenticité (cadre bronze,
 * logo de la boutique, polices adaptées à l'écriture de la langue), avec les
 * textes du template `care_certificate` dans la langue du client — aucune
 * traduction supplémentaire : les libellés du corps de l'email sont réutilisés.
 *
 * @author  Neria
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class CarePdfGenerator
{
    /** @var Neria */
    private $module;

    public function __construct(Neria $module)
    {
        $this->module = $module;
    }

    /**
     * Génère le PDF.
     *
     * @param bool $compress false = flux de texte non compressé (tests)
     *
     * @return array{content?: string, filename?: string, error?: string}
     */
    public function generate(
        string $productName,
        string $materials,
        string $careInstructions,
        string $lang,
        int    $idShop,
        bool   $compress = true
    ): array {
        $tcpdfPath = _PS_ROOT_DIR_ . '/vendor/tecnickcom/tcpdf/tcpdf.php';
        if (!file_exists($tcpdfPath)) {
            return ['error' => 'TCPDF introuvable'];
        }
        require_once $tcpdfPath;

        $engine = new TranslationEngine($this->module);
        $t = static function (string $key) use ($engine, $lang, $idShop): string {
            $raw = (string) $engine->get('care_certificate', $key, $lang, $idShop);
            return trim(html_entity_decode(strip_tags($raw), ENT_QUOTES, 'UTF-8'));
        };
        $clean = static function (string $v): string {
            return trim(html_entity_decode(strip_tags($v), ENT_QUOTES, 'UTF-8'));
        };

        $shopName = (string) \Configuration::get('PS_SHOP_NAME', null, null, $idShop);
        $logoPath = '';
        $psLogo   = _PS_IMG_DIR_ . \Configuration::get('PS_LOGO');
        if (is_file($psLogo)) {
            $logoPath = $psLogo;
        }

        [$fontSans, $fontSerif, $isRtl] = CertificateManager::pdfFontsForLang($lang, [$productName, $materials, $careInstructions, $shopName]);

        try {
            $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
            $pdf->SetCompression($compress);
            $pdf->SetCreator('Neria');
            $pdf->SetAuthor($shopName);
            $pdf->SetTitle($t('care_certificate_title'));
            $pdf->SetMargins(20, 20, 20);
            $pdf->SetAutoPageBreak(true, 20);
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->setRTL($isRtl);
            $pdf->AddPage();

            // Cadre : identique au certificat d'authenticité.
            $pdf->SetFillColor(250, 246, 240);
            $pdf->Rect(10, 10, 190, 277, 'F');
            $pdf->SetDrawColor(179, 139, 89);
            $pdf->SetLineWidth(0.8);
            $pdf->Rect(12, 12, 186, 273);
            $pdf->SetLineWidth(0.3);
            $pdf->Rect(14, 14, 182, 269);

            $y = 22;
            if ($logoPath !== '') {
                $pdf->Image($logoPath, 85, $y, 40, 0, '', '', '', false, 300);
                $y += 28;
            } else {
                CertificateManager::pdfSetFont($pdf, $fontSans, 'B', 16);
                $pdf->SetTextColor(26, 26, 46);
                $pdf->SetXY(20, $y);
                $pdf->Cell(170, 10, $shopName, 0, 1, 'C');
                $y += 14;
            }

            $pdf->SetDrawColor(179, 139, 89);
            $pdf->SetLineWidth(0.5);
            $pdf->Line(30, $y, 180, $y);
            $y += 6;

            CertificateManager::pdfSetFont($pdf, $fontSerif, 'B', 22);
            $pdf->SetTextColor(26, 26, 46);
            $pdf->SetXY(20, $y);
            $pdf->Cell(170, 10, mb_strtoupper($t('care_certificate_title')), 0, 1, 'C');
            $y += 14;

            // Introduction (italique, centrée).
            CertificateManager::pdfSetFont($pdf, $fontSerif, 'I', 10);
            $pdf->SetTextColor(100, 80, 40);
            $pdf->SetXY(25, $y);
            $pdf->MultiCell(160, 5.5, $t('care_certificate_intro'), 0, 'C');
            $y = $pdf->GetY() + 8;

            // Produit et matières : lignes libellé / valeur.
            $rows = [
                $t('care_certificate_product')   => $clean($productName),
                $t('care_certificate_materials') => $clean($materials),
            ];
            foreach ($rows as $label => $value) {
                CertificateManager::pdfSetFont($pdf, $fontSans, 'B', 9);
                $pdf->SetTextColor(130, 100, 50);
                $pdf->SetXY(25, $y - 0.8);
                $pdf->Cell(50, 7, mb_strtoupper($label), 0, 0, 'L');

                CertificateManager::pdfSetFont($pdf, $fontSans, '', 10);
                $pdf->SetTextColor(26, 26, 46);
                $pdf->SetXY(75, $y);
                $pdf->MultiCell(110, 7, $value, 0, 'L');
                $y = max($y + 9, $pdf->GetY() + 2);

                $pdf->SetDrawColor(220, 200, 170);
                $pdf->SetLineWidth(0.2);
                $pdf->Line(25, $y - 2, 185, $y - 2);
            }
            $y += 6;

            // Consignes d'entretien : bloc mis en avant.
            CertificateManager::pdfSetFont($pdf, $fontSans, 'B', 9);
            $pdf->SetTextColor(130, 100, 50);
            $pdf->SetXY(25, $y);
            $pdf->Cell(160, 7, mb_strtoupper($t('care_certificate_instructions')), 0, 1, 'L');
            $y += 9;

            CertificateManager::pdfSetFont($pdf, $fontSerif, '', 11);
            $pdf->SetTextColor(26, 26, 46);
            $pdf->SetXY(25, $y);
            $pdf->MultiCell(160, 6.5, $clean($careInstructions), 0, 'L');
            $y = $pdf->GetY() + 12;

            // Signature. La note de l'email (« certificat joint à cet email »)
            // n'a pas sa place DANS le PDF : elle n'est donc pas reprise ici.
            $y += 6;

            $pdf->SetDrawColor(179, 139, 89);
            $pdf->SetLineWidth(0.5);
            $pdf->Line(30, $y, 180, $y);
            $y += 6;

            CertificateManager::pdfSetFont($pdf, $fontSerif, 'I', 10);
            $pdf->SetTextColor(100, 80, 40);
            $pdf->SetXY(25, $y);
            $pdf->Cell(160, 6, $t('care_certificate_signature'), 0, 1, 'C');
            CertificateManager::pdfSetFont($pdf, $fontSerif, 'B', 12);
            $pdf->SetTextColor(26, 26, 46);
            $pdf->SetXY(25, $y + 7);
            $pdf->Cell(160, 7, $shopName, 0, 1, 'C');

            $content = (string) $pdf->Output('', 'S');
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        $slug = (string) \Tools::str2url($clean($productName));
        return [
            'content'  => $content,
            'filename' => 'care-certificate' . ($slug !== '' ? '-' . $slug : '') . '.pdf',
        ];
    }
}
