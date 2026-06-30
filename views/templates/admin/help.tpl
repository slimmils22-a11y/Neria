{**
 * NERIA — help.tpl
 * Onglet Aide — Documentation, diagnostic et support
 * i18n : libellés via {neria_admin key='...'} (18 langues, AdminTranslator)
 *}

{* ── Watchdog v2 — Score de santé global ────────────────────── *}
{if isset($watchdog_health)}
{assign var="wh" value=$watchdog_health}
<div class="neria-section" id="neria-help-watchdog-score">
  <h2 class="neria-section__title">
    ⚡ Score de santé Watchdog
  </h2>

  {* Score principal *}
  <div style="display:flex;align-items:center;gap:24px;flex-wrap:wrap;margin-bottom:24px;">
    <div style="text-align:center;flex-shrink:0;">
      <svg viewBox="0 0 100 100" width="90" height="90">
        {assign var="wCircum" value=251.2}
        {assign var="wOffset" value=$wCircum|default:251.2}
        {assign var="wPct"    value=$wh.score|default:0}
        <circle cx="50" cy="50" r="40" fill="none" stroke="#e8d5b0" stroke-width="10"/>
        <circle cx="50" cy="50" r="40" fill="none"
                stroke="{$wh.color|default:'#16a34a'}" stroke-width="10"
                stroke-dasharray="{$wCircum}"
                stroke-dashoffset="{math equation='c - c * p / 100' c=$wCircum p=$wPct}"
                stroke-linecap="round"
                transform="rotate(-90 50 50)"/>
        <text x="50" y="46" text-anchor="middle"
              style="font-size:20px;font-weight:700;fill:{$wh.color|default:'#16a34a'}">{$wh.score|default:0}</text>
        <text x="50" y="60" text-anchor="middle"
              style="font-size:9px;fill:#888;">/100</text>
      </svg>
      <div style="font-size:13px;font-weight:700;color:{$wh.color|default:'#16a34a'};margin-top:4px;">{$wh.label|default:'—'}</div>
    </div>

    {* Issues *}
    <div style="flex:1;min-width:200px;">
      {if empty($wh.issues)}
        <div style="color:#16a34a;font-size:13px;font-weight:600;">✓ Aucun problème détecté</div>
        <div style="color:#888;font-size:12px;margin-top:4px;">Tous les systèmes fonctionnent normalement.</div>
      {else}
        <div style="font-size:12px;font-weight:700;color:#7a5800;margin-bottom:8px;">Problèmes détectés :</div>
        <ul style="margin:0;padding-left:16px;font-size:12px;color:#5c3d1e;line-height:1.8;">
          {foreach $wh.issues as $issue}
            <li>{$issue|escape:'html'}</li>
          {/foreach}
        </ul>
      {/if}
    </div>
  </div>

  {* Grille sous-systèmes : Crons *}
  <div style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;opacity:.55;color:var(--neria-dark);margin-bottom:10px;">
    Monitoring des crons
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;margin-bottom:20px;">
    {foreach $wh.crons as $cKey => $cron}
      {assign var="cStatus" value=$cron.status|default:'late'}
      <div style="padding:12px 14px;border-radius:6px;border:1px solid {if $cStatus === 'ok'}#bbf7d0{elseif $cStatus === 'error'}#fecaca{else}#fed7aa{/if};background:{if $cStatus === 'ok'}#f0fdf4{elseif $cStatus === 'error'}#fff5f5{else}#fffbf0{/if};">
        <div style="font-size:11px;font-weight:700;color:{if $cStatus === 'ok'}#16a34a{elseif $cStatus === 'error'}#dc2626{else}#d97706{/if};margin-bottom:4px;">
          {if $cStatus === 'ok'}✓{elseif $cStatus === 'error'}✕{else}⚠{/if}
          {$cron.label|escape:'html'}
        </div>
        {if $cron.last_run}
          <div style="font-size:11px;color:#888;">
            Il y a
            {if $cron.age_minutes < 60}
              {$cron.age_minutes} min
            {else}
              {math equation="floor(m/60)" m=$cron.age_minutes}h
            {/if}
            ({$cron.last_count} traité{if $cron.last_count > 1}s{/if})
          </div>
        {else}
          <div style="font-size:11px;color:#d97706;">Jamais exécuté</div>
        {/if}
      </div>
    {/foreach}

    {* Queue *}
    {assign var="qStatus" value=$wh.queue.status|default:'ok'}
    <div style="padding:12px 14px;border-radius:6px;border:1px solid {if $qStatus === 'ok'}#bbf7d0{else}#fed7aa{/if};background:{if $qStatus === 'ok'}#f0fdf4{else}#fffbf0{/if};">
      <div style="font-size:11px;font-weight:700;color:{if $qStatus === 'ok'}#16a34a{else}#d97706{/if};margin-bottom:4px;">
        {if $qStatus === 'ok'}✓{else}⚠{/if} File d'attente
      </div>
      {if $wh.queue.exists}
        {if $wh.queue.stuck > 0}
          <div style="font-size:11px;color:#d97706;">{$wh.queue.stuck} bloqué{if $wh.queue.stuck > 1}s{/if} (&gt;2h)</div>
        {/if}
        {if $wh.queue.failed > 0}
          <div style="font-size:11px;color:#dc2626;">{$wh.queue.failed} en échec</div>
        {/if}
        {if $wh.queue.stuck == 0 && $wh.queue.failed == 0}
          <div style="font-size:11px;color:#888;">{$wh.queue.total_pending} en attente — OK</div>
        {/if}
      {else}
        <div style="font-size:11px;color:#888;">Queue non activée</div>
      {/if}
    </div>

    {* Erreurs 24h *}
    {assign var="e24Err"    value=$wh.rc_24h.error|default:0}
    {assign var="e24Crit"   value=$wh.rc_24h.critical|default:0}
    {assign var="e24Warn"   value=$wh.rc_24h.warning|default:0}
    <div style="padding:12px 14px;border-radius:6px;border:1px solid {if $e24Err > 0 || $e24Crit > 0}#fecaca{else}#bbf7d0{/if};background:{if $e24Err > 0 || $e24Crit > 0}#fff5f5{else}#f0fdf4{/if};">
      <div style="font-size:11px;font-weight:700;color:{if $e24Err > 0 || $e24Crit > 0}#dc2626{else}#16a34a{/if};margin-bottom:4px;">
        {if $e24Err > 0 || $e24Crit > 0}✕{else}✓{/if} Erreurs (24h)
      </div>
      {if $e24Err == 0 && $e24Crit == 0 && $e24Warn == 0}
        <div style="font-size:11px;color:#888;">Aucune anomalie</div>
      {else}
        {if $e24Crit > 0}<div style="font-size:11px;color:#dc2626;">{$e24Crit} critique{if $e24Crit > 1}s{/if}</div>{/if}
        {if $e24Err > 0}<div style="font-size:11px;color:#a32d2d;">{$e24Err} erreur{if $e24Err > 1}s{/if}</div>{/if}
        {if $e24Warn > 0}<div style="font-size:11px;color:#d97706;">{$e24Warn} warning{if $e24Warn > 1}s{/if}</div>{/if}
      {/if}
    </div>

  </div>

  {* Anomalies métriques *}
  {if isset($anomaly_warnings) && !empty($anomaly_warnings)}
  <div style="background:#fffbf0;border:1px solid #fcd34d;border-radius:6px;padding:14px 18px;margin-top:4px;">
    <div style="font-size:12px;font-weight:700;color:#92400e;margin-bottom:10px;">
      ⚠ Anomalies détectées sur {$anomaly_warnings|@count} template{if $anomaly_warnings|@count > 1}s{/if}
    </div>
    {foreach $anomaly_warnings as $anm}
    <div style="margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid #fde68a;font-size:12px;">
      <strong>{$anm.template|escape:'html'}</strong> —
      {if $anm.open_drop >= 20}Ouv. : -{$anm.open_drop}% (S-1 : {$anm.last_week.open_rate}% → S : {$anm.this_week.open_rate}%){/if}
      {if $anm.open_drop >= 20 && $anm.click_drop >= 20} · {/if}
      {if $anm.click_drop >= 20}Clics : -{$anm.click_drop}%{/if}
    </div>
    {/foreach}
  </div>
  {/if}

