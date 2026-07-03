<?php
/**
 * NERIA — Page d'urgence Watchdog
 *
 * Accessible SANS PrestaShop, directement via URL + token secret.
 * Utile quand une erreur 500 empêche d'accéder au back-office.
 *
 * URL : https://votre-boutique.com/modules/neria/neria-emergency.php?token=VOTRE_TOKEN
 *
 * Le token est affiché dans Neria → Aide → section Diagnostic.
 */

// ── Sécurité de base ─────────────────────────────────────────────
define('NERIA_EMERGENCY_VERSION', '1.0');
$startTime = microtime(true);

// Désactiver l'affichage des erreurs PHP (sécurité)
@ini_set('display_errors', '0');
@error_reporting(0);

// ── Connexion DB via parameters.php de PS ────────────────────────
$psRoot = realpath(__DIR__ . '/../../');
$paramsFile = $psRoot . '/app/config/parameters.php';

// Avant validation du token : aucun détail technique (chemin serveur, message
// d'exception PDO) n'est renvoyé à l'appelant — cette page est accessible sans
// authentification PrestaShop, un visiteur non autorisé ne doit rien apprendre
// sur l'infrastructure. Les détails complets vont uniquement au log PHP serveur.
if (!file_exists($paramsFile)) {
    error_log('[Neria emergency] Fichier de configuration introuvable : ' . $paramsFile);
    emergencyDie('Configuration PrestaShop introuvable. Consultez les logs serveur pour le détail.');
}

$params = require $paramsFile;
$p      = $params['parameters'] ?? [];

$dbHost   = $p['database_host']     ?? 'localhost';
$dbPort   = $p['database_port']     ?: '3306';
$dbName   = $p['database_name']     ?? '';
$dbUser   = $p['database_user']     ?? '';
$dbPass   = $p['database_password'] ?? '';
$prefix   = $p['database_prefix']   ?? 'ps_';

try {
    $dsn = 'mysql:host=' . $dbHost . ';port=' . $dbPort
         . ';dbname=' . $dbName . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
    ]);
} catch (Exception $e) {
    error_log('[Neria emergency] Connexion DB échouée : ' . $e->getMessage());
    emergencyDie('Connexion à la base de données impossible. Consultez les logs serveur pour le détail.');
}

// ── Validation du token ───────────────────────────────────────────
$token = trim((string) ($_GET['token'] ?? ''));

if ($token === '') {
    emergencyDie('Token manquant. Ajoutez <code>?token=VOTRE_TOKEN</code> à l\'URL.'
        . '<br><small>Le token est visible dans Neria → Onglet Aide → section Diagnostic.</small>');
}

