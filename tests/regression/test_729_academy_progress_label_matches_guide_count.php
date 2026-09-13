<?php
/**
 * Régression : le libellé statique initial de la barre de progression
 * academy.tpl ("0 / N") doit correspondre au nombre RÉEL de guides
 * (na-guide-card), pas à un ancien total figé depuis un ajout de guide
 * passé jamais resynchronisé avec le HTML statique.
 *
 * Bug identifié le 13/09/2026 (round 349, audit multi-agents, angle
 * academy/empreinte carbone) : le HTML statique affichait "0 / 6" alors
 * que 8 cartes de guide existent réellement (openrate, subject, gdpr,
 * deliverability, segmentation, loyalty, abtest, cart) et que
 * updateProgress() (JS) calcule explicitement `n / 8 * 100`. Le JS écrase
 * ce texte au chargement de la page, donc l'incohérence n'était visible
 * que lors d'un flash très bref avant exécution JS (ou si le JS échoue à
 * s'exécuter) — impact réel faible, mais résidu de contenu obsolète.
 *
 * Test structurel : compte le nombre réel de `na-guide-card` dans le
 * template et vérifie que le libellé statique initial l'annonce
 * correctement, et que updateProgress() utilise bien ce même dénominateur.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $tplPath = _PS_MODULE_DIR_ . 'neria/views/templates/admin/academy.tpl';
    $src = file_get_contents($tplPath);
    neria_assert($src !== false, 'Impossible de lire academy.tpl');

    $guideCount = preg_match_all('/class="na-guide-card"/', $src);
    neria_assert($guideCount > 0, 'jeu de test invalide : aucune carte na-guide-card trouvée');

    neria_assert(
        strpos($src, '<span id="na-progress-label">0 / ' . $guideCount . '</span>') !== false,
        "Le libellé statique initial de la progression academy n'annonce plus 0 / {$guideCount} (nombre réel de guides) — régression du bug corrigé le 13/09/2026 (round 349)"
    );

    $posUpdate = strpos($src, 'function updateProgress()');
    neria_assert($posUpdate !== false, 'updateProgress() introuvable');
    $updateBody = substr($src, $posUpdate, 400);
    neria_assert(
        strpos($updateBody, 'n / ' . $guideCount . ' * 100') !== false
        && strpos($updateBody, "n + ' / {$guideCount}'") !== false,
        "updateProgress() ne calcule plus la progression sur {$guideCount} (nombre réel de guides) — incohérence potentielle si un guide est ajouté/retiré sans mise à jour cohérente"
    );

    return [
        'pass'    => true,
        'message' => "Le libellé statique de progression academy ({$guideCount} guides) est cohérent avec le nombre réel de na-guide-card et avec le dénominateur utilisé par updateProgress() — bug corrigé le 13/09/2026 (round 349)",
    ];
}
