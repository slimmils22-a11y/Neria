{**
 * NERIA — abtest.tpl
 * Onglet A/B Testing
 * v1.0.14 : revenus par variante, application du gagnant, historique, durée estimée
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
                {if isset($report.A.total_revenue) && $report.A.total_revenue > 0}
                  <span class="neria-abtest-metric neria-abtest-metric--revenue" title="CA total attribué à cette variante">
                    {$report.A.total_revenue|string_format:"%.2f"}€
                  </span>
                  <span class="neria-abtest-metric neria-abtest-metric--secondary" title="CA par 100 envois">
                    {$report.A.revenue_per_100|string_format:"%.2f"}€/100e
                  </span>
                {/if}
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
                {if isset($report.B.total_revenue) && $report.B.total_revenue > 0}
                  <span class="neria-abtest-metric neria-abtest-metric--revenue" title="CA total attribué à cette variante">
                    {$report.B.total_revenue|string_format:"%.2f"}€
                  </span>
                  <span class="neria-abtest-metric neria-abtest-metric--secondary" title="CA par 100 envois">
                    {$report.B.revenue_per_100|string_format:"%.2f"}€/100e
                  </span>
                {/if}
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

              {* Durée estimée avant résultat *}
              {assign var="days_rem" value=$report.days_remaining|default:null}
              {if $days_rem !== null && $days_rem > 0}
                <div style="font-size:11px;color:var(--neria-muted);margin-top:6px;padding:4px 8px;background:#fef9f0;border-radius:4px;display:inline-block;">
                  ⏱ Résultat estimé dans <strong>{$days_rem}</strong> jour{if $days_rem > 1}s{/if}
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

            {* Bouton "Appliquer le gagnant" — visible seulement si significativité ≥ 95% *}
            {if isset($conf) && $conf >= 95 && $winner}
            <form method="post" action="{$smarty.server.REQUEST_URI}"
                  id="neria-abtest-apply-{$key}" style="display:inline">
              <input type="hidden" name="neria_action"    value="apply_abtest_winner">
              <input type="hidden" name="neria_tab"       value="abtest">
              <input type="hidden" name="abtest_template" value="{$key}">
              <input type="hidden" name="abtest_winner"   value="{$winner}">
              <button type="submit"
                      class="neria-btn neria-btn--primary neria-btn--sm"
                      data-confirm="Appliquer la variante {$winner} comme template par défaut ? Le test sera archivé et fermé."
                      title="La variante {$winner} sera promue en template par défaut pour tous les futurs envois.">
                ✓ Appliquer {$winner}
              </button>
            </form>
            {/if}

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

  {* ══ HISTORIQUE DES TESTS TERMINÉS ══════════════════════════════ *}
  {if !empty($ab_history)}
  <div style="margin-top:40px;">
    <div style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;opacity:.55;color:var(--neria-dark);margin-bottom:16px;">
      Historique des tests terminés
    </div>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
          <tr style="background:#f9f6f1;border-bottom:2px solid #e8d5b0;">
            <th style="padding:8px 12px;text-align:left;font-weight:700;color:#5c3d1e;white-space:nowrap;">Template</th>
            <th style="padding:8px 12px;text-align:center;font-weight:700;color:#5c3d1e;">Variante A</th>
            <th style="padding:8px 12px;text-align:center;font-weight:700;color:#5c3d1e;">Variante B</th>
            <th style="padding:8px 12px;text-align:center;font-weight:700;color:#5c3d1e;">Ouv.</th>
            <th style="padding:8px 12px;text-align:center;font-weight:700;color:#5c3d1e;">Clics</th>
            <th style="padding:8px 12px;text-align:center;font-weight:700;color:#5c3d1e;">CA</th>
            <th style="padding:8px 12px;text-align:center;font-weight:700;color:#5c3d1e;">Gagnant</th>
            <th style="padding:8px 12px;text-align:center;font-weight:700;color:#5c3d1e;">Confiance</th>
            <th style="padding:8px 12px;text-align:center;font-weight:700;color:#5c3d1e;">Appliqué</th>
            <th style="padding:8px 12px;text-align:right;font-weight:700;color:#5c3d1e;">Fin</th>
          </tr>
        </thead>
        <tbody>
          {foreach $ab_history as $h}
          {assign var="hWinner" value=$h.winner|default:''}
          <tr style="border-bottom:1px solid #f0e8d8;{if $h@iteration % 2 === 0}background:#fdfaf6;{/if}">
            <td style="padding:8px 12px;font-weight:600;color:var(--neria-dark);">
              {$h.template|escape:'html'}
            </td>
            <td style="padding:8px 12px;text-align:center;{if $hWinner === 'A'}font-weight:700;color:#16a34a;{/if}">
              {$h.variant_a_name|escape:'html'|default:'A'}
              {if $hWinner === 'A'} ↑{/if}
            </td>
            <td style="padding:8px 12px;text-align:center;{if $hWinner === 'B'}font-weight:700;color:#16a34a;{/if}">
              {$h.variant_b_name|escape:'html'|default:'B'}
              {if $hWinner === 'B'} ↑{/if}
            </td>
            <td style="padding:8px 12px;text-align:center;color:var(--neria-muted);">
              {$h.rate_open_a|string_format:"%.1f"}% / {$h.rate_open_b|string_format:"%.1f"}%
            </td>
            <td style="padding:8px 12px;text-align:center;color:var(--neria-muted);">
              {$h.rate_click_a|string_format:"%.1f"}% / {$h.rate_click_b|string_format:"%.1f"}%
            </td>
            <td style="padding:8px 12px;text-align:center;color:var(--neria-muted);">
              {if $h.revenue_a > 0 || $h.revenue_b > 0}
                {$h.revenue_a|string_format:"%.2f"}€ / {$h.revenue_b|string_format:"%.2f"}€
              {else}
                —
              {/if}
            </td>
            <td style="padding:8px 12px;text-align:center;">
              {if $hWinner}
                <span style="font-weight:700;color:#16a34a;">{$hWinner}</span>
              {else}
                <span style="color:var(--neria-muted);">—</span>
              {/if}
            </td>
            <td style="padding:8px 12px;text-align:center;color:var(--neria-muted);">
              {if $h.confidence}
                {$h.confidence}%
              {else}
                —
              {/if}
            </td>
            <td style="padding:8px 12px;text-align:center;">
              {if $h.applied}
                <span style="color:#16a34a;font-weight:700;" title="La variante gagnante a été appliquée comme défaut">✓</span>
              {else}
                <span style="color:var(--neria-muted);">–</span>
              {/if}
            </td>
            <td style="padding:8px 12px;text-align:right;color:var(--neria-muted);white-space:nowrap;">
              {$h.date_end|date_format:'%d/%m/%Y'}
            </td>
          </tr>
          {/foreach}
        </tbody>
      </table>
    </div>
  </div>
  {/if}

</div>
