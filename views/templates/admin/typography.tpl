{**
 * NERIA — typography.tpl
 * Onglet Typographie — polices par script + corps de texte + aperçu temps réel
 *}

<div class="neria-design-wrap">
  <div class="neria-design-layout">

    {* ── Panneau de configuration ─────────────────────────────── *}
    <div class="neria-design-panel">

      <form method="post" action="{$smarty.server.REQUEST_URI}"
            id="neria-typography-form">
        <input type="hidden" name="neria_action" value="save_typography">
        <input type="hidden" name="neria_tab"    value="typography">

        {* ── Taille de police corps ──────────────────────────────── *}
        <div class="neria-section">
          <div class="neria-section__header">
            <h2 class="neria-section__title">{neria_admin key='typography.font_size_title'}</h2>
            <button type="button" class="neria-section-reset"
                    data-defaults='{ldelim}"font_size":"14"{rdelim}'>
              ↺ Défauts
            </button>
          </div>
          <p class="neria-hint">{neria_admin key='typography.font_size_hint'}</p>

          <div class="neria-slider-row">
            <span class="neria-slider-label">12px</span>
            <input type="range"
                   id="font_size_range"
                   class="neria-range"
                   name="font_size"
                   min="12" max="16" step="1"
                   data-sync-input="font_size_number"
                   value="{$typography_font_size|default:14}">
            <span class="neria-slider-label">16px</span>
            <input type="number"
                   id="font_size_number"
                   class="neria-input neria-input--small"
                   min="12" max="16" step="1"
                   value="{$typography_font_size|default:14}"
                   readonly>
            <span class="neria-slider-unit">px</span>
          </div>
        </div>

        {* ── Interligne ──────────────────────────────────────────── *}
        <div class="neria-section">
          <div class="neria-section__header">
            <h2 class="neria-section__title">{neria_admin key='typography.line_height_title'}</h2>
            <button type="button" class="neria-section-reset"
                    data-defaults='{ldelim}"line_height":"1.8"{rdelim}'>
              ↺ Défauts
            </button>
          </div>
          <p class="neria-hint">{neria_admin key='typography.line_height_hint'}</p>

          <div class="neria-slider-row">
            <span class="neria-slider-label">1.4</span>
            <input type="range"
                   id="line_height_range"
                   class="neria-range"
                   name="line_height"
                   min="1.4" max="2.0" step="0.1"
                   data-sync-input="line_height_number"
                   value="{$typography_line_height|default:1.8}">
            <span class="neria-slider-label">2.0</span>
            <input type="number"
                   id="line_height_number"
                   class="neria-input neria-input--small"
                   min="1.4" max="2.0" step="0.1"
                   value="{$typography_line_height|default:1.8}"
                   readonly>
          </div>
        </div>

        {* ── Poids des titres ────────────────────────────────────── *}
        <div class="neria-section">
          <div class="neria-section__header">
            <h2 class="neria-section__title">{neria_admin key='typography.heading_weight_title'}</h2>
            <button type="button" class="neria-section-reset"
                    data-defaults='{ldelim}"heading_weight":"600"{rdelim}'>
              ↺ Défauts
            </button>
          </div>
          <p class="neria-hint">{neria_admin key='typography.heading_weight_hint'}</p>

          <div class="neria-radio-row">
            {foreach [400 => 'Fin', 600 => 'Normal', 700 => 'Gras'] as $wval => $wlabel}
              {assign var="wid" value="hw_`$wval`"}
              <label class="neria-radio-card
                {if ($typography_heading_weight|default:600) == $wval}neria-radio-card--selected{/if}"
                for="{$wid}">
                <input type="radio"
                       id="{$wid}"
                       name="heading_weight"
                       value="{$wval}"
                       class="neria-radio-card__input"
                       {if ($typography_heading_weight|default:600) == $wval}checked{/if}>
                <span class="neria-radio-card__preview"
                      style="font-family:'Cormorant Garamond',Georgia,serif;font-weight:{$wval};font-size:18px;">
                  Aa
                </span>
                <span class="neria-radio-card__label">{$wlabel} ({$wval})</span>
              </label>
            {/foreach}
          </div>
        </div>

        {* ── Polices par script ──────────────────────────────────── *}
        {foreach $font_scripts as $script => $scriptData}
        <div class="neria-section">

          <h2 class="neria-section__title">
            {$scriptData.label}
            <span class="neria-section__langs">
              {foreach $scriptData.languages as $lang}
                <span class="neria-lang-chip">
                  {$lang_flags[$lang]|default:''} {$lang}
                </span>
              {/foreach}
            </span>
          </h2>

          <div class="neria-font-grid">

            {foreach $fonts_by_script[$script] as $fontName => $fontData}
              {assign var="radio_id" value="font_`$script`_`$fontName`"}

              <label class="neria-font-card
                {if $current_fonts[$script]|default:'' === $fontName}
                  neria-font-card--selected
                {/if}"
                for="{$radio_id|replace:' ':'_'}">

                <input type="radio"
                       id="{$radio_id|replace:' ':'_'}"
                       name="font_{$script}"
                       value="{$fontName|escape:'html'}"
                       class="neria-font-card__radio"
                       {if $current_fonts[$script]|default:'' === $fontName}checked{/if}>

                <div class="neria-font-card__preview"
                     style="font-family: {$fontData.css_family|escape:'html'};">
                  {if $script === 'arabic'}
                    بيت الفنون والأناقة
                  {elseif $script === 'japanese'}
                    優雅な日本語
                  {elseif $script === 'korean'}
                    우아한 한국어
                  {elseif $script === 'chinese_simplified'}
                    优雅的中文
                  {elseif $script === 'chinese_traditional'}
                    優雅的中文
                  {elseif $script === 'cyrillic'}
                    Дом изящных искусств
                  {else}
                    Maison Neria
                  {/if}
                </div>

                <div class="neria-font-card__name">{$fontName}</div>
                <div class="neria-font-card__desc">{$fontData.description}</div>

              </label>
            {/foreach}

          </div>

        </div>
        {/foreach}

        <div class="neria-form-actions neria-form-actions--sticky">
          <button type="submit" class="neria-btn neria-btn--primary">
            {neria_admin key='typography.save'}
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
          <select id="preview_template" class="neria-select neria-select--sm">
            {foreach $templates_list as $tplName => $tplLabel}
              <option value="{$tplName}"
                {if $tplName === 'order_conf'}selected{/if}>
                {$tplLabel|truncate:28:'…':true}
              </option>
            {/foreach}
          </select>

          <select id="preview_lang" class="neria-select neria-select--sm">
            {foreach ['fr','en','de','es','it','pt','nl','pl','ar','ja','ko','zh','tw','ru'] as $lc}
              <option value="{$lc}" {if $lc === 'fr'}selected{/if}>
                {$lc|upper}
              </option>
            {/foreach}
          </select>
        </div>
      </div>

      <div class="neria-preview-frame-wrap">
        <div class="neria-preview-loading" id="neria-preview-loading">
          <span>{neria_admin key='design.preview_loading'}</span>
        </div>

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
