{**
 * NERIA — _customer_history_content.tpl
 * Contenu partagé du bloc « Emails reçus » : badge, alertes, timeline,
 * tableau complet, actions, modale d'aperçu, formulaire de renvoi, JS.
 * Inclus à la fois depuis :
 *   - customer_email_history.tpl (hook displayAdminCustomers, fiche client)
 *   - customer_history.tpl (onglet « Historique clients » du panneau Neria)
 * Variables attendues : $neria_history, $neria_customer_id,
 * $neria_resend_message, $neria_resend_confirm_texts
 *}
{if $neria_resend_message}
<div class="neria-alert neria-alert--{if $neria_resend_message.ok}success{else}error{/if}">
  {$neria_resend_message.text|escape:'html'}
</div>
{/if}

{* ── Alerte risque de désabonnement ──────────────────────────── *}
{if isset($neria_churn) && $neria_churn && $neria_churn.score >= ($neria_churn_threshold|default:70)}
{assign var="churn_score" value=$neria_churn.score|intval}
{assign var="churn_level" value='high'}
{if $churn_score >= 85}{assign var="churn_level" value='critical'}{/if}
<div class="neria-alert" style="background:{if $churn_level === 'critical'}#fff0f0{else}#fff8ee{/if};border-left:3px solid {if $churn_level === 'critical'}#e05c5c{else}#e09c3c{/if};display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div>
    <strong style="color:{if $churn_level === 'critical'}#c0392b{else}#b8600a{/if};">
      {if $churn_level === 'critical'}⚠ {neria_admin key='churn.alert_critical'}{else}⚡ {neria_admin key='churn.alert_high'}{/if}
    </strong>
    <span style="margin-left:10px;font-size:22px;font-weight:700;color:{if $churn_level === 'critical'}#c0392b{else}#b8600a{/if};">{$churn_score}/100</span>
    <div style="margin-top:4px;font-size:12px;color:var(--neria-text-muted,#888);">
      {neria_admin key='churn.trend_label'} :
      P3 {($neria_churn.rate_p3*100)|number_format:0}% →
      P2 {($neria_churn.rate_p2*100)|number_format:0}% →
      P1 {($neria_churn.rate_p1*100)|number_format:0}%
      {if $neria_churn.last_open}
        · {neria_admin key='churn.last_open'} : {$neria_churn.last_open|escape:'html'|substr:0:10}
      {/if}
    </div>
  </div>
  <a href="{$smarty.server.REQUEST_URI|regex_replace:'/&neria_tab=[^&]*/':''}&neria_tab=send"
     class="neria-btn neria-btn--sm" style="background:{if $churn_level === 'critical'}#c0392b{else}#b8600a{/if};color:#fff;">
    ✉ {neria_admin key='churn.send_reengagement'}
  </a>
</div>
{/if}

