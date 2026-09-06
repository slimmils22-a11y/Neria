<?php
/**
 * Régression : ConfigManager::getSignatureConfig() (lu par l'onglet
 * Configure BO) et EmailRenderer::resolveSignature() (lu à l'injection
 * réelle dans les emails) résolvaient tous deux la signature active via
 * `WHERE id_shop = ... AND is_active = 1` SANS `ORDER BY` — si (par
 * anomalie de données : bug ailleurs, restauration partielle, concurrence
 * d'écriture) plus d'une ligne is_active=1 existait pour la même boutique,
 * MySQL ne garantit aucun ordre particulier sans ORDER BY : la signature
 * affichée en BO pouvait alors différer de façon non déterministe de celle
 * réellement injectée dans les emails.
 *
 * Corrigé le 06/09/2026 (round 308) : ORDER BY `date_upd` DESC ajouté aux
 * deux requêtes (même convention que CertificateManager) — la ligne la
 * plus récemment mise à jour gagne, de façon déterministe et cohérente
 * entre BO et emails.
 *
 * Test comportemental réel : insère DEUX lignes is_active=1 pour la même
 * boutique avec des date_upd distinctes, puis vérifie que
 * ConfigManager::getSignatureConfig() ET EmailRenderer::resolveSignature()
 * (via Reflection, méthode privée) résolvent tous deux systématiquement
 * la même ligne (la plus récente), de façon reproductible sur plusieurs
 * appels.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;

    $db->execute("DELETE FROM {$prefix}neria_signature WHERE signer_name IN ('Regtest585Old', 'Regtest585New')");
    // date_upd distinctes et non ambiguës (2 secondes d'écart) — la plus
    // récente (New) doit systématiquement gagner.
    $db->execute(
        "INSERT INTO {$prefix}neria_signature (id_shop, signer_name, signer_title, font_style, color, image_path, is_active, date_add, date_upd)
         VALUES ({$idShop}, 'Regtest585Old', '', 'elegant', '#b38b59', 'img/old.png', 1, NOW(), DATE_SUB(NOW(), INTERVAL 2 SECOND))"
    );
    $db->execute(
        "INSERT INTO {$prefix}neria_signature (id_shop, signer_name, signer_title, font_style, color, image_path, is_active, date_add, date_upd)
         VALUES ({$idShop}, 'Regtest585New', '', 'elegant', '#b38b59', 'img/new.png', 1, NOW(), NOW())"
    );

    try {
        $module = neria_test_module();
        $config = new ConfigManager($module);
        $renderer = new EmailRenderer($module);

        $refMethod = new ReflectionMethod($renderer, 'resolveSignature');
        $refMethod->setAccessible(true);

        // Répété 5 fois : sans ORDER BY, un résultat non déterministe se
        // manifesterait souvent dès la première variation de plan
        // d'exécution — 5 appels donnent une marge de sécurité raisonnable.
        for ($i = 0; $i < 5; $i++) {
            $boConfig = $config->getSignatureConfig();
            neria_assert(
                isset($boConfig['founder_name']) && $boConfig['founder_name'] === 'Regtest585New',
                "ConfigManager::getSignatureConfig() ne résout plus systématiquement la signature la PLUS RÉCEMMENT mise à jour (obtenu : '" . ($boConfig['founder_name'] ?? '?') . "') — régression du bug corrigé le 06/09/2026 (round 308) : requête sans ORDER BY, résultat non déterministe possible"
            );

            $emailSig = $refMethod->invoke($renderer, $idShop);
            neria_assert(
                isset($emailSig['name']) && $emailSig['name'] === 'Regtest585New',
                "EmailRenderer::resolveSignature() ne résout plus systématiquement la signature la PLUS RÉCEMMENT mise à jour (obtenu : '" . ($emailSig['name'] ?? '?') . "') — régression du bug corrigé le 06/09/2026 (round 308)"
            );
        }

        return [
            'pass'    => true,
            'message' => "ConfigManager::getSignatureConfig() et EmailRenderer::resolveSignature() résolvent tous deux, de façon déterministe et cohérente entre eux, la signature active la plus récemment mise à jour (ORDER BY date_upd DESC) — bug corrigé le 06/09/2026 (round 308)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_signature WHERE signer_name IN ('Regtest585Old', 'Regtest585New')");
    }
}
