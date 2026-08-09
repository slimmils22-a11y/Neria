<?php
/**
 * Régression : EmailRenderer::resolveSignature()/injectSignatureVars()
 * doivent recevoir l'idShop explicite du destinataire réel
 * (resolveShopId($params)), pas le contexte d'exécution ambiant.
 *
 * Bug réel corrigé le 08/08/2026 (round 138) : resolveSignature()
 * utilisait directement $this->context->shop->id au lieu de l'idShop du
 * destinataire — un cron/envoi programmé traitant un email pour la
 * boutique B pouvait injecter la signature manuscrite configurée pour la
 * boutique A si le contexte ambiant n'avait pas encore basculé,
 * incohérent avec resolveCustomerId()/{preferences_url} dans le même
 * fichier qui utilisent déjà resolveShopId($params).
 *
 * Test comportemental réel : deux boutiques avec des signatures
 * différentes, vérifie que resolveSignature($idShop) résout bien celle de
 * la boutique demandée, indépendamment du contexte ambiant.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';

    $shops = Db::getInstance()->executeS('SELECT id_shop FROM `' . _DB_PREFIX_ . 'shop` WHERE active = 1 ORDER BY id_shop ASC');
    neria_assert(is_array($shops) && count($shops) >= 1, 'Aucune boutique active trouvée pour le test');

    $idShopA = (int) $shops[0]['id_shop'];
    $idShopB = count($shops) > 1 ? (int) $shops[1]['id_shop'] : $idShopA;
    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $table = $prefix . 'neria_signature';

    $originalContext = Shop::getContextShopID(true);

    try {
        $db->execute("DELETE FROM {$table} WHERE id_shop IN ({$idShopA}, {$idShopB}) AND signer_name LIKE 'RoundTest%'");
        $db->execute("INSERT INTO {$table} (id_shop, signer_name, signer_title, image_path, is_active, date_add, date_upd) VALUES ({$idShopA}, 'RoundTest-A', 'Fondatrice A', '', 1, NOW(), NOW())");
        if ($idShopB !== $idShopA) {
            $db->execute("INSERT INTO {$table} (id_shop, signer_name, signer_title, image_path, is_active, date_add, date_upd) VALUES ({$idShopB}, 'RoundTest-B', 'Fondatrice B', '', 1, NOW(), NOW())");
        }

        Context::getContext()->shop = new Shop($idShopA);
        $renderer = new EmailRenderer(neria_test_module());
        $method = new ReflectionMethod(EmailRenderer::class, 'resolveSignature');
        $method->setAccessible(true);

        $resultA = $method->invoke($renderer, $idShopA);
        neria_assert($resultA['name'] === 'RoundTest-A', "resolveSignature(\$idShopA) n'a pas résolu la signature attendue — jeu de test invalide");

        if ($idShopB !== $idShopA) {
            $resultB = $method->invoke($renderer, $idShopB);
            neria_assert(
                $resultB['name'] === 'RoundTest-B',
                "resolveSignature(\$idShopB) résout '{$resultB['name']}' au lieu de 'RoundTest-B' alors que le contexte ambiant est la boutique A — régression du bug corrigé le 08/08/2026 (round 138) : la signature redeviendrait résolue via le contexte ambiant au lieu de l'idShop explicite"
            );
        }
    } finally {
        $db->execute("DELETE FROM {$table} WHERE id_shop IN ({$idShopA}, {$idShopB}) AND signer_name LIKE 'RoundTest%'");
        Context::getContext()->shop = new Shop($originalContext);
    }

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php');
    neria_assert(
        strpos($src, 'private function resolveSignature(int $idShop): array') !== false,
        "resolveSignature() n'accepte plus l'idShop explicite en paramètre — régression du bug corrigé le 08/08/2026 (round 138)"
    );

    return [
        'pass'    => true,
        'message' => "EmailRenderer::resolveSignature() résout bien la signature manuscrite via l'idShop explicite du destinataire, pas le contexte d'exécution ambiant",
    ];
}
