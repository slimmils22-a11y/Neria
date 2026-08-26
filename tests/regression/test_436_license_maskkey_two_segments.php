<?php
/**
 * Régression : LicenseManager::maskKey() ne masquait que le 3e segment
 * (parts[2]) d'une clé de licence au format NERIA-XXXX-YYYY-ZZZZ,
 * laissant 8 des 12 caractères significatifs (segments 1 et 3) en clair
 * dans les logs Watchdog (activateLicense()) et l'affichage BO
 * (getStatusForDisplay()) — contredisant le commentaire de la méthode
 * ("jamais la clé en clair dans un log").
 *
 * Corrigé le 25/08/2026 (round 206) : les segments 1 ET 2 sont désormais
 * masqués, ne laissant visibles que le préfixe fixe (NERIA) et le dernier
 * segment — suffisant pour qu'un marchand reconnaisse sa clé sans exposer
 * une fraction significative de sa valeur réelle.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/LicenseManager.php';

    $module = neria_test_module();
    $mgr = new LicenseManager($module);

    $masked = $mgr->maskKey('NERIA-A3F2-B7K1-2P8Q');

    neria_assert(
        $masked === 'NERIA-••••-••••-2P8Q',
        "LicenseManager::maskKey() renvoie '{$masked}' au lieu de 'NERIA-••••-••••-2P8Q' — régression du bug corrigé le 25/08/2026 (round 206) : un segment significatif de la clé redeviendrait visible en clair"
    );
    neria_assert(
        strpos($masked, 'A3F2') === false,
        "LicenseManager::maskKey() laisse fuiter le segment 'A3F2' en clair — régression du bug corrigé le 25/08/2026 (round 206)"
    );
    neria_assert(
        strpos($masked, 'B7K1') === false,
        "LicenseManager::maskKey() laisse fuiter le segment 'B7K1' en clair"
    );

    // Format invalide (nombre de segments incorrect) : repli sûr, jamais
    // un fragment de la valeur d'origine.
    $invalid = $mgr->maskKey('PAS-UNE-CLE');
    neria_assert(
        $invalid === '••••',
        "LicenseManager::maskKey() sur un format invalide renvoie '{$invalid}' au lieu de '••••' — comportement de repli cassé"
    );

    return [
        'pass'    => true,
        'message' => "LicenseManager::maskKey() masque bien 2 segments sur 4, ne laissant fuiter que le préfixe fixe et le dernier segment — bug corrigé le 25/08/2026 (round 206)",
    ];
}