</div>
{/if}

{* ── Diagnostic ─────────────────────────────────────────────── *}
<div class="neria-section" id="neria-help-diagnostic">
  <h2 class="neria-section__title">
    {neria_admin key='help.diagnostic_title'}
    <span class="neria-score neria-score--{$diagnostic.score.status}">
      {$diagnostic.score.score}/100
    </span>
  </h2>

  <div class="neria-diag-grid">

    {* PHP *}
    <div class="neria-diag-block">
      <h3 class="neria-diag-block__title">PHP</h3>
      <ul class="neria-diag-list">
        <li class="{if $diagnostic.php.version_ok}neria-diag--ok{else}neria-diag--err{/if}">
          PHP {$diagnostic.php.version}
          {if !$diagnostic.php.version_ok}
            <span class="neria-diag-note">{neria_admin key='help.php_required'}</span>
          {/if}
        </li>
        <li class="{if $diagnostic.php.gd_available}neria-diag--ok{else}neria-diag--warn{/if}">
          GD (signatures)
          {if !$diagnostic.php.gd_available}
            <span class="neria-diag-note">{neria_admin key='help.gd_required'}</span>
          {/if}
        </li>
        <li class="{if $diagnostic.php.mbstring}neria-diag--ok{else}neria-diag--err{/if}">
          mbstring
        </li>
        <li class="{if $diagnostic.php.openssl}neria-diag--ok{else}neria-diag--warn{/if}">
          OpenSSL
        </li>
      </ul>
    </div>

    {* Base de données *}
    <div class="neria-diag-block">
      <h3 class="neria-diag-block__title">{neria_admin key='help.database'}</h3>
      <ul class="neria-diag-list">
        {foreach $diagnostic.database as $table => $data}
          <li class="{if $data.exists}neria-diag--ok{else}neria-diag--err{/if}">
            {$table}
            {if $data.exists}
              <span class="neria-diag-count">{$data.rows} {neria_admin key='help.rows'}</span>
            {else}
              <span class="neria-diag-note">{neria_admin key='help.table_missing'}</span>
            {/if}
          </li>
        {/foreach}
      </ul>
    </div>

    {* Hooks *}
    <div class="neria-diag-block">
      <h3 class="neria-diag-block__title">Hooks</h3>
      <ul class="neria-diag-list">
        {foreach $diagnostic.hooks as $hook => $registered}
          <li class="{if $registered}neria-diag--ok{else}neria-diag--err{/if}">
            {$hook}
            {if !$registered}
              <span class="neria-diag-note">{neria_admin key='help.not_registered'}</span>
            {/if}
          </li>
        {/foreach}
      </ul>
    </div>

    {* Fichiers *}
    <div class="neria-diag-block">
      <h3 class="neria-diag-block__title">{neria_admin key='help.files'}</h3>
      <ul class="neria-diag-list">
        {foreach $diagnostic.files as $label => $data}
          <li class="{if $data.exists}neria-diag--ok{else}neria-diag--err{/if}">
            {$label}
            {if $data.exists}
              <span class="neria-diag-count">{$data.size}</span>
            {else}
              <span class="neria-diag-note">{neria_admin key='help.not_found'}</span>
            {/if}
          </li>
        {/foreach}
      </ul>
    </div>

    {* Polices TTF *}
    <div class="neria-diag-block">
      <h3 class="neria-diag-block__title">{neria_admin key='help.ttf_fonts'}</h3>
      <ul class="neria-diag-list">
        {foreach $diagnostic.fonts as $font => $present}
          <li class="{if $present}neria-diag--ok{else}neria-diag--warn{/if}">
            {$font}
            {if !$present}
              <span class="neria-diag-note">
                <a href="https://fonts.google.com" target="_blank">
                  {neria_admin key='help.download_google_fonts'}
                </a>
              </span>
            {/if}
          </li>
        {/foreach}
      </ul>
    </div>

    {* Permissions *}
    <div class="neria-diag-block">
      <h3 class="neria-diag-block__title">{neria_admin key='help.folder_permissions'}</h3>
      <ul class="neria-diag-list">
        {foreach $diagnostic.permissions as $dir => $data}
          <li class="{if $data.exists && $data.writable}neria-diag--ok{elseif $data.exists}neria-diag--warn{else}neria-diag--err{/if}">
            {$dir}
            {if !$data.exists}
              <span class="neria-diag-note">{neria_admin key='help.folder_missing'}</span>
            {elseif !$data.writable}
              <span class="neria-diag-note">{neria_admin key='help.not_writable'}</span>
            {/if}
          </li>
        {/foreach}
      </ul>
    </div>

  </div>
