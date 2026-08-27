<?php
/**
 * Régression : neria.php — l'action BO 'quote_add' (ajout manuel d'un
 * devis B2B à la séquence de relance) lisait son contrôle anti-doublon
 * ($alreadyTracked) via Db::getValue() sans $use_cache=false. Même
 * famille de bug systémique que les rounds 210-216 : sous cache SQL
 * périmé, un double-clic ou un rechargement de formulaire POST pouvait
 * laisser passer un second INSERT pour le même devis (id_shop +
 * id_customer + quote_ref), créant deux séquences de relance parallèles
 * et des emails dupliqués au client (J-2, Jour J, prolongation).
 *
 * Corrigé le 26/08/2026 (round 217) : $use_cache=false explicite.
 *
 * Test structurel (l'action est du code inline dans le contrôleur admin,
 * nécessitant un contexte AdminController/Employee complet pour être
 * invoquée réellement — hors périmètre pratique de ce jeu de tests) :
 * vérifie la présence du garde-fou dans le code source.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $srcRaw = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($srcRaw !== false, 'Impossible de lire neria.php');
    $src = str_replace("\r", '', $srcRaw);

    $posAction = strpos($src, "Tools::getValue('neria_action') === 'quote_add'");
    neria_assert($posAction !== false, "Action 'quote_add' introuvable — jeu de test invalide");

    $body = substr($src, $posAction, 3200);

    neria_assert(
        strpos($body, "AND quote_ref = \\'' . pSQL(\$quoteRef) . '\\'',\n                    false\n                )") !== false,
        "neria.php::quote_add n'a plus \$use_cache=false sur son contrôle anti-doublon \$alreadyTracked — régression du bug corrigé le 26/08/2026 (round 217) : un devis B2B pourrait de nouveau être suivi en double, causant des emails de relance dupliqués"
    );

    return [
        'pass'    => true,
        'message' => "neria.php::quote_add résout bien \$alreadyTracked avec \$use_cache=false — bug corrigé le 26/08/2026 (round 217)",
    ];
}
