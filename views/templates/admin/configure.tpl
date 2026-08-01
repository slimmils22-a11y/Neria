{**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — configure.tpl
 * Page d'accueil du back-office
 * Fix 2 : id unique sur le select signature
 * Fix 4 : $upcoming_events doit être assignée par neria.php
 * i18n : libellés via {neria_admin key='...'} (19 langues, AdminTranslator)
 *}

{* ── Statut chiffrement AES-256-GCM ─────────────────────────── *}
<div style="margin:0 0 16px;padding:12px 18px;border-radius:6px;border:1px solid {if $crypto_status.available && $crypto_status.key_set}#c6e9c6{else}#f5c6cb{/if};background:{if $crypto_status.available && $crypto_status.key_set}#f0faf0{else}#fff0f0{/if};display:flex;align-items:center;gap:12px;">
  <span style="font-size:18px;">{if $crypto_status.available && $crypto_status.key_set}&#128274;{else}&#9888;{/if}</span>
  <div>
    <strong style="font-size:0.85rem;color:{if $crypto_status.available && $crypto_status.key_set}#1a7a40{else}#a00{/if};">
      {if $crypto_status.available && $crypto_status.key_set}{neria_admin key="crypto.status_active"}{else}{neria_admin key="crypto.status_unavailable"}{/if}
    </strong>
    <span style="font-size:0.76rem;color:#666;margin-left:8px;">
      {if $crypto_status.available && $crypto_status.key_set}{neria_admin key="crypto.status_desc_active"}{else}{neria_admin key="crypto.status_desc_unavailable"}{/if}
    </span>
  </div>
</div>
{* ── KPIs rapides ───────────────────────────────────────────── *}
<div class="neria-section" id="neria-cfg-dashboard">
  <h2 class="neria-section__title">
    {neria_admin key='configure.dashboard_title'}
  </h2>
  <div class="neria-kpi-grid">
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$kpis.total_sent|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='common.emails_sent'}</div>
      <div class="neria-kpi__period">{neria_admin key='common.last_30_days'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$kpis.rate_open|default:0}%</div>
      <div class="neria-kpi__label">{neria_admin key='common.open_rate'}</div>
      <div class="neria-kpi__period">{neria_admin key='common.last_30_days'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$kpis.rate_click|default:0}%</div>
      <div class="neria-kpi__label">{neria_admin key='common.click_rate'}</div>
      <div class="neria-kpi__period">{neria_admin key='common.last_30_days'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$kpis.active_langs|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='common.active_langs'}</div>
      <div class="neria-kpi__period">{neria_admin key='configure.of_18'}</div>
    </div>
  </div>
</div>

{* ── Détection automatique de la langue ─────────────────────── *}
{if $neria_menu_visible.auto_lang}
<div class="neria-section" id="neria-cfg-autolang">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:8px;">
    <h2 class="neria-section__title" style="margin:0;">{neria_admin key='configure.autolang_title'}</h2>
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}" style="display:inline;">
      <input type="hidden" name="neria_action" value="toggle_autolang">
      <input type="hidden" name="neria_tab"    value="configure">
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if $auto_lang_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if $auto_lang_enabled}● {neria_admin key='help.cron_toggle_on'}{else}○ {neria_admin key='help.cron_toggle_off'}{/if}
      </button>
    </form>
  </div>
  <p class="neria-section__desc">
    {neria_admin key='configure.autolang_desc'}
  </p>
</div>
{/if}