</div>

{* ── Contrôles de santé actifs ──────────────────────────────── *}
<div class="neria-section" id="neria-help-health">
  <h2 class="neria-section__title">
    {neria_admin key='help.health_title'}
    {if $health_last_run}
      <span style="font-size:12px; font-weight:400; color:var(--neria-text-light); margin-left:10px;">
        {neria_admin key='help.health_last_run'} {$health_last_run}
      </span>
    {else}
      <span style="font-size:12px; font-weight:400; color:#BA7517; margin-left:10px;">
        {neria_admin key='help.health_never'}
      </span>
    {/if}
  </h2>

  <p style="font-size:12px; color:var(--neria-text-light); margin-bottom:16px;">
    {neria_admin key='help.health_auto_note'}
  </p>

  {* Bouton diagnostic complet *}
  <div style="margin-bottom:20px;">
    <form method="post" action="{$smarty.server.REQUEST_URI}" style="display:inline;">
      <input type="hidden" name="neria_action" value="run_full_diagnostic">
      <input type="hidden" name="neria_tab"    value="help">
      <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm" style="gap:6px;">
        <span>⟳</span> {neria_admin key='help.health_run_full'}
      </button>
    </form>
    <span style="font-size:12px; color:var(--neria-text-light); margin-left:12px;">
      {neria_admin key='help.health_run_full_note'}
    </span>
  </div>

  {if $health_results}
    {assign var='_checks' value=[
      'sent_reconciliation'  => 'help.health_check_sent',
      'pixel_in_html'        => 'help.health_check_pixel_html',
      'theme_override'       => 'help.health_check_theme',
      'translation_gaps'     => 'help.health_check_trad',
      'cron_triggered'       => 'help.health_check_cron',
      'crons_health'         => 'help.health_check_crons_health',
      'config_keys'          => 'help.health_check_config',
      'list_unsubscribe'     => 'help.health_check_unsubscribe',
      'assets'               => 'help.health_check_assets',
      'smtp_config'          => 'help.health_check_smtp',
      'bounce_rate'          => 'help.health_check_bounce_rate',
      'consecutive_failures' => 'help.health_check_consecutive_failures',
      'gdpr_registry'        => 'help.health_check_gdpr_registry',
      'critical_methods'     => 'help.health_check_critical_methods',
      'template_files'       => 'help.health_check_template_files',
      'trad_keys'            => 'help.health_check_trad_keys',
      'hooks_registered'     => 'help.health_check_hooks_registered',
      'version_sync'         => 'help.health_check_version_sync',
      'open_rate'            => 'help.health_check_open_rate',
      'queue_blocked'        => 'help.health_check_queue_blocked',
      'hmac_secret'          => 'help.health_check_hmac_secret'
    ]}
    <div class="neria-diag-grid">
      {foreach $health_results as $checkKey => $result}
        {assign var='hStatus' value=$result.status|default:'ok'}
        <div class="neria-diag-block">
          <h3 class="neria-diag-block__title">{neria_admin key=$_checks[$checkKey]|default:$checkKey}</h3>
          <ul class="neria-diag-list">
            <li class="{if $hStatus === 'ok'}neria-diag--ok{elseif $hStatus === 'warning'}neria-diag--warn{else}neria-diag--err{/if}">
              {if !empty($result.auto_fixed)}
                <span class="neria-badge neria-badge--warn" style="margin-right:4px;">
                  {neria_admin key='help.health_auto_fixed'}
                </span>
              {/if}
              {$result.detail|default:''|escape:'html'|regex_replace:'/→ Que faire :/':"<br><strong style=\"color:#BA7517;\">→ Que faire :</strong>"}
            </li>
          </ul>
        </div>
      {/foreach}
    </div>
  {else}
    <div class="neria-empty-state">
      <span class="neria-empty-state__icon">⟳</span>
      <p>{neria_admin key='help.health_pending'}</p>
    </div>
  {/if}

  {* Test manuel du pixel HTTP *}
  <div style="margin-top:20px; padding-top:16px; border-top:1px solid var(--neria-border);">
    <strong style="font-size:13px;">{neria_admin key='help.health_pixel_manual'}</strong>
    <p style="font-size:12px; color:var(--neria-text-light); margin:6px 0 10px;">
      {neria_admin key='help.health_pixel_desc'}
    </p>

    {if isset($health_pixel_result)}
      {assign var='_ps' value=$health_pixel_result.status|default:'ok'}
      <div class="neria-alert {if $_ps === 'ok'}neria-alert--success{else}neria-alert--warning{/if}"
           style="margin-bottom:10px; font-size:12px;">
        {$health_pixel_result.detail|default:''|escape:'html'}
      </div>
    {/if}

    <form method="post" action="{$smarty.server.REQUEST_URI}" style="display:inline;">
      <input type="hidden" name="neria_action" value="health_pixel_test">
      <input type="hidden" name="neria_tab"    value="help">
      <button type="submit" class="neria-btn neria-btn--ghost neria-btn--sm">
        {neria_admin key='help.health_pixel_btn'}
      </button>
    </form>
  </div>