{* ── Potentiel client 12 mois (CLV) ──────────────────────── *}
{if isset($neria_clv) && $neria_clv && $neria_clv.order_count > 0}
{assign var="clv" value=$neria_clv}
{assign var="clv_color" value='#27ae60'}
{if $clv.clv_label === 'medium'}{assign var="clv_color" value='#b38b59'}{/if}
{if $clv.clv_label === 'low'}{assign var="clv_color" value='#888'}{/if}
<div style="margin-bottom:16px; padding:14px 16px; background:#faf6f0; border-radius:6px; border:1px solid #e8dcc8;">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">

    <div>
      <div style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#888; margin-bottom:4px;">
        {neria_admin key='clv.title'}
      </div>
      <div style="font-size:26px; font-weight:800; color:{$clv_color}; line-height:1;">
        {$clv.clv_12m|number_format:0} {$clv.currency_symbol}
      </div>
      <div style="font-size:11px; color:#aaa; margin-top:2px;">
        {$clv.order_count} {neria_admin key='clv.orders'} · {$clv.months_active} {neria_admin key='clv.months'}
      </div>
    </div>

    <div style="display:flex; gap:18px; flex-wrap:wrap;">

      <div style="text-align:center;">
        <div style="font-size:10px; color:#aaa; text-transform:uppercase; letter-spacing:.04em;">{neria_admin key='clv.avg_order'}</div>
        <div style="font-size:15px; font-weight:700; color:#1a1a2e;">{$clv.avg_order|number_format:0} {$clv.currency_symbol}</div>
      </div>

      <div style="text-align:center;">
        <div style="font-size:10px; color:#aaa; text-transform:uppercase; letter-spacing:.04em;">{neria_admin key='clv.engagement'}</div>
        <div style="font-size:15px; font-weight:700;
             color:{if $clv.engagement_label === 'high'}#27ae60{elseif $clv.engagement_label === 'medium'}#b38b59{else}#c0392b{/if};">
          {$clv.engagement_rate}%
        </div>
      </div>

      <div style="text-align:center;">
        <div style="font-size:10px; color:#aaa; text-transform:uppercase; letter-spacing:.04em;">{neria_admin key='clv.segment'}</div>
        <div style="font-size:13px; font-weight:700; color:#1a1a2e;">
          {if $clv.segment_label === 'ambassador'}🏆{elseif $clv.segment_label === 'loyal'}⭐{elseif $clv.segment_label === 'warm'}🌱{elseif $clv.segment_label === 'dormant'}😴{elseif $clv.segment_label === 'ghost'}👻{/if}
          {neria_admin key="seg.label_`$clv.segment_label`"|default:$clv.segment_label}
        </div>
      </div>

      <div style="text-align:center;">
        <div style="font-size:10px; color:#aaa; text-transform:uppercase; letter-spacing:.04em;">{neria_admin key='clv.churn_risk'}</div>
        <div style="font-size:13px; font-weight:700;
             color:{if $clv.churn_label === 'high'}#c0392b{elseif $clv.churn_label === 'medium'}#e09c3c{else}#27ae60{/if};">
          {$clv.churn_score}/100
        </div>
      </div>

    </div>
  </div>

  <div style="margin-top:10px; padding-top:8px; border-top:1px solid #e8dcc8; font-size:11px; color:#aaa;">
    {neria_admin key='clv.formula'} :
    <code style="color:#888;">{$clv.avg_order|number_format:0} × {$clv.frequency_monthly|number_format:2}{neria_admin key='clv.per_month'} × 12 × ×{$clv.engagement_mult} × ×{$clv.segment_mult} × ×{$clv.churn_mult}</code>
    <span style="float:right; color:#b38b59; font-weight:600; font-size:10px;">✦ Neria</span>
  </div>
</div>
{/if}

{* ── Bloc fidélité client ─────────────────────────────────── *}
{if isset($neria_loyalty_enabled) && $neria_loyalty_enabled && isset($neria_loyalty) && $neria_loyalty}
{assign var="loy" value=$neria_loyalty}
<div style="margin-bottom:16px; padding:14px 16px; background:var(--neria-bg); border-radius:6px; border:1px solid var(--neria-border);">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">

    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
      {* Badge palier actuel *}
      {if $loy.tier}
        {assign var="tk" value=$loy.tier.key}
        <span style="padding:4px 12px; border-radius:20px; font-size:11px; font-weight:700; letter-spacing:.05em;
                     {if $tk === 'gold'}background:#fef9e7; color:#a0520d;
                     {elseif $tk === 'silver'}background:#f4f4f4; color:#5a5a5a;
                     {else}background:#faf0e8; color:#8b4513;{/if}">
          {if $tk === 'gold'}&#127947;{elseif $tk === 'silver'}&#127948;{else}&#127949;{/if}
          {$loy.tier.name|escape:'html'}
        </span>
      {else}
        <span style="padding:4px 12px; background:#f4f4f4; color:#888; border-radius:20px; font-size:11px; font-weight:600;">
          Sans palier
        </span>
      {/if}

      <span style="font-size:18px; font-weight:700; color:var(--neria-accent);">
        {$loy.total_points} pts
      </span>
    </div>

    {* Barre de progression vers le prochain palier *}
    {if $loy.next_tier}
    <div style="flex:1; min-width:160px; max-width:260px;">
      <div style="display:flex; justify-content:space-between; font-size:10px; color:var(--neria-muted); margin-bottom:4px;">
        <span>{$loy.prev_points} pts</span>
        <span>{$loy.next_tier.name|escape:'html'} : {$loy.next_tier.points} pts</span>
      </div>
      <div style="height:6px; background:var(--neria-border); border-radius:3px; overflow:hidden;">
        <div style="width:{$loy.progress_pct}%; height:100%; background:var(--neria-accent); border-radius:3px;"></div>
      </div>
      <div style="font-size:10px; color:var(--neria-muted); margin-top:3px; text-align:right;">{$loy.progress_pct}%</div>
    </div>
    {else}
    <span style="font-size:11px; color:#1a7a40; font-weight:600;">&#127942; Palier maximum atteint</span>
    {/if}
  </div>

  {* Historique des 5 derniers événements *}
  {if $loy.history}
  <div style="margin-top:12px; border-top:1px solid var(--neria-border); padding-top:10px;
              display:flex; gap:8px; flex-wrap:wrap;">
    {foreach $loy.history as $evt}
    {if $evt@index >= 5}{break}{/if}
    <span style="padding:3px 8px; border-radius:20px; font-size:10px; font-weight:600;
                 {if $evt.event_type === 'conversion'}background:#f0f8f4; color:#1a7a40;
                 {elseif $evt.event_type === 'click'}background:#f0f4f8; color:#2563a8;
                 {else}background:#f8f8f8; color:#666;{/if}">
      {if $evt.event_type === 'conversion'}+10 pts — Achat
      {elseif $evt.event_type === 'click'}+3 pts — Clic
      {else}+1 pt — Ouverture{/if}
    </span>
    {/foreach}
  </div>
  {/if}

  {* Bons déjà reçus *}
  {if $loy.rewards}
  <div style="margin-top:10px; border-top:1px solid var(--neria-border); padding-top:10px;">
    <span style="font-size:10px; color:var(--neria-muted); font-weight:700; text-transform:uppercase; letter-spacing:.06em;">
      Bons reçus :
    </span>
    {foreach $loy.rewards as $rew}
    <span style="margin-left:8px; font-size:11px; font-weight:600; color:var(--neria-accent);
                 background:var(--neria-bg); border:1px dashed var(--neria-accent);
                 padding:2px 8px; border-radius:4px;">
      {$rew.voucher_code|escape:'html'}
      ({if $rew.is_percent}{$rew.voucher_amount|string_format:"%.0f"}%{else}{$rew.voucher_amount|string_format:"%.2f"} {$currency_symbol}{/if})
    </span>
    {/foreach}
  </div>
  {/if}
