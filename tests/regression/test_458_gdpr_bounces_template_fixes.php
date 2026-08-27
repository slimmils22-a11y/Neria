<?php
/**
 * Régression round 219 (26/08/2026) — 3 correctifs distincts sur les
 * templates admin :
 *
 * 1. gdpr.tpl : aucune des 3 branches {if}/{elseif} du résumé chiffrement
 *    ne couvrait le cas openssl_ok=true ET key_ok=false (crypto.active
 *    devient false dans ce cas précis, voir GdprAuditManager.php:588-590)
 *    — le résumé restait vide, sans expliquer au marchand pourquoi le
 *    chiffrement est inactif. 4e branche ajoutée.
 *
 * 2. bounces.tpl : {$b.reason|escape:'html'|truncate:80:'…'} appliquait
 *    l'échappement AVANT la troncature — une coupure au milieu d'une
 *    entité HTML affichait du texte cassé. Ordre inversé (truncate puis
 *    escape).
 *
 * 3. bounces.tpl : {$bounce_webhook_url} affiché sans |escape:'html' à 2
 *    endroits, incohérent avec le reste du fichier (défense en
 *    profondeur, pas d'exploit connu dans la config standard).
 *
 * Test structurel : vérifie la présence de chaque garde-fou dans le code
 * source des templates.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $base = _PS_MODULE_DIR_ . 'neria/views/templates/admin/';

    $gdpr = file_get_contents($base . 'gdpr.tpl');
    neria_assert($gdpr !== false, 'Impossible de lire views/templates/admin/gdpr.tpl');
    neria_assert(
        strpos($gdpr, "{elseif \$gdpr_audit.crypto.openssl_ok && !\$gdpr_audit.crypto.key_ok}") !== false,
        "gdpr.tpl n'a plus la branche openssl_ok/!key_ok — régression du bug corrigé le 26/08/2026 (round 219) : le résumé chiffrement resterait de nouveau vide quand la clé est invalide"
    );
    neria_assert(
        strpos($gdpr, "gdpr.encrypt_key_invalid") !== false,
        "gdpr.tpl n'utilise plus la clé de traduction gdpr.encrypt_key_invalid — régression du bug corrigé le 26/08/2026 (round 219)"
    );

    $trad = json_decode((string) file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(
        is_array($trad) && isset($trad['gdpr.encrypt_key_invalid']['fr']),
        "La clé de traduction gdpr.encrypt_key_invalid est absente de admin_translations.json — régression du bug corrigé le 26/08/2026 (round 219)"
    );

    $bounces = file_get_contents($base . 'bounces.tpl');
    neria_assert($bounces !== false, 'Impossible de lire views/templates/admin/bounces.tpl');
    neria_assert(
        strpos($bounces, "{\$b.reason|truncate:80:'…'|escape:'html'}") !== false,
        "bounces.tpl n'applique plus truncate AVANT escape sur \$b.reason — régression du bug corrigé le 26/08/2026 (round 219) : une coupure au milieu d'une entité HTML afficherait de nouveau du texte cassé"
    );
    neria_assert(
        substr_count($bounces, "{\$bounce_webhook_url|escape:'html'}") >= 2,
        "bounces.tpl n'échappe plus \$bounce_webhook_url aux 2 emplacements attendus — régression du bug corrigé le 26/08/2026 (round 219)"
    );

    return [
        'pass'    => true,
        'message' => 'Round 219 : branche crypto manquante ajoutée dans gdpr.tpl, ordre truncate/escape corrigé et webhook URL échappée dans bounces.tpl',
    ];
}
