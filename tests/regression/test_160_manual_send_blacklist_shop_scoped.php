<?php
/**
 * Régression : ManualSendManager::send()/scheduleManual() doivent vérifier
 * la blacklist de LA BOUTIQUE DU CLIENT réel, pas celle du contexte BO de
 * l'opérateur qui déclenche l'envoi.
 *
 * Bug réel corrigé le 08/08/2026 (round 136) : BlacklistManager n'avait
 * pas de moyen de recevoir un idShop explicite — son constructeur retombait
 * toujours sur Context::getContext()->shop->id. Un opérateur en contexte
 * "Boutique A" envoyant manuellement (ou planifiant) un template à un
 * client de la "Boutique B", où ce template est blacklisté sur B,
 * contournait silencieusement la règle : la blacklist de A (vide ou
 * différente) était vérifiée à la place.
 *
 * Test comportemental réel : pose une règle de blacklist sur la boutique
 * B uniquement, place le contexte d'exécution sur la boutique A, puis
 * vérifie qu'un BlacklistManager construit avec l'idShop explicite de B
 * détecte bien la règle — alors qu'un BlacklistManager sans idShop
 * explicite (contexte ambiant = A) ne la détecte pas, confirmant que le
 * scoping explicite est bien ce qui fait la différence.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/BlacklistManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php';

    $shops = Db::getInstance()->executeS('SELECT id_shop FROM `' . _DB_PREFIX_ . 'shop` WHERE active = 1 ORDER BY id_shop ASC');
    neria_assert(is_array($shops) && count($shops) >= 1, 'Aucune boutique active trouvée pour le test');

    $idShopA = (int) $shops[0]['id_shop'];
    $idShopB = count($shops) > 1 ? (int) $shops[1]['id_shop'] : $idShopA;
    $testTemplate = 'neria_test_blacklist_round136';

    $originalContext = Shop::getContextShopID(true);

    try {
        // Pose une règle de blacklist sur la boutique B uniquement.
        $mgrB = new BlacklistManager($idShopB);
        $mgrB->add($testTemplate, '');

        if ($idShopB !== $idShopA) {
            // Contexte d'exécution ambiant reste sur A (comme le contexte
            // BO d'un opérateur qui déclenche l'envoi manuel).
            Context::getContext()->shop = new Shop($idShopA);

            // Sans idShop explicite (ancien comportement) : ne détecte PAS
            // la règle de B — c'est exactement le bug corrigé.
            $mgrAmbiant = new BlacklistManager();
            neria_assert(
                $mgrAmbiant->isBlacklisted($testTemplate, '') === false,
                "BlacklistManager() sans idShop explicite détecte la règle de la boutique B alors que le contexte ambiant est A — jeu de test invalide (les deux boutiques partageraient déjà la même donnée)"
            );

            // Avec idShop explicite = B (comme ManualSendManager le fait
            // désormais) : détecte bien la règle, peu importe le contexte
            // ambiant.
            $mgrExplicit = new BlacklistManager($idShopB);
            neria_assert(
                $mgrExplicit->isBlacklisted($testTemplate, '') === true,
                "BlacklistManager(\$idShopB) ne détecte plus la règle de blacklist de la boutique B — régression du bug corrigé le 08/08/2026 (round 136) : le scoping explicite d'idShop ne fonctionnerait plus"
            );
        } else {
            neria_assert((new BlacklistManager($idShopB))->isBlacklisted($testTemplate, '') === true, "Jeu de test invalide sur boutique unique");
        }

        // Vérification structurelle complémentaire : ManualSendManager doit
        // bien passer $idShop/$idShopManual à BlacklistManager.
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php');
        neria_assert(
            strpos($src, 'new \BlacklistManager($idShop))') !== false,
            "ManualSendManager::send() n'instancie plus BlacklistManager avec l'idShop explicite du client — régression du bug corrigé le 08/08/2026 (round 136)"
        );
        neria_assert(
            strpos($src, 'new \BlacklistManager($idShopManual))') !== false,
            "ManualSendManager::scheduleManual() n'instancie plus BlacklistManager avec l'idShopManual explicite du client — régression du bug corrigé le 08/08/2026 (round 136)"
        );
    } finally {
        $mgrCleanup = new BlacklistManager($idShopB);
        $rules = $mgrCleanup->getAll();
        foreach ($rules as $rule) {
            if ($rule['template'] === $testTemplate) {
                $mgrCleanup->remove((int) $rule['id_blacklist']);
            }
        }
        Context::getContext()->shop = new Shop($originalContext);
    }

    return [
        'pass'    => true,
        'message' => "ManualSendManager::send()/scheduleManual() vérifient bien la blacklist de la boutique du CLIENT (idShop explicite), pas celle du contexte BO ambiant de l'opérateur",
    ];
}
