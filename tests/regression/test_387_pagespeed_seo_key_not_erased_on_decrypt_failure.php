<?php
/**
 * Régression : les handlers save_pagespeed_key/save_seo_config (neria.php)
 * ré-écrivaient sans condition NERIA_PAGESPEED_API_KEY/NERIA_SEMRUSH_API_KEY/
 * NERIA_MOZ_ACCESS_ID à chaque soumission du formulaire, y compris quand le
 * champ arrivait vide — alors que ces champs sont pré-remplis avec la
 * valeur DÉCHIFFRÉE dans stats.tpl. Si la clé maîtresse de chiffrement
 * change (restauration DB partielle, régénération), CryptoManager::decrypt()
 * échoue silencieusement et renvoie '' (round 172) : le champ pré-rempli
 * paraît vide, indiscernable d'un "jamais configuré". La moindre
 * resauvegarde du formulaire effaçait alors définitivement la clé API
 * réellement configurée.
 *
 * Corrigé le 19/08/2026 (round 186) :
 * - PageSpeed : n'écrase plus la clé stockée si le champ soumis est vide
 *   (même garde-fou que webhook_secret/bounce_webhook_secret).
 * - SEO (SEMrush/Moz) : le vidage volontaire reste possible (design
 *   documenté), mais si la valeur ACTUELLEMENT stockée existe et ne se
 *   déchiffre plus (symptôme du bug), un champ vide ne l'efface plus.
 *
 * Test comportemental réel : simule le scénario exact — pose une clé
 * PageSpeed/SEMrush chiffrée avec la VRAIE clé maîtresse courante puis
 * corrompt volontairement le texte chiffré stocké (pour simuler un échec
 * de déchiffrement réaliste sans toucher à la clé maîtresse elle-même,
 * effet de bord trop large pour un test isolé) ; vérifie que
 * CryptoManager::decrypt() échoue bien dessus (lastDecryptFailed() vrai),
 * puis que le code du handler (relu directement, pas exécuté via HTTP)
 * contient bien les gardes attendus.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CryptoManager.php';

    // --- Partie 1 : CryptoManager::lastDecryptFailed() distingue bien
    // "jamais configuré" (chaîne vide) de "configuré mais illisible"
    // (texte chiffré corrompu) — prérequis du correctif.
    CryptoManager::decrypt('');
    neria_assert(
        CryptoManager::lastDecryptFailed() === false,
        "CryptoManager::lastDecryptFailed() est vrai pour une valeur jamais configurée ('') — le correctif round 186 ne pourrait pas distinguer les deux cas"
    );

    $realEncrypted = CryptoManager::encrypt('ma-cle-api-regtest-387');
    neria_assert($realEncrypted !== '', "CryptoManager::encrypt() a échoué — jeu de test invalide (clé maîtresse absente ?)");
    $corrupted = substr($realEncrypted, 0, -5) . 'XXXXX';
    CryptoManager::decrypt($corrupted);
    neria_assert(
        CryptoManager::lastDecryptFailed() === true,
        "CryptoManager::lastDecryptFailed() n'est pas vrai pour un texte chiffré corrompu — jeu de test invalide"
    );

    // --- Partie 2 : vérification structurelle des 3 correctifs dans neria.php ---
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $posPageSpeed = strpos($src, "if (Tools::getValue('neria_action') === 'save_pagespeed_key'");
    neria_assert($posPageSpeed !== false, "Handler save_pagespeed_key introuvable — jeu de test invalide");
    $posPageSpeedEnd = strpos($src, "if (Tools::getValue('neria_action') === 'refresh_pagespeed'", $posPageSpeed);
    $pageSpeedBody = substr($src, $posPageSpeed, $posPageSpeedEnd - $posPageSpeed);
    neria_assert(
        strpos($pageSpeedBody, "if (\$key !== '') {") !== false,
        "save_pagespeed_key n'a plus de garde 'if (\$key !== \"\")' avant l'écrasement de la clé — régression du bug corrigé le 19/08/2026 (round 186) : un champ vide (affiché après un échec de déchiffrement) effacerait de nouveau la clé stockée"
    );
    neria_assert(
        strpos($pageSpeedBody, '$pageSpeedMgr->clearError();') !== false,
        "save_pagespeed_key n'appelle plus clearError() (clé scopée par boutique) — régression du bug corrigé le 19/08/2026 (round 186)"
    );

    $posSeo = strpos($src, "if (Tools::getValue('neria_action') === 'save_seo_config'");
    neria_assert($posSeo !== false, "Handler save_seo_config introuvable — jeu de test invalide");
    $seoBody = substr($src, $posSeo, 3000);
    neria_assert(
        strpos($seoBody, '$semrushDecryptBroken = CryptoManager::lastDecryptFailed();') !== false
            && strpos($seoBody, '$mozAccessDecryptBroken = CryptoManager::lastDecryptFailed();') !== false,
        "save_seo_config ne revérifie plus CryptoManager::lastDecryptFailed() avant d'écraser semrush_key/moz_access — régression du bug corrigé le 19/08/2026 (round 186)"
    );

    // --- Partie 3 : PageSpeedManager::clearError() existe et est scopée ---
    require_once _PS_MODULE_DIR_ . 'neria/src/PageSpeedManager.php';
    neria_assert(
        method_exists('PageSpeedManager', 'clearError'),
        "PageSpeedManager::clearError() a disparu — régression du bug corrigé le 19/08/2026 (round 186)"
    );

    return [
        'pass'    => true,
        'message' => "Les clés API PageSpeed/SEO ne sont plus effacées silencieusement en cas d'échec de déchiffrement — bug corrigé le 19/08/2026 (round 186)",
    ];
}
