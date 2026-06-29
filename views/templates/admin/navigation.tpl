{**
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
      <span class="neria-bo-header__logo">✦</span>
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
           title="{neria_admin key='nav.send_test_title'}">
          ✉ {neria_admin key='nav.email_test'}
        </a>
      </span>
      <script>
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

  {* ── Alertes ────────────────────────────────────────────────── *}
  {if isset($neria_success) && $neria_success}
    <div class="neria-alert neria-alert--success" data-neria-alert data-neria-action="{$neria_msg_action|default:''|escape:'html'}">
      <span class="neria-alert__icon">✓</span>
      {$neria_success}
    </div>
  {/if}

  {if isset($neria_error) && $neria_error}
    <div class="neria-alert neria-alert--error" data-neria-alert data-neria-action="{$neria_msg_action|default:''|escape:'html'}">
      <span class="neria-alert__icon">⚠</span>
      {$neria_error}
    </div>
  {/if}

  {* ── Navigation ────────────────────────────────────────────── *}
  <nav class="neria-bo-nav">
    <ul class="neria-bo-nav__list">

      {assign var="neria_tab_base" value=$smarty.server.REQUEST_URI|regex_replace:'/&neria_tab=[^&]*/':''}
      <li class="neria-bo-nav__item neria-bo-nav__item--has-sub">
        <a href="{$neria_tab_base}&neria_tab=configure"
           class="neria-bo-nav__link {if $neria_active_tab === 'configure'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">⊞</span>
          {neria_admin key='nav.home'}
          <span class="neria-nav-arrow">▾</span>
        </a>
        <ul class="neria-nav-dropdown">
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-dashboard">📊 Tableau de bord</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-autolang">🌐 Détection de langue</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-time-greetings">⏱ Smart Salutation — Heure locale</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-firstname-fallbacks">✦ Smart Fallbacks — Prénom manquant</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-vouchers">🎁 Bons de réduction</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-cooldown">⏱ Mode Silence</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-carbon">🌿 Empreinte carbone</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-senders">✉ Multi-expéditeur</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-blacklist">🚫 Blacklist templates</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-report">📅 Rapport mensuel</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-customvars">⚙ Variables personnalisées</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-signature">✍ Signature manuscrite</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-cfg-preferences">✉ Centre de préférences</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=configure#neria-loyalty-section">⭐ Programme de fidélité</a></li>
        </ul>
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
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-health-kpi-banner">🛡 Santé &amp; Tendances</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-engagement-chart-section">📊 Engagement email</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-heatmap-section">🌡 Heatmap horaire</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-monthly-comparison">📅 Comparatif mensuel</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-revenue-chart-section">📈 Graphique de revenus</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-abtest-focus">⇋ A/B Testing</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-domain-rep">🔒 Réputation de domaine</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-postmaster-tools">🔭 Postmaster Tools</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-score-panel">📋 Score de délivrabilité</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-checkout-abandonment-section">🛒 Abandon de caisse</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-relationship-anniversary-section">🎂 Anniversaire relation</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-upsell-section">⬆ Upsell intelligent</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-propensity-section">🎯 Propension d'achat</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-golden-hour-section">✦ L'Heure d'Or</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-purchase-window-section">⏰ Fenêtre d'achat</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-lifespan-section">⏳ Fin de vie produit</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-reconciliation-section">↩ Réconciliation</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-quote-section">📄 Devis B2B</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-collection-section">◎ Complétion collection</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-look-section">✦ Complétez votre look</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-waitlist-section">🔔 Liste d'attente</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=stats#neria-ghost-cart-section">👻 Panier fantôme</a></li>
        </ul>
      </li>

      <li class="neria-bo-nav__item">
        <a href="{$neria_tab_base}&neria_tab=abtest"
           class="neria-bo-nav__link {if $neria_active_tab === 'abtest'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">⇋</span>
          {neria_admin key='nav.abtest'}
        </a>
      </li>

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

      <li class="neria-bo-nav__item">
        <a href="{$neria_tab_base}&neria_tab=bounces"
           class="neria-bo-nav__link {if $neria_active_tab === 'bounces'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">↩</span>
          {neria_admin key='nav.bounces'}
        </a>
      </li>

      <li class="neria-bo-nav__item">
        <a href="{$neria_tab_base}&neria_tab=gdpr"
           class="neria-bo-nav__link {if $neria_active_tab === 'gdpr'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">⚖</span>
          RGPD
        </a>
      </li>

      <li class="neria-bo-nav__item">
        <a href="{$neria_tab_base}&neria_tab=academy"
           class="neria-bo-nav__link {if $neria_active_tab === 'academy'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">✦</span>
          {neria_admin key='nav.academy'}
        </a>
      </li>

      <li class="neria-bo-nav__item">
        <a href="{$neria_tab_base}&neria_tab=certificates"
           class="neria-bo-nav__link {if $neria_active_tab === 'certificates'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">📜</span>
          Certificats
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
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=help#neria-help-diagnostic">🔍 Diagnostic</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=help#neria-help-health">✅ Contrôles de santé</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=help#neria-help-alerts">📧 Alertes Watchdog</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=help#neria-help-emergency">🚨 Page d'urgence</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=help#neria-help-log">📋 Journal</a></li>
          <li><a class="neria-nav-dropdown__item" href="{$neria_tab_base}&neria_tab=help#neria-help-quickguide">📖 Documentation</a></li>
        </ul>
      </li>

    </ul>
  </nav>

  {* ── Boutons flottants haut / bas ──────────────────────────── *}
  <button type="button" id="neria-scroll-top" class="neria-scroll-fab neria-scroll-fab--top"
          onclick="window.scrollTo(0,0);" title="Haut de page" aria-label="Haut de page">▲</button>
  <button type="button" id="neria-scroll-bot" class="neria-scroll-fab neria-scroll-fab--bot"
          onclick="window.scrollTo(0,document.body.scrollHeight);" title="Bas de page" aria-label="Bas de page">▼</button>

