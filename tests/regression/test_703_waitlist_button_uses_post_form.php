<?php
/**
 * Régression : `views/templates/front/waitlist_button.tpl` rendait les
 * actions d'inscription/désinscription à la liste d'attente comme de
 * simples liens `<a href="...">`, qui ne produisent qu'une requête GET au
 * clic. Or `controllers/front/waitlist.php` exige STRICTEMENT une requête
 * POST (`if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { Tools::
 * redirect($redirect); }`, garde-fou anti-CSRF déjà présent dans son
 * code) — et `Controller::run()` (cœur PrestaShop) appelle `postProcess()`
 * pour TOUTE requête, quelle que soit sa méthode HTTP, malgré son nom.
 * Conséquence : cliquer sur le bouton "Me notifier" ou "Annuler" ne
 * déclenchait donc JAMAIS l'inscription/désinscription réelle — la
 * requête GET échouait systématiquement le contrôle de méthode et
 * redirigeait immédiatement sans toucher `neria_waitlist`, sans aucune
 * erreur visible (la page produit se rechargeait normalement). La
 * fonctionnalité liste d'attente était donc intégralement inopérante via
 * l'interface, quel que soit le client ou le produit.
 *
 * Bug identifié le 12/09/2026 (round 339, audit des contrôleurs front
 * publics track/certificate/waitlist/cron).
 *
 * Corrigé le 12/09/2026 (round 339) : les deux `<a href>` remplacés par de
 * vrais `<form method="post">` (bouton `<button type="submit">` stylé à
 * l'identique) — les paramètres (action/id_product/back) restent portés
 * par l'URL cible (déjà générée par `getModuleLink()`), donc aucun champ
 * caché supplémentaire n'est nécessaire. Le retour de `register()`/
 * `unregister()` est également désormais vérifié dans le contrôleur
 * (alerte Watchdog dédiée si l'écriture échoue silencieusement).
 *
 * Test structurel sur le template + le contrôleur (l'action réelle du
 * contrôleur ne peut pas être invoquée isolément en CLI, `Tools::
 * redirect()` termine le script — même limite déjà acceptée pour ce
 * fichier, cf. test_56) + comportemental réel sur le chemin nominal de
 * `WaitlistManager::register()`/`unregister()` (garantit que le correctif
 * de vérification du retour n'a rien cassé).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    // ── Vérification structurelle du template ─────────────────────────
    $tpl = file_get_contents(_PS_MODULE_DIR_ . 'neria/views/templates/front/waitlist_button.tpl');
    neria_assert($tpl !== false, 'Impossible de lire views/templates/front/waitlist_button.tpl');

    neria_assert(
        substr_count($tpl, '<form action="{$waitlist_') === 2,
        "waitlist_button.tpl ne contient plus 2 <form action=\"{\$waitlist_...\"> (inscription + désinscription) — régression du bug corrigé le 12/09/2026 (round 339) : le bouton redeviendrait un simple <a href> (GET), incompatible avec l'exigence POST du contrôleur, rendant la fonctionnalité intégralement inopérante"
    );
    neria_assert(
        substr_count($tpl, 'url|escape:\'html\'}" method="post"') === 2,
        "waitlist_button.tpl ne contient plus 2 <form ... method=\"post\"> ciblant waitlist_subscribe_url/waitlist_unsubscribe_url — régression du bug corrigé le 12/09/2026 (round 339)"
    );
    neria_assert(
        strpos($tpl, '<a href="{$waitlist_subscribe_url') === false
            && strpos($tpl, '<a href="{$waitlist_unsubscribe_url') === false,
        "waitlist_button.tpl contient encore un <a href> vers waitlist_subscribe_url/waitlist_unsubscribe_url — régression : ce lien produirait une requête GET systématiquement rejetée par le contrôleur"
    );

    // ── Vérification structurelle du contrôleur ────────────────────────
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/controllers/front/waitlist.php');
    neria_assert($src !== false, 'Impossible de lire controllers/front/waitlist.php');
    neria_assert(
        strpos($src, '$writeOk = $action === \'subscribe\'') !== false
            && strpos($src, "WatchdogManager::i18nMsg('watchdog.waitlist_write_failed'") !== false,
        "waitlist.php ne vérifie plus le retour de register()/unregister() — régression du bug corrigé le 12/09/2026 (round 339) : un échec d'écriture silencieux redeviendrait indétectable, le client croirait être inscrit sans que rien n'ait été enregistré"
    );

    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(is_array($translations), 'admin_translations.json illisible — jeu de test invalide');
    neria_assert(isset($translations['watchdog.waitlist_write_failed']), "Clé 'watchdog.waitlist_write_failed' absente de admin_translations.json");
    $expectedLangs = ['fr', 'en', 'de', 'it', 'es', 'pt', 'br', 'ar', 'ja', 'ko', 'zh', 'tw', 'ru', 'tr', 'sv', 'no', 'da', 'nl', 'gb'];
    foreach ($expectedLangs as $lang) {
        neria_assert(!empty($translations['watchdog.waitlist_write_failed'][$lang]), "Traduction 'watchdog.waitlist_write_failed' manquante pour la langue '{$lang}'");
    }

    // ── Vérification comportementale du chemin nominal ─────────────────
    require_once _PS_MODULE_DIR_ . 'neria/src/WaitlistManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idShop     = (int) Context::getContext()->shop->id;
    $idCustomer = neria_test_any_customer_id();
    $module     = neria_test_module();
    $mgr        = new WaitlistManager($module);

    // Produit factice garanti inexistant — id_product n'a pas de contrainte
    // FK sur neria_waitlist (déjà documenté dans waitlist.php), donc
    // register()/unregister() fonctionnent indépendamment de l'existence
    // réelle du produit pour ce test isolé du manager.
    $idProduct = 999999992;

    try {
        $registered = $mgr->register($idCustomer, $idProduct, $idShop);
        neria_assert($registered === true, "WaitlistManager::register() renvoie " . var_export($registered, true) . " sur le chemin nominal — comportement nominal cassé");

        $row = $db->getRow("SELECT * FROM {$prefix}neria_waitlist WHERE id_customer = {$idCustomer} AND id_product = {$idProduct} AND id_shop = {$idShop}");
        neria_assert($row !== false && $row !== null, "L'inscription n'est pas retrouvable en base après register() — comportement nominal cassé");

        $unregistered = $mgr->unregister($idCustomer, $idProduct, $idShop);
        neria_assert($unregistered === true, "WaitlistManager::unregister() renvoie " . var_export($unregistered, true) . " sur le chemin nominal — comportement nominal cassé");

        return [
            'pass'    => true,
            'message' => "waitlist_button.tpl utilise désormais de vrais formulaires POST (le bouton redevient fonctionnel), et waitlist.php vérifie bien le retour de register()/unregister() — bug corrigé le 12/09/2026 (round 339)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_waitlist WHERE id_customer = {$idCustomer} AND id_product = {$idProduct} AND id_shop = {$idShop}");
    }
}
