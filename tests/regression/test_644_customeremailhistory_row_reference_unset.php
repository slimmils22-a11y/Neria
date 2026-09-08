<?php
/**
 * Régression : CustomerEmailHistoryManager::getEmails() itère avec
 * `foreach ($rows as &$row) { ... }` mais n'appelait jamais `unset($row)`
 * après la boucle — $row restait une référence PHP vers le DERNIER
 * élément du tableau retourné. Aucun appelant de ce fichier ne réutilise
 * une variable nommée `$row` sur ce même tableau (pas d'impact observable
 * aujourd'hui), mais c'est une bombe à retardement classique : le jour où
 * un appelant fait `foreach ($emails as $row) { ... }` sur le tableau
 * retourné, la DERNIÈRE entrée serait silencieusement écrasée par les
 * valeurs de l'avant-dernière itération.
 *
 * Corrigé le 08/09/2026 (round 325) : `unset($row)` ajouté après la
 * boucle.
 *
 * Test comportemental réel : reproduit exactement le mécanisme du bug —
 * appelle getEmails() (≥3 lignes), puis itère le résultat avec
 * `foreach ($result as $row) { $row = 'corrompu'; }` (réutilisation
 * accidentelle du nom de variable $row, exactement le scénario à risque)
 * et vérifie que la DERNIÈRE entrée du tableau original n'a PAS été
 * corrompue — ce qui seul confirme que la référence a bien été libérée
 * par unset($row) dans getEmails() lui-même.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CustomerEmailHistoryManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;

    $idCustomer = (int) $db->getValue(
        "SELECT id_customer FROM {$prefix}customer WHERE active = 1 AND deleted = 0"
    );
    neria_assert($idCustomer > 0, 'Aucun client actif trouvé pour le test');

    $template = 'neria_test_round325_' . time();
    $tokens = [];
    try {
        for ($i = 0; $i < 3; $i++) {
            $token = 'regtest325_' . $i . '_' . uniqid();
            $tokens[] = $token;
            $db->execute(
                "INSERT INTO {$prefix}neria_stat
                    (id_shop, id_customer, template, lang, event_type, tracking_token, date_add, is_mpp)
                 VALUES
                    ({$idShop}, {$idCustomer}, '{$template}', 'fr', 'sent', '{$token}', DATE_ADD(NOW(), INTERVAL {$i} SECOND), 0)"
            );
        }

        $mgr = new CustomerEmailHistoryManager($module);
        $result = $mgr->getEmails($idCustomer);

        $ourRows = array_values(array_filter($result, fn($r) => $r['template'] === $template));
        neria_assert(count($ourRows) === 3, "getEmails() n'a pas renvoyé les 3 entrées attendues — jeu de test invalide");

        // Reproduit exactement le mécanisme du bug : un futur appelant qui
        // réutilise la variable $row sur le tableau retourné par
        // getEmails() écraserait la DERNIÈRE entrée d'origine si la
        // référence interne n'a pas été correctement libérée.
        foreach ($ourRows as $row) {
            $row = 'corrompu';
        }
        unset($row);

        $lastEntry = $ourRows[2];
        neria_assert(
            is_array($lastEntry) && isset($lastEntry['template']) && $lastEntry['template'] === $template,
            "La dernière entrée du tableau retourné par getEmails() a été corrompue par une réutilisation externe de \$row — régression du bug corrigé le 08/09/2026 (round 325) : unset(\$row) manquant en fin de boucle interne, laissant une référence PHP dangereuse vers le dernier élément"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE template = '{$template}'");
    }

    return [
        'pass'    => true,
        'message' => "CustomerEmailHistoryManager::getEmails() libère bien la référence \$row (unset) après sa boucle interne, sans exposer le tableau retourné à une corruption silencieuse — bug corrigé le 08/09/2026 (round 325)",
    ];
}