{* ── Smart Salutation — section unifiée ─────────────────────── *}
{if $neria_menu_visible.time_greeting}
<div class="neria-section" id="neria-cfg-time-greetings">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:8px;">
    <h2 class="neria-section__title" style="margin:0;">⏱ {neria_admin key='configure.time_greetings_title'}</h2>
    <form method="post" action="{$smarty.server.REQUEST_URI}" style="margin:0;">
      <input type="hidden" name="neria_action" value="toggle_time_greeting">
      <input type="hidden" name="neria_tab"    value="configure">
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if $time_greeting_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if $time_greeting_enabled}● {neria_admin key='help.cron_toggle_on'}{else}○ {neria_admin key='help.cron_toggle_off'}{/if}
      </button>
    </form>
  </div>
  <p class="neria-section__desc">
    {neria_admin key='configure.time_greetings_desc'}
  </p>

  {* ── Pays cibles ─────────────────────────────────────────────── *}
  <div style="margin-top:20px;padding-top:16px;border-top:1px solid #e8d5b0;">
    <p style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;opacity:.6;margin:0 0 6px;">
      🌍 {neria_admin key='configure.target_countries_title'}
    </p>
    <p class="neria-section__desc" style="margin-bottom:10px;">
      {neria_admin key='configure.target_countries_desc'}
    </p>

    <form method="post" action="{$smarty.server.REQUEST_URI}" id="neria-countries-form">
      <input type="hidden" name="neria_action" value="save_target_countries">
      <input type="hidden" name="neria_tab"    value="configure">

      <div style="display:flex;gap:8px;margin-bottom:10px;">
        <button type="button" onclick="neriaSelectAllCountries(true)"
          style="font-size:11px;padding:3px 10px;background:#fff;border:1px solid #e8d5b0;border-radius:4px;cursor:pointer;color:var(--neria-dark);">
          {neria_admin key='configure.select_all'}
        </button>
        <button type="button" onclick="neriaSelectAllCountries(false)"
          style="font-size:11px;padding:3px 10px;background:#fff;border:1px solid #e8d5b0;border-radius:4px;cursor:pointer;color:var(--neria-dark);">
          {neria_admin key='configure.deselect_all'}
        </button>
      </div>

      <input type="text" id="neria-country-search" placeholder="🔍 {neria_admin key='configure.search_country_placeholder'}"
             oninput="neriaFilterCountries(this.value)"
             style="width:100%;padding:7px 12px;border:1px solid #e8d5b0;border-radius:4px;font-size:12px;margin-bottom:10px;box-sizing:border-box;">

      <div id="neria-countries-list"
           style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:4px;max-height:240px;overflow-y:auto;padding:4px;">
        {foreach $all_countries as $iso => $name}
          <label data-country="{$name|lower}" style="display:flex;align-items:center;gap:6px;font-size:12px;padding:3px 6px;border-radius:3px;cursor:pointer;white-space:nowrap;overflow:hidden;">
            <input type="checkbox" name="neria_target_countries[]" value="{$iso}"
                   {if !$target_countries || in_array($iso, $target_countries)}checked{/if}>
            <span style="overflow:hidden;text-overflow:ellipsis;" title="{$name|escape:'html'}">{$name|escape:'html'}</span>
          </label>
        {/foreach}
      </div>

      <div style="margin-top:12px;">
        <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">{neria_admin key='configure.save_countries'}</button>
        <span style="font-size:11px;color:#6b6459;margin-left:10px;font-style:italic;">
          &#9432; {neria_admin key='configure.countries_empty_hint'}
        </span>
      </div>
    </form>
  </div>

  <script>
  function neriaFilterCountries(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('#neria-countries-list label').forEach(function(el) {
      el.style.display = (!q || el.dataset.country.indexOf(q) !== -1) ? '' : 'none';
    });
  }
  function neriaSelectAllCountries(check) {
    document.querySelectorAll('#neria-countries-list input[type=checkbox]').forEach(function(cb) {
      if (cb.closest('label').style.display !== 'none') cb.checked = check;
    });
  }
  </script>

  {* ── Formules de salutation ──────────────────────────────────── *}
  <div style="margin-top:20px;padding-top:16px;border-top:1px solid #e8d5b0;">
    <p style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;opacity:.6;margin:0 0 12px;">
      {neria_admin key='configure.tg_formulas_title'}
    </p>

  {assign var="tg_langs" value=[
    'fr'=>'FR','en'=>'EN','de'=>'DE','it'=>'IT','es'=>'ES','pt'=>'PT',
    'br'=>'BR','gb'=>'GB','ar'=>'AR','ja'=>'JA','ko'=>'KO','zh'=>'ZH','tw'=>'TW',
    'ru'=>'RU','tr'=>'TR','sv'=>'SV','no'=>'NO','da'=>'DA','nl'=>'NL'
  ]}
  {assign var="tg_slots" value=['morning','afternoon','evening','night']}

  {* ── Tableau des salutations ─────────────────────────────────────── *}
  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="save_time_greetings">
    <input type="hidden" name="neria_tab"    value="configure">

    <div style="overflow-x:auto;margin-top:16px;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
          <tr style="background:#f9f6f1;">
            <th style="padding:8px 10px;text-align:left;border-bottom:2px solid #e8d5b0;white-space:nowrap;">{neria_admin key='common.language'}</th>
            {foreach $tg_slots as $slot}
              <th style="padding:8px 10px;text-align:left;border-bottom:2px solid #e8d5b0;white-space:nowrap;">{neria_admin key="configure.tg_slot_`$slot`"}</th>
            {/foreach}
          </tr>
        </thead>
        <tbody>
          {foreach $tg_langs as $code => $label}
            <tr style="border-bottom:1px solid #f0e8d8;">
              <td style="padding:6px 10px;font-weight:600;color:var(--neria-dark);white-space:nowrap;">{$label}</td>
              {foreach $tg_slots as $slot}
                <td style="padding:4px 6px;">
                  <input type="text" name="neria_tg_{$code}_{$slot}" class="neria-input"
                         style="font-size:12px;padding:4px 8px;"
                         value="{$time_greetings[$code][$slot]|default:''|escape:'html'}">
                </td>
              {/foreach}
            </tr>
          {/foreach}
        </tbody>
      </table>
    </div>

    <div id="neria-tg-save-row" style="margin-top:16px;display:flex;align-items:center;gap:12px;">
      <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">{neria_admin key='common.register'}</button>
      <span style="font-size:11px;color:#6b6459;font-style:italic;">
        ✓ {neria_admin key='configure.tg_plugplay_hint'}
      </span>
    </div>
  </form>

  {* ── Réinitialisation ─────────────────────────────────────────── *}
  <div id="neria-tg-reset-panel" style="margin-top:16px;padding:12px 16px;background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
    <span style="font-size:12px;color:#7a6f65;font-weight:600;white-space:nowrap;">🔄 {neria_admin key='configure.tg_reset_label'}</span>

    {* Toutes les langues *}
    <form method="post" action="{$smarty.server.REQUEST_URI}" style="margin:0;">
      <input type="hidden" name="neria_action" value="reset_time_greetings_all">
      <input type="hidden" name="neria_tab"    value="configure">
      <button type="button" class="neria-btn neria-btn--sm"
              data-confirm="{neria_admin key='configure.tg_reset_all_confirm' esc='html'}"
              onclick="neriaConfirmDelete(this);"
              style="background:#dc2626;color:#fff;border-color:#dc2626;">
        {neria_admin key='configure.tg_reset_all_btn'}
      </button>
    </form>

    {* Une langue au choix *}
    <form method="post" action="{$smarty.server.REQUEST_URI}" style="margin:0;display:flex;align-items:center;gap:8px;">
      <input type="hidden" name="neria_action" value="reset_time_greetings_lang">
      <input type="hidden" name="neria_tab"    value="configure">
      <select name="neria_reset_lang" class="neria-input" style="font-size:12px;padding:4px 8px;height:auto;">
        {foreach $lang_labels as $code => $name}
          <option value="{$code}">{$lang_flags[$code]|default:''} {$name}</option>
        {/foreach}
      </select>
      <button type="button" class="neria-btn neria-btn--sm"
              data-confirm="{neria_admin key='configure.tg_reset_lang_confirm' esc='html'}"
              onclick="neriaConfirmDelete(this);"
              style="background:#dc2626;color:#fff;border-color:#dc2626;">
        {neria_admin key='configure.tg_reset_lang_btn'}
      </button>
    </form>
  </div>
  </div>{* /Formules par langue *}
</div>
{/if}

{* ── Smart Fallbacks — prénom manquant ─────────────────────── *}
{if $neria_menu_visible.firstname_fallback}
<div class="neria-section" id="neria-cfg-firstname-fallbacks">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:8px;">
    <h2 class="neria-section__title" style="margin:0;">✦ {neria_admin key='configure.firstname_fallbacks_title'}</h2>
    <form method="post" action="{$smarty.server.REQUEST_URI}" style="margin:0;">
      <input type="hidden" name="neria_action" value="toggle_firstname_fallback">
      <input type="hidden" name="neria_tab"    value="configure">
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if $firstname_fallback_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if $firstname_fallback_enabled}● {neria_admin key='help.cron_toggle_on'}{else}○ {neria_admin key='help.cron_toggle_off'}{/if}
      </button>
    </form>
  </div>
  <p class="neria-section__desc">
    {neria_admin key='configure.firstname_fallbacks_desc_pre'} <code>{ldelim}firstname{rdelim}</code>
    {neria_admin key='configure.firstname_fallbacks_desc_post'}
  </p>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="save_firstname_fallbacks">
    <input type="hidden" name="neria_tab"    value="configure">

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;margin-top:16px;">
      {foreach $lang_labels as $code => $label}
        <div class="neria-form-group" style="margin:0;">
          <label class="neria-label" style="font-size:11px;margin-bottom:4px;">
            {$lang_flags[$code]|default:''} {$label} <span style="color:#6b6459;font-weight:400;">({$code})</span>
          </label>
          <input type="text" name="neria_fallback_{$code}" class="neria-input"
                 placeholder="{$firstname_fallbacks[$code]|default:''|escape:'html'}"
                 value="{$firstname_fallbacks[$code]|default:''|escape:'html'}">
        </div>
      {/foreach}
    </div>

    <div style="margin-top:16px;display:flex;align-items:center;gap:12px;">
      <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
        {neria_admin key='configure.save_fallbacks'}
      </button>
      <span style="font-size:11px;color:#6b6459;font-style:italic;">
        &#9432; {neria_admin key='configure.fallbacks_hint_pre'} <code>{ldelim}firstname{rdelim}</code> {neria_admin key='configure.fallbacks_hint_post'}
      </span>
    </div>
  </form>
</div>
{/if}

