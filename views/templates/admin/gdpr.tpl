{**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — gdpr.tpl
 * Onglet Conformite RGPD — Audit automatique et purge des donnees
 *}

{assign var="grade_color" value=$gdpr_audit.grade_color|default:'#888'}

{* ── Bouton de rapport PDF ─────────────────────────────────── *}
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
  <div>
    <h2 class="neria-section__title" style="margin:0;">{neria_admin key='gdpr.title'}</h2>
    <p class="neria-section__desc" style="margin-top:4px;">
      {neria_admin key='gdpr.desc'}
    </p>
    {if $neria_bo_lang == 'en' || $neria_bo_lang == 'ja' || $neria_bo_lang == 'ko' || $neria_bo_lang == 'zh' || $neria_bo_lang == 'tw' || $neria_bo_lang == 'ru' || $neria_bo_lang == 'tr' || $neria_bo_lang == 'ar'}
      <p class="neria-section__desc" style="margin-top:6px;font-style:italic;">
        {neria_admin key='gdpr.local_law_note'}
      </p>
    {/if}
  </div>
  <a href="{$smarty.server.REQUEST_URI|escape:'html'}&neria_action=gdpr_pdf"
     target="_blank"
     class="neria-btn neria-btn--primary neria-btn--sm">
    {neria_admin key='gdpr.download_pdf'}
  </a>
</div>

{* ── Purge automatique — cron quotidien selon la durée de rétention par table ── *}
<div class="neria-section" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:20px;">
  <div>
    <div style="font-weight:700;font-size:13px;color:#5c3d1e;">{neria_admin key='gdpr.auto_purge_title'}</div>
    <div style="font-size:12px;color:#7a6a5a;margin-top:2px;">{neria_admin key='gdpr.auto_purge_desc'}</div>
  </div>
  <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}">
    <input type="hidden" name="neria_action" value="toggle_gdpr_auto_purge">
    <input type="hidden" name="neria_tab"    value="gdpr">
    <button type="submit"
            style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                   background:{if $gdpr_auto_purge_enabled}#1a7a40{else}#c0392b{/if};
                   color:#fff; border:none; border-radius:4px; font-size:12px;
                   font-weight:700; cursor:pointer; letter-spacing:.04em;">
      {if $gdpr_auto_purge_enabled}● {neria_admin key='help.cron_toggle_on'}{else}○ {neria_admin key='help.cron_toggle_off'}{/if}
    </button>
  </form>
</div>

{* ── Score global ──────────────────────────────────────────── *}
<div class="neria-section neria-gdpr-score-card">
  <div style="display:flex;align-items:center;gap:20px;">
    <div class="neria-gdpr-grade" style="background:{$grade_color};">
      {$gdpr_audit.score}
    </div>
    <div>
      <div style="font-size:16px;font-weight:700;">
        {if $gdpr_audit.score === 'A'}{neria_admin key='gdpr.grade_a'}{/if}
        {if $gdpr_audit.score === 'B'}{neria_admin key='gdpr.grade_b'}{/if}
        {if $gdpr_audit.score === 'C'}{neria_admin key='gdpr.grade_c'}{/if}
        {if $gdpr_audit.score === 'D'}{neria_admin key='gdpr.grade_d'}{/if}
      </div>
      <div style="font-size:13px;color:var(--neria-text-muted,#888);margin-top:4px;">
        {$gdpr_audit.issues} {neria_admin key='gdpr.issues_label'} · {neria_admin key='gdpr.report_generated_on'} {$gdpr_audit.generated_at}
      </div>
    </div>
  </div>
</div>

