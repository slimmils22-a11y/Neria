{**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — help.tpl
 * Onglet Aide — Documentation, diagnostic et support
 * i18n : libellés via {neria_admin key='...'} (19 langues, AdminTranslator)
 *}

{* ── Watchdog v2 — Score de santé global ────────────────────── *}
{if isset($watchdog_health)}
{assign var="wh" value=$watchdog_health}
<div class="neria-section" id="neria-help-watchdog-score">
  <h2 class="neria-section__title" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
    <span>{neria_admin key='help.wd_score_title'}</span>
    <button id="neria-watchdog-analyze-btn" type="button"
            style="background:#16a34a;color:#fff;border:none;padding:7px 16px;border-radius:5px;font-size:12px;font-weight:700;cursor:pointer;letter-spacing:.02em;display:flex;align-items:center;gap:6px;">
      <span id="neria-watchdog-analyze-icon" style="display:inline-block;">🔄</span>
      <span id="neria-watchdog-analyze-label">{neria_admin key='help.wd_analyze_btn'}</span>
    </button>
  </h2>
  <div id="neria-wd-timestamp" style="font-size:11px;color:#aaa;text-align:right;margin-top:-8px;margin-bottom:8px;"></div>

  {* Score principal *}
  <div style="display:flex;align-items:center;gap:24px;flex-wrap:wrap;margin-bottom:24px;">
    <div style="text-align:center;flex-shrink:0;">
      <svg viewBox="0 0 100 100" width="90" height="90">
        {assign var="wCircum" value=251.2}
        {assign var="wPct"    value=$wh.score|default:0}
        <circle cx="50" cy="50" r="40" fill="none" stroke="#e8d5b0" stroke-width="10"/>
        <circle id="neria-wd-circle-bar" cx="50" cy="50" r="40" fill="none"
                stroke="{$wh.color|default:'#16a34a'}" stroke-width="10"
                stroke-dasharray="{$wCircum}"
                stroke-dashoffset="{math equation='c - c * p / 100' c=$wCircum p=$wPct}"
                stroke-linecap="round"
                transform="rotate(-90 50 50)"/>
        <text id="neria-wd-score-num" x="50" y="46" text-anchor="middle"
              style="font-size:20px;font-weight:700;fill:{$wh.color|default:'#16a34a'}">{$wh.score|default:0}</text>
        <text x="50" y="60" text-anchor="middle"
              style="font-size:9px;fill:#888;">/100</text>
      </svg>
      <div id="neria-wd-score-label" style="font-size:13px;font-weight:700;color:{$wh.color|default:'#16a34a'};margin-top:4px;">{$wh.label|default:'—'}</div>
    </div>

    {* Issues *}
    <div id="neria-wd-issues-wrap" style="flex:1;min-width:200px;">
      {if empty($wh.issues)}
        <div style="color:#16a34a;font-size:13px;font-weight:600;">{neria_admin key='help.wd_no_issues_title'}</div>
        <div style="color:#888;font-size:12px;margin-top:4px;">{neria_admin key='help.wd_no_issues_desc'}</div>
      {else}
        <div style="font-size:12px;font-weight:700;color:#7a5800;margin-bottom:8px;">{neria_admin key='help.wd_issues_title'}</div>
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
    {neria_admin key='help.wd_crons_section_title'}
  </div>
  <div id="neria-wd-crons-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;margin-bottom:20px;">
    {foreach $wh.crons as $cKey => $cron}
      {assign var="cStatus" value=$cron.status|default:'late'}
      <div style="padding:12px 14px;border-radius:6px;border:1px solid {if $cStatus === 'ok'}#bbf7d0{elseif $cStatus === 'error'}#fecaca{else}#fed7aa{/if};background:{if $cStatus === 'ok'}#f0fdf4{elseif $cStatus === 'error'}#fff5f5{else}#fffbf0{/if};">
        <div style="font-size:11px;font-weight:700;color:{if $cStatus === 'ok'}#16a34a{elseif $cStatus === 'error'}#dc2626{else}#d97706{/if};margin-bottom:4px;">
          {if $cStatus === 'ok'}✓{elseif $cStatus === 'error'}✕{else}⚠{/if}
          {$cron.label|escape:'html'}
        </div>
        {if $cron.last_run}
          <div style="font-size:11px;color:#888;">
            {if $cron.age_minutes < 60}
              {neria_admin key='help.wd_ago_min' n=$cron.age_minutes}
            {else}
              {math equation="floor(m/60)" m=$cron.age_minutes assign="cHrs"}
              {neria_admin key='help.wd_ago_hours' n=$cHrs}
            {/if}
            {if $cron.last_count > 1}
              {neria_admin key='help.wd_processed_plural' n=$cron.last_count}
            {else}
              {neria_admin key='help.wd_processed_singular' n=$cron.last_count}
            {/if}
          </div>
        {else}
          <div style="font-size:11px;color:#d97706;">{neria_admin key='help.wd_never_run'}</div>
        {/if}
      </div>
    {/foreach}

    {* Queue *}
    {assign var="qStatus" value=$wh.queue.status|default:'ok'}
    <div style="padding:12px 14px;border-radius:6px;border:1px solid {if $qStatus === 'ok'}#bbf7d0{else}#fed7aa{/if};background:{if $qStatus === 'ok'}#f0fdf4{else}#fffbf0{/if};">
      <div style="font-size:11px;font-weight:700;color:{if $qStatus === 'ok'}#16a34a{else}#d97706{/if};margin-bottom:4px;">
        {if $qStatus === 'ok'}✓{else}⚠{/if} {neria_admin key='help.wd_queue_title'}
      </div>
      {if $wh.queue.exists}
        {if $wh.queue.stuck > 0}
          <div style="font-size:11px;color:#d97706;">{if $wh.queue.stuck > 1}{neria_admin key='help.wd_queue_stuck_plural' n=$wh.queue.stuck}{else}{neria_admin key='help.wd_queue_stuck_singular' n=$wh.queue.stuck}{/if}</div>
        {/if}
        {if $wh.queue.failed > 0}
          <div style="font-size:11px;color:#dc2626;">{neria_admin key='help.wd_queue_failed' n=$wh.queue.failed}</div>
        {/if}
        {if $wh.queue.stuck == 0 && $wh.queue.failed == 0}
          <div style="font-size:11px;color:#888;">{neria_admin key='help.wd_queue_pending_ok' n=$wh.queue.total_pending}</div>
        {/if}
      {else}
        <div style="font-size:11px;color:#888;">{neria_admin key='help.wd_queue_disabled'}</div>
      {/if}
    </div>

    {* Erreurs 24h *}
    {assign var="e24Err"    value=$wh.rc_24h.error|default:0}
    {assign var="e24Crit"   value=$wh.rc_24h.critical|default:0}
    {assign var="e24Warn"   value=$wh.rc_24h.warning|default:0}
    <div style="padding:12px 14px;border-radius:6px;border:1px solid {if $e24Err > 0 || $e24Crit > 0}#fecaca{else}#bbf7d0{/if};background:{if $e24Err > 0 || $e24Crit > 0}#fff5f5{else}#f0fdf4{/if};">
      <div style="font-size:11px;font-weight:700;color:{if $e24Err > 0 || $e24Crit > 0}#dc2626{else}#16a34a{/if};margin-bottom:4px;">
        {if $e24Err > 0 || $e24Crit > 0}✕{else}✓{/if} {neria_admin key='help.wd_errors_24h_title'}
      </div>
      {if $e24Err == 0 && $e24Crit == 0 && $e24Warn == 0}
        <div style="font-size:11px;color:#888;">{neria_admin key='help.wd_no_anomaly'}</div>
      {else}
        {if $e24Crit > 0}<div style="font-size:11px;color:#dc2626;">{if $e24Crit > 1}{neria_admin key='help.wd_crit_plural' n=$e24Crit}{else}{neria_admin key='help.wd_crit_singular' n=$e24Crit}{/if}</div>{/if}
        {if $e24Err > 0}<div style="font-size:11px;color:#a32d2d;">{if $e24Err > 1}{neria_admin key='help.wd_err_plural' n=$e24Err}{else}{neria_admin key='help.wd_err_singular' n=$e24Err}{/if}</div>{/if}
        {if $e24Warn > 0}<div style="font-size:11px;color:#d97706;">{if $e24Warn > 1}{neria_admin key='help.wd_warn_plural' n=$e24Warn}{else}{neria_admin key='help.wd_warn_singular' n=$e24Warn}{/if}</div>{/if}
      {/if}
    </div>

  </div>

  {* Anomalies métriques *}
  <div id="neria-wd-anomalies">
  {if isset($anomaly_warnings) && !empty($anomaly_warnings)}
  <div style="background:#fffbf0;border:1px solid #fcd34d;border-radius:6px;padding:14px 18px;margin-top:4px;">
    <div style="font-size:12px;font-weight:700;color:#92400e;margin-bottom:10px;">
      {assign var="anmCount" value=$anomaly_warnings|@count}
      {if $anmCount > 1}{neria_admin key='help.wd_anomalies_title_plural' n=$anmCount}{else}{neria_admin key='help.wd_anomalies_title_singular' n=$anmCount}{/if}
    </div>
    {foreach $anomaly_warnings as $anm}
    <div style="margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid #fde68a;font-size:12px;">
      <strong>{$anm.template|escape:'html'}</strong> —
      {if $anm.open_drop >= 20}{neria_admin key='help.wd_anomaly_open_prefix'}{$anm.open_drop}{neria_admin key='help.wd_anomaly_open_pct_prevweek'}{$anm.last_week.open_rate}{neria_admin key='help.wd_anomaly_open_pct_thisweek'}{$anm.this_week.open_rate}{neria_admin key='help.wd_anomaly_open_pct_suffix'}{/if}
      {if $anm.open_drop >= 20 && $anm.click_drop >= 20} · {/if}
      {if $anm.click_drop >= 20}{neria_admin key='help.wd_anomaly_click_prefix'}{$anm.click_drop}{neria_admin key='help.wd_anomaly_pct_suffix'}{/if}
    </div>
    {/foreach}
  </div>
  {/if}
  </div>{* /neria-wd-anomalies *}

  <script>
  window.NERIA_WD_LABELS = {
    noIssuesTitle:          "{neria_admin key='help.wd_no_issues_title' esc='javascript'}",
    noIssuesDesc:           "{neria_admin key='help.wd_no_issues_desc' esc='javascript'}",
    issuesTitle:            "{neria_admin key='help.wd_issues_title' esc='javascript'}",
    agoMin:                 "{neria_admin key='help.wd_ago_min' esc='javascript'}",
    agoHours:               "{neria_admin key='help.wd_ago_hours' esc='javascript'}",
    processedSingular:      "{neria_admin key='help.wd_processed_singular' esc='javascript'}",
    processedPlural:        "{neria_admin key='help.wd_processed_plural' esc='javascript'}",
    neverRun:               "{neria_admin key='help.wd_never_run' esc='javascript'}",
    queueTitle:             "{neria_admin key='help.wd_queue_title' esc='javascript'}",
    queueStuckSingular:     "{neria_admin key='help.wd_queue_stuck_singular' esc='javascript'}",
    queueStuckPlural:       "{neria_admin key='help.wd_queue_stuck_plural' esc='javascript'}",
    queueFailed:            "{neria_admin key='help.wd_queue_failed' esc='javascript'}",
    queuePendingOk:         "{neria_admin key='help.wd_queue_pending_ok' esc='javascript'}",
    queueDisabled:          "{neria_admin key='help.wd_queue_disabled' esc='javascript'}",
    errors24hTitle:         "{neria_admin key='help.wd_errors_24h_title' esc='javascript'}",
    noAnomaly:              "{neria_admin key='help.wd_no_anomaly' esc='javascript'}",
    critSingular:           "{neria_admin key='help.wd_crit_singular' esc='javascript'}",
    critPlural:             "{neria_admin key='help.wd_crit_plural' esc='javascript'}",
    errSingular:            "{neria_admin key='help.wd_err_singular' esc='javascript'}",
    errPlural:              "{neria_admin key='help.wd_err_plural' esc='javascript'}",
    warnSingular:           "{neria_admin key='help.wd_warn_singular' esc='javascript'}",
    warnPlural:             "{neria_admin key='help.wd_warn_plural' esc='javascript'}",
    anomaliesTitleSingular: "{neria_admin key='help.wd_anomalies_title_singular' esc='javascript'}",
    anomaliesTitlePlural:   "{neria_admin key='help.wd_anomalies_title_plural' esc='javascript'}",
    anomalyOpenPrefix:      "{neria_admin key='help.wd_anomaly_open_prefix' esc='javascript'}",
    anomalyClickPrefix:     "{neria_admin key='help.wd_anomaly_click_prefix' esc='javascript'}",
    pctSuffix:              "{neria_admin key='help.wd_anomaly_pct_suffix' esc='javascript'}",
    updatedAt:              "{neria_admin key='help.wd_updated_at' esc='javascript'}",
    analyzing:              "{neria_admin key='help.wd_analyzing' esc='javascript'}",
    analyzeBtn:             "{neria_admin key='help.wd_analyze_btn' esc='javascript'}"
  };
  </script>

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
      'critical_methods'     => 'help.health_check_critical_methods',
      'template_files'       => 'help.health_check_template_files',
      'trad_keys'            => 'help.health_check_trad_keys',
      'hooks_registered'     => 'help.health_check_hooks_registered',
      'version_sync'         => 'help.health_check_version_sync',
      'upgrade_integrity'    => 'help.health_check_upgrade_integrity',
      'open_rate_7d'         => 'help.health_check_open_rate_7d',
      'queue_blocked'        => 'help.health_check_queue_blocked',
      'hmac_security'        => 'help.health_check_hmac_security',
      'engagement_trend'     => 'help.health_check_engagement_trend',
      'oauth_freshness'      => 'help.health_check_oauth_freshness',
      'visibility_freshness' => 'help.health_check_visibility_freshness',
      'ajax_endpoints'       => 'help.health_check_ajax_endpoints',
      'bounces_unprocessed'  => 'help.health_check_bounces_unprocessed',
      'webhook_failures'     => 'help.health_check_webhook_failures',
      'abtest_stuck'         => 'help.health_check_abtest_stuck',
      'crypto_key'           => 'help.health_check_crypto_key',
      'secrets_encrypted'    => 'help.health_check_secrets_encrypted',
      'send_volume_spike'    => 'help.health_check_send_volume_spike',
      'domain_rep_score'     => 'help.health_check_domain_rep_score',
      'ptr_record'           => 'help.health_check_ptr_record',
      'db_tables'            => 'help.health_check_db_tables',
      'unsubscribe_url'      => 'help.health_check_unsubscribe_url',
      'waitlist_backlog'     => 'help.health_check_waitlist_backlog',
      'smtp_quota'           => 'help.health_check_smtp_quota',
      'postmaster_rep'       => 'help.health_check_postmaster_rep',
      'click_rate_7d'        => 'help.health_check_click_rate_7d',
      'unsubscribe_spike'    => 'help.health_check_unsubscribe_spike',
      'fallback_template'    => 'help.health_check_fallback_template',
      'front_controllers'    => 'help.health_check_front_controllers',
      'queue_overflow'       => 'help.health_check_queue_overflow',
      'behavioral_dedup'     => 'help.health_check_behavioral_dedup',
      'multi_sender_json'    => 'help.health_check_multi_sender_json',
      'monthly_report_cfg'   => 'help.health_check_monthly_report_cfg',
      'deepl_key_valid'      => 'help.health_check_deepl_key_valid',
      'php_memory_limit'     => 'help.health_check_php_memory_limit',
      'loyalty_integrity'    => 'help.health_check_loyalty_integrity',
      'segment_freshness'    => 'help.health_check_segment_freshness',
      'clv_freshness'        => 'help.health_check_clv_freshness',
      'quote_reminders'      => 'help.health_check_quote_reminders',
      'campaign_empty_seg'   => 'help.health_check_campaign_empty_seg',
      'attribution_coverage' => 'help.health_check_attribution_coverage',
      'history_table_size'   => 'help.health_check_history_table_size',
      'abtest_trad_gaps'     => 'help.health_check_abtest_trad_gaps',
      'managers_available'   => 'help.health_check_managers_available',
      'active_cron'          => 'help.health_check_active_cron',
      'class_override'       => 'help.health_check_class_override',
      'smarty_compile_check' => 'help.health_check_smarty_compile_check',
      'upgrade_script_safety' => 'help.health_check_upgrade_script_safety',
      'known_regressions_guard' => 'help.health_check_known_regressions_guard',
      'sql_pattern_risks' => 'help.health_check_sql_pattern_risks',
      'txt_placeholder_coverage' => 'help.health_check_txt_placeholder_coverage',
      'orphaned_voucher_reservations' => 'help.health_check_orphaned_voucher_reservations',
      'encoded_residual_links' => 'help.health_check_encoded_residual_links',
      'crypto_key_health'    => 'help.health_check_crypto_key_health',
      'html_txt_pairs'       => 'help.health_check_html_txt_pairs',
      'template_staleness'   => 'help.health_check_template_staleness',
      'blacklist_stale_files' => 'help.health_check_blacklist_stale_files',
      'residual_vars_recent' => 'help.health_check_residual_vars_recent',
      'sig_social_recent'    => 'help.health_check_sig_social_recent',
      'action_banner_coverage' => 'help.health_check_action_banner_coverage',
      'orphan_placeholders'  => 'help.health_check_orphan_placeholders',
      'render_canary_recent' => 'help.health_check_render_canary_recent',
      'milestone_order_health' => 'help.health_check_milestone_order_health',
      'custom_vars_completeness' => 'help.health_check_custom_vars_completeness',
      'churn_propensity_freshness' => 'help.health_check_churn_propensity_freshness',
      'collection_look_products' => 'help.health_check_collection_look_products',
      'queue_failed_rate'    => 'help.health_check_queue_failed_rate',
      'json_config_integrity' => 'help.health_check_json_config_integrity',
      'crypto_unavailable_plain' => 'help.health_check_crypto_unavailable_plain',
      'abtest_variant_pair'  => 'help.health_check_abtest_variant_pair',
      'milestone_voucher_cartrule' => 'help.health_check_milestone_voucher_cartrule',
      'css_inliner_failures' => 'help.health_check_css_inliner_failures',
      'stored_secrets_decryptable' => 'help.health_check_stored_secrets_decryptable',
      'calendar_json_integrity' => 'help.health_check_calendar_json_integrity'
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
              {if $checkKey === 'trad_keys' && $hStatus !== 'ok'}
                <form method="post" action="{$smarty.server.REQUEST_URI}" style="margin-top:8px;">
                  <input type="hidden" name="neria_action" value="reload_all_translations">
                  <input type="hidden" name="neria_tab"    value="help">
                  <button type="submit" class="neria-btn neria-btn--secondary neria-btn--sm">
                    ↺ {neria_admin key='translations.reload_all'}
                  </button>
                </form>
              {/if}
              {if $checkKey === 'version_sync' && $hStatus !== 'ok'}
                <form method="post" action="{$smarty.server.REQUEST_URI}" style="margin-top:8px;">
                  <input type="hidden" name="neria_action" value="repair_module_version">
                  <input type="hidden" name="neria_tab"    value="help">
                  <button type="submit" class="neria-btn neria-btn--secondary neria-btn--sm">
                    ↺ {neria_admin key='help.health_fix_version_btn'}
                  </button>
                </form>
              {/if}
              {if $checkKey === 'bounces_unprocessed' && $hStatus !== 'ok'}
                <form method="post" action="{$smarty.server.REQUEST_URI}" style="margin-top:8px;">
                  <input type="hidden" name="neria_action" value="repair_bounces_check">
                  <input type="hidden" name="neria_tab"    value="help">
                  <button type="submit" class="neria-btn neria-btn--secondary neria-btn--sm">
                    ↺ {neria_admin key='help.health_fix_bounces_btn'}
                  </button>
                </form>
              {/if}
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
      <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
        {neria_admin key='help.health_pixel_btn'}
      </button>
    </form>
  </div>
