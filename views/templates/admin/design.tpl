{**
 * NERIA — design.tpl
 * Onglet Design — Couleurs, logo, mode sombre, largeur conteneur
 * Fix 5 : iframe avec src par défaut (preview fonctionnel sans JS)
 * i18n : libellés via {neria_admin key='...'} (18 langues, AdminTranslator)
 *}

<div class="neria-design-wrap">
  <div class="neria-design-layout">

    {* ── Panneau de configuration ─────────────────────────────── *}
    <div class="neria-design-panel">

      <form method="post" action="{$smarty.server.REQUEST_URI}"
            id="neria-design-form" enctype="multipart/form-data">
        <input type="hidden" name="neria_action" value="save_design">
        <input type="hidden" name="neria_tab"    value="design">

        {* Couleurs *}
        <div class="neria-section">
          <h2 class="neria-section__title">{neria_admin key='design.colors_title'}</h2>
          <div class="neria-color-grid">

            <div class="neria-form-group">
              <label class="neria-label" for="color_background">
                {neria_admin key='design.color_background'}
              </label>
              <div class="neria-color-input-wrap">
                <input type="color" id="color_background" name="color_background"
                       class="neria-color-picker" data-sync="color_background"
                       value="{$design.color_background|default:'#f4f1eb'}">
                <input type="text" class="neria-input neria-input--hex"
                       value="{$design.color_background|default:'#f4f1eb'}"
                       data-sync="color_background">
              </div>
            </div>

            <div class="neria-form-group">
              <label class="neria-label" for="color_container">
                {neria_admin key='design.color_container'}
              </label>
              <div class="neria-color-input-wrap">
                <input type="color" id="color_container" name="color_container"
                       class="neria-color-picker" data-sync="color_container"
                       value="{$design.color_container|default:'#ffffff'}">
                <input type="text" class="neria-input neria-input--hex"
                       value="{$design.color_container|default:'#ffffff'}"
                       data-sync="color_container">
              </div>
            </div>

            <div class="neria-form-group">
              <label class="neria-label" for="color_accent">
                {neria_admin key='design.color_accent'}
                <span class="neria-hint">{neria_admin key='design.color_accent_hint'}</span>
              </label>
              <div class="neria-color-input-wrap">
                <input type="color" id="color_accent" name="color_accent"
                       class="neria-color-picker" data-sync="color_accent"
                       value="{$design.color_accent|default:'#b38b59'}">
                <input type="text" class="neria-input neria-input--hex"
                       value="{$design.color_accent|default:'#b38b59'}"
                       data-sync="color_accent">
              </div>
            </div>

            <div class="neria-form-group">
              <label class="neria-label" for="color_text">
                {neria_admin key='design.color_text'}
              </label>
              <div class="neria-color-input-wrap">
                <input type="color" id="color_text" name="color_text"
                       class="neria-color-picker" data-sync="color_text"
                       value="{$design.color_text|default:'#2c2c2c'}">
                <input type="text" class="neria-input neria-input--hex"
                       value="{$design.color_text|default:'#2c2c2c'}"
                       data-sync="color_text">
              </div>
            </div>

          </div>
        </div>

        {* Mise en page *}
        <div class="neria-section">
          <h2 class="neria-section__title">{neria_admin key='design.layout_title'}</h2>

          <div class="neria-form-group">
            <label class="neria-label" for="container_width">
              {neria_admin key='design.container_width'}
              <span class="neria-hint">{neria_admin key='design.container_width_hint'}</span>
            </label>
            <div class="neria-range-wrap">
              <input type="range" id="container_width_range"
                     min="480" max="800" step="10"
                     value="{$design.container_width|default:620}"
                     class="neria-range"
                     data-sync-input="container_width">
              <input type="number" id="container_width" name="container_width"
                     class="neria-input neria-input--number"
                     min="480" max="800"
                     value="{$design.container_width|default:620}">
              <span class="neria-unit">px</span>
            </div>
          </div>

          <div class="neria-form-group">
            <label class="neria-label" for="logo_width">
              {neria_admin key='design.logo_width'}
              <span class="neria-hint">{neria_admin key='design.logo_width_hint'}</span>
            </label>
            <div class="neria-range-wrap">
              <input type="range" id="logo_width_range"
                     min="80" max="320" step="10"
                     value="{$design.logo_width|default:160}"
                     class="neria-range"
                     data-sync-input="logo_width">
              <input type="number" id="logo_width" name="logo_width"
                     class="neria-input neria-input--number"
                     min="80" max="320"
                     value="{$design.logo_width|default:160}">
              <span class="neria-unit">px</span>
            </div>
          </div>

          <div class="neria-form-group">
            <label class="neria-label">
              {neria_admin key='design.dark_mode'}
              <span class="neria-hint">
                {neria_admin key='design.dark_mode_hint'}
              </span>
            </label>
            <label class="neria-toggle">
              <input type="checkbox" name="dark_mode" value="1"
                     {if $design.dark_mode}checked{/if}>
              <span class="neria-toggle__slider"></span>
              <span class="neria-toggle__label">{neria_admin key='common.enabled'}</span>
            </label>
          </div>
        </div>

        {* Logo *}
        <div class="neria-section">
          <h2 class="neria-section__title">{neria_admin key='design.logo_title'}</h2>

          {if isset($design.logo_url) && $design.logo_url}
            <div class="neria-logo-current">
              <img src="{$design.logo_url}" alt="Logo"
                   style="max-width:160px; max-height:80px;">
              <span class="neria-logo-current__label">
                {neria_admin key='design.logo_current'}
              </span>
            </div>
          {/if}

          <div class="neria-form-group">
            <label class="neria-label" for="logo_upload">
              {neria_admin key='design.logo_upload'}
              <span class="neria-hint">{neria_admin key='design.logo_upload_hint'}</span>
            </label>
            {* Champ fichier personnalisé : le texte natif du navigateur
               (« Aucun fichier… ») n'est pas traduisible ; on le remplace
               par nos propres libellés (18 langues). *}
            <div class="neria-file-field">
              <input type="file" id="logo_upload" name="logo"
                     class="neria-file-field__input"
                     style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);border:0;"
                     accept=".png,.jpg,.jpeg,.webp"
                     data-default-text="{neria_admin key='design.logo_no_file'}">
              <label for="logo_upload" class="neria-btn neria-btn--ghost neria-file-field__btn">
                {neria_admin key='design.logo_choose'}
              </label>
              <span class="neria-file-field__name">{neria_admin key='design.logo_no_file'}</span>
            </div>
          </div>
        </div>

        <div class="neria-form-actions neria-form-actions--sticky">
          <button type="button" class="neria-btn neria-btn--ghost"
                  id="neria-design-reset"
                  data-confirm="{neria_admin key='design.reset_confirm'}">
            {neria_admin key='design.reset'}
          </button>
          <button type="submit" class="neria-btn neria-btn--primary">
            {neria_admin key='design.save'}
          </button>
        </div>

      </form>
    </div>

    {* ── Aperçu temps réel ──────────────────────────────────────── *}
    <div class="neria-design-preview-panel">
      <div class="neria-preview-header">
        <span class="neria-preview-header__title">
          {neria_admin key='design.preview_title'}
        </span>
        <div class="neria-preview-controls">
          <label class="neria-label" for="preview_template">
            {neria_admin key='common.template'}
          </label>
          <select id="preview_template" class="neria-select neria-select--sm">
            {foreach $template_labels as $key => $label}
              <option value="{$key}" {if $key === 'order_conf'}selected{/if}>{$label}</option>
            {/foreach}
          </select>

          <label class="neria-label" for="preview_lang">
            {neria_admin key='common.language'}
          </label>
          <select id="preview_lang" class="neria-select neria-select--sm">
            {foreach $lang_labels as $code => $name}
              <option value="{$code}" {if $code === 'fr'}selected{/if}>
                {$lang_flags[$code]|default:''} {$name}
              </option>
            {/foreach}
          </select>
        </div>
      </div>

      <div class="neria-preview-frame-wrap">
        <div class="neria-preview-loading" id="neria-preview-loading">
          <span>{neria_admin key='design.preview_loading'}</span>
        </div>

        {*
          Fix 5 : src par défaut avec les valeurs courantes du design
          L'aperçu est fonctionnel même sans JavaScript actif
        *}
        <iframe id="neria-preview-frame"
                class="neria-preview-frame"
                src="{$smarty.server.REQUEST_URI}&neria_action=preview&neria_template=order_conf&neria_lang=fr"
                frameborder="0"
                scrolling="yes">
        </iframe>
      </div>

      <div class="neria-preview-footer">
        <span class="neria-preview-footer__note">
          {neria_admin key='design.preview_note'}
        </span>
      </div>
    </div>

  </div>
</div>