{* ── AXE 1 : DÉSABONNEMENT ─────────────────────────────────── *}
<div class="neria-section">
  <h3 class="neria-section__title" style="font-size:14px;text-transform:uppercase;letter-spacing:.06em;">
    1 — {neria_admin key='gdpr.axis1_title'}
  </h3>
  <p class="neria-section__desc">
    {neria_admin key='gdpr.axis1_desc'}
  </p>
  <div class="neria-gdpr-checks">
    {foreach $gdpr_audit.unsubscribe.checks as $check}
    <div class="neria-gdpr-check {if isset($check.info)}neria-gdpr-check--info{elseif $check.ok}neria-gdpr-check--ok{else}neria-gdpr-check--fail{/if}">
      <span class="neria-gdpr-check__icon">
        {if isset($check.info)}·{elseif $check.ok}✓{else}✕{/if}
      </span>
      <div>
        <div class="neria-gdpr-check__label">{$check.label|escape:'html'}</div>
        <div class="neria-gdpr-check__detail">{$check.detail|escape:'html'}</div>
      </div>
    </div>
    {/foreach}
  </div>
</div>

{* ── AXE 2 : RÉTENTION ─────────────────────────────────────── *}
<div class="neria-section">
  <h3 class="neria-section__title" style="font-size:14px;text-transform:uppercase;letter-spacing:.06em;">
    2 — {neria_admin key='gdpr.axis2_title'}
  </h3>
  <p class="neria-section__desc">
    {neria_admin key='gdpr.axis2_desc'}
  </p>
  <table class="neria-gdpr-table">
    <thead>
      <tr>
        <th>{neria_admin key='gdpr.col_data'}</th>
        <th>{neria_admin key='gdpr.col_limit'}</th>
        <th>{neria_admin key='gdpr.col_oldest'}</th>
        <th>{neria_admin key='gdpr.col_overdue'}</th>
        <th>{neria_admin key='gdpr.col_status'}</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      {foreach $gdpr_audit.retention.rows as $row}
      <tr>
        <td>
          <strong>{$row.label|escape:'html'}</strong>
          <div class="neria-gdpr-table__note">{$row.note|escape:'html'}</div>
        </td>
        <td class="neria-gdpr-table__num">{$row.months} {neria_admin key='gdpr.months_unit'}</td>
        <td class="neria-gdpr-table__num">{$row.oldest}</td>
        <td class="neria-gdpr-table__num {if $row.overdue > 0}neria-gdpr-overdue{/if}">
          {$row.overdue}
        </td>
        <td>
          {if $row.ok}
            <span class="neria-badge neria-gdpr-badge--ok">{neria_admin key='gdpr.status_compliant'}</span>
          {else}
            <span class="neria-badge neria-gdpr-badge--warn">{neria_admin key='gdpr.status_to_purge'}</span>
          {/if}
        </td>
        <td>
          {if $row.overdue > 0}
          <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}" style="margin:0;">
            <input type="hidden" name="neria_action"    value="gdpr_purge">
            <input type="hidden" name="neria_tab"        value="gdpr">
            <input type="hidden" name="gdpr_table"       value="{$row.table|escape:'html'}">
            <input type="hidden" name="gdpr_date_col"    value="{$row.date_col|escape:'html'}">
            <input type="hidden" name="gdpr_months"      value="{$row.months|intval}">
            <button type="button" class="neria-btn neria-btn--danger neria-btn--xs"
                    data-confirm="{neria_admin key='gdpr.purge_confirm_prefix'} {$row.overdue} {neria_admin key='gdpr.purge_confirm_records_of'} {$row.label|escape:'html'} {neria_admin key='gdpr.purge_confirm_older_than'} {$row.months} {neria_admin key='gdpr.purge_confirm_months_q'}"
                    onclick="neriaConfirmDelete(this);">
              {neria_admin key='gdpr.purge_btn'}
            </button>
          </form>
          {/if}
        </td>
      </tr>
      {/foreach}
    </tbody>
  </table>
</div>

