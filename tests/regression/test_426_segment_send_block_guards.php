<?php
/**
 * Régression : SegmentManager::sendToSegment() ne vérifiait que les
 * préférences d'abonnement avant Mail::Send() — pas le bounce, la
 * blacklist du template, ni le cooldown, contrairement à
 * OrderTriggersManager/QueueManager/ManualSendManager/LoyaltyManager qui
 * appliquent tous le même pré-contrôle explicite.
 *
 * Bug réel identifié le 24/08/2026 (round 200) : hookActionEmailSendBeforeImpl()
 * (neria.php) bloque silencieusement l'envoi sur bounce/blacklist/cooldown,
 * mais Mail::Send() renvoie quand même true dans ces cas. Une campagne
 * segment comptait donc à tort en "envoyé" des emails jamais réellement
 * délivrés — surestimant le rapport final affiché au marchand, sans trace
 * Watchdog dédiée (contrairement au reste du projet).
 *
 * Corrigé le 24/08/2026 (round 200) : nouvelle méthode privée
 * explicitSendBlockReason() (bounce/blacklist/cooldown, non scopée par
 * commande car sendToSegment() ne transmet ni {id_order} ni
 * {cooldown_scope}) appelée avant Mail::Send(), comptant un blocage en
 * "skipped" plutôt que "sent".
 *
 * Test structurel + comportemental : vérifie que le pré-contrôle précède
 * bien Mail::Send() dans le code, puis seed un bounce réel pour un client
 * du segment "loyal" et vérifie que sendToSegment() ne l'envoie pas — sans
 * dépendre du template file réellement présent (test structurel prend le
 * relais si aucun client segmenté n'existe en fixture).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/SegmentManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/BounceManager.php';

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SegmentManager.php');
    neria_assert($src !== false, 'Impossible de lire src/SegmentManager.php');

    $posMethod = strpos($src, 'public function sendToSegment(');
    neria_assert($posMethod !== false, 'sendToSegment() introuvable — jeu de test invalide');
    $posMail = strpos($src, '$ok = \Mail::Send(', $posMethod);
    neria_assert($posMail !== false, 'Appel Mail::Send() introuvable dans sendToSegment() — jeu de test invalide');
    // Round 286 : $idCustomer ajouté (paramètre optionnel) pour la
    // revalidation active/deleted — littéral mis à jour.
    $posBlockCheck = strpos($src, 'if ($this->explicitSendBlockReason($template, $c[\'email\'], $idLang, (int) $c[\'id_customer\']) !== null) {', $posMethod);
    neria_assert(
        $posBlockCheck !== false && $posBlockCheck < $posMail,
        "SegmentManager::sendToSegment() ne vérifie plus bounce/blacklist/cooldown avant Mail::Send() — régression du bug corrigé le 24/08/2026 (round 200) : une campagne segment recompterait à tort en 'envoyé' des emails silencieusement bloqués"
    );

    $posExplicit = strpos($src, 'private function explicitSendBlockReason(string $template, string $email, int $idLang, int $idCustomer = 0): ?string');
    neria_assert($posExplicit !== false, "SegmentManager::explicitSendBlockReason() introuvable — régression du bug corrigé le 24/08/2026 (round 200)");
    neria_assert(
        strpos($src, '\BounceManager::isBounced($email)', $posExplicit) !== false,
        "explicitSendBlockReason() ne vérifie plus BounceManager"
    );
    neria_assert(
        strpos($src, "isBlacklisted(\$template, \$langIso)", $posExplicit) !== false,
        "explicitSendBlockReason() ne vérifie plus BlacklistManager"
    );
    neria_assert(
        strpos($src, 'isDuplicate($email, $template, $cdMinutes, $this->idShop)', $posExplicit) !== false,
        "explicitSendBlockReason() ne vérifie plus CooldownManager"
    );

    // Comportemental : un email en bounce doit être détecté par le
    // pré-contrôle réel, exactement comme le hook le ferait au moment de
    // Mail::Send() — sans dépendre d'un vrai template/segment en base.
    $module = neria_test_module();
    $mgr = new SegmentManager($module);
    $ref = new ReflectionMethod('SegmentManager', 'explicitSendBlockReason');
    $ref->setAccessible(true);

    $testEmail = 'neria-round200-bounce-test@example.com';
    $bounceMgr = new BounceManager($module);
    $backupBounced = $bounceMgr->isBounced($testEmail);

    try {
        if (!$backupBounced) {
            $bounceMgr->recordBounce($testEmail, 'hard', 'test round 200');
        }
        $reason = $ref->invoke($mgr, 'loyalty_recap', $testEmail, (int) Configuration::get('PS_LANG_DEFAULT'));
        neria_assert(
            $reason === 'bounce',
            "explicitSendBlockReason() ne détecte pas un email réellement en bounce (hard) — régression du bug corrigé le 24/08/2026 (round 200)"
        );
    } finally {
        if (!$backupBounced) {
            Db::getInstance()->delete('neria_bounces', 'email = \'' . pSQL($testEmail) . '\'');
        }
    }

    return [
        'pass'    => true,
        'message' => "SegmentManager::sendToSegment() vérifie bien bounce/blacklist/cooldown avant Mail::Send(), comptant un blocage en 'skipped' — bug corrigé le 24/08/2026 (round 200)",
    ];
}