</div>

{* ── Scan de code du module ────────────────────────────────── *}
<div class="neria-section" id="neria-help-code-scan">
  <h2 class="neria-section__title">
    🔍 {neria_admin key='help.code_scan_title'}
    {if $code_diag_last_run}
      <span style="font-size:12px; font-weight:400; color:var(--neria-text-light); margin-left:10px;">
        {neria_admin key='help.health_last_run'} {$code_diag_last_run}
      </span>
    {/if}
  </h2>

  <p style="font-size:12px; color:var(--neria-text-light); margin-bottom:16px;">
    {neria_admin key='help.code_scan_desc'}
  </p>

  <div style="margin-bottom:20px;">
    <form method="post" action="{$smarty.server.REQUEST_URI}" style="display:inline;">
      <input type="hidden" name="neria_action" value="run_code_diagnostic">
      <input type="hidden" name="neria_tab"    value="help">
      <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm" style="gap:6px;">
        <span>🔍</span> {neria_admin key='help.code_scan_btn'}
      </button>
    </form>
    <span style="font-size:12px; color:var(--neria-text-light); margin-left:12px;">
      {neria_admin key='help.code_scan_note'}
    </span>
  </div>

  {if $code_diag_results}
    {assign var='_codeChecks' value=[
      'admin_trad_usage' => 'help.code_scan_trad_usage',
      'class_references' => 'help.code_scan_class_refs'
    ]}
    <div class="neria-diag-grid">
      {foreach $code_diag_results as $checkKey => $result}
        {assign var='cStatus' value=$result.status|default:'ok'}
        <div class="neria-diag-block">
          <h3 class="neria-diag-block__title">{neria_admin key=$_codeChecks[$checkKey]|default:$checkKey}</h3>
          <ul class="neria-diag-list">
            <li class="{if $cStatus === 'ok'}neria-diag--ok{elseif $cStatus === 'warning'}neria-diag--warn{else}neria-diag--err{/if}">
              {$result.detail|default:''|escape:'html'|regex_replace:'/→ Que faire :/':"<br><strong style=\"color:#BA7517;\">→ Que faire :</strong>"}
            </li>
          </ul>
        </div>
      {/foreach}
    </div>
  {else}
    <div class="neria-empty-state">
      <span class="neria-empty-state__icon">🔍</span>
      <p>{neria_admin key='help.code_scan_pending'}</p>
    </div>
  {/if}