{* ── AXE 3 : DONNÉES PERSONNELLES ──────────────────────────── *}
<div class="neria-section">
  <h3 class="neria-section__title" style="font-size:14px;text-transform:uppercase;letter-spacing:.06em;">
    3 — {neria_admin key='gdpr.axis3_title'}
  </h3>
  <p class="neria-section__desc">
    {neria_admin key='gdpr.axis3_desc'}
  </p>

  {* Mentions légales *}
  <div class="neria-gdpr-check {if $gdpr_audit.pii.legal_in_layout}neria-gdpr-check--ok{else}neria-gdpr-check--fail{/if}" style="margin-bottom:12px;">
    <span class="neria-gdpr-check__icon">{if $gdpr_audit.pii.legal_in_layout}✓{else}✕{/if}</span>
    <div>
      <div class="neria-gdpr-check__label">{neria_admin key='gdpr.legal_notice_check_label'}</div>
      <div class="neria-gdpr-check__detail">
        {if $gdpr_audit.pii.legal_in_layout}
          {neria_admin key='gdpr.legal_notice_ok'}
        {else}
          {neria_admin key='gdpr.legal_notice_missing'}
        {/if}
      </div>
    </div>
  </div>

  {if $gdpr_audit.pii.map}
  <table class="neria-gdpr-table">
    <thead>
      <tr>
        <th>{neria_admin key='gdpr.col_template'}</th>
        <th>{neria_admin key='gdpr.col_personal_data'}</th>
        <th>{neria_admin key='gdpr.col_legal_basis'}</th>
      </tr>
    </thead>
    <tbody>
      {foreach $gdpr_audit.pii.map as $row}
      <tr>
        <td><code style="font-size:11px;">{$row.template|escape:'html'}</code></td>
        <td class="neria-gdpr-table__note">{$row.vars_str|escape:'html'}</td>
        <td class="neria-gdpr-table__note" style="color:var(--neria-text-muted,#999);">{$row.legal_basis|escape:'html'}</td>
      </tr>
      {/foreach}
    </tbody>
  </table>
  {else}
  <p class="neria-empty-state" style="margin:0;">{neria_admin key='gdpr.pii_empty'}</p>
  {/if}
</div>