</div>
{/if}

{if $neria_history.badge.total_sent > 0}
<div class="neria-history__summary">
  <div class="neria-history__summary-stat">
    <span class="neria-history__summary-value">{$neria_history.badge.total_sent}</span>
    <span class="neria-history__summary-label">{neria_admin key='history.total_sent'}</span>
  </div>
  <div class="neria-history__summary-stat">
    <span class="neria-history__summary-value">{$neria_history.badge.rate_open}%</span>
    <span class="neria-history__summary-label">{neria_admin key='history.open_rate'}</span>
    {if isset($neria_history.badge.shop_avg_rate_open)}
      <span style="display:block;font-size:11px;margin-top:2px;
                   color:{if $neria_history.badge.rate_open >= $neria_history.badge.shop_avg_rate_open}#1a7a40{else}#c0392b{/if};">
        {if $neria_history.badge.rate_open >= $neria_history.badge.shop_avg_rate_open}▲{else}▼{/if}
        vs {$neria_history.badge.shop_avg_rate_open}% {neria_admin key='history.shop_avg'}
      </span>
    {/if}
  </div>
  <div class="neria-history__summary-badge">
    {if $neria_history.badge.level === 'very_engaged'}
      <span class="neria-badge neria-badge--success">{neria_admin key='history.badge_very_engaged'}</span>
    {elseif $neria_history.badge.level === 'engaged'}
      <span class="neria-badge neria-badge--accent">{neria_admin key='history.badge_engaged'}</span>
    {elseif $neria_history.badge.level === 'low'}
      <span class="neria-badge neria-badge--neutral">{neria_admin key='history.badge_low'}</span>
    {else}
      <span class="neria-badge neria-badge--warn">{neria_admin key='history.badge_inactive'}</span>
    {/if}
  </div>
</div>
{/if}

{if $neria_history.alerts|@count > 0}
<div class="neria-history__alerts">
  {foreach $neria_history.alerts as $alert}
    <div class="neria-alert neria-alert--{if $alert.type === 'success'}success{else}warning{/if}">
      {$alert.text|escape:'html'}
    </div>
  {/foreach}
</div>
{/if}