{* ── Bons de réduction ──────────────────────────────────────── *}
{if $neria_menu_visible.vouchers}
<div class="neria-section" id="neria-cfg-vouchers">
  <h2 class="neria-section__title">{neria_admin key='configure.voucher_title'}</h2>

  <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin:16px 0;">
    <div style="background:#fff;border:1px solid #e8d5b0;border-radius:8px;padding:14px;">
      <div style="font-size:20px;margin-bottom:6px;">🗓️</div>
      <div style="font-weight:700;font-size:13px;color:#5c3d1e;margin-bottom:4px;">{neria_admin key='configure.voucher_card_validity_title'}</div>
      <div style="font-size:12px;color:#7a6a5a;line-height:1.5;">
        {neria_admin key='configure.voucher_card_validity_desc'}
      </div>
    </div>
    <div style="background:#fff;border:1px solid #e8d5b0;border-radius:8px;padding:14px;">
      <div style="font-size:20px;margin-bottom:6px;">🎂</div>
      <div style="font-weight:700;font-size:13px;color:#5c3d1e;margin-bottom:4px;">{neria_admin key='configure.voucher_card_birthday_title'}</div>
      <div style="font-size:12px;color:#7a6a5a;line-height:1.5;">
        {neria_admin key='configure.voucher_card_birthday_desc'}
      </div>
    </div>
  </div>

  <p class="neria-section__desc">
    {neria_admin key='configure.voucher_desc'}
  </p>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="save_voucher_validity">
    <input type="hidden" name="neria_tab"    value="configure">

    <div style="display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap;">
      <div class="neria-form-group">
        <label class="neria-label" for="neria-voucher-validity">
          {neria_admin key='configure.voucher_validity_label'}
        </label>
        <input type="number" id="neria-voucher-validity" name="neria_voucher_validity"
               class="neria-input" min="1" max="365" style="max-width:140px;"
               value="{$voucher_validity|default:30}">
      </div>

      <div class="neria-form-group">
        <label class="neria-label" for="neria-voucher-fixed-cap">
          {neria_admin key='configure.voucher_fixed_cap_label'}
        </label>
        <input type="number" id="neria-voucher-fixed-cap" name="neria_voucher_fixed_cap"
               class="neria-input" min="1" max="1000000" step="0.01" style="max-width:160px;"
               value="{$voucher_fixed_cap|default:10000}">
        <div style="font-size:11px;color:#7a6a5a;margin-top:4px;max-width:260px;">
          {neria_admin key='configure.voucher_fixed_cap_help'}
        </div>
      </div>
    </div>

    <div style="margin-top:16px;">
      <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
        {neria_admin key='common.register'}
      </button>
    </div>
  </form>

  <hr style="margin:24px 0;border:none;border-top:1px solid #e8d5b0;">

  <h3 style="margin:0 0 6px 0;font-size:13px;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;color:#2c2c2c;">
    {neria_admin key='configure.birthday_voucher_subtitle'}
  </h3>
  <p class="neria-section__desc">
    {neria_admin key='configure.birthday_voucher_desc'}
  </p>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="save_birthday_voucher">
    <input type="hidden" name="neria_tab"    value="configure">

    <div style="display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap;">
      <div class="neria-form-group">
        <label class="neria-label" for="neria-birthday-voucher-amount">
          {neria_admin key='configure.birthday_voucher_amount_label'}
        </label>
        <input type="number" id="neria-birthday-voucher-amount" name="neria_birthday_voucher_amount"
               class="neria-input" min="0" step="0.01" style="max-width:140px;"
               value="{$birthday_voucher_amount|default:10}">
      </div>

      <div class="neria-form-group">
        <label class="neria-label" for="neria-birthday-voucher-percent">
          {neria_admin key='configure.birthday_voucher_type_label'}
        </label>
        <select id="neria-birthday-voucher-percent" name="neria_birthday_voucher_percent"
                class="neria-input" style="max-width:180px;">
          <option value="1" {if $birthday_voucher_percent}selected{/if}>{neria_admin key='configure.birthday_voucher_type_percent'}</option>
          <option value="0" {if !$birthday_voucher_percent}selected{/if}>{neria_admin key='configure.birthday_voucher_type_amount'}</option>
        </select>
      </div>
    </div>

    <div style="margin-top:16px;">
      <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
        {neria_admin key='common.register'}
      </button>
    </div>
  </form>

  <hr style="margin:24px 0;border:none;border-top:1px solid #e8d5b0;">

  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:6px;">
    <h3 style="margin:0;font-size:13px;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;color:#2c2c2c;">
      {neria_admin key='configure.milestone_voucher_subtitle'}
    </h3>
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}" id="neria-milestone-voucher-toggle-form" style="display:inline;">
      <input type="hidden" name="neria_action" value="save_milestone_voucher">
      <input type="hidden" name="neria_tab"    value="configure">
      <input type="hidden" name="neria_milestone_voucher_enabled" id="neria-milestone-voucher-enabled-input" value="{if $milestone_voucher_enabled}1{else}0{/if}">
      <input type="hidden" name="neria_milestone_voucher_amount"  id="neria-milestone-voucher-toggle-amount"  value="{$milestone_voucher_amount|default:10}">
      <input type="hidden" name="neria_milestone_voucher_percent" id="neria-milestone-voucher-toggle-percent" value="{if $milestone_voucher_percent}1{else}0{/if}">
      <button type="button" id="neria-milestone-voucher-toggle-btn"
              onclick="document.getElementById('neria-milestone-voucher-enabled-input').value = document.getElementById('neria-milestone-voucher-enabled-input').value === '1' ? '0' : '1'; this.form.submit();"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if $milestone_voucher_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if $milestone_voucher_enabled}● {neria_admin key='help.cron_toggle_on'}{else}○ {neria_admin key='help.cron_toggle_off'}{/if}
      </button>
    </form>
  </div>
  <p class="neria-section__desc">
    {neria_admin key='configure.milestone_voucher_desc'}
  </p>

  <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin:16px 0;">
    <div style="background:#fff;border:1px solid #e8d5b0;border-radius:8px;padding:14px;">
      <div style="font-size:20px;margin-bottom:6px;">🎯</div>
      <div style="font-weight:700;font-size:13px;color:#5c3d1e;margin-bottom:4px;">{neria_admin key='configure.milestone_voucher_card1_title'}</div>
      <div style="font-size:12px;color:#7a6a5a;line-height:1.5;">
        {neria_admin key='configure.milestone_voucher_card1_desc'}
      </div>
    </div>
    <div style="background:#fff;border:1px solid #e8d5b0;border-radius:8px;padding:14px;">
      <div style="font-size:20px;margin-bottom:6px;">🔗</div>
      <div style="font-weight:700;font-size:13px;color:#5c3d1e;margin-bottom:4px;">{neria_admin key='configure.milestone_voucher_card2_title'}</div>
      <div style="font-size:12px;color:#7a6a5a;line-height:1.5;">
        {neria_admin key='configure.milestone_voucher_card2_desc'}
      </div>
    </div>
  </div>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="save_milestone_voucher">
    <input type="hidden" name="neria_tab"    value="configure">
    <input type="hidden" name="neria_milestone_voucher_enabled" value="{if $milestone_voucher_enabled}1{else}0{/if}">

    <div style="display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap;">
      <div class="neria-form-group">
        <label class="neria-label" for="neria-milestone-voucher-amount">
          {neria_admin key='configure.milestone_voucher_amount_label'}
        </label>
        <input type="number" id="neria-milestone-voucher-amount" name="neria_milestone_voucher_amount"
               class="neria-input" min="0" step="0.01" style="max-width:140px;"
               value="{$milestone_voucher_amount|default:10}">
      </div>

      <div class="neria-form-group">
        <label class="neria-label" for="neria-milestone-voucher-percent">
          {neria_admin key='configure.birthday_voucher_type_label'}
        </label>
        <select id="neria-milestone-voucher-percent" name="neria_milestone_voucher_percent"
                class="neria-input" style="max-width:180px;">
          <option value="1" {if $milestone_voucher_percent}selected{/if}>{neria_admin key='configure.birthday_voucher_type_percent'}</option>
          <option value="0" {if !$milestone_voucher_percent}selected{/if}>{neria_admin key='configure.birthday_voucher_type_amount'}</option>
        </select>
      </div>
    </div>

    <div style="margin-top:16px;">
      <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
        {neria_admin key='common.register'}
      </button>
    </div>
  </form>
</div>
{/if}

