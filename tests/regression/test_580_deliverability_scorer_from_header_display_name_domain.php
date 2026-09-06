<?php
/**
 * Régression : DeliverabilityScorer::score() extrayait le domaine
 * d'envoi via `explode('@', $fromEmail)[1]` sur PS_MAIL_EMAIL_MESSAGE_FROM
 * sans jamais tenir compte d'un format "Nom <adresse@domaine.tld>" — si ce
 * réglage contient un nom affiché (valeur non standard chez PrestaShop,
 * mais rien ne l'empêchait d'être écrite ainsi directement en base/API/
 * import), le domaine extrait conservait le chevron fermant '>' collé
 * (ex. "domaine.tld>"), invalidant toute recherche DNS SPF/DMARC/DKIM et
 * pénalisant à tort le score de délivrabilité de -24 points pour un
 * domaine pourtant parfaitement configuré.
 *
 * Corrigé le 06/09/2026 (round 307) : extraction via une regex qui isole
 * l'adresse entre chevrons si présents, puis nettoyage défensif des
 * caractères parasites résiduels sur le domaine extrait.
 *
 * Test comportemental réel : préremplit le cache DNS statique (protégé,
 * accédé par Reflection) avec un résultat "tout au vert" pour un domaine
 * de test connu, configure PS_MAIL_EMAIL_MESSAGE_FROM au format
 * "Nom <adresse@ce-domaine>", puis vérifie que score() rapporte bien
 * SPF/DMARC/DKIM configurés (donc qu'il a interrogé EXACTEMENT ce domaine,
 * pas une variante polluée par le chevron) — sans dépendre d'une vraie
 * résolution DNS réseau.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/DeliverabilityScorer.php';

    $testDomain = 'neria-test-domain-307.example';
    $fakeDns    = ['spf' => true, 'dmarc' => true, 'dkim' => true, 'timed_out' => false];

    $ref = new ReflectionProperty('DeliverabilityScorer', 'dnsCache');
    $ref->setAccessible(true);
    $originalCache = $ref->getValue();
    $ref->setValue(null, [$testDomain => $fakeDns]);

    $originalFrom = Configuration::get('PS_MAIL_EMAIL_MESSAGE_FROM');

    try {
        Configuration::updateValue('PS_MAIL_EMAIL_MESSAGE_FROM', 'Boutique Test <contact@' . $testDomain . '>');

        // HTML neutre volontairement construit pour ne déclencher AUCUNE
        // autre pénalité (lien de désabonnement présent, domaine boutique
        // présent, ratio texte/HTML confortable, aucun mot spam, sujet de
        // longueur optimale) : le score doit être exactement 100 si (et
        // seulement si) le domaine SPF/DMARC/DKIM interrogé correspond
        // exactement à '{$testDomain}' préchargé dans le cache.
        $shopDomain = (string) Configuration::get('PS_SHOP_DOMAIN');
        $html = '<html><body><p>Bonjour, ceci est un message de test parfaitement neutre et suffisamment long pour obtenir un bon ratio de texte visible par rapport au balisage HTML utilise. Retrouvez notre boutique sur ' . $shopDomain . '. Vous pouvez vous désabonner à tout moment en cliquant sur le lien de désabonnement présent en bas de cet email.</p></body></html>';
        $subject = 'Une actualite de notre boutique ce mois';

        $scorer = new DeliverabilityScorer();
        $result = $scorer->score($html, $subject);

        neria_assert(
            $result['score'] === 100,
            "Score de délivrabilité = " . $result['score'] . " au lieu de 100 attendu — le domaine interrogé pour SPF/DMARC/DKIM n'est probablement pas '{$testDomain}' mais une variante polluée par le format d'affichage 'Nom <email>' de PS_MAIL_EMAIL_MESSAGE_FROM — régression du bug corrigé le 06/09/2026 (round 307). Critères : " . json_encode($result['criteria'])
        );
    } finally {
        Configuration::updateValue('PS_MAIL_EMAIL_MESSAGE_FROM', (string) $originalFrom);
        $ref->setValue(null, $originalCache);
    }

    // Vérification structurelle complémentaire : la regex d'extraction du
    // format "Nom <email>" est bien présente dans le code source.
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/DeliverabilityScorer.php');
    neria_assert($src !== false, 'Impossible de lire src/DeliverabilityScorer.php');
    neria_assert(
        strpos($src, "preg_match('/<\\s*([^<>\\s]+@[^<>\\s]+)\\s*>/', \$fromEmail, \$mFrom307)") !== false,
        "L'extraction regex du format 'Nom <email>' a disparu du code source — régression du bug corrigé le 06/09/2026 (round 307)"
    );

    return [
        'pass'    => true,
        'message' => "DeliverabilityScorer::score() extrait bien le domaine réel même quand PS_MAIL_EMAIL_MESSAGE_FROM contient un nom affiché au format 'Nom <email>' — bug corrigé le 06/09/2026 (round 307)",
    ];
}