</div>

{if $is_regression_dev_mode}
{* ── Tests de régression (dev uniquement, _PS_MODE_DEV_) ─────── *}
<div class="neria-section" id="neria-help-regression">
  <h2 class="neria-section__title">
    🧪 {neria_admin key='help.regression_title'}
    {if $regression_last_run}
      <span style="font-size:12px; font-weight:400; color:var(--neria-text-light); margin-left:10px;">
        {neria_admin key='help.health_last_run'} {$regression_last_run}
      </span>
    {/if}
  </h2>

  <p style="font-size:12px; color:var(--neria-text-light); margin-bottom:16px;">
    {neria_admin key='help.regression_desc'}
  </p>

  <div style="margin-bottom:20px;">
    <form method="post" action="{$smarty.server.REQUEST_URI}" style="display:inline;">
      <input type="hidden" name="neria_action" value="run_regression_tests">
      <input type="hidden" name="neria_tab"    value="help">
      <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm" style="gap:6px;">
        <span>🧪</span> {neria_admin key='help.regression_btn'} ({$regression_test_count})
      </button>
    </form>
  </div>

  {if $regression_results}
    <div class="neria-diag-grid">
      {foreach $regression_results as $r}
        <div class="neria-diag-block">
          <h3 class="neria-diag-block__title">{$r.name|escape:'html'}</h3>
          <ul class="neria-diag-list">
            <li class="{if $r.pass}neria-diag--ok{else}neria-diag--err{/if}">
              {$r.message|escape:'html'}
            </li>
          </ul>
        </div>
      {/foreach}
    </div>
  {else}
    <div class="neria-empty-state">
      <span class="neria-empty-state__icon">🧪</span>
      <p>{neria_admin key='help.regression_pending'}</p>
    </div>
  {/if}