{* ── Mode Silence — anti-doublon ────────────────────────────── *}
{if $neria_menu_visible.cooldown}
<div class="neria-section" id="neria-cfg-cooldown">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:8px;">
    <h2 class="neria-section__title" style="margin:0;">{neria_admin key='configure.cooldown_title'}</h2>
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}" style="display:inline;">
      <input type="hidden" name="neria_action" value="toggle_cooldown">
      <input type="hidden" name="neria_tab"    value="configure">
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if $cooldown_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if $cooldown_enabled}● {neria_admin key='help.cron_toggle_on'}{else}○ {neria_admin key='help.cron_toggle_off'}{/if}
      </button>
    </form>
  </div>
  <p class="neria-section__desc">{neria_admin key='configure.cooldown_desc'}</p>

  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:16px 0;">
    <div style="background:#fff;border:1px solid #e8d5b0;border-radius:8px;padding:14px;">
      <div style="font-size:20px;margin-bottom:6px;">🛡️</div>
      <div style="font-weight:700;font-size:13px;color:#5c3d1e;margin-bottom:4px;">{neria_admin key='configure.cooldown_card1_title'}</div>
      <div style="font-size:12px;color:#7a6a5a;line-height:1.5;">
        {neria_admin key='configure.cooldown_card1_desc'}
      </div>
    </div>
    <div style="background:#fff;border:1px solid #e8d5b0;border-radius:8px;padding:14px;">
      <div style="font-size:20px;margin-bottom:6px;">⏱️</div>
      <div style="font-weight:700;font-size:13px;color:#5c3d1e;margin-bottom:4px;">{neria_admin key='configure.cooldown_card2_title'}</div>
      <div style="font-size:12px;color:#7a6a5a;line-height:1.5;">
        {neria_admin key='configure.cooldown_card2_desc'}
      </div>
    </div>
    <div style="background:#fff;border:1px solid #e8d5b0;border-radius:8px;padding:14px;">
      <div style="font-size:20px;margin-bottom:6px;">📊</div>
      <div style="font-weight:700;font-size:13px;color:#5c3d1e;margin-bottom:4px;">{neria_admin key='configure.cooldown_card3_title'}</div>
      <div style="font-size:12px;color:#7a6a5a;line-height:1.5;">
        {neria_admin key='configure.cooldown_card3_desc'}
      </div>
    </div>
  </div>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="save_cooldown">
    <input type="hidden" name="neria_tab"    value="configure">

    <div class="neria-form-group" style="margin-top:12px;">
      <label class="neria-label" for="neria-cooldown-minutes">
        {neria_admin key='configure.cooldown_minutes_label'}
      </label>
      <div style="display:flex;align-items:center;gap:8px;">
        <input type="number" id="neria-cooldown-minutes" name="neria_cooldown_minutes"
               class="neria-input" min="1" max="60" style="max-width:100px;"
               value="{$cooldown_minutes|default:10}">
        <span style="font-size:13px;color:var(--neria-text-light);">{neria_admin key='configure.cooldown_minutes_unit'}</span>
      </div>
      <p class="neria-hint">{neria_admin key='configure.cooldown_hint'}</p>
    </div>

    <div style="margin-top:16px;">
      <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
        {neria_admin key='common.register'}
      </button>
    </div>
  </form>

  <div style="margin-top:20px;padding-top:16px;border-top:1px solid #e8d5b0;">
    <h3 style="font-size:14px;font-weight:700;color:#5c3d1e;margin:0 0 8px;">📬 {neria_admin key='configure.smtp_quota_title'}</h3>
    <p style="font-size:13px;color:#7a6a5a;margin:0 0 12px;line-height:1.6;">
      {neria_admin key='configure.smtp_quota_desc1'}
      {neria_admin key='configure.smtp_quota_desc2'}
    </p>

    <div style="background:#fef9f0;border:1px solid #e8d5b0;border-radius:8px;padding:12px 14px;margin-bottom:14px;font-size:12px;color:#7a6a5a;line-height:1.6;">
      💡 <strong>{neria_admin key='configure.smtp_quota_howto_title'}</strong><br>
      {neria_admin key='configure.smtp_quota_howto_body'}
    </div>

    <form method="post" action="{$smarty.server.REQUEST_URI}">
      <input type="hidden" name="neria_action" value="save_smtp_quota">
      <input type="hidden" name="neria_tab"    value="configure">
      <div class="neria-form-group">
        <label class="neria-label" for="neria-smtp-quota">{neria_admin key='configure.smtp_quota_label'}</label>
        <div style="display:flex;align-items:center;gap:8px;">
          <input type="number" id="neria-smtp-quota" name="neria_smtp_quota"
                 class="neria-input" min="0" style="max-width:120px;"
                 value="{$smtp_daily_quota|default:0}">
          <span style="font-size:13px;color:var(--neria-text-light);">{neria_admin key='configure.smtp_quota_unit'}</span>
        </div>
      </div>
      <div style="margin-top:12px;">
        <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
          {neria_admin key='common.register'}
        </button>
      </div>
    </form>
  </div>
</div>
{/if}

{* ── Témoin silencieux (archive BCC) ───────────────────────── *}
{if $neria_menu_visible.silent_witness}
<div class="neria-section" id="neria-cfg-archive">
  <h2 class="neria-section__title">✦ {neria_admin key='configure.archive_title'}</h2>
  <p class="neria-section__desc">{neria_admin key='configure.archive_desc'}</p>

  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin:16px 0;font-size:13px;line-height:1.75;color:#4a3f35;">
    {neria_admin key='configure.archive_how'}
    <div style="margin-top:14px;padding-top:12px;border-top:1px solid #e8d5b0;font-size:12px;opacity:.75;">
      <strong>{neria_admin key='help.cron_tip_label'} :</strong> {neria_admin key='configure.archive_privacy_note'}
    </div>
  </div>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="save_archive_config">
    <input type="hidden" name="neria_tab"    value="configure">
    <div class="neria-form-group">
      <label class="neria-label" for="neria-archive-email">{neria_admin key='configure.archive_email_label'}</label>
      <input type="email" id="neria-archive-email" name="neria_archive_email"
             class="neria-input" style="max-width:340px;"
             placeholder="archive@maboutique.com"
             value="{$archive_email|default:''|escape:'html'}">
      <p class="neria-hint">{neria_admin key='configure.archive_email_hint'}</p>
    </div>
    <div style="margin-top:12px;">
      <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
        {neria_admin key='common.register'}
      </button>
    </div>
  </form>
</div>
{/if}

{* ── Empreinte carbone ──────────────────────────────────── *}
{if $neria_menu_visible.carbon}
<div class="neria-section" id="neria-cfg-carbon">
  <h2 class="neria-section__title">{neria_admin key='configure.carbon_title'}</h2>
  <p class="neria-section__desc">{neria_admin key='configure.carbon_desc'}</p>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="save_carbon">
    <input type="hidden" name="neria_tab"    value="configure">

    <div class="neria-form-group">
      <input type="hidden" name="neria_carbon_enabled" value="0">
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;color:var(--neria-text);">
        <input type="checkbox" name="neria_carbon_enabled" value="1"
               style="width:16px;height:16px;cursor:pointer;"
               {if $carbon_enabled}checked{/if}>
        <span>{neria_admin key='configure.carbon_enabled_label'}</span>
      </label>
    </div>

    <div class="neria-form-group" style="margin-top:14px; max-width:480px;">
      <label class="neria-label" for="neria-carbon-link">
        {neria_admin key='configure.carbon_link_label'}
        <span class="neria-hint">{neria_admin key='configure.carbon_link_hint'}</span>
      </label>
      <input type="url" id="neria-carbon-link" name="neria_carbon_link"
             class="neria-input" placeholder="https://…"
             value="{$carbon_link|default:''|escape:'html'}">
    </div>

    <p class="neria-hint" style="margin-top:10px;">
      {neria_admin key='configure.carbon_example'}
    </p>

    <div style="margin-top:16px;">
      <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
        {neria_admin key='common.register'}
      </button>
    </div>
  </form>
