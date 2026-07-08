{**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — social.tpl
 * Onglet Réseaux sociaux
 * Fix 7 : tableau inline remplacé par variable $social_networks
 *         assignée par neria.php → compatible Smarty 2 et 3
 *}

{assign var="social_configured_count" value=0}
{foreach $social_networks as $network => $data}
  {if isset($social_links[$network]) && $social_links[$network]}
    {assign var="social_configured_count" value=$social_configured_count+1}
  {/if}
{/foreach}
{assign var="social_total_count" value=$social_networks|@count}

<div class="neria-section">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:4px;">
    <p class="neria-section__desc" style="margin:0;">
      {neria_admin key='social.desc'}
    </p>
    <span class="neria-badge {if $social_configured_count > 0}neria-badge--success{else}neria-badge--neutral{/if}" style="white-space:nowrap;">
      {$social_configured_count} / {$social_total_count} {neria_admin key='social.configured_suffix'}
    </span>
  </div>

  {* ── Mode d'emploi ──────────────────────────────────────────────── *}
  <div class="neria-card" style="margin-top:16px;margin-bottom:8px;background:var(--neria-bg-subtle,#f9f7f4);border-left:3px solid var(--neria-accent,#b8976a);padding:18px 24px;">
    <h4 style="margin:0 0 10px;font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:var(--neria-accent,#b8976a);">{neria_admin key='social.howto_title'}</h4>
    <p style="margin:0 0 8px;font-size:13px;color:var(--neria-text-muted,#666);line-height:1.7;">
      {neria_admin key='social.howto_body'}
    </p>
    <p style="margin:0;font-size:12px;color:var(--neria-text-muted,#888);line-height:1.6;">
      💡 {neria_admin key='social.howto_tip'}
    </p>
  </div>

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
                   data-network="{$network}"
                   value="{$social_links[$network]|default:''|escape:'html'}"
                   placeholder="{$data.placeholder}">
            <span class="neria-hint neria-social-item__mismatch" id="mismatch_{$network}" style="display:none;color:#b8600a;"></span>
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

<script>
var NERIA_SOCIAL_MISMATCH_PREFIX = '{neria_admin key='social.url_mismatch_warning' esc='javascript'}';
{literal}
(function () {
  // Alerte discrète (non bloquante) si l'URL saisie ne correspond pas
  // au domaine attendu du réseau — évite de coller la mauvaise URL au
  // mauvais champ, ce qui arrive facilement avec 6 champs similaires.
  var EXPECTED_DOMAINS = {
    instagram: 'instagram.com',
    pinterest: 'pinterest.',
    facebook:  'facebook.com',
    twitter:   ['x.com', 'twitter.com'],
    youtube:   'youtube.com',
    tiktok:    'tiktok.com'
  };

  function checkField(input) {
    var network = input.getAttribute('data-network');
    var warn    = document.getElementById('mismatch_' + network);
    if (!warn) { return; }

    var val = input.value.trim();
    if (!val) { warn.style.display = 'none'; return; }

    var expected = EXPECTED_DOMAINS[network];
    var domains  = Array.isArray(expected) ? expected : [expected];
    var lower    = val.toLowerCase();
    var matches  = domains.some(function (d) { return lower.indexOf(d) !== -1; });

    warn.textContent   = matches ? '' : '⚠ ' + NERIA_SOCIAL_MISMATCH_PREFIX + ' ' + (Array.isArray(expected) ? expected.join(' / ') : expected);
    warn.style.display = matches ? 'none' : 'block';
  }

  document.querySelectorAll('.neria-input[data-network]').forEach(function (input) {
    input.addEventListener('input', function () { checkField(input); });
    checkField(input);
  });
})();
{/literal}
</script>
