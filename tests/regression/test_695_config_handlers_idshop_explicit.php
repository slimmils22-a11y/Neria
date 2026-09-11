<?php
/**
 * Régression : 5 handlers de configuration BO dans `neria.php`
 * (`toggle_autolang`, `save_cooldown`, `save_voucher_validity`,
 * `save_birthday_voucher`, `save_milestone_voucher`) écrivaient via
 * `Configuration::updateValue($key, $value)` SANS `$idShop` explicite —
 * retombant sur le contexte statique ambiant (`Shop::$context_id_shop`,
 * jamais fiable après une simple réassignation `Context->shop`, cf.
 * commentaires round 129/132/133/144/185/266 dans ce même fichier) — alors
 * que TOUTES les lectures correspondantes passent par
 * `ConfigManager::get()`, qui transmet toujours `$this->idShop` capturé au
 * constructeur. C'est exactement le défaut déjà identifié et corrigé pour
 * `save_senders` (round 132/133, commentaire explicite "id_shop
 * explicite... cohérent avec ConfigManager::get()/set()"), jamais porté à
 * ces 5 handlers.
 *
 * Sur une installation multi-boutique, un marchand modifiant un de ces
 * réglages (montant du bon anniversaire/palier, durée de cooldown, mode
 * auto-langue) depuis le contexte d'une boutique pouvait voir "Enregistré"
 * affiché sans que le réglage n'ait d'effet pour la boutique réellement
 * visée (écriture au mauvais endroit), ou inversement affecter une
 * boutique différente de celle en cours d'édition.
 *
 * Bug identifié le 11/09/2026 (round 337, audit WatchdogManager/
 * ConfigManager).
 *
 * Corrigé le 11/09/2026 (round 337) : les 5 handlers transmettent
 * désormais explicitement `(int) $this->context->shop->id` en 5e argument
 * de `Configuration::updateValue()`.
 *
 * Test structurel : les 5 handlers passent tous 5 arguments à
 * `Configuration::updateValue()` avec l'idShop explicite en dernière
 * position, symétrique au pattern déjà établi pour save_senders.
 * (Comportement non testable end-to-end dans cet environnement de dev :
 * `Configuration::get()`/`updateValue()` du cœur PrestaShop ignorent
 * silencieusement tout idShop explicite quand `Shop::isFeatureActive()`
 * === false — installation mono-boutique de cet environnement — cf.
 * leçon déjà documentée pour ce même piège, test_188/test_141.)
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $checks = [
        'toggle_autolang' => [
            'marker' => "Tools::getValue('neria_action') === 'toggle_autolang'",
            'needle' => "Configuration::updateValue(self::CONFIG_PREFIX . 'AUTO_LANG', (int) \$enabled, false, null, \$idShop337)",
        ],
        'save_cooldown' => [
            'marker' => "Tools::getValue('neria_action') === 'save_cooldown'",
            'needle' => "Configuration::updateValue(self::CONFIG_PREFIX . 'COOLDOWN_MINUTES', \$minutes, false, null, (int) \$this->context->shop->id)",
        ],
        'save_voucher_validity' => [
            'marker' => "Tools::getValue('neria_action') === 'save_voucher_validity'",
            'needle' => "Configuration::updateValue(self::CONFIG_PREFIX . 'VOUCHER_VALIDITY', \$days, false, null, (int) \$this->context->shop->id)",
        ],
        'save_birthday_voucher' => [
            'marker' => "Tools::getValue('neria_action') === 'save_birthday_voucher'",
            'needle' => "Configuration::updateValue(self::CONFIG_PREFIX . 'BIRTHDAY_VOUCHER_AMOUNT', \$amount, false, null, (int) \$this->context->shop->id)",
        ],
        'save_milestone_voucher' => [
            'marker' => "Tools::getValue('neria_action') === 'save_milestone_voucher'",
            'needle' => "Configuration::updateValue(self::CONFIG_PREFIX . 'MILESTONE_VOUCHER_ENABLED', \$enabled ? 1 : 0, false, null, \$idShop337b)",
        ],
    ];

    foreach ($checks as $action => $c) {
        $pos = strpos($src, $c['marker']);
        neria_assert($pos !== false, "Handler {$action} introuvable — jeu de test invalide");
        $body = substr($src, $pos, 1600);
        neria_assert(
            strpos($body, $c['needle']) !== false,
            "{$action} ne transmet plus id_shop explicite à Configuration::updateValue() — régression du bug corrigé le 11/09/2026 (round 337) : incohérence de nouveau introduite entre l'écriture (contexte ambiant) et la lecture (ConfigManager::get(), toujours scopée par \$this->idShop)"
        );
    }

    // Contrôle négatif : save_senders (déjà correct depuis round 132/133)
    // doit rester intact, preuve que ce round n'a pas introduit d'effet de
    // bord sur le pattern déjà établi.
    $posSenders = strpos($src, "Tools::getValue('neria_action') === 'save_senders'");
    neria_assert($posSenders !== false, "Handler save_senders introuvable — jeu de test invalide");
    $bodySenders = substr($src, $posSenders, 1600);
    neria_assert(
        strpos($bodySenders, 'false, null, (int) $this->context->shop->id') !== false,
        "save_senders ne transmet plus id_shop explicite — régression d'un correctif antérieur (round 132/133), sans lien direct avec ce round mais qui invaliderait la référence utilisée ici"
    );

    return [
        'pass'    => true,
        'message' => "Les 5 handlers de configuration BO (toggle_autolang, save_cooldown, save_voucher_validity, save_birthday_voucher, save_milestone_voucher) transmettent désormais id_shop explicite à Configuration::updateValue(), cohérent avec ConfigManager::get() et le pattern déjà établi (save_senders, round 132/133) — bug corrigé le 11/09/2026 (round 337)",
    ];
}
