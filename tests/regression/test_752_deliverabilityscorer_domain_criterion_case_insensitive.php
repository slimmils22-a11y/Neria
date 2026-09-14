<?php
/**
 * Régression : DeliverabilityScorer::score() — critère "domaine de la
 * boutique présent" comparait $htmlContent brut (non normalisé en casse) à
 * $shopDomain (PS_SHOP_DOMAIN), contrairement aux autres critères du même
 * fichier (sujet, mots spam corps, désabonnement) qui normalisent
 * systématiquement via mb_strtolower() — même piège déjà corrigé pour le
 * critère désabonnement (round 322, commentaire présent dans le fichier).
 *
 * Bug identifié le 14/09/2026 (round 356, audit dédié DeliverabilityScorer) :
 * si PS_SHOP_DOMAIN est stocké dans une casse différente de celle utilisée
 * dans le HTML de l'email (migration, saisie manuelle en BO), le critère
 * signalait à tort "domaine absent" (-3 points + recommandation d'ajouter
 * un domaine déjà présent), alors que le domaine figure bien dans le HTML.
 *
 * Corrigé le 14/09/2026 : comparaison normalisée en casse (mb_strtolower
 * des deux côtés), réutilisant $htmlContentLower déjà calculé plus haut
 * dans la méthode pour le critère désabonnement.
 *
 * Test comportemental réel : configure PS_SHOP_DOMAIN en MAJUSCULES, fournit
 * un HTML contenant le même domaine en minuscules, vérifie que le critère
 * "Domaine boutique" est bien "success" (présent), pas "warning" (absent).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/DeliverabilityScorer.php';

    $scorer = new DeliverabilityScorer(neria_test_module());
    $originalDomain = Configuration::get('PS_SHOP_DOMAIN');

    try {
        // Domaine boutique en MAJUSCULES ; HTML de l'email contient le même
        // domaine en minuscules (cas réel : lien généré en minuscules par
        // le cœur PrestaShop alors que PS_SHOP_DOMAIN a été saisi/migré en
        // casse différente).
        Configuration::updateValue('PS_SHOP_DOMAIN', 'REGTEST752-EXAMPLE.INVALID');

        $html = '<html><body>Visitez <a href="https://regtest752-example.invalid/produit">notre boutique</a>. '
              . str_repeat('Contenu neutre pour la longueur. ', 20) . '</body></html>';

        $result = $scorer->score($html, 'Sujet de test neutre');

        $domainCriterion = null;
        foreach ($result['criteria'] as $c) {
            if (($c['name'] ?? '') === 'Domaine boutique') {
                $domainCriterion = $c;
            }
        }

        neria_assert(
            $domainCriterion !== null,
            "Critère 'Domaine boutique' introuvable dans le résultat de score() — jeu de test invalide"
        );
        neria_assert(
            $domainCriterion['type'] === 'success',
            "Critère 'Domaine boutique' signalé '{$domainCriterion['type']}' (domaine présent en minuscules dans le HTML, configuré en majuscules) au lieu de 'success' — régression du bug corrigé le 14/09/2026 (round 356) : la comparaison de casse n'est plus normalisée"
        );

        return [
            'pass'    => true,
            'message' => "DeliverabilityScorer::score() détecte bien le domaine boutique dans le HTML quel que soit l'écart de casse avec PS_SHOP_DOMAIN — bug corrigé le 14/09/2026 (round 356)",
        ];
    } finally {
        if ($originalDomain !== false) {
            Configuration::updateValue('PS_SHOP_DOMAIN', $originalDomain);
        }
    }
}
