<?php
/**
 * Régression : neria.php reprenait le paramètre GET filter_segment tel
 * quel, sans le valider contre la liste blanche réelle des segments
 * (SegmentManager::getAllSegments()), avant de l'injecter dans une clé de
 * traduction dynamique dans segments.tpl
 * ({neria_admin key="seg.label_{$segment_filter}"} /
 * {neria_admin key="seg.desc_{$segment_filter}"}).
 *
 * Bug réel identifié le 24/08/2026 (round 201) : AdminTranslator::t()
 * renvoie la clé BRUTE, non échappée, quand aucune traduction n'existe pour
 * elle (comportement voulu pour repérer visuellement un oubli de
 * traduction) — et smartyHelper() n'échappe la sortie que si le paramètre
 * 'esc=html' est explicitement passé, ce qui n'était pas le cas dans
 * segments.tpl. Un lien
 * ?filter_segment=<img src=x onerror=alert(1)> ouvert par un employé BO
 * exécutait du JavaScript arbitraire dans le contexte admin (XSS réfléchi).
 *
 * Corrigé le 24/08/2026 (round 201) : neria.php valide désormais
 * $seg contre SegmentManager::getAllSegments() avant de l'assigner à la
 * variable Smarty 'segment_filter' et de l'utiliser pour la requête
 * getCustomersBySegment(), retombant sur SegmentManager::AMBASSADOR si la
 * valeur ne fait pas partie de la liste blanche.
 *
 * Test structurel (garde-fou présent dans neria.php) + comportemental
 * (démontre que AdminTranslator::t() renvoie bien la clé brute non
 * échappée pour une clé inconnue — confirmant que le SEUL rempart possible
 * est la validation en amont de $seg, pas l'échappement en aval).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationEngine.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/AdminTranslator.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/SegmentManager.php';

    // 1) Structurel : la validation par liste blanche doit être présente
    // dans neria.php, avant l'assignation à segment_filter/segment_customers.
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');
    neria_assert(
        strpos($src, "if (\$seg !== '' && !in_array(\$seg, SegmentManager::getAllSegments(), true)) {") !== false,
        "neria.php ne valide plus filter_segment contre SegmentManager::getAllSegments() — régression du bug corrigé le 24/08/2026 (round 201) : XSS réfléchi via ?filter_segment=<payload>"
    );

    // 2) Comportemental : confirme le mécanisme exact du bug — une clé
    // composée avec un payload inconnu du dictionnaire est bien renvoyée
    // BRUTE (non échappée) par AdminTranslator::t(), prouvant que seule la
    // validation de $seg en amont (et non l'échappement du libellé) protège
    // segments.tpl contre l'injection.
    $payload = '<img src=x onerror=alert(1)>';
    $maliciousKey = 'seg.label_' . $payload;
    $out = AdminTranslator::t($maliciousKey);
    neria_assert(
        $out === $maliciousKey,
        "AdminTranslator::t() ne renvoie plus la clé brute pour une clé inconnue — comportement attendu documenté, la protection repose sur la validation de \$seg en amont dans neria.php"
    );
    neria_assert(
        strpos($out, '<img') !== false,
        "Le payload de test n'est pas reproduit tel quel par AdminTranslator::t() — jeu de test invalide"
    );

    // 3) La liste blanche réelle doit refuser ce payload comme segment valide.
    neria_assert(
        !in_array($payload, SegmentManager::getAllSegments(), true),
        "SegmentManager::getAllSegments() accepterait à tort un payload XSS comme segment valide — jeu de test invalide"
    );

    return [
        'pass'    => true,
        'message' => "neria.php valide bien filter_segment contre la liste blanche des segments avant injection dans une clé de traduction dynamique — bug corrigé le 24/08/2026 (round 201)",
    ];
}
