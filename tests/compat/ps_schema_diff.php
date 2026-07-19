<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * Diagnostic de compatibilité — schéma SQL des tables core PrestaShop.
 *
 * Neria interroge directement en SQL brut certaines tables du cœur (pas via
 * ObjectModel), donc invisible au scan `ps_core_diff.php` (qui ne couvre que
 * les appels PHP statiques). Une colonne renommée/supprimée entre deux
 * versions PS casse silencieusement la requête sans passer par aucune
 * méthode PHP. Voir CARTOGRAPHY.md, axe 3.
 *
 * Usage : identique à ps_core_diff.php — copier à la racine de chaque
 * install PS à comparer, appeler en HTTP, sauvegarder la sortie, SUPPRIMER
 * le fichier du serveur, puis diff les deux sorties (en normalisant
 * `int(N)` → `int`, qui est un artefact d'affichage MySQL sans rapport avec
 * le schéma PS : `sed -E 's/int\([0-9]+\)/int/g'`).
 *
 * Régénérer $tables après tout ajout de requête SQL brute sur une nouvelle
 * table core dans le code Neria :
 *   grep -rhoE '\{\$this->prefix\}[a-z_]+' src/ neria.php controllers/ | sed 's/{$this->prefix}//' | sort -u
 *   grep -rhoE "\\\$this->prefix\.'[a-z_]+'" src/ neria.php controllers/ | sed -E "s/.*prefix\.'//;s/'\$//" | sort -u
 * (exclure les tables neria_* propres au module, qui n'ont pas de risque
 * de compatibilité amont).
 *
 * Dernier scan complet : 2026-07-19, PS8 8.1.7 vs PS9 9.0.2 → 12 tables
 * comparées, quelques différences trouvées (category_lang.meta_keywords et
 * product_lang.meta_keywords supprimées sur PS9, product.ean13/orders.reference
 * élargies, accessory PK devenue composite) — AUCUNE n'affecte Neria (colonnes
 * jamais lues/écrites par le code, ou changement purement additif/cosmétique).
 * Voir CARTOGRAPHY.md.
 */
require_once __DIR__ . '/config/config.inc.php';

$tables = [
    'accessory',
    'category',
    'category_lang',
    'category_product',
    'customer',
    'image',
    'order_detail',
    'order_history',
    'orders',
    'product',
    'product_lang',
    'stock_available',
];

echo '=== VERSION: ' . (defined('_PS_VERSION_') ? _PS_VERSION_ : 'unknown') . ' ===' . PHP_EOL;

foreach ($tables as $t) {
    $full = _DB_PREFIX_ . $t;
    try {
        $cols = Db::getInstance()->executeS("SHOW COLUMNS FROM `{$full}`");
        if (!is_array($cols)) {
            echo "TABLE_MISSING\t{$t}" . PHP_EOL;
            continue;
        }
        foreach ($cols as $c) {
            echo "COL\t{$t}\t{$c['Field']}\t{$c['Type']}\t{$c['Null']}\t{$c['Key']}" . PHP_EOL;
        }
    } catch (\Throwable $e) {
        echo "ERROR\t{$t}\t" . $e->getMessage() . PHP_EOL;
    }
}
