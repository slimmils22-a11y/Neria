<?php
/**
 * Régression : le handler BO `save_pagespeed_key` (`neria.php`) validait
 * l'URL cible saisie via `parse_url($targetUrl)` SANS jamais ajouter de
 * schéma manquant. Une URL saisie sans "http(s)://" (ex. "boutique.com",
 * saisie naturelle pour un marchand non technique) ne fait remonter AUCUNE
 * clé `host` chez `parse_url()` (PHP la place dans `path`) —
 * `$enteredHost` devenait alors `''`, déclenchant systématiquement
 * `msg.url_wrong_domain` même pour un domaine par ailleurs correct,
 * empêchant la sauvegarde tant que le marchand ne devine pas qu'il faut
 * préfixer explicitement l'URL.
 *
 * Bug identifié le 11/09/2026 (round 338, audit PageSpeedManager/
 * NeriaTools).
 *
 * Corrigé le 11/09/2026 (round 338) : un schéma `https://` est ajouté
 * avant `parse_url()` si absent.
 *
 * Test structurel (le handler est une action BO complète — dispatch
 * d'action admin, impraticable à invoquer isolément en CLI, même limite
 * déjà acceptée pour d'autres handlers de ce fichier) + comportemental
 * réel sur la logique exacte de résolution d'hôte reproduite depuis le
 * code corrigé : vérifie qu'une URL sans schéma résout bien le même host
 * qu'avec schéma explicite.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    // ── Vérification structurelle du correctif ────────────────────────
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $posFn = strpos($src, "Tools::getValue('neria_action') === 'save_pagespeed_key'");
    neria_assert($posFn !== false, 'Handler save_pagespeed_key introuvable — jeu de test invalide');
    $body = substr($src, $posFn, 1200);

    neria_assert(
        strpos($body, "\$urlToParse  = preg_match('/^https?:\\/\\//i', \$targetUrl) ? \$targetUrl : 'https://' . \$targetUrl;") !== false,
        "save_pagespeed_key n'ajoute plus de schéma https:// manquant avant parse_url() — régression du bug corrigé le 11/09/2026 (round 338) : une URL saisie sans schéma (ex. 'boutique.com') redeviendrait systématiquement rejetée avec msg.url_wrong_domain, même pour un domaine correct"
    );
    neria_assert(
        strpos($body, 'parse_url($urlToParse)') !== false,
        "save_pagespeed_key ne parse plus \$urlToParse (URL normalisée) — régression du bug corrigé le 11/09/2026 (round 338)"
    );

    // ── Vérification comportementale de la logique exacte ─────────────
    $targetUrlNoScheme = 'example-shop-regtest697.test';
    $targetUrlWithScheme = 'https://example-shop-regtest697.test';

    foreach ([$targetUrlNoScheme, $targetUrlWithScheme] as $targetUrl) {
        $urlToParse  = preg_match('/^https?:\/\//i', $targetUrl) ? $targetUrl : 'https://' . $targetUrl;
        $parsed      = parse_url($urlToParse);
        $enteredHost = strtolower(preg_replace('/^www\./', '', $parsed['host'] ?? ''));
        neria_assert(
            $enteredHost === 'example-shop-regtest697.test',
            "La résolution d'hôte pour '{$targetUrl}' a produit '{$enteredHost}' au lieu de 'example-shop-regtest697.test' — jeu de test invalide ou régression"
        );
    }

    return [
        'pass'    => true,
        'message' => "save_pagespeed_key ajoute désormais un schéma https:// manquant avant parse_url() — une URL cible saisie sans schéma résout bien le même hôte qu'avec schéma explicite — bug corrigé le 11/09/2026 (round 338)",
    ];
}
