<?php
/**
 * Régression : CertificateManager::sendCertificateEmail() doit utiliser
 * $order->id_shop (la vraie boutique de la commande) pour Mail::Send() et
 * {shop_url}, pas Context::getContext()->shop (contexte BO de l'employé).
 *
 * Bug réel corrigé le 06/08/2026 (round 74) : contrairement à l'INSERT en
 * base dans issue() (déjà corrigé), l'email de certificat calculait
 * $idShop depuis le contexte BO courant. Un employé en contexte différent
 * de la boutique de la commande, émettant un certificat pour une commande
 * d'une AUTRE boutique, envoyait l'email avec la config SMTP/expéditeur de
 * la MAUVAISE boutique — incohérent avec l'enregistrement en base.
 *
 * Non vérifiable en conditions multi-boutiques réelles sur cet
 * environnement de dev (une seule boutique configurée) — même limite que
 * test_37/test_40/test_58. Vérifie donc au niveau du code source que
 * $order->id_shop est bien utilisé, garde-fou structurel.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CertificateManager.php');
    neria_assert($src !== false, 'Impossible de lire src/CertificateManager.php');

    neria_assert(
        strpos($src, '$idShop   = (int) $order->id_shop;') !== false,
        "sendCertificateEmail() n'utilise plus \$order->id_shop pour \$idShop — régression du bug corrigé le 06/08/2026 (round 74) : Mail::Send() résoudrait de nouveau la config SMTP/expéditeur du contexte BO de l'employé au lieu de la vraie boutique de la commande"
    );

    neria_assert(
        strpos($src, "if ((int) \$originalShop->id !== \$idShop) {\n            \\Context::getContext()->shop = new \\Shop(\$idShop);") !== false,
        "sendCertificateEmail() ne bascule plus temporairement le contexte vers la boutique de la commande avant de résoudre {shop_url} — régression du bug corrigé le 06/08/2026 (round 74)"
    );

    return ['pass' => true, 'message' => "sendCertificateEmail() reste bien scopé par \$order->id_shop (Mail::Send() et {shop_url})"];
}
