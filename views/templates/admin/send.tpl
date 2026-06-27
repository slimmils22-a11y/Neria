{**
 * NERIA — send.tpl
 * Envoi manuel d'un template à un client (vague 1).
 * Le marchand choisit un template + un destinataire, remplit les champs
 * de contenu propres au template, et envoie. L'email passe par le hook
 * Neria (design + traduction + détection de langue).
 *}

<div class="neria-section">
  <h2 class="neria-section__title">{neria_admin key='nav.manual_send'}</h2>
  <p class="neria-section__desc">
    {neria_admin key='send.desc'}
  </p>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="send_manual">
    <input type="hidden" name="neria_tab"    value="send">

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

        {* Avertissement statique doublon (affiché dès sélection du template) *}
        <div id="neria-anniversary-static-warn"
             style="display:none; margin-top:10px; padding:11px 14px;
                    background:#fff8e1; border-left:3px solid #f59e0b;
                    border-radius:4px; font-size:12px; color:#78350f; line-height:1.6;">
          <span id="neria-anniversary-static-text"></span>
        </div>
      </div>

      {* ── Destinataire ───────────────────────────────────────── *}
      <div class="neria-form-group">
        <label class="neria-label" for="neria-send-email">
          {neria_admin key='send.email_label'}
        </label>
        <input type="email" id="neria-send-email" name="neria_email" class="neria-input"
               placeholder="client@exemple.com" required
               value="{if isset($smarty.post.neria_email)}{$smarty.post.neria_email|escape:'html'}{/if}">
        <span class="neria-hint">{neria_admin key='send.email_hint'}</span>

        {* Avertissement dynamique par client (chargé en AJAX après saisie email) *}
        <div id="neria-anniversary-guard"
             style="display:none; margin-top:10px; padding:11px 14px;
                    border-radius:4px; font-size:12px; line-height:1.6;">
          <span id="neria-anniversary-guard-text"></span>
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
               placeholder="NER-000123"
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
                  <label class="neria-label">{$f.label|escape:'html'}</label>
                  <input type="text" class="neria-input" name="neria_var[{$f.key}]"
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

    {* ── Message personnalisé (optionnel, valable pour tous les templates) ── *}
    <div class="neria-form-group neria-form-group--full" style="margin-top:18px;">
      <label class="neria-label" for="neria-send-message">
        {neria_admin key='send.custom_message'}
        <span class="neria-hint">({neria_admin key='send.custom_message_hint'})</span>
      </label>
      <textarea id="neria-send-message" name="neria_var[custom_message]" class="neria-input" rows="3"
                placeholder="{neria_admin key='send.custom_message_ph'}">{if isset($smarty.post.neria_var) && isset($smarty.post.neria_var.custom_message)}{$smarty.post.neria_var.custom_message|escape:'html'}{/if}</textarea>
    </div>

    <div style="margin-top:20px;">
      <button type="submit" id="neria-send-btn" class="neria-btn neria-btn--primary">
        <span class="neria-icon">✉</span>
        {neria_admin key='send.send_btn'}
      </button>
    </div>

  </form>
</div>

<script>
{literal}
(function () {
  var sel        = document.getElementById('neria-send-template');
  var emailInput = document.getElementById('neria-send-email');
  var sendBtn    = document.getElementById('neria-send-btn');
  var staticWarn = document.getElementById('neria-anniversary-static-warn');
  var staticText = document.getElementById('neria-anniversary-static-text');
  var guardBox   = document.getElementById('neria-anniversary-guard');
  var guardText  = document.getElementById('neria-anniversary-guard-text');

  if (!sel) { return; }

  var ajaxTimer = null;
  var currentBlocked = false;

  function setBlocked(blocked) {
    currentBlocked = blocked;
    if (sendBtn) {
      sendBtn.disabled = blocked;
      sendBtn.style.opacity = blocked ? '0.45' : '';
      sendBtn.style.cursor  = blocked ? 'not-allowed' : '';
    }
  }

  function showGuard(data) {
    if (!guardBox || !guardText) { return; }
    if (!data.message) {
      guardBox.style.display = 'none';
      setBlocked(false);
      return;
    }
    guardText.innerHTML = data.message;
    guardBox.style.background  = data.blocked ? '#fef2f2' : '#fff8e1';
    guardBox.style.borderLeft  = data.blocked ? '3px solid #dc2626' : '3px solid #f59e0b';
    guardBox.style.color       = data.blocked ? '#7f1d1d' : '#78350f';
    guardBox.style.display     = '';
    setBlocked(data.blocked);
  }

  function hideGuard() {
    if (guardBox) { guardBox.style.display = 'none'; }
    setBlocked(false);
  }

  var GUARD_TEMPLATES = ['first_anniversary', 'relationship_anniversary'];

  function checkGuard() {
    var tpl = sel.value;
    if (GUARD_TEMPLATES.indexOf(tpl) === -1) { hideGuard(); return; }
    var email = (emailInput ? emailInput.value : '').trim();
    if (!email || email.indexOf('@') === -1) { hideGuard(); return; }

    clearTimeout(ajaxTimer);
    ajaxTimer = setTimeout(function () {
      var base = window.location.href.replace(/[?&]neria_action=[^&]*/g, '');
      var sep  = base.indexOf('?') !== -1 ? '&' : '?';
      var url  = base + sep + 'neria_action=check_anniversary_guard'
               + '&neria_email=' + encodeURIComponent(email)
               + '&neria_template=' + encodeURIComponent(tpl);
      fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) { showGuard(data); })
        .catch(function () { hideGuard(); });
    }, 500);
  }

  function refresh() {
    var tpl = sel.value;
    var fields = document.querySelectorAll('.neria-send-fields');
    for (var i = 0; i < fields.length; i++) {
      fields[i].style.display = (fields[i].getAttribute('data-tpl') === tpl) ? '' : 'none';
    }

    // Avertissement statique dès sélection du template
    if (staticWarn && staticText) {
      if (tpl === 'first_anniversary') {
        staticText.innerHTML = '⚠ <strong>Doublon potentiel :</strong> la fonctionnalité '
          + '<em>Anniversaire de la relation client</em> envoie également un email chaque année '
          + 'à la date du 1er achat. Saisissez l\'adresse email du client pour vérifier si un '
          + 'email automatique a déjà été envoyé cette année.';
        staticWarn.style.display = '';
      } else if (tpl === 'relationship_anniversary') {
        staticText.innerHTML = '⚠ <strong>Doublon potentiel :</strong> le template '
          + '<em>Premier anniversaire client</em> (first_anniversary) s\'envoie également à J+365 '
          + 'du 1er achat. Si les deux sont actifs, un client peut recevoir deux emails le même jour '
          + 'lors de sa 1ère année.';
        staticWarn.style.display = '';
      } else {
        staticWarn.style.display = 'none';
      }
    }

    // Garde dynamique pour les deux templates anniversaire
    if (GUARD_TEMPLATES.indexOf(tpl) === -1) { hideGuard(); }
    else { checkGuard(); }
  }

  sel.addEventListener('change', refresh);
  if (emailInput) { emailInput.addEventListener('blur', checkGuard); }
  refresh();
})();
{/literal}
</script>
