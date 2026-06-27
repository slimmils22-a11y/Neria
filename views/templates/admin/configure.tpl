{**
 * NERIA — configure.tpl
 * Page d'accueil du back-office
 * Fix 2 : id unique sur le select signature
 * Fix 4 : $upcoming_events doit être assignée par neria.php
 * i18n : libellés via {neria_admin key='...'} (18 langues, AdminTranslator)
 *}

{* ── KPIs rapides ───────────────────────────────────────────── *}
<div class="neria-section" id="neria-cfg-dashboard">
  <h2 class="neria-section__title">
    {neria_admin key='configure.dashboard_title'}
  </h2>
  <div class="neria-kpi-grid">
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$kpis.total_sent|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='common.emails_sent'}</div>
      <div class="neria-kpi__period">{neria_admin key='common.last_30_days'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$kpis.rate_open|default:0}%</div>
      <div class="neria-kpi__label">{neria_admin key='common.open_rate'}</div>
      <div class="neria-kpi__period">{neria_admin key='common.last_30_days'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$kpis.rate_click|default:0}%</div>
      <div class="neria-kpi__label">{neria_admin key='common.click_rate'}</div>
      <div class="neria-kpi__period">{neria_admin key='common.last_30_days'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$kpis.active_langs|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='common.active_langs'}</div>
      <div class="neria-kpi__period">{neria_admin key='configure.of_18'}</div>
    </div>
  </div>
</div>

{* ── Détection automatique de la langue ─────────────────────── *}
<div class="neria-section" id="neria-cfg-autolang">
  <h2 class="neria-section__title">
    {neria_admin key='configure.autolang_title'}
  </h2>
  <p class="neria-section__desc">
    {neria_admin key='configure.autolang_desc'}
  </p>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action"    value="save_autolang">
    <input type="hidden" name="neria_tab"       value="configure">
    <input type="hidden" name="neria_auto_lang" value="0">

    <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-size:14px; color:var(--neria-text);">
      <input type="checkbox" name="neria_auto_lang" value="1"
             style="width:16px; height:16px; cursor:pointer;"
             {if $auto_lang_enabled}checked{/if}>
      <span>{neria_admin key='configure.autolang_toggle'}</span>
    </label>

    <div style="margin-top:16px;">
      <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
        {neria_admin key='common.register'}
      </button>
    </div>
  </form>
</div>