</div>
{/if}

{* ── Multi-expéditeur par langue ────────────────────────── *}
{if $neria_menu_visible.multi_sender}
<div class="neria-section" id="neria-cfg-senders">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:8px;">
    <h2 class="neria-section__title" style="margin:0;">{neria_admin key='configure.senders_title'}</h2>
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}" style="display:inline;">
      <input type="hidden" name="neria_action" value="toggle_multi_sender">
      <input type="hidden" name="neria_tab"    value="configure">
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if $multi_sender_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if $multi_sender_enabled}● {neria_admin key='help.cron_toggle_on'}{else}○ {neria_admin key='help.cron_toggle_off'}{/if}
      </button>
    </form>
  </div>
  <p class="neria-section__desc">{neria_admin key='configure.senders_desc'}</p>

  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin-bottom:24px;font-size:13px;line-height:1.75;color:#4a3f35;">
    <div style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;opacity:.6;margin-bottom:10px;">{neria_admin key='configure.senders_why_title'}</div>
    <p style="margin:0 0 10px;">{neria_admin key='configure.senders_why_body'}</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-top:14px;">
      <div style="background:#fff;border:1px solid #e8d5b0;border-radius:5px;padding:12px 14px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;opacity:.5;margin-bottom:4px;">{neria_admin key='configure.senders_card1_title'}</div>
        <div style="font-size:13px;">{neria_admin key='configure.senders_card1_desc'}</div>
      </div>
      <div style="background:#fff;border:1px solid #e8d5b0;border-radius:5px;padding:12px 14px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;opacity:.5;margin-bottom:4px;">{neria_admin key='configure.senders_card2_title'}</div>
        <div style="font-size:13px;">{neria_admin key='configure.senders_card2_desc'}</div>
      </div>
      <div style="background:#fff;border:1px solid #e8d5b0;border-radius:5px;padding:12px 14px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;opacity:.5;margin-bottom:4px;">{neria_admin key='configure.senders_card3_title'}</div>
        <div style="font-size:13px;">{neria_admin key='configure.senders_card3_desc'}</div>
      </div>
    </div>
    <div style="margin-top:14px;padding-top:12px;border-top:1px solid #e8d5b0;font-size:12px;color:#4a3f35;">
      💡 {neria_admin key='configure.senders_tip_pre'}
      <a href="{$smarty.server.REQUEST_URI|regex_replace:'/&neria_tab=[^&]*/':''}&neria_tab=stats#neria-domain-rep"
         style="color:#b38b59;font-weight:700;text-decoration:underline;">
        → {neria_admin key='configure.senders_tip_link'}
      </a>
    </div>
  </div>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="save_senders">
    <input type="hidden" name="neria_tab"    value="configure">

    <table style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead>
        <tr style="border-bottom:2px solid var(--neria-border);">
          <th style="text-align:left;padding:8px 10px;color:var(--neria-muted);font-weight:600;width:200px;">{neria_admin key='configure.senders_col_lang'}</th>
          <th style="text-align:left;padding:8px 10px;color:var(--neria-muted);font-weight:600;">{neria_admin key='configure.senders_col_name'}</th>
          <th style="text-align:left;padding:8px 10px;color:var(--neria-muted);font-weight:600;">{neria_admin key='configure.senders_col_email'}</th>
        </tr>
      </thead>
      <tbody>
        {foreach from=$lang_labels key=iso item=label}
        <tr style="border-bottom:1px solid var(--neria-border);">
          <td style="padding:8px 10px;color:var(--neria-text);white-space:nowrap;">
            {if isset($lang_flags[$iso])}{$lang_flags[$iso]}{/if} {$label}
          </td>
          <td style="padding:6px 10px;">
            <input type="text"
                   name="neria_sender_name_{$iso}"
                   value="{if isset($senders_config[$iso]) && isset($senders_config[$iso].name)}{$senders_config[$iso].name|escape:'html'}{/if}"
                   placeholder="{neria_admin key='configure.senders_name_placeholder'}"
                   class="neria-input" style="width:100%;max-width:260px;">
          </td>
          <td style="padding:6px 10px;">
            <input type="email"
                   name="neria_sender_email_{$iso}"
                   value="{if isset($senders_config[$iso]) && isset($senders_config[$iso].email)}{$senders_config[$iso].email|escape:'html'}{/if}"
                   placeholder="contact@votreboutique.fr"
                   class="neria-input" style="width:100%;max-width:280px;">
          </td>
        </tr>
        {/foreach}
      </tbody>
    </table>

    <p class="neria-hint" style="margin-top:12px;">{neria_admin key='configure.senders_spf_hint'}</p>

    <div style="margin-top:16px;">
      <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
        {neria_admin key='common.register'}
      </button>
    </div>
  </form>
</div>
{/if}

