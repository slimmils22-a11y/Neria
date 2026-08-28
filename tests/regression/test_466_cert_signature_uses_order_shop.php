<?php
/**
 * Régression round 226 (28/08/2026) : hookDisplayAdminOrderMainBottomImpl()
 * calculait $hasSig (disponibilité d'une signature pour le bloc
 * "certificat d'authenticité" de la fiche commande BO) via
 * $this->context->shop->id — la boutique SÉLECTIONNÉE dans le contexte BO
 * de l'employé — au lieu de $order->id_shop, la boutique RÉELLE de la
 * commande consultée.
 *
 * Même famille de bug que rounds 107/111 (CertificateManager::getByOrder()/
 * redownload()), sur un point d'entrée différent qui y avait échappé :
 * sur une installation multi-boutiques, un employé dont le sélecteur
 * d'en-tête pointe sur la boutique A, consultant une commande de la
 * boutique B qui a bien une signature active configurée, voyait
 * cert_has_signature = false à tort (aucune signature pour A) — et
 * inversement si c'est A qui a une signature active et pas B.
 *
 * Corrigé le 28/08/2026 (round 226) : $hasSig résout désormais via
 * (int) $order->id_shop.
 *
 * Test structurel (le hook a une signature Smarty complexe, impraticable à
 * invoquer directement hors contexte BO complet) : vérifie que le fragment
 * SQL de $hasSig utilise bien $order->id_shop et plus
 * $this->context->shop->id.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $posFn = strpos($src, 'function hookDisplayAdminOrderMainBottomImpl');
    neria_assert($posFn !== false, 'hookDisplayAdminOrderMainBottomImpl() introuvable — jeu de test invalide');
    $body = substr($src, $posFn, 2600);

    $posSig = strpos($body, 'neria_signature');
    neria_assert($posSig !== false, "Fragment SQL \$hasSig introuvable dans hookDisplayAdminOrderMainBottomImpl() — jeu de test invalide");
    $sigFragment = substr($body, $posSig, 200);

    neria_assert(
        strpos($sigFragment, '(int) $order->id_shop') !== false,
        "hookDisplayAdminOrderMainBottomImpl() ne calcule plus \$hasSig via \$order->id_shop — régression du bug corrigé le 28/08/2026 (round 226) : la disponibilité de signature affichée sur la fiche commande BO pourrait de nouveau refléter la mauvaise boutique sur une install multi-boutiques"
    );
    neria_assert(
        strpos($sigFragment, '$this->context->shop->id') === false,
        "hookDisplayAdminOrderMainBottomImpl() filtre de nouveau \$hasSig via \$this->context->shop->id (contexte BO de l'employé) — régression du bug corrigé le 28/08/2026 (round 226)"
    );

    return [
        'pass'    => true,
        'message' => "hookDisplayAdminOrderMainBottomImpl() calcule bien cert_has_signature via \$order->id_shop (boutique réelle de la commande), pas le contexte BO courant de l'employé",
    ];
}