{* ── Smart Salutation — heure locale client ─────────────────── *}
<div class="neria-section" id="neria-cfg-time-greetings">
  <h2 class="neria-section__title">⏱ Smart Salutation — Heure locale</h2>
  <p class="neria-section__desc">
    Neria injecte automatiquement la bonne formule de salutation selon l'heure locale du client
    (déduite de son adresse de livraison). Aucune retouche de template nécessaire — personnalisez
    simplement les formules ci-dessous par langue et par créneau.
  </p>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="save_time_greetings">
    <input type="hidden" name="neria_tab"    value="configure">

    {assign var="tg_langs" value=[
      'fr'=>'FR','en'=>'EN','de'=>'DE','it'=>'IT','es'=>'ES','pt'=>'PT',
      'br'=>'BR','ar'=>'AR','ja'=>'JA','ko'=>'KO','zh'=>'ZH','tw'=>'TW',
      'ru'=>'RU','tr'=>'TR','sv'=>'SV','no'=>'NO','da'=>'DA','nl'=>'NL'
    ]}
    {assign var="tg_slots" value=['morning'=>'🌅 Matin (6h–12h)','afternoon'=>'☀ Après-midi (12h–18h)','evening'=>'🌆 Soir (18h–22h)','night'=>'🌙 Nuit (22h–6h)']}

    {* ── Sélecteur de pays cibles ───────────────────────────────── *}
    <div style="margin-bottom:24px;padding:16px;background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;">
      <form method="post" action="{$smarty.server.REQUEST_URI}" id="neria-countries-form">
        <input type="hidden" name="neria_action" value="save_target_countries">
        <input type="hidden" name="neria_tab"    value="configure">

        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:10px;">
          <strong style="font-size:13px;color:var(--neria-dark);">🌍 Pays cibles</strong>
          <div style="display:flex;gap:8px;">
            <button type="button" onclick="neriaSelectAllCountries(true)"
              style="font-size:11px;padding:3px 10px;background:#fff;border:1px solid #e8d5b0;border-radius:4px;cursor:pointer;color:var(--neria-dark);">
              Tout activer
            </button>
            <button type="button" onclick="neriaSelectAllCountries(false)"
              style="font-size:11px;padding:3px 10px;background:#fff;border:1px solid #e8d5b0;border-radius:4px;cursor:pointer;color:var(--neria-dark);">
              Tout désactiver
            </button>
          </div>
        </div>

        <input type="text" id="neria-country-search" placeholder="🔍 Rechercher un pays…"
               oninput="neriaFilterCountries(this.value)"
               style="width:100%;padding:7px 12px;border:1px solid #e8d5b0;border-radius:4px;font-size:12px;margin-bottom:10px;box-sizing:border-box;">

        <div id="neria-countries-list"
             style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:4px;max-height:240px;overflow-y:auto;padding:4px;">
          {foreach $all_countries as $iso => $name}
            <label data-country="{$name|lower}" style="display:flex;align-items:center;gap:6px;font-size:12px;padding:3px 6px;border-radius:3px;cursor:pointer;white-space:nowrap;overflow:hidden;">
              <input type="checkbox" name="neria_target_countries[]" value="{$iso}"
                     {if !$target_countries || in_array($iso, $target_countries)}checked{/if}>
              <span style="overflow:hidden;text-overflow:ellipsis;" title="{$name|escape:'html'}">{$name|escape:'html'}</span>
            </label>
          {/foreach}
        </div>

        <div style="margin-top:12px;">
          <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">Enregistrer les pays</button>
          <span style="font-size:11px;color:#a09990;margin-left:10px;font-style:italic;">
            &#9432; Vide = tous les pays activés par défaut.
          </span>
        </div>
      </form>
    </div>

    <script>
    function neriaFilterCountries(q) {
      q = q.toLowerCase().trim();
      document.querySelectorAll('#neria-countries-list label').forEach(function(el) {
        el.style.display = (!q || el.dataset.country.indexOf(q) !== -1) ? '' : 'none';
      });
    }
    function neriaSelectAllCountries(check) {
      document.querySelectorAll('#neria-countries-list input[type=checkbox]').forEach(function(cb) {
        if (cb.closest('label').style.display !== 'none') cb.checked = check;
      });
    }
    </script>

    {* ── Tableau des salutations ─────────────────────────────────── *}
    <div style="overflow-x:auto;margin-top:16px;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
          <tr style="background:#f9f6f1;">
            <th style="padding:8px 10px;text-align:left;border-bottom:2px solid #e8d5b0;white-space:nowrap;">Langue</th>
            {foreach $tg_slots as $slot => $slotLabel}
              <th style="padding:8px 10px;text-align:left;border-bottom:2px solid #e8d5b0;white-space:nowrap;">{$slotLabel}</th>
            {/foreach}
          </tr>
        </thead>
        <tbody>
          {foreach $tg_langs as $code => $label}
            <tr style="border-bottom:1px solid #f0e8d8;">
              <td style="padding:6px 10px;font-weight:600;color:var(--neria-dark);white-space:nowrap;">{$label}</td>
              {foreach $tg_slots as $slot => $slotLabel}
                <td style="padding:4px 6px;">
                  <input type="text" name="neria_tg_{$code}_{$slot}" class="neria-input"
                         style="font-size:12px;padding:4px 8px;"
                         value="{$time_greetings[$code][$slot]|default:''|escape:'html'}">
                </td>
              {/foreach}
            </tr>
          {/foreach}
        </tbody>
      </table>
    </div>

    <div style="margin-top:16px;display:flex;align-items:center;gap:12px;">
      <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">Enregistrer</button>
      <span style="font-size:11px;color:#a09990;font-style:italic;">
        ✓ Plug &amp; play — la salutation remplace automatiquement la formule d'accueil dans tous vos templates.
      </span>
    </div>
  </form>

  {* ── Réinitialisation ─────────────────────────────────────────── *}
  <div style="margin-top:16px;padding:12px 16px;background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
    <span style="font-size:12px;color:#7a6f65;font-weight:600;white-space:nowrap;">🔄 Réinitialiser :</span>

    {* Toutes les langues *}
    <form method="post" action="{$smarty.server.REQUEST_URI}" style="margin:0;"
          onsubmit="return confirm('Réinitialiser toutes les langues aux valeurs par défaut ?')">
      <input type="hidden" name="neria_action" value="reset_time_greetings_all">
      <input type="hidden" name="neria_tab"    value="configure">
      <button type="submit" class="neria-btn neria-btn--sm"
              style="background:#dc2626;color:#fff;border-color:#dc2626;">
        Toutes les langues
      </button>
    </form>

    {* Une langue au choix *}
    <form method="post" action="{$smarty.server.REQUEST_URI}" style="margin:0;display:flex;align-items:center;gap:8px;"
          onsubmit="return confirm('Réinitialiser cette langue aux valeurs par défaut ?')">
      <input type="hidden" name="neria_action" value="reset_time_greetings_lang">
      <input type="hidden" name="neria_tab"    value="configure">
      <select name="neria_reset_lang" class="neria-input" style="font-size:12px;padding:4px 8px;height:auto;">
        <option value="fr">🇫🇷 Français</option>
        <option value="en">🇬🇧 English</option>
        <option value="de">🇩🇪 Deutsch</option>
        <option value="it">🇮🇹 Italiano</option>
        <option value="es">🇪🇸 Español</option>
        <option value="pt">🇵🇹 Português</option>
        <option value="br">🇧🇷 Português (BR)</option>
        <option value="ar">🇸🇦 العربية</option>
        <option value="ja">🇯🇵 日本語</option>
        <option value="ko">🇰🇷 한국어</option>
        <option value="zh">🇨🇳 中文 (ZH)</option>
        <option value="tw">🇹🇼 中文 (TW)</option>
        <option value="ru">🇷🇺 Русский</option>
        <option value="tr">🇹🇷 Türkçe</option>
        <option value="sv">🇸🇪 Svenska</option>
        <option value="no">🇳🇴 Norsk</option>
        <option value="da">🇩🇰 Dansk</option>
        <option value="nl">🇳🇱 Nederlands</option>
      </select>
      <button type="submit" class="neria-btn neria-btn--sm"
              style="background:#dc2626;color:#fff;border-color:#dc2626;">
        Réinitialiser
      </button>
    </form>
  </div>
