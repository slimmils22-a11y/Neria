<?php
/**
 * Régression : le bloc de sauvegarde SEO (save_seo_config, neria.php) doit
 * écraser SANS CONDITION seo_semrush_key et seo_moz_access (pré-remplis
 * avec la vraie valeur dans le formulaire — vide = révocation volontaire),
 * mais ne mettre à jour seo_moz_secret QUE s'il est non vide (champ
 * password JAMAIS pré-rempli par sécurité — toujours vide sauf resaisie).
 *
 * Bug réel corrigé le 05/08/2026 (round 53) : les 3 champs étaient
 * protégés par `if ($x !== '')`, empêchant tout marchand de révoquer une
 * clé Semrush/Moz en vidant le champ (contrairement à PageSpeed, qui
 * écrase toujours) — la clé chiffrée restait silencieusement en base et
 * continuait d'être utilisée. Une correction naïve en "écraser toujours
 * les 3" aurait introduit un NOUVEAU bug : seo_moz_secret étant jamais
 * pré-rempli, il serait effacé à CHAQUE sauvegarde du formulaire, même
 * pour une raison sans rapport.
 *
 * Round 186 : semrush_key/moz_access écrasent toujours SAUF dans un cas
 * précis — la valeur actuellement stockée existe mais ne se déchiffre plus
 * (clé maîtresse de chiffrement changée entre-temps) ET le champ soumis
 * est vide. Ce cas est indiscernable pour le marchand d'une révocation
 * volontaire (le champ pré-rempli avec CryptoManager::decrypt() affiche
 * '' dans les deux cas), donc la révocation "à l'aveugle" effaçait
 * accidentellement des clés toujours configurées. Fenêtre élargie
 * 2500→4200 (le correctif a allongé le bloc).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');

    $block = null;
    if (preg_match('/save_seo_config[\s\S]{0,4200}?\(new SeoApiManager/', $src, $m)) {
        $block = $m[0];
    }
    neria_assert($block !== null, "bloc save_seo_config introuvable dans neria.php — vérifier que l'action n'a pas été renommée");

    // semrush_key et moz_access : écrasés dans le cas normal (round 186 :
    // sauf le cas précis "déchiffrement cassé + champ vide", cf. docblock).
    neria_assert(
        (bool) preg_match('/Configuration::updateValue\(SeoApiManager::CONFIG_SEMRUSH_KEY, CryptoManager::encrypt\(\$semrushKey\)\);/', $block)
        && strpos($block, "if (\$semrushKey !== '' || !\$semrushDecryptBroken) {") !== false,
        "seo_semrush_key ne conditionne plus l'écrasement à \$semrushDecryptBroken — régression du bug corrigé le 19/08/2026 (round 186), ou régression du bug round 53 (révocation volontaire empêchée)"
    );
    neria_assert(
        (bool) preg_match('/Configuration::updateValue\(SeoApiManager::CONFIG_MOZ_ACCESS, CryptoManager::encrypt\(\$mozAccess\)\);/', $block)
        && strpos($block, "if (\$mozAccess !== '' || !\$mozAccessDecryptBroken) {") !== false,
        "seo_moz_access ne conditionne plus l'écrasement à \$mozAccessDecryptBroken — régression du bug corrigé le 19/08/2026 (round 186), ou régression du bug round 53 (révocation volontaire empêchée)"
    );

    // moz_secret : DOIT rester conditionné (champ jamais pré-rempli).
    neria_assert(
        (bool) preg_match('/if \(\$mozSecret !== .{2}\)\s*\{\s*Configuration::updateValue\(SeoApiManager::CONFIG_MOZ_SECRET/', $block),
        "seo_moz_secret n'est plus protégé par un if — un champ password jamais pré-rempli effacerait le secret à CHAQUE sauvegarde du formulaire SEO, même sans rapport"
    );

    return [
        'pass'    => true,
        'message' => 'save_seo_config traite bien différemment les champs pré-remplis (écrasement systématique) et le champ password jamais pré-rempli (conditionnel)',
    ];
}
