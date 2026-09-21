<?php
/**
 * P8b (21/09/2026) : l'action BO save_seasonal_campaign créait une campagne saisonnière même sans nom ni modèle d'e-mail
 * (POST vide : « Campagne créée »), seul l'attribut HTML `required` du formulaire protégeait. Test comportemental : lance la
 * vraie action via bo_action_worker.php (transaction annulée) — refusée avec un message d'erreur et aucune ligne créée sans nom/modèle,
 * acceptée avec un nom et un modèle valide.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $worker = strtr(_PS_MODULE_DIR_ . 'neria/tests/functional/bo_action_worker.php', chr(92), '/');
    neria_assert(is_file($worker), 'bo_action_worker.php introuvable');
    $run = static function (array $params) use ($worker): array {
        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' save_seasonal_campaign --params64=' . base64_encode(json_encode($params)) . ' 2>&1');
        neria_assert((bool) preg_match('/@@RESULT@@(.*)$/m', $out, $m), 'aucun résultat du worker : ' . substr($out, -200));
        $r = json_decode($m[1], true);
        neria_assert(is_array($r), 'résultat illisible');
        return $r;
    };
    neria_test_module(); // charge l'autoload du module
    // un modèle proposé par le formulaire (ManualSendManager::getSendableTemplates), pas un e-mail transactionnel
    $sendable = (new ManualSendManager(neria_test_module()))->getSendableTemplates();
    neria_assert($sendable !== [], 'aucun modèle envoyable');
    $tpl = (string) array_key_first($sendable);
    $none = $run([]);
    neria_assert(!empty($none['neria_error']) && empty($none['neria_success']), 'POST vide accepté : ' . json_encode($none['neria_success'] ?? $none));
    neria_assert(empty($none['table_deltas']['neria_seasonal_campaign']), 'une campagne vide a été créée');
    $noName = $run(['seasonal_name' => '', 'seasonal_template' => $tpl, 'seasonal_annual_date' => '12-25']);
    neria_assert(!empty($noName['neria_error']) && empty($noName['table_deltas']['neria_seasonal_campaign']), 'campagne sans nom acceptée');
    $badTpl = $run(['seasonal_name' => 'Noël', 'seasonal_template' => 'modele_inexistant', 'seasonal_annual_date' => '12-25']);
    neria_assert(!empty($badTpl['neria_error']) && empty($badTpl['table_deltas']['neria_seasonal_campaign']), 'campagne avec modèle inexistant acceptée');
    $ok = $run(['seasonal_name' => 'Noël', 'seasonal_template' => $tpl, 'seasonal_annual_date' => '12-25']);
    neria_assert(!empty($ok['neria_success']) && ($ok['table_deltas']['neria_seasonal_campaign'] ?? 0) === 1, 'campagne valide refusée : ' . json_encode($ok['neria_error'] ?? $ok));
    return ['pass' => true, 'message' => 'save_seasonal_campaign refuse une campagne sans nom ou sans modèle d\'e-mail valide (POST vide, nom vide, modèle inexistant) et accepte une campagne valide'];
}
