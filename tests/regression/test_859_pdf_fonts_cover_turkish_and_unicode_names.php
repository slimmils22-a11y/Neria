<?php
/**
 * Régression (constat réel du 25/09/2026, P9 — PDF de certificat généré dans les 19 langues) : le certificat en
 * turc imprimait « Test Neria taraf?ndan düzenlenmi? resmi belge » et « ?ule » pour « Şule » : les polices core
 * TCPDF (helvetica/times, encodage WinAnsi) n'ont ni ğ Ğ ş Ş İ ı. Même défaut, dans n'importe quelle langue
 * latine, pour un nom de client ou de produit hors WinAnsi (polonais « Łukasz », tchèque, roumain, grec…).
 *
 * Corrigé : le turc utilise toujours une police Unicode (dejavusans/freeserif), et CertificateManager::
 * pdfFontsForLang() bascule vers elle dès qu'un texte imprimé sort du WinAnsi (needsUnicodeFont()) ; le
 * certificat et la fiche d'entretien lui transmettent leurs textes.
 *
 * Test comportemental : choix des polices, puis génération réelle d'un certificat (turc ; français avec un nom
 * polonais ; français ordinaire) et lecture des polices embarquées dans le PDF.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CertificateManager.php';
    $module = neria_test_module();
    $db = neria_test_db();
    $p = neria_test_prefix();

    neria_assert(CertificateManager::pdfFontsForLang('tr')[0] === 'dejavusans', 'Le turc n\'utilise pas une police Unicode — régression du bug corrigé le 25/09/2026');
    neria_assert(CertificateManager::pdfFontsForLang('fr', ['Łukasz Żółć'])[0] === 'dejavusans', 'Un nom polonais ne bascule pas vers une police Unicode');
    neria_assert(CertificateManager::pdfFontsForLang('fr', ['Jean Œuvre — « Kilim » €'])[0] === 'helvetica', 'Des caractères WinAnsi (œ, tirets, guillemets, €) ne doivent pas forcer la police Unicode');
    neria_assert(CertificateManager::pdfFontsForLang('ar')[0] === 'aealarabiya' && CertificateManager::pdfFontsForLang('ja')[0] === 'cid0jp', 'Les polices arabe/japonais ont changé');

    $order = new Order((int) $db->getValue("SELECT id_order FROM {$p}orders ORDER BY id_order DESC"));
    neria_assert(Validate::isLoadedObject($order), 'Jeu de test invalide : aucune commande');
    $mgr = new CertificateManager($module);
    $m = new ReflectionMethod($mgr, 'generatePdf');
    $m->setAccessible(true);
    $fontsOf = static function (string $lang, string $customer, string $product) use ($m, $mgr, $order): array {
        $r = $m->invoke($mgr, 'REGTEST-859', $order, $customer, $product, 'Note', $lang, null, null);
        neria_assert(!empty($r['content']), 'Génération du PDF impossible : ' . json_encode($r, JSON_UNESCAPED_UNICODE));
        if (!empty($r['path']) && file_exists($r['path'])) {
            @unlink($r['path']);
        }
        preg_match_all('/\/BaseFont\s*\/([\w+\-]+)/', (string) $r['content'], $mm);
        return $mm[1];
    };
    $has = static function (array $fonts, string $needle): bool {
        foreach ($fonts as $f) {
            if (stripos($f, $needle) !== false) {
                return true;
            }
        }
        return false;
    };

    $tr = $fontsOf('tr', 'Şule Ğüneş İnce', 'Kilim Örgü — Şişli');
    neria_assert($has($tr, 'DejaVuSans'), "Le PDF turc n'embarque pas de police Unicode : " . implode(',', $tr));
    $pl = $fontsOf('fr', 'Łukasz Żółć', 'Tapis Kilim');
    neria_assert($has($pl, 'DejaVuSans'), "Le PDF d'un client polonais en français n'embarque pas de police Unicode : " . implode(',', $pl));
    $fr = $fontsOf('fr', 'Jean Dupont', 'Tapis Kilim Œuvre');
    neria_assert(!$has($fr, 'DejaVu') && $has($fr, 'Helvetica'), 'Un PDF français ordinaire ne doit pas embarquer de police Unicode (poids et style) : ' . implode(',', $fr));

    return [
        'pass'    => true,
        'message' => "Les PDF (certificat, fiche d'entretien) utilisent une police Unicode en turc et pour tout nom hors WinAnsi (Łukasz, Şule…), et gardent Helvetica/Times pour le français ordinaire — bug corrigé le 25/09/2026",
    ];
}
