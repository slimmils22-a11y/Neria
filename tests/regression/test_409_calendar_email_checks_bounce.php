<?php
/**
 * Régression : CalendarManager::sendCalendarEmail() ne vérifiait jamais
 * BounceManager::isBounced(), contrairement aux 8 autres chemins d'envoi du
 * module (CertificateManager, CollectionManager, CustomerEmailHistoryManager,
 * LookCompletionManager, ManualSendManager, OrderTriggersManager,
 * QueueManager, LookCompletionManager). Seuls la blacklist du TEMPLATE et
 * les préférences par catégorie étaient vérifiées — jamais l'adresse
 * elle-même.
 *
 * Bug réel identifié le 23/08/2026 (round 191) : une adresse en hard bounce
 * continuait de recevoir les emails calendrier (Noël, fête des mères,
 * etc.), dégradant la réputation du domaine d'envoi — exactement le cas
 * que BounceManager existe pour prévenir ailleurs dans le module.
 *
 * Corrigé le 23/08/2026 (round 191) : BounceManager::isBounced() ajouté,
 * juste après le contrôle blacklist.
 *
 * Test comportemental réel : seed une adresse en hard bounce (status
 * 'active') puis appelle sendCalendarEmail() (privée, via Reflection) —
 * doit retourner false sans jamais atteindre Mail::Send().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CalendarManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/BounceManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();

    $email = 'bounced.round191@example.test';

    $db->execute("DELETE FROM {$prefix}neria_bounces WHERE email = '" . pSQL($email) . "'");
    $db->execute(
        "INSERT INTO {$prefix}neria_bounces (email, type, source, bounce_count, last_bounce_at, status, date_add)
         VALUES ('" . pSQL($email) . "', 'hard', 'manual', 1, NOW(), 'active', NOW())"
    );

    try {
        neria_assert(
            BounceManager::isBounced($email),
            'jeu de test invalide : BounceManager::isBounced() ne détecte pas le bounce seedé'
        );

        $mgr = new CalendarManager($module);
        $ref = new ReflectionMethod(CalendarManager::class, 'sendCalendarEmail');
        $ref->setAccessible(true);

        $customer = [
            'id_customer' => 0,
            'id_lang'     => (int) Configuration::get('PS_LANG_DEFAULT'),
            'firstname'   => 'Test',
            'lastname'    => 'Round191',
            'email'       => $email,
        ];

        $result = $ref->invoke($mgr, $customer, 'christmas', 'fr');
        neria_assert(
            $result === false,
            "CalendarManager::sendCalendarEmail() retourne " . var_export($result, true) . " pour une adresse en hard bounce au lieu de false — régression du bug corrigé le 23/08/2026 (round 191) : les emails calendrier seraient de nouveau envoyés à des adresses en bounce, dégradant la réputation du domaine"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_bounces WHERE email = '" . pSQL($email) . "'");
    }

    return [
        'pass'    => true,
        'message' => "CalendarManager::sendCalendarEmail() vérifie bien BounceManager avant l'envoi — bug corrigé le 23/08/2026 (round 191)",
    ];
}
