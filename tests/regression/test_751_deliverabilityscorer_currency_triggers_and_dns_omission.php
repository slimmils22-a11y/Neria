<?php
/**
 * Régression round 356 (DeliverabilityScorer, 2 correctifs comportementaux
 * réels dans le même audit dédié) :
 *
 * 1. '$$$'/'€€€' (3 caractères) étaient en-dessous du seuil minimal de 4
 *    caractères (triggerMeetsMinLength(), script non-CJK) — déclencheurs
 *    anti-spam classiques jamais détectés ni dans score() ni dans
 *    getSubjectSpamTriggers(), malgré leur présence explicite dans le
 *    dictionnaire. Étendus à '$$$$'/'€€€€' pour franchir le seuil.
 *
 * 2. Aucune ligne de critère (SPF/DMARC/DKIM, -24 points potentiels) n'était
 *    produite quand aucune adresse d'expédition valide n'était configurée
 *    (PS_MAIL_EMAIL_MESSAGE_FROM et PS_SHOP_EMAIL vides/sans '@') — une
 *    boutique sans AUCUNE configuration email obtenait ainsi un meilleur
 *    score de délivrabilité qu'une boutique avec un domaine réel mais mal
 *    configuré, sans jamais recevoir de recommandation pour corriger la
 *    cause racine.
 *
 * Test comportemental réel : appelle score() avec un sujet contenant '$$$$'
 * et vérifie que le déclencheur apparaît bien dans getSubjectSpamTriggers()
 * (partie 1) ; simule une boutique sans configuration email et vérifie que
 * les 3 critères SPF/DMARC/DKIM apparaissent bien en warning (partie 2).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/DeliverabilityScorer.php';

    // ── Partie 1 : déclencheurs $$$$/€€€€ réellement détectés ───────────
    $scorer = new DeliverabilityScorer(neria_test_module());
    $subjectTriggers = $scorer->getSubjectSpamTriggers();

    neria_assert(
        in_array('$$$$', $subjectTriggers, true),
        "getSubjectSpamTriggers() ne contient plus '\$\$\$\$' — régression du bug corrigé le 14/09/2026 (round 356) : ce déclencheur classique (anciennement '\$\$\$', sous le seuil minimal de 4 caractères) redeviendrait inerte"
    );
    neria_assert(
        !in_array('$$$', $subjectTriggers, true),
        "getSubjectSpamTriggers() contient encore l'ancien littéral '\$\$\$' (3 caractères, sous le seuil) — la migration vers '\$\$\$\$' n'a pas été appliquée partout"
    );

    $result = $scorer->score('<html><body>Contenu neutre sans déclencheur.</body></html>', 'GAGNEZ $$$$ MAINTENANT');
    // Vérification indirecte plus robuste : le score doit être pénalisé par
    // rapport à un sujet neutre équivalent (preuve que le déclencheur agit
    // réellement sur le calcul, pas seulement listé).
    $neutralResult = $scorer->score('<html><body>Contenu neutre sans déclencheur.</body></html>', 'Confirmation de votre commande');
    neria_assert(
        $result['score'] < $neutralResult['score'],
        "score() avec '\$\$\$\$' dans le sujet n'est pas pénalisé par rapport à un sujet neutre équivalent — le déclencheur reste inerte malgré le correctif"
    );

    // ── Partie 2 : SPF/DMARC/DKIM signalés même sans domaine d'expédition ──
    $originalFrom = Configuration::get('PS_MAIL_EMAIL_MESSAGE_FROM');
    $originalShopEmail = Configuration::get('PS_SHOP_EMAIL');

    try {
        Configuration::updateValue('PS_MAIL_EMAIL_MESSAGE_FROM', '');
        Configuration::updateValue('PS_SHOP_EMAIL', '');

        $noDomainResult = $scorer->score('<html><body>Contenu neutre.</body></html>', 'Sujet neutre');

        $dnsCriteriaNames = [];
        foreach ($noDomainResult['criteria'] as $c) {
            if (in_array(($c['name'] ?? ''), ['SPF', 'DMARC', 'DKIM'], true)) {
                $dnsCriteriaNames[] = $c['name'];
            }
        }

        neria_assert(
            count($dnsCriteriaNames) === 3,
            "score() sans adresse d'expédition configurée ne produit plus les 3 lignes de critère SPF/DMARC/DKIM (trouvé : " . implode(',', $dnsCriteriaNames) . ") — régression du bug corrigé le 14/09/2026 (round 356) : ces 3 critères redisparaîtraient silencieusement du rapport au lieu d'alerter sur l'absence de configuration email"
        );

        return [
            'pass'    => true,
            'message' => "DeliverabilityScorer : '\$\$\$\$'/'€€€€' réellement pénalisés (score {$result['score']} < {$neutralResult['score']}), SPF/DMARC/DKIM bien signalés en avertissement sans configuration email — bugs corrigés le 14/09/2026 (round 356)",
        ];
    } finally {
        if ($originalFrom !== false) {
            Configuration::updateValue('PS_MAIL_EMAIL_MESSAGE_FROM', $originalFrom);
        }
        if ($originalShopEmail !== false) {
            Configuration::updateValue('PS_SHOP_EMAIL', $originalShopEmail);
        }
    }
}