</div>

{* ── Smart Fallbacks — prénom manquant ─────────────────────── *}
<div class="neria-section" id="neria-cfg-firstname-fallbacks">
  <h2 class="neria-section__title">✦ Smart Fallbacks — Prénom manquant</h2>
  <p class="neria-section__desc">
    Si un client s'est inscrit sans prénom, Neria remplace automatiquement <code>{ldelim}firstname{rdelim}</code>
    par le mot élégant que vous définissez ici, selon la langue de l'email.
    Laissez un champ vide pour conserver la valeur par défaut.
  </p>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="save_firstname_fallbacks">
    <input type="hidden" name="neria_tab"    value="configure">

    {assign var="fallback_langs" value=[
      'fr'=>'Français','en'=>'English','de'=>'Deutsch','it'=>'Italiano',
      'es'=>'Español','pt'=>'Português','br'=>'Português BR','ar'=>'العربية',
      'ja'=>'日本語','ko'=>'한국어','zh'=>'中文简','tw'=>'中文繁',
      'ru'=>'Русский','tr'=>'Türkçe','sv'=>'Svenska','no'=>'Norsk',
      'da'=>'Dansk','nl'=>'Nederlands'
    ]}

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;margin-top:16px;">
      {foreach $fallback_langs as $code => $label}
        <div class="neria-form-group" style="margin:0;">
          <label class="neria-label" style="font-size:11px;margin-bottom:4px;">
            {$label} <span style="color:#a09990;font-weight:400;">({$code})</span>
          </label>
          <input type="text" name="neria_fallback_{$code}" class="neria-input"
                 placeholder="{$firstname_fallbacks[$code]|default:''}"
                 value="{$firstname_fallbacks[$code]|default:''|escape:'html'}">
        </div>
      {/foreach}
    </div>

    <div style="margin-top:16px;display:flex;align-items:center;gap:12px;">
      <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
        Enregistrer les fallbacks
      </button>
      <span style="font-size:11px;color:#a09990;font-style:italic;">
        &#9432; Ces textes remplacent <code>{ldelim}firstname{rdelim}</code> uniquement si le champ est vide côté client.
      </span>
    </div>
  </form>
</div>

{* ── Bons de réduction ──────────────────────────────────────── *}
<div class="neria-section" id="neria-cfg-vouchers">
  <h2 class="neria-section__title">{neria_admin key='configure.voucher_title'}</h2>
  <p class="neria-section__desc">
    {neria_admin key='configure.voucher_desc'}
  </p>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="save_voucher_validity">
    <input type="hidden" name="neria_tab"    value="configure">

    <div class="neria-form-group">
      <label class="neria-label" for="neria-voucher-validity">
        {neria_admin key='configure.voucher_validity_label'}
      </label>
      <input type="number" id="neria-voucher-validity" name="neria_voucher_validity"
             class="neria-input" min="1" max="365" style="max-width:140px;"
             value="{$voucher_validity|default:30}">
    </div>

    <div style="margin-top:16px;">
      <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
        {neria_admin key='common.register'}
      </button>
    </div>
  </form>
</div>

{* ── Mode Silence — anti-doublon ────────────────────────────── *}
<div class="neria-section" id="neria-cfg-cooldown">
  <h2 class="neria-section__title">{neria_admin key='configure.cooldown_title'}</h2>
  <p class="neria-section__desc">{neria_admin key='configure.cooldown_desc'}</p>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="save_cooldown">
    <input type="hidden" name="neria_tab"    value="configure">

    <div class="neria-form-group">
      <input type="hidden" name="neria_cooldown_enabled" value="0">
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;color:var(--neria-text);">
        <input type="checkbox" name="neria_cooldown_enabled" value="1"
               style="width:16px;height:16px;cursor:pointer;"
               {if $cooldown_enabled}checked{/if}>
        <span>{neria_admin key='configure.cooldown_enabled_label'}</span>
      </label>
    </div>

    <div class="neria-form-group" style="margin-top:12px;">
      <label class="neria-label" for="neria-cooldown-minutes">
        {neria_admin key='configure.cooldown_minutes_label'}
      </label>
      <div style="display:flex;align-items:center;gap:8px;">
        <input type="number" id="neria-cooldown-minutes" name="neria_cooldown_minutes"
               class="neria-input" min="1" max="60" style="max-width:100px;"
               value="{$cooldown_minutes|default:10}">
        <span style="font-size:13px;color:var(--neria-text-light);">{neria_admin key='configure.cooldown_minutes_unit'}</span>
      </div>
      <p class="neria-hint">{neria_admin key='configure.cooldown_hint'}</p>
    </div>

    <div style="margin-top:16px;">
      <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
        {neria_admin key='common.register'}
      </button>
    </div>
  </form>
</div>

