{**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — seasonal.tpl
 * Campagnes saisonnières automatiques
 *}

{assign var="base_url" value=$smarty.server.REQUEST_URI|regex_replace:'/&neria_action=[^&]*/':''}
{assign var="base_url" value=$base_url|regex_replace:'/&id_campaign=[^&]*/':''}
{assign var="base_url" value=$base_url|regex_replace:'/&edit_campaign=[^&]*/':''}
{assign var="base_url" value=$base_url|regex_replace:'/&neria_tab=[^&]*/':''}
{assign var="tab_url"  value="{$base_url}&neria_tab=seasonal"}

{* Noms des mois traduits directement au point d'usage via
   {neria_admin key="common.month_`$n`"} — voir plus bas. *}

{assign var="is_edit" value=false}
{if isset($seasonal_edit) && $seasonal_edit}{assign var="is_edit" value=true}{/if}

<style>
.ns-pill {
  display:inline-flex; align-items:center; gap:5px;
  font-size:12px; padding:5px 11px; border-radius:20px; cursor:pointer;
  border:1px solid var(--neria-border); background:var(--neria-light-bg);
  color:var(--neria-text); transition:all .15s; user-select:none;
}
.ns-pill input[type=checkbox] { display:none; }
.ns-pill:has(input:checked) {
  background:var(--neria-dark); color:#fff; border-color:var(--neria-dark);
}
.ns-pill-accent:has(input:checked) {
  background:var(--neria-accent); border-color:var(--neria-accent);
}
.ns-field { margin-bottom:18px; }
.ns-field .neria-label { margin-bottom:7px; display:block; }
.ns-row { display:grid; gap:20px; }
.ns-row--2 { grid-template-columns:1fr 1fr; }
.ns-row--3 { grid-template-columns:1fr 1fr 1fr; }
.ns-status-dot { display:inline-block; width:7px; height:7px; border-radius:50%; }
.ns-status-dot--on  { background:var(--neria-success); }
.ns-status-dot--off { background:var(--neria-border); }

/* Carte option (Actif / Mode cadeaux) */
.ns-option-card {
  flex:1; min-width:220px; border:1px solid var(--neria-border);
  border-radius:8px; padding:16px 20px;
}
.ns-option-card__label {
  font-size:10px; font-weight:700; letter-spacing:.08em; text-transform:uppercase;
  color:var(--neria-text-light); margin-bottom:10px;
}
.ns-option-card--gift {
  border-color:var(--neria-accent); background:var(--neria-light-bg);
}
.ns-option-card--gift .ns-option-card__label { color:var(--neria-accent); }

