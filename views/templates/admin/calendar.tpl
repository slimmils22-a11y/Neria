{**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — calendar.tpl
 * Gestion des occasions calendaires automatiques.
 * Le marchand active/désactive des occasions, règle le délai d'envoi
 * et associe un template Neria. CalendarManager les envoie chaque jour.
 *}

<div class="neria-section">
  <h2 class="neria-section__title">{neria_admin key='calendar.title'}</h2>
  <p class="neria-section__desc">{neria_admin key='calendar.desc'}</p>

  {* ── Mode d'emploi ──────────────────────────────────────────────── *}
  <div class="neria-card" style="margin-top:20px;background:var(--neria-bg-subtle,#f9f7f4);border-left:3px solid var(--neria-accent,#b8976a);padding:18px 24px;">
    <h4 style="margin:0 0 12px;font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:var(--neria-accent,#b8976a);">{neria_admin key='calendar.howto_title'}</h4>
    <ol style="margin:0;padding-left:18px;line-height:1.9;font-size:13px;color:var(--neria-text-muted,#888);">
      <li>{neria_admin key='calendar.howto_step1'}</li>
      <li>{neria_admin key='calendar.howto_step2'}</li>
      <li>{neria_admin key='calendar.howto_step3'}</li>
      <li>{neria_admin key='calendar.howto_step4'}</li>
    </ol>
    <p style="margin:12px 0 0;font-size:12px;color:var(--neria-text-muted,#aaa);">
      ⓘ {neria_admin key='calendar.howto_note'}
    </p>
  </div>

  {* ── Tableau des occasions configurées ─────────────────────────── *}
  {if $calendar_events|@count > 0}
  <table class="neria-table" style="margin-top:24px;">
    <thead>
      <tr>
        <th>{neria_admin key='calendar.col_event'}</th>
        <th>{neria_admin key='calendar.col_lang'}</th>
        <th>{neria_admin key='calendar.col_country'}</th>
        <th>{neria_admin key='calendar.col_template'}</th>
        <th style="text-align:center;">{neria_admin key='calendar.col_days'}</th>
        <th>{neria_admin key='calendar.col_next_date'}</th>
        <th>{neria_admin key='calendar.col_last_sent'}</th>
        <th style="text-align:center;">{neria_admin key='calendar.col_active'}</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      {foreach $calendar_events as $ev}
      <tr>
        <td>
          <strong>{$ev.event_key|escape:'html'}</strong>
          {if $ev.custom_date}<br><span class="neria-hint" style="font-size:11px;">📅 {$ev.custom_date|escape:'html'}</span>{/if}
        </td>
        <td>{$ev.lang|upper|escape:'html'}</td>
        <td>{if $ev.country_code}{$ev.country_code|upper|escape:'html'}{else}—{/if}</td>
        <td style="font-family:monospace;font-size:12px;">{$ev.template|escape:'html'}</td>

        {* Délai inline ─────────────────────────────────────────── *}
        <td style="text-align:center;">
          <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}" style="display:inline-flex;gap:4px;align-items:center;">
            <input type="hidden" name="neria_action" value="save_calendar_event">
            <input type="hidden" name="neria_tab"    value="calendar">
            <input type="hidden" name="cal_id"       value="{$ev.id_event|intval}">
            <input type="number" name="cal_days"     value="{$ev.send_days_before|intval}"
                   min="1" max="60" style="width:60px;" class="neria-input neria-input--sm">
            <button type="submit" class="neria-btn neria-btn--xs" title="{neria_admin key='common.save' esc='html'}" aria-label="{neria_admin key='common.save' esc='html'}">✓</button>
          </form>
        </td>

        {* Prochaine date calculée ──────────────────────────────── *}
        <td style="font-size:12px;">
          {if $ev.display_info.next_event_date}
            📅 {$ev.display_info.next_event_date}
            {if $ev.display_info.next_send_date}
              <br><span class="neria-hint" style="font-size:11px;">→ envoi le {$ev.display_info.next_send_date}</span>
            {/if}
          {else}
            <span class="neria-hint">—</span>
          {/if}
        </td>

        {* Dernier envoi ─────────────────────────────────────────── *}
        <td style="font-size:12px;">
          {if $ev.display_info.last_sent}
            {$ev.display_info.last_sent.date|date_format:"%d/%m/%Y"}
            {if $ev.display_info.last_sent.count}
              <br><span class="neria-hint" style="font-size:11px;">{$ev.display_info.last_sent.count|escape:'html'} destinataires</span>
            {/if}
          {else}
            <span class="neria-hint">{neria_admin key='calendar.never_sent'}</span>
          {/if}
        </td>

        {* Toggle actif ─────────────────────────────────────────── *}
        <td style="text-align:center;">
          <a href="{$smarty.server.REQUEST_URI|escape:'html'}&neria_action=toggle_calendar_event&neria_tab=calendar&cal_id={$ev.id_event|intval}"
             class="neria-badge {if $ev.is_active}neria-badge--on{else}neria-badge--off{/if}"
             onclick="return neriaPostLink(event, this);">
            {if $ev.is_active}{neria_admin key='common.enabled'}{else}{neria_admin key='common.disabled'}{/if}
          </a>
        </td>

        {* Duplication + Suppression ────────────────────────────── *}
        <td style="white-space:nowrap;">
          <button type="button" class="neria-btn neria-btn--ghost neria-btn--xs neria-cal-duplicate"
                  data-event-key="{$ev.event_key|escape:'html'}"
                  data-country="{$ev.country_code|escape:'html'}"
                  data-template="{$ev.template|escape:'html'}"
                  data-days="{$ev.send_days_before|intval}"
                  title="{neria_admin key='calendar.duplicate_btn'}">⎘</button>
          <a href="{$smarty.server.REQUEST_URI|escape:'html'}&neria_action=delete_calendar_event&neria_tab=calendar&cal_id={$ev.id_event|intval}"
             class="neria-btn neria-btn--danger neria-btn--xs"
             data-confirm="{neria_admin key='calendar.delete_confirm' esc='html'}"
             onclick="return neriaPostLink(event, this);">✕</a>
        </td>
      </tr>
      {/foreach}
    </tbody>
  </table>
  {else}
  <p class="neria-hint" style="margin-top:16px;">{neria_admin key='calendar.no_events'}</p>
  {/if}

  {* ── Formulaire ajout ──────────────────────────────────────────── *}
  <div class="neria-card" id="cal-add-form" style="margin-top:32px;">
    <h3 class="neria-card__title">{neria_admin key='calendar.add_title'}</h3>

    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}">
      <input type="hidden" name="neria_action" value="add_calendar_event">
      <input type="hidden" name="neria_tab"    value="calendar">

      <div class="neria-form-grid">

        {* Occasion ─────────────────────────────────────────────── *}
        <div class="neria-form-group">
          <label class="neria-label">{neria_admin key='calendar.col_event'}</label>
          <select id="cal-event-key-select" name="cal_event_key" class="neria-select" required>
            {foreach $calendar_known_keys as $k => $label}
              <option value="{$k|escape:'html'}">{$label|escape:'html'} ({$k|escape:'html'})</option>
            {/foreach}
            <option value="__custom__">✎ {neria_admin key='calendar.custom_occasion'}</option>
          </select>
          <span class="neria-hint">{neria_admin key='calendar.event_hint'}</span>
        </div>

        {* Champs visibles uniquement si "Occasion personnalisée" ── *}
        <div id="cal-custom-fields" style="display:none;grid-column:1/-1;">
          <div class="neria-form-grid" style="margin-top:0;">
            <div class="neria-form-group">
              <label class="neria-label">{neria_admin key='calendar.custom_key_label'}</label>
              <input type="text" id="cal-custom-key-input" name="cal_custom_key" class="neria-input"
                     placeholder="{neria_admin key='calendar.custom_key_placeholder'}" maxlength="50">
              <span class="neria-hint">{neria_admin key='calendar.custom_key_hint'}</span>
            </div>
            <div class="neria-form-group">
              <label class="neria-label">{neria_admin key='calendar.custom_date_label'}</label>
              <input type="text" name="cal_custom_date" class="neria-input"
                     placeholder="MM-JJ  ex: 12-25" maxlength="5" pattern="\d{2}-\d{2}">
              <span class="neria-hint">{neria_admin key='calendar.custom_date_hint'}</span>
            </div>
          </div>
        </div>

        <script>
        (function(){
          var sel   = document.getElementById('cal-event-key-select');
          var block = document.getElementById('cal-custom-fields');
          var keyIn = document.getElementById('cal-custom-key-input');
          function toggle(){
            var isCustom = sel.value === '__custom__';
            block.style.display = isCustom ? 'block' : 'none';
            if(keyIn) keyIn.required = isCustom;
          }
          sel.addEventListener('change', toggle);
          toggle();
        })();
        </script>

        {* Langue ───────────────────────────────────────────────── *}
        <div class="neria-form-group">
          <label class="neria-label">{neria_admin key='calendar.col_lang'}</label>
          <select name="cal_lang" class="neria-select" required>
            {foreach $lang_labels as $code => $name}
              <option value="{$code|escape:'html'}">{$lang_flags[$code]|default:''} {$name|escape:'html'}</option>
            {/foreach}
          </select>
        </div>

        {* Code pays (optionnel) ─────────────────────────────────── *}
        <div class="neria-form-group">
          <label class="neria-label">{neria_admin key='calendar.col_country'} <span class="neria-hint">({neria_admin key='common.optional'})</span></label>
          <select id="cal-country-input" name="cal_country" class="neria-select">
            <option value="">— {neria_admin key='calendar.all_countries'} —</option>
            {foreach $calendar_countries as $iso => $name}
              <option value="{$iso|escape:'html'}">{$name|escape:'html'} ({$iso|escape:'html'})</option>
            {/foreach}
          </select>
          <span class="neria-hint">{neria_admin key='calendar.country_hint'}</span>
        </div>

        {* Template ─────────────────────────────────────────────── *}
        <div class="neria-form-group">
          <label class="neria-label">{neria_admin key='calendar.col_template'}</label>
          <select id="cal-template-select" name="cal_template" class="neria-select" required>
            {foreach $calendar_templates as $tpl}
              <option value="{$tpl|escape:'html'}">{$tpl|escape:'html'}</option>
            {/foreach}
          </select>
        </div>

        {* Jours avant ──────────────────────────────────────────── *}
        <div class="neria-form-group">
          <label class="neria-label">{neria_admin key='calendar.col_days'}</label>
          <input type="number" id="cal-days-input" name="cal_days" class="neria-input" value="7" min="1" max="60" required>
          <span class="neria-hint">{neria_admin key='calendar.days_hint'}</span>
        </div>

        {* Actif ─────────────────────────────────────────────────── *}
        <div class="neria-form-group" style="justify-content:flex-end;">
          <label class="neria-toggle-label">
            <input type="checkbox" name="cal_active" value="1" checked class="neria-toggle-input">
            <span class="neria-toggle-switch"></span>
            {neria_admin key='calendar.col_active'}
          </label>
        </div>

      </div>

      <div style="margin-top:16px;">
        <button type="submit" class="neria-btn neria-btn--primary">{neria_admin key='calendar.add_btn'}</button>
      </div>
    </form>
  </div>

</div>

{literal}<script>
document.addEventListener('click', function (e) {
  var btn = e.target.closest('.neria-cal-duplicate');
  if (!btn) { return; }

  var eventSel = document.getElementById('cal-event-key-select');
  var country  = document.getElementById('cal-country-input');
  var tplSel   = document.getElementById('cal-template-select');
  var days     = document.getElementById('cal-days-input');

  if (eventSel) {
    var key = btn.dataset.eventKey;
    var hasOption = Array.prototype.some.call(eventSel.options, function (o) { return o.value === key; });
    eventSel.value = hasOption ? key : '__custom__';
    eventSel.dispatchEvent(new Event('change'));
    if (!hasOption) {
      var keyIn = document.getElementById('cal-custom-key-input');
      if (keyIn) { keyIn.value = key; }
    }
  }
  if (country) { country.value = btn.dataset.country || ''; }
  if (tplSel && btn.dataset.template) { tplSel.value = btn.dataset.template; }
  if (days && btn.dataset.days) { days.value = btn.dataset.days; }

  document.getElementById('cal-add-form').scrollIntoView({ behavior: 'smooth', block: 'start' });
});
</script>{/literal}