{* ── Empreinte carbone ──────────────────────────────────── *}
<div class="neria-section" id="neria-cfg-carbon">
  <h2 class="neria-section__title">{neria_admin key='configure.carbon_title'}</h2>
  <p class="neria-section__desc">{neria_admin key='configure.carbon_desc'}</p>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="save_carbon">
    <input type="hidden" name="neria_tab"    value="configure">

    <div class="neria-form-group">
      <input type="hidden" name="neria_carbon_enabled" value="0">
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;color:var(--neria-text);">
        <input type="checkbox" name="neria_carbon_enabled" value="1"
               style="width:16px;height:16px;cursor:pointer;"
               {if $carbon_enabled}checked{/if}>
        <span>{neria_admin key='configure.carbon_enabled_label'}</span>
      </label>
    </div>

    <div class="neria-form-group" style="margin-top:14px; max-width:480px;">
      <label class="neria-label" for="neria-carbon-link">
        {neria_admin key='configure.carbon_link_label'}
        <span class="neria-hint">{neria_admin key='configure.carbon_link_hint'}</span>
      </label>
      <input type="url" id="neria-carbon-link" name="neria_carbon_link"
             class="neria-input" placeholder="https://…"
             value="{$carbon_link|default:''}">
    </div>

    <p class="neria-hint" style="margin-top:10px;">
      {neria_admin key='configure.carbon_example'}
    </p>

    <div style="margin-top:16px;">
      <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
        {neria_admin key='common.register'}
      </button>
    </div>
  </form>
</div>

{* ── Multi-expéditeur par langue ────────────────────────── *}
<div class="neria-section" id="neria-cfg-senders">
  <h2 class="neria-section__title">{neria_admin key='configure.senders_title'}</h2>
  <p class="neria-section__desc">{neria_admin key='configure.senders_desc'}</p>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="save_senders">
    <input type="hidden" name="neria_tab"    value="configure">

    <table style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead>
        <tr style="border-bottom:2px solid var(--neria-border);">
          <th style="text-align:left;padding:8px 10px;color:var(--neria-muted);font-weight:600;width:200px;">{neria_admin key='configure.senders_col_lang'}</th>
          <th style="text-align:left;padding:8px 10px;color:var(--neria-muted);font-weight:600;">{neria_admin key='configure.senders_col_name'}</th>
          <th style="text-align:left;padding:8px 10px;color:var(--neria-muted);font-weight:600;">{neria_admin key='configure.senders_col_email'}</th>
        </tr>
      </thead>
      <tbody>
        {foreach from=$lang_labels key=iso item=label}
        <tr style="border-bottom:1px solid var(--neria-border);">
          <td style="padding:8px 10px;color:var(--neria-text);white-space:nowrap;">
            {if isset($lang_flags[$iso])}{$lang_flags[$iso]}{/if} {$label}
          </td>
          <td style="padding:6px 10px;">
            <input type="text"
                   name="neria_sender_name_{$iso}"
                   value="{if isset($senders_config[$iso]) && isset($senders_config[$iso].name)}{$senders_config[$iso].name|escape:'html'}{/if}"
                   placeholder="{neria_admin key='configure.senders_name_placeholder'}"
                   class="neria-input" style="width:100%;max-width:260px;">
          </td>
          <td style="padding:6px 10px;">
            <input type="email"
                   name="neria_sender_email_{$iso}"
                   value="{if isset($senders_config[$iso]) && isset($senders_config[$iso].email)}{$senders_config[$iso].email|escape:'html'}{/if}"
                   placeholder="contact@votreboutique.fr"
                   class="neria-input" style="width:100%;max-width:280px;">
          </td>
        </tr>
        {/foreach}
      </tbody>
    </table>

    <p class="neria-hint" style="margin-top:12px;">{neria_admin key='configure.senders_spf_hint'}</p>

    <div style="margin-top:16px;">
      <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
        {neria_admin key='common.register'}
      </button>
    </div>
  </form>
</div>

