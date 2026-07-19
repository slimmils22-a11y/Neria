<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * Diagnostic de compatibilité cœur PrestaShop.
 *
 * Liste exhaustive des appels statiques de Neria vers le cœur PrestaShop
 * (extraite via grep sur src/, neria.php, controllers/, upgrade/). Vérifie
 * pour chacun : existence de la classe, existence de la méthode, nombre et
 * type des paramètres, type de retour.
 *
 * Usage : copier ce fichier à la racine d'une installation PrestaShop
 * (à côté de config/), l'appeler via HTTP (ex. https://boutique/ps_core_diff.php),
 * puis SUPPRIMER le fichier immédiatement après lecture — ne jamais le
 * laisser accessible publiquement.
 *
 * Exécuter une fois par version PS à comparer (ex. PS8 sur Laragon, PS9 en
 * réel), puis `diff` les deux sorties. Toute ligne différente entre les deux
 * exécutions est un point de compatibilité à vérifier avant de considérer
 * Neria compatible avec la nouvelle version.
 *
 * Régénérer la liste $pairs après tout ajout d'appel core dans le code Neria :
 *   grep -rhoE '\b(Tools|Db|Mail|Order|Customer|Context|Validate|Configuration|Hook|Product|Currency|Cart|CartRule|Language|Shop|StockAvailable|ImageType|Address|Country|State|Employee|ObjectModel|Translate|Tag|Category|Manufacturer|Combination|Attribute|Feature|Carrier|Module|Cookie|Link|Media|Warehouse|Supplier|SpecificPrice|TaxRulesGroup|FileLogger|PrestaShopLogger)::[A-Za-z_]+' src/ neria.php controllers/ upgrade/ | sort -u
 *
 * ATTENTION : ce scan couvre uniquement les appels STATIQUES directs. Il ne
 * détecte PAS les incompatibilités liées aux objets reçus en paramètre de
 * hook (ex. bug List-Unsubscribe 2026-07-18 : Symfony\Component\Mime\Email
 * remplaçant Swift_Message sur PS9, même noms de méthode mais forme de
 * retour différente sur getTo()). Pour cette famille de bugs, vérifier
 * manuellement les hooks qui reçoivent des objets du cœur PS
 * (actionMailAlterMessageBeforeSend, actionObjectXxxAfterUpdate, etc.) en
 * inspectant le vrai type d'objet reçu sur la nouvelle version.
 *
 * Dernier scan complet : 2026-07-19, PS8 8.1.7 vs PS9 9.0.2 → 1 seule
 * différence trouvée (Tools::displayPrice supprimée sur PS9), déjà corrigée
 * via NeriaTools::displayPrice() (commit 045a438). Voir README.md.
 */

require_once __DIR__ . '/config/config.inc.php';

$pairs = [
    ['Address', 'getCountryAndState'],
    ['CartRule', 'add'],
    ['Configuration', 'deleteByName'],
    ['Configuration', 'get'],
    ['Configuration', 'getGlobalValue'],
    ['Configuration', 'updateGlobalValue'],
    ['Configuration', 'updateValue'],
    ['Context', 'getContext'],
    ['Context', 'getCurrentLocale'],
    ['Country', 'getByIso'],
    ['Country', 'getCountries'],
    ['Country', 'getIsoById'],
    ['Currency', 'getDefaultCurrency'],
    ['Customer', 'customerExists'],
    ['Db', 'delete'],
    ['Db', 'getInstance'],
    ['Hook', 'getIdByName'],
    ['ImageType', 'getFormattedName'],
    ['Language', 'getIdByIso'],
    ['Language', 'getIsoById'],
    ['Language', 'getLanguage'],
    ['Language', 'getLanguages'],
    ['Link', 'getPageLink'],
    ['Mail', 'Send'],
    ['Mail', 'getTemplateBasePath'],
    ['Mail', 'l'],
    ['Module', 'isEnabled'],
    ['Module', 'isInstalled'],
    ['Module', 'needUpgrade'],
    ['Module', 'runUpgradeModule'],
    ['Order', 'getCustomerNbOrders'],
    ['PrestaShopLogger', 'addLog'],
    ['Product', 'getCover'],
    ['Product', 'getPriceStatic'],
    ['Shop', 'addSqlRestriction'],
    ['StockAvailable', 'getQuantityAvailableByProduct'],
    ['Tools', 'displayPrice'],
    ['Tools', 'getContextLocale'],
    ['Tools', 'getShopDomain'],
    ['Tools', 'getShopDomainSsl'],
    ['Tools', 'getValue'],
    ['Tools', 'isSubmit'],
    ['Tools', 'passwdGen'],
    ['Tools', 'redirect'],
    ['Tools', 'redirectAdmin'],
    ['Tools', 'safeOutput'],
    ['Tools', 'strtolower'],
    ['Tools', 'strtoupper'],
    ['Tools', 'substr'],
    ['Validate', 'isAbsoluteUrl'],
    ['Validate', 'isEmail'],
    ['Validate', 'isLoadedObject'],
];

$props = [
    ['Context', 'language'],
    ['Order', 'valid'],
];

$consts = [
    ['Shop', 'SHARE_CUSTOMER'],
];

echo '=== VERSION: ' . (defined('_PS_VERSION_') ? _PS_VERSION_ : 'unknown') . ' (PHP ' . PHP_VERSION . ') ===' . PHP_EOL;

foreach ($pairs as [$class, $method]) {
    if (!class_exists($class)) {
        echo "MISSING_CLASS\t{$class}::{$method}" . PHP_EOL;
        continue;
    }
    if (!method_exists($class, $method)) {
        echo "MISSING_METHOD\t{$class}::{$method}" . PHP_EOL;
        continue;
    }
    try {
        $ref = new ReflectionMethod($class, $method);
        $params = [];
        foreach ($ref->getParameters() as $p) {
            $type = $p->getType() ? (string) $p->getType() : '?';
            $params[] = $type . ' $' . $p->getName() . ($p->isOptional() ? '=default' : '');
        }
        $returnType = $ref->getReturnType() ? (string) $ref->getReturnType() : '?';
        $static = $ref->isStatic() ? 'static' : 'instance';
        echo "OK\t{$class}::{$method}\t{$static}\t(" . implode(', ', $params) . ")\t: {$returnType}" . PHP_EOL;
    } catch (\Throwable $e) {
        echo "REFLECT_ERROR\t{$class}::{$method}\t" . $e->getMessage() . PHP_EOL;
    }
}

foreach ($props as [$class, $prop]) {
    if (!class_exists($class)) {
        echo "MISSING_CLASS\t{$class}::\${$prop}" . PHP_EOL;
        continue;
    }
    echo (property_exists($class, $prop) ? "OK_PROP\t" : "MISSING_PROP\t") . "{$class}::\${$prop}" . PHP_EOL;
}

foreach ($consts as [$class, $const]) {
    if (!class_exists($class)) {
        echo "MISSING_CLASS\t{$class}::{$const}" . PHP_EOL;
        continue;
    }
    echo (defined("{$class}::{$const}") ? "OK_CONST\t" : "MISSING_CONST\t") . "{$class}::{$const}" . PHP_EOL;
}
