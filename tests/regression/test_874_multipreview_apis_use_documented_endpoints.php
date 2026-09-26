<?php
/**
 * Régression (P8b, 26/09/2026) : les deux intégrations d'aperçu multi-clients utilisaient des points d'entrée qui ne sont
 * pas ceux des API documentées — Email on Acid « api.emailonacid.com/v6/emails » (l'API est en v5, POST /email/tests, résultats
 * indexés par client) et Litmus « api.litmus.com/v1/tests » (API historique ; l'API Instant est instant-api.litmus.com/v1/emails,
 * corps html_text + configurations, identifiant email_guid, captures servies par /emails/{guid}/previews/{client}/full).
 * Avec une vraie clé, l'envoi aurait échoué ou n'aurait rien affiché. Le chemin « avec clé » n'avait jamais été exercé.
 *
 * Corrigé selon les documentations officielles ; URL de base surchargeable (https) pour les tests. Comportement vérifié sur
 * ps-test contre un serveur factice : en-têtes d'authentification (Basic clé:mot_de_passe / clé:), corps, analyse des résultats.
 *
 * Test : contrôle structurel des points d'entrée et de l'analyse des réponses.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    neria_test_module();
    $src = str_replace("\r\n", "\n", (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/src/MultiClientPreviewManager.php'));
    foreach (['emailonacid.com/v6', 'https://api.litmus.com', "'email_source'", "'applications'"] as $old) {
        neria_assert(!str_contains($src, $old), "Ancien point d'entrée / format obsolète de retour : {$old}");
    }
    foreach ([
        "const EOA_API_BASE    = 'https://api.emailonacid.com/v5';",
        "const LITMUS_API_BASE = 'https://instant-api.litmus.com/v1';",
        "'/email/tests'",
        "'html_text'      => \$html",
        "return ['id' => is_array(\$data) ? (\$data['email_guid'] ?? null) : null",
        "'/previews/' . \$litmusClient . '/full'",
        "\$r['screenshots']['default']",
        "strcasecmp((string) (\$r['status'] ?? ''), 'Complete') === 0",
    ] as $needle) {
        neria_assert(str_contains($src, $needle), 'Élément du contrat API absent : ' . $needle);
    }

    return ['pass' => true, 'message' => "Aperçus multi-clients : Email on Acid v5 (/email/tests) et Litmus Instant (/v1/emails) selon les documentations officielles — corrigé le 26/09/2026"];
}