try {
    $stmt = $pdo->prepare(
        "SELECT `value` FROM `{$prefix}configuration`
         WHERE `name` = 'NERIA_EMERGENCY_TOKEN' LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch();
    $validToken = $row ? (string) $row['value'] : '';
} catch (Exception $e) {
    error_log('[Neria emergency] Lecture token échouée : ' . $e->getMessage());
    emergencyDie('Erreur de lecture en base. Consultez les logs serveur pour le détail.');
}

if ($validToken === '' || !hash_equals($validToken, $token)) {
    // Simuler un délai pour ralentir le bruteforce
    sleep(2);
    emergencyDie('Token invalide. Accès refusé.'
        . '<br><small>Le token correct est affiché dans Neria → Onglet Aide.</small>');
}

// ── Lecture des données ───────────────────────────────────────────

// Derniers 100 logs watchdog
try {
    $stmt = $pdo->prepare(
        "SELECT `level`, `template`, `class`, `message`, `date_add`
         FROM `{$prefix}neria_log`
         ORDER BY `date_add` DESC
         LIMIT 100"
    );
    $stmt->execute();
    $logs = $stmt->fetchAll();
} catch (Exception $e) {
    $logs = [];
    $logsError = $e->getMessage();
}

// Derniers résultats de santé
try {
    $stmt = $pdo->prepare(
        "SELECT `value` FROM `{$prefix}configuration`
         WHERE `name` = 'NERIA_HEALTH_RESULTS' LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch();
    $healthResults = $row ? json_decode((string) $row['value'], true) : [];
} catch (Exception $e) {
    $healthResults = [];
}

// Dernière exécution du diagnostic
try {
    $stmt = $pdo->prepare(
        "SELECT `value` FROM `{$prefix}configuration`
         WHERE `name` = 'NERIA_HEALTH_LAST_RUN' LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch();
    $healthLastRun = $row ? (string) $row['value'] : '';
} catch (Exception $e) {
    $healthLastRun = '';
}

// Compteur d'échecs consécutifs
try {
    $stmt = $pdo->prepare(
        "SELECT `value` FROM `{$prefix}configuration`
         WHERE `name` = 'NERIA_CONSECUTIVE_FAILURES' LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch();
    $consecutiveFailures = $row ? (int) $row['value'] : 0;
} catch (Exception $e) {
    $consecutiveFailures = 0;
}

// Nombre de bounces actifs
try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS n FROM `{$prefix}neria_bounces` WHERE `status` = 'active'"
    );
    $stmt->execute();
    $row = $stmt->fetch();
    $activeBounces = $row ? (int) $row['n'] : 0;
} catch (Exception $e) {
    $activeBounces = 0;
}

// Comptes par niveau de log
$logCounts = ['info' => 0, 'warning' => 0, 'error' => 0, 'critical' => 0];
foreach ($logs as $l) {
    $lv = $l['level'] ?? 'info';
    if (isset($logCounts[$lv])) {
        $logCounts[$lv]++;
    }
}

$elapsed = round((microtime(true) - $startTime) * 1000);

// ── Rendu HTML ────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, no-cache');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Neria — Journal d'urgence</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; font-size: 13px; background: #f5f5f5; color: #333; }
.header { background: #1a1a2e; color: #fff; padding: 16px 24px; display: flex; align-items: center; gap: 16px; }
.header__logo { font-size: 20px; color: #b38b59; }
.header__title { font-size: 18px; font-weight: 600; }
.header__sub { font-size: 12px; color: #aaa; margin-top: 2px; }
.header__badge { margin-left: auto; background: #b38b59; color: #fff; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; }
.container { max-width: 1200px; margin: 0 auto; padding: 20px; }
.alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-size: 13px; }
.alert--warn { background: #fff8e6; border: 1px solid #e6a817; color: #7a5500; }
.alert--ok { background: #f0faf0; border: 1px solid #4caf50; color: #1b5e20; }
.section { background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; margin-bottom: 20px; overflow: hidden; }
.section__head { padding: 14px 20px; border-bottom: 1px solid #eee; display: flex; align-items: center; gap: 10px; }
.section__title { font-size: 14px; font-weight: 600; color: #1a1a2e; }
.section__body { padding: 20px; }
.kpi-row { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 0; }
.kpi { flex: 1; min-width: 120px; background: #f9f9f9; border: 1px solid #eee; border-radius: 6px; padding: 14px 16px; text-align: center; }
.kpi__val { font-size: 28px; font-weight: 700; line-height: 1; }
.kpi__lbl { font-size: 11px; color: #888; margin-top: 4px; }
.kpi--info .kpi__val { color: #1976d2; }
.kpi--warn .kpi__val { color: #ba7517; }
.kpi--err .kpi__val { color: #a32d2d; }
.kpi--crit .kpi__val { color: #7a0000; }
.kpi--bounce .kpi__val { color: #b38b59; }
.kpi--fail .kpi__val { color: <?= $consecutiveFailures >= 3 ? '#a32d2d' : ($consecutiveFailures > 0 ? '#ba7517' : '#4caf50') ?>; }
.health-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
.hcard { border-radius: 6px; padding: 12px 14px; border: 1px solid; }
.hcard--ok { background: #f0faf0; border-color: #c8e6c9; }
.hcard--warning { background: #fff8e6; border-color: #ffe082; }
.hcard--error { background: #fff0f0; border-color: #ffcdd2; }
.hcard__key { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 6px; color: #555; }
.hcard__detail { font-size: 12px; color: #444; line-height: 1.5; }
.hcard__action { display: block; margin-top: 6px; color: #ba7517; font-weight: 600; font-size: 12px; }
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 12px; }
th { background: #f5f5f5; padding: 8px 10px; text-align: left; font-weight: 600; color: #555; border-bottom: 2px solid #e0e0e0; white-space: nowrap; }
td { padding: 7px 10px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
tr:hover td { background: #fafafa; }
.badge { display: inline-block; padding: 2px 7px; border-radius: 3px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
.badge--info { background: #e3f2fd; color: #1565c0; }
.badge--warning { background: #fff8e6; color: #ba7517; border: 1px solid #ffe082; }
.badge--error { background: #ffebee; color: #a32d2d; border: 1px solid #ffcdd2; }
.badge--critical { background: #7a0000; color: #fff; }
.msg { max-width: 600px; word-break: break-word; line-height: 1.5; }
.msg-action { display: block; color: #ba7517; font-weight: 600; margin-top: 3px; }
.footer { text-align: center; color: #aaa; font-size: 11px; padding: 20px; }
.filter-row { display: flex; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
.filter-row select, .filter-row input { padding: 6px 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 12px; }
.no-data { color: #aaa; text-align: center; padding: 30px; }
</style>
</head>
<body>

<div class="header">
  <span class="header__logo">✦</span>
  <div>
    <div class="header__title">Neria — Journal d'urgence</div>
    <div class="header__sub">Accès direct DB · Sans PrestaShop · <?= htmlspecialchars($dbName) ?> · Généré en <?= $elapsed ?>ms</div>
  </div>
  <span class="header__badge">MODE URGENCE</span>
</div>

<div class="container">

  <div class="alert alert--warn">
    ⚠ Cette page est accessible sans PrestaShop. Elle est protégée par un token secret.
    Ne partagez pas l'URL complète. Pour révoquer l'accès, régénérez le token dans Neria → Aide → Diagnostic.
  </div>

  <?php if ($consecutiveFailures >= 3): ?>
  <div class="alert alert--warn">
    🔴 <strong><?= $consecutiveFailures ?> échecs de rendu consécutifs détectés.</strong>
    Neria utilise l'email de secours pour chaque envoi. Consultez le journal ci-dessous pour identifier la cause.
  </div>
  <?php endif; ?>

  <!-- KPIs -->
  <div class="section">
    <div class="section__head">
      <span class="section__title">Vue d'ensemble</span>
    </div>
    <div class="section__body">
      <div class="kpi-row">
        <div class="kpi kpi--info">
          <div class="kpi__val"><?= $logCounts['info'] ?></div>
          <div class="kpi__lbl">INFO</div>
        </div>
        <div class="kpi kpi--warn">
          <div class="kpi__val"><?= $logCounts['warning'] ?></div>
          <div class="kpi__lbl">WARNING</div>
        </div>
        <div class="kpi kpi--err">
          <div class="kpi__val"><?= $logCounts['error'] ?></div>
          <div class="kpi__lbl">ERROR</div>
        </div>
        <div class="kpi kpi--crit">
          <div class="kpi__val"><?= $logCounts['critical'] ?></div>
          <div class="kpi__lbl">CRITICAL</div>
        </div>
        <div class="kpi kpi--fail">
          <div class="kpi__val"><?= $consecutiveFailures ?></div>
          <div class="kpi__lbl">Échecs consécutifs</div>
        </div>
        <div class="kpi kpi--bounce">
          <div class="kpi__val"><?= $activeBounces ?></div>
          <div class="kpi__lbl">Bounces actifs</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Contrôles de santé -->
  <?php if (!empty($healthResults)): ?>
  <div class="section">
    <div class="section__head">
      <span class="section__title">Derniers contrôles de santé</span>
      <?php if ($healthLastRun): ?>
        <span style="font-size:11px;color:#aaa;margin-left:auto;">Dernier diagnostic : <?= htmlspecialchars($healthLastRun) ?></span>
      <?php endif; ?>
    </div>
    <div class="section__body">
      <div class="health-grid">
        <?php foreach ($healthResults as $key => $result):
          $status = $result['status'] ?? 'ok';
          $detail = $result['detail'] ?? '';
          $parts  = explode('→ Que faire :', $detail, 2);
          $fact   = trim($parts[0]);
          $action = isset($parts[1]) ? trim($parts[1]) : '';
        ?>
        <div class="hcard hcard--<?= htmlspecialchars($status) ?>">
          <div class="hcard__key"><?= htmlspecialchars(str_replace('_', ' ', $key)) ?></div>
          <div class="hcard__detail">
            <?= htmlspecialchars($fact) ?>
            <?php if ($action): ?>
              <span class="hcard__action">→ Que faire : <?= htmlspecialchars($action) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Journal -->
  <div class="section">
    <div class="section__head">
      <span class="section__title">Journal des événements (100 derniers)</span>
    </div>
    <div class="section__body">

      <div class="filter-row">
        <select id="fLevel" onchange="filterLogs()">
          <option value="">Tous les niveaux</option>
          <option value="info">INFO</option>
          <option value="warning">WARNING</option>
          <option value="error">ERROR</option>
          <option value="critical">CRITICAL</option>
        </select>
        <input type="text" id="fText" placeholder="Filtrer par message ou classe…" oninput="filterLogs()" style="min-width:240px;">
      </div>

      <?php if (empty($logs)): ?>
        <div class="no-data">Aucun log trouvé<?= isset($logsError) ? ' — ' . htmlspecialchars($logsError) : '' ?>.</div>
      <?php else: ?>
      <div class="table-wrap">
        <table id="logTable">
          <thead>
            <tr>
              <th>Date</th>
              <th>Niveau</th>
              <th>Classe</th>
              <th>Template</th>
              <th>Message</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($logs as $log):
            $lv      = $log['level'] ?? 'info';
            $msg     = $log['message'] ?? '';
            $parts   = explode('→ Que faire :', $msg, 2);
            $msgMain = trim($parts[0]);
            $msgAct  = isset($parts[1]) ? trim($parts[1]) : '';
          ?>
            <tr data-level="<?= htmlspecialchars($lv) ?>"
                data-text="<?= htmlspecialchars(strtolower($msg . ' ' . ($log['class'] ?? ''))) ?>">
              <td style="white-space:nowrap;"><?= htmlspecialchars(substr($log['date_add'] ?? '', 0, 16)) ?></td>
              <td><span class="badge badge--<?= htmlspecialchars($lv) ?>"><?= htmlspecialchars(strtoupper($lv)) ?></span></td>
              <td style="white-space:nowrap;"><?= htmlspecialchars($log['class'] ?? '—') ?></td>
              <td style="white-space:nowrap;"><?= htmlspecialchars($log['template'] ?? '—') ?></td>
              <td class="msg">
                <?= htmlspecialchars($msgMain) ?>
                <?php if ($msgAct): ?>
                  <span class="msg-action">→ Que faire : <?= htmlspecialchars($msgAct) ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

    </div>
  </div>

</div>

<div class="footer">
  Neria Emergency Watchdog v<?= NERIA_EMERGENCY_VERSION ?> —
  Connexion directe DB · PS non chargé ·
  <a href="?token=<?= htmlspecialchars(urlencode($token)) ?>" style="color:#b38b59;">Rafraîchir</a>
</div>

<script>
function filterLogs() {
  var level = document.getElementById('fLevel').value.toLowerCase();
  var text  = document.getElementById('fText').value.toLowerCase();
  document.querySelectorAll('#logTable tbody tr').forEach(function(tr) {
    var lvMatch  = !level || tr.dataset.level === level;
    var txtMatch = !text  || tr.dataset.text.indexOf(text) !== -1;
    tr.style.display = (lvMatch && txtMatch) ? '' : 'none';
  });
}
</script>

</body>
</html>
<?php

// ── Fonctions utilitaires ─────────────────────────────────────────

function emergencyDie(string $html): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">
    <title>Neria — Accès refusé</title>
    <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;
    min-height:100vh;background:#f5f5f5;margin:0;}
    .box{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:40px;
    max-width:520px;text-align:center;}
    .logo{font-size:32px;color:#b38b59;margin-bottom:16px;}
    h1{font-size:18px;color:#1a1a2e;margin-bottom:12px;}
    p{font-size:13px;color:#666;line-height:1.6;}</style>
    </head><body><div class="box">
    <div class="logo">✦</div>
    <h1>Neria Emergency Watchdog</h1>
    <p>' . $html . '</p>
    </div></body></html>';
    exit;
}