{* ── AXE 4 : CHIFFREMENT ───────────────────────────────────── *}
<div class="neria-section">
  <h3 class="neria-section__title" style="font-size:14px;text-transform:uppercase;letter-spacing:.06em;">
    4 — {neria_admin key='gdpr.axis4_title'}
  </h3>
  <p class="neria-section__desc">
    {neria_admin key='gdpr.axis4_desc_pre'} {$gdpr_audit.crypto.cipher} — {neria_admin key='gdpr.axis4_desc_post'}
  </p>

  <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;">

    <div class="neria-gdpr-check {if $gdpr_audit.crypto.openssl_ok}neria-gdpr-check--ok{else}neria-gdpr-check--fail{/if}" style="flex:1;min-width:200px;">
      <span class="neria-gdpr-check__icon">{if $gdpr_audit.crypto.openssl_ok}✓{else}✕{/if}</span>
      <div>
        <div class="neria-gdpr-check__label">{neria_admin key='gdpr.openssl_check_label'}</div>
        <div class="neria-gdpr-check__detail">
          {if $gdpr_audit.crypto.openssl_ok}{neria_admin key='gdpr.openssl_ok_detail'}{else}{neria_admin key='gdpr.openssl_missing_detail'}{/if}
        </div>
      </div>
    </div>

    <div class="neria-gdpr-check {if $gdpr_audit.crypto.key_ok}neria-gdpr-check--ok{else}neria-gdpr-check--fail{/if}" style="flex:1;min-width:200px;">
      <span class="neria-gdpr-check__icon">{if $gdpr_audit.crypto.key_ok}✓{else}✕{/if}</span>
      <div>
        <div class="neria-gdpr-check__label">{neria_admin key='gdpr.crypto_key_check_label'}</div>
        <div class="neria-gdpr-check__detail">
          {if $gdpr_audit.crypto.key_ok}{neria_admin key='gdpr.crypto_key_ok_detail'}{else}{neria_admin key='gdpr.crypto_key_missing_detail'}{/if}
        </div>
      </div>
    </div>

  </div>

  {if $gdpr_audit.crypto.total > 0}
  <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
    <div style="flex:1;min-width:130px;padding:14px 16px;text-align:center;background:var(--neria-bg-hover,#faf8f5);border:1px solid var(--neria-border,#e8e0d5);border-radius:8px;">
      <div style="font-size:24px;font-weight:700;color:#4a9e6b;">{$gdpr_audit.crypto.encrypted}</div>
      <div style="font-size:12px;color:var(--neria-text-muted,#888);margin-top:4px;">{neria_admin key='gdpr.stat_encrypted'}</div>
    </div>
    <div style="flex:1;min-width:130px;padding:14px 16px;text-align:center;background:var(--neria-bg-hover,#faf8f5);border:1px solid var(--neria-border,#e8e0d5);border-radius:8px;">
      <div style="font-size:24px;font-weight:700;{if $gdpr_audit.crypto.plain > 0}color:#e05c5c;{else}color:#4a9e6b;{/if}">{$gdpr_audit.crypto.plain}</div>
      <div style="font-size:12px;color:var(--neria-text-muted,#888);margin-top:4px;">{neria_admin key='gdpr.stat_plain'}</div>
    </div>
    <div style="flex:1;min-width:130px;padding:14px 16px;text-align:center;background:var(--neria-bg-hover,#faf8f5);border:1px solid var(--neria-border,#e8e0d5);border-radius:8px;">
      <div style="font-size:24px;font-weight:700;">{$gdpr_audit.crypto.total}</div>
      <div style="font-size:12px;color:var(--neria-text-muted,#888);margin-top:4px;">{neria_admin key='gdpr.stat_total'}</div>
    </div>
  </div>
  {/if}

  {if $gdpr_audit.crypto.active && $gdpr_audit.crypto.plain > 0}
  <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}" style="margin:0;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
    <input type="hidden" name="neria_action" value="gdpr_encrypt_all">
    <input type="hidden" name="neria_tab"    value="gdpr">
    <button type="button" class="neria-btn neria-btn--primary neria-btn--sm"
            data-confirm="{neria_admin key='gdpr.encrypt_confirm_pre'} {$gdpr_audit.crypto.plain} {neria_admin key='gdpr.encrypt_confirm_post'}"
            onclick="neriaConfirmDelete(this);">
      {neria_admin key='gdpr.encrypt_btn_pre'} {$gdpr_audit.crypto.plain|intval} {neria_admin key='gdpr.encrypt_btn_post'}
    </button>
    <span style="font-size:12px;color:var(--neria-text-muted,#888);">
      {neria_admin key='gdpr.encrypt_auto_note'}
    </span>
  </form>
  {elseif $gdpr_audit.crypto.active && $gdpr_audit.crypto.plain == 0 && $gdpr_audit.crypto.total > 0}
  <p style="font-size:13px;color:#4a9e6b;font-weight:600;">✓ {neria_admin key='gdpr.all_encrypted'}</p>
  {elseif !$gdpr_audit.crypto.openssl_ok}
  <p style="font-size:13px;color:var(--neria-text-muted,#888);">{neria_admin key='gdpr.encrypt_unavailable'}</p>
  {/if}

  {if $gdpr_audit.crypto.other_pii_tables|@count > 0}
  <div style="margin-top:14px;padding:12px 14px;background:#fff8ec;border:1px solid #f0d9a8;border-radius:6px;">
    <p style="font-size:12px;color:#8a6d1a;font-weight:600;margin:0 0 6px;">ℹ️ {neria_admin key='gdpr.crypto_scope_note_title'}</p>
    <p style="font-size:12px;color:#8a6d1a;margin:0 0 6px;line-height:1.6;">{neria_admin key='gdpr.crypto_scope_note_body'}</p>
    <ul style="font-size:12px;color:#8a6d1a;margin:0;padding-left:18px;line-height:1.7;">
      {foreach $gdpr_audit.crypto.other_pii_tables as $ot}
      <li><strong>{$ot.table|escape:'html'}</strong> — {$ot.note|escape:'html'}</li>
      {/foreach}
    </ul>
  </div>
  {/if}
</div>

{* ── Avertissement ─────────────────────────────────────────── *}
<div class="neria-section" style="background:var(--neria-bg-hover,#faf8f5);border:1px solid var(--neria-border,#e8e0d5);">
  <p style="font-size:12px;color:var(--neria-text-muted,#888);line-height:1.7;">
    <strong>{neria_admin key='gdpr.disclaimer_title'}</strong>
    {neria_admin key='gdpr.disclaimer_body'}
  </p>
</div>
