<?php
/**
 * Régression : ManualSendManager::getPreferencesGuardStatus() et
 * getCooldownGuestNoticeStatus() interpolaient l'email transmis tel quel
 * dans le message renvoyé via AdminTranslator::tVars({email}) — qui fait
 * un strtr() brut, SANS échappement HTML (contrairement à la convention
 * déjà établie ailleurs, ex. EmailRenderer.php::renderPreviewHtml()).
 *
 * Ces 2 méthodes alimentent les endpoints AJAX check_preferences_guard et
 * check_cooldown_guest_notice (neria.php), qui n'appliquent AUCUNE
 * validation Validate::isEmail() côté serveur sur le paramètre
 * neria_email. views/templates/admin/send.tpl injecte ensuite le message
 * reçu directement via `textEl.innerHTML = data.message` /
 * `cooldownNoticeText.innerHTML = d.message` — sans passer par Smarty
 * |escape:'html' (le garde-fou de gate côté JS, email.indexOf('@'), est
 * côté client uniquement et ne protège pas un appel direct de l'endpoint).
 *
 * Bug réel identifié round bloc B (17/09/2026, passage template-par-
 * template sur les .tpl admin) : un opérateur BO saisissant (volontairement
 * ou par erreur, ex. copier-coller depuis une source non fiable) une
 * valeur contenant du HTML/JS dans le champ destinataire de l'envoi manuel
 * déclenchait son exécution dans sa propre session BO authentifiée, dès la
 * vérification en temps réel du garde-fou (avant même tout envoi réel).
 *
 * Corrigé : email passé à htmlspecialchars(ENT_QUOTES, 'UTF-8') avant
 * interpolation dans les 2 messages.
 *
 * Test comportemental réel : un email contenant une charge XSS classique
 * doit revenir dans le message avec les métacaractères HTML échappés
 * (jamais de '<' ou '>' brut), pour les 2 méthodes.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/PreferencesManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();

    // Email syntaxiquement invalide (Validate::isEmail() le rejetterait),
    // mais les 2 endpoints AJAX visés ne valident jamais le format — le
    // scénario réel n'exige donc pas une adresse email valide.
    $xssEmail = '<img src=x onerror=alert(1)>@evil.test';
    $payload  = '<img src=x onerror=alert(1)>';

    $manual = new ManualSendManager($module);
    $cfg    = new ConfigManager($module);

    // ── 1. getCooldownGuestNoticeStatus() ────────────────────────────
    // Aucun client réel ne correspond à cet email -> "notice" (destinataire
    // invité), le chemin qui construit le message vulnérable. L'état de
    // NERIA_COOLDOWN_ENABLED sur cet environnement n'est pas garanti —
    // forcé explicitement puis restauré, plutôt que de supposer l'état
    // ambiant (jeu de test fiable indépendamment de l'environnement).
    $wasCooldownEnabled = $cfg->isCooldownEnabled();
    $cfg->setCooldownEnabled(true);

    try {
        $cooldownStatus = $manual->getCooldownGuestNoticeStatus($xssEmail);
        neria_assert(
            $cooldownStatus['notice'] === true && $cooldownStatus['message'] !== '',
            'jeu de test invalide : getCooldownGuestNoticeStatus() ne renvoie pas de message pour un destinataire invité malgré NERIA_COOLDOWN_ENABLED forcé à vrai'
        );
        neria_assert(
            strpos($cooldownStatus['message'], $payload) === false,
            "getCooldownGuestNoticeStatus() renvoie la charge XSS NON échappée dans le message : {$cooldownStatus['message']} — régression du correctif bloc B (17/09/2026), exploitable via l'endpoint AJAX check_cooldown_guest_notice (aucune validation d'email côté serveur) et injectée en innerHTML brut par send.tpl"
        );
        neria_assert(
            strpos($cooldownStatus['message'], 'onerror') !== false && strpos($cooldownStatus['message'], '&lt;') !== false,
            'jeu de test invalide : le message ne contient plus la charge (même échappée) — vérifiez la clé de traduction msg.cooldown_guest_notice'
        );
    } finally {
        $cfg->setCooldownEnabled($wasCooldownEnabled);
    }

    // ── 2. getPreferencesGuardStatus() ───────────────────────────────
    // L'opt-out est enregistré directement sous l'email malicieux : aucun
    // client réel ne correspond à $xssEmail (findCustomer() renvoie null),
    // donc isAllowed() retombe sur la recherche par email en id_customer=0
    // — même mécanique que test_406, mais ici l'email EST la charge XSS,
    // pour exercer réellement le message vulnérable (et pas seulement un
    // email voisin qui laisserait 'message' vide et l'assertion inerte).
    $db->execute("DELETE FROM {$prefix}neria_preferences WHERE email = '" . pSQL($xssEmail) . "'");
    $idShop = (int) Context::getContext()->shop->id;
    $db->execute(
        "INSERT INTO {$prefix}neria_preferences (id_shop, id_customer, email, category, subscribed, date_upd)
         VALUES ({$idShop}, 0, '" . pSQL($xssEmail) . "', 'newsletter', 0, NOW())"
    );

    try {
        $prefStatusXss = $manual->getPreferencesGuardStatus($xssEmail, 'newsletter');
        neria_assert(
            $prefStatusXss['blocked'] === true && $prefStatusXss['message'] !== '',
            'jeu de test invalide : getPreferencesGuardStatus() ne bloque pas malgré le désabonnement enregistré sous cet email — vérifiez PreferencesManager::isAllowed()'
        );
        neria_assert(
            strpos($prefStatusXss['message'], $payload) === false,
            "getPreferencesGuardStatus() renvoie la charge XSS NON échappée dans le message : {$prefStatusXss['message']} — régression du correctif bloc B (17/09/2026), exploitable via l'endpoint AJAX check_preferences_guard et injectée en innerHTML brut par send.tpl"
        );
        neria_assert(
            strpos($prefStatusXss['message'], 'onerror') !== false && strpos($prefStatusXss['message'], '&lt;') !== false,
            'jeu de test invalide : le message ne contient plus la charge (même échappée) — vérifiez la clé de traduction msg.send_blocked_preferences'
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_preferences WHERE email = '" . pSQL($xssEmail) . "'");
    }

    return [
        'pass'    => true,
        'message' => "ManualSendManager::getPreferencesGuardStatus()/getCooldownGuestNoticeStatus() échappent bien l'email en HTML avant interpolation dans le message — bug XSS corrigé round bloc B (17/09/2026)",
    ];
}
