<?php
/**
 * Arbitrage tranché round bloc D (17/09/2026) : BounceManager::
 * parseGenericWebhook() (branche "default" pour tout ESP non explicitement
 * supporté — Mailgun/SendGrid/Postmark) classait tout événement ne
 * contenant PAS littéralement le mot "soft" comme 'hard' par défaut.
 *
 * Neria est un module vendu mondialement à des commerçants utilisant une
 * grande diversité de fournisseurs email au-delà des 3 explicitement
 * supportés (fournisseurs régionaux : Brevo, OVH, Amazon SES, etc.) — ce
 * chemin générique n'est donc PAS un cas marginal mais le chemin réel pour
 * une part significative de la base installée mondiale.
 *
 * Un défaut 'hard' pour un format d'événement ambigu/inconnu est le pire
 * des deux sens d'erreur possibles à cette échelle : isBounced() ne
 * réhabilite JAMAIS automatiquement un 'hard' (seule une action BO
 * manuelle reactivateBounce() le peut) — un vrai client d'un marchand
 * utilisant un ESP non reconnu perdait donc silencieusement et
 * DÉFINITIVEMENT ses emails de commande dès le premier événement de bounce
 * au format ambigu, sans que le marchand ne comprenne pourquoi.
 *
 * Corrigé : seul un marqueur EXPLICITE de permanence ('hard'/'permanent')
 * classe désormais en 'hard' ; tout le reste (y compris un format
 * totalement inconnu) reste 'soft' — réversible, auto-expirable après
 * CFG_SOFT_EXPIRY_MONTHS, cohérent avec le principe "échouer vers le sens
 * réversible" déjà appliqué ailleurs dans ce module.
 *
 * Test comportemental réel : un événement webhook générique totalement
 * ambigu (aucun des deux mots 'hard'/'soft'/'permanent') doit être
 * enregistré en 'soft', pas 'hard'. Un événement portant explicitement
 * 'hard' ou 'permanent' doit rester classé 'hard'.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/BounceManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $mgr    = new BounceManager($module);

    $emailAmbiguous = 'regtest779.ambiguous@example.test';
    $emailExplicit  = 'regtest779.explicit@example.test';

    $db->execute("DELETE FROM {$prefix}neria_bounces WHERE email IN ('" . pSQL($emailAmbiguous) . "', '" . pSQL($emailExplicit) . "')");

    try {
        // Événement générique totalement ambigu — ni 'hard', ni 'soft', ni
        // 'permanent' — format inconnu d'un ESP tiers non supporté.
        $ambiguousPayload = [
            'event'   => 'bounce_notification',
            'email'   => $emailAmbiguous,
            'reason'  => 'mailbox issue',
            'id'      => 'evt-779-ambiguous',
        ];
        $ok1 = $mgr->processBounceWebhook($ambiguousPayload, 'generic');
        neria_assert($ok1 === true, "processBounceWebhook() n'a pas enregistré l'événement générique ambigu — jeu de test invalide");

        $typeAmbiguous = (string) $db->getValue(
            "SELECT `type` FROM {$prefix}neria_bounces WHERE email = '" . pSQL($emailAmbiguous) . "'"
        );
        neria_assert(
            $typeAmbiguous === 'soft',
            "BounceManager::parseGenericWebhook() classe l'événement ambigu en '{$typeAmbiguous}' au lieu de 'soft' — régression de l'arbitrage bloc D (17/09/2026) : un vrai client d'un ESP tiers non reconnu perdrait de nouveau ses emails DÉFINITIVEMENT dès le premier événement au format ambigu"
        );

        // Événement générique portant explicitement un marqueur de
        // permanence — doit rester classé 'hard'.
        $explicitPayload = [
            'event'   => 'hard_bounce_permanent_failure',
            'email'   => $emailExplicit,
            'reason'  => 'mailbox does not exist',
            'id'      => 'evt-779-explicit',
        ];
        $ok2 = $mgr->processBounceWebhook($explicitPayload, 'generic');
        neria_assert($ok2 === true, "processBounceWebhook() n'a pas enregistré l'événement générique explicite — jeu de test invalide");

        $typeExplicit = (string) $db->getValue(
            "SELECT `type` FROM {$prefix}neria_bounces WHERE email = '" . pSQL($emailExplicit) . "'"
        );
        neria_assert(
            $typeExplicit === 'hard',
            "BounceManager::parseGenericWebhook() classe un événement portant explicitement 'hard_bounce_permanent_failure' en '{$typeExplicit}' au lieu de 'hard' — le correctif de l'arbitrage bloc D ne doit pas ignorer un marqueur de permanence explicite"
        );

        return [
            'pass'    => true,
            'message' => "BounceManager::parseGenericWebhook() classe désormais un événement ambigu en 'soft' (réversible) par défaut, réservant 'hard' aux marqueurs explicites — arbitrage tranché round bloc D (17/09/2026)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_bounces WHERE email IN ('" . pSQL($emailAmbiguous) . "', '" . pSQL($emailExplicit) . "')");
    }
}
