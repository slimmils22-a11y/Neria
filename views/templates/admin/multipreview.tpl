{**
 * NERIA — multipreview.tpl
 * Prévisualisation multi-client : iframes chargées via getpreview.php
 *}

{* ── Formulaire de sélection ────────────────────────────────── *}
<div class="neria-section">
  <form method="post" action="{$smarty.server.REQUEST_URI}" id="mp-form">
    <input type="hidden" name="neria_action" value="multipreview_render">
    <input type="hidden" name="neria_tab"    value="multipreview">

    <div class="neria-trad-selectors">

      <div class="neria-form-group">
        <label class="neria-label" for="mp-template">{neria_admin key='common.template'}</label>
        <select id="mp-template" name="mp_template" class="neria-select">
          {foreach $template_labels as $key => $label}
            <option value="{$key}"
              {if isset($mp_selected_template) && $mp_selected_template === $key}selected{/if}>
              {$label}
            </option>
          {/foreach}
        </select>
      </div>

      <div class="neria-form-group">
        <label class="neria-label" for="mp-lang">{neria_admin key='common.language'}</label>
        <select id="mp-lang" name="mp_lang" class="neria-select">
          {foreach $lang_labels as $code => $name}
            <option value="{$code}"
              {if isset($mp_selected_lang) && $mp_selected_lang === $code}selected{/if}>
              {$lang_flags[$code]|default:''} {$name}
            </option>
          {/foreach}
        </select>
      </div>

      <button type="submit" class="neria-btn neria-btn--primary">
        {neria_admin key='multipreview.render_btn'}
      </button>

    </div>
  </form>
  <p class="neria-section__desc" style="margin-top:8px;">
    {neria_admin key='multipreview.desc'}
  </p>
</div>

{* ── Grille de prévisualisations ────────────────────────────── *}
{if isset($mp_token) && $mp_token}

  <div class="neria-mp-grid">
    {foreach $mp_clients as $clientId => $ci}
      {assign var="meta" value=$mp_previews_meta[$clientId]|default:[]}

      <div class="neria-mp-card">

        <div class="neria-mp-card__header" style="border-left:3px solid {$ci.color};">
          <span class="neria-mp-card__icon" style="background:{$ci.color};">{$ci.icon}</span>
          <span class="neria-mp-card__name">{$ci.name}</span>
          {if ($meta.issues|default:0) > 0}
            <span class="neria-mp-card__badge" style="background:#f57f17;color:#fff;">
              {$meta.issues} ⚠
            </span>
          {/if}
        </div>

        <div class="neria-mp-card__viewport">
          <iframe
            src="{$mp_preview_base}?client={$clientId|escape:'url'}&amp;token={$mp_token|escape:'url'}"
            class="neria-mp-frame"
            sandbox="allow-same-origin"
            title="{$ci.name}"></iframe>
        </div>

        <p class="neria-mp-card__desc">{$ci.support|escape:'html'}</p>

      </div>
    {/foreach}
  </div>

{else}

  <div class="neria-empty-state">
    <span class="neria-empty-state__icon">◩</span>
    <p>{neria_admin key='multipreview.empty'}</p>
  </div>

{/if}

{* ── Section API (optionnelle) ──────────────────────────────── *}
<div class="neria-section" style="margin-top:8px;">
  <h2 class="neria-section__title">{neria_admin key='multipreview.api_title'}</h2>
  <p class="neria-section__desc">{neria_admin key='multipreview.api_desc'}</p>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="save_multipreview_keys">
    <input type="hidden" name="neria_tab"    value="multipreview">

    <div class="neria-form-group" style="max-width:480px; margin-bottom:12px;">
      <label class="neria-label" for="mp-litmus-key">
        Litmus API Key
      </label>
      <input type="password"
             id="mp-litmus-key"
             name="litmus_key"
             class="neria-input"
             placeholder="sk_live_…"
             autocomplete="new-password">
    </div>

    <div class="neria-form-group" style="max-width:480px; margin-bottom:20px;">
      <label class="neria-label" for="mp-eoa-key">
        Email on Acid — account_id:api_password
      </label>
      <input type="password"
             id="mp-eoa-key"
             name="eoa_key"
             class="neria-input"
             placeholder="12345:abc…"
             autocomplete="new-password">
    </div>

    <button type="submit" class="neria-btn neria-btn--secondary neria-btn--sm">
      {neria_admin key='translations.save'}
    </button>
  </form>

  {if isset($mp_has_litmus) && $mp_has_litmus}
    <p style="margin-top:12px; font-size:12px; color:var(--neria-success);">
      ✓ Clé Litmus configurée — {neria_admin key='multipreview.litmus_btn'} disponible après prévisualisation.
    </p>
  {/if}
  {if isset($mp_has_eoa) && $mp_has_eoa}
    <p style="margin-top:8px; font-size:12px; color:var(--neria-success);">
      ✓ Clé Email on Acid configurée — {neria_admin key='multipreview.eoa_btn'} disponible après prévisualisation.
    </p>
  {/if}
</div>
