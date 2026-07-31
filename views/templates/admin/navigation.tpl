{**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — navigation.tpl
 * Menu de navigation principal du back-office
 * NOTE : Ce template N'ouvre PAS .neria-bo-content
 * C'est neria.php → getContent() qui encapsule :
 *   $navigation . '<div class="neria-bo-content">' . $content . '</div></div>'
 *}

<div class="neria-bo-wrap" dir="{$neria_bo_dir|default:'ltr'}">

  {* ── Header ─────────────────────────────────────────────────── *}
  <div class="neria-bo-header">
    <div class="neria-bo-header__brand">
      <span class="neria-bo-header__logo-wrap">
        <span class="neria-bo-header__logo">✦</span>
        <span class="neria-bo-header__logo-spark">✦</span>
      </span>
      <div>
        <h1 class="neria-bo-header__title">Neria</h1>
        <span class="neria-bo-header__version">
          Luxury Email Suite · v{$neria_version}
        </span>
      </div>
    </div>

    <div class="neria-bo-header__actions">

      {* ── Sélecteur de langue de l'aperçu BO ──────────────────── *}
      {assign var="neria_bo_base" value=$smarty.server.REQUEST_URI|regex_replace:'/&neria_bo_lang=[^&]*/':''}
      <label class="neria-header-label" for="neria-bo-lang-select">{neria_admin key='common.interface_language'}</label>
      <select id="neria-bo-lang-select" class="neria-select neria-select--sm"
              onchange="if(this.value)window.location.href=this.value;"
              aria-label="{neria_admin key='common.interface_language'}">
        {foreach $lang_labels as $code => $name}
          <option value="{$neria_bo_base}&neria_bo_lang={$code}"{if isset($neria_bo_lang) && $neria_bo_lang == $code} selected{/if}>{$lang_flags[$code]|default:''} {$name}</option>
        {/foreach}
      </select>

      <span class="neria-header-sep"></span>

      {* ── Envoi email de test ──────────────────────────────────── *}
      {assign var="neria_test_base" value=$smarty.server.REQUEST_URI|regex_replace:'/&neria_action=[^&]*/':''}
      {assign var="neria_test_base" value=$neria_test_base|regex_replace:'/&neria_test_lang=[^&]*/':''}
      <span class="neria-test-send">
        <label class="neria-header-label" for="neria-test-lang-select">{neria_admin key='nav.email_test'}</label>
        <select id="neria-test-lang-select" class="neria-select neria-select--sm">
          {foreach $lang_labels as $code => $name}
            <option value="{$code}"{if isset($neria_bo_lang) && $neria_bo_lang == $code} selected{/if}>{$lang_flags[$code]|default:''} {$name}</option>
          {/foreach}
        </select>
        <a id="neria-test-send-btn"
           href="{$neria_test_base}&neria_action=send_test&neria_test_lang={$neria_bo_lang|default:'fr'}"
           class="neria-btn neria-btn--primary neria-btn--sm"
           title="{neria_admin key='nav.send_test_title'}"
           onclick="return neriaPostLink(event, this);">
          ✉ {neria_admin key='nav.email_test'}
        </a>
      </span>
      <script>
      window.NERIA_UI = {
        close:           "{neria_admin key='common.close' esc='javascript'}",
        confirmGeneric:  "{neria_admin key='msg.confirm_reset_generic' esc='javascript'}",
        sigPreviewError: "{neria_admin key='configure.sig_preview_error' esc='javascript'}",
        sigPreviewAlt:   "{neria_admin key='configure.sig_preview_alt' esc='javascript'}"
      };
      (function(){
        var sel = document.getElementById('neria-test-lang-select');
        var btn = document.getElementById('neria-test-send-btn');
        var base = '{$neria_test_base|escape:'javascript'}';
        function update(){ btn.href = base + '&neria_action=send_test&neria_test_lang=' + sel.value; }
        if(sel && btn){ sel.addEventListener('change', update); update(); }
      })();
      </script>
      <span class="neria-status {if $neria_active}neria-status--on{else}neria-status--off{/if}">
        <span class="neria-status__dot"></span>
        {if $neria_active}{neria_admin key='common.active'}{else}{neria_admin key='common.inactive'}{/if}
      </span>
    </div>
  </div>

  {* ── Contexte boutique (installations multi-boutique uniquement) ── *}
  {if isset($neria_shop_ctx_active) && $neria_shop_ctx_active}
    {if $neria_shop_ctx_is_single}
      <div class="neria-alert neria-alert--shopctx">
        <span class="neria-alert__icon">🏬</span>
        {neria_admin key='nav.shop_context_single'} <strong>{$neria_shop_ctx_active_name|escape:'html'}</strong>
      </div>
    {else}
      <div class="neria-alert neria-alert--warning">
        <span class="neria-alert__icon">⚠</span>
        {neria_admin key='nav.shop_context_no_aggregate'} <strong>{$neria_shop_ctx_active_name|escape:'html'}</strong>.
      </div>
    {/if}
  {/if}

  {* ── Licence ──────────────────────────────────────────────────── *}
  {if isset($neria_license_active) && $neria_license_active}
    {assign var="ls" value=$neria_license_status}
    {* Champ de saisie de clé — réutilisé sur toutes les bannières où le
       marchand doit pouvoir corriger la situation immédiatement (jamais
       activé, révoqué, expire bientôt, ou bloqué), pas seulement le cas
       "jamais activé" qui était le seul à l'avoir jusqu'ici. *}
    {capture "neria_license_key_form"}
      <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}" style="display:inline-flex;gap:8px;align-items:center;margin-left:10px;">
        <input type="text" name="license_key" placeholder="NERIA-XXXX-XXXX-XXXX"
               pattern="NERIA-[A-Za-z0-9]{ldelim}4{rdelim}-[A-Za-z0-9]{ldelim}4{rdelim}-[A-Za-z0-9]{ldelim}4{rdelim}"
               oninput="this.value = this.value.trim();" onpaste="var el=this; setTimeout(function(){ldelim} el.value = el.value.trim(); {rdelim}, 0);"
               class="neria-select neria-select--sm" style="width:220px;text-transform:uppercase;" required>
        <button type="submit" name="neria_action" value="activate_license" class="neria-btn neria-btn--primary neria-btn--sm">
          {neria_admin key='license.activate_btn'}
        </button>
      </form>
    {/capture}
    {if !$ls.sending_allowed}
      <div class="neria-alert neria-alert--error">
        <span class="neria-alert__icon">⚠</span>
        {neria_admin key='license.banner_blocked'}
        {$smarty.capture.neria_license_key_form}
      </div>
    {elseif $ls.in_grace_period && !$ls.has_key}
      <div class="neria-alert neria-alert--shopctx">
        <span class="neria-alert__icon">🔑</span>
        {neria_admin key='license.banner_not_activated'}
        {if $ls.grace_days_left !== null}
          {neria_admin key='license.banner_days_left' n=$ls.grace_days_left}
        {/if}
        {$smarty.capture.neria_license_key_form}
      </div>
    {elseif $ls.in_grace_period && $ls.revoked}
      <div class="neria-alert neria-alert--warning">
        <span class="neria-alert__icon">⚠</span>
        {neria_admin key='license.banner_revoked'}
        {if $ls.grace_days_left !== null}
          {neria_admin key='license.banner_days_left' n=$ls.grace_days_left}
        {/if}
        {$smarty.capture.neria_license_key_form}
      </div>
    {elseif $ls.expires_soon}
      <div class="neria-alert neria-alert--warning">
        <span class="neria-alert__icon">⏳</span>
        {neria_admin key='license.banner_expires_soon'} <strong>{$ls.expires_at}</strong>.
        {$smarty.capture.neria_license_key_form}
      </div>
    {/if}
  {/if}

  {* ── Alertes ────────────────────────────────────────────────── *}
  {if isset($neria_success) && $neria_success}
    <div class="neria-alert neria-alert--success" data-neria-alert data-neria-action="{$neria_msg_action|default:''|escape:'html'}">
      <span class="neria-alert__icon">✓</span>
      {$neria_success|escape:'html'}
    </div>
  {/if}

  {if isset($neria_error) && $neria_error}
    <div class="neria-alert neria-alert--error" data-neria-alert data-neria-action="{$neria_msg_action|default:''|escape:'html'}">
      <span class="neria-alert__icon">⚠</span>
      {$neria_error|escape:'html'}
    </div>
  {/if}

  {* ── Navigation ────────────────────────────────────────────── *}
  <nav class="neria-bo-nav">
    <ul class="neria-bo-nav__list">

      {* Retire aussi neria_success/neria_error/neria_action/neria_msg_action de
         l'URL courante avant de reconstruire les liens de menu — sinon un
         message de confirmation (ex: "Fonctionnalité activée.") issu d'un
         precedent toggle reste coince dans la query string et reapparait
         sur CHAQUE onglet visite ensuite, indefiniment, jusqu'a ce que
         l'utilisateur recharge une URL sans ces parametres a la main. *}
      {assign var="neria_tab_base" value=$smarty.server.REQUEST_URI|regex_replace:'/&neria_tab=[^&]*/':''}
      {assign var="neria_tab_base" value=$neria_tab_base|regex_replace:'/&neria_success=[^&]*/':''}
      {assign var="neria_tab_base" value=$neria_tab_base|regex_replace:'/&neria_error=[^&]*/':''}
      {assign var="neria_tab_base" value=$neria_tab_base|regex_replace:'/&neria_action=[^&]*/':''}
      {assign var="neria_tab_base" value=$neria_tab_base|regex_replace:'/&neria_msg_action=[^&]*/':''}
      <li class="neria-bo-nav__item neria-bo-nav__item--has-sub">
        <a href="{$neria_tab_base}&neria_tab=configure"
           class="neria-bo-nav__link {if $neria_active_tab === 'configure'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">⊞</span>
          {neria_admin key='nav.home'}
          <span class="neria-nav-arrow">▾</span>
        </a>
        <ul class="neria-nav-dropdown">
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-dashboard">📊 {neria_admin key='nav.sub_dashboard'}</a></li>
          {if $neria_menu_visible.auto_lang}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-autolang">🌐 {neria_admin key='nav.sub_autolang'}</a></li>{/if}
          {if $neria_menu_visible.time_greeting}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-time-greetings">⏱ {neria_admin key='nav.sub_time_greetings'}</a></li>{/if}
          {if $neria_menu_visible.firstname_fallback}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-firstname-fallbacks">✦ {neria_admin key='nav.sub_firstname_fallbacks'}</a></li>{/if}
          {if $neria_menu_visible.vouchers}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-vouchers">🎁 {neria_admin key='nav.sub_vouchers'}</a></li>{/if}
          {if $neria_menu_visible.cooldown}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-cooldown">⏱ {neria_admin key='nav.sub_cooldown'}</a></li>{/if}
          {if $neria_menu_visible.silent_witness}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-archive">🔇 {neria_admin key='nav.sub_silent_witness'}</a></li>{/if}
          {if $neria_menu_visible.carbon}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-carbon">🌿 {neria_admin key='nav.sub_carbon'}</a></li>{/if}
          {if $neria_menu_visible.multi_sender}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-senders">✉ {neria_admin key='nav.sub_senders'}</a></li>{/if}
          {if $neria_menu_visible.blacklist}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-blacklist">🚫 {neria_admin key='nav.sub_blacklist'}</a></li>{/if}
          {if $neria_menu_visible.monthly_report}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-report">📅 {neria_admin key='nav.sub_report'}</a></li>{/if}
          {if $neria_menu_visible.upcoming_events && $neria_has_upcoming_events}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-upcoming">🎉 {neria_admin key='nav.sub_upcoming'}</a></li>{/if}
          {if $neria_menu_visible.custom_vars}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-customvars">⚙ {neria_admin key='nav.sub_customvars'}</a></li>{/if}
          {if $neria_menu_visible.signature}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-signature">✍ {neria_admin key='nav.sub_signature'}</a></li>{/if}
          {if $neria_menu_visible.preferences}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-preferences">✉ {neria_admin key='nav.sub_preferences'}</a></li>{/if}
          {if $neria_menu_visible.loyalty}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-loyalty-section">⭐ {neria_admin key='nav.sub_loyalty'}</a></li>{/if}
        </ul>
      </li>

      <li class="neria-bo-nav__item">
        <a href="{$neria_tab_base}&neria_tab=automations"
           class="neria-bo-nav__link {if $neria_active_tab === 'automations'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">⚙</span>
          {neria_admin key='nav.automations'}
        </a>
      </li>

      <li class="neria-bo-nav__item">
        <a href="{$neria_tab_base}&neria_tab=design"
           class="neria-bo-nav__link {if $neria_active_tab === 'design'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">◈</span>
          {neria_admin key='nav.design'}
        </a>
      </li>

      <li class="neria-bo-nav__item">
        <a href="{$neria_tab_base}&neria_tab=typography"
           class="neria-bo-nav__link {if $neria_active_tab === 'typography'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">Aa</span>
          {neria_admin key='nav.typography'}
        </a>
      </li>

      <li class="neria-bo-nav__item">
        <a href="{$neria_tab_base}&neria_tab=translations"
           class="neria-bo-nav__link {if $neria_active_tab === 'translations'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">❡</span>
          {neria_admin key='nav.translations'}
        </a>
      </li>

      <li class="neria-bo-nav__item">
        <a href="{$neria_tab_base}&neria_tab=social"
           class="neria-bo-nav__link {if $neria_active_tab === 'social'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">◎</span>
          {neria_admin key='nav.social'}
        </a>
      </li>

      <li class="neria-bo-nav__item neria-bo-nav__item--has-sub">
        <a href="{$neria_tab_base}&neria_tab=stats"
           class="neria-bo-nav__link {if $neria_active_tab === 'stats'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">◫</span>
          {neria_admin key='nav.stats'}
          <span class="neria-nav-arrow">▾</span>
        </a>
        <ul class="neria-nav-dropdown">
          {if $neria_menu_visible.health_kpi}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-health-kpi-banner">🛡 {neria_admin key='nav.sub_health_kpi'}</a></li>{/if}
          {if $neria_menu_visible.engagement}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-engagement-chart-section">📊 {neria_admin key='nav.sub_engagement'}</a></li>{/if}
          {if $neria_menu_visible.heatmap}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-heatmap-section">🌡 {neria_admin key='nav.sub_heatmap'}</a></li>{/if}
          {if $neria_menu_visible.monthly_comparison && $neria_has_monthly_comparison}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-monthly-comparison">📅 {neria_admin key='nav.sub_monthly_comparison'}</a></li>{/if}
          {if $neria_menu_visible.revenue_attribution}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-revenue-attribution">◈ {neria_admin key='nav.sub_revenue_attribution'}</a></li>{/if}
          {if $neria_has_active_abtest}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-abtest-focus">⇋ {neria_admin key='nav.sub_abtest_focus'}</a></li>{/if}
          {if $neria_menu_visible.domain_rep}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-domain-rep">🔒 {neria_admin key='nav.sub_domain_rep'}</a></li>{/if}
          {if $neria_menu_visible.pagespeed}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-visibility-section">🌐 {neria_admin key='nav.sub_visibility'}</a></li>{/if}
          {if $neria_menu_visible.search_console}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-search-console-section">🔍 {neria_admin key='nav.sub_search_console'}</a></li>{/if}
          {if $neria_menu_visible.seo_api}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-seo-api-section">📊 {neria_admin key='nav.sub_seo_api'}</a></li>{/if}
          {if $neria_menu_visible.snds}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-snds-section">🪟 {neria_admin key='nav.sub_snds'}</a></li>{/if}
          {if $neria_menu_visible.postmaster}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-postmaster-tools">🔭 {neria_admin key='nav.sub_postmaster'}</a></li>{/if}
          {if $neria_menu_visible.score_panel}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-score-panel">📋 {neria_admin key='nav.sub_score'}</a></li>{/if}
          {if $neria_menu_visible.checkout_abandonment}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-checkout-abandonment-section">🛒 {neria_admin key='nav.sub_checkout_abandonment'}</a></li>{/if}
          {if $neria_menu_visible.relationship_anniversary}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-relationship-anniversary-section">🎂 {neria_admin key='nav.sub_relationship_anniversary'}</a></li>{/if}
          {if $neria_menu_visible.upsell}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-upsell-section">⬆ {neria_admin key='nav.sub_upsell'}</a></li>{/if}
          {if $neria_menu_visible.propensity}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-propensity-section">🎯 {neria_admin key='nav.sub_propensity'}</a></li>{/if}
          {if $neria_menu_visible.golden_hour && $neria_has_golden_hour_data}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-golden-hour-section">✦ {neria_admin key='nav.sub_golden_hour'}</a></li>{/if}
          {if $neria_menu_visible.purchase_window}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-purchase-window-section">⏰ {neria_admin key='nav.sub_purchase_window'}</a></li>{/if}
          {if $neria_menu_visible.lifespan}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-lifespan-section">⏳ {neria_admin key='nav.sub_lifespan'}</a></li>{/if}
          {if $neria_menu_visible.reconciliation}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-reconciliation-section">↩ {neria_admin key='nav.sub_reconciliation'}</a></li>{/if}
          {if $neria_menu_visible.quote}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-quote-section">📄 {neria_admin key='nav.sub_quote'}</a></li>{/if}
          {if $neria_menu_visible.collection}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-collection-section">◎ {neria_admin key='nav.sub_collection'}</a></li>{/if}
          {if $neria_menu_visible.look}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-look-section">✦ {neria_admin key='nav.sub_look'}</a></li>{/if}
          {if $neria_menu_visible.waitlist}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-waitlist-section">🔔 {neria_admin key='nav.sub_waitlist'}</a></li>{/if}
          {if $neria_menu_visible.ghost_cart}<li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-ghost-cart-section">👻 {neria_admin key='nav.sub_ghost_cart'}</a></li>{/if}
        </ul>
      </li>

      {if $neria_menu_visible.abtest}
      <li class="neria-bo-nav__item">
        <a href="{$neria_tab_base}&neria_tab=abtest"
           class="neria-bo-nav__link {if $neria_active_tab === 'abtest'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">⇋</span>
          {neria_admin key='nav.abtest'}
        </a>
      </li>
      {/if}

      <li class="neria-bo-nav__item">
        <a href="{$neria_tab_base}&neria_tab=send"
           class="neria-bo-nav__link {if $neria_active_tab === 'send'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">✉</span>
          {neria_admin key='nav.manual_send'}
        </a>
      </li>

      <li class="neria-bo-nav__item">
        <a href="{$neria_tab_base}&neria_tab=multipreview"
           class="neria-bo-nav__link {if $neria_active_tab === 'multipreview'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">◩</span>
          {neria_admin key='nav.multipreview'}
        </a>
      </li>

      <li class="neria-bo-nav__item">
        <a href="{$neria_tab_base}&neria_tab=customer_history"
           class="neria-bo-nav__link {if $neria_active_tab === 'customer_history'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">⏲</span>
          {neria_admin key='nav.customer_history'}
        </a>
      </li>

      <li class="neria-bo-nav__item">
        <a href="{$neria_tab_base}&neria_tab=calendar"
           class="neria-bo-nav__link {if $neria_active_tab === 'calendar'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">◷</span>
          {neria_admin key='nav.calendar'}
        </a>
      </li>

      <li class="neria-bo-nav__item">
        <a href="{$neria_tab_base}&neria_tab=webhooks"
           class="neria-bo-nav__link {if $neria_active_tab === 'webhooks'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">⇢</span>
          {neria_admin key='nav.webhooks'}
        </a>
      </li>

      <li class="neria-bo-nav__item">
        <a href="{$neria_tab_base}&neria_tab=segments"
           class="neria-bo-nav__link {if $neria_active_tab === 'segments'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">◈</span>
          {neria_admin key='nav.segments'}
        </a>
      </li>

      <li class="neria-bo-nav__item">
        <a href="{$neria_tab_base}&neria_tab=seasonal"
           class="neria-bo-nav__link {if $neria_active_tab === 'seasonal'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">◑</span>
          {neria_admin key='nav.seasonal'}
        </a>
      </li>

      {if $neria_menu_visible.bounces}
      <li class="neria-bo-nav__item">
        <a href="{$neria_tab_base}&neria_tab=bounces"
           class="neria-bo-nav__link {if $neria_active_tab === 'bounces'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">↩</span>
          {neria_admin key='nav.bounces'}
        </a>
      </li>
      {/if}

      <li class="neria-bo-nav__item">
        <a href="{$neria_tab_base}&neria_tab=gdpr"
           class="neria-bo-nav__link {if $neria_active_tab === 'gdpr'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">⚖</span>
          {neria_admin key='nav.gdpr'}
        </a>
      </li>

      <li class="neria-bo-nav__item">
        <a href="{$neria_tab_base}&neria_tab=academy"
           class="neria-bo-nav__link {if $neria_active_tab === 'academy'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">✦</span>
          {neria_admin key='nav.academy'}
        </a>
      </li>

      {if $neria_menu_visible.certificates}
      <li class="neria-bo-nav__item">
        <a href="{$neria_tab_base}&neria_tab=certificates"
           class="neria-bo-nav__link {if $neria_active_tab === 'certificates'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">📜</span>
          {neria_admin key='nav.certificates'}
        </a>
      </li>
      {/if}

      <li class="neria-bo-nav__item">
        <a href="{$neria_tab_base}&neria_tab=control_center"
           class="neria-bo-nav__link {if $neria_active_tab === 'control_center'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">◫</span>
          {neria_admin key='nav.control_center'}
        </a>
      </li>

      <li class="neria-bo-nav__item neria-bo-nav__item--has-sub">
        <a href="{$neria_tab_base}&neria_tab=help"
           class="neria-bo-nav__link {if $neria_active_tab === 'help'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">?</span>
          {neria_admin key='nav.help'}
          <span class="neria-nav-arrow">▾</span>
        </a>
        <ul class="neria-nav-dropdown">
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=help#neria-help-diagnostic">🔍 {neria_admin key='nav.sub_diagnostic'}</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=help#neria-help-health">✅ {neria_admin key='nav.sub_health'}</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=help#neria-help-alerts">📧 {neria_admin key='nav.sub_alerts'}</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=help#neria-help-emergency">🚨 {neria_admin key='nav.sub_emergency'}</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=help#neria-help-log">📋 {neria_admin key='nav.sub_log'}</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=help#neria-help-quickguide">📖 {neria_admin key='nav.sub_quickguide'}</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=help#neria-help-danger-zone">⚠ {neria_admin key='nav.sub_danger_zone'}</a></li>
        </ul>
      </li>

    </ul>
  </nav>

  {* ── Boutons flottants haut / bas ──────────────────────────── *}
  <button type="button" id="neria-scroll-top" class="neria-scroll-fab neria-scroll-fab--top"
          onclick="window.scrollTo(0,0);" title="{neria_admin key='nav.scroll_top'}" aria-label="{neria_admin key='nav.scroll_top'}">▲</button>
  <button type="button" id="neria-scroll-bot" class="neria-scroll-fab neria-scroll-fab--bot"
          onclick="window.scrollTo(0,document.body.scrollHeight);" title="{neria_admin key='nav.scroll_bottom'}" aria-label="{neria_admin key='nav.scroll_bottom'}">▼</button>

  {* ── Modale de confirmation partagée (remplace confirm() natif partout) ── *}
  {literal}<style>
  #neria-delete-modal-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.45);z-index:99999;align-items:center;justify-content:center;}
  #neria-delete-modal-overlay.active{display:flex;}
  #neria-delete-modal{background:#fff;border-radius:10px;padding:32px 28px;max-width:420px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.18);text-align:center;}
  #neria-delete-modal h4{margin:0 0 12px;font-size:16px;color:#1a1a1a;}
  #neria-delete-modal p{margin:0 0 24px;font-size:13px;color:#666;line-height:1.6;}
  #neria-delete-modal .neria-modal-key{display:inline-block;background:#f3ede4;color:#b38b59;border-radius:4px;padding:2px 8px;font-family:monospace;font-size:12px;margin-bottom:16px;}
  #neria-delete-modal-actions{display:flex;gap:10px;justify-content:center;}
  </style>{/literal}

  <div id="neria-delete-modal-overlay">
    <div id="neria-delete-modal">
      <h4>⚠ {neria_admin key='common.confirm_modal_title'}</h4>
      <p id="neria-delete-modal-msg"></p>
      <span id="neria-delete-modal-key" class="neria-modal-key"></span>
      <div id="neria-delete-modal-actions">
        <button type="button" class="neria-btn neria-btn--secondary" onclick="neriaCloseDeleteModal();">
          {neria_admin key='common.cancel'}
        </button>
        <button type="button" class="neria-btn neria-btn--danger" id="neria-delete-modal-confirm">
          {neria_admin key='common.confirm_btn'}
        </button>
      </div>
    </div>
  </div>

  <script>
  var _neriaDeleteForm = null;
  var _neriaConfirmCallback = null;
  function neriaConfirmDelete(btn) {
    _neriaDeleteForm = btn.closest('form');
    _neriaConfirmCallback = null;
    document.getElementById('neria-delete-modal-msg').textContent = btn.getAttribute('data-confirm');
    document.getElementById('neria-delete-modal-key').textContent = btn.getAttribute('data-key') || '';
    document.getElementById('neria-delete-modal-confirm').disabled = false;
    document.getElementById('neria-delete-modal-overlay').classList.add('active');
  }
  function neriaConfirmAction(message, callback) {
    _neriaDeleteForm = null;
    _neriaConfirmCallback = callback;
    document.getElementById('neria-delete-modal-msg').textContent = message;
    document.getElementById('neria-delete-modal-key').textContent = '';
    document.getElementById('neria-delete-modal-confirm').disabled = false;
    document.getElementById('neria-delete-modal-overlay').classList.add('active');
  }
  // Pour les liens <a href> (GET) au lieu d'un <form> — appeler avec
  // onclick="return neriaConfirmLink(event, this);"
  function neriaConfirmLink(event, link) {
    event.preventDefault();
    neriaConfirmAction(link.getAttribute('data-confirm'), function() {
      window.location.href = link.href;
    });
    return false;
  }
  // Pour les liens <a href> qui déclenchent une action d'écriture côté
  // serveur (neria_action mutant l'état) : reconstruit une soumission POST
  // à partir des paramètres de l'URL au lieu de naviguer en GET — le
  // dispatch PHP exige désormais REQUEST_METHOD === 'POST' pour ces
  // actions (durcissement anti-CSRF-via-lien). Si data-confirm est présent,
  // affiche la modale de confirmation avant de soumettre.
  function neriaPostLink(event, link) {
    event.preventDefault();
    var doSubmit = function () {
      var url  = new URL(link.href, window.location.href);
      var form = document.createElement('form');
      form.method = 'post';
      form.action = url.origin + url.pathname;
      url.searchParams.forEach(function (value, key) {
        var input = document.createElement('input');
        input.type  = 'hidden';
        input.name  = key;
        input.value = value;
        form.appendChild(input);
      });
      document.body.appendChild(form);
      form.submit();
    };
    var confirmMsg = link.getAttribute('data-confirm');
    if (confirmMsg) {
      neriaConfirmAction(confirmMsg, doSubmit);
    } else {
      doSubmit();
    }
    return false;
  }
  function neriaCloseDeleteModal() {
    document.getElementById('neria-delete-modal-overlay').classList.remove('active');
    document.getElementById('neria-delete-modal-confirm').disabled = false;
    _neriaDeleteForm = null;
    _neriaConfirmCallback = null;
  }
  document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('neria-delete-modal-confirm').addEventListener('click', function(e) {
      // Garde contre le double-clic rapide : sans ça, un clic répété avant la
      // fermeture de la modale pouvait soumettre le même formulaire deux fois.
      if (e.currentTarget.disabled) { return; }
      e.currentTarget.disabled = true;
      if (_neriaDeleteForm) { _neriaDeleteForm.submit(); }
      if (_neriaConfirmCallback) { _neriaConfirmCallback(); }
      neriaCloseDeleteModal();
    });
    document.getElementById('neria-delete-modal-overlay').addEventListener('click', function(e) {
      if (e.target === this) { neriaCloseDeleteModal(); }
    });
  });
  </script>

