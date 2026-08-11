<?php
/**
 * Régression : controllers/front/preferences.php doit construire son lien
 * de désabonnement via getUnsubscribeUrl() (donc via getModuleLink()), pas
 * en concaténant '/module/neria/unsubscribe?...' en dur.
 *
 * Bug réel corrigé le 09/08/2026 (round 148) : le lien était construit en
 * dur ('/module/neria/unsubscribe?email=...&token=...&lang=...'), cassé
 * (404) sur toute boutique où l'URL rewriting est désactivé
 * (PS_REWRITING_SETTINGS=0, comportement par défaut d'une installation
 * PrestaShop fraîche) — même pattern déjà corrigé sur waitlist.php aux
 * rounds 54/67, jamais répliqué ici. Bug annexe découvert au passage : le
 * code en dur passait le paramètre 'lang', alors que le contrôleur
 * unsubscribe.php lit en réalité 'neria_lang' (Tools::getValue('neria_lang'))
 * — le lien envoyait donc aussi la MAUVAISE langue.
 *
 * Test comportemental réel : appelle Neria::getUnsubscribeUrl() (la
 * méthode maintenant utilisée par preferences.php) et vérifie que l'URL
 * générée contient bien 'controller=unsubscribe' (ou le chemin réécrit
 * équivalent) et le paramètre 'neria_lang' — pas 'module/neria/unsubscribe'
 * en dur ni le paramètre 'lang' à la place de 'neria_lang'. Vérifie aussi
 * structurellement que le contrôleur ne construit plus l'URL à la main.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $module = neria_test_module();
    $customerRow = neria_test_db()->getRow(
        "SELECT email FROM " . neria_test_prefix() . "customer WHERE id_customer = " . neria_test_any_customer_id()
    );
    neria_assert($customerRow !== false, 'Client de test introuvable — jeu de test invalide');
    $email = $customerRow['email'];

    $url = $module->getUnsubscribeUrl($email, 'fr');
    neria_assert($url !== '', "getUnsubscribeUrl() a renvoye une URL vide pour un email valide — jeu de test invalide");

    // getModuleLink() peut légitimement produire un chemin qui RESSEMBLE au
    // format en dur ('/module/neria/unsubscribe?...') selon la config de
    // rewriting — la distinction réelle porte sur le paramètre de langue :
    // l'ancien code en dur passait 'lang=' (jamais lu par unsubscribe.php,
    // qui attend 'neria_lang='), getUnsubscribeUrl() passe le bon nom.
    neria_assert(
        strpos($url, '/unsubscribe') !== false,
        "l'URL generee par getUnsubscribeUrl() ne pointe pas vers le controleur unsubscribe : {$url}"
    );
    neria_assert(
        strpos($url, 'neria_lang=fr') !== false,
        "l'URL generee ne transporte pas le parametre 'neria_lang' (celui reellement lu par unsubscribe.php) — regression du bug annexe corrige le 09/08/2026 (round 148) : {$url}"
    );
    neria_assert(
        preg_match('/[?&]lang=/', $url) === 0,
        "l'URL generee transporte encore le mauvais parametre 'lang' (jamais lu par unsubscribe.php) — regression du bug corrige le 09/08/2026 (round 148) : {$url}"
    );

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/controllers/front/preferences.php');
    neria_assert($src !== false, 'Impossible de lire controllers/front/preferences.php');
    neria_assert(
        strpos($src, "'/module/neria/unsubscribe'") === false,
        "preferences.php construit de nouveau son lien de desabonnement en dur — regression du bug corrige le 09/08/2026 (round 148) : casse a nouveau sans URL rewriting"
    );
    neria_assert(
        strpos($src, '$this->module->getUnsubscribeUrl(') !== false,
        "preferences.php n'appelle plus getUnsubscribeUrl() — regression du bug corrige le 09/08/2026 (round 148)"
    );

    return [
        'pass'    => true,
        'message' => "controllers/front/preferences.php construit bien son lien de desabonnement via getUnsubscribeUrl()/getModuleLink(), plus en dur",
    ];
}