{* ── Blacklist templates ─────────────────────────────────── *}
{if $neria_menu_visible.blacklist}
<div class="neria-section" id="neria-cfg-blacklist">
  <h2 class="neria-section__title">{neria_admin key='configure.blacklist_title'}</h2>
  <p class="neria-section__desc">{neria_admin key='configure.blacklist_desc'}</p>

  {* Formulaire d'ajout *}
  <form method="post" action="{$smarty.server.REQUEST_URI}" style="margin-bottom:20px;">
    <input type="hidden" name="neria_action" value="add_blacklist">
    <input type="hidden" name="neria_tab"    value="configure">
    <div style="display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;">
      <div>
        <label class="neria-label" for="neria-bl-template">{neria_admin key='common.template'}</label>
        <select id="neria-bl-template" name="neria_bl_template" class="neria-select" style="max-width:280px;">
          <option value="">— {neria_admin key='configure.blacklist_choose_template'} —</option>
          {foreach $template_labels as $code => $label}
            <option value="{$code}">{$label} ({$code})</option>
          {/foreach}
        </select>
      </div>
      <div>
        <label class="neria-label" for="neria-bl-lang">{neria_admin key='configure.blacklist_language'}</label>
        <select id="neria-bl-lang" name="neria_bl_lang" class="neria-select" style="max-width:200px;">
          <option value="">{neria_admin key='configure.blacklist_all_langs'}</option>
          {foreach $lang_labels as $code => $name}
            <option value="{$code}">{$lang_flags[$code]|default:''} {$name}</option>
          {/foreach}
        </select>
      </div>
      <div style="display:flex;gap:8px;align-items:flex-end;">
        <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
          + {neria_admin key='configure.blacklist_add'}
        </button>
        {if $blacklist}
        <button type="button" class="neria-btn neria-btn--ghost neria-btn--sm"
                style="color:var(--neria-error);border-color:var(--neria-error);"
                onclick="var f=this.closest('form'); neriaConfirmAction('{neria_admin key='configure.blacklist_reset_confirm' esc='javascript'}', function(){ f.querySelector('[name=neria_action]').value='reset_blacklist'; f.submit(); });">
          {neria_admin key='configure.blacklist_reset'}
        </button>
        {/if}
      </div>
    </div>
  </form>

  {* Tableau des règles actives *}
  {if $blacklist}
    <div class="neria-table-wrap">
      <table class="neria-table neria-blacklist-table">
        <colgroup>
          <col style="width:220px;">
          <col style="width:160px;">
          <col style="width:150px;">
          <col style="width:60px;">
        </colgroup>
        <thead>
          <tr>
            <th>{neria_admin key='common.template'}</th>
            <th>{neria_admin key='configure.blacklist_language'}</th>
            <th>{neria_admin key='common.date'}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {foreach $blacklist as $rule}
            <tr>
              <td>
                <code style="font-size:12px;">{$rule.template}</code>
                {if isset($template_labels[$rule.template])}
                  <span style="font-size:11px;color:var(--neria-text-light);display:block;">
                    {$template_labels[$rule.template]}
                  </span>
                {/if}
              </td>
              <td>
                {if $rule.lang === ''}
                  <span class="neria-badge neria-badge--neutral">{neria_admin key='configure.blacklist_all_langs'}</span>
                {else}
                  <span>{$lang_flags[$rule.lang]|default:''} {$lang_labels[$rule.lang]|default:$rule.lang}</span>
                {/if}
              </td>
              <td style="font-size:12px;color:var(--neria-text-light);">{$rule.date_add|date_format:'%d/%m/%Y'}</td>
              <td>
                <form method="post" action="{$smarty.server.REQUEST_URI}">
                  <input type="hidden" name="neria_action" value="remove_blacklist">
                  <input type="hidden" name="neria_tab"    value="configure">
                  <input type="hidden" name="neria_bl_id"  value="{$rule.id_blacklist}">
                  <button type="submit" class="neria-btn neria-btn--ghost neria-btn--sm"
                          style="color:var(--neria-error);border-color:var(--neria-error);"
                          title="{neria_admin key='configure.blacklist_remove'}">✕</button>
                </form>
              </td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    </div>
  {else}
    <p style="font-size:13px;color:var(--neria-text-light);font-style:italic;">
      {neria_admin key='configure.blacklist_empty'}
    </p>
  {/if}
</div>
{/if}

{* ── Rapport mensuel automatique ───────────────────────────── *}
{if $neria_menu_visible.monthly_report}
<div class="neria-section" id="neria-cfg-report">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:8px;">
    <h2 class="neria-section__title" style="margin:0;">{neria_admin key='configure.report_title'}</h2>
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}" style="display:inline;">
      <input type="hidden" name="neria_action" value="toggle_report">
      <input type="hidden" name="neria_tab"    value="configure">
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if $report_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if $report_enabled}● {neria_admin key='help.cron_toggle_on'}{else}○ {neria_admin key='help.cron_toggle_off'}{/if}
      </button>
    </form>
  </div>
  <p class="neria-section__desc">{neria_admin key='configure.report_desc'}</p>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="save_report_config">
    <input type="hidden" name="neria_tab"    value="configure">

    <div class="neria-form-group" style="margin-top:12px;">
      <label class="neria-label" for="neria-report-recipients">
        {neria_admin key='configure.report_recipients_label'}
      </label>
      <input type="email" id="neria-report-recipients" name="neria_report_recipients"
             class="neria-input" style="max-width:380px;"
             placeholder="{neria_admin key='configure.report_recipients_placeholder'}"
             value="{$report_recipients|default:''|escape:'html'}">
      <p class="neria-hint">{neria_admin key='configure.report_recipients_hint'}</p>
    </div>

    <div style="margin-top:16px;display:flex;align-items:center;gap:12px;">
      <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
        {neria_admin key='common.register'}
      </button>
      {if $report_last_sent}
      <span class="neria-badge neria-badge--success">
        {neria_admin key='configure.report_last_sent'} {$report_last_sent}
      </span>
      {/if}
    </div>
  </form>

  <form method="post" action="{$smarty.server.REQUEST_URI}" style="margin-top:16px;">
    <input type="hidden" name="neria_action" value="send_report_now">
    <input type="hidden" name="neria_tab"    value="configure">
    <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
      {neria_admin key='configure.report_send_now'}
    </button>
  </form>
</div>
{/if}

{* ── Prochaines occasions calendaires ───────────────────────── *}
{* $upcoming_events est assignée par neria.php via CalendarManager::getUpcomingDates() *}
{if $neria_menu_visible.upcoming_events}
{if isset($upcoming_events) && $upcoming_events|@count > 0}
<div class="neria-section" id="neria-cfg-upcoming">
  <h2 class="neria-section__title">
    {neria_admin key='configure.upcoming_title'}
  </h2>
  <div class="neria-upcoming">
    {foreach $upcoming_events as $event}
      <div class="neria-upcoming__item {if $event.already_sent}neria-upcoming__item--sent{/if}">
        <div class="neria-upcoming__days">
          {if $event.already_sent}
            <span class="neria-badge neria-badge--success">✓</span>
          {else}
            <strong>J-{$event.days_until}</strong>
          {/if}
        </div>
        <div class="neria-upcoming__info">
          <span class="neria-upcoming__event">{$event.event_key}</span>
          <span class="neria-upcoming__lang">[{$event.lang}]</span>
        </div>
        <div class="neria-upcoming__date">{$event.send_date}</div>
        <div class="neria-upcoming__source">
          <span class="neria-badge neria-badge--{if $event.date_source === 'manuel'}accent{else}neutral{/if}">
            {$event.date_source}
          </span>
        </div>
      </div>
    {/foreach}
  </div>
</div>
{/if}
{/if}

{* ── Variables personnalisées ───────────────────────────────── *}
{if $neria_menu_visible.custom_vars}
<div class="neria-section" id="neria-cfg-customvars">
  <h2 class="neria-section__title">
    {neria_admin key='configure.customvars_title'}
  </h2>
  <p class="neria-section__desc">
    {neria_admin key='configure.customvars_desc'}
  </p>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="save_custom_vars">
    <input type="hidden" name="neria_tab"    value="configure">

    <div class="neria-form-grid">

      <div class="neria-form-group">
        <label class="neria-label" for="maison_name">
          {neria_admin key='configure.var_maison_name'}
          <span class="neria-var-tag">{literal}{maison_name}{/literal}</span>
        </label>
        <input type="text" id="maison_name" name="maison_name"
               class="neria-input"
               value="{$custom_vars.maison_name|default:''|escape:'html'}"
               placeholder="{neria_admin key='configure.ph_maison_name'}">
      </div>

      <div class="neria-form-group">
        <label class="neria-label" for="slogan">
          {neria_admin key='configure.var_slogan'}
          <span class="neria-var-tag">{literal}{slogan}{/literal}</span>
        </label>
        <input type="text" id="slogan" name="slogan"
               class="neria-input"
               value="{$custom_vars.slogan|default:''|escape:'html'}"
               placeholder="{neria_admin key='configure.ph_slogan'}">
      </div>

      <div class="neria-form-group">
        <label class="neria-label" for="founder_name">
          {neria_admin key='configure.var_founder_name'}
          <span class="neria-var-tag">{literal}{founder_name}{/literal}</span>
        </label>
        <input type="text" id="founder_name" name="founder_name"
               class="neria-input"
               value="{$custom_vars.founder_name|default:''|escape:'html'}"
               placeholder="{neria_admin key='configure.ph_founder_name'}">
      </div>

      <div class="neria-form-group">
        <label class="neria-label" for="founder_title">
          {neria_admin key='configure.var_founder_title'}
          <span class="neria-var-tag">{literal}{founder_title}{/literal}</span>
        </label>
        <input type="text" id="founder_title" name="founder_title"
               class="neria-input"
               value="{$custom_vars.founder_title|default:''|escape:'html'}"
               placeholder="{neria_admin key='configure.ph_founder_title'}">
      </div>

      <div class="neria-form-group neria-form-group--full">
        <label class="neria-label" for="signature_closing">
          {neria_admin key='configure.var_signature_closing'}
          <span class="neria-var-tag">{literal}{signature_closing}{/literal}</span>
        </label>
        <input type="text" id="signature_closing" name="signature_closing"
               class="neria-input"
               value="{$custom_vars.signature_closing|default:''|escape:'html'}"
               placeholder="{neria_admin key='configure.ph_signature_closing'}">
      </div>

      <div class="neria-form-group neria-form-group--full">
        <label class="neria-label" for="return_address">
          {neria_admin key='configure.var_return_address'}
          <span class="neria-var-tag">{literal}{return_address}{/literal}</span>
        </label>
        <textarea id="return_address" name="return_address"
                  class="neria-input" rows="3"
                  placeholder="{neria_admin key='configure.ph_return_address'}">{$custom_vars.return_address|default:''|escape:'html'}</textarea>
      </div>

      <div class="neria-form-group">
        <label class="neria-label" for="return_deadline_days">
          {neria_admin key='configure.var_return_deadline'}
          <span class="neria-var-tag">{literal}{return_deadline_days}{/literal}</span>
        </label>
        <input type="text" id="return_deadline_days" name="return_deadline_days"
               class="neria-input"
               value="{$custom_vars.return_deadline_days|default:''|escape:'html'}"
               placeholder="{neria_admin key='configure.ph_return_deadline'}">
      </div>

      <div class="neria-form-group">
        <label class="neria-label" for="return_processing_days">
          {neria_admin key='configure.var_return_processing'}
          <span class="neria-var-tag">{literal}{return_processing_days}{/literal}</span>
        </label>
        <input type="text" id="return_processing_days" name="return_processing_days"
               class="neria-input"
               value="{$custom_vars.return_processing_days|default:''|escape:'html'}"
               placeholder="{neria_admin key='configure.ph_return_processing'}">
      </div>

    </div>

    <div class="neria-form-actions">
      <button type="submit" class="neria-btn neria-btn--primary">
        {neria_admin key='common.save'}
      </button>
    </div>
  </form>
