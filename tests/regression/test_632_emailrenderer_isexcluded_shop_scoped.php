<?php
/**
 * Régression : EmailRenderer::isExcluded() instanciait BlacklistManager()
 * SANS idShop explicite — son constructeur retombait alors sur
 * Context::getContext()->shop->id (contexte ambiant), pas la boutique du
 * DESTINATAIRE réel de l'email. Même famille de bug que ManualSendManager
 * (round 136, voir test_160), mais jamais corrigée dans EmailRenderer lui-
 * même : ManualSendManager::send() calcule bien l'idShop réel du client et
 * fait un pré-check correct AVANT Mail::Send(), mais restaure le contexte
 * ambiant (resolveShopUrl()) avant l'appel réel — c'est donc le contexte de
 * L'OPÉRATEUR BO qui était actif quand le hook actionEmailSendBefore
 * déclenchait EmailRenderer::isExcluded().
 *
 * Corrigé le 08/09/2026 (round 321) : réutilise $this->resolveShopId($params)
 * (déjà utilisé ailleurs dans ce même fichier depuis le round 138) —
 * $params['idShop'] est déjà fourni par Mail::Send() du cœur PrestaShop
 * (hook actionEmailSendBefore) dès qu'un appelant passe l'idShop explicite
 * en 13e argument, ce que ManualSendManager fait déjà.
 *
 * Test comportemental réel : pose une règle de blacklist sur la boutique B
 * uniquement, place le contexte d'exécution sur la boutique A, puis appelle
 * isExcluded() (via réflexion) avec $params['idShop']=B — doit détecter la
 * règle malgré le contexte ambiant A ; sans $params['idShop'] (repli sur le
 * contexte ambiant A), ne doit PAS la détecter, confirmant que le scoping
 * explicite est bien ce qui fait la différence.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/BlacklistManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';

    $shops = Db::getInstance()->executeS('SELECT id_shop FROM `' . _DB_PREFIX_ . 'shop` WHERE active = 1 ORDER BY id_shop ASC');
    neria_assert(is_array($shops) && count($shops) >= 1, 'Aucune boutique active trouvée pour le test');

    $idShopA = (int) $shops[0]['id_shop'];
    $idShopB = count($shops) > 1 ? (int) $shops[1]['id_shop'] : $idShopA;
    $testTemplate = 'neria_test_isexcluded_round321';

    $originalContext = Shop::getContextShopID(true);

    try {
        $mgrB = new BlacklistManager($idShopB);
        $mgrB->add($testTemplate, '');

        $renderer = new EmailRenderer(neria_test_module());
        $ref = new ReflectionMethod($renderer, 'isExcluded');
        $ref->setAccessible(true);

        if ($idShopB !== $idShopA) {
            Context::getContext()->shop = new Shop($idShopA);

            // Sans $params['idShop'] (repli sur le contexte ambiant A) : ne
            // détecte PAS la règle de B — comportement AVANT ce correctif
            // (utilisé ici comme contrôle négatif, pas comme comportement
            // souhaité).
            $excludedAmbiant = $ref->invoke($renderer, $testTemplate, []);
            neria_assert(
                $excludedAmbiant === false,
                "isExcluded() sans \$params['idShop'] détecte la règle de la boutique B alors que le contexte ambiant est A — jeu de test invalide (les deux boutiques partageraient déjà la même donnée)"
            );

            // Avec $params['idShop']=B (comme Mail::Send() le fournit
            // désormais via le hook actionEmailSendBefore) : détecte bien la
            // règle, peu importe le contexte ambiant.
            $excludedExplicit = $ref->invoke($renderer, $testTemplate, ['idShop' => $idShopB]);
            neria_assert(
                $excludedExplicit === true,
                "isExcluded() avec \$params['idShop']={$idShopB} ne détecte plus la règle de blacklist de la boutique B — régression du bug corrigé le 08/09/2026 (round 321) : le scoping explicite d'idShop ne fonctionnerait plus"
            );
        } else {
            neria_assert($ref->invoke($renderer, $testTemplate, ['idShop' => $idShopB]) === true, "Jeu de test invalide sur boutique unique");
        }

        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php');
        neria_assert(
            strpos($src, 'new BlacklistManager($this->resolveShopId($params))') !== false,
            "EmailRenderer::isExcluded() n'instancie plus BlacklistManager avec l'idShop résolu via resolveShopId(\$params) — régression du bug corrigé le 08/09/2026 (round 321)"
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
        'message' => "EmailRenderer::isExcluded() vérifie bien la blacklist de la boutique du DESTINATAIRE (idShop résolu via \$params), pas celle du contexte ambiant de l'opérateur/cron — bug corrigé le 08/09/2026 (round 321)",
    ];
}
