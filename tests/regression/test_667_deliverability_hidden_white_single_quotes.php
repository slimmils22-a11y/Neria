<?php
/**
 * Régression : DeliverabilityScorer::hasHiddenWhiteText() n'extrayait les
 * attributs `style="..."` que s'ils utilisaient des guillemets DOUBLES —
 * la regex `/style\s*=\s*"([^"]*)"/i` ignorait totalement
 * `style='color:#fff'` (guillemets simples), un usage pourtant courant
 * (éditeurs WYSIWYG, contenu collé depuis Word/Outlook). La technique de
 * masquage réelle (texte blanc caché) écrite avec des guillemets simples
 * passait donc totalement inaperçue par cette fonction, alors même que
 * c'est précisément le cas d'usage qu'elle existe pour couvrir (round 195).
 *
 * Bug identifié le 10/09/2026 (round 331, audit DeliverabilityScorer/
 * DomainReputationManager).
 *
 * Corrigé le 10/09/2026 (round 331) : la regex accepte désormais les deux
 * styles de guillemets (`(["\'])(.*?)\1`), avec rétro-référence pour
 * garantir la cohérence (n'accepte pas un attribut ouvert avec `"` et
 * fermé avec `'`).
 *
 * Test comportemental réel (méthode privée, via Reflection) — même
 * structure que test_415 (round 195), mais avec des guillemets simples.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/DeliverabilityScorer.php';

    $scorer = new DeliverabilityScorer();
    $ref = new ReflectionMethod(DeliverabilityScorer::class, 'hasHiddenWhiteText');
    $ref->setAccessible(true);

    // Cas 1 : masquage classique white-on-white, guillemets SIMPLES.
    $htmlHidden = "<div style='color:#fff;background:#fff;'>contenu caché</div>";
    $resultHidden = $ref->invoke($scorer, $htmlHidden);
    neria_assert(
        $resultHidden === true,
        "hasHiddenWhiteText() ne détecte plus le masquage white-on-white écrit avec des guillemets simples (style='...') — régression du bug corrigé le 10/09/2026 (round 331)"
    );

    // Cas 2 : texte blanc sans fond déclaré, guillemets SIMPLES.
    $htmlNoBg = "<div style='color:#fff;'>contenu caché</div>";
    $resultNoBg = $ref->invoke($scorer, $htmlNoBg);
    neria_assert(
        $resultNoBg === true,
        "hasHiddenWhiteText() ne détecte plus le texte blanc sans fond déclaré avec des guillemets simples — régression du bug corrigé le 10/09/2026 (round 331)"
    );

    // Cas 3 : non-régression — fond réellement coloré, guillemets SIMPLES,
    // doit rester exclu (comme avec des guillemets doubles, test_415).
    $htmlLegit = "<div style='color:#fff;background-color:#0056b3;'>Acheter maintenant</div>";
    $resultLegit = $ref->invoke($scorer, $htmlLegit);
    neria_assert(
        $resultLegit === false,
        "hasHiddenWhiteText() détecte à tort un faux positif sur du texte blanc légitime (fond coloré) écrit avec des guillemets simples"
    );

    // Cas 4 : non-régression — guillemets DOUBLES toujours détectés (test_415
    // déjà couvert, revérifié ici pour confirmer que le changement de regex
    // n'a pas cassé le cas d'origine).
    $htmlDouble = '<div style="color:#fff;background:#fff;">contenu caché</div>';
    $resultDouble = $ref->invoke($scorer, $htmlDouble);
    neria_assert(
        $resultDouble === true,
        "hasHiddenWhiteText() ne détecte plus le masquage avec des guillemets doubles — régression du correctif round 331 sur le cas d'origine (round 195)"
    );

    return [
        'pass'    => true,
        'message' => "DeliverabilityScorer::hasHiddenWhiteText() détecte désormais le masquage white-on-white écrit avec des guillemets simples OU doubles — bug corrigé le 10/09/2026 (round 331)",
    ];
}
