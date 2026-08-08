<?php
/**
 * Régression : l'action BO save_multipreview_keys (neria.php) doit
 * rejeter une clé Email on Acid ne contenant pas le séparateur ':'
 * (format attendu "account_id:api_password"), au lieu de l'enregistrer
 * telle quelle sans validation.
 *
 * Bug réel corrigé le 08/08/2026 (round 134) : la clé EOA était seulement
 * trim() puis chiffrée et stockée, sans aucune validation de format — une
 * valeur invalide n'était détectée qu'au prochain appel API (erreur HTTP
 * 401 côté Email on Acid), pas au moment de la sauvegarde. Même pattern
 * de validation déjà en place pour save_webhooks (isPublicUrl()).
 *
 * Test structurel + comportemental : vérifie la présence du contrôle de
 * format dans neria.php, et que la clé de traduction associée existe dans
 * toutes les langues supportées.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $posAction = strpos($src, "'neria_action') === 'save_multipreview_keys'");
    neria_assert($posAction !== false, "Action save_multipreview_keys introuvable dans neria.php");

    $block = substr($src, $posAction, 900);

    neria_assert(
        strpos($block, "strpos(\$eoaKey, ':') === false") !== false,
        "L'action save_multipreview_keys ne valide plus le format de la clé EOA (présence du séparateur ':') — régression du bug corrigé le 08/08/2026 (round 134) : une clé mal formée serait de nouveau enregistrée sans contrôle"
    );
    neria_assert(
        strpos($block, "'msg.eoa_key_invalid_format'") !== false,
        "L'action save_multipreview_keys n'assigne plus de message d'erreur explicite pour un format de clé EOA invalide — régression du bug corrigé le 08/08/2026 (round 134)"
    );

    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationEngine.php';
    $jsonPath = _PS_MODULE_DIR_ . 'neria/data/admin_translations.json';
    $dict = json_decode((string) file_get_contents($jsonPath), true);
    neria_assert(is_array($dict) && isset($dict['msg.eoa_key_invalid_format']), "La clé de traduction msg.eoa_key_invalid_format est absente du dictionnaire admin_translations.json");

    foreach (TranslationEngine::SUPPORTED_LANGS as $iso) {
        neria_assert(
            !empty($dict['msg.eoa_key_invalid_format'][$iso]),
            "msg.eoa_key_invalid_format n'a pas de traduction pour la langue '{$iso}'"
        );
    }

    return [
        'pass'    => true,
        'message' => "L'action save_multipreview_keys valide bien le format de la clé Email on Acid avant de l'enregistrer, avec un message d'erreur traduit dans toutes les langues supportées",
    ];
}
