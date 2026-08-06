<?php
/**
 * Régression : BlacklistManager::add()/remove() doivent vérifier
 * Affected_Rows() > 0, pas seulement le résultat de execute() — sinon un
 * doublon (add) ou un id déjà supprimé (remove) déclenche un faux message
 * de succès côté BO alors qu'aucune règle n'a réellement été ajoutée ou
 * retirée.
 *
 * Bug réel corrigé le 06/08/2026 (round 66, piste identifiée le 05/08/2026
 * round 54) : execute() retourne true même sur un INSERT IGNORE ignoré (0
 * ligne) ou un DELETE sans correspondance (0 ligne) — aucune perte de
 * données, mais retour trompeur pour le marchand.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/BlacklistManager.php';

    $db       = neria_test_db();
    $prefix   = neria_test_prefix();
    $template = 'regtest_bl_' . uniqid();

    $db->execute("DELETE FROM {$prefix}neria_blacklist WHERE template = '" . pSQL($template) . "'");

    try {
        $mgr = new BlacklistManager();

        $first = $mgr->add($template, 'fr');
        neria_assert($first === true, "add() a échoué pour un ajout réellement nouveau — jeu de test invalide");

        $duplicate = $mgr->add($template, 'fr');
        neria_assert(
            $duplicate === false,
            "add() renvoie encore true pour un doublon (règle déjà existante, 0 ligne insérée) — régression du bug corrigé le 06/08/2026 (round 66) : le BO afficherait de nouveau un faux message de succès"
        );

        $countAfterDuplicate = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_blacklist WHERE template = '" . pSQL($template) . "' AND lang = 'fr'"
        );
        neria_assert($countAfterDuplicate === 1, "le doublon a créé une 2e ligne — la contrainte UNIQUE ne protège plus correctement");

        $idBlacklist = (int) $db->getValue(
            "SELECT id_blacklist FROM {$prefix}neria_blacklist WHERE template = '" . pSQL($template) . "' AND lang = 'fr'"
        );

        $realRemove = $mgr->remove($idBlacklist);
        neria_assert($realRemove === true, "remove() a échoué pour une suppression réellement effective — jeu de test invalide");

        $phantomRemove = $mgr->remove($idBlacklist);
        neria_assert(
            $phantomRemove === false,
            "remove() renvoie encore true pour un id déjà supprimé (0 ligne supprimée) — régression du bug corrigé le 06/08/2026 (round 66) : le BO afficherait de nouveau un faux message de succès"
        );

        return [
            'pass'    => true,
            'message' => "add()/remove() renvoient bien false quand 0 ligne n'est réellement affectée (doublon ignoré, id déjà supprimé)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_blacklist WHERE template = '" . pSQL($template) . "'");
    }
}