</div>
{/if}

{* ── Alertes email Watchdog ─────────────────────────────────── *}
<div class="neria-section" id="neria-help-alerts">
  <h2 class="neria-section__title">
    📧 {neria_admin key="help.alert_title"}
  </h2>
  <p style="font-size:12px; color:var(--neria-text-light); margin-bottom:16px;">
    {neria_admin key="help.alert_desc"}
  </p>

  <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}">
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
          btn.textContent = '{neria_admin key='help.copied_feedback' esc='javascript'}';
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
      <button type="button" class="neria-btn neria-btn--danger neria-btn--sm"
              data-confirm="{neria_admin key='help.emergency_regen_confirm' esc='html'}"
              onclick="neriaConfirmDelete(this);">
        ↺ {neria_admin key='help.emergency_regen'}
      </button>
    </form>
    <span style="font-size:12px; color:var(--neria-text-light);">
      {neria_admin key='help.emergency_regen_note'}
    </span>
  </div>
</div>

{* ── Cron externe (surveillance active) ────────────────────────── *}
<div class="neria-section" id="neria-help-cron">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
    <h2 class="neria-section__title" style="margin-bottom:0;">
      ⏱ {neria_admin key='help.cron_title'}
    </h2>
    <form method="post" action="{$smarty.server.REQUEST_URI}" style="display:inline;">
      <input type="hidden" name="neria_action" value="cron_toggle">
      <input type="hidden" name="neria_tab"    value="help">
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if $cron_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if $cron_enabled}● {neria_admin key='help.cron_toggle_on'}{else}○ {neria_admin key='help.cron_toggle_off'}{/if}
      </button>
    </form>
  </div>

  <p style="font-size:12px; color:var(--neria-text-light); margin:12px 0 16px;">
    {neria_admin key='help.cron_desc'}
  </p>

  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin-bottom:24px;font-size:13px;line-height:1.75;color:#4a3f35;">
    <div style="font-weight:700;margin-bottom:12px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">{neria_admin key='help.cron_how_title'}</div>
    {neria_admin key='help.cron_how_body'}
    <div style="font-weight:700;margin:16px 0 8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">{neria_admin key='help.cron_setup_title'}</div>
    <ol style="margin:0 0 0 18px;padding:0;">
      <li style="margin-bottom:6px;">{neria_admin key='help.cron_setup_1'}</li>
      <li style="margin-bottom:6px;">{neria_admin key='help.cron_setup_2'}</li>
      <li>{neria_admin key='help.cron_setup_3'}</li>
    </ol>
    <div style="margin-top:14px;padding-top:12px;border-top:1px solid #e8d5b0;font-size:12px;opacity:.75;">
      <strong>{neria_admin key='help.cron_tip_label'} :</strong> {neria_admin key='help.cron_tip_body'}
    </div>
  </div>

  {if !$cron_enabled}
    <p style="font-size:12px; color:#c0392b; margin-bottom:12px;">
      ⏸ {neria_admin key='help.cron_disabled_notice'}
    </p>
  {elseif $cron_last_hit}
    <p style="font-size:12px; color:#16a34a; margin-bottom:12px;">
      ✓ {neria_admin key='help.cron_active'} — {$cron_last_hit|escape:'html'}
    </p>
  {else}
    <p style="font-size:12px; color:#d97706; margin-bottom:12px;">
      ⚠ {neria_admin key='help.cron_not_configured'}
    </p>
  {/if}

  <div style="background:#1a1a2e; border-radius:8px; padding:14px 18px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px;">
    <span style="color:#b38b59; font-size:16px;">⏱</span>
    <span style="color:#e8e8e8 !important; font-size:12px; word-break:break-all; flex:1; font-family:monospace;">*/10 * * * * curl -s "{$cron_url|escape:'html'}" &gt;/dev/null</span>
    <button id="neria-cron-copy-btn" class="neria-btn neria-btn--ghost neria-btn--sm" style="color:#b38b59; border-color:#b38b59;">
      {neria_admin key='help.emergency_copy'}
    </button>
  </div>
  <script>
  (function() {
    var btn = document.getElementById('neria-cron-copy-btn');
    var url = '*/10 * * * * curl -s "{$cron_url|escape:"javascript"}" >/dev/null';
    if (btn) {
      btn.addEventListener('click', function() {
        navigator.clipboard.writeText(url).then(function() {
          btn.textContent = '{neria_admin key='help.copied_feedback' esc='javascript'}';
          setTimeout(function() { btn.textContent = btn.dataset.label; }, 2000);
        });
      });
      btn.dataset.label = btn.textContent;
    }
  })();
  </script>

  <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
    <form method="post" action="{$smarty.server.REQUEST_URI}" style="display:inline;">
      <input type="hidden" name="neria_action" value="regenerate_cron_token">
      <input type="hidden" name="neria_tab"    value="help">
      <button type="button" class="neria-btn neria-btn--danger neria-btn--sm"
              data-confirm="{neria_admin key='help.cron_regen_confirm' esc='html'}"
              onclick="neriaConfirmDelete(this);">
        ↺ {neria_admin key='help.cron_regen'}
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
      <button type="button" class="neria-btn neria-watchdog-btn neria-watchdog-btn--danger"
              data-confirm="{neria_admin key='help.clear_confirm' esc='html'}"
              onclick="neriaConfirmDelete(this);">
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

