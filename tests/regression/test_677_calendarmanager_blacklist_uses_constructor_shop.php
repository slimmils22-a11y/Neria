<?php
/**
 * Régression : `CalendarManager::sendCalendarEmail()` instanciait
 * `BlacklistManager()` SANS `idShop` explicite — son constructeur
 * retombait alors sur `Context::getContext()->shop->id` (contexte
 * AMBIANT au moment de l'appel), pas `$this->idShop` (boutique capturée
 * au constructeur de `CalendarManager`, utilisée par TOUTES ses autres
 * requêtes scopées). Seul appel de `BlacklistManager` dans tout le module
 * qui n'utilisait pas son constructeur explicite — même famille de bug
 * que `EmailRenderer::isExcluded()` (round 321, voir test_632).
 *
 * Bug identifié le 10/09/2026 (round 333, audit CryptoManager/
 * SignatureGenerator, redirigé). Sur le seul chemin d'appel actuel
 * (boucle multi-boutique de neria.php, qui réaffecte le contexte ambiant
 * juste avant chaque `new CalendarManager()`), le contexte ambiant
 * correspond accidentellement à la bonne boutique — non exploitable
 * aujourd'hui, mais latent pour tout futur appelant (bouton "envoi test"
 * BO, appel direct hors de cette boucle précise).
 *
 * Corrigé le 10/09/2026 (round 333) : `$this->idShop` transmis
 * explicitement au constructeur de `BlacklistManager`.
 *
 * Test comportemental réel (même méthode que test_632/round 321) : pose
 * une règle de blacklist sur la boutique B, construit CalendarManager
 * alors que le contexte ambiant pointe sur B (capture $this->idShop=B),
 * bascule ENSUITE le contexte ambiant sur A, puis appelle
 * sendCalendarEmail() via réflexion — doit toujours détecter la règle de
 * B (celle de $this->idShop, pas du contexte ambiant A au moment de
 * l'appel).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/BlacklistManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/CalendarManager.php';

    $shops = Db::getInstance()->executeS('SELECT id_shop FROM `' . _DB_PREFIX_ . 'shop` WHERE active = 1 ORDER BY id_shop ASC');
    neria_assert(is_array($shops) && count($shops) >= 1, 'Aucune boutique active trouvée pour le test');

    $idShopA = (int) $shops[0]['id_shop'];
    $idShopB = count($shops) > 1 ? (int) $shops[1]['id_shop'] : $idShopA;
    $testTemplate = 'neria_test_calendar_round333';

    $originalContext = Shop::getContextShopID(true);

    try {
        $mgrB = new BlacklistManager($idShopB);
        $mgrB->add($testTemplate, '');

        // Construit CalendarManager alors que le contexte ambiant pointe
        // sur B — $this->idShop capture B au constructeur.
        Context::getContext()->shop = new Shop($idShopB);
        $calMgr = new CalendarManager(neria_test_module());

        // Bascule ENSUITE le contexte ambiant sur A, APRÈS la construction
        // — reproduit exactement le scénario latent (tout code exécuté
        // entre la construction et l'appel qui modifierait le contexte
        // ambiant).
        Context::getContext()->shop = new Shop($idShopA);

        $ref = new ReflectionMethod('CalendarManager', 'sendCalendarEmail');
        $ref->setAccessible(true);
        $fakeCustomer = ['id_customer' => 0, 'email' => 'regtest677@example.test', 'firstname' => 'Test', 'lastname' => 'Round333'];

        $sent = $ref->invoke($calMgr, $fakeCustomer, $testTemplate, 'fr');

        neria_assert(
            $sent === false,
            "CalendarManager::sendCalendarEmail() n'a pas détecté la règle de blacklist posée sur \$this->idShop (boutique B) alors que le contexte ambiant a changé (boutique A) — régression du bug corrigé le 10/09/2026 (round 333) : BlacklistManager retomberait de nouveau sur le contexte ambiant au lieu de \$this->idShop"
        );

        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CalendarManager.php');
        neria_assert(
            strpos($src, 'new \BlacklistManager($this->idShop)') !== false,
            "CalendarManager::sendCalendarEmail() n'instancie plus BlacklistManager avec \$this->idShop explicite — régression du bug corrigé le 10/09/2026 (round 333)"
        );

        return [
            'pass'    => true,
            'message' => "CalendarManager::sendCalendarEmail() vérifie bien la blacklist de \$this->idShop (boutique capturée au constructeur), pas du contexte ambiant au moment de l'appel — bug corrigé le 10/09/2026 (round 333)",
        ];
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
}