/* Calendrier */
.ns-calendar-grid {
  display:grid; grid-template-columns:repeat(3,1fr); gap:14px;
}
.ns-calendar-month {
  background:var(--neria-light-bg,#faf8f5); border:1px solid var(--neria-border);
  border-radius:8px; padding:16px 16px 12px;
  display:flex; flex-direction:column;
}
.ns-calendar-month__name {
  font-size:12px; font-weight:700; letter-spacing:.06em; text-transform:uppercase;
  color:var(--neria-accent); margin-bottom:12px; padding-bottom:10px;
  border-bottom:2px solid var(--neria-border); flex-shrink:0;
}
.ns-calendar-month__body {
  height:140px; overflow-y:auto; flex:1;
}
.ns-empty-month {
  display:flex; align-items:center; justify-content:center;
  height:100%; font-size:22px; color:var(--neria-border); letter-spacing:.1em;
}
.ns-campaign-badge {
  display:flex; align-items:flex-start; gap:7px; margin-bottom:8px;
}
.ns-campaign-badge__day {
  font-size:11px; font-weight:700; color:#fff; border-radius:4px;
  padding:2px 7px; background:var(--neria-accent); white-space:nowrap; flex-shrink:0; margin-top:1px;
}
.ns-campaign-badge__day--inactive { background:#ccc; }
.ns-campaign-badge__name {
  font-size:12px; color:var(--neria-text); line-height:1.35;
}
.ns-campaign-badge__name--inactive { color:var(--neria-text-light); }
.ns-campaign-badge__gift {
  font-size:10px; color:var(--neria-accent); font-weight:600;
}
.ns-empty-month { font-size:12px; color:var(--neria-border); }
</style>

{* ══════════════════════════════════════════════════════════════
   SECTION 1 — FORMULAIRE
══════════════════════════════════════════════════════════════ *}
<div class="neria-section">

  <h2 class="neria-section__title">◑ {neria_admin key='seasonal.title'}</h2>
  <p class="neria-section__desc">
    {neria_admin key='seasonal.desc'}
  </p>

  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin-bottom:24px;font-size:13px;line-height:1.75;color:#4a3f35;">
    <div style="font-weight:700;margin-bottom:12px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">{neria_admin key='social.howto_title'}</div>
    {neria_admin key='seasonal.howto_body'}
    <div style="font-weight:700;margin:16px 0 8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">{neria_admin key='seasonal.usage_title'}</div>
    <ol style="margin:0 0 0 18px;padding:0;">
      <li style="margin-bottom:6px;">{neria_admin key='seasonal.usage_item1'}</li>
      <li style="margin-bottom:6px;">{neria_admin key='seasonal.usage_item2'}</li>
      <li style="margin-bottom:6px;">{neria_admin key='seasonal.usage_item3'}</li>
      <li style="margin-bottom:6px;">{neria_admin key='seasonal.usage_item4'}</li>
      <li style="margin-bottom:6px;">{neria_admin key='seasonal.usage_item5'}</li>
      <li>{neria_admin key='seasonal.usage_item6'}</li>
    </ol>
    <div style="margin-top:14px;padding-top:12px;border-top:1px solid #e8d5b0;font-size:12px;opacity:.75;">
      <strong>{neria_admin key='seasonal.tip_label'}</strong> {neria_admin key='seasonal.tip_body'}
    </div>
  </div>

  <form method="post" action="{$tab_url|escape:'html'}&neria_action=save_seasonal_campaign">
    {if $is_edit}
      <input type="hidden" name="id_campaign" value="{$seasonal_edit.id_campaign|intval}">
    {/if}

    {* ── Rangée 1 : Nom + Template ── *}
    <div class="ns-row ns-row--2">
      <div class="ns-field">
        <label class="neria-label" for="ns-name">
          {neria_admin key='seasonal.name_label'}
          <span class="neria-hint">{neria_admin key='seasonal.name_hint'}</span>
        </label>
        <input id="ns-name" type="text" name="seasonal_name" class="neria-input" required
               placeholder="{neria_admin key='seasonal.name_placeholder'}"
               value="{if $is_edit}{$seasonal_edit.name|escape:'html'}{/if}">
      </div>
      <div class="ns-field">
        <label class="neria-label" for="ns-template">
          {neria_admin key='seasonal.template_label'}
          <span class="neria-hint">{neria_admin key='seasonal.template_hint'}</span>
        </label>
        <select id="ns-template" name="seasonal_template" class="neria-select" style="width:100%;" required>
          <option value="">— {neria_admin key='seasonal.choose_template_option'} —</option>
          {foreach $seasonal_templates as $tplKey => $tplLabel}
            <option value="{$tplKey|escape:'html'}"
              {if $is_edit && $seasonal_edit.template === $tplKey} selected{/if}>
              {$tplLabel|escape:'html'}
            </option>
          {/foreach}
        </select>
      </div>
    </div>

    {* ── Rangée 2 : Date + Délai + Genre ── *}
    <div class="ns-row ns-row--3" style="align-items:start;">
      <div class="ns-field">
        <label class="neria-label" for="ns-date">{neria_admin key='seasonal.date_label'}</label>
        <input id="ns-date" type="text" name="seasonal_annual_date" class="neria-input" required
               pattern="(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])"
               placeholder="12-25" title="{neria_admin key='seasonal.date_format_title'}"
               value="{if $is_edit}{$seasonal_edit.annual_date|escape:'html'}{else}12-25{/if}">
        <span class="neria-hint">{neria_admin key='seasonal.date_hint'}</span>
      </div>
      <div class="ns-field">
        <label class="neria-label" for="ns-days">{neria_admin key='seasonal.days_before_label'}</label>
        <div style="display:flex;align-items:center;gap:10px;">
          <input id="ns-days" type="number" name="seasonal_days_before" class="neria-input"
                 style="width:80px;" min="0" max="30"
                 value="{if $is_edit}{$seasonal_edit.days_before|intval}{else}0{/if}">
          <span class="neria-hint">{neria_admin key='seasonal.days_before_hint'}</span>
        </div>
      </div>
      <div class="ns-field">
        <label class="neria-label" for="ns-gender">{neria_admin key='seasonal.gender_label'}</label>
        <select id="ns-gender" name="seasonal_gender" class="neria-select" style="width:100%;">
          <option value="0" {if !$is_edit || $seasonal_edit.target_gender == 0} selected{/if}>{neria_admin key='seasonal.gender_all'}</option>
          <option value="1" {if $is_edit && $seasonal_edit.target_gender == 1} selected{/if}>{neria_admin key='seasonal.gender_men'}</option>
          <option value="2" {if $is_edit && $seasonal_edit.target_gender == 2} selected{/if}>{neria_admin key='seasonal.gender_women'}</option>
        </select>
      </div>
    </div>

    {* ── Rangée 3 : Tranche d'âge + Segments ── *}
    <div class="ns-row ns-row--2">
      <div class="ns-field">
        <label class="neria-label">
          {neria_admin key='seasonal.age_range_label'}
          <span class="neria-hint">{neria_admin key='seasonal.age_no_limit_hint'}</span>
        </label>
        <div style="display:flex;align-items:center;gap:12px;">
          <div style="display:flex;align-items:center;gap:6px;">
            <span style="font-size:12px;color:var(--neria-text-light);white-space:nowrap;">{neria_admin key='seasonal.age_from'}</span>
            <input type="number" name="seasonal_min_age" class="neria-input"
                   style="width:72px;" min="0" max="120" placeholder="0"
                   value="{if $is_edit}{$seasonal_edit.min_age|intval}{else}0{/if}">
          </div>
          <div style="display:flex;align-items:center;gap:6px;">
            <span style="font-size:12px;color:var(--neria-text-light);white-space:nowrap;">{neria_admin key='seasonal.age_to'}</span>
            <input type="number" name="seasonal_max_age" class="neria-input"
                   style="width:72px;" min="0" max="120" placeholder="0"
                   value="{if $is_edit}{$seasonal_edit.max_age|intval}{else}0{/if}">
            <span style="font-size:12px;color:var(--neria-text-light);">{neria_admin key='seasonal.age_years_unit'}</span>
          </div>
        </div>
      </div>
      <div class="ns-field">
        <label class="neria-label">
          {neria_admin key='seasonal.segments_label'}
          <span class="neria-hint">{neria_admin key='seasonal.segments_hint'}</span>
        </label>
        <div style="display:flex;flex-wrap:wrap;gap:6px;">
          {foreach $seasonal_segments as $segKey => $segLabel}
            <label class="ns-pill">
              <input type="checkbox" name="seasonal_segments[]" value="{$segKey|escape:'html'}"
                {if $is_edit && isset($seasonal_edit_seg_map[$segKey])} checked
                {elseif !$is_edit} checked
                {/if}>
              {$segLabel|escape:'html'}
            </label>
          {/foreach}
        </div>
      </div>
    </div>

    {* ── Langues ── *}
    <div class="ns-field">
      <label class="neria-label">
        {neria_admin key='seasonal.langs_label'}
        <span class="neria-hint">{neria_admin key='seasonal.langs_hint'}</span>
      </label>
      <div style="display:flex;flex-wrap:wrap;gap:6px;">
        {foreach $lang_labels as $code => $name}
          <label class="ns-pill ns-pill-accent">
            <input type="checkbox" name="seasonal_langs[]" value="{$code|escape:'html'}"
              {if $is_edit && isset($seasonal_edit_lang_map[$code])} checked
              {elseif !$is_edit} checked
              {/if}>
            {if isset($lang_flags[$code])}{$lang_flags[$code]}{/if} {$name|escape:'html'}
          </label>
        {/foreach}
      </div>
    </div>

    {* ── Options : Actif + Mode cadeaux ── *}
    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:4px;margin-bottom:24px;">

      {* Statut *}
      <div class="ns-option-card">
        <div class="ns-option-card__label">{neria_admin key='seasonal.status_label'}</div>
        <input type="hidden" id="ns-is-active-val" name="seasonal_is_active" value="{if !$is_edit || $seasonal_edit.is_active}1{else}0{/if}">
        <button type="button" id="ns-btn-active"
          onclick="nsToggleBtn(this,'ns-is-active-val','{neria_admin key='seasonal.active_label' esc='javascript'}','{neria_admin key='seasonal.inactive_label' esc='javascript'}','#16a34a','#dc2626')"
          style="padding:8px 20px;border-radius:20px;border:none;cursor:pointer;font-size:13px;font-weight:700;
                 background:{if !$is_edit || $seasonal_edit.is_active}#16a34a{else}#dc2626{/if};color:#fff;">
          {if !$is_edit || $seasonal_edit.is_active}● {neria_admin key='seasonal.active_label'}{else}○ {neria_admin key='seasonal.inactive_label'}{/if}
        </button>
        <p class="neria-hint" style="margin-top:8px;">{neria_admin key='seasonal.status_hint'}</p>
      </div>

      {* Mode cadeaux *}
      <div class="ns-option-card ns-option-card--gift">
        <div class="ns-option-card__label">{neria_admin key='seasonal.gift_mode_label'}</div>
        <input type="hidden" id="ns-gift-mode-val" name="seasonal_gift_mode" value="{if $is_edit && $seasonal_edit.gift_mode}1{else}0{/if}">
        <button type="button" id="ns-btn-gift"
          onclick="nsToggleBtn(this,'ns-gift-mode-val','{neria_admin key='seasonal.enabled_label' esc='javascript'}','{neria_admin key='seasonal.disabled_label' esc='javascript'}','#16a34a','#dc2626')"
          style="padding:8px 20px;border-radius:20px;border:none;cursor:pointer;font-size:13px;font-weight:700;
                 background:{if $is_edit && $seasonal_edit.gift_mode}#16a34a{else}#dc2626{/if};color:#fff;">
          {if $is_edit && $seasonal_edit.gift_mode}● {neria_admin key='seasonal.enabled_label'}{else}○ {neria_admin key='seasonal.disabled_label'}{/if}
        </button>
        <p class="neria-hint" style="margin-top:8px;">
          {neria_admin key='seasonal.gift_mode_hint'}
        </p>
      </div>

    </div>

    {* ── Boutons ── *}
    <div style="display:flex;align-items:center;gap:12px;padding-top:20px;border-top:1px solid var(--neria-border);">
      <button type="submit" class="neria-btn neria-btn--primary">
        {if $is_edit}✓ {neria_admin key='seasonal.save_edit_btn'}{else}＋ {neria_admin key='seasonal.create_btn'}{/if}
      </button>
      {if $is_edit}
        <a href="{$tab_url|escape:'html'}" class="neria-btn neria-btn--ghost">✕ {neria_admin key='common.cancel'}</a>
      {/if}
    </div>

  </form>
</div>

{* ══════════════════════════════════════════════════════════════
   SECTION 2 — LISTE DES CAMPAGNES
══════════════════════════════════════════════════════════════ *}
{if $seasonal_campaigns|count > 0}
<div class="neria-section">
  <h2 class="neria-section__title">
    {$seasonal_campaigns|count} {if $seasonal_campaigns|count > 1}{neria_admin key='seasonal.campaign_plural'}{else}{neria_admin key='seasonal.campaign_singular'}{/if}
  </h2>

  <div class="neria-bo-wrap">
  <div class="neria-table-wrap">
  <table class="neria-table">
    <thead>
      <tr>
        <th>{neria_admin key='seasonal.col_name'}</th>
        <th>{neria_admin key='common.template'}</th>
        <th style="text-align:center;">{neria_admin key='seasonal.col_date'}</th>
        <th style="text-align:center;">{neria_admin key='seasonal.col_send'}</th>
        <th>{neria_admin key='seasonal.col_targeting'}</th>
        <th style="text-align:center;">{neria_admin key='gdpr.col_status'}</th>
        <th style="text-align:center;">{neria_admin key='bounces.col_actions'}</th>
      </tr>
    </thead>
    <tbody>
      {foreach $seasonal_campaigns as $c}
        {assign var="m_idx" value=$c.annual_date|substr:0:2|intval}
        {assign var="d_idx" value=$c.annual_date|substr:3:2|intval}
        <tr>
          <td>
            <strong>{$c.name|escape:'html'}</strong>
          </td>
          <td>
            {if $c.gift_mode}
              <span class="neria-var-tag" style="background:var(--neria-accent);color:#fff;">{neria_admin key='seasonal.gift_ideas_badge'}</span>
            {else}
              <span class="neria-var-tag">{$c.template|escape:'html'}</span>
            {/if}
          </td>
          <td style="text-align:center;white-space:nowrap;">
            <span class="neria-badge neria-badge--accent" style="font-size:11px;padding:4px 10px;">
              {neria_admin key="common.month_abbr_`$m_idx`"} {$d_idx}
            </span>
          </td>
          <td style="text-align:center;font-size:12px;color:var(--neria-text-light);">
            {if $c.days_before > 0}{neria_admin key='seasonal.day_minus_prefix'}–{$c.days_before|intval}{else}{neria_admin key='seasonal.day_j'}{/if}
          </td>
          <td style="font-size:12px;line-height:1.7;">
            {if $c.target_segment neq ''}
              <div style="color:var(--neria-text);">{$c.target_segment|escape:'html'}</div>
            {else}
              <div style="color:var(--neria-text-light);">{neria_admin key='seasonal.all_segments'}</div>
            {/if}
            {if $c.target_gender == 1}<div>♂ {neria_admin key='seasonal.men_short'}</div>
            {elseif $c.target_gender == 2}<div>♀ {neria_admin key='seasonal.women_short'}</div>
            {/if}
            {if $c.target_lang neq ''}
              <div style="color:var(--neria-text-light);font-size:11px;">{$c.target_lang|escape:'html'}</div>
            {/if}
            {if $c.min_age > 0 || $c.max_age > 0}
              <div style="color:var(--neria-text-light);">
                {$c.min_age|intval}–{if $c.max_age > 0}{$c.max_age|intval}{else}∞{/if} {neria_admin key='seasonal.age_years_unit'}
              </div>
            {/if}
          </td>
          <td style="text-align:center;">
            <form method="post" action="{$tab_url|escape:'html'}&neria_action=toggle_seasonal_campaign"
                  style="display:inline;">
              <input type="hidden" name="id_campaign" value="{$c.id_campaign|intval}">
              <button type="submit"
                class="neria-btn neria-btn--sm"
                style="{if $c.is_active}background:var(--neria-success);color:#fff;border-color:var(--neria-success);{else}background:var(--neria-danger,#dc2626);color:#fff;border-color:var(--neria-danger,#dc2626);{/if}font-weight:700;">
                {if $c.is_active}● {neria_admin key='seasonal.active_label'}{else}○ {neria_admin key='seasonal.inactive_label'}{/if}
              </button>
            </form>
          </td>
          <td style="text-align:center;white-space:nowrap;">
            <a href="{$tab_url|escape:'html'}&edit_campaign={$c.id_campaign|intval}"
               class="neria-btn neria-btn--sm neria-btn--ghost" title="{neria_admin key='seasonal.edit_btn'}">
              ✏ {neria_admin key='seasonal.edit_btn'}
            </a>
            <form method="post"
                  action="{$tab_url|escape:'html'}&neria_action=delete_seasonal_campaign"
                  style="display:inline;margin-left:4px;">
              <input type="hidden" name="id_campaign" value="{$c.id_campaign|intval}">
              <button type="button" class="neria-btn neria-btn--sm neria-btn--danger"
                      data-confirm="{neria_admin key='seasonal.delete_confirm_pre'} {$c.name|escape:'html'} {neria_admin key='seasonal.delete_confirm_post'}"
                      onclick="neriaConfirmDelete(this);"
                      title="{neria_admin key='seasonal.delete_title'}">✕</button>
            </form>
          </td>
        </tr>
      {/foreach}
    </tbody>
  </table>
  </div>
  </div>
</div>
{else}
<div class="neria-section" style="text-align:center;padding:40px 28px;">
  <div style="font-size:32px;color:var(--neria-border);margin-bottom:12px;">◑</div>
  <p style="font-size:14px;color:var(--neria-text-light);margin:0 0 20px;">
    {neria_admin key='seasonal.empty_message'}
  </p>
  <div style="font-size:12px;color:var(--neria-text-light);line-height:1.8;">
    {neria_admin key='seasonal.examples_label'} {neria_admin key='seasonal.example_christmas'}<br>
    {neria_admin key='seasonal.example_blackfriday'}<br>
    {neria_admin key='seasonal.example_summersale'}
  </div>
</div>
{/if}

{* ══════════════════════════════════════════════════════════════
   SECTION 3 — CALENDRIER ANNUEL
══════════════════════════════════════════════════════════════ *}
<div class="neria-section">
  <h2 class="neria-section__title">{neria_admin key='seasonal.calendar_title'}</h2>

  <div class="ns-calendar-grid">
    {foreach from=$seasonal_calendar key=mNum item=mCampaigns}
      <div class="ns-calendar-month">
        <div class="ns-calendar-month__name">{neria_admin key="common.month_`$mNum`"}</div>
        <div class="ns-calendar-month__body">
          {if $mCampaigns|count > 0}
            {foreach $mCampaigns as $mc}
              <div class="ns-campaign-badge">
                <span class="ns-campaign-badge__day{if !$mc.is_active} ns-campaign-badge__day--inactive{/if}">
                  {$mc.day|intval}{if $mc.days_before > 0}<span style="font-weight:400;opacity:.8;font-size:10px;"> {neria_admin key='seasonal.day_minus_prefix'}-{$mc.days_before|intval}</span>{/if}
                </span>
                <span class="ns-campaign-badge__name{if !$mc.is_active} ns-campaign-badge__name--inactive{/if}">
                  {$mc.name|truncate:24:'…'|escape:'html'}
                  {if $mc.gift_mode}<br><span class="ns-campaign-badge__gift">{neria_admin key='seasonal.gift_ideas_badge'}</span>{/if}
                </span>
              </div>
            {/foreach}
          {else}
            <div class="ns-empty-month">—</div>
          {/if}
        </div>
      </div>
    {/foreach}
  </div>

  <p style="margin-top:14px;font-size:12px;color:var(--neria-text-light);line-height:1.8;">
    <span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:var(--neria-accent);vertical-align:middle;margin-right:4px;"></span> {neria_admin key='seasonal.legend_active'} &nbsp;·&nbsp;
    <span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#ccc;vertical-align:middle;margin-right:4px;"></span> {neria_admin key='seasonal.legend_inactive'} &nbsp;·&nbsp;
    {neria_admin key='seasonal.legend_j_minus_n'} &nbsp;·&nbsp;
    {neria_admin key='seasonal.legend_cron'}
  </p>
</div>

<script>
function nsToggleBtn(btn, inputId, labelOn, labelOff, colorOn, colorOff) {
  var input = document.getElementById(inputId);
  var isOn  = input.value === '1';
  input.value       = isOn ? '0' : '1';
  btn.textContent   = isOn ? '○ ' + labelOff : '● ' + labelOn;
  btn.style.background = isOn ? colorOff : colorOn;
}

(function(){
  document.querySelectorAll('.ns-pill input[type=checkbox]').forEach(function(cb){
    cb.addEventListener('change', function(){
      var pill = this.closest('.ns-pill');
      var isAccent = pill.classList.contains('ns-pill-accent');
      pill.style.background = this.checked ? (isAccent ? 'var(--neria-accent)' : 'var(--neria-dark)') : '';
      pill.style.color  = this.checked ? '#fff' : '';
      pill.style.borderColor = this.checked ? (isAccent ? 'var(--neria-accent)' : 'var(--neria-dark)') : '';
    });
    if(cb.checked){
      var pill = cb.closest('.ns-pill');
      var isAccent = pill.classList.contains('ns-pill-accent');
      pill.style.background = isAccent ? 'var(--neria-accent)' : 'var(--neria-dark)';
      pill.style.color = '#fff';
      pill.style.borderColor = isAccent ? 'var(--neria-accent)' : 'var(--neria-dark)';
    }
  });
})();
</script>