{* ── Lexique des termes techniques ─────────────────────────────── *}
<div class="neria-section" id="neria-help-glossary">
  <h2 class="neria-section__title">📖&nbsp;{neria_admin key='help.glossary_title'}</h2>
  <p class="neria-section__desc">{neria_admin key='help.glossary_intro'}</p>

  {assign var="_glossaryTerms" value=[
    'cartrule', 'hook', 'cron', 'webhook', 'hmac', 'oauth', 'token',
    'swiftmessage', 'spf_dkim_dmarc', 'rbl', 'template', 'smarty', 'json',
    'imap', 'ptr', 'smtp_quota', 'segment', 'churn', 'cache_ttl', 'encryption_key'
  ]}
  <dl class="neria-glossary">
    {foreach $_glossaryTerms as $_term}
      <div class="neria-glossary__item" id="neria-lex-{$_term}">
        <dt class="neria-glossary__term">{neria_admin key="help.glossary_term_`$_term`"}</dt>
        <dd class="neria-glossary__def">{neria_admin key="help.glossary_def_`$_term`"}</dd>
      </div>
    {/foreach}
  </dl>
</div>

{* ── Zone de danger ───────────────────────────────────────────── *}
<div class="neria-section" id="neria-help-danger-zone" style="border:1px solid #dc2626;">
  <h2 class="neria-section__title" style="color:#dc2626;">⚠ {neria_admin key='help.danger_zone_title'}</h2>
  <p class="neria-section__desc">{neria_admin key='help.danger_zone_desc'}</p>

  <form method="post" action="{$smarty.server.REQUEST_URI}"
        onsubmit="return confirm('{neria_admin key='help.danger_zone_confirm_js' esc='javascript'}');">
    <input type="hidden" name="neria_action" value="reset_all_data">
    <input type="hidden" name="neria_tab"    value="help">

    <div class="neria-form-group">
      <label class="neria-label" for="neria-reset-password">
        {neria_admin key='help.danger_zone_password_label'}
      </label>
      <input type="password" id="neria-reset-password" name="neria_reset_password"
             class="neria-input" style="max-width:280px;" autocomplete="current-password" required>
    </div>

    <div class="neria-form-group" style="display:flex;align-items:center;gap:8px;">
      <input type="checkbox" id="neria-reset-confirm" name="neria_reset_confirm" value="1" required>
      <label for="neria-reset-confirm" style="margin:0;font-size:12px;color:#5c3d1e;">
        {neria_admin key='help.danger_zone_checkbox_label'}
      </label>
    </div>

    <div style="margin-top:14px;">
      <button type="submit" class="neria-btn neria-btn--danger neria-btn--sm">
        {neria_admin key='help.danger_zone_btn'}
      </button>
    </div>
  </form>
