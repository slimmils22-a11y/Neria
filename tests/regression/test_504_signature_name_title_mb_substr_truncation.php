<?php
/**
 * Régression : neria.php (action BO 'generate_signature') écrivait
 * `$sigName`/`$sigTitle` dans `neria_signature.signer_name`/`signer_title`
 * (VARCHAR(100)) SANS troncature explicite — ces valeurs proviennent des
 * Variables personnalisées (`variable_value` VARCHAR(500), champ BO libre
 * SANS `maxlength` HTML dans configure.tpl), donc un marchand saisissant un
 * titre professionnel réaliste mais un peu long (ex: "Fondatrice &
 * Directrice Artistique, Maison de Haute Maroquinerie depuis 1987...")
 * pouvait légitimement dépasser 100 caractères sans jamais s'approcher de
 * la limite de 500 caractères du champ source.
 *
 * Bug identifié le 01/09/2026 (round 262, audit "troncature silencieuse de
 * colonnes VARCHAR/TEXT courtes"). Sans troncature explicite, MySQL tronque
 * silencieusement en OCTETS en mode non strict (courant sur mutualisé,
 * risque de mojibage sur un caractère UTF-8 multi-octets coupé en plein
 * milieu — cette table est en utf8mb4) ou fait échouer l'INSERT en mode
 * strict, dans les deux cas sans message clair pour le marchand (le code ne
 * vérifie que $path généré par SignatureGenerator, pas le succès de cet
 * INSERT). Même pattern exact que les 2 bugs déjà corrigés au round 254
 * (ABTestManager::createTest()/SeasonalCampaignManager::create()/update()).
 *
 * Corrigé le 01/09/2026 (round 262) : `mb_substr(..., 0, 100)` explicite
 * avant écriture, sur $sigName ET $sigTitle.
 *
 * Test comportemental réel : fixture de 105 caractères dont les 6 derniers
 * sont des caractères arabes multi-octets consécutifs (une troncature en
 * octets tomberait nécessairement en plein milieu de l'un d'eux) — insérée
 * réellement dans neria_signature via le même mécanisme mb_substr() que le
 * correctif, relue depuis la base, vérifiée à exactement 100 caractères ET
 * séquence UTF-8 valide (même méthode que test_489, round 254).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;

    // 99 caractères ASCII + 6 caractères arabes multi-octets consécutifs = 105.
    $longTitle = str_repeat('A', 99) . 'مرحبا؟';
    neria_assert(mb_strlen($longTitle) === 105, "jeu de test invalide : la fixture ne fait pas 105 caractères (" . mb_strlen($longTitle) . ")");

    // Reproduit exactement la logique du correctif (neria.php, action
    // generate_signature) : mb_substr(trim(...), 0, 100).
    $truncated = mb_substr(trim($longTitle), 0, 100);
    neria_assert(mb_strlen($truncated) === 100, "jeu de test invalide : mb_substr(...,0,100) ne produit pas 100 caractères");
    neria_assert(mb_check_encoding($truncated, 'UTF-8'), "jeu de test invalide : la troncature mb_substr() produit une séquence UTF-8 invalide — jeu de test lui-même cassé");

    $db->execute("DELETE FROM {$prefix}neria_signature WHERE signer_name = 'RegtestSignature504'");

    try {
        $db->execute(
            "INSERT INTO {$prefix}neria_signature
                (id_shop, signer_name, signer_title, font_style, color, image_path, is_active, date_add, date_upd)
             VALUES ({$idShop}, 'RegtestSignature504', '" . pSQL($truncated) . "', 'elegant', '#b38b59', '', 0, NOW(), NOW())"
        );

        $saved = $db->getValue(
            "SELECT signer_title FROM {$prefix}neria_signature WHERE signer_name = 'RegtestSignature504'"
        );
        neria_assert($saved !== false, "jeu de test invalide : la ligne de test n'a pas été insérée");
        neria_assert(
            mb_strlen((string) $saved) === 100 && mb_check_encoding((string) $saved, 'UTF-8'),
            "La colonne signer_title ne contient plus exactement 100 caractères UTF-8 valides après troncature explicite — régression du bug corrigé le 01/09/2026 (round 262) : une troncature en octets (comportement MySQL par défaut sans mb_substr()) couperait de nouveau en plein milieu d'un caractère multi-octets, produisant du mojibake dans la signature affichée. Valeur obtenue = " . var_export($saved, true)
        );

        // Vérification structurelle complémentaire : neria.php applique
        // bien mb_substr(..., 0, 100) sur $sigName ET $sigTitle AVANT
        // l'INSERT dans neria_signature.
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
        neria_assert($src !== false, "Impossible de lire neria.php");
        $posAction = strpos($src, "'generate_signature'");
        neria_assert($posAction !== false, "action generate_signature introuvable — jeu de test invalide");
        $posInsert = strpos($src, "\$db->insert('neria_signature',", $posAction);
        neria_assert($posInsert !== false, "INSERT neria_signature introuvable — jeu de test invalide");
        $body = substr($src, $posAction, $posInsert - $posAction);

        neria_assert(
            strpos($body, "\$sigName  = mb_substr(trim((string) (\$customVars['founder_name']  ?? '')), 0, 100);") !== false,
            "neria.php ne tronque plus \$sigName via mb_substr(..., 0, 100) avant l'INSERT — régression du bug corrigé le 01/09/2026 (round 262)"
        );
        neria_assert(
            strpos($body, "\$sigTitle = mb_substr(trim((string) (\$customVars['founder_title'] ?? '')), 0, 100);") !== false,
            "neria.php ne tronque plus \$sigTitle via mb_substr(..., 0, 100) avant l'INSERT — régression du bug corrigé le 01/09/2026 (round 262)"
        );

        return [
            'pass'    => true,
            'message' => "neria.php tronque désormais explicitement \$sigName/\$sigTitle via mb_substr(..., 0, 100) avant l'écriture dans neria_signature (VARCHAR(100)), évitant une troncature en octets par MySQL au milieu d'un caractère multi-octets — bug corrigé le 01/09/2026 (round 262)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_signature WHERE signer_name = 'RegtestSignature504'");
    }
}
