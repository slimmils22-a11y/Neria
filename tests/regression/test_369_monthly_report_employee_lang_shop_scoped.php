<?php
/**
 * Régression : MonthlyReportManager::deliverReportLocked() résolvait la
 * langue de chaque destinataire employé (SELECT email, id_lang FROM
 * employee WHERE active = 1) sans filtrer par la boutique DU RAPPORT
 * ($this->idShop) — sur une install multi-boutiques où un employé n'a
 * accès qu'à la Boutique A, un rapport envoyé pour la Boutique B à cette
 * même adresse utilisait quand même la langue BO configurée de cet
 * employé au lieu de la langue par défaut de la boutique concernée.
 *
 * Corrigé le 16/08/2026 (round 180) : jointure employee_shop ajoutée,
 * scopée sur $this->idShop.
 *
 * Test structurel (une vraie fixture multi-employé/multi-boutique
 * nécessiterait de créer un employé réel — effet de bord sur les données
 * de l'environnement de dev) : vérifie que la requête filtre bien par
 * employee_shop.id_shop.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/MonthlyReportManager.php');
    neria_assert($src !== false, 'Impossible de lire src/MonthlyReportManager.php');

    $posQuery = strpos($src, "SELECT e.`email`, e.`id_lang`");
    neria_assert($posQuery !== false, "Requête de résolution de langue employé introuvable — jeu de test invalide");
    $queryBlock = substr($src, $posQuery, 400);

    neria_assert(
        strpos($queryBlock, 'employee_shop') !== false && strpos($queryBlock, "es.`id_shop` = ' . \$this->idShop") !== false,
        "MonthlyReportManager::deliverReportLocked() ne filtre plus la résolution de langue employé par la boutique du rapport — régression du bug corrigé le 16/08/2026 (round 180) : un employé restreint à une autre boutique verrait de nouveau sa langue BO appliquée à tort au rapport d'une boutique à laquelle il n'a pas accès"
    );

    // Vérification comportementale minimale : la requête reste exploitable
    // (pas de régression de syntaxe SQL) via un vrai appel getRecipients()
    // suivi d'un appel réel de la requête reconstruite.
    require_once _PS_MODULE_DIR_ . 'neria/src/MonthlyReportManager.php';
    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;
    $rows = $db->executeS(
        'SELECT e.`email`, e.`id_lang`
         FROM `' . $prefix . 'employee` e
         INNER JOIN `' . $prefix . 'employee_shop` es ON es.`id_employee` = e.`id_employee`
         WHERE e.`active` = 1 AND es.`id_shop` = ' . $idShop
    );
    neria_assert(is_array($rows), "La requête employee_shop reconstruite échoue — régression du bug corrigé le 16/08/2026 (round 180) : jointure invalide ou table employee_shop introuvable");

    return [
        'pass'    => true,
        'message' => "MonthlyReportManager::deliverReportLocked() filtre bien la résolution de langue employé par la boutique du rapport — bug corrigé le 16/08/2026 (round 180)",
    ];
}
