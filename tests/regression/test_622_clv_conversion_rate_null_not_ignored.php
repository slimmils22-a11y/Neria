<?php
/**
 * Régression : les requêtes batch de ClvManager (getTopCustomers()) et le
 * calcul des remboursements (computeClv()) utilisaient
 * `IF(conversion_rate = 0, 1, conversion_rate)` pour se prémunir d'une
 * division par zéro sur une donnée legacy/import — mais `conversion_rate`
 * NULL (tout aussi plausible dans ce même contexte legacy/import que le
 * code documente déjà lui-même pour la valeur 0) fait évaluer
 * `IF(NULL = 0, 1, NULL)` à NULL (pas à la branche 1, car NULL = 0 est
 * NULL, traité comme faux par IF()), donc SUM() ignorait silencieusement
 * toute commande à conversion_rate NULL. Le calcul PHP de computeClv()
 * (`$o['conversion_rate'] ?: 1.0`) traite pourtant déjà NULL et 0 de façon
 * identique — écart entre le CA d'un client sur sa fiche individuelle
 * (l'inclut à taux 1.0) et dans le classement Top clients (l'exclut
 * totalement), pour la même commande.
 *
 * Corrigé le 07/09/2026 (round 316) : `IF(conversion_rate IS NULL OR
 * conversion_rate = 0, 1, conversion_rate)` sur les 4 requêtes concernées.
 *
 * Test comportemental réel du moteur SQL (pas de dépendance à une vraie
 * commande en base — construit la valeur NULL via une sous-requête
 * littérale) : vérifie que SUM() inclut bien la ligne à conversion_rate
 * NULL au lieu de l'ignorer.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();

    // Simule 2 lignes : total_paid=100 à taux NULL, total_paid=50 à taux 2.
    // Sans le correctif (IF(x = 0, 1, x) seul) : la ligne NULL est ignorée
    // par SUM(), résultat = 25 (50/2). Avec le correctif : résultat =
    // 100/1 + 50/2 = 125.
    $sqlOld = "SELECT SUM(total_paid / IF(rate = 0, 1, rate)) FROM (
        SELECT 100 AS total_paid, NULL AS rate
        UNION ALL
        SELECT 50 AS total_paid, 2 AS rate
    ) t";
    $sqlNew = "SELECT SUM(total_paid / IF(rate IS NULL OR rate = 0, 1, rate)) FROM (
        SELECT 100 AS total_paid, NULL AS rate
        UNION ALL
        SELECT 50 AS total_paid, 2 AS rate
    ) t";

    $resultOld = (float) $db->getValue($sqlOld, false);
    $resultNew = (float) $db->getValue($sqlNew, false);

    neria_assert(
        abs($resultOld - 25.0) < 0.01,
        "Jeu de test invalide : l'ancien pattern IF(rate = 0, 1, rate) ne produit plus 25 pour une ligne à rate NULL ignorée (obtenu {$resultOld}) — le comportement du moteur MySQL a peut-être changé"
    );

    neria_assert(
        abs($resultNew - 125.0) < 0.01,
        "IF(rate IS NULL OR rate = 0, 1, rate) ne produit plus 125 pour ces mêmes lignes (obtenu {$resultNew}) — jeu de test invalide ou comportement moteur MySQL changé"
    );

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ClvManager.php');
    neria_assert($src !== false, 'Impossible de lire src/ClvManager.php');
    neria_assert(
        substr_count($src, 'IS NULL OR o.`conversion_rate` = 0') + substr_count($src, 'IS NULL OR os.`conversion_rate` = 0') === 4,
        "ClvManager n'applique plus le correctif IS NULL sur les 4 requêtes attendues — régression du bug corrigé le 07/09/2026 (round 316)"
    );

    return [
        'pass'    => true,
        'message' => "ClvManager traite bien conversion_rate NULL comme conversion_rate=0 (taux 1.0) dans ses requêtes batch, cohérent avec computeClv() en PHP — bug corrigé le 07/09/2026 (round 316)",
    ];
}
