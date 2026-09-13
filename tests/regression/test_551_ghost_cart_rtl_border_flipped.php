<?php
/**
 * Régression : `mails/themes/neria_global/core/ghost_cart.html` (email
 * "panier fantôme") est le SEUL template du thème (sur 236) à utiliser
 * un style physique `border-left`/`padding-left` sur sa note de clôture
 * (`.neria-text-note`) — tous les autres blocs de cette classe n'ont que
 * des marges verticales. Le mécanisme RTL du module (`{$neria_dir}` sur
 * `<html>`, `{$neria_text_align}` pour l'alignement du texte) ne
 * comporte AUCUNE règle qui retourne les styles physiques left/right
 * pour la langue arabe ('ar') — ni sélecteur CSS `[dir="rtl"]`, ni
 * propriétés logiques (`border-inline-start`).
 *
 * Bug identifié le 04/09/2026 (round 295, audit "rendu des templates
 * email — support RTL"). Conséquence concrète avant correctif : un
 * client arabophone recevant l'email ghost_cart voit son corps de texte
 * correctement aligné à droite (RTL), mais la note finale garde sa barre
 * d'accent décorative et son padding sur le côté GAUCHE — à l'opposé du
 * sens de lecture RTL, rupture visuelle typique d'un habillage LTR non
 * adapté sur un template qui par ailleurs prétend supporter le RTL
 * complet.
 *
 * Corrigé le 04/09/2026 (round 295) : bloc conditionnel `{if $neria_dir ==
 * 'rtl'}...{else}...{/if}` ajouté, inversant `border-left`/`padding-left`
 * en `border-right`/`padding-right` pour la langue arabe.
 *
 * Round 350 : cette syntaxe (comparaison d'égalité) s'est révélée n'avoir
 * JAMAIS fonctionné dans le moteur de rendu maison réel — vérifié par un
 * test comportemental réel (test_731), impossible à ce moment-là avec le
 * bootstrap CLI minimal disponible au round 295. Le handler de blocs
 * conditionnels du compilateur maison (EmailRenderer) n'accepte qu'un nom
 * de variable seul, jamais une comparaison — l'ancienne syntaxe faisait
 * tomber tout le bloc (les deux branches) dans le nettoyage générique des
 * résidus Smarty, supprimant AUSSI la note de clôture elle-même, dans
 * TOUS les emails ghost_cart, LTR comme RTL, pas seulement en arabe.
 * Remplacée par une variable booléenne dédiée reconnue par ce moteur.
 *
 * Test structurel (la compilation Smarty réelle du template nécessite un
 * conteneur Symfony/Smarty complet hors périmètre sûr de ce bootstrap de
 * test CLI minimal, cf. contrainte déjà documentée test_46/test_103/
 * test_500 — voir test_731 pour la preuve comportementale réelle sur le
 * moteur de rendu maison, qui EST accessible depuis ce bootstrap) :
 * vérifie la présence du bloc conditionnel RTL et l'absence de tout style
 * border-left/padding-left INCONDITIONNEL restant dans le fichier source.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $tplPath = _PS_MODULE_DIR_ . 'neria/mails/themes/neria_global/core/ghost_cart.html';
    $src     = file_get_contents($tplPath);
    neria_assert($src !== false, 'Impossible de lire mails/themes/neria_global/core/ghost_cart.html');

    neria_assert(
        strpos($src, '{if neria_is_rtl}') !== false,
        "ghost_cart.html n'a plus de bloc conditionnel RTL pour sa note de clôture — régression du bug corrigé le 04/09/2026 (round 295)/13/09/2026 (round 350) : la bordure/padding redeviendraient à gauche même en arabe"
    );
    neria_assert(
        strpos($src, 'border-right:3px solid #e8d5b0;padding-right:14px;') !== false,
        "ghost_cart.html n'a plus de variante border-right/padding-right pour le RTL — régression du bug corrigé le 04/09/2026 (round 295)"
    );

    // Contre-preuve : aucune ligne du fichier ne doit contenir
    // border-left/padding-left EN DEHORS du bloc {else} explicitement
    // dédié au LTR (un style physique laissé inconditionnel serait la
    // régression exacte corrigée ici).
    $lines = explode("\n", $src);
    $unconditionalLeftFound = false;
    $inConditional = false;
    foreach ($lines as $line) {
        if (strpos($line, '{if neria_is_rtl}') !== false) {
            $inConditional = true;
            continue;
        }
        if (strpos($line, '{/if}') !== false) {
            $inConditional = false;
            continue;
        }
        if (!$inConditional && (strpos($line, 'border-left:') !== false || strpos($line, 'padding-left:') !== false)) {
            $unconditionalLeftFound = true;
        }
    }
    neria_assert(
        !$unconditionalLeftFound,
        "ghost_cart.html contient encore un style border-left/padding-left en dehors du branchement conditionnel RTL/LTR — régression du bug corrigé le 04/09/2026 (round 295)"
    );

    return [
        'pass'    => true,
        'message' => "ghost_cart.html inverse désormais bordure/padding pour la langue arabe (RTL), cohérent avec le reste du mécanisme {\$neria_dir}/{\$neria_text_align} — bug corrigé le 04/09/2026 (round 295)",
    ];
}
