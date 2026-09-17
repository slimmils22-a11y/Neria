<?php
/**
 * Arbitrage tranché round bloc D (17/09/2026) : EmailRenderer::
 * injectSocialVars() résolvait les liens réseaux sociaux via $this->config
 * — un ConfigManager construit UNE SEULE FOIS avec le contexte AMBIANT au
 * moment de l'instanciation d'EmailRenderer (`new ConfigManager($module)`,
 * sans idShop explicite) — au lieu de la boutique réelle du destinataire
 * de CET envoi.
 *
 * Même famille de piège déjà corrigée dans ce même fichier pour la
 * signature manuscrite (round 138), le sujet/{greeting_main} (round 357)
 * et le multi-expéditeur (round 351) : NERIA_SOCIAL_INSTAGRAM/FACEBOOK/etc.
 * sont bien des réglages scopés par boutique (ConfigManager::get() passe
 * $this->idShop à Configuration::get(), round 132) — un envoi programmé/
 * cron traitant un email pour la boutique B pouvait donc afficher les
 * liens réseaux sociaux configurés pour la boutique A (contexte ambiant au
 * moment de la construction d'EmailRenderer), pas ceux de B.
 *
 * Neria est vendu mondialement — les installs multi-boutiques (agences,
 * marchands multi-marques/multi-pays) ne sont pas un cas marginal à cette
 * échelle.
 *
 * Corrigé : injectSocialVars() accepte désormais un $idShop optionnel,
 * transmis via resolveShopId($params) dans le chemin d'envoi réel (comme
 * la signature juste en dessous dans ce même fichier) ; le 2e appelant
 * (buildCompiledHtml(), aperçu BO / renvoi historique, sans destinataire
 * réel) reste volontairement ambiant.
 *
 * Test structurel + comportemental partiel : PrestaShop\Configuration::get()
 * ignore silencieusement tout $idShop explicite quand Shop::isFeatureActive()
 * est faux (multi-boutique désactivé, cas de CET environnement de dev) —
 * repli toujours vers Shop::getContextShopID() ambiant. Une vraie
 * différenciation comportementale entre 2 boutiques via ps_configuration
 * n'est donc PAS reproductible fiablement ici, même limitation déjà
 * rencontrée pour ce type de correctif (voir test_117, test_115) : la
 * vérification porte sur la présence structurelle du bon appel
 * resolveShopId() dans le chemin d'envoi réel, complétée par une preuve
 * comportementale que le chemin ambiant (idShop=null, 2e appelant) reste
 * fonctionnel.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    $module = neria_test_module();
    $shopA  = (int) Context::getContext()->shop->id;
    $keyInsta = ConfigManager::KEY_SOCIAL_INSTAGRAM;

    $originalA = Configuration::get($keyInsta, null, null, $shopA);
    $urlA = 'https://instagram.com/round780_ambient_check';
    Configuration::updateValue($keyInsta, $urlA, false, null, $shopA);

    try {
        // ── 1. Comportemental : le chemin ambiant (idShop=null, 2e appelant
        //    buildCompiledHtml()) reste fonctionnel après l'ajout du
        //    paramètre — aucune régression de la signature de méthode.
        $renderer = new EmailRenderer($module);
        $method   = new ReflectionMethod(EmailRenderer::class, 'injectSocialVars');
        $method->setAccessible(true);

        $varsAmbient = [];
        $method->invokeArgs($renderer, [&$varsAmbient, null]);
        neria_assert(
            strpos($varsAmbient['neria_social_links'] ?? '', $urlA) !== false,
            "injectSocialVars(\$vars, null) ne reflète plus le lien réseau social configuré — régression du chemin ambiant (2e appelant, buildCompiledHtml()) introduite par l'ajout du paramètre \$idShop"
        );

        // ── 2. Structurel : le chemin d'ENVOI RÉEL (celui qui a l'id du
        //    destinataire) doit transmettre resolveShopId($params) —
        //    c'est le coeur du correctif, non reproductible en
        //    comportemental sur cet environnement mono-boutique.
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php');
        neria_assert($src !== false, 'Impossible de lire src/EmailRenderer.php');
        neria_assert(
            strpos($src, "\$this->injectSocialVars(\$params['templateVars'], \$this->resolveShopId(\$params));") !== false,
            "EmailRenderer n'appelle plus injectSocialVars() avec resolveShopId(\$params) dans le chemin d'envoi réel — régression de l'arbitrage bloc D (17/09/2026) : les liens réseaux sociaux d'une AUTRE boutique de l'installation pourraient de nouveau s'afficher dans un email traité pour la boutique consultée (contexte ambiant au lieu du destinataire réel)"
        );

        return [
            'pass'    => true,
            'message' => "EmailRenderer::injectSocialVars() transmet bien resolveShopId(\$params) dans le chemin d'envoi réel (chemin ambiant du 2e appelant toujours fonctionnel) — arbitrage tranché round bloc D (17/09/2026)",
        ];
    } finally {
        if ($originalA !== false && $originalA !== '') {
            Configuration::updateValue($keyInsta, $originalA, false, null, $shopA);
        } else {
            Configuration::deleteFromContext($keyInsta, null, $shopA);
        }
    }
}