</div>

{* ── Alertes email Watchdog ─────────────────────────────────── *}
<div class="neria-section" id="neria-help-alerts">
  <h2 class="neria-section__title">
    📧 {neria_admin key="help.alert_title"}
  </h2>
  <p style="font-size:12px; color:var(--neria-text-light); margin-bottom:16px;">
    {neria_admin key="help.alert_desc"}
  </p>

  <form method="post" action="{$current_url|escape:'html'}">
    <input type="hidden" name="neria_action" value="save_alert_config">

    <div class="neria-field" style="margin-bottom:12px;">
      <label style="display:block; font-size:12px; font-weight:600; margin-bottom:6px; color:var(--neria-text);">
        {neria_admin key="help.alert_email_label"}
      </label>
      <input type="email" name="neria_alert_email"
             value="{$alert_email|escape:'html'}"
             placeholder="admin@votre-boutique.com"
             style="width:100%; max-width:380px; padding:8px 12px; border:1px solid var(--neria-border); border-radius:6px; font-size:13px;">
      <p style="font-size:11px; color:var(--neria-text-light); margin-top:4px;">
        {neria_admin key="help.alert_email_hint"}
      </p>
    </div>

    <div style="display:flex; flex-direction:column; gap:10px; margin-bottom:16px;">
      <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
        <input type="checkbox" name="neria_alert_immediate" value="1"
               {if $alert_immediate}checked{/if}
               style="margin-top:2px; flex-shrink:0; width:15px; height:15px; cursor:pointer;">
        <span>
          <strong style="font-size:13px;">{neria_admin key="help.alert_immediate_label"}</strong><br>
          <span style="font-size:11px; color:var(--neria-text-light);">{neria_admin key="help.alert_immediate_hint"}</span>
        </span>
      </label>

      <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
        <input type="checkbox" name="neria_alert_digest" value="1"
               {if $alert_digest}checked{/if}
               style="margin-top:2px; flex-shrink:0; width:15px; height:15px; cursor:pointer;">
        <span>
          <strong style="font-size:13px;">{neria_admin key="help.alert_digest_label"}</strong><br>
          <span style="font-size:11px; color:var(--neria-text-light);">{neria_admin key="help.alert_digest_hint"}</span>
        </span>
      </label>
    </div>

    <button type="submit" class="neria-btn neria-btn--primary" style="font-size:13px; padding:9px 20px;">
      {neria_admin key="help.alert_save"}
    </button>
  </form>
</div>

