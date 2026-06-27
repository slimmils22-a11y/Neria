{**
 * NERIA — abtest.tpl
 * Onglet A/B Testing
 * Fix 6  : lien stats avec paramètre abtest_template correctement transmis
 * Fix 11 : id unique sur chaque formulaire de création
 * Fix 12 : significance statistique (z-test proportions, IC 90/95/99%)
 *}

<div class="neria-section">
  <p class="neria-section__desc">
    {neria_admin key='abtest.desc'}
  </p>

  <div class="neria-abtest-grid">

    {foreach $eligible_templates as $key => $label}
      {assign var="status" value=$tests_status[$key]|default:'none'}

      <div class="neria-abtest-card neria-abtest-card--{$status}">

        <div class="neria-abtest-card__header">
          <span class="neria-abtest-card__label">{$label}</span>
          <span class="neria-abtest-status neria-abtest-status--{$status}">
            {if $status === 'active'}
              ● {neria_admin key='abtest.status_active'}
            {elseif $status === 'draft'}
              ○ {neria_admin key='abtest.status_draft'}
            {else}
              – {neria_admin key='abtest.status_none'}
            {/if}
          </span>
        </div>

        {if $status === 'active' && isset($tests_data[$key])}

          <div class="neria-abtest-results">
            {assign var="report" value=$ab_reports[$key]|default:[]}
            {assign var="sig"    value=$report.significance|default:[]}
            {assign var="winner" value=$sig.overall_winner|default:''}

            {* Variante A *}
            <div class="neria-abtest-variant{if $winner === 'A'} neria-abtest-variant--winner{/if}">
              <span class="neria-abtest-variant__label">A</span>
              <span class="neria-abtest-variant__name">
                {$tests_data[$key].a.variant_name|default:'Variante A'}
              </span>
              <span class="neria-abtest-variant__metrics">
                <span class="neria-abtest-metric">{$report.A.rate_open|default:0}% {neria_admin key='abtest.open_short'}</span>
                <span class="neria-abtest-metric neria-abtest-metric--secondary">{$report.A.rate_click|default:0}% {neria_admin key='abtest.click_short'}</span>
              </span>
              {if $winner === 'A'}<span class="neria-abtest-crown">↑</span>{/if}
            </div>

            {* Variante B *}
            <div class="neria-abtest-variant{if $winner === 'B'} neria-abtest-variant--winner{/if}">
              <span class="neria-abtest-variant__label neria-abtest-variant__label--b">B</span>
              <span class="neria-abtest-variant__name">
                {$tests_data[$key].b.variant_name|default:'Variante B'}
              </span>
              <span class="neria-abtest-variant__metrics">
                <span class="neria-abtest-metric">{$report.B.rate_open|default:0}% {neria_admin key='abtest.open_short'}</span>
                <span class="neria-abtest-metric neria-abtest-metric--secondary">{$report.B.rate_click|default:0}% {neria_admin key='abtest.click_short'}</span>
              </span>
              {if $winner === 'B'}<span class="neria-abtest-crown">↑</span>{/if}
            </div>

            {* Badge de significance statistique *}
            {if !empty($sig)}
              {assign var="conf" value=0}
              {if isset($sig.open.confidence)  && $sig.open.confidence  > $conf}{assign var="conf" value=$sig.open.confidence}{/if}
              {if isset($sig.click.confidence) && $sig.click.confidence > $conf}{assign var="conf" value=$sig.click.confidence}{/if}

              {if !($sig.open.sufficient|default:false)}
                <div class="neria-sig-badge neria-sig-badge--pending">
                  ◌ {neria_admin key='abtest.sig_running'}
                  &nbsp;— {$sig.sent_a|default:0}/{$sig.min_sample|default:100} {neria_admin key='abtest.sig_needed'}
                </div>
              {elseif $conf >= 95}
                <div class="neria-sig-badge neria-sig-badge--sig">
                  ✓ {neria_admin key='abtest.sig_at'} {$conf}%{if $winner} — {$winner} {neria_admin key='abtest.sig_wins'}{/if}
                </div>
              {elseif $conf >= 90}
                <div class="neria-sig-badge neria-sig-badge--marginal">
                  ~ {neria_admin key='abtest.sig_at'} {$conf}%{if $winner} — {$winner} {neria_admin key='abtest.sig_wins'}{/if}
                </div>
              {else}
                <div class="neria-sig-badge neria-sig-badge--pending">
                  ◌ {neria_admin key='abtest.sig_running'}
                </div>
              {/if}
            {/if}

            <p class="neria-abtest-hint">
              ✎ Pour modifier les textes de la variante B, rendez-vous dans l'onglet <strong>Traductions</strong>, sélectionnez ce template et cliquez sur Charger.
            </p>

          </div>{* /.neria-abtest-results *}

          <div class="neria-abtest-card__actions">
            <a href="{$smarty.server.REQUEST_URI|regex_replace:'/&neria_tab=[^&]*/':''}&neria_tab=stats&abtest_template={$key|escape:'url'}#neria-ab-{$key|escape:'url'}"
               class="neria-btn neria-btn--ghost neria-btn--sm">
              {neria_admin key='abtest.view_stats'}
            </a>

            <form method="post" action="{$smarty.server.REQUEST_URI}"
                  id="neria-abtest-stop-{$key}" style="display:inline">
              <input type="hidden" name="neria_action"     value="deactivate_abtest">
              <input type="hidden" name="neria_tab"        value="abtest">
              <input type="hidden" name="abtest_template"  value="{$key}">
              <button type="submit"
                      class="neria-btn neria-btn--danger neria-btn--sm"
                      data-confirm="{neria_admin key='abtest.stop_confirm'}">
                {neria_admin key='abtest.stop'}
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
                     placeholder="{neria_admin key='abtest.variant_a_ph'}"
                     required>
              <input type="text" name="variant_b_name"
                     class="neria-input neria-input--sm"
                     placeholder="{neria_admin key='abtest.variant_b_ph'}"
                     required>
              <div class="neria-split-wrap">
                <label class="neria-label neria-label--sm">
                  {neria_admin key='abtest.split_a'}
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
                  {neria_admin key='abtest.activate'}
                {else}
                  {neria_admin key='abtest.create_activate'}
                {/if}
              </button>
            </div>

          </form>
        {/if}

      </div>
    {/foreach}

  </div>
</div>
