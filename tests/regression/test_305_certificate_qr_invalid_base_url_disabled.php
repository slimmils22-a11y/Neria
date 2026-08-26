<?php
/**
 * Régression : le QR code du certificat n'avait aucun garde-fou si l'URL
 * de base (CFG_QR_URL ou repli getShopDomainSsl(true)) était vide —
 * TCPDF ne lève aucune exception dans ce cas, le QR s'imprimait avec une
 * URL invalide/illisible ('?cert=SERIAL' sans host), sans qu'aucun log
 * Watchdog ni retour d'erreur ne signale la dégradation.
 *
 * Corrigé le 14/08/2026 (round 167) : $qrEnabled est désormais forcé à
 * false si $qrBaseUrl est vide ou invalide (Validate::isUrl()), avec un
 * log Watchdog dédié.
 *
 * Test structurel (générer un vrai PDF avec un domaine vide nécessiterait
 * de manipuler Tools::getShopDomainSsl() globalement — trop invasif pour
 * l'environnement de test partagé) : vérifie la présence du garde-fou.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CertificateManager.php');
    neria_assert($src !== false, 'Impossible de lire CertificateManager.php');

    $posQr = strpos($src, '$qrEnabled = (bool) \Configuration::get(self::CFG_QR_ENABLED, null, null, (int) $order->id_shop);');
    neria_assert($posQr !== false, 'Résolution de $qrEnabled introuvable — jeu de test invalide');
    $body = substr($src, $posQr, 2600);

    neria_assert(
        strpos($body, "\$qrBaseUrl === '' || !\Validate::isUrl(\$qrBaseUrl)") !== false,
        "Le garde-fou sur \$qrBaseUrl vide/invalide a disparu — régression du bug corrigé le 14/08/2026 (round 167) : un QR pointant vers une adresse cassée pourrait de nouveau être imprimé silencieusement"
    );
    neria_assert(
        strpos($body, '$qrEnabled = false;') !== false,
        "Le garde-fou ne désactive plus \$qrEnabled sur URL de base invalide — régression du bug corrigé le 14/08/2026 (round 167)"
    );
    neria_assert(
        strpos($body, 'watchdog.certificate_qr_base_url_invalid') !== false,
        "Le log Watchdog dédié à la désactivation du QR a disparu — régression du bug corrigé le 14/08/2026 (round 167)"
    );

    return [
        'pass'    => true,
        'message' => "CertificateManager désactive bien le QR code (avec log Watchdog) si l'URL de base est vide ou invalide — bug corrigé le 14/08/2026 (round 167)",
    ];
}