{* ── Timeline (vue par défaut) ──────────────────────────────── *}
<div class="neria-history__timeline" id="neria-history-timeline">
  {foreach $neria_history.timeline as $email}
    <div class="neria-timeline-item {if $email.opened}neria-timeline-item--opened{else}neria-timeline-item--sent{/if}">
      <div class="neria-timeline-dot"></div>
      <div class="neria-timeline-content">
        <span class="neria-timeline-template">{$email.template}</span>
        <span class="neria-timeline-lang">[{$email.lang|upper}]</span>
        <span class="neria-timeline-date">{$email.sent_at_fmt}</span>
        <span class="neria-timeline-status">
          {if $email.opened}
            <i class="icon-eye" title="{neria_admin key='history.status_opened'}"></i>
          {else}
            <i class="icon-circle-o" title="{neria_admin key='history.status_sent'}"></i>
          {/if}
        </span>
        <span class="neria-timeline-row-actions">
          <button type="button" class="neria-link-btn neria-history-view"
                  data-id-stat="{$email.id_stat}">{neria_admin key='history.btn_view'}</button>
          <button type="button" class="neria-link-btn neria-history-resend"
                  data-id-stat="{$email.id_stat}"
                  data-has-snapshot="{if $email.has_snapshot}1{else}0{/if}">{neria_admin key='history.btn_resend'}</button>
        </span>
      </div>
    </div>
  {/foreach}

  {if $neria_history.timeline|@count == 0}
    <p class="neria-history__empty">{neria_admin key='history.empty'}</p>
  {/if}
</div>

{* ── Tableau complet (masqué par défaut) ────────────────────── *}
<div id="neria-history-filters" style="display:none;gap:10px;margin-bottom:10px;">
  <select id="neria-history-filter-template" class="neria-select" style="max-width:220px;">
    <option value="">{neria_admin key='history.filter_all_templates'}</option>
    {foreach $neria_history.templates_list as $tpl}
      <option value="{$tpl|escape:'html'}">{$tpl}</option>
    {/foreach}
  </select>
  <select id="neria-history-filter-status" class="neria-select" style="max-width:180px;">
    <option value="">{neria_admin key='history.filter_all_status'}</option>
    <option value="opened">{neria_admin key='history.status_opened'}</option>
    <option value="sent">{neria_admin key='history.status_sent'}</option>
  </select>
</div>
<table class="table neria-history__full-table" id="neria-history-table" style="display:none;">
  <thead>
    <tr>
      <th>{neria_admin key='history.col_date'}</th>
      <th>{neria_admin key='history.col_template'}</th>
      <th>{neria_admin key='history.col_lang'}</th>
      <th>{neria_admin key='history.col_status'}</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
    {foreach $neria_history.emails as $email}
      <tr data-template="{$email.template|escape:'html'}" data-status="{if $email.opened}opened{else}sent{/if}">
        <td>{$email.sent_at_fmt}</td>
        <td>{$email.template}</td>
        <td>{$email.lang|upper}</td>
        <td>
          {if $email.opened}
            <span class="neria-badge neria-badge--success">{neria_admin key='history.status_opened'}</span>
          {else}
            <span class="neria-badge neria-badge--neutral">{neria_admin key='history.status_sent'}</span>
          {/if}
        </td>
        <td>
          <button type="button" class="neria-link-btn neria-history-view"
                  data-id-stat="{$email.id_stat}">{neria_admin key='history.btn_view'}</button>
          <button type="button" class="neria-link-btn neria-history-resend"
                  data-id-stat="{$email.id_stat}"
                  data-has-snapshot="{if $email.has_snapshot}1{else}0{/if}">{neria_admin key='history.btn_resend'}</button>
        </td>
      </tr>
    {/foreach}
  </tbody>
</table>

{if $neria_history.emails|@count > 0}
<div class="neria-history__actions">
  {if $neria_history.has_more || $neria_history.emails|@count > 0}
    <button type="button" class="neria-btn neria-btn--primary neria-btn--sm" id="neria-history-toggle">
      {neria_admin key='history.view_all'}
    </button>
  {/if}
  <a class="neria-btn neria-btn--ghost neria-btn--sm"
     href="{$smarty.server.REQUEST_URI}{if $smarty.server.QUERY_STRING}&{else}?{/if}neria_export_csv=1&id_customer={$neria_customer_id}">
    {neria_admin key='history.export_csv'}
  </a>
</div>
{/if}

