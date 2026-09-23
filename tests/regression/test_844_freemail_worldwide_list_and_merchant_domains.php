<?php
/**
 * Round 370 (suite du round 369) : le module est vendu dans le monde entier — la liste des
 * domaines de messagerie gratuite / fournisseurs d'accès doit couvrir toutes les régions, et le
 * marchand doit pouvoir la compléter (réglage NERIA_FREEMAIL_EXTRA_DOMAINS, onglet Statistiques,
 * action save_freemail_domains). Sans cela, un expéditeur chez un fournisseur régional absent de la
 * liste retombait sur l'audit trompeur « score D » du domaine du fournisseur.
 *
 * Test : (1) échantillon représentatif de chaque région reconnu, faux positifs écartés ;
 * (2) normalisation de la saisie marchand (virgules, lignes, @, URL, invalides) ; (3) l'action BO
 * réelle (worker) répond avec le message exact « n enregistré(s), n ignoré(s) » et modifie bien le
 * réglage ; (4) un domaine ajouté par le marchand est reconnu, et runFullCheck() renvoie alors un
 * rapport freemail sans audit DNS.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    neria_test_module();
    $regions = [
        'Europe' => ['seznam.cz', 'wp.pl', 'bluewin.ch', 'libero.it', 'web.de', 'orange.fr', 'telia.com', 'mail.ru', 'ukr.net', 'gmx.ch', 'outlook.de'],
        'Asie' => ['qq.com', '163.com', 'naver.com', 'daum.net', 'docomo.ne.jp', 'yahoo.co.jp', 'rediffmail.com', 'singnet.com.sg'],
        'Amériques' => ['uol.com.br', 'terra.com.br', 'yahoo.com.br', 'hotmail.com.mx', 'comcast.net', 'rogers.com', 'prodigy.net.mx'],
        'Océanie/Afrique/Moyen-Orient' => ['bigpond.com', 'xtra.co.nz', 'mweb.co.za', 'walla.co.il', 'mynet.com', 'hotmail.com.tr'],
        'Mondial' => ['gmail.com', 'icloud.com', 'proton.me', 'zoho.com', 'zoho.eu', 'GMAIL.COM'],
    ];
    foreach ($regions as $region => $domains) {
        foreach ($domains as $d) {
            neria_assert(DomainReputationManager::isFreemailDomain($d, 1), "{$d} ({$region}) devrait être reconnu comme messagerie gratuite / fournisseur d'accès");
        }
    }
    foreach (['example.com', 'ma-boutique.fr', 'gmail.maboutique.com', 'yahoo.mycompany.com', 'boutique-du-web.de', 'monsite.co.jp', ''] as $d) {
        neria_assert(!DomainReputationManager::isFreemailDomain($d, 1), "« {$d} » ne doit PAS être reconnu comme messagerie gratuite");
    }

    $p = DomainReputationManager::parseFreemailDomains("Mail.Regional-XYZ.example, @autre-fournisseur.test\nhttps://troisieme.test/page?x=1\n  pas un domaine ;  ..mauvais  mail.regional-xyz.example  ");
    neria_assert(
        $p['valid'] === ['mail.regional-xyz.example', 'autre-fournisseur.test', 'troisieme.test'],
        'parseFreemailDomains() : domaines valides inattendus ' . json_encode($p['valid'])
    );
    neria_assert(count($p['invalid']) === 4, 'parseFreemailDomains() : 4 saisies invalides attendues (pas, un, domaine, ..mauvais), obtenu ' . json_encode($p['invalid']));

    $worker = str_replace('\\', '/', _PS_MODULE_DIR_ . 'neria/tests/functional/bo_action_worker.php');
    $params = ['freemail_extra_domains' => "a-regional.example\nb-regional.example\nc-regional.example\nnon valide !\n..x"];
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' save_freemail_domains --params64=' . base64_encode(json_encode($params)) . ' 2>&1');
    neria_assert((bool) preg_match('/@@RESULT@@(.*)$/m', $out, $m), 'aucun résultat du worker : ' . substr($out, -200));
    $r = json_decode($m[1], true);
    neria_assert(
        ($r['neria_success'] ?? '') === '3 domaine(s) enregistré(s), 4 ignoré(s) (format invalide).',
        "Message exact attendu « 3 domaine(s) enregistré(s), 4 ignoré(s) (format invalide). », obtenu : " . json_encode($r['neria_success'] ?? ($r['neria_error'] ?? $r))
    );
    neria_assert(in_array('NERIA_FREEMAIL_EXTRA_DOMAINS', $r['config_changes'] ?? [], true), "save_freemail_domains n'a pas modifié NERIA_FREEMAIL_EXTRA_DOMAINS : " . json_encode($r['config_changes'] ?? null));

    $db = neria_test_db();
    $idShop = (int) Context::getContext()->shop->id;
    $orig = Configuration::get('NERIA_FREEMAIL_EXTRA_DOMAINS', null, null, $idShop);
    $origEmail = Configuration::get('PS_SHOP_EMAIL', null, null, $idShop);
    $origSenders = Configuration::get('NERIA_SENDERS_JSON', null, null, $idShop);
    $origCache = Configuration::get(DomainReputationManager::CONFIG_CACHE, null, null, $idShop);
    try {
        neria_assert(!DomainReputationManager::isFreemailDomain('mail.regional-xyz.example', $idShop), 'précondition : domaine non reconnu avant ajout');
        Configuration::updateValue('NERIA_FREEMAIL_EXTRA_DOMAINS', implode("\n", $p['valid']), false, null, $idShop);
        neria_assert(DomainReputationManager::isFreemailDomain('mail.regional-xyz.example', $idShop), 'le domaine ajouté par le marchand doit être reconnu');
        Configuration::updateValue('PS_SHOP_EMAIL', 'boutique@mail.regional-xyz.example', false, null, $idShop);
        Configuration::updateValue('NERIA_SENDERS_JSON', '', false, null, $idShop);
        $t0 = microtime(true);
        $report = (new DomainReputationManager(neria_test_module()))->runFullCheck();
        neria_assert(!empty($report['freemail']) && (microtime(true) - $t0) < 3.0, 'runFullCheck() doit renvoyer un rapport freemail sans audit DNS pour un domaine ajouté par le marchand');
    } finally {
        foreach ([['NERIA_FREEMAIL_EXTRA_DOMAINS', $orig], ['PS_SHOP_EMAIL', $origEmail], ['NERIA_SENDERS_JSON', $origSenders], [DomainReputationManager::CONFIG_CACHE, $origCache]] as [$k, $v]) {
            if ($v === false || $v === null) {
                Configuration::deleteByName($k);
            } else {
                Configuration::updateValue($k, $v, false, null, $idShop);
            }
        }
    }

    return [
        'pass'    => true,
        'message' => "Liste mondiale de messagerie gratuite (Europe, Asie, Amériques, Océanie/Afrique/Moyen-Orient) reconnue sans faux positif, saisie marchand normalisée, action BO save_freemail_domains fonctionnelle (message exact) et domaine ajouté écarté de l'audit — round 370",
    ];
}
