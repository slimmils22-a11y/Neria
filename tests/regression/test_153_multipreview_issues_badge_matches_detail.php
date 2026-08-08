<?php
/**
 * Régression : le badge "issues" du multi-preview (neria.php, action
 * multipreview_render) doit refléter count($detail) — les vraies anomalies
 * détectées via $diffChecks — pas un simple comptage de blocs <style>
 * supprimés.
 *
 * Bug réel corrigé le 08/08/2026 (round 134) : l'ancien calcul
 * (`max(0, $styleCountRaw - preg_match_all('/<style\b/i', $transformed))`)
 * ne mesurait que la suppression de blocs <style>. Pour Outlook et
 * ProtonMail, la quasi-totalité des neutralisations se fait dans les
 * attributs style="" inline (transformOutlook()/transformProtonMail()),
 * pas dans des blocs <style> : un email massivement modifié en inline
 * affichait "0 issue" alors que $detail listait déjà plusieurs anomalies
 * réelles — le marchand se fiait au badge chiffré et passait à côté du
 * problème sans ouvrir le détail.
 *
 * Test structurel : vérifie que le badge est bien calculé via
 * count($detail), pas via l'ancien calcul basé sur $styleCountRaw.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $posLoop = strpos($src, "foreach (array_keys(MultiClientPreviewManager::CLIENTS) as \$clientId) {");
    neria_assert($posLoop !== false, "Boucle de génération des aperçus multi-client introuvable — régression du bug corrigé le 08/08/2026");

    $body = substr($src, $posLoop, 1500);

    neria_assert(
        strpos($body, "'issues' => count(\$detail)") !== false,
        "Le badge 'issues' du multi-preview ne se base plus sur count(\$detail) — régression du bug corrigé le 08/08/2026 (round 134) : les anomalies inline (Outlook/ProtonMail) redeviendraient sous-comptées, affichant '0 issue' à tort"
    );
    neria_assert(
        strpos($body, 'styleCountRaw') === false,
        "L'ancien calcul basé sur \$styleCountRaw est de nouveau présent — régression du bug corrigé le 08/08/2026 (round 134)"
    );

    return [
        'pass'    => true,
        'message' => "Le badge 'issues' du multi-preview reflète bien le nombre réel d'anomalies détectées (count(\$detail)), y compris les neutralisations de styles inline pour Outlook/ProtonMail",
    ];
}
