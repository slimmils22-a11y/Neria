{**
 * NERIA — abtest.tpl
 * Onglet A/B Testing
 * Fix 6  : lien stats avec paramètre abtest_template correctement transmis
 * Fix 11 : id unique sur chaque formulaire de création
 *}

<div class="neria-section">
  <p class="neria-section__desc">
    {l s='Testez deux versions d\'un email marketing et mesurez celle qui convertit le mieux. Seuls les templates marketing sont éligibles.' mod='neria'}
  </p>

  <div class="neria-abtest-grid">

    {foreach $eligible_templates as $key => $label}
      {assign var="status" value=$tests_status[$key]|default:'none'}

      <div class="neria-abtest-card neria-abtest-card--{$status}">

        <div class="neria-abtest-card__header">
          <span class="neria-abtest-card__label">{$label}</span>
          <span class="neria-abtest-status neria-abtest-status--{$status}">
            {if $status === 'active'}
              ● {l s='En cours' mod='neria'}
            {elseif $status === 'draft'}
              ○ {l s='Configuré' mod='neria'}
            {else}
              – {l s='Aucun test' mod='neria'}
            {/if}
          </span>
        </div>

        {if $status === 'active' && isset($tests_data[$key])}

          <div class="neria-abtest-results">
            {assign var="report" value=$ab_reports[$key]|default:[]}

            <div class="neria-abtest-variant">
              <span class="neria-abtest-variant__label">A</span>
              <span class="neria-abtest-variant__name">
                {$tests_data[$key].a.variant_name|default:'Variante A'}
              </span>
              <span class="neria-abtest-variant__rate">
                {$report.A.rate_open|default:0}% {l s='ouv.' mod='neria'}
              </span>
            </div>

            <div class="neria-abtest-variant">
              <span class="neria-abtest-variant__label neria-abtest-variant__label--b">B</span>
              <span class="neria-abtest-variant__name">
                {$tests_data[$key].b.variant_name|default:'Variante B'}
              </span>
              <span class="neria-abtest-variant__rate">
                {$report.B.rate_open|default:0}% {l s='ouv.' mod='neria'}
              </span>
            </div>
          </div>

          <div class="neria-abtest-card__actions">
            {*
              Fix 6 : neria.php lit GET abtest_template et filtre
              les données de stats avant de passer à stats.tpl
            *}
            <a href="{$smarty.server.REQUEST_URI|regex_replace:'/&neria_tab=[^&]*/':''}&neria_tab=stats&abtest_template={$key|escape:'url'}"
               class="neria-btn neria-btn--ghost neria-btn--sm">
              {l s='Voir les stats' mod='neria'}
            </a>

            <form method="post" action="{$smarty.server.REQUEST_URI}"
                  id="neria-abtest-stop-{$key}" style="display:inline">
              <input type="hidden" name="neria_action"     value="deactivate_abtest">
              <input type="hidden" name="neria_tab"        value="abtest">
              <input type="hidden" name="abtest_template"  value="{$key}">
              <button type="submit"
                      class="neria-btn neria-btn--danger neria-btn--sm"
                      data-confirm="{l s='Arrêter ce test A/B ?' mod='neria'}">
                {l s='Arrêter' mod='neria'}
              </button>
            </form>
          </div>

        {else}

          {* Fix 11 : id unique sur chaque formulaire de création *}
          <form method="post" action="{$smarty.server.REQUEST_URI}"
                id="neria-abtest-form-{$key}"
                class="neria-abtest-create-form">
            <input type="hidden" name="neria_action"    value="create_abtest">
            <input type="hidden" name="neria_tab"       value="abtest">
            <input type="hidden" name="abtest_template" value="{$key}">

            <div class="neria-abtest-inputs">
              <input type="text" name="variant_a_name"
                     class="neria-input neria-input--sm"
                     placeholder="{l s='Nom variante A (ex: Ton discret)' mod='neria'}"
                     required>
              <input type="text" name="variant_b_name"
                     class="neria-input neria-input--sm"
                     placeholder="{l s='Nom variante B (ex: Ton urgent)' mod='neria'}"
                     required>
              <div class="neria-split-wrap">
                <label class="neria-label neria-label--sm">
                  {l s='Répartition A' mod='neria'}
                </label>
                <input type="number" name="split_percent"
                       class="neria-input neria-input--number"
                       min="10" max="90" value="50">
                <span class="neria-unit">%</span>
              </div>
            </div>

            <div class="neria-abtest-card__actions">
              <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
                {if $status === 'draft'}
                  {l s='Activer' mod='neria'}
                {else}
                  {l s='Créer et activer' mod='neria'}
                {/if}
              </button>
            </div>

          </form>
        {/if}

      </div>
    {/foreach}

  </div>
</div>
