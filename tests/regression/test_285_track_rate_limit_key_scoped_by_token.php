<?php
/**
 * Régression : controllers/front/track.php throttlait les écritures de
 * stats via une clé APCu scopée par IP SEULE ('neria_track_rl_' . md5($ip)),
 * tous tokens/destinataires confondus. Derrière un NAT/proxy partagé
 * (bureau, opérateur mobile), un seul destinataire très actif épuisait le
 * quota de 30 écritures/10s pour TOUS les autres visiteurs légitimes de la
 * même IP — pixels/clics toujours servis, mais leur enregistrement en
 * stats silencieusement sauté pour des destinataires n'ayant rien à voir
 * avec l'abus (sous-comptage silencieux, pas une faille de sécurité).
 *
 * Corrigé le 13/08/2026 (round 164) : la clé combine désormais IP + token
 * ('t'), isolant le throttling par destinataire réel.
 *
 * Test structurel (déclencher une vraie rafale APCu nécessiterait une
 * extension non garantie disponible en CLI de test) : vérifie que la clé
 * de rate-limit intègre bien le token, pas seulement l'IP.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/controllers/front/track.php');
    neria_assert($src !== false, 'Impossible de lire controllers/front/track.php');

    neria_assert(
        strpos($src, "'neria_track_rl_' . md5(\$ip)") === false,
        "track.php utilise de nouveau une clé de throttling scopée par IP seule — régression du bug corrigé le 13/08/2026 (round 164)"
    );
    neria_assert(
        strpos($src, "'neria_track_rl_' . md5(\$ip . '|' . \$token)") !== false,
        "track.php ne combine plus IP+token pour la clé de throttling — régression du bug corrigé le 13/08/2026 (round 164) : un NAT/proxy partagé referait sous-compter les stats de destinataires légitimes non liés à l'abus"
    );

    return [
        'pass'    => true,
        'message' => "track.php scope bien la clé de throttling par IP+token, isolant le quota par destinataire réel — bug corrigé le 13/08/2026 (round 164)",
    ];
}
