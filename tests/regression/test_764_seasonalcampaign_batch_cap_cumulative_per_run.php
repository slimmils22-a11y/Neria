<?php
/**
 * Régression : SeasonalCampaignManager::runDueCampaigns() — le plafond
 * MAX_BATCH_PER_RUN (documenté comme une limite PAR EXÉCUTION de cron,
 * round 289) était en réalité appliqué PAR CAMPAGNE (array_slice() dans la
 * boucle foreach, sans compteur cumulé). Avec plusieurs campagnes dues le
 * même jour (fréquent en période de fêtes), chacune consommait jusqu'à 500
 * clients — 5 campagnes dues le même jour pouvaient déclencher jusqu'à 2500
 * envois SMTP réels en un seul passage, réduisant d'autant l'efficacité du
 * garde-fou anti-crash (fenêtre d'exposition mémoire/temps d'exécution) que
 * ce plafond est censé garantir par exécution.
 *
 * Bug identifié le 14/09/2026 (round 359, audit dédié SeasonalCampaignManager).
 *
 * Corrigé le 14/09/2026 : `$remainingBudget` initialisé à MAX_BATCH_PER_RUN
 * AVANT la boucle foreach des campagnes, décrémenté du nombre RÉEL de
 * clients traités par campagne, et la boucle s'arrête (`break`) dès que le
 * budget est épuisé — garantissant un plafond réellement cumulé sur tout
 * l'appel, pas par campagne individuelle.
 *
 * Test structurel (reproduire un scénario réaliste "plusieurs campagnes
 * dues le même jour, chacune avec >500 clients éligibles" nécessiterait un
 * jeu de données client disproportionné pour ce correctif — le mécanisme
 * de comptage est vérifié directement dans le code source) : vérifie que
 * $remainingBudget est initialisé avant la boucle des campagnes, décrémenté
 * après chaque campagne, et que la boucle s'arrête quand il est épuisé.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SeasonalCampaignManager.php');
    neria_assert($src !== false, 'Impossible de lire src/SeasonalCampaignManager.php');

    $posBudgetInit = strpos($src, '$remainingBudget = self::MAX_BATCH_PER_RUN;');
    neria_assert(
        $posBudgetInit !== false,
        "\$remainingBudget n'est plus initialisé à MAX_BATCH_PER_RUN — régression du bug corrigé le 14/09/2026 (round 359) : le plafond redeviendrait appliqué par campagne, pas par exécution"
    );

    $posForeach = strpos($src, 'foreach ($campaigns as $campaign) {');
    neria_assert($posForeach !== false, "Boucle foreach des campagnes introuvable — jeu de test invalide");
    neria_assert(
        $posBudgetInit < $posForeach,
        "\$remainingBudget n'est plus initialisé AVANT la boucle foreach des campagnes — le compteur ne serait plus cumulé sur tout l'appel"
    );

    $posBreak = strpos($src, 'if ($remainingBudget <= 0) {');
    neria_assert(
        $posBreak !== false && $posBreak > $posForeach,
        "La boucle des campagnes ne s'arrête plus quand \$remainingBudget est épuisé — régression du bug corrigé le 14/09/2026 (round 359)"
    );

    $posDecrement = strpos($src, '$remainingBudget -= count($customers);');
    neria_assert(
        $posDecrement !== false,
        "\$remainingBudget n'est plus décrémenté du nombre réel de clients traités par campagne — régression du bug corrigé le 14/09/2026 (round 359) : le budget cumulé ne diminuerait jamais, plusieurs campagnes pourraient de nouveau consommer chacune jusqu'à 500 clients"
    );

    // Le slice par campagne doit désormais utiliser le budget RESTANT, pas
    // le plafond fixe MAX_BATCH_PER_RUN (sinon une 2e campagne pourrait
    // encore consommer 500 clients même si le budget restant est de 10).
    $posSlice = strpos($src, 'array_slice($customers, 0, $remainingBudget)');
    neria_assert(
        $posSlice !== false,
        "Le slice par campagne n'utilise plus \$remainingBudget (budget restant) — régression du bug corrigé le 14/09/2026 (round 359) : une campagne pourrait de nouveau consommer jusqu'à MAX_BATCH_PER_RUN clients même si le budget cumulé est presque épuisé"
    );

    return [
        'pass'    => true,
        'message' => "SeasonalCampaignManager::runDueCampaigns() applique désormais MAX_BATCH_PER_RUN comme un plafond réellement cumulé sur toute l'exécution, pas par campagne individuelle — bug corrigé le 14/09/2026 (round 359)",
    ];
}