{* ── Page d'urgence Watchdog ────────────────────────────────── *}
<div class="neria-section" id="neria-help-emergency">
  <h2 class="neria-section__title">
    🚨 {neria_admin key='help.emergency_title'}
  </h2>

  <p style="font-size:12px; color:var(--neria-text-light); margin-bottom:16px;">
    {neria_admin key='help.emergency_desc'}
  </p>

  <div style="background:#1a1a2e; border-radius:8px; padding:14px 18px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px;">
    <span style="color:#b38b59; font-size:16px;">✦</span>
    <span style="color:#e8e8e8 !important; font-size:12px; word-break:break-all; flex:1; font-family:monospace;">{$emergency_url|escape:'html'}</span>
    <button id="neria-emergency-copy-btn" class="neria-btn neria-btn--ghost neria-btn--sm" style="color:#b38b59; border-color:#b38b59;">
      {neria_admin key='help.emergency_copy'}
    </button>
    <a href="{$emergency_url|escape:'html'}" target="_blank" class="neria-btn neria-btn--ghost neria-btn--sm" style="color:#b38b59; border-color:#b38b59;">
      {neria_admin key='help.emergency_open'}
    </a>
  </div>
  <script>
  (function() {
    var btn = document.getElementById('neria-emergency-copy-btn');
    var url = '{$emergency_url|escape:"javascript"}';
    if (btn) {
      btn.addEventListener('click', function() {
        navigator.clipboard.writeText(url).then(function() {
          btn.textContent = '✓ Copié';
          setTimeout(function() { btn.textContent = btn.dataset.label; }, 2000);
        });
      });
      btn.dataset.label = btn.textContent;
    }
  })();
  </script>

  <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
    <form method="post" action="{$smarty.server.REQUEST_URI}" style="display:inline;">
      <input type="hidden" name="neria_action" value="regenerate_emergency_token">
      <input type="hidden" name="neria_tab"    value="help">
      <button type="submit" class="neria-btn neria-btn--danger neria-btn--sm"
              onclick="return confirm('{neria_admin key="help.emergency_regen_confirm"}')">
        ↺ {neria_admin key='help.emergency_regen'}
      </button>
    </form>
    <span style="font-size:12px; color:var(--neria-text-light);">
      {neria_admin key='help.emergency_regen_note'}
    </span>
  </div>
</div>

{* ── Journal des événements ─────────────────────────────────── *}
<div class="neria-section" id="neria-help-log">
  <h2 class="neria-section__title">
    {neria_admin key='help.log_title'}
  </h2>

  {* Réglage : inclure ou non les emails internes (administrateur) *}
  <form method="post" action="{$smarty.server.REQUEST_URI}" style="margin-bottom:18px;">
    <input type="hidden" name="neria_action"       value="save_log_internal">
    <input type="hidden" name="neria_tab"          value="help">
    <input type="hidden" name="neria_log_internal" value="0">
    <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-size:13px; color:var(--neria-text);">
      <input type="checkbox" name="neria_log_internal" value="1"
             style="width:16px; height:16px; cursor:pointer;"
             onchange="this.form.submit()"
             {if $log_internal_enabled}checked{/if}>
      <span>{neria_admin key='help.log_internal_toggle'}
        <span style="color:#999;">— {neria_admin key='help.log_internal_note'}</span>
      </span>
    </label>
  </form>

  {* Résumé par niveau *}
  <div class="neria-kpi-grid" style="margin-bottom:20px;">
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$log_counts.info|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='common.level_info'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value" style="color:#BA7517;">{$log_counts.warning|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='common.level_warning'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value" style="color:#A32D2D;">{$log_counts.error|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='common.level_error'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value" style="color:#7a0000;">{$log_counts.critical|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='common.level_critical'}</div>
    </div>
  </div>

  {* Filtres *}
  <div class="neria-trad-selectors" style="margin-bottom:16px;">
    <select id="neria-log-level" class="neria-select neria-select--sm">
      <option value="">{neria_admin key='help.all_levels'}</option>
      <option value="info">{neria_admin key='common.level_info'}</option>
      <option value="warning">{neria_admin key='common.level_warning'}</option>
      <option value="error">{neria_admin key='common.level_error'}</option>
      <option value="critical">{neria_admin key='common.level_critical'}</option>
    </select>

    <select id="neria-log-template" class="neria-select neria-select--sm">
      <option value="">{neria_admin key='help.all_templates'}</option>
      {foreach $log_templates as $tpl}
        <option value="{$tpl}">{$tpl}</option>
      {/foreach}
    </select>

    <form method="post" action="{$smarty.server.REQUEST_URI}" style="display:inline">
      <input type="hidden" name="neria_action" value="clear_logs">
      <input type="hidden" name="neria_tab" value="help">
      <button type="submit" class="neria-btn neria-watchdog-btn neria-watchdog-btn--danger"
              onclick="return confirm('{neria_admin key='help.clear_confirm'}')">
        🗑 {neria_admin key='help.clear_log'}
      </button>
    </form>

    <button type="button" id="neria-log-pdf-btn" class="neria-btn neria-watchdog-btn">
      ⬇ {neria_admin key='help.log_pdf'}
    </button>

    <form method="post" action="{$smarty.server.REQUEST_URI}" style="display:inline;">
      <input type="hidden" name="neria_action" value="send_log_email">
      <input type="hidden" name="neria_tab" value="help">
      <button type="submit" class="neria-btn neria-watchdog-btn"
              title="{neria_admin key='help.log_email_send_title'}">
        📧 {neria_admin key='help.log_email_send'}
      </button>
    </form>

    <button type="button" id="neria-log-share-btn" class="neria-btn neria-watchdog-btn">
      ↗ {neria_admin key='help.log_share'}
    </button>
  </div>

  {* Tableau des logs *}
  {if isset($logs) && $logs}
    <div class="neria-table-wrap">
      <table class="neria-table neria-log-table" id="neria-log-table">
        <colgroup>
          <col class="col-date">
          <col class="col-level">
          <col class="col-class">
          <col class="col-tpl">
          <col class="col-msg">
        </colgroup>
        <thead>
          <tr>
            <th>{neria_admin key='common.date'}</th>
            <th>{neria_admin key='help.col_level'}</th>
            <th>{neria_admin key='help.col_class'}</th>
            <th>{neria_admin key='common.template'}</th>
            <th>{neria_admin key='help.col_message'}</th>
          </tr>
        </thead>
        <tbody>
          {foreach $logs as $log}
            <tr class="neria-log-row neria-log-row--{$log.level}">
              <td class="col-date">{$log.date_add}</td>
              <td class="col-level">
                <span class="neria-badge neria-badge--{if $log.level === 'info'}neutral{elseif $log.level === 'warning'}warn{else}err{/if}">
                  {$log.level}
                </span>
                {assign var="occCount" value=$log.occurrence_count|default:1}
                {if $occCount > 1}
                  <span style="font-size:10px;background:#e8d5b0;color:#5c3d1e;padding:1px 5px;border-radius:10px;font-weight:700;margin-left:3px;"
                        title="Ce message est apparu {$occCount} fois en 1h">×{$occCount}</span>
                {/if}
              </td>
              <td class="col-class">{$log.class}</td>
              <td class="col-tpl">{$log.template|default:'—'}</td>
              <td class="col-msg">{$log.message|escape:'html'}</td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    </div>
  {else}
    <div class="neria-empty-state">
      <span class="neria-empty-state__icon">✓</span>
      <p>{neria_admin key='help.log_empty'}</p>
    </div>
  {/if}
</div>

{* ── Documentation rapide ───────────────────────────────────── *}
<div class="neria-section" id="neria-help-quickguide">
  <h2 class="neria-section__title">{neria_admin key='help.quickguide_title'}</h2>

  <div class="neria-doc-grid">

    <div class="neria-doc-card">
      <h3 class="neria-doc-card__title">◈&nbsp;{neria_admin key='help.vars_title'}</h3>
      <p>{neria_admin key='help.vars_desc'}</p>
      <ul class="neria-doc-vars">
        <li><code>{literal}{maison_name}{/literal}</code> — {neria_admin key='configure.var_maison_name'}</li>
        <li><code>{literal}{slogan}{/literal}</code> — {neria_admin key='help.var_slogan_desc'}</li>
        <li><code>{literal}{founder_name}{/literal}</code> — {neria_admin key='configure.var_founder_name'}</li>
        <li><code>{literal}{founder_title}{/literal}</code> — {neria_admin key='configure.var_founder_title'}</li>
        <li><code>{literal}{signature_closing}{/literal}</code> — {neria_admin key='configure.var_signature_closing'}</li>
        <li><code>{literal}{shop_name}{/literal}</code> — {neria_admin key='help.var_shop_name'}</li>
        <li><code>{literal}{firstname}{/literal}</code> — {neria_admin key='help.var_firstname'}</li>
        <li><code>{literal}{lastname}{/literal}</code> — {neria_admin key='help.var_lastname'}</li>
      </ul>
    </div>

    <div class="neria-doc-card">
      <h3 class="neria-doc-card__title">⇋&nbsp;{neria_admin key='help.abtest_tips_title'}</h3>
      <ul class="neria-doc-list">
        <li>{neria_admin key='help.abtest_tip1'}</li>
        <li>{neria_admin key='help.abtest_tip2'}</li>
        <li>{neria_admin key='help.abtest_tip3'}</li>
        <li>{neria_admin key='help.abtest_tip4'}</li>
      </ul>
    </div>

    <div class="neria-doc-card">
      <h3 class="neria-doc-card__title">◫&nbsp;{neria_admin key='help.calendar_title'}</h3>
      <p>{neria_admin key='help.calendar_desc'}</p>
    </div>

    <div class="neria-doc-card">
      <h3 class="neria-doc-card__title">?&nbsp;{neria_admin key='help.support_title'}</h3>
      <p>{neria_admin key='help.support_desc'}</p>
      <a href="https://www.neria.io/docs" target="_blank"
         class="neria-btn neria-btn--ghost neria-btn--sm">
        {neria_admin key='help.documentation'}
      </a>
    </div>

  </div>
</div>

{* ── PDF + Partage : JS ─────────────────────────────────────── *}
{literal}
<script>
(function () {
  /* ── PDF : ouvre une nouvelle fenêtre avec uniquement le tableau ── */
  var btnPdf = document.getElementById('neria-log-pdf-btn');
  if (btnPdf) {
    btnPdf.addEventListener('click', function () { openPdfWindow(''); });
  }

  /* ── Partager : menu déroulant ── */
  var LS_KEY = 'neria_share_platforms';

  var BUILTIN = [
    { id: 'whatsapp',  label: 'WhatsApp',        icon: '💬', url: 'https://wa.me/?text={text}' },
    { id: 'telegram',  label: 'Telegram',         icon: '✈️',  url: 'https://t.me/share/url?url={url}&text={text}' },
    { id: 'teams',     label: 'Microsoft Teams',  icon: '🟦', url: 'https://teams.microsoft.com/share?href={url}&msgText={text}' },
    { id: 'gmail',     label: 'Gmail',            icon: '📧', url: 'https://mail.google.com/mail/?view=cm&su={title}&body={text}' },
    { id: 'yahoo',     label: 'Yahoo Mail',       icon: '🟣', url: 'https://compose.mail.yahoo.com/?subject={title}&body={text}' },
    { id: 'outlook',   label: 'Outlook',          icon: '🔵', url: 'https://outlook.live.com/mail/0/deeplink/compose?subject={title}&body={text}' },
    { id: 'copy',      label: 'Copier le texte',  icon: '📋', url: '__copy__' },
  ];

  function getCustomPlatforms() {
    try { return JSON.parse(localStorage.getItem(LS_KEY) || '[]'); } catch(e) { return []; }
  }
  function saveCustomPlatforms(list) {
    localStorage.setItem(LS_KEY, JSON.stringify(list));
  }

  function buildLines() {
    var rows  = document.querySelectorAll('#neria-log-table tbody tr');
    var lines = [
      'Journal Watchdog Neria — ' + new Date().toLocaleString('fr-FR'),
      window.location.hostname,
      ''
    ];
    rows.forEach(function (r) {
      if (r.style.display === 'none') return;
      var cells = r.querySelectorAll('td');
      if (cells.length >= 5) {
        lines.push('[' + cells[1].textContent.trim().toUpperCase() + '] '
          + cells[0].textContent.trim() + ' — '
          + (cells[3].textContent.trim() || cells[2].textContent.trim()) + ' : '
          + cells[4].textContent.trim().substring(0, 120));
      }
    });
    return lines.slice(0, 33);
  }

  var EMAIL_PLATFORMS = ['gmail', 'yahoo', 'outlook'];

  function openPlatform(p, title) {
    var lines = buildLines();

    if (p.url === '__copy__') {
      var t = lines.join('\n');
      navigator.clipboard ? navigator.clipboard.writeText(t) : (function(){
        var ta = document.createElement('textarea');
        ta.value = t; document.body.appendChild(ta); ta.select();
        document.execCommand('copy'); document.body.removeChild(ta);
      })();
      return;
    }

    var isEmail = EMAIL_PLATFORMS.indexOf(p.id) !== -1;
    var encodedText, url;

    if (isEmail) {
      var mailUrl = p.url
        .replace('{text}',  encodeURIComponent('Veuillez trouver ci-joint le journal Watchdog Neria.'))
        .replace('{title}', encodeURIComponent('Journal Watchdog Neria — ' + window.location.hostname))
        .replace('{url}',   encodeURIComponent(window.location.href));
      /* Ouvre le PDF */
      openPdfWindow('');
      /* Affiche un bandeau flottant avec le lien vers la boîte mail */
      showMailToast(p.label, p.icon, mailUrl);
    } else {
      var text = lines.join('\n');
      url = p.url
        .replace('{text}',  encodeURIComponent(text))
        .replace('{title}', encodeURIComponent(title))
        .replace('{url}',   encodeURIComponent(window.location.href));
      window.open(url, '_blank', 'noopener');
    }
  }

  function showMailToast(label, icon, mailUrl) {
    var existing = document.getElementById('neria-mail-toast');
    if (existing) existing.remove();

    var toast = document.createElement('div');
    toast.id = 'neria-mail-toast';
    toast.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:99999;'
      + 'background:#1a1a2e;color:#fff;border-radius:10px;padding:16px 20px;'
      + 'box-shadow:0 6px 24px rgba(0,0,0,.35);max-width:320px;font-family:sans-serif;';
    toast.innerHTML = '<div style="font-size:12px;color:#b38b59;font-weight:600;margin-bottom:8px;">📎 PDF généré</div>'
      + '<div style="font-size:13px;margin-bottom:14px;line-height:1.5;">Enregistrez le PDF, puis cliquez pour ouvrir votre boîte mail.</div>'
      + '<div style="display:flex;gap:8px;align-items:center;">'
      + '<a href="' + mailUrl + '" target="_blank" rel="noopener" '
      +   'style="flex:1;display:block;text-align:center;background:#b38b59;color:#fff;padding:9px 14px;'
      +   'border-radius:6px;text-decoration:none;font-size:13px;font-weight:600;">'
      + icon + ' Ouvrir ' + label + '</a>'
      + '<button onclick="document.getElementById(\'neria-mail-toast\').remove()" '
      +   'style="background:rgba(255,255,255,.1);border:none;color:#fff;border-radius:6px;'
      +   'padding:9px 12px;cursor:pointer;font-size:13px;">✕</button>'
      + '</div>';

    document.body.appendChild(toast);

    /* Disparaît automatiquement après 20s */
    setTimeout(function () {
      if (document.getElementById('neria-mail-toast')) {
        toast.style.transition = 'opacity .4s';
        toast.style.opacity = '0';
        setTimeout(function () { toast.remove(); }, 400);
      }
    }, 20000);
  }

  function openPdfWindow(notice) {
    var level = (document.getElementById('neria-log-level') || {}).value || '';
    var rows  = document.querySelectorAll('#neria-log-table tbody tr');
    var tbody = '';
    rows.forEach(function (r) {
      if (level && !r.classList.contains('neria-log-row--' + level)) return;
      tbody += r.outerHTML;
    });
    var thEl  = document.querySelector('#neria-log-table thead');
    var thead = thEl ? thEl.outerHTML : '';
    var now   = new Date().toLocaleString('fr-FR');
    var host  = window.location.hostname;
    var noticeHtml = notice
      ? '<div style="background:#fff8e6;border:1px solid #ffe082;border-radius:6px;padding:12px 16px;margin-bottom:18px;font-size:12px;color:#7a5800;">'
        + '📎 ' + notice + '</div>'
      : '';
    var html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
      + '<title>Neria — Journal Watchdog</title>'
      + '<style>'
      + 'body{font-family:sans-serif;font-size:11px;margin:24px;color:#222;}'
      + '.print-header{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #b38b59;padding-bottom:10px;margin-bottom:18px;}'
      + '.print-header h1{margin:0;font-size:16px;color:#1a1a2e;}'
      + '.notice{background:#fff8e6;border:1px solid #ffe082;border-radius:6px;padding:12px 16px;margin-bottom:18px;font-size:12px;color:#7a5800;}'
      + 'table{width:100%;border-collapse:collapse;font-size:10px;}'
      + 'th{background:#1a1a2e;color:#fff;padding:5px 8px;text-align:left;}'
      + 'td{padding:4px 8px;border-bottom:1px solid #eee;vertical-align:top;}'
      + '.neria-log-row--warning td{background:#fffbf0;}'
      + '.neria-log-row--error td{background:#fff5f5;}'
      + '.neria-log-row--critical td{background:#fdf0f0;}'
      + '.neria-badge{padding:1px 5px;border-radius:3px;font-size:9px;font-weight:700;color:#fff;display:inline-block;}'
      + '.neria-badge--neutral{background:#6c757d;}.neria-badge--warn{background:#ba7517;}.neria-badge--err{background:#a32d2d;}'
      + '.col-msg{max-width:340px;word-break:break-word;}'
      + '@media print{.notice{display:none;}@page{margin:1cm;}}'
      + '</style></head><body>'
      + '<div class="print-header">'
      +   '<div><h1>Neria — Journal Watchdog</h1><div style="font-size:10px;color:#888;">' + host + '</div></div>'
      +   '<div style="text-align:right;font-size:10px;color:#888;">Exporté le ' + now + '<br>PrestaShop BO</div>'
      + '</div>'
      + (notice ? '<div class="notice">📎 ' + notice + '</div>' : '')
      + '<table>' + thead + '<tbody>' + tbody + '</tbody></table>'
      + '<script>window.onload=function(){window.print();}<\/script>'
      + '</body></html>';
    var win = window.open('', '_blank', 'width=900,height=700');
    if (win) { win.document.write(html); win.document.close(); }
  }

  function buildDropdown() {
    var drop = document.getElementById('neria-share-dropdown');
    if (!drop) return;
    drop.innerHTML = '';

    var all = BUILTIN.concat(getCustomPlatforms());
    all.forEach(function (p) {
      var li = document.createElement('li');
      li.style.cssText = 'list-style:none;';
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.style.cssText = 'width:100%;text-align:left;padding:8px 14px;background:none;border:none;cursor:pointer;font-size:13px;display:flex;align-items:center;gap:8px;color:#222;white-space:nowrap;';
      btn.innerHTML = '<span>' + p.icon + '</span><span>' + p.label + '</span>';
      if (p.custom) {
        var del = document.createElement('span');
        del.textContent = '✕';
        del.title = 'Supprimer';
        del.style.cssText = 'margin-left:auto;color:#aaa;font-size:11px;cursor:pointer;';
        del.addEventListener('click', function (e) {
          e.stopPropagation();
          var customs = getCustomPlatforms().filter(function(x){ return x.id !== p.id; });
          saveCustomPlatforms(customs);
          buildDropdown();
        });
        btn.appendChild(del);
      }
      btn.addEventListener('mouseenter', function(){ btn.style.background = '#f5f5f5'; });
      btn.addEventListener('mouseleave', function(){ btn.style.background = 'none'; });
      btn.addEventListener('click', function () {
        openPlatform(p, 'Journal Watchdog Neria');
        closeDrop();
      });
      li.appendChild(btn);
      drop.appendChild(li);
    });

    /* Séparateur + formulaire ajout custom */
    var sep = document.createElement('li');
    sep.style.cssText = 'list-style:none;border-top:1px solid #eee;margin:4px 0;';
    drop.appendChild(sep);

    var addLi = document.createElement('li');
    addLi.style.cssText = 'list-style:none;padding:8px 14px;';
    addLi.innerHTML = '<div style="font-size:11px;font-weight:600;color:#888;margin-bottom:6px;">➕ Ajouter une plateforme</div>'
      + '<input id="neria-share-add-name" placeholder="Nom (ex: Slack)" style="width:100%;padding:4px 7px;border:1px solid #ddd;border-radius:4px;font-size:12px;margin-bottom:5px;box-sizing:border-box;">'
      + '<input id="neria-share-add-url"  placeholder="URL avec {text} et {title}" style="width:100%;padding:4px 7px;border:1px solid #ddd;border-radius:4px;font-size:12px;margin-bottom:6px;box-sizing:border-box;">'
      + '<button id="neria-share-add-btn" type="button" style="font-size:11px;padding:4px 10px;background:#b38b59;color:#fff;border:none;border-radius:4px;cursor:pointer;">Ajouter</button>';
    drop.appendChild(addLi);

    document.getElementById('neria-share-add-btn').addEventListener('click', function () {
      var name = (document.getElementById('neria-share-add-name').value || '').trim();
      var url  = (document.getElementById('neria-share-add-url').value  || '').trim();
      if (!name || !url) return;
      var customs = getCustomPlatforms();
      customs.push({ id: 'custom_' + Date.now(), label: name, icon: '🔗', url: url, custom: true });
      saveCustomPlatforms(customs);
      buildDropdown();
    });
  }

  function closeDrop() {
    var wrap = document.getElementById('neria-share-wrap');
    if (wrap) wrap.classList.remove('open');
  }

  var btnShare = document.getElementById('neria-log-share-btn');
  if (btnShare) {
    /* Injecte le dropdown dans le DOM */
    var wrap = document.createElement('div');
    wrap.id = 'neria-share-wrap';
    wrap.style.cssText = 'position:relative;display:inline-block;';
    btnShare.parentNode.insertBefore(wrap, btnShare);
    wrap.appendChild(btnShare);

    var drop = document.createElement('ul');
    drop.id = 'neria-share-dropdown';
    drop.style.cssText = 'display:none;position:absolute;top:calc(100% + 4px);right:0;background:#fff;'
      + 'border:1px solid #e0e0e0;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.12);'
      + 'padding:4px 0;min-width:230px;z-index:9999;margin:0;';
    wrap.appendChild(drop);

    wrap.addEventListener('click', function (e) { e.stopPropagation(); });

    btnShare.addEventListener('click', function () {
      var isOpen = wrap.classList.toggle('open');
      drop.style.display = isOpen ? 'block' : 'none';
      if (isOpen) buildDropdown();
    });

    document.addEventListener('click', function () {
      closeDrop();
      drop.style.display = 'none';
    });
  }
})();
</script>
{/literal}

{* ── Fermeture du wrapper principal (ouvert dans navigation.tpl) *}
  </div>{* .neria-bo-content *}
</div>{* .neria-bo-wrap *}