{* ── Modale aperçu (iframe) ──────────────────────────────────── *}
<div class="neria-modal-overlay" id="neria-preview-modal" style="display:none;">
  <div class="neria-modal">
    <div class="neria-modal__header">
      <span>{neria_admin key='history.title'}</span>
      <button type="button" class="neria-modal__close" id="neria-preview-close">&times;</button>
    </div>
    <iframe id="neria-preview-iframe" class="neria-modal__iframe" src="about:blank"></iframe>
  </div>
</div>

{* ── Formulaire de renvoi (caché, rempli par JS) ─────────────── *}
<form method="post" action="{$smarty.server.REQUEST_URI}" id="neria-resend-form" style="display:none;">
  <input type="hidden" name="neria_resend_email" value="1">
  <input type="hidden" name="id_customer" value="{$neria_customer_id}">
  <input type="hidden" name="id_stat" id="neria-resend-id-stat" value="">
</form>

<script>
  var neriaHistoryCustomerId    = {$neria_customer_id};
  var neriaHistoryBaseUrl       = {$smarty.server.REQUEST_URI|json_encode};
  var neriaHistoryConfirmTexts  = {$neria_resend_confirm_texts|json_encode};
</script>
<script>
{literal}
document.addEventListener('DOMContentLoaded', function () {
    var toggle   = document.getElementById('neria-history-toggle');
    var timeline = document.getElementById('neria-history-timeline');
    var table    = document.getElementById('neria-history-table');
    var filters  = document.getElementById('neria-history-filters');
    if (toggle) {
        var showingTable = false;
        toggle.addEventListener('click', function () {
            showingTable = !showingTable;
            timeline.style.display = showingTable ? 'none' : '';
            table.style.display    = showingTable ? '' : 'none';
            if (filters) { filters.style.display = showingTable ? 'flex' : 'none'; }
        });
    }

    // Filtres template / statut sur le tableau complet
    var filterTemplate = document.getElementById('neria-history-filter-template');
    var filterStatus   = document.getElementById('neria-history-filter-status');
    function neriaApplyHistoryFilters() {
        if (!table) { return; }
        var tplVal    = filterTemplate ? filterTemplate.value : '';
        var statusVal = filterStatus ? filterStatus.value : '';
        table.querySelectorAll('tbody tr').forEach(function (row) {
            var matchTpl    = !tplVal    || row.dataset.template === tplVal;
            var matchStatus = !statusVal || row.dataset.status === statusVal;
            row.style.display = (matchTpl && matchStatus) ? '' : 'none';
        });
    }
    if (filterTemplate) { filterTemplate.addEventListener('change', neriaApplyHistoryFilters); }
    if (filterStatus)   { filterStatus.addEventListener('change', neriaApplyHistoryFilters); }

    var modal     = document.getElementById('neria-preview-modal');
    var iframe    = document.getElementById('neria-preview-iframe');
    var closeBtn  = document.getElementById('neria-preview-close');
    var idCustomer = neriaHistoryCustomerId;
    var baseUrl    = neriaHistoryBaseUrl;

    function buildUrl(param, idStat) {
        var sep = baseUrl.indexOf('?') === -1 ? '?' : '&';
        return baseUrl + sep + param + '=1&id_customer=' + idCustomer + '&id_stat=' + idStat;
    }

    document.querySelectorAll('.neria-history-view').forEach(function (btn) {
        btn.addEventListener('click', function () {
            iframe.src = buildUrl('neria_preview_email', btn.dataset.idStat);
            modal.style.display = 'flex';
        });
    });

    if (closeBtn) {
        closeBtn.addEventListener('click', function () {
            modal.style.display = 'none';
            iframe.src = 'about:blank';
        });
    }
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                modal.style.display = 'none';
                iframe.src = 'about:blank';
            }
        });
    }

    var resendForm   = document.getElementById('neria-resend-form');
    var resendIdStat = document.getElementById('neria-resend-id-stat');
    document.querySelectorAll('.neria-history-resend').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var hasSnapshot = btn.dataset.hasSnapshot === '1';
            var msgKey = hasSnapshot ? 'history.resend_confirm' : 'history.resend_confirm_no_snapshot';
            var msg = neriaHistoryConfirmTexts;
            neriaConfirmAction(msg[msgKey], function () {
                resendIdStat.value = btn.dataset.idStat;
                resendForm.submit();
            });
        });
    });
});
{/literal}
</script>
