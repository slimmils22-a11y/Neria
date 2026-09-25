<?php
/**
 * Régression (P10, 25/09/2026, constatée sur ps-test en multi-boutique réel) : le lien de désabonnement, l'en-tête
 * List-Unsubscribe, le pixel d'ouverture et les liens de clic d'un e-mail de la boutique 2 pointaient vers le domaine
 * de la boutique AMBIANTE (boutique 1, celle du processus qui envoie). Conséquence grave : le destinataire de la
 * boutique 2 qui cliquait « Se désabonner » était désabonné dans la boutique 1 seulement ; ses préférences de la
 * boutique 2 restaient actives et il continuait à recevoir des e-mails marketing (isAllowed(boutique 2) = true).
 *
 * Corrigé : Neria::getUnsubscribeUrl() accepte la boutique ; EmailRenderer (pied de page, secours, pixel, clics) et
 * l'en-tête List-Unsubscribe utilisent la boutique réelle de l'envoi ($params['idShop'] fourni par Mail::Send()).
 *
 * Test : comportement de getUnsubscribeUrl() (mêmes URL avec/sans boutique explicite en mono-boutique, boutique
 * explicite transmise à getModuleLink) + ancrages source des appelants. Preuve comportementale multi-boutique réelle
 * faite sur ps-test (liens /shop2/ dans le mail de la boutique 2).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;
    $email = 'regtest865@example.com';

    $rm = new ReflectionMethod($module, 'getUnsubscribeUrl');
    neria_assert($rm->getNumberOfParameters() === 3 && $rm->getParameters()[2]->getName() === 'idShop', 'getUnsubscribeUrl() n\'accepte plus la boutique en 3e argument');

    $default = $module->getUnsubscribeUrl($email, 'fr');
    $explicit = $module->getUnsubscribeUrl($email, 'fr', $idShop);
    neria_assert($default !== '' && $default === $explicit, "URL différente avec la boutique explicite : {$default} / {$explicit}");

    $root = _PS_MODULE_DIR_ . 'neria/';
    $read = static function (string $f) use ($root): string {
        return str_replace("\r\n", "\n", (string) file_get_contents($root . $f));
    };
    $er = $read('src/EmailRenderer.php');
    $main = $read('neria.php');

    neria_assert(str_contains($er, "getUnsubscribeUrl((string) \$unsubTo, \$lang, \$this->resolveShopId(\$params))"), 'Le lien de pied de page ne reçoit plus la boutique de l\'envoi');
    neria_assert(str_contains($er, "getUnsubscribeUrl(\$to, \$lang, \$idShopFallback)"), 'Le lien de l\'e-mail de secours ne reçoit plus la boutique de l\'envoi');
    neria_assert(str_contains($er, "\$trackIdLang,\n            \$this->resolveShopId(\$params)"), 'Le pixel d\'ouverture n\'est plus construit pour la boutique de l\'envoi');
    neria_assert(str_contains($er, "\$wrapIdLang,\n                            \$wrapIdShop"), 'Les liens de clic ne sont plus construits pour la boutique de l\'envoi');
    neria_assert(str_contains($er, "\$this->resolveShopId(\$params));\n            }\n\n            if (isset(\$params['templatePath']))"), 'wrapLinksInFile() ne reçoit plus la boutique de l\'envoi');
    neria_assert(str_contains($main, 'self::$currentSendShopId = (int) ($params[\'idShop\'] ?? 0);'), 'hookActionEmailSendBefore ne mémorise plus la boutique de l\'envoi');
    neria_assert(str_contains($main, "\$this->getUnsubscribeUrl(\$email, '', \$idShopMsg)"), 'L\'en-tête List-Unsubscribe n\'utilise plus la boutique de l\'envoi');

    return [
        'pass'    => true,
        'message' => "Lien de désabonnement, List-Unsubscribe, pixel et liens de clic construits pour la boutique réelle de l'envoi — un client de la boutique 2 se désabonne bien dans la boutique 2 (corrigé le 25/09/2026, P10)",
    ];
}