{* ── Blacklist templates ─────────────────────────────────── *}
<div class="neria-section" id="neria-cfg-blacklist">
  <h2 class="neria-section__title">{neria_admin key='configure.blacklist_title'}</h2>
  <p class="neria-section__desc">{neria_admin key='configure.blacklist_desc'}</p>

  {* Formulaire d'ajout *}
  <form method="post" action="{$smarty.server.REQUEST_URI}" style="margin-bottom:20px;">
    <input type="hidden" name="neria_action" value="add_blacklist">
    <input type="hidden" name="neria_tab"    value="configure">
    <div style="display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;">
      <div>
        <label class="neria-label" for="neria-bl-template">{neria_admin key='common.template'}</label>
        <select id="neria-bl-template" name="neria_bl_template" class="neria-select" style="max-width:280px;">
          <option value="">— {neria_admin key='configure.blacklist_choose_template'} —</option>
          {foreach $template_labels as $code => $label}
            <option value="{$code}">{$label} ({$code})</option>
          {/foreach}
        </select>
      </div>
      <div>
        <label class="neria-label" for="neria-bl-lang">{neria_admin key='configure.blacklist_language'}</label>
        <select id="neria-bl-lang" name="neria_bl_lang" class="neria-select" style="max-width:200px;">
          <option value="">{neria_admin key='configure.blacklist_all_langs'}</option>
          {foreach $lang_labels as $code => $name}
            <option value="{$code}">{$lang_flags[$code]|default:''} {$name}</option>
          {/foreach}
        </select>
      </div>
      <div style="display:flex;gap:8px;align-items:flex-end;">
        <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
          + {neria_admin key='configure.blacklist_add'}
        </button>
        {if $blacklist}
        <button type="button" class="neria-btn neria-btn--ghost neria-btn--sm"
                style="color:var(--neria-error);border-color:var(--neria-error);"
                onclick="if(confirm('{neria_admin key='configure.blacklist_reset_confirm'}'))this.closest('form').querySelector('[name=neria_action]').value='reset_blacklist',this.closest('form').submit();">
          {neria_admin key='configure.blacklist_reset'}
        </button>
        {/if}
      </div>
    </div>
  </form>

  {* Tableau des règles actives *}
  {if $blacklist}
    <div class="neria-table-wrap">
      <table class="neria-table neria-blacklist-table">
        <colgroup>
          <col style="width:220px;">
          <col style="width:160px;">
          <col style="width:150px;">
          <col style="width:60px;">
        </colgroup>
        <thead>
          <tr>
            <th>{neria_admin key='common.template'}</th>
            <th>{neria_admin key='configure.blacklist_language'}</th>
            <th>{neria_admin key='common.date'}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {foreach $blacklist as $rule}
            <tr>
              <td>
                <code style="font-size:12px;">{$rule.template}</code>
                {if isset($template_labels[$rule.template])}
                  <span style="font-size:11px;color:var(--neria-text-light);display:block;">
                    {$template_labels[$rule.template]}
                  </span>
                {/if}
              </td>
              <td>
                {if $rule.lang === ''}
                  <span class="neria-badge neria-badge--neutral">{neria_admin key='configure.blacklist_all_langs'}</span>
                {else}
                  <span>{$lang_flags[$rule.lang]|default:''} {$lang_labels[$rule.lang]|default:$rule.lang}</span>
                {/if}
              </td>
              <td style="font-size:12px;color:var(--neria-text-light);">{$rule.date_add|date_format:'%d/%m/%Y'}</td>
              <td>
                <form method="post" action="{$smarty.server.REQUEST_URI}">
                  <input type="hidden" name="neria_action" value="remove_blacklist">
                  <input type="hidden" name="neria_tab"    value="configure">
                  <input type="hidden" name="neria_bl_id"  value="{$rule.id_blacklist}">
                  <button type="submit" class="neria-btn neria-btn--ghost neria-btn--sm"
                          style="color:var(--neria-error);border-color:var(--neria-error);"
                          title="{neria_admin key='configure.blacklist_remove'}">✕</button>
                </form>
              </td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    </div>
  {else}
    <p style="font-size:13px;color:var(--neria-text-light);font-style:italic;">
      {neria_admin key='configure.blacklist_empty'}
    </p>
  {/if}
</div>

{* ── Rapport mensuel automatique ───────────────────────────── *}
<div class="neria-section" id="neria-cfg-report">
  <h2 class="neria-section__title">{neria_admin key='configure.report_title'}</h2>
  <p class="neria-section__desc">{neria_admin key='configure.report_desc'}</p>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="save_report_config">
    <input type="hidden" name="neria_tab"    value="configure">

    <div class="neria-form-group">
      <input type="hidden" name="neria_report_enabled" value="0">
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;color:var(--neria-text);">
        <input type="checkbox" name="neria_report_enabled" value="1"
               style="width:16px;height:16px;cursor:pointer;"
               {if $report_enabled}checked{/if}>
        <span>{neria_admin key='configure.report_enabled_label'}</span>
      </label>
    </div>

    <div class="neria-form-group" style="margin-top:12px;">
      <label class="neria-label" for="neria-report-recipients">
        {neria_admin key='configure.report_recipients_label'}
      </label>
      <input type="email" id="neria-report-recipients" name="neria_report_recipients"
             class="neria-input" style="max-width:380px;"
             placeholder="{neria_admin key='configure.report_recipients_placeholder'}"
             value="{$report_recipients|default:''}">
      <p class="neria-hint">{neria_admin key='configure.report_recipients_hint'}</p>
    </div>

    <div style="margin-top:16px;display:flex;align-items:center;gap:12px;">
      <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
        {neria_admin key='common.register'}
      </button>
      {if $report_last_sent}
      <span class="neria-badge neria-badge--success">
        {neria_admin key='configure.report_last_sent'} {$report_last_sent}
      </span>
      {/if}
    </div>
  </form>

  <form method="post" action="{$smarty.server.REQUEST_URI}" style="margin-top:16px;">
    <input type="hidden" name="neria_action" value="send_report_now">
    <input type="hidden" name="neria_tab"    value="configure">
    <button type="submit" class="neria-btn neria-btn--secondary neria-btn--sm">
      {neria_admin key='configure.report_send_now'}
    </button>
  </form>
</div>

{* ── Prochaines occasions calendaires ───────────────────────── *}
{* $upcoming_events est assignée par neria.php via CalendarManager::getUpcomingDates() *}
{if isset($upcoming_events) && $upcoming_events|@count > 0}
<div class="neria-section" id="neria-cfg-upcoming">
  <h2 class="neria-section__title">
    {neria_admin key='configure.upcoming_title'}
  </h2>
  <div class="neria-upcoming">
    {foreach $upcoming_events as $event}
      <div class="neria-upcoming__item {if $event.already_sent}neria-upcoming__item--sent{/if}">
        <div class="neria-upcoming__days">
          {if $event.already_sent}
            <span class="neria-badge neria-badge--success">✓</span>
          {else}
            <strong>J-{$event.days_until}</strong>
          {/if}
        </div>
        <div class="neria-upcoming__info">
          <span class="neria-upcoming__event">{$event.event_key}</span>
          <span class="neria-upcoming__lang">[{$event.lang}]</span>
        </div>
        <div class="neria-upcoming__date">{$event.send_date}</div>
        <div class="neria-upcoming__source">
          <span class="neria-badge neria-badge--{if $event.date_source === 'manuel'}accent{else}neutral{/if}">
            {$event.date_source}
          </span>
        </div>
      </div>
    {/foreach}
  </div>
</div>
{/if}

{* ── Variables personnalisées ───────────────────────────────── *}
<div class="neria-section" id="neria-cfg-customvars">
  <h2 class="neria-section__title">
    {neria_admin key='configure.customvars_title'}
  </h2>
  <p class="neria-section__desc">
    {neria_admin key='configure.customvars_desc'}
  </p>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="save_custom_vars">
    <input type="hidden" name="neria_tab"    value="configure">

    <div class="neria-form-grid">

      <div class="neria-form-group">
        <label class="neria-label" for="maison_name">
          {neria_admin key='configure.var_maison_name'}
          <span class="neria-var-tag">{literal}{maison_name}{/literal}</span>
        </label>
        <input type="text" id="maison_name" name="maison_name"
               class="neria-input"
               value="{$custom_vars.maison_name|default:''|escape:'html'}"
               placeholder="{neria_admin key='configure.ph_maison_name'}">
      </div>

      <div class="neria-form-group">
        <label class="neria-label" for="slogan">
          {neria_admin key='configure.var_slogan'}
          <span class="neria-var-tag">{literal}{slogan}{/literal}</span>
        </label>
        <input type="text" id="slogan" name="slogan"
               class="neria-input"
               value="{$custom_vars.slogan|default:''|escape:'html'}"
               placeholder="{neria_admin key='configure.ph_slogan'}">
      </div>

      <div class="neria-form-group">
        <label class="neria-label" for="founder_name">
          {neria_admin key='configure.var_founder_name'}
          <span class="neria-var-tag">{literal}{founder_name}{/literal}</span>
        </label>
        <input type="text" id="founder_name" name="founder_name"
               class="neria-input"
               value="{$custom_vars.founder_name|default:''|escape:'html'}"
               placeholder="{neria_admin key='configure.ph_founder_name'}">
      </div>

      <div class="neria-form-group">
        <label class="neria-label" for="founder_title">
          {neria_admin key='configure.var_founder_title'}
          <span class="neria-var-tag">{literal}{founder_title}{/literal}</span>
        </label>
        <input type="text" id="founder_title" name="founder_title"
               class="neria-input"
               value="{$custom_vars.founder_title|default:''|escape:'html'}"
               placeholder="{neria_admin key='configure.ph_founder_title'}">
      </div>

      <div class="neria-form-group neria-form-group--full">
        <label class="neria-label" for="signature_closing">
          {neria_admin key='configure.var_signature_closing'}
          <span class="neria-var-tag">{literal}{signature_closing}{/literal}</span>
        </label>
        <input type="text" id="signature_closing" name="signature_closing"
               class="neria-input"
               value="{$custom_vars.signature_closing|default:''|escape:'html'}"
               placeholder="{neria_admin key='configure.ph_signature_closing'}">
      </div>

      <div class="neria-form-group neria-form-group--full">
        <label class="neria-label" for="return_address">
          {neria_admin key='configure.var_return_address'}
          <span class="neria-var-tag">{literal}{return_address}{/literal}</span>
        </label>
        <textarea id="return_address" name="return_address"
                  class="neria-input" rows="3"
                  placeholder="{neria_admin key='configure.ph_return_address'}">{$custom_vars.return_address|default:''|escape:'html'}</textarea>
      </div>

      <div class="neria-form-group">
        <label class="neria-label" for="return_deadline_days">
          {neria_admin key='configure.var_return_deadline'}
          <span class="neria-var-tag">{literal}{return_deadline_days}{/literal}</span>
        </label>
        <input type="text" id="return_deadline_days" name="return_deadline_days"
               class="neria-input"
               value="{$custom_vars.return_deadline_days|default:''|escape:'html'}"
               placeholder="{neria_admin key='configure.ph_return_deadline'}">
      </div>

      <div class="neria-form-group">
        <label class="neria-label" for="return_processing_days">
          {neria_admin key='configure.var_return_processing'}
          <span class="neria-var-tag">{literal}{return_processing_days}{/literal}</span>
        </label>
        <input type="text" id="return_processing_days" name="return_processing_days"
               class="neria-input"
               value="{$custom_vars.return_processing_days|default:''|escape:'html'}"
               placeholder="{neria_admin key='configure.ph_return_processing'}">
      </div>

    </div>

    <div class="neria-form-actions">
      <button type="submit" class="neria-btn neria-btn--primary">
        {neria_admin key='common.save'}
      </button>
    </div>
  </form>
</div>

{* ── Signature manuscrite ───────────────────────────────────── *}
<div class="neria-section" id="neria-cfg-signature">
  <h2 class="neria-section__title">
    {neria_admin key='configure.signature_title'}
  </h2>
  <p class="neria-section__desc">
    {neria_admin key='configure.signature_desc'}
  </p>

  <form method="post" action="{$smarty.server.REQUEST_URI}" id="neria-signature-form">
    <input type="hidden" name="neria_action" value="generate_signature">
    <input type="hidden" name="neria_tab"    value="configure">

    <div class="neria-form-grid">

      <div class="neria-form-group">
        <label class="neria-label" for="neria-sig-style">
          {neria_admin key='configure.signature_style'}
        </label>
        {* Fix 2 : un seul id="neria-sig-style", name="sig_style" pour le POST *}
        <select id="neria-sig-style" name="sig_style" class="neria-select">
          {foreach $signature_styles as $key => $label}
            <option value="{$key}"
              {if isset($current_signature.style) && $current_signature.style === $key}selected{/if}>
              {$label}
            </option>
          {/foreach}
        </select>
      </div>

      <div class="neria-form-group">
        <label class="neria-label" for="sig_color">
          {neria_admin key='common.color'}
        </label>
        <div class="neria-color-input-wrap">
          <input type="color" id="sig_color" name="sig_color"
                 class="neria-color-picker"
                 value="{$current_signature.color|default:'#b38b59'}">
          <input type="text"
                 class="neria-input neria-input--hex"
                 value="{$current_signature.color|default:'#b38b59'}"
                 data-sync="sig_color">
        </div>
      </div>

    </div>

    <div class="neria-signature-preview" id="neria-sig-preview">
      {if isset($current_signature.url) && $current_signature.url}
        <img src="{$current_signature.url}"
             alt="{neria_admin key='common.signature'}"
             class="neria-signature-preview__img">
      {else}
        <span class="neria-signature-preview__placeholder">
          {neria_admin key='configure.signature_placeholder'}
        </span>
      {/if}
    </div>

    <div class="neria-form-actions">
      <button type="button" class="neria-btn neria-btn--ghost"
              id="neria-sig-preview-btn">
        {neria_admin key='common.preview'}
      </button>
      <button type="submit" class="neria-btn neria-btn--primary">
        {neria_admin key='configure.signature_generate'}
      </button>
    </div>

  </form>
</div>

{* ── Centre de préférences email ───────────────────────────── *}
<div class="neria-section" id="neria-cfg-preferences">
  <h2 class="neria-section__title">Centre de préférences email</h2>
  <p class="neria-section__desc">Vos clients peuvent choisir quels types d'emails ils souhaitent recevoir via un lien dans le pied de chaque email. Opt-in par défaut — seuls les clients ayant modifié leurs préférences apparaissent ici.</p>

  {if $prefs_stats}
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;margin-bottom:24px;">
    {foreach ['cart'=>'Relances panier','post'=>'Post-achat','loyalty'=>'Fidélité','behav'=>'Personnalisés','season'=>'Saisonniers','b2b'=>'Devis B2B','newsletter'=>'Newsletters'] as $cat=>$label}
      {assign var="s" value=$prefs_stats[$cat]}
      <div style="background:#fff;border:1px solid var(--neria-border);border-radius:8px;padding:14px 16px;text-align:center;">
        <div style="font-size:22px;font-weight:700;color:{if $s.opted_out > 0}#dc2626{else}#16a34a{/if};">{$s.opted_out|intval}</div>
        <div style="font-size:11px;color:var(--neria-muted);margin-top:2px;">opt-out</div>
        <div style="font-size:12px;font-weight:600;color:var(--neria-text);margin-top:6px;">{$label}</div>
        {if $s.total > 0}
        <div style="font-size:10px;color:#aaa;margin-top:2px;">sur {$s.total|intval} modifiés</div>
        {/if}
      </div>
    {/foreach}
  </div>
  {/if}

  {if $prefs_recent}
  <p style="font-size:12px;font-weight:700;color:var(--neria-text);margin:0 0 12px 0;text-transform:uppercase;letter-spacing:.06em;">Dernières modifications</p>
  <div style="overflow-x:auto;">
    <table class="neria-table" style="min-width:460px;">
      <thead>
        <tr><th>Client</th><th>Opt-out</th><th>Modifié le</th></tr>
      </thead>
      <tbody>
        {foreach $prefs_recent as $r}
        <tr>
          <td>
            <span style="font-size:13px;font-weight:600;">{$r.firstname|escape:'html'} {$r.lastname|escape:'html'}</span><br>
            <span style="font-size:11px;color:var(--neria-muted);">{$r.email|escape:'html'}</span>
          </td>
          <td style="text-align:center;">
            {if $r.nb_optout > 0}
              <span style="font-size:13px;font-weight:700;color:#dc2626;">{$r.nb_optout|intval} catégorie{if $r.nb_optout > 1}s{/if}</span>
            {else}
              <span style="font-size:12px;color:#16a34a;">✓ Toutes actives</span>
            {/if}
          </td>
          <td style="font-size:12px;color:var(--neria-muted);">{$r.date_upd|escape:'html'}</td>
        </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
  {else}
  <p style="font-size:13px;color:var(--neria-muted);font-style:italic;">Aucun client n'a encore modifié ses préférences.</p>
  {/if}
</div>

{* ── Section Programme de Fidélité ───────────────────────── *}
<div class="neria-section" id="neria-loyalty-section">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
      <h2 class="neria-section__title" style="margin:0 0 4px 0;">Programme de Fidélité</h2>
      <p class="neria-section__desc" style="margin:0;">
        Attribue des points à chaque interaction email (ouverture +1 pt, clic +3 pts, achat +10 pts) et envoie automatiquement un bon de réduction à chaque palier atteint.
      </p>
    </div>
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}" style="display:inline;">
      <input type="hidden" name="neria_action" value="loyalty_toggle">
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if $loyalty_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if $loyalty_enabled}● Actif — Désactiver{else}○ Inactif — Activer{/if}
      </button>
    </form>
  </div>

  {if $loyalty_enabled && $loyalty_global_stats}
  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px,1fr)); gap:12px; margin-bottom:28px;">
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$loyalty_global_stats.active_customers|default:0}</div>
      <div class="neria-kpi__label">Clients actifs</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$loyalty_global_stats.total_points|default:0}</div>
      <div class="neria-kpi__label">Points distribués</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$loyalty_global_stats.rewards_sent|default:0}</div>
      <div class="neria-kpi__label">Bons envoyés</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$loyalty_global_stats.cnt_open|default:0}</div>
      <div class="neria-kpi__label">Ouvertures</div>
      <div class="neria-kpi__rate">+1 pt</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$loyalty_global_stats.cnt_click|default:0}</div>
      <div class="neria-kpi__label">Clics</div>
      <div class="neria-kpi__rate">+3 pts</div>
    </div>
    <div class="neria-kpi neria-kpi--main">
      <div class="neria-kpi__value">{$loyalty_global_stats.cnt_conversion|default:0}</div>
      <div class="neria-kpi__label">Achats trackés</div>
      <div class="neria-kpi__rate">+10 pts</div>
    </div>
  </div>
  {/if}

  <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}">
    <input type="hidden" name="neria_action" value="save_loyalty_tiers">

    <p style="font-size:12px; font-weight:700; color:var(--neria-text); margin:0 0 14px 0; text-transform:uppercase; letter-spacing:.06em;">
      Configuration des paliers
    </p>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px,1fr)); gap:16px; margin-bottom:20px;">
      {foreach $loyalty_tiers as $tier}
      <div style="padding:16px; background:var(--neria-bg); border-radius:6px; border:1px solid var(--neria-border);">
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:14px;">
          {if $tier.key === 'bronze'}<span style="font-size:18px; line-height:1;">&#127949;</span>
          {elseif $tier.key === 'silver'}<span style="font-size:18px; line-height:1;">&#127948;</span>
          {else}<span style="font-size:18px; line-height:1;">&#127947;</span>{/if}
          <input type="text" name="loyalty_name_{$tier.key|escape:'html'}"
                 value="{$tier.name|escape:'html'}"
                 style="flex:1; padding:6px 10px; border:1px solid var(--neria-border); border-radius:4px;
                        font-size:14px; font-weight:600; background:var(--neria-container);">
        </div>
        <div style="margin-bottom:10px;">
          <label style="font-size:11px; color:var(--neria-muted); display:block; margin-bottom:4px;">Seuil (points)</label>
          <input type="number" name="loyalty_points_{$tier.key|escape:'html'}"
                 value="{$tier.points|intval}"
                 min="1" step="1"
                 style="width:100%; padding:7px 10px; border:1px solid var(--neria-border); border-radius:4px;
                        font-size:13px; background:var(--neria-container); box-sizing:border-box;">
        </div>
        <div style="margin-bottom:10px;">
          <label style="font-size:11px; color:var(--neria-muted); display:block; margin-bottom:4px;">Récompense (montant)</label>
          <input type="number" name="loyalty_amount_{$tier.key|escape:'html'}"
                 value="{$tier.amount|string_format:"%.2f"}"
                 min="0.01" step="0.01"
                 style="width:100%; padding:7px 10px; border:1px solid var(--neria-border); border-radius:4px;
                        font-size:13px; background:var(--neria-container); box-sizing:border-box;">
        </div>
        <label style="display:flex; align-items:center; gap:8px; font-size:12px; color:var(--neria-text); cursor:pointer;">
          <input type="checkbox" name="loyalty_percent_{$tier.key|escape:'html'}"
                 value="1" {if $tier.is_percent}checked{/if}>
          Réduction en % (sinon montant fixe {$currency_symbol})
        </label>
      </div>
      {/foreach}
    </div>

    <div class="neria-form-actions">
      <button type="submit" class="neria-btn neria-btn--primary">Enregistrer les paliers</button>
    </div>
  </form>

  {if $loyalty_enabled && $loyalty_top_customers}
  <div style="margin-top:28px;">
    <p style="font-size:12px; font-weight:700; color:var(--neria-text); margin:0 0 12px 0; text-transform:uppercase; letter-spacing:.06em;">
      Top 10 clients — points fidélité
    </p>
    <div style="overflow-x:auto;">
      <table class="neria-table" style="min-width:400px;">
        <thead>
          <tr><th>#</th><th>Client</th><th>Points</th></tr>
        </thead>
        <tbody>
          {foreach $loyalty_top_customers as $c}
          <tr>
            <td style="font-size:12px; color:var(--neria-muted); text-align:center;">{$c@iteration}</td>
            <td>
              <span style="font-size:13px; font-weight:600;">{$c.firstname|escape:'html'} {$c.lastname|escape:'html'}</span><br>
              <span style="font-size:11px; color:var(--neria-muted);">{$c.email|escape:'html'}</span>
            </td>
            <td style="font-size:16px; font-weight:700; color:var(--neria-accent); text-align:right;">{$c.total|intval}</td>
          </tr>
          {/foreach}
        </tbody>
      </table>
    </div>
  </div>
  {/if}

</div>
