<?php
/**
 * Régression : le bloc de tracking upsell dans track.php (détection de
 * neria_ur dans l'URL cible, action=click) était un FRÈRE du bloc
 * `if ($ref)` (token valide/existant en base), pas un ENFANT — atteignable
 * avec un token totalement inconnu, sans aucune vérification de signature
 * HMAC (contrairement à la redirection, protégée par
 * NeriaTools::verifyTrackingUrl()). Seul $trackingWriteAllowed (throttling
 * best-effort par IP+token, contournable en faisant varier le token)
 * protégeait UpsellManager::recordClick() — une écriture SQL sans
 * authentification réelle sur ps_neria_upsell.clicked_at.
 *
 * Corrigé le 19/08/2026 (round 186) : le bloc upsell est désormais imbriqué
 * DANS le bloc if ($ref), donc inatteignable sans un token réellement
 * connu en base.
 *
 * Test structurel par analyse de profondeur d'accolades (un test HTTP
 * complet nécessiterait de simuler toute la pile ModuleFrontController,
 * hors de portée d'un test isolé) : localise "if ($ref) {" et l'appel
 * UpsellManager(...)->recordClick(...), puis calcule la profondeur
 * d'imbrication réelle entre les deux pour confirmer que le second est
 * bien À L'INTÉRIEUR du premier (jamais retombé à la profondeur du if,
 * ni en-dessous, avant d'atteindre l'appel).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/controllers/front/track.php');
    neria_assert($src !== false, 'Impossible de lire controllers/front/track.php');

    $posRef = strpos($src, 'if ($ref) {');
    neria_assert($posRef !== false, "\"if (\$ref) {\" introuvable — jeu de test invalide");

    $posUpsellCall = strpos($src, '(new UpsellManager($this->module))->recordClick($idUpsell);', $posRef);
    neria_assert($posUpsellCall !== false, "Appel UpsellManager::recordClick() introuvable après if (\$ref) — jeu de test invalide");

    // Calcule la profondeur d'accolades entre les deux positions, en
    // partant de 0 juste après l'accolade ouvrante du if ($ref).
    $between = substr($src, $posRef + strlen('if ($ref) {'), $posUpsellCall - ($posRef + strlen('if ($ref) {')));
    $depth = 0;
    $minDepth = 0;
    foreach (str_split($between) as $ch) {
        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            $minDepth = min($minDepth, $depth);
        }
    }

    neria_assert(
        $minDepth >= 0,
        "L'appel UpsellManager::recordClick() n'est plus imbriqué à l'intérieur du bloc if (\$ref) (profondeur minimale atteinte : {$minDepth}) — régression du bug corrigé le 19/08/2026 (round 186) : un token inconnu/inexistant en base pourrait de nouveau déclencher l'écriture sur ps_neria_upsell sans aucune vérification"
    );

    return [
        'pass'    => true,
        'message' => "track.php n'appelle plus UpsellManager::recordClick() qu'à l'intérieur d'un token vérifié (if (\$ref)) — bug corrigé le 19/08/2026 (round 186)",
    ];
}
