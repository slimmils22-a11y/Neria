<?php
/**
 * Régression : `upgrade_module_1_0_17()` (chiffrement rétroactif des
 * secrets sensibles) appelait `Configuration::get($key)`/`updateValue($key,
 * ...)` SANS `$idShop` explicite — sur une installation multi-boutiques
 * (`Shop::isFeatureActive()`), ces deux méthodes du cœur PrestaShop
 * retombent alors sur `Shop::getContextShopID(true)`, la boutique du
 * CONTEXTE D'EXÉCUTION de cet upgrade (CLI, cron de gestionnaire de
 * modules, ou BO avec une autre boutique active), pas forcément celle
 * sous laquelle le marchand a enregistré le secret.
 *
 * `Configuration::updateValue()` sans `$idShop` enregistre une valeur
 * SCOPÉE À LA BOUTIQUE ACTIVE (pas globale) dès que le multi-boutique est
 * actif — un secret saisi depuis la Boutique B restait donc invisible à
 * ce script s'il tournait dans le contexte de la Boutique A, laissant ce
 * secret (mot de passe IMAP, tokens OAuth, clés API tierces) EN CLAIR en
 * base indéfiniment pour cette boutique, sans aucune alerte (la sonde de
 * clé ne couvre que le cas "clé illisible", pas celui-ci).
 *
 * Bug identifié le 04/09/2026 (round 297, audit "chiffrement et gestion
 * des secrets"). Même classe de bug déjà corrigée aux rounds 132/133/144
 * (`ConfigManager::set()`, `GdprAuditManager::encryptExistingRecords()`),
 * jamais répliquée ici.
 *
 * Corrigé le 04/09/2026 (round 297) : boucle explicite sur `Shop::
 * getShops(true, null, true)` (+ le scope global 0) — chaque boutique
 * active revalidée individuellement avec son `$idShop` explicite.
 *
 * Test structurel (invoquer réellement la fonction touche la config
 * globale réelle du serveur de test, trop invasif — même contrainte que
 * test_138) + comportemental sûr (exécution réelle sans erreur, les clés
 * SENSITIVE_CONFIG_KEYS de cet environnement de test sont vides donc rien
 * n'est modifié).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/upgrade/upgrade-1.0.17.php');
    neria_assert($src !== false, 'Impossible de lire upgrade/upgrade-1.0.17.php');

    neria_assert(
        strpos($src, 'Shop::getShops(true, null, true)') !== false,
        "upgrade_module_1_0_17() ne boucle plus explicitement sur les boutiques actives — régression du bug corrigé le 04/09/2026 (round 297) : un secret saisi sous une autre boutique que le contexte d'exécution de l'upgrade resterait de nouveau en clair en base"
    );
    neria_assert(
        strpos($src, 'Configuration::get($key, null, null, $idShopUpgrade)') !== false,
        "upgrade_module_1_0_17() ne lit plus la config avec un \$idShop explicite par boutique — régression du bug corrigé le 04/09/2026 (round 297)"
    );
    neria_assert(
        strpos($src, 'Configuration::updateValue($key, CryptoManager::encrypt($value), false, null, $idShopUpgrade)') !== false,
        "upgrade_module_1_0_17() n'écrit plus la config chiffrée avec un \$idShop explicite par boutique — régression du bug corrigé le 04/09/2026 (round 297)"
    );

    // Vérification comportementale réelle : la fonction s'exécute
    // toujours sans erreur (chemin nominal — clé de chiffrement valide
    // dans cet environnement de test, boucle multi-boutique parcourue).
    require_once _PS_MODULE_DIR_ . 'neria/upgrade/upgrade-1.0.17.php';
    $result = upgrade_module_1_0_17(neria_test_module());
    neria_assert(
        $result === true,
        "upgrade_module_1_0_17() ne retourne plus true après l'ajout de la boucle multi-boutique — le mécanisme d'upgrade PrestaShop ne considérerait plus ce script comme réussi"
    );

    return [
        'pass'    => true,
        'message' => "upgrade_module_1_0_17() parcourt désormais explicitement chaque boutique active avec son \$idShop propre pour le chiffrement rétroactif des secrets — bug corrigé le 04/09/2026 (round 297)",
    ];
}
