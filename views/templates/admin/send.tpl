{**
 * NERIA — send.tpl
 * Envoi manuel d'un template à un client (vague 1).
 * Le marchand choisit un template + un destinataire, remplit les champs
 * de contenu propres au template, et envoie. L'email passe par le hook
 * Neria (design + traduction + détection de langue).
 *}

<div class="neria-section">
  <h2 class="neria-section__title">{l s='Envoi manuel' mod='neria'}</h2>
  <p class="neria-section__desc">
    {l s='Envoyez à un client un email « à la demande » (message d\'artisan, invitation privée, excuses, rappel produit…). L\'email est mis en forme, traduit et envoyé dans la langue du client automatiquement.' mod='neria'}
  </p>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="send_manual">
    <input type="hidden" name="neria_tab"    value="send">

    <div class="neria-form-grid">

      {* ── Template ───────────────────────────────────────────── *}
      <div class="neria-form-group">
        <label class="neria-label" for="neria-send-template">
          {l s='Template à envoyer' mod='neria'}
        </label>
        <select id="neria-send-template" name="neria_template" class="neria-select">
          {foreach $send_templates as $key => $lbl}
            <option value="{$key}" data-label="{$lbl|escape:'html'}"
              {if isset($smarty.post.neria_template) && $smarty.post.neria_template == $key}selected{/if}>
              {$lbl} ({$key})
            </option>
          {/foreach}
        </select>
      </div>

      {* ── Destinataire ───────────────────────────────────────── *}
      <div class="neria-form-group">
        <label class="neria-label" for="neria-send-email">
          {l s='Email du client' mod='neria'}
        </label>
        <input type="email" id="neria-send-email" name="neria_email" class="neria-input"
               placeholder="client@exemple.com" required
               value="{if isset($smarty.post.neria_email)}{$smarty.post.neria_email|escape:'html'}{/if}">
        <span class="neria-hint">{l s='La langue de l\'email est détectée automatiquement à partir de ce client.' mod='neria'}</span>
      </div>

      {* ── Sujet ──────────────────────────────────────────────── *}
      <div class="neria-form-group">
        <label class="neria-label" for="neria-send-subject">
          {l s='Sujet de l\'email' mod='neria'}
        </label>
        <input type="text" id="neria-send-subject" name="neria_subject" class="neria-input"
               value="{if isset($smarty.post.neria_subject)}{$smarty.post.neria_subject|escape:'html'}{/if}">
      </div>

      {* ── Commande (optionnel) ───────────────────────────────── *}
      <div class="neria-form-group">
        <label class="neria-label" for="neria-send-order">
          {l s='Référence commande' mod='neria'} <span class="neria-hint">({l s='optionnel' mod='neria'})</span>
        </label>
        <input type="text" id="neria-send-order" name="neria_order_ref" class="neria-input"
               placeholder="NER-000123"
               value="{if isset($smarty.post.neria_order_ref)}{$smarty.post.neria_order_ref|escape:'html'}{/if}">
      </div>

    </div>

    {* ── Champs de contenu spécifiques au template ────────────── *}
    <div class="neria-send-content" style="margin-top:18px;">
      {foreach $send_editable_map as $tpl => $vars}
        <div class="neria-send-fields" data-tpl="{$tpl}" style="display:none;">
          {if $vars}
            <div class="neria-form-grid">
              {foreach $vars as $v}
                <div class="neria-form-group">
                  <label class="neria-label">{$v|replace:'_':' '|capitalize}</label>
                  <input type="text" class="neria-input" name="neria_var[{$v}]"
                         value="{if isset($smarty.post.neria_var) && isset($smarty.post.neria_var[$v])}{$smarty.post.neria_var[$v]|escape:'html'}{/if}">
                </div>
              {/foreach}
            </div>
          {else}
            <p class="neria-section__desc" style="margin:0;">
              {l s='Ce template n\'a pas de champ à remplir — il est prêt à l\'envoi.' mod='neria'}
            </p>
          {/if}
        </div>
      {/foreach}
    </div>

    <div style="margin-top:20px;">
      <button type="submit" class="neria-btn neria-btn--primary">
        <span class="neria-icon">✉</span>
        {l s='Envoyer l\'email' mod='neria'}
      </button>
    </div>

  </form>
</div>

<script>
{literal}
(function () {
  var sel  = document.getElementById('neria-send-template');
  var subj = document.getElementById('neria-send-subject');
  if (!sel) { return; }

  function refresh(setSubject) {
    var tpl = sel.value;
    var fields = document.querySelectorAll('.neria-send-fields');
    for (var i = 0; i < fields.length; i++) {
      fields[i].style.display = (fields[i].getAttribute('data-tpl') === tpl) ? '' : 'none';
    }
    if (setSubject && subj) {
      var opt = sel.options[sel.selectedIndex];
      subj.value = opt ? (opt.getAttribute('data-label') || '') : '';
    }
  }

  sel.addEventListener('change', function () { refresh(true); });
  // Au chargement : afficher les bons champs, pré-remplir le sujet s'il est vide
  refresh(subj && subj.value === '');
})();
{/literal}
</script>
