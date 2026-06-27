{**
 * NERIA — typography.tpl
 * Onglet Typographie
 * Fix 9 : aperçu cyrillique ajouté (Дом изящных искусств)
 *}

<form method="post" action="{$smarty.server.REQUEST_URI}" id="neria-typography-form">
  <input type="hidden" name="neria_action" value="save_typography">
  <input type="hidden" name="neria_tab"    value="typography">

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
