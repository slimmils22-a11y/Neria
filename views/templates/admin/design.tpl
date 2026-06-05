{**
 * NERIA — design.tpl
 * Onglet Design — Couleurs, logo, mode sombre, largeur conteneur
 * Fix 5 : iframe avec src par défaut (preview fonctionnel sans JS)
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
          <h2 class="neria-section__title">{l s='Couleurs' mod='neria'}</h2>
          <div class="neria-color-grid">

            <div class="neria-form-group">
              <label class="neria-label" for="color_background">
                {l s='Fond d\'envoi' mod='neria'}
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
                {l s='Fond du conteneur' mod='neria'}
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
                {l s='Couleur accent' mod='neria'}
                <span class="neria-hint">{l s='Liens, bordures, boutons' mod='neria'}</span>
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
                {l s='Couleur du texte' mod='neria'}
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
          <h2 class="neria-section__title">{l s='Mise en page' mod='neria'}</h2>

          <div class="neria-form-group">
            <label class="neria-label" for="container_width">
              {l s='Largeur du conteneur' mod='neria'}
              <span class="neria-hint">{l s='Entre 480 et 800px' mod='neria'}</span>
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
              {l s='Largeur du logo' mod='neria'}
              <span class="neria-hint">{l s='Entre 80 et 320px' mod='neria'}</span>
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
              {l s='Mode sombre forcé' mod='neria'}
              <span class="neria-hint">
                {l s='Verrouille les couleurs dans les clients email en dark mode' mod='neria'}
              </span>
            </label>
            <label class="neria-toggle">
              <input type="checkbox" name="dark_mode" value="1"
                     {if $design.dark_mode}checked{/if}>
              <span class="neria-toggle__slider"></span>
              <span class="neria-toggle__label">{l s='Activé' mod='neria'}</span>
            </label>
          </div>
        </div>

        {* Logo *}
        <div class="neria-section">
          <h2 class="neria-section__title">{l s='Logo' mod='neria'}</h2>

          {if isset($design.logo_url) && $design.logo_url}
            <div class="neria-logo-current">
              <img src="{$design.logo_url}" alt="Logo actuel"
                   style="max-width:160px; max-height:80px;">
              <span class="neria-logo-current__label">
                {l s='Logo actuel' mod='neria'}
              </span>
            </div>
          {/if}

          <div class="neria-form-group">
            <label class="neria-label" for="logo_upload">
              {l s='Télécharger un nouveau logo' mod='neria'}
              <span class="neria-hint">{l s='PNG, JPG ou WebP · Max 2 Mo' mod='neria'}</span>
            </label>
            <input type="file" id="logo_upload" name="logo"
                   class="neria-input neria-input--file"
                   accept=".png,.jpg,.jpeg,.webp">
          </div>
        </div>

        <div class="neria-form-actions neria-form-actions--sticky">
          <button type="button" class="neria-btn neria-btn--ghost"
                  id="neria-design-reset"
                  data-confirm="{l s='Réinitialiser le design aux valeurs par défaut ?' mod='neria'}">
            {l s='Réinitialiser' mod='neria'}
          </button>
          <button type="submit" class="neria-btn neria-btn--primary">
            {l s='Sauvegarder le design' mod='neria'}
          </button>
        </div>

      </form>
    </div>

    {* ── Aperçu temps réel ──────────────────────────────────────── *}
    <div class="neria-design-preview-panel">
      <div class="neria-preview-header">
        <span class="neria-preview-header__title">
          {l s='Aperçu en temps réel' mod='neria'}
        </span>
        <div class="neria-preview-controls">
          <label class="neria-label" for="preview_template">
            {l s='Template' mod='neria'}
          </label>
          <select id="preview_template" class="neria-select neria-select--sm">
            <option value="order_conf">Confirmation commande</option>
            <option value="shipped">Expédition</option>
            <option value="abandoned_cart_1">Panier abandonné</option>
            <option value="payment">Paiement</option>
            <option value="birthday">Anniversaire</option>
          </select>

          <label class="neria-label" for="preview_lang">
            {l s='Langue' mod='neria'}
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
          <span>{l s='Chargement de l\'aperçu...' mod='neria'}</span>
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
          {l s='L\'aperçu se met à jour automatiquement à chaque modification.' mod='neria'}
        </span>
      </div>
    </div>

  </div>
</div>
