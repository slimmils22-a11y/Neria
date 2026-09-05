<?php
/**
 * Régression : PropensityScoreManager::calcRecencyScore()/calcFrequencyScore()
 * calculaient le nombre de jours écoulés via `new \DateTime()` (horloge/fuseau
 * PHP) comparée à une date issue de MySQL ($lastOrder/$firstDate) —
 * mélange d'horloges qui fait dériver silencieusement le score si le
 * serveur web et le serveur MySQL n'ont pas le même fuseau horaire, avec
 * un impact direct puisque RECENCY_FULL_DAYS=7 et RECENCY_ZERO_DAYS=90 sont
 * des seuils sensibles à un décalage même de quelques heures autour de
 * minuit.
 *
 * ChurnScoreManager::recomputeAll() (round 237) avait déjà corrigé ce même
 * pattern (TIMESTAMPDIFF(SECOND, ..., NOW()) côté SQL, pas time()/DateTime()
 * PHP), mais PropensityScoreManager ne l'avait jamais reçu.
 *
 * Corrigé le 05/09/2026 (round 303) : les 2 requêtes SQL alimentant
 * scoreRecency()/scoreFrequency()/recalculateAll() calculent désormais le
 * nombre de jours directement via TIMESTAMPDIFF(DAY, ..., NOW()) — les
 * méthodes de calcul pur reçoivent un entier déjà calculé, plus une chaîne
 * de date nécessitant un new \DateTime() PHP.
 *
 * Test comportemental réel : un client de test DÉDIÉ (créé pour ce test,
 * aucune commande préexistante ne peut donc fausser MAX(date_add)/
 * MIN(date_add)) passe une commande valide avec date_add fixé à exactement
 * 10 jours dans le passé (>RECENCY_FULL_DAYS=7, <RECENCY_ZERO_DAYS=90) —
 * scoreRecency()/scoreFrequency() doivent renvoyer exactement le score
 * attendu selon la formule documentée, prouvant que le calcul repose sur
 * une vraie mesure de jours (pas une chaîne de date jamais interprétée).
 * Vérification structurelle complémentaire : plus aucun new \DateTime()
 * dans les 2 méthodes de calcul pur.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PropensityScoreManager.php';

    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $idShop = (int) Context::getContext()->shop->id;
    $idLang = (int) Configuration::get('PS_LANG_DEFAULT');

    $email = 'regtest569_' . uniqid() . '@example.test';
    $db->execute("DELETE FROM {$prefix}customer WHERE email = '{$email}'");
    $db->execute("INSERT INTO {$prefix}customer
        (id_shop, id_shop_group, id_lang, firstname, lastname, email, passwd, active, deleted, date_add, date_upd)
        VALUES ({$idShop}, 1, {$idLang}, 'RegtestPropensity', '569', '{$email}', '" . md5('x') . "', 1, 0, NOW(), NOW())");
    $idCustomer = (int) $db->Insert_ID();
    neria_assert($idCustomer > 0, "jeu de test invalide : client de test non créé");

    try {
        // Une seule commande valide pour ce client dédié, exactement 10
        // jours dans le passé — entre RECENCY_FULL_DAYS (7) et
        // RECENCY_ZERO_DAYS (90).
        $db->execute("INSERT INTO {$prefix}orders
            (id_shop, id_shop_group, id_customer, id_carrier, id_lang, id_currency, id_address_delivery, id_address_invoice, current_state, secure_key, payment, conversion_rate, total_paid, total_paid_tax_incl, total_paid_tax_excl, total_paid_real, total_products, total_products_wt, valid, date_add, date_upd)
            VALUES ({$idShop},1,{$idCustomer},1,1,1,0,0,1,'x','regtest569',1,10,10,10,10,10,10,1, DATE_SUB(NOW(), INTERVAL 10 DAY), NOW())");
        $idOrder = (int) $db->Insert_ID();

        $mgr = new PropensityScoreManager(neria_test_module());

        // 1) Logique de calcul pure, entier de jours connu (reflection —
        // méthodes privées).
        $refRecency = new ReflectionMethod(PropensityScoreManager::class, 'calcRecencyScore');
        $refRecency->setAccessible(true);
        $refFrequency = new ReflectionMethod(PropensityScoreManager::class, 'calcFrequencyScore');
        $refFrequency->setAccessible(true);

        // Formule documentée : ratio linéaire entre J+7 (score plein=40) et
        // J+90 (score nul=0). À J+10 : ratio=(10-7)/(90-7)=3/83, score=40*(1-3/83)≈38.6.
        $expectedRecency = round(40 * (1 - (10 - 7) / (90 - 7)), 1);
        $actualRecency = $refRecency->invoke($mgr, 10);
        neria_assert(
            abs($actualRecency - $expectedRecency) < 0.01,
            "calcRecencyScore(10) renvoie {$actualRecency} au lieu de {$expectedRecency} — la formule ne calcule plus correctement à partir d'un entier de jours (régression round 303)"
        );

        // 1 commande / (10/30.44) mois ≈ 3.044 mois → perMonth ≈ 0.328 →
        // score = min(25, round(0.328*8, 1)) ≈ 2.6
        $expectedFrequency = min(25, round((1 / max(1, 10 / 30.44)) * 8, 1));
        $actualFrequency = $refFrequency->invoke($mgr, 1, 10);
        neria_assert(
            abs($actualFrequency - $expectedFrequency) < 0.01,
            "calcFrequencyScore(1, 10) renvoie {$actualFrequency} au lieu de {$expectedFrequency} — régression round 303"
        );

        // 2) Bout-en-bout réel : scoreRecency()/scoreFrequency() (privées)
        // interrogent la vraie commande insérée pour ce client DÉDIÉ (donc
        // sans ambiguïté possible avec une autre commande) et doivent
        // produire EXACTEMENT les mêmes valeurs que le calcul pur ci-dessus.
        $refScoreRecency = new ReflectionMethod(PropensityScoreManager::class, 'scoreRecency');
        $refScoreRecency->setAccessible(true);
        $refScoreFrequency = new ReflectionMethod(PropensityScoreManager::class, 'scoreFrequency');
        $refScoreFrequency->setAccessible(true);

        $realRecency = $refScoreRecency->invoke($mgr, $idCustomer);
        $realFrequency = $refScoreFrequency->invoke($mgr, $idCustomer);

        neria_assert(
            abs($realRecency - $expectedRecency) < 0.5,
            "scoreRecency() renvoie {$realRecency} au lieu d'environ {$expectedRecency} pour une commande vieille de 10 jours — TIMESTAMPDIFF ne produit plus la bonne mesure de jours (régression round 303)"
        );
        neria_assert(
            abs($realFrequency - $expectedFrequency) < 0.5,
            "scoreFrequency() renvoie {$realFrequency} au lieu d'environ {$expectedFrequency} pour un client inscrit depuis 10 jours avec 1 commande — régression round 303"
        );

        // 3) Vérification structurelle : plus aucun new \DateTime() dans
        // les 2 méthodes de calcul pur (mélange horloge PHP/MySQL éliminé).
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/PropensityScoreManager.php');
        neria_assert($src !== false, 'Impossible de lire PropensityScoreManager.php');
        $posCalcRecency = strpos($src, 'private function calcRecencyScore(');
        $posCalcFrequency = strpos($src, 'private function calcFrequencyScore(');
        neria_assert($posCalcRecency !== false && $posCalcFrequency !== false, 'Méthodes de calcul introuvables — jeu de test invalide');
        $calcBlock = substr($src, $posCalcRecency, $posCalcFrequency - $posCalcRecency + 400);
        neria_assert(
            strpos($calcBlock, '->diff(new \DateTime(') === false,
            "calcRecencyScore()/calcFrequencyScore() utilisent de nouveau new \\DateTime()->diff() — régression du bug corrigé le 05/09/2026 (round 303) : mélange horloge PHP/horloge MySQL réintroduit"
        );
        neria_assert(
            strpos($src, 'TIMESTAMPDIFF(DAY,') !== false,
            "Plus aucune requête n'utilise TIMESTAMPDIFF(DAY, ...) — régression du bug corrigé le 05/09/2026 (round 303)"
        );

        return [
            'pass'    => true,
            'message' => "PropensityScoreManager::calcRecencyScore()/calcFrequencyScore() calculent bien le nombre de jours via TIMESTAMPDIFF SQL (horloge MySQL des deux côtés), plus de mélange avec l'horloge PHP — bug corrigé le 05/09/2026 (round 303)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}orders WHERE id_customer = {$idCustomer} AND payment = 'regtest569'");
        $db->execute("DELETE FROM {$prefix}customer WHERE id_customer = {$idCustomer}");
    }
}
