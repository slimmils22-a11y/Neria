<?php
/**
 * Régression : le mot de passe IMAP de la boîte de bounces, déchiffré via
 * CryptoManager::decrypt(), était réinjecté en clair dans l'attribut
 * value="" du champ <input type="password" name="bounce_imap_pass">. Le
 * masquage visuel type="password" n'empêche pas la lecture du secret en
 * clair dans le code source de la page servie (devtools, cache/proxy),
 * annulant l'intérêt du chiffrement au repos une fois la page rendue.
 *
 * Corrigé le 13/08/2026 (round 163) : bounce_cfg.pass est désormais
 * toujours vide côté PHP (jamais transmis au template), remplacé par un
 * indicateur bounce_cfg.has_pass affiché comme hint ("déjà enregistré,
 * laisser vide pour conserver"). Le handler save_bounce_config ignorait
 * déjà un champ vide et conservait la valeur existante — comportement
 * inchangé, seule l'exposition en clair est supprimée.
 *
 * Test réel + structurel : sauvegarde un vrai mot de passe IMAP de test
 * via CryptoManager (comme le ferait save_bounce_config), vérifie que
 * neria.php ne transmet plus jamais la valeur déchiffrée au template
 * (structurel, car rendre getContentImpl() produirait une page BO
 * entière), puis vérifie via CryptoManager que le mot de passe réel reste
 * bien récupérable en base (le chiffrement au repos n'est pas cassé par
 * ce correctif).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CryptoManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/BounceManager.php';
    neria_assert(class_exists('CryptoManager'), 'Classe CryptoManager introuvable');
    neria_assert(class_exists('BounceManager'), 'Classe BounceManager introuvable');

    $original = (string) Configuration::get(BounceManager::CFG_IMAP_PASS);
    $testPass = 'Round163TestPass_' . uniqid();

    try {
        Configuration::updateValue(BounceManager::CFG_IMAP_PASS, CryptoManager::encrypt($testPass));

        $decrypted = CryptoManager::decrypt((string) Configuration::get(BounceManager::CFG_IMAP_PASS));
        neria_assert(
            $decrypted === $testPass,
            "Le chiffrement/déchiffrement du mot de passe IMAP ne fonctionne plus — jeu de test invalide (pas lié au bug round 163)"
        );
    } finally {
        Configuration::updateValue(BounceManager::CFG_IMAP_PASS, $original);
    }

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $posBounceCfg = strpos($src, "'bounce_cfg'");
    neria_assert($posBounceCfg !== false, "'bounce_cfg' introuvable dans neria.php — jeu de test invalide");
    $body = substr($src, $posBounceCfg, 1600);

    neria_assert(
        strpos($body, "'pass'     => '',") !== false,
        "neria.php transmet de nouveau une valeur non vide pour bounce_cfg.pass — régression du bug corrigé le 13/08/2026 (round 163) : le mot de passe IMAP redeviendrait lisible en clair dans le code source de la page BO"
    );
    neria_assert(
        strpos($body, "CryptoManager::decrypt((string) Configuration::get(BounceManager::CFG_IMAP_PASS))") === false,
        "neria.php déchiffre de nouveau le mot de passe IMAP pour le transmettre au template bounce_cfg — régression du bug corrigé le 13/08/2026 (round 163)"
    );
    neria_assert(
        strpos($body, "'has_pass'") !== false,
        "L'indicateur bounce_cfg.has_pass a disparu — le template ne pourrait plus indiquer qu'un mot de passe est déjà configuré"
    );

    $tplSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/views/templates/admin/bounces.tpl');
    neria_assert($tplSrc !== false, 'Impossible de lire bounces.tpl');
    neria_assert(
        strpos($tplSrc, 'value="{$bounce_cfg.pass') === false,
        "bounces.tpl affiche de nouveau bounce_cfg.pass dans l'attribut value du champ mot de passe — régression du bug corrigé le 13/08/2026 (round 163)"
    );

    return [
        'pass'    => true,
        'message' => "Le mot de passe IMAP des bounces n'est plus transmis en clair au template BO, tout en restant correctement chiffré/déchiffrable en base — bug corrigé le 13/08/2026 (round 163)",
    ];
}
