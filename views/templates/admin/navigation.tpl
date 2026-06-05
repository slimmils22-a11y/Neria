{**
 * NERIA — navigation.tpl
 * Menu de navigation principal du back-office
 * NOTE : Ce template N'ouvre PAS .neria-bo-content
 * C'est neria.php → getContent() qui encapsule :
 *   $navigation . '<div class="neria-bo-content">' . $content . '</div></div>'
 *}

<div class="neria-bo-wrap">

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
      <a href="{$smarty.server.REQUEST_URI}&neria_action=send_test"
         class="neria-btn neria-btn--ghost"
         title="{l s='Envoyer un email de test' mod='neria'}">
        <span class="neria-icon">✉</span>
        {l s='Email test' mod='neria'}
      </a>
      <span class="neria-status {if $neria_active}neria-status--on{else}neria-status--off{/if}">
        <span class="neria-status__dot"></span>
        {if $neria_active}{l s='Actif' mod='neria'}{else}{l s='Inactif' mod='neria'}{/if}
      </span>
    </div>
  </div>

  {* ── Alertes ────────────────────────────────────────────────── *}
  {if isset($neria_success) && $neria_success}
    <div class="neria-alert neria-alert--success">
      <span class="neria-alert__icon">✓</span>
      {$neria_success}
    </div>
  {/if}

  {if isset($neria_error) && $neria_error}
    <div class="neria-alert neria-alert--error">
      <span class="neria-alert__icon">✕</span>
      {$neria_error}
    </div>
  {/if}

  {* ── Navigation ────────────────────────────────────────────── *}
  <nav class="neria-bo-nav">
    <ul class="neria-bo-nav__list">

      <li class="neria-bo-nav__item">
        <a href="{$smarty.server.REQUEST_URI|regex_replace:'/&neria_tab=[^&]*/':''}&neria_tab=configure"
           class="neria-bo-nav__link {if $neria_active_tab === 'configure'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">⊞</span>
          {l s='Accueil' mod='neria'}
        </a>
      </li>

      <li class="neria-bo-nav__item">
        <a href="{$smarty.server.REQUEST_URI|regex_replace:'/&neria_tab=[^&]*/':''}&neria_tab=design"
           class="neria-bo-nav__link {if $neria_active_tab === 'design'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">◈</span>
          {l s='Design' mod='neria'}
        </a>
      </li>

      <li class="neria-bo-nav__item">
        <a href="{$smarty.server.REQUEST_URI|regex_replace:'/&neria_tab=[^&]*/':''}&neria_tab=typography"
           class="neria-bo-nav__link {if $neria_active_tab === 'typography'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">Aa</span>
          {l s='Typographie' mod='neria'}
        </a>
      </li>

      <li class="neria-bo-nav__item">
        <a href="{$smarty.server.REQUEST_URI|regex_replace:'/&neria_tab=[^&]*/':''}&neria_tab=translations"
           class="neria-bo-nav__link {if $neria_active_tab === 'translations'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">❡</span>
          {l s='Traductions' mod='neria'}
        </a>
      </li>

      <li class="neria-bo-nav__item">
        <a href="{$smarty.server.REQUEST_URI|regex_replace:'/&neria_tab=[^&]*/':''}&neria_tab=social"
           class="neria-bo-nav__link {if $neria_active_tab === 'social'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">◎</span>
          {l s='Réseaux sociaux' mod='neria'}
        </a>
      </li>

      <li class="neria-bo-nav__item">
        <a href="{$smarty.server.REQUEST_URI|regex_replace:'/&neria_tab=[^&]*/':''}&neria_tab=stats"
           class="neria-bo-nav__link {if $neria_active_tab === 'stats'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">◫</span>
          {l s='Statistiques' mod='neria'}
        </a>
      </li>

      <li class="neria-bo-nav__item">
        <a href="{$smarty.server.REQUEST_URI|regex_replace:'/&neria_tab=[^&]*/':''}&neria_tab=abtest"
           class="neria-bo-nav__link {if $neria_active_tab === 'abtest'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">⇋</span>
          {l s='A/B Testing' mod='neria'}
        </a>
      </li>

      <li class="neria-bo-nav__item neria-bo-nav__item--right">
        <a href="{$smarty.server.REQUEST_URI|regex_replace:'/&neria_tab=[^&]*/':''}&neria_tab=help"
           class="neria-bo-nav__link {if $neria_active_tab === 'help'}neria-bo-nav__link--active{/if}">
          <span class="neria-bo-nav__icon">?</span>
          {l s='Aide' mod='neria'}
        </a>
      </li>

    </ul>
  </nav>
