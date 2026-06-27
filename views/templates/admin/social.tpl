{**
 * NERIA — social.tpl
 * Onglet Réseaux sociaux
 * Fix 7 : tableau inline remplacé par variable $social_networks
 *         assignée par neria.php → compatible Smarty 2 et 3
 *}

<div class="neria-section">
  <p class="neria-section__desc">
    {neria_admin key='social.desc'}
  </p>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="save_social">
    <input type="hidden" name="neria_tab"    value="social">

    {*
      $social_networks est assignée par neria.php :
      $this->context->smarty->assign('social_networks', [
        'instagram' => ['icon' => '◉', 'label' => 'Instagram', 'placeholder' => 'https://instagram.com/...'],
        ...
      ]);
    *}
    <div class="neria-social-grid">
      {foreach $social_networks as $network => $data}
        <div class="neria-social-item">

          <div class="neria-social-item__icon">{$data.icon}</div>

          <div class="neria-social-item__fields">
            <label class="neria-label" for="social_{$network}">
              {$data.label}
            </label>
            <input type="url"
                   id="social_{$network}"
                   name="social_{$network}"
                   class="neria-input"
                   value="{$social_links[$network]|default:''|escape:'html'}"
                   placeholder="{$data.placeholder}">
          </div>

          {if isset($social_links[$network]) && $social_links[$network]}
            <span class="neria-social-item__status neria-social-item__status--on">✓</span>
          {else}
            <span class="neria-social-item__status neria-social-item__status--off">–</span>
          {/if}

        </div>
      {/foreach}
    </div>

    <div class="neria-form-actions">
      <button type="submit" class="neria-btn neria-btn--primary">
        {neria_admin key='social.save'}
      </button>
    </div>

  </form>
</div>
