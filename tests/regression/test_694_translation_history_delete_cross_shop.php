<?php
/**
 * Régression : le handler BO `delete_history` de `neria.php`
 * (`neria_action=delete_history`) filtrait son `DELETE` par
 * `AND id_shop = <boutique courante>`, incohérent avec
 * `TranslationHistoryManager::getHistoryForTemplate()`/`getById()` (round
 * 138) qui ne scopent délibérément PLUS par `id_shop` — l'historique de
 * traduction est affiché GLOBALEMENT (la table `neria_translation`
 * elle-même n'a aucune colonne `id_shop`, une édition de traduction est
 * toujours globale à l'installation). Sur une installation multi-boutique,
 * un opérateur consultant l'historique depuis la boutique A pouvait donc
 * voir une entrée créée depuis la boutique B (visible via la liste
 * globale), mais cliquer "Supprimer" n'avait alors AUCUN effet (0 ligne
 * matchée par `id_shop`, aucune vérification/erreur affichée dans ce bloc)
 * — l'entrée réapparaissait silencieusement à chaque rechargement de la
 * page.
 *
 * Bug identifié le 11/09/2026 (round 337, audit FontManager/
 * TranslationHistoryManager).
 *
 * Corrigé le 11/09/2026 (round 337) : filtre `id_shop` retiré du `DELETE`
 * — cohérent avec le scope global de lecture.
 *
 * Test structurel (le handler est une action BO complète — dispatch
 * d'action admin, impraticable à invoquer isolément en CLI, même limite
 * déjà acceptée pour d'autres handlers de ce fichier, cf. test_675) +
 * comportemental réel sur le SQL exact du correctif : insère une vraie
 * entrée d'historique avec un `id_shop` DIFFÉRENT de la boutique de test,
 * exécute le DELETE exactement comme le handler corrigé le ferait, et
 * vérifie que la ligne est bien supprimée malgré la différence d'id_shop.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    // ── Vérification structurelle du correctif ────────────────────────
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $posFn = strpos($src, "if (\$tradAction === 'delete_history' && class_exists('TranslationHistoryManager')) {");
    neria_assert($posFn !== false, "Handler delete_history introuvable — jeu de test invalide");
    $posEnd = strpos($src, '// Charge (ou recharge après save/reset)', $posFn);
    neria_assert($posEnd !== false, 'Marqueur de fin du bloc introuvable — jeu de test invalide');
    $body = substr($src, $posFn, $posEnd - $posFn);

    $posDelete = strpos($body, 'DELETE FROM `" . _DB_PREFIX_ . "neria_translation_history`');
    neria_assert($posDelete !== false, "Requête DELETE introuvable dans le handler delete_history — jeu de test invalide");
    $sqlStatement = substr($body, $posDelete, 200);
    neria_assert(
        strpos($sqlStatement, 'id_shop') === false,
        "Le handler delete_history filtre de nouveau par id_shop dans son DELETE — régression du bug corrigé le 11/09/2026 (round 337) : la suppression redeviendrait silencieusement inopérante pour une entrée créée depuis une autre boutique, sans aucune vérification ni message d'erreur"
    );

    // ── Vérification comportementale du SQL exact du correctif ────────
    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;
    $table  = $prefix . 'neria_translation_history';
    $otherShopId = $idShop + 999; // boutique fictive distincte, jamais réellement utilisée

    $db->execute(
        "INSERT INTO {$table} (id_shop, template_key, lang_code, translation_key, old_value, new_value, date_add)
         VALUES ({$otherShopId}, 'regtest694_template', 'fr', 'regtest694_key', 'old', 'new', NOW())"
    );
    $idHistory = (int) $db->Insert_ID();
    neria_assert($idHistory > 0, "Insert_ID() n'a pas renvoyé d'id valide — jeu de test invalide");

    try {
        // Reproduit exactement le SQL corrigé du handler (sans id_shop).
        $db->execute("DELETE FROM `{$table}` WHERE `id_history` = {$idHistory}");

        $row = $db->getRow("SELECT id_history FROM {$table} WHERE id_history = {$idHistory}");
        neria_assert(
            $row === false || $row === null,
            "L'entrée d'historique créée pour une AUTRE boutique (id_shop={$otherShopId}) n'a pas été supprimée alors que le SQL corrigé ne filtre plus par id_shop — jeu de test invalide ou régression"
        );

        return [
            'pass'    => true,
            'message' => "Le handler delete_history (neria.php) ne filtre plus par id_shop — une entrée d'historique créée depuis une autre boutique est désormais bien supprimable, cohérent avec le scope global de lecture (round 138) — bug corrigé le 11/09/2026 (round 337)",
        ];
    } finally {
        $db->execute("DELETE FROM {$table} WHERE id_history = {$idHistory}");
    }
}
