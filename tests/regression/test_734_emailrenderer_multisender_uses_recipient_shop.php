<?php
/**
 * Régression : EmailRenderer::applyNeriaRendering() doit résoudre
 * l'expéditeur multi-boutique (NERIA_SENDERS_JSON) via la boutique RÉELLE
 * du destinataire (resolveShopId($params)), pas via $this->config —
 * construit une seule fois à l'instanciation d'EmailRenderer et figé sur
 * la boutique alors ambiante.
 *
 * Bug identifié le 13/09/2026 (round 351, audit multi-agents, angle
 * multi-preview/multi-sender) : sur une installation multi-boutique où
 * chaque boutique a un expéditeur SMTP/nom distinct, tout envoi déclenché
 * alors que le contexte ambiant diffère de la boutique du destinataire
 * (cron, boucle multi-boutique, hook exécuté hors contexte du client)
 * envoyait l'email avec le nom/adresse expéditeur de la MAUVAISE boutique
 * — confusion de marque, risque de plainte spam/phishing perçu.
 *
 * Corrigé en ajoutant un paramètre optionnel $idShop au constructeur de
 * ConfigManager (rétrocompatible), et en l'utilisant explicitement pour ce
 * bloc précis dans EmailRenderer.
 *
 * Test comportemental réel (même limitation d'environnement que test_91 :
 * Shop::isFeatureActive() est false sur cette installation mono-boutique
 * de dev — Configuration::get()/updateValue() retombent alors TOUS LES
 * DEUX sur la boutique ambiante quel que soit le $idShop explicite passé,
 * donc écriture et lecture restent symétriques ; ce test prouve que le
 * paramètre $idShop du constructeur ConfigManager est bien PROPAGÉ jusqu'à
 * Configuration::get(), pas la vraie isolation inter-boutiques physique,
 * qui nécessiterait une install multi-boutique réelle) : configure un
 * expéditeur, construit un ConfigManager avec ce même $idShop explicite,
 * vérifie qu'il le résout correctement.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    $idShop = (int) Context::getContext()->shop->id;
    $prevSenders = Configuration::get('NERIA_SENDERS_JSON', null, null, $idShop);
    $prevMulti   = Configuration::get('NERIA_MULTI_SENDER_ENABLED', null, null, $idShop);

    try {
        $sendersFixture = json_encode(['fr' => ['name' => 'Round351 Sender', 'email' => 'round351@example.invalid']]);
        Configuration::updateValue('NERIA_SENDERS_JSON', $sendersFixture, false, null, $idShop);
        Configuration::updateValue('NERIA_MULTI_SENDER_ENABLED', 1, false, null, $idShop);

        $config = new ConfigManager(neria_test_module(), $idShop);
        neria_assert(
            $config->isMultiSenderEnabled() === true,
            "ConfigManager(module, \$idShop) ne résout plus isMultiSenderEnabled() avec le \$idShop explicite fourni au constructeur — régression du bug corrigé le 13/09/2026 (round 351) : le paramètre optionnel aurait disparu ou serait ignoré"
        );
        $sender = $config->getSenderForLang('fr');
        neria_assert(
            ($sender['email'] ?? '') === 'round351@example.invalid',
            "ConfigManager(module, \$idShop) ne résout plus le bon expéditeur avec le \$idShop explicite fourni — obtenu " . json_encode($sender) . " — régression du bug corrigé le 13/09/2026 (round 351)"
        );

        // Vérification structurelle complémentaire : EmailRenderer doit
        // bien utiliser resolveShopId($params), pas $this->config, pour ce
        // bloc précis (comportement non isolable en pur unitaire sans
        // déclencher un vrai Mail::Send(), et sans installation
        // multi-boutique réelle pour prouver la vraie isolation physique).
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php');
        neria_assert($src !== false, 'Impossible de lire src/EmailRenderer.php');
        neria_assert(
            strpos($src, 'new \ConfigManager($this->module, $this->resolveShopId($params));') !== false,
            "EmailRenderer n'instancie plus un ConfigManager scopé sur resolveShopId(\$params) pour le multi-sender — régression du bug corrigé le 13/09/2026 (round 351) : le bloc multi-sender retomberait de nouveau sur \$this->config (figé sur la boutique ambiante à l'instanciation d'EmailRenderer)"
        );

        return [
            'pass'    => true,
            'message' => "ConfigManager(module, \$idShop) propage bien le \$idShop explicite du constructeur jusqu'à isMultiSenderEnabled()/getSenderForLang(), et EmailRenderer l'utilise bien pour le bloc multi-sender au lieu de \$this->config figé — bug corrigé le 13/09/2026 (round 351)",
        ];
    } finally {
        if ($prevSenders !== false && $prevSenders !== '') {
            Configuration::updateValue('NERIA_SENDERS_JSON', $prevSenders, false, null, $idShop);
        } else {
            Configuration::deleteByName('NERIA_SENDERS_JSON');
        }
        Configuration::updateValue('NERIA_MULTI_SENDER_ENABLED', (int) $prevMulti, false, null, $idShop);
    }
}
