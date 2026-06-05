{**
 * NERIA — translations.tpl
 * Onglet Traductions — Édition des textes par template et langue
 * Fix 3 : IDs uniques sur les selects (neria-trad-* uniquement)
 *}

<div class="neria-section">
  <div class="neria-trad-selectors">

    <div class="neria-form-group">
      <label class="neria-label" for="neria-trad-template">
        {l s='Template' mod='neria'}
      </label>
      <select id="neria-trad-template" name="trad_template" class="neria-select">
        <option value="">{l s='— Choisir un template —' mod='neria'}</option>
        {foreach $template_labels as $key => $label}
          <option value="{$key}"
            {if isset($selected_template) && $selected_template === $key}selected{/if}>
            {$label}
          </option>
        {/foreach}
      </select>
    </div>

    <div class="neria-form-group">
      <label class="neria-label" for="neria-trad-lang">
        {l s='Langue' mod='neria'}
      </label>
      <select id="neria-trad-lang" name="trad_lang" class="neria-select">
        {foreach $lang_labels as $code => $name}
          <option value="{$code}"
            {if isset($selected_lang) && $selected_lang === $code}selected{/if}>
            {$lang_flags[$code]|default:''} {$name}
          </option>
        {/foreach}
      </select>
    </div>

    <button type="button" class="neria-btn neria-btn--secondary"
            id="neria-trad-load">
      {l s='Charger' mod='neria'}
    </button>

  </div>
</div>

{if isset($translations) && $translations}

  <div class="neria-section" id="neria-trad-editor">
    <div class="neria-trad-header">
      <h2 class="neria-section__title">
        {$template_labels[$selected_template]|default:$selected_template}
        <span class="neria-lang-chip">
          {$lang_flags[$selected_lang]|default:''}
          {$lang_labels[$selected_lang]|default:$selected_lang}
        </span>
      </h2>

      <div class="neria-trad-header__actions">
        <button type="button"
                class="neria-btn neria-btn--ghost neria-btn--sm"
                id="neria-trad-reset"
                data-template="{$selected_template}"
                data-lang="{$selected_lang}"
                data-confirm="{l s='Réinitialiser ce template aux textes Neria par défaut ?' mod='neria'}">
          {l s='Réinitialiser ce template' mod='neria'}
        </button>
      </div>
    </div>

    <form method="post" action="{$smarty.server.REQUEST_URI}">
      <input type="hidden" name="neria_action"   value="save_translations">
      <input type="hidden" name="neria_tab"       value="translations">
      <input type="hidden" name="trad_template"   value="{$selected_template}">
      <input type="hidden" name="trad_lang"       value="{$selected_lang}">

      <div class="neria-trad-fields">
        {foreach $translations as $key => $value}
          <div class="neria-form-group neria-trad-field">

            <label class="neria-label" for="trad_field_{$key}">
              {$key}
              {if $is_custom[$key]|default:false}
                <span class="neria-badge neria-badge--accent">
                  {l s='personnalisé' mod='neria'}
                </span>
              {/if}
            </label>

            {if $value|strlen > 120}
              <textarea id="trad_field_{$key}"
                        name="fields[{$key}]"
                        class="neria-textarea neria-textarea--auto"
                        rows="3">{$value|escape:'html'}</textarea>
            {else}
              <input type="text"
                     id="trad_field_{$key}"
                     name="fields[{$key}]"
                     class="neria-input"
                     value="{$value|escape:'html'}">
            {/if}

          </div>
        {/foreach}
      </div>

      <div class="neria-form-actions neria-form-actions--sticky">
        <button type="submit" class="neria-btn neria-btn--primary">
          {l s='Sauvegarder les traductions' mod='neria'}
        </button>
      </div>

    </form>
  </div>

{else}

  <div class="neria-empty-state">
    <span class="neria-empty-state__icon">❡</span>
    <p>{l s='Sélectionnez un template et une langue pour éditer les textes.' mod='neria'}</p>
  </div>

{/if}
