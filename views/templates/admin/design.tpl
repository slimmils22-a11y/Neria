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
          <div class="neria-section__header">
            <h2 class="neria-section__title">{neria_admin key='design.colors_title'}</h2>
            <button type="button" class="neria-section-reset"
                    data-defaults='{"color_background":"#f4f1eb","color_container":"#ffffff","color_accent":"#b38b59","color_text":"#2c2c2c"}'>
              ↺ Défauts
            </button>
          </div>
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
          <div class="neria-section__header">
            <h2 class="neria-section__title">{neria_admin key='design.layout_title'}</h2>
            <button type="button" class="neria-section-reset"
                    data-defaults='{"container_width":"620","logo_width":"160"}'>
              ↺ Défauts
            </button>
          </div>

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

        {* ── Police de titres ───────────────────────────────────── *}
        <div class="neria-section">
          <div class="neria-section__header">
            <h2 class="neria-section__title">Police de titres</h2>
            <button type="button" class="neria-section-reset"
                    data-defaults='{"font_heading":"Cormorant Garamond"}'>
              ↺ Défauts
            </button>
          </div>
          <p style="font-size:12px;color:#7a6a5a;margin:0 0 14px;">
            Appliquée aux titres principaux et sous-titres des emails. La police du corps de texte se configure dans l'onglet Typographie.
          </p>
          <div class="neria-form-group">
            <label class="neria-label" for="font_heading">Famille de police</label>
            <select id="font_heading" name="font_heading" class="neria-select">
              {foreach ['Cormorant Garamond','Playfair Display','EB Garamond','Lora','Libre Baskerville','Cinzel','Josefin Sans','Raleway'] as $fkey}
                <option value="{$fkey}" {if ($design.font_heading|default:'Cormorant Garamond') === $fkey}selected{/if}>
                  {if $fkey === 'Cormorant Garamond'}Cormorant Garamond — Élégance classique
                  {elseif $fkey === 'Playfair Display'}Playfair Display — Éditorial luxe
                  {elseif $fkey === 'EB Garamond'}EB Garamond — Intemporel lettres
                  {elseif $fkey === 'Lora'}Lora — Chaleur contemporaine
                  {elseif $fkey === 'Libre Baskerville'}Libre Baskerville — Sobre et lisible
                  {elseif $fkey === 'Cinzel'}Cinzel — Prestige romain
                  {elseif $fkey === 'Josefin Sans'}Josefin Sans — Minimalisme chic
                  {else}Raleway — Sophistiqué moderne
                  {/if}
                </option>
              {/foreach}
            </select>
          </div>
        </div>

        {* ── Style des boutons ───────────────────────────────────── *}
        <div class="neria-section">
          <div class="neria-section__header">
            <h2 class="neria-section__title">Style des boutons</h2>
            <button type="button" class="neria-section-reset"
                    data-defaults='{"btn_color":"#2b2520","btn_radius":"2"}'>
              ↺ Défauts
            </button>
          </div>
          <div class="neria-form-group">
            <label class="neria-label">Couleur du bouton</label>
            <div class="neria-color-input-wrap">
              <input type="color" id="btn_color" name="btn_color"
                     class="neria-color-picker" data-sync="btn_color"
                     value="{$design.btn_color|default:'#2b2520'}">
              <input type="text" class="neria-input neria-input--hex"
                     value="{$design.btn_color|default:'#2b2520'}"
                     data-sync="btn_color">
            </div>
          </div>
          <div class="neria-form-group" style="margin-top:14px;">
            <label class="neria-label">Arrondi des coins</label>
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:6px;">
              {foreach [0=>['label'=>'Carré','px'=>'0px'],2=>['label'=>'Discret','px'=>'2px'],6=>['label'=>'Arrondi','px'=>'6px'],24=>['label'=>'Pill','px'=>'24px']] as $rv=>$rd}
                <label style="cursor:pointer;text-align:center;">
                  <input type="radio" name="btn_radius" value="{$rv}"
                         {if (int)($design.btn_radius|default:2) === $rv}checked{/if}
                         style="display:none;" class="neria-radius-radio">
                  <div class="neria-radius-preview" data-radius="{$rv}"
                       style="height:36px;line-height:36px;border:2px solid {if (int)($design.btn_radius|default:2) === $rv}#b38b59{else}#e8d5b0{/if};border-radius:{$rd.px};background:{if (int)($design.btn_radius|default:2) === $rv}#f9f4ef{else}#fff{/if};font-size:11px;color:#5c3d1e;transition:all .2s;">
                    {$rd.label}
                  </div>
                </label>
              {/foreach}
            </div>
          </div>
        </div>

        {* ── Couleurs header / footer ────────────────────────────── *}
        <div class="neria-section">
          <div class="neria-section__header">
            <h2 class="neria-section__title">Couleurs header & footer</h2>
            <button type="button" class="neria-section-reset"
                    data-defaults='{"color_header_bg":"#ffffff","color_footer_bg":"#ffffff","color_footer_text":"#a09990"}'>
              ↺ Défauts
            </button>
          </div>
          <div class="neria-color-grid">
            <div class="neria-form-group">
              <label class="neria-label">Fond du header</label>
              <div class="neria-color-input-wrap">
                <input type="color" id="color_header_bg" name="color_header_bg"
                       class="neria-color-picker" data-sync="color_header_bg"
                       value="{$design.color_header_bg|default:'#ffffff'}">
                <input type="text" class="neria-input neria-input--hex"
                       value="{$design.color_header_bg|default:'#ffffff'}"
                       data-sync="color_header_bg">
              </div>
            </div>
            <div class="neria-form-group">
              <label class="neria-label">Fond du footer</label>
              <div class="neria-color-input-wrap">
                <input type="color" id="color_footer_bg" name="color_footer_bg"
                       class="neria-color-picker" data-sync="color_footer_bg"
                       value="{$design.color_footer_bg|default:'#ffffff'}">
                <input type="text" class="neria-input neria-input--hex"
                       value="{$design.color_footer_bg|default:'#ffffff'}"
                       data-sync="color_footer_bg">
              </div>
            </div>
            <div class="neria-form-group">
              <label class="neria-label">Texte du footer</label>
              <div class="neria-color-input-wrap">
                <input type="color" id="color_footer_text" name="color_footer_text"
                       class="neria-color-picker" data-sync="color_footer_text"
                       value="{$design.color_footer_text|default:'#a09990'}">
                <input type="text" class="neria-input neria-input--hex"
                       value="{$design.color_footer_text|default:'#a09990'}"
                       data-sync="color_footer_text">
              </div>
            </div>
          </div>
        </div>

        {* ── Espacement & séparateur & ombre ────────────────────── *}
        <div class="neria-section">
          <div class="neria-section__header">
            <h2 class="neria-section__title">Espacement, séparateur & ombre</h2>
            <button type="button" class="neria-section-reset"
                    data-defaults='{"section_padding":"40","block_spacing":"48","separator_style":"line","card_shadow":"soft"}'>
              ↺ Défauts
            </button>
          </div>

          <div class="neria-form-group">
            <label class="neria-label" for="section_padding">
              Padding interne
              <span class="neria-hint">Espace entre le bord de l'email et le contenu</span>
            </label>
            <div class="neria-range-wrap">
              <input type="range" id="section_padding_range" min="16" max="64" step="4"
                     value="{$design.section_padding|default:40}" class="neria-range"
                     data-sync-input="section_padding">
              <input type="number" id="section_padding" name="section_padding"
                     class="neria-input neria-input--number" min="16" max="64"
                     value="{$design.section_padding|default:40}">
              <span class="neria-unit">px</span>
            </div>
          </div>

          <div class="neria-form-group">
            <label class="neria-label" for="block_spacing">
              Espacement entre blocs
              <span class="neria-hint">Marge au-dessus des titres de section</span>
            </label>
            <div class="neria-range-wrap">
              <input type="range" id="block_spacing_range" min="16" max="80" step="4"
                     value="{$design.block_spacing|default:48}" class="neria-range"
                     data-sync-input="block_spacing">
              <input type="number" id="block_spacing" name="block_spacing"
                     class="neria-input neria-input--number" min="16" max="80"
                     value="{$design.block_spacing|default:48}">
              <span class="neria-unit">px</span>
            </div>
          </div>

          <div class="neria-form-group" style="margin-top:14px;">
            <label class="neria-label">Style du séparateur</label>
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:6px;">
              {foreach ['none'=>'Aucun','line'=>'Ligne','dotted'=>'Pointillés','double'=>'Double'] as $sv=>$sl}
                <label style="cursor:pointer;text-align:center;">
                  <input type="radio" name="separator_style" value="{$sv}"
                         {if ($design.separator_style|default:'line') === $sv}checked{/if}
                         style="display:none;" class="neria-sep-radio">
                  <div style="height:40px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;border:2px solid {if ($design.separator_style|default:'line') === $sv}#b38b59{else}#e8d5b0{/if};border-radius:5px;background:{if ($design.separator_style|default:'line') === $sv}#f9f4ef{else}#fff{/if};font-size:11px;color:#5c3d1e;padding:4px 8px;cursor:pointer;transition:all .2s;" data-sep="{$sv}">
                    {if $sv === 'none'}<span style="font-size:16px;">✕</span>
                    {elseif $sv === 'line'}<span style="width:32px;height:1px;background:#b38b59;display:block;"></span>
                    {elseif $sv === 'dotted'}<span style="width:32px;height:1px;border-top:1px dotted #b38b59;display:block;"></span>
                    {else}<span style="width:32px;height:2px;border-top:3px double #b38b59;display:block;"></span>
                    {/if}
                    <span>{$sl}</span>
                  </div>
                </label>
              {/foreach}
            </div>
          </div>

          <div class="neria-form-group" style="margin-top:14px;">
            <label class="neria-label">Ombre de la carte email</label>
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:6px;">
              {foreach ['none'=>'Aucune','soft'=>'Légère','medium'=>'Marquée','strong'=>'Forte'] as $shv=>$shl}
                <label style="cursor:pointer;text-align:center;">
                  <input type="radio" name="card_shadow" value="{$shv}"
                         {if ($design.card_shadow|default:'soft') === $shv}checked{/if}
                         style="display:none;" class="neria-shadow-radio">
                  <div style="height:36px;line-height:36px;border:2px solid {if ($design.card_shadow|default:'soft') === $shv}#b38b59{else}#e8d5b0{/if};border-radius:5px;background:{if ($design.card_shadow|default:'soft') === $shv}#f9f4ef{else}#fff{/if};font-size:11px;color:#5c3d1e;transition:all .2s;" data-shadow="{$shv}">
                    {$shl}
                  </div>
                </label>
              {/foreach}
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
