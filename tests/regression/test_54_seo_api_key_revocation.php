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
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');

    $block = null;
    if (preg_match('/save_seo_config[\s\S]{0,2500}?\(new SeoApiManager/', $src, $m)) {
        $block = $m[0];
    }
    neria_assert($block !== null, "bloc save_seo_config introuvable dans neria.php — vérifier que l'action n'a pas été renommée");

    // semrush_key et moz_access : écrasés sans condition (pas de if avant
    // leur updateValue).
    neria_assert(
        (bool) preg_match('/Configuration::updateValue\(SeoApiManager::CONFIG_SEMRUSH_KEY, CryptoManager::encrypt\(\$semrushKey\)\);/', $block)
        && !preg_match('/if \(\$semrushKey !== .{2}\)\s*\{\s*Configuration::updateValue\(SeoApiManager::CONFIG_SEMRUSH_KEY/', $block),
        "seo_semrush_key n'est plus écrasé sans condition — régression du bug empêchant la révocation volontaire d'une clé Semrush"
    );
    neria_assert(
        (bool) preg_match('/Configuration::updateValue\(SeoApiManager::CONFIG_MOZ_ACCESS, CryptoManager::encrypt\(\$mozAccess\)\);/', $block)
        && !preg_match('/if \(\$mozAccess !== .{2}\)\s*\{\s*Configuration::updateValue\(SeoApiManager::CONFIG_MOZ_ACCESS/', $block),
        "seo_moz_access n'est plus écrasé sans condition — régression du bug empêchant la révocation volontaire d'un accès Moz"
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
