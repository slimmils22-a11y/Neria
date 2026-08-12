{**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — send.tpl
 * Envoi manuel d'un template à un client (vague 1).
 * Features : auto-complétion client, détection doublon, planification différée, prévisualisation.
 *}

<div class="neria-section">
  <h2 class="neria-section__title">{neria_admin key='nav.manual_send'}</h2>
  <p class="neria-section__desc">
    {neria_admin key='send.desc'}
  </p>

  <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}" id="neria-send-form">
    <input type="hidden" name="neria_action" value="send_manual">
    <input type="hidden" name="neria_tab"    value="send">
    <input type="hidden" name="neria_send_at" id="neria-send-at-hidden" value="">

    <div class="neria-form-grid">

      {* ── Template ───────────────────────────────────────────── *}
      <div class="neria-form-group">
        <label class="neria-label" for="neria-send-template">
          {neria_admin key='send.template_label'}
        </label>
        <select id="neria-send-template" name="neria_template" class="neria-select">
          {foreach $send_templates as $key => $lbl}
            <option value="{$key}" data-label="{$lbl|escape:'html'}"
              {if isset($smarty.post.neria_template) && $smarty.post.neria_template == $key}selected{/if}>
              {$lbl} ({$key})
            </option>
          {/foreach}
        </select>

        {* Avertissement statique doublon anniversaire *}
        <div id="neria-anniversary-static-warn"
             style="display:none; margin-top:10px; padding:11px 14px;
                    background:#fff8e1; border-left:3px solid #f59e0b;
                    border-radius:4px; font-size:12px; color:#78350f; line-height:1.6;">
          <span id="neria-anniversary-static-text"></span>
        </div>
      </div>

      {* ── Destinataire avec auto-complétion ─────────────────── *}
      <div class="neria-form-group" style="position:relative;">
        <label class="neria-label" for="neria-send-email">
          {neria_admin key='send.email_label'}
        </label>
        {* Round 154 : role="combobox"/aria-expanded/aria-controls —
           complète la liste role="listbox" ci-dessous (patron ARIA
           combobox simplifié) pour que la navigation clavier
           (flèches/Entrée/Échap, gérée en JS) soit annoncée correctement. *}
        <input type="email" id="neria-send-email" name="neria_email" class="neria-input"
               placeholder="{neria_admin key='send.email_placeholder' esc='html'}" required autocomplete="off"
               role="combobox" aria-expanded="false" aria-controls="neria-autocomplete-dropdown" aria-autocomplete="list"
               value="{if isset($smarty.post.neria_email)}{$smarty.post.neria_email|escape:'html'}{/if}">
        <span class="neria-hint">{neria_admin key='send.email_hint'}</span>

        {* Dropdown autocomplétion — round 154 : role="listbox", auparavant
           une liste de <div> avec seulement mouseenter/mouseleave/mousedown,
           totalement inatteignable et inutilisable au clavier sur ce flux
           d'envoi manuel parmi les plus fréquents du BO. *}
        <div id="neria-autocomplete-dropdown" role="listbox"
             style="display:none; position:absolute; top:100%; left:0; right:0; z-index:200;
                    background:#fff; border:1px solid #e8d5b0; border-radius:0 0 6px 6px;
                    box-shadow:0 4px 12px rgba(0,0,0,.10); max-height:260px; overflow-y:auto;">
        </div>

        {* Carte client identifié *}
        <div id="neria-customer-card"
             style="display:none; margin-top:8px; padding:10px 14px;
                    background:#f9f6f1; border:1px solid #e8d5b0; border-radius:6px;
                    font-size:12px; color:#4a3f35; line-height:1.7;">
        </div>

        {* Avertissement doublon générique (chargé en AJAX) *}
        <div id="neria-duplicate-guard"
             style="display:none; margin-top:10px; padding:11px 14px;
                    border-radius:4px; font-size:12px; line-height:1.6;">
          <span id="neria-duplicate-guard-text"></span>
        </div>

        {* Avertissement dynamique anniversaire (chargé en AJAX) *}
        <div id="neria-anniversary-guard"
             style="display:none; margin-top:10px; padding:11px 14px;
                    border-radius:4px; font-size:12px; line-height:1.6;">
          <span id="neria-anniversary-guard-text"></span>
        </div>

        {* Avertissement centre de préférences (chargé en AJAX) — le client
           a désactivé la catégorie de ce template : le hook central bloque
           déjà réellement l'envoi (le client ne recevra rien), ce bandeau
           informe l'opérateur AVANT de cliquer "Envoyer" plutôt qu'après
           coup dans le journal Watchdog. *}
        <div id="neria-preferences-guard"
             style="display:none; margin-top:10px; padding:11px 14px;
                    border-radius:4px; font-size:12px; line-height:1.6;">
          <span id="neria-preferences-guard-text"></span>
        </div>

        {* Bandeau informatif Mode Silence (chargé en AJAX) — jamais
           bloquant, se contente de prévenir le marchand d'une limitation
           déjà existante (pas de colonne email sur neria_stat) pour les
           destinataires sans compte client. *}
        <div id="neria-cooldown-notice"
             style="display:none; margin-top:10px; padding:11px 14px;
                    border-radius:4px; font-size:12px; line-height:1.6;
                    background:#eef6fb; border-left:3px solid #3b82f6; color:#1e3a5f;">
          <span id="neria-cooldown-notice-text"></span>
        </div>
      </div>

      {* ── Sujet ──────────────────────────────────────────────── *}
      <div class="neria-form-group">
        <label class="neria-label" for="neria-send-subject">
          {neria_admin key='send.subject_label'} <span class="neria-hint">({neria_admin key='common.optional'})</span>
        </label>
        <input type="text" id="neria-send-subject" name="neria_subject" class="neria-input"
               placeholder="{neria_admin key='send.subject_ph'}"
               value="{if isset($smarty.post.neria_subject)}{$smarty.post.neria_subject|escape:'html'}{/if}">
      </div>

      {* ── Commande (optionnel) ───────────────────────────────── *}
      <div class="neria-form-group">
        <label class="neria-label" for="neria-send-order">
          {neria_admin key='send.order_label'} <span class="neria-hint">({neria_admin key='common.optional'})</span>
        </label>
        <input type="text" id="neria-send-order" name="neria_order_ref" class="neria-input"
               placeholder="{neria_admin key='send.order_ref_placeholder' esc='html'}"
               value="{if isset($smarty.post.neria_order_ref)}{$smarty.post.neria_order_ref|escape:'html'}{/if}">
      </div>

    </div>

    {* ── Champs de contenu spécifiques au template ────────────── *}
    <div class="neria-send-content" style="margin-top:18px;">
      {foreach $send_editable_map as $tpl => $fields}
        <div class="neria-send-fields" data-tpl="{$tpl}" style="display:none;">
          {if $fields}
            <div class="neria-form-grid">
              {foreach $fields as $f}
                <div class="neria-form-group">
                  {* Round 154 : id/for associés — auparavant aucun lien
                     label/champ, un lecteur d'écran annonçait "champ de
                     texte" sans préciser lequel sur ce formulaire d'envoi
                     manuel utilisé quotidiennement. *}
                  <label class="neria-label" for="neria-send-var-{$tpl|escape:'html'}-{$f.key|escape:'html'}">{$f.label|escape:'html'}</label>
                  <input type="text" class="neria-input" id="neria-send-var-{$tpl|escape:'html'}-{$f.key|escape:'html'}" name="neria_var[{$f.key}]"
                         value="{if isset($smarty.post.neria_var) && isset($smarty.post.neria_var[$f.key])}{$smarty.post.neria_var[$f.key]|escape:'html'}{/if}">
                </div>
              {/foreach}
            </div>
          {else}
            <p class="neria-section__desc" style="margin:0;">
              {neria_admin key='send.no_fields'}
            </p>
          {/if}
        </div>
      {/foreach}
    </div>

    {* ── Message personnalisé ───────────────────────────────────── *}
    <div class="neria-form-group neria-form-group--full" style="margin-top:18px;">
      <label class="neria-label" for="neria-send-message">
        {neria_admin key='send.custom_message'}
        <span class="neria-hint">({neria_admin key='send.custom_message_hint'})</span>
      </label>
      <textarea id="neria-send-message" name="neria_var[custom_message]" class="neria-input" rows="3"
                placeholder="{neria_admin key='send.custom_message_ph'}">{if isset($smarty.post.neria_var) && isset($smarty.post.neria_var.custom_message)}{$smarty.post.neria_var.custom_message|escape:'html'}{/if}</textarea>
    </div>

    {* ── Planification différée ─────────────────────────────────── *}
    <div style="margin-top:20px; padding:16px 20px; background:#f9f6f1; border:1px solid #e8d5b0; border-radius:6px;">
      {* Round 154 : role="button"/tabindex/aria-expanded — auparavant un
         simple <div onclick>, inatteignable et inutilisable au clavier
         (voir le pattern correct déjà en place sur certificates.tpl pour
         un toggle équivalent, répliqué ici). *}
      <div style="display:flex; align-items:center; gap:10px; cursor:pointer;" id="neria-schedule-toggle"
           role="button" tabindex="0" aria-expanded="false" aria-controls="neria-schedule-body">
        <span style="font-size:12px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:#4a3f35; opacity:.75;">
          {neria_admin key='send.schedule_toggle_label'}
        </span>
        <span id="neria-schedule-arrow" style="font-size:11px; color:#a08060; transition:.2s;">▼</span>
        <span id="neria-schedule-badge" style="display:none; margin-left:auto; font-size:11px; font-weight:700;
              background:#16a34a; color:#fff; border-radius:10px; padding:2px 10px;">{neria_admin key='send.schedule_badge_planned'}</span>
      </div>
      <div id="neria-schedule-body" style="display:none; margin-top:14px;">
        <p style="font-size:12px; color:#6b5e52; margin:0 0 12px;">
          {neria_admin key='send.schedule_body_desc'}
        </p>
        <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
          <div class="neria-form-group" style="margin:0;">
            <label class="neria-label">{neria_admin key='send.schedule_date_label'}</label>
            <input type="date" id="neria-schedule-date" class="neria-input" style="width:160px;"
                   min="{$smarty.now|date_format:'%Y-%m-%d'}">
          </div>
          <div class="neria-form-group" style="margin:0;">
            <label class="neria-label">{neria_admin key='send.schedule_time_label'}</label>
            <input type="time" id="neria-schedule-time" class="neria-input" style="width:110px;" value="09:00">
          </div>
          <button type="button" id="neria-schedule-confirm" class="neria-btn neria-btn--ghost" style="margin-bottom:0;">
            {neria_admin key='send.schedule_confirm_btn'}
          </button>
          <button type="button" id="neria-schedule-cancel"
                  style="display:none; background:none; border:none; font-size:12px;
                         color:#dc2626; cursor:pointer; text-decoration:underline; margin-bottom:4px;">
            {neria_admin key='send.schedule_cancel_btn'}
          </button>
        </div>
        <div id="neria-schedule-feedback" style="display:none; margin-top:10px; font-size:12px;
             font-weight:700; color:#16a34a;">
        </div>
      </div>
    </div>

    {* ── Boutons ────────────────────────────────────────────────── *}
    <div style="margin-top:20px; display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
      <button type="submit" id="neria-send-btn" class="neria-btn neria-btn--primary">
        <span class="neria-icon">✉</span>
        <span id="neria-send-btn-label">{neria_admin key='send.send_btn'}</span>
      </button>
      <button type="button" id="neria-preview-btn" class="neria-btn neria-btn--ghost">
        {neria_admin key='send.preview_btn'}
      </button>
    </div>

  </form>
</div>

{* ── File d'attente des envois planifiés ────────────────────────── *}
{if isset($send_queue_pending) && $send_queue_pending|@count > 0}
<div style="margin-top:24px; padding:18px 22px; background:#f9f6f1; border:1px solid #e8d5b0; border-radius:6px;">
  <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
    <span style="font-size:12px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:#4a3f35; opacity:.75;">
      {neria_admin key='send.queue_pending_title'}
      ({if isset($send_queue_pending_total) && $send_queue_pending_total > $send_queue_pending|@count}{neria_admin key='send.queue_pending_count' n=$send_queue_pending|@count}{$send_queue_pending_total}{else}{$send_queue_pending|@count}{/if})
    </span>
    <button type="button" id="neria-process-queue-btn" class="neria-btn neria-btn--primary" style="font-size:11px; padding:6px 14px;">
      {neria_admin key='send.process_queue_btn'}
    </button>
  </div>
  <div id="neria-process-queue-result" style="display:none; margin-bottom:10px; padding:8px 12px;
       background:#dcfce7; border:1px solid #16a34a; border-radius:4px; font-size:12px; font-weight:700; color:#14532d;">
  </div>
  <table style="width:100%; border-collapse:collapse; font-size:12px;">
    <thead>
      <tr style="border-bottom:1px solid #e8d5b0; color:#4a3f35; opacity:.7; text-align:left;">
        <th style="padding:6px 10px 8px;">{neria_admin key='send.queue_col_template'}</th>
        <th style="padding:6px 10px 8px;">{neria_admin key='send.queue_col_recipient'}</th>
        <th style="padding:6px 10px 8px;">{neria_admin key='send.queue_col_scheduled_for'}</th>
        <th style="padding:6px 10px 8px;">{neria_admin key='send.queue_col_status'}</th>
      </tr>
    </thead>
    <tbody>
      {foreach $send_queue_pending as $q}
      <tr style="border-bottom:1px solid #f0e8d8;">
        <td style="padding:7px 10px; color:#4a3f35; font-family:monospace;">{$q.template|escape:'html'}</td>
        <td style="padding:7px 10px; color:#4a3f35;">
          {if $q.recipient_name}{$q.recipient_name|escape:'html'} &lt;{/if}{$q.recipient_email|escape:'html'}{if $q.recipient_name}&gt;{/if}
        </td>
        <td style="padding:7px 10px; color:#4a3f35;">{$q.send_at_fmt|escape:'html'}</td>
        <td style="padding:7px 10px;">
          <span style="background:{if $q.send_at < $smarty.now|date_format:'%Y-%m-%d %H:%M:%S'}#fef2f2{else}#fefce8{/if};
                        color:{if $q.send_at < $smarty.now|date_format:'%Y-%m-%d %H:%M:%S'}#991b1b{else}#854d0e{/if};
                        padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700;">
            {if $q.send_at < $smarty.now|date_format:'%Y-%m-%d %H:%M:%S'}{neria_admin key='send.queue_status_late'}{else}{neria_admin key='send.queue_status_planned'}{/if}
          </span>
        </td>
      </tr>
      {/foreach}
    </tbody>
  </table>
  <p style="margin:10px 0 0; font-size:11px; color:#a08060;">
    {neria_admin key='send.queue_cron_note'}
  </p>
</div>
{/if}

{* ── Prévisualisation (iframe en bas, hors form) ────────────────── *}
<div id="neria-preview-wrap" style="display:none; margin-top:28px;">
  <div style="padding:16px 20px 12px; background:#f9f6f1; border:1px solid #e8d5b0;
              border-radius:6px 6px 0 0; display:flex; align-items:center; justify-content:space-between;">
    <span style="font-size:12px; font-weight:700; letter-spacing:.06em; text-transform:uppercase;
                 color:#4a3f35; opacity:.75;">
      {neria_admin key='send.preview_section_title'}
    </span>
    {* Round 154 : aria-label — bouton icône-seule, un lecteur d'écran
       annonçait juste "bouton" en fermant la prévisualisation. *}
    <button type="button" id="neria-preview-close" aria-label="{neria_admin key='common.close'}" title="{neria_admin key='common.close'}"
            style="background:none; border:none; font-size:16px; cursor:pointer; color:#a08060;">✕</button>
  </div>
  <iframe id="neria-preview-frame"
          style="width:100%; border:1px solid #e8d5b0; border-top:none;
                 border-radius:0 0 6px 6px; min-height:600px; background:#fff;"
          src="about:blank">
  </iframe>
</div>

<script>
window.NERIA_SEND_L10N = {
  noOrder:                "{neria_admin key='send.customer_no_order' esc='javascript'}",
  lastOrderLabel:         "{neria_admin key='send.customer_last_order_label' esc='javascript'}",
  orderPrefix:            "{neria_admin key='send.autocomplete_order_prefix' esc='javascript'}",
  anniversaryWarnFirst:   "{neria_admin key='send.anniversary_warn_first' esc='javascript'}",
  anniversaryWarnRelationship: "{neria_admin key='send.anniversary_warn_relationship' esc='javascript'}",
  dateRequiredAlert:      "{neria_admin key='send.date_required_alert' esc='javascript'}",
  schedulePlannedFeedback:"{neria_admin key='send.schedule_planned_feedback' esc='javascript'}",
  scheduleSendBtnLabel:   "{neria_admin key='send.schedule_send_btn_label' esc='javascript'}",
  sendBtnLabel:           "{neria_admin key='send.send_btn' esc='javascript'}",
  processingLabel:        "{neria_admin key='send.processing_label' esc='javascript'}",
  processQueueBtn:        "{neria_admin key='send.process_queue_btn' esc='javascript'}",
  processedErrorPrefix:   "{neria_admin key='send.processed_error_prefix' esc='javascript'}",
  processedResultSent:    "{neria_admin key='send.processed_result_sent' esc='javascript'}",
  processedResultNone:    "{neria_admin key='send.processed_result_none' esc='javascript'}",
  processedUnexpected:    "{neria_admin key='send.processed_unexpected_response' esc='javascript'}"
};
{literal}
(function () {
  var sel        = document.getElementById('neria-send-template');
  var emailInput = document.getElementById('neria-send-email');
  var sendBtn    = document.getElementById('neria-send-btn');
  var sendLabel  = document.getElementById('neria-send-btn-label');
  var sendAtHidden = document.getElementById('neria-send-at-hidden');

  // Guards
  var staticWarn = document.getElementById('neria-anniversary-static-warn');
  var staticText = document.getElementById('neria-anniversary-static-text');
  var annGuardBox  = document.getElementById('neria-anniversary-guard');
  var annGuardText = document.getElementById('neria-anniversary-guard-text');
  var dupGuardBox  = document.getElementById('neria-duplicate-guard');
  var dupGuardText = document.getElementById('neria-duplicate-guard-text');
  var prefGuardBox  = document.getElementById('neria-preferences-guard');
  var prefGuardText = document.getElementById('neria-preferences-guard-text');
  var cooldownNoticeBox  = document.getElementById('neria-cooldown-notice');
  var cooldownNoticeText = document.getElementById('neria-cooldown-notice-text');

  // Customer card + autocomplete
  var dropdown   = document.getElementById('neria-autocomplete-dropdown');
  var custCard   = document.getElementById('neria-customer-card');

  // Planning
  var schedToggle  = document.getElementById('neria-schedule-toggle');
  var schedBody    = document.getElementById('neria-schedule-body');
  var schedArrow   = document.getElementById('neria-schedule-arrow');
  var schedDate    = document.getElementById('neria-schedule-date');
  var schedTime    = document.getElementById('neria-schedule-time');
  var schedConfirm = document.getElementById('neria-schedule-confirm');
  var schedCancel  = document.getElementById('neria-schedule-cancel');
  var schedFeedback= document.getElementById('neria-schedule-feedback');
  var schedBadge   = document.getElementById('neria-schedule-badge');

  // Preview
  var previewBtn   = document.getElementById('neria-preview-btn');
  var previewWrap  = document.getElementById('neria-preview-wrap');
  var previewFrame = document.getElementById('neria-preview-frame');
  var previewClose = document.getElementById('neria-preview-close');

  if (!sel) { return; }

  var ajaxTimer = null;
  var acTimer   = null;
  var currentBlocked = false;

  function getBase() {
    return window.location.href.split('#')[0].replace(/[?&]neria_action=[^&]*/g, '');
  }
  function ajaxUrl(action, extra) {
    var base = getBase();
    var sep  = base.indexOf('?') !== -1 ? '&' : '?';
    return base + sep + 'neria_action=' + action + (extra || '');
  }

  // ── Blocage bouton ────────────────────────────────────────────
  function setBlocked(blocked) {
    currentBlocked = blocked;
    if (sendBtn) {
      sendBtn.disabled = blocked;
      sendBtn.style.opacity = blocked ? '0.45' : '';
      sendBtn.style.cursor  = blocked ? 'not-allowed' : '';
    }
  }

  // ── Affichage guards ──────────────────────────────────────────
  function showBox(box, textEl, data, isOrange) {
    if (!box || !textEl) { return; }
    if (!data.message) { box.style.display = 'none'; return; }
    textEl.innerHTML = data.message;
    box.style.background = data.blocked
      ? '#fef2f2' : (isOrange ? '#fff8e1' : '#fff8e1');
    box.style.borderLeft = data.blocked
      ? '3px solid #dc2626' : '3px solid #f59e0b';
    box.style.color = data.blocked ? '#7f1d1d' : '#78350f';
    box.style.display = '';
    if (data.blocked) { setBlocked(true); }
  }
  function hideBox(box) { if (box) { box.style.display = 'none'; } }

  // ── AJAX : doublon générique ──────────────────────────────────
  function checkDuplicate() {
    var tpl   = sel.value;
    var email = (emailInput ? emailInput.value : '').trim();
    if (!email || email.indexOf('@') === -1) { hideBox(dupGuardBox); return; }
    fetch(ajaxUrl('check_send_duplicate',
      '&neria_template=' + encodeURIComponent(tpl)
      + '&neria_email='  + encodeURIComponent(email)),
      { credentials: 'same-origin' }
    ).then(function (r) { return r.json(); })
     .then(function (d) { showBox(dupGuardBox, dupGuardText, d, true); })
     .catch(function () { hideBox(dupGuardBox); });
  }

  // ── AJAX : garde anniversaire ─────────────────────────────────
  var GUARD_TEMPLATES = ['first_anniversary', 'relationship_anniversary'];

  function checkAnniversaryGuard() {
    var tpl = sel.value;
    if (GUARD_TEMPLATES.indexOf(tpl) === -1) { hideBox(annGuardBox); setBlocked(false); return; }
    var email = (emailInput ? emailInput.value : '').trim();
    if (!email || email.indexOf('@') === -1) { hideBox(annGuardBox); setBlocked(false); return; }
    fetch(ajaxUrl('check_anniversary_guard',
      '&neria_email=' + encodeURIComponent(email)
      + '&neria_template=' + encodeURIComponent(tpl)),
      { credentials: 'same-origin' }
    ).then(function (r) { return r.json(); })
     .then(function (d) {
       showBox(annGuardBox, annGuardText, d, false);
       if (d.blocked) { setBlocked(true); } else { setBlocked(false); }
     })
     .catch(function () { hideBox(annGuardBox); setBlocked(false); });
  }

  // ── AJAX : garde centre de préférences ─────────────────────────
  function checkPreferencesGuard() {
    var tpl = sel.value;
    var email = (emailInput ? emailInput.value : '').trim();
    if (!email || email.indexOf('@') === -1) { hideBox(prefGuardBox); return; }
    fetch(ajaxUrl('check_preferences_guard',
      '&neria_email=' + encodeURIComponent(email)
      + '&neria_template=' + encodeURIComponent(tpl)),
      { credentials: 'same-origin' }
    ).then(function (r) { return r.json(); })
     .then(function (d) {
       showBox(prefGuardBox, prefGuardText, d, false);
       if (d.blocked) { setBlocked(true); }
     })
     .catch(function () { hideBox(prefGuardBox); });
  }

  // ── AJAX : bandeau informatif Mode Silence (jamais bloquant) ───
  function checkCooldownNotice() {
    var email = (emailInput ? emailInput.value : '').trim();
    if (!email || email.indexOf('@') === -1) { hideBox(cooldownNoticeBox); return; }
    fetch(ajaxUrl('check_cooldown_guest_notice',
      '&neria_email=' + encodeURIComponent(email)),
      { credentials: 'same-origin' }
    ).then(function (r) { return r.json(); })
     .then(function (d) {
       if (!cooldownNoticeBox || !cooldownNoticeText) { return; }
       if (!d.notice || !d.message) { cooldownNoticeBox.style.display = 'none'; return; }
       cooldownNoticeText.innerHTML = d.message;
       cooldownNoticeBox.style.display = '';
     })
     .catch(function () { hideBox(cooldownNoticeBox); });
  }

  function checkAllGuards() {
    setBlocked(false);
    hideBox(dupGuardBox);
    hideBox(annGuardBox);
    hideBox(prefGuardBox);
    hideBox(cooldownNoticeBox);
    clearTimeout(ajaxTimer);
    ajaxTimer = setTimeout(function () {
      checkDuplicate();
      checkAnniversaryGuard();
      checkPreferencesGuard();
      checkCooldownNotice();
    }, 500);
  }

  // ── Auto-complétion client ────────────────────────────────────
  function showCustomerCard(c) {
    if (!custCard) { return; }
    var label = '<strong>' + esc(c.firstname) + ' ' + esc(c.lastname) + '</strong>'
              + ' — ' + esc(c.email);
    if (c.last_order_ref) {
      label += ' &nbsp;·&nbsp; ' + NERIA_SEND_L10N.lastOrderLabel + ' <strong>' + esc(c.last_order_ref) + '</strong>'
             + (c.last_order_date ? ' (' + esc(c.last_order_date) + ')' : '');
    } else {
      label += ' &nbsp;·&nbsp; <em>' + NERIA_SEND_L10N.noOrder + '</em>';
    }
    custCard.innerHTML = '👤 ' + label;
    custCard.style.display = '';
  }
  function hideCustomerCard() { if (custCard) { custCard.style.display = 'none'; } }

  function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  // Round 154 : index de l'option actuellement mise en évidence au clavier
  // (flèches haut/bas) — -1 = aucune sélection active.
  var acActiveIndex = -1;

  function acSelectCustomer(c) {
    if (emailInput) { emailInput.value = c.email; }
    dropdown.style.display = 'none';
    if (emailInput) { emailInput.setAttribute('aria-expanded', 'false'); }
    acActiveIndex = -1;
    showCustomerCard(c);
    checkAllGuards();
  }

  function acSetActive(index) {
    var items = dropdown.querySelectorAll('[role="option"]');
    if (!items.length) { return; }
    items.forEach(function (el) { el.style.background = ''; el.setAttribute('aria-selected', 'false'); });
    acActiveIndex = Math.max(0, Math.min(index, items.length - 1));
    var active = items[acActiveIndex];
    active.style.background = '#f9f6f1';
    active.setAttribute('aria-selected', 'true');
    active.scrollIntoView({ block: 'nearest' });
    if (emailInput) { emailInput.setAttribute('aria-activedescendant', active.id); }
  }

  function buildDropdown(results) {
    if (!dropdown) { return; }
    dropdown.innerHTML = '';
    acActiveIndex = -1;
    if (emailInput) { emailInput.removeAttribute('aria-activedescendant'); }
    if (!results.length) {
      dropdown.style.display = 'none';
      if (emailInput) { emailInput.setAttribute('aria-expanded', 'false'); }
      return;
    }
    results.forEach(function (c, idx) {
      var item = document.createElement('div');
      item.id = 'neria-ac-option-' + idx;
      item.setAttribute('role', 'option');
      item.setAttribute('aria-selected', 'false');
      item.style.cssText = 'padding:9px 14px; cursor:pointer; border-bottom:1px solid #f0e8d8;'
                         + 'font-size:12px; color:#4a3f35; line-height:1.5;';
      item.innerHTML = '<strong>' + esc(c.firstname) + ' ' + esc(c.lastname) + '</strong>'
                     + ' &lt;' + esc(c.email) + '&gt;'
                     + (c.last_order_ref
                         ? '<br><span style="color:#a08060;">' + NERIA_SEND_L10N.orderPrefix + ' ' + esc(c.last_order_ref) + (c.last_order_date ? ' · ' + esc(c.last_order_date) : '') + '</span>'
                         : '<br><span style="color:#a08060;font-style:italic;">' + NERIA_SEND_L10N.noOrder + '</span>');
      item.addEventListener('mouseenter', function () { acSetActive(idx); });
      item.addEventListener('mouseleave', function () { this.style.background = ''; this.setAttribute('aria-selected', 'false'); });
      item.addEventListener('mousedown', function (e) {
        e.preventDefault();
        acSelectCustomer(c);
      });
      dropdown.appendChild(item);
    });
    dropdown.style.display = '';
    if (emailInput) { emailInput.setAttribute('aria-expanded', 'true'); }
    // Round 154 : conserve la liste de résultats pour la navigation clavier
    // (ArrowDown/ArrowUp/Enter, gérée sur l'input — voir plus bas).
    dropdown._neriaResults = results;
  }

  function runAutocomplete() {
    var q = (emailInput ? emailInput.value : '').trim();
    if (q.length < 2) { if (dropdown) { dropdown.style.display = 'none'; } return; }
    clearTimeout(acTimer);
    acTimer = setTimeout(function () {
      fetch(ajaxUrl('customer_autocomplete', '&q=' + encodeURIComponent(q)),
        { credentials: 'same-origin' }
      ).then(function (r) { return r.json(); })
       .then(function (d) { buildDropdown(d); })
       .catch(function () { if (dropdown) { dropdown.style.display = 'none'; } });
    }, 280);
  }

  if (emailInput) {
    emailInput.addEventListener('input', function () {
      hideCustomerCard();
      runAutocomplete();
    });
    emailInput.addEventListener('blur', function () {
      setTimeout(function () { if (dropdown) { dropdown.style.display = 'none'; } }, 200);
      checkAllGuards();
    });
    // Round 154 : navigation clavier de la liste d'auto-complétion
    // (ArrowDown/ArrowUp déplacent la sélection en évidence, Entrée
    // valide, Échap ferme) — auparavant totalement inutilisable sans
    // souris malgré un affichage visuel correct des suggestions.
    emailInput.addEventListener('keydown', function (e) {
      if (!dropdown || dropdown.style.display === 'none') { return; }
      var results = dropdown._neriaResults || [];
      if (!results.length) { return; }
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        acSetActive(acActiveIndex + 1);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        acSetActive(acActiveIndex - 1);
      } else if (e.key === 'Enter') {
        if (acActiveIndex >= 0 && acActiveIndex < results.length) {
          e.preventDefault();
          acSelectCustomer(results[acActiveIndex]);
        }
      } else if (e.key === 'Escape') {
        dropdown.style.display = 'none';
        emailInput.setAttribute('aria-expanded', 'false');
        acActiveIndex = -1;
      }
    });
  }

  // ── Refresh champs + guards au changement de template ────────
  function refresh() {
    var tpl    = sel.value;
    var fields = document.querySelectorAll('.neria-send-fields');
    for (var i = 0; i < fields.length; i++) {
      fields[i].style.display = (fields[i].getAttribute('data-tpl') === tpl) ? '' : 'none';
    }

    if (staticWarn && staticText) {
      if (tpl === 'first_anniversary') {
        staticText.innerHTML = NERIA_SEND_L10N.anniversaryWarnFirst;
        staticWarn.style.display = '';
      } else if (tpl === 'relationship_anniversary') {
        staticText.innerHTML = NERIA_SEND_L10N.anniversaryWarnRelationship;
        staticWarn.style.display = '';
      } else {
        staticWarn.style.display = 'none';
      }
    }

    checkAllGuards();
  }

  sel.addEventListener('change', refresh);
  refresh();

  // ── Planification différée ────────────────────────────────────
  if (schedToggle) {
    // Round 154 : extrait dans une fonction nommée + gestionnaire clavier
    // (Entrée/Espace) sur ce role="button" custom, et synchronise
    // aria-expanded — auparavant seul un clic souris pouvait ouvrir ce
    // panneau, le rendant totalement inatteignable au clavier.
    var toggleSchedule = function () {
      var open = schedBody.style.display !== 'none';
      schedBody.style.display = open ? 'none' : '';
      schedArrow.textContent = open ? '▼' : '▲';
      schedToggle.setAttribute('aria-expanded', open ? 'false' : 'true');
    };
    schedToggle.addEventListener('click', toggleSchedule);
    schedToggle.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
        e.preventDefault();
        toggleSchedule();
      }
    });
  }

  if (schedConfirm) {
    schedConfirm.addEventListener('click', function () {
      var d = schedDate ? schedDate.value : '';
      var t = schedTime ? schedTime.value : '09:00';
      if (!d) {
        alert(NERIA_SEND_L10N.dateRequiredAlert);
        return;
      }
      var dt = d + ' ' + t + ':00';
      if (sendAtHidden) { sendAtHidden.value = dt; }

      var dFmt = d.split('-').reverse().join('/');
      if (schedFeedback) {
        schedFeedback.textContent = NERIA_SEND_L10N.schedulePlannedFeedback.replace('{date}', dFmt).replace('{time}', t);
        schedFeedback.style.display = '';
      }
      if (schedBadge) { schedBadge.style.display = ''; }
      if (schedCancel) { schedCancel.style.display = ''; }
      if (schedConfirm) { schedConfirm.style.display = 'none'; }
      if (sendLabel) { sendLabel.textContent = NERIA_SEND_L10N.scheduleSendBtnLabel; }
    });
  }

  if (schedCancel) {
    schedCancel.addEventListener('click', function () {
      if (sendAtHidden) { sendAtHidden.value = ''; }
      if (schedFeedback) { schedFeedback.style.display = 'none'; }
      if (schedBadge) { schedBadge.style.display = 'none'; }
      schedCancel.style.display = 'none';
      if (schedConfirm) { schedConfirm.style.display = ''; }
      if (sendLabel) { sendLabel.textContent = NERIA_SEND_L10N.sendBtnLabel; }
    });
  }

  // ── Prévisualisation ──────────────────────────────────────────
  if (previewBtn) {
    previewBtn.addEventListener('click', function () {
      var tpl   = sel.value;
      var email = emailInput ? emailInput.value.trim() : '';
      var url   = ajaxUrl('preview_manual',
        '&neria_template=' + encodeURIComponent(tpl)
        + '&neria_email='  + encodeURIComponent(email));
      if (previewFrame) { previewFrame.src = url; }
      if (previewWrap) { previewWrap.style.display = ''; }
      previewWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }

  if (previewClose) {
    previewClose.addEventListener('click', function () {
      if (previewWrap) { previewWrap.style.display = 'none'; }
      if (previewFrame) { previewFrame.src = 'about:blank'; }
    });
  }

  // ── Traitement manuel de la file ─────────────────────────────
  var processQueueBtn    = document.getElementById('neria-process-queue-btn');
  var processQueueResult = document.getElementById('neria-process-queue-result');
  if (processQueueBtn) {
    processQueueBtn.addEventListener('click', function () {
      processQueueBtn.disabled = true;
      processQueueBtn.textContent = NERIA_SEND_L10N.processingLabel;
      fetch(ajaxUrl('process_queue_now'), { method: 'POST', credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (processQueueResult) {
            if (!d.ok && d.error) {
              processQueueResult.textContent = NERIA_SEND_L10N.processedErrorPrefix.replace('{error}', d.error);
              processQueueResult.style.background = '#fef2f2';
              processQueueResult.style.borderColor = '#dc2626';
              processQueueResult.style.color = '#7f1d1d';
            } else {
              processQueueResult.textContent = d.sent > 0
                ? NERIA_SEND_L10N.processedResultSent.replace('{n}', d.sent)
                : NERIA_SEND_L10N.processedResultNone;
              processQueueResult.style.background = '#dcfce7';
              processQueueResult.style.borderColor = '#16a34a';
              processQueueResult.style.color = '#14532d';
            }
            processQueueResult.style.display = '';
          }
        })
        .catch(function (err) {
          if (processQueueResult) {
            processQueueResult.textContent = NERIA_SEND_L10N.processedUnexpected;
            processQueueResult.style.background = '#fef2f2';
            processQueueResult.style.borderColor = '#dc2626';
            processQueueResult.style.color = '#7f1d1d';
            processQueueResult.style.display = '';
          }
        })
        .finally(function () {
          processQueueBtn.disabled = false;
          processQueueBtn.textContent = NERIA_SEND_L10N.processQueueBtn;
        });
    });
  }

})();
{/literal}
</script>