</div>
{/if}

{* ── Signature manuscrite ───────────────────────────────────── *}
{if $neria_menu_visible.signature}
<div class="neria-section" id="neria-cfg-signature">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:8px;">
    <h2 class="neria-section__title" style="margin:0;">{neria_admin key='configure.signature_title'}</h2>
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}" style="display:inline;">
      <input type="hidden" name="neria_action" value="toggle_signature">
      <input type="hidden" name="neria_tab"    value="configure">
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if $signature_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if $signature_enabled}● {neria_admin key='help.cron_toggle_on'}{else}○ {neria_admin key='help.cron_toggle_off'}{/if}
      </button>
    </form>
  </div>
  <p class="neria-section__desc">
    {neria_admin key='configure.signature_desc'}
  </p>

  <form method="post" action="{$smarty.server.REQUEST_URI}" id="neria-signature-form">
    <input type="hidden" name="neria_action" value="generate_signature">
    <input type="hidden" name="neria_tab"    value="configure">

    <div class="neria-form-grid">

      <div class="neria-form-group">
        <label class="neria-label" for="neria-sig-style">
          {neria_admin key='configure.signature_style'}
        </label>
        {* Fix 2 : un seul id="neria-sig-style", name="sig_style" pour le POST *}
        <select id="neria-sig-style" name="sig_style" class="neria-select">
          {foreach $signature_styles as $key => $label}
            <option value="{$key}"
              {if isset($current_signature.style) && $current_signature.style === $key}selected{/if}>
              {$label}
            </option>
          {/foreach}
        </select>
      </div>

      <div class="neria-form-group">
        <label class="neria-label" for="sig_color">
          {neria_admin key='common.color'}
        </label>
        <div class="neria-color-input-wrap">
          <input type="color" id="sig_color" name="sig_color"
                 class="neria-color-picker"
                 value="{$current_signature.color|default:'#b38b59'}">
          <input type="text"
                 class="neria-input neria-input--hex"
                 value="{$current_signature.color|default:'#b38b59'}"
                 data-sync="sig_color">
        </div>
      </div>

    </div>

    <div class="neria-signature-preview" id="neria-sig-preview">
      {if isset($current_signature.url) && $current_signature.url}
        <img src="{$current_signature.url}"
             alt="{neria_admin key='common.signature'}"
             class="neria-signature-preview__img">
      {else}
        <span class="neria-signature-preview__placeholder">
          {neria_admin key='configure.signature_placeholder'}
        </span>
      {/if}
    </div>

    <div class="neria-form-actions">
      <button type="button" class="neria-btn neria-btn--ghost"
              id="neria-sig-preview-btn">
        {neria_admin key='common.preview'}
      </button>
      <button type="submit" class="neria-btn neria-btn--primary">
        {neria_admin key='configure.signature_generate'}
      </button>
    </div>

  </form>
</div>
{/if}

{* ── Centre de préférences email ───────────────────────────── *}
{if $neria_menu_visible.preferences}
<div class="neria-section" id="neria-cfg-preferences">
  <h2 class="neria-section__title">{neria_admin key='configure.preferences_title'}</h2>
  <p class="neria-section__desc">{neria_admin key='configure.preferences_desc'}</p>

  {if $prefs_stats}
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;margin-bottom:24px;">
    {foreach ['cart','post','loyalty','behav','season','b2b','newsletter'] as $cat}
      {assign var="s" value=$prefs_stats[$cat]}
      <div style="background:#fff;border:1px solid var(--neria-border);border-radius:8px;padding:14px 16px;text-align:center;">
        <div style="font-size:22px;font-weight:700;color:{if $s.opted_out > 0}#dc2626{else}#16a34a{/if};">{$s.opted_out|intval}</div>
        <div style="font-size:11px;color:var(--neria-muted);margin-top:2px;">{neria_admin key='configure.pref_optout_label'}</div>
        <div style="font-size:12px;font-weight:600;color:var(--neria-text);margin-top:6px;">{neria_admin key="configure.pref_cat_`$cat`"}</div>
        {if $s.total > 0}
        <div style="font-size:10px;color:#aaa;margin-top:2px;">{neria_admin key='configure.pref_of_pre'} {$s.total|intval} {neria_admin key='configure.pref_of_post'}</div>
        {/if}
      </div>
    {/foreach}
  </div>
  {/if}

  {if $prefs_recent}
  <p style="font-size:12px;font-weight:700;color:var(--neria-text);margin:0 0 12px 0;text-transform:uppercase;letter-spacing:.06em;">{neria_admin key='configure.pref_recent_title'}</p>
  <div style="overflow-x:auto;">
    <table class="neria-table" style="min-width:460px;">
      <thead>
        <tr><th>{neria_admin key='configure.pref_col_customer'}</th><th>{neria_admin key='configure.pref_col_optout'}</th><th>{neria_admin key='configure.pref_col_modified'}</th></tr>
      </thead>
      <tbody>
        {foreach $prefs_recent as $r}
        <tr>
          <td>
            <span style="font-size:13px;font-weight:600;">{$r.firstname|escape:'html'} {$r.lastname|escape:'html'}</span><br>
            <span style="font-size:11px;color:var(--neria-muted);">{$r.email|escape:'html'}</span>
          </td>
          <td style="text-align:center;">
            {if $r.nb_optout > 0}
              <span style="font-size:13px;font-weight:700;color:#dc2626;">{$r.nb_optout|intval} {if $r.nb_optout > 1}{neria_admin key='configure.pref_category_plural'}{else}{neria_admin key='configure.pref_category_singular'}{/if}</span>
            {else}
              <span style="font-size:12px;color:#16a34a;">✓ {neria_admin key='configure.pref_all_active'}</span>
            {/if}
          </td>
          <td style="font-size:12px;color:var(--neria-muted);">{$r.date_upd|escape:'html'}</td>
        </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
  {else}
  <p style="font-size:13px;color:var(--neria-muted);font-style:italic;">{neria_admin key='configure.pref_empty'}</p>
  {/if}