</div>

{* ── PDF + Partage : JS ─────────────────────────────────────── *}
<script>
window.NERIA_HELP_L10N = {
  pdfGeneratedTitle: "{neria_admin key='help.pdf_generated_title' esc='javascript'}",
  pdfGeneratedDesc:  "{neria_admin key='help.pdf_generated_desc' esc='javascript'}",
  pdfOpenPrefix:     "{neria_admin key='help.pdf_open_prefix' esc='javascript'}",
  printJournalTitle: "{neria_admin key='help.print_journal_title' esc='javascript'}",
  exportedOn:        "{neria_admin key='help.exported_on' esc='javascript'}"
};
</script>
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
    toast.innerHTML = '<div style="font-size:12px;color:#b38b59;font-weight:600;margin-bottom:8px;">' + window.NERIA_HELP_L10N.pdfGeneratedTitle + '</div>'
      + '<div style="font-size:13px;margin-bottom:14px;line-height:1.5;">' + window.NERIA_HELP_L10N.pdfGeneratedDesc + '</div>'
      + '<div style="display:flex;gap:8px;align-items:center;">'
      + '<a href="' + mailUrl + '" target="_blank" rel="noopener" '
      +   'style="flex:1;display:block;text-align:center;background:#b38b59;color:#fff;padding:9px 14px;'
      +   'border-radius:6px;text-decoration:none;font-size:13px;font-weight:600;">'
      + icon + ' ' + window.NERIA_HELP_L10N.pdfOpenPrefix + ' ' + label + '</a>'
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
      +   '<div><h1>' + window.NERIA_HELP_L10N.printJournalTitle + '</h1><div style="font-size:10px;color:#888;">' + host + '</div></div>'
      +   '<div style="text-align:right;font-size:10px;color:#888;">' + window.NERIA_HELP_L10N.exportedOn + ' ' + now + '<br>PrestaShop BO</div>'
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