</div>
{/if}

{* ── Section Programme de Fidélité ───────────────────────── *}
{if $neria_menu_visible.loyalty}
<div class="neria-section" id="neria-loyalty-section">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
      <h2 class="neria-section__title" style="margin:0 0 4px 0;">{neria_admin key='configure.loyalty_title'}</h2>
      <p class="neria-section__desc" style="margin:0;">
        {neria_admin key='configure.loyalty_desc'}
      </p>
    </div>
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}" style="display:inline;">
      <input type="hidden" name="neria_action" value="loyalty_toggle">
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if $loyalty_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if $loyalty_enabled}● {neria_admin key='help.cron_toggle_on'}{else}○ {neria_admin key='help.cron_toggle_off'}{/if}
      </button>
    </form>
  </div>

  {if $loyalty_enabled && $loyalty_global_stats}
  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px,1fr)); gap:12px; margin-bottom:28px;">
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$loyalty_global_stats.active_customers|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='configure.loyalty_kpi_active_customers'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$loyalty_global_stats.total_points|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='configure.loyalty_kpi_points_distributed'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$loyalty_global_stats.rewards_sent|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='configure.loyalty_kpi_rewards_sent'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$loyalty_global_stats.cnt_open|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='configure.loyalty_kpi_opens'}</div>
      <div class="neria-kpi__rate">+1 pt</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$loyalty_global_stats.cnt_click|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='configure.loyalty_kpi_clicks'}</div>
      <div class="neria-kpi__rate">+3 pts</div>
    </div>
    <div class="neria-kpi neria-kpi--main">
      <div class="neria-kpi__value">{$loyalty_global_stats.cnt_conversion|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='configure.loyalty_kpi_conversions'}</div>
      <div class="neria-kpi__rate">+10 pts</div>
    </div>
  </div>
  {/if}

  <div class="neria-notice" style="background:#f9f6f1; border:1px solid #e8d5b0; border-radius:6px; padding:20px 24px; margin-bottom:24px;">
    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
      <div>
        <p style="font-size:12px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; opacity:.6; margin:0 0 6px 0;">
          {neria_admin key='configure.loyalty_cross_shop_title'}
        </p>
        <p style="font-size:13px; line-height:1.75; color:#4a3f35; margin:0;">
          {neria_admin key='configure.loyalty_cross_shop_desc'}
        </p>
      </div>
      <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}" style="display:inline;flex-shrink:0;">
        <input type="hidden" name="neria_action" value="loyalty_cross_shop_toggle">
        <input type="hidden" name="neria_tab"    value="configure">
        <button type="submit"
                style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                       background:{if $loyalty_cross_shop_enabled}#1a7a40{else}#c0392b{/if};
                       color:#fff; border:none; border-radius:4px; font-size:12px;
                       font-weight:700; cursor:pointer; letter-spacing:.04em;">
          {if $loyalty_cross_shop_enabled}● {neria_admin key='configure.loyalty_cross_shop_on'}{else}○ {neria_admin key='configure.loyalty_cross_shop_off'}{/if}
        </button>
      </form>
    </div>
  </div>

  <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}">
    <input type="hidden" name="neria_action" value="save_loyalty_tiers">

    <p style="font-size:12px; font-weight:700; color:var(--neria-text); margin:0 0 14px 0; text-transform:uppercase; letter-spacing:.06em;">
      {neria_admin key='configure.loyalty_tiers_title'}
    </p>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px,1fr)); gap:16px; margin-bottom:20px;">
      {foreach $loyalty_tiers as $tier}
      <div style="padding:16px; background:var(--neria-bg); border-radius:6px; border:1px solid var(--neria-border);">
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:14px;">
          {if $tier.key === 'bronze'}<span style="font-size:18px; line-height:1;">&#127949;</span>
          {elseif $tier.key === 'silver'}<span style="font-size:18px; line-height:1;">&#127948;</span>
          {else}<span style="font-size:18px; line-height:1;">&#127947;</span>{/if}
          <input type="text" name="loyalty_name_{$tier.key|escape:'html'}"
                 value="{$tier.name|escape:'html'}"
                 style="flex:1; padding:6px 10px; border:1px solid var(--neria-border); border-radius:4px;
                        font-size:14px; font-weight:600; background:var(--neria-container);">
        </div>
        <div style="margin-bottom:10px;">
          <label style="font-size:11px; color:var(--neria-muted); display:block; margin-bottom:4px;">{neria_admin key='configure.loyalty_threshold_label'}</label>
          <input type="number" name="loyalty_points_{$tier.key|escape:'html'}"
                 value="{$tier.points|intval}"
                 min="1" step="1"
                 style="width:100%; padding:7px 10px; border:1px solid var(--neria-border); border-radius:4px;
                        font-size:13px; background:var(--neria-container); box-sizing:border-box;">
        </div>
        <div style="margin-bottom:10px;">
          <label style="font-size:11px; color:var(--neria-muted); display:block; margin-bottom:4px;">{neria_admin key='configure.loyalty_reward_label'}</label>
          <input type="number" name="loyalty_amount_{$tier.key|escape:'html'}"
                 value="{$tier.amount|string_format:"%.2f"}"
                 min="0.01" step="0.01"
                 style="width:100%; padding:7px 10px; border:1px solid var(--neria-border); border-radius:4px;
                        font-size:13px; background:var(--neria-container); box-sizing:border-box;">
        </div>
        <label style="display:flex; align-items:center; gap:8px; font-size:12px; color:var(--neria-text); cursor:pointer;">
          <input type="checkbox" name="loyalty_percent_{$tier.key|escape:'html'}"
                 value="1" {if $tier.is_percent}checked{/if}>
          {neria_admin key='configure.loyalty_percent_label_pre'} {$currency_symbol})
        </label>
      </div>
      {/foreach}
    </div>

    <div class="neria-form-actions">
      <button type="submit" class="neria-btn neria-btn--primary">{neria_admin key='configure.save_tiers'}</button>
    </div>
  </form>

  {if $loyalty_enabled && $loyalty_top_customers}
  <div style="margin-top:28px;">
    <p style="font-size:12px; font-weight:700; color:var(--neria-text); margin:0 0 12px 0; text-transform:uppercase; letter-spacing:.06em;">
      {neria_admin key='configure.loyalty_top10_title'}
    </p>
    <div style="overflow-x:auto;">
      <table class="neria-table" style="min-width:400px;">
        <thead>
          <tr><th>{neria_admin key='configure.loyalty_col_rank'}</th><th>{neria_admin key='configure.pref_col_customer'}</th><th>{neria_admin key='configure.loyalty_col_points'}</th></tr>
        </thead>
        <tbody>
          {foreach $loyalty_top_customers as $c}
          <tr>
            <td style="font-size:12px; color:var(--neria-muted); text-align:center;">{$c@iteration}</td>
            <td>
              <span style="font-size:13px; font-weight:600;">{$c.firstname|escape:'html'} {$c.lastname|escape:'html'}</span><br>
              <span style="font-size:11px; color:var(--neria-muted);">{$c.email|escape:'html'}</span>
            </td>
            <td style="font-size:16px; font-weight:700; color:var(--neria-accent); text-align:right;">{$c.total|intval}</td>
          </tr>
          {/foreach}
        </tbody>
      </table>
    </div>
  </div>
  {/if}

</div>
{/if}

