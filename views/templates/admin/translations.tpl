{**
 * NERIA — translations.tpl
 * Onglet Traductions — v2 : recherche globale, traduction auto DeepL,
 * export/import CSV, aperçu live, boutons réinitialiser
 * Note : la modale de confirmation (#neria-delete-modal-*) est partagée
 * et définie une seule fois dans navigation.tpl, rendue sur chaque onglet.
 *}
<script>
function neriaToggleWhy() {
  var body    = document.getElementById('neria-why-body');
  var chevron = document.getElementById('neria-why-chevron');
  var hidden  = body.style.display === 'none';
  body.style.display    = hidden ? '' : 'none';
  chevron.style.transform = hidden ? '' : 'rotate(-90deg)';
  localStorage.setItem('neria_why_collapsed', hidden ? '0' : '1');
}
document.addEventListener('DOMContentLoaded', function() {
  if (localStorage.getItem('neria_why_collapsed') === '1') {
    document.getElementById('neria-why-body').style.display = 'none';
    document.getElementById('neria-why-chevron').style.transform = 'rotate(-90deg)';
  }
});
</script>

<script>
window.NERIA_SPAM_TRIGGERS = {$subject_spam_triggers_json|default:'[]'};
window.NERIA_LABELS = {$nsa_labels_json|default:'{}'};
window.NERIA_LANG   = '{$selected_lang|default:'en'}';
// Construit une URL AJAX en préservant le token PS et tous les params existants
window.neriaAjaxUrl = function(action, extra) {
  var url = new URL(window.location.href);
  url.searchParams.set('neria_action', action);
  if (extra) { Object.keys(extra).forEach(function(k){ url.searchParams.set(k, extra[k]); }); }
  return url.toString();
};
</script>

{* ── En-tête page ──────────────────────────────────────────────── *}
<div class="neria-section">
  <h2 class="neria-section__title">{neria_admin key='translations.page_title'}</h2>
  <p class="neria-section__desc">{neria_admin key='translations.page_desc'}</p>
</div>

{* ── Panneau de configuration — un seul bloc ───────────────────── *}
<div class="neria-section">
<div class="neria-card" style="padding:0;overflow:hidden;">

  {* — Bloc info repliable ————————————————————————————————————————— *}
  <div id="neria-trad-why" style="border-bottom:1px solid var(--neria-border,#e8d5b0);">
    <button type="button" onclick="neriaToggleWhy()" style="width:100%;display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:none;border:none;cursor:pointer;text-align:left;">
      <span style="font-size:13px;font-weight:700;color:var(--neria-dark,#2c1810);">
        💡 Neria est déjà traduit en 18 langues — à quoi sert cet onglet ?
      </span>
      <span id="neria-why-chevron" style="font-size:16px;color:var(--neria-accent,#b8976a);transition:transform .2s;">▾</span>
    </button>
    <div id="neria-why-body" style="padding:0 20px 20px;">
      <p style="margin:0 0 10px;font-size:13px;color:#555;line-height:1.7;">
        Oui, Neria livre tous vos emails prêts à l'emploi. Mais ces textes sont <strong>génériques</strong> : chaque boutique qui utilise Neria reçoit exactement les mêmes formulations. Cet onglet vous permet de <strong>faire sonner vos emails comme votre marque</strong>, pas comme un logiciel.
      </p>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px;">
        <div style="background:var(--neria-bg-subtle,#f9f6f1);border:1px solid var(--neria-border,#e8d5b0);border-radius:6px;padding:11px 14px;">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--neria-accent,#b8976a);margin-bottom:4px;">Adapter le ton</div>
          <div style="font-size:12px;color:#666;line-height:1.6;">Remplacez "Cher client" par "Chère Madame, Cher Monsieur" ou par le prénom — selon votre positionnement.</div>
        </div>
        <div style="background:var(--neria-bg-subtle,#f9f6f1);border:1px solid var(--neria-border,#e8d5b0);border-radius:6px;padding:11px 14px;">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--neria-accent,#b8976a);margin-bottom:4px;">Soigner une langue</div>
          <div style="font-size:12px;color:#666;line-height:1.6;">La traduction par défaut est correcte, mais votre marché attend peut-être un registre différent.</div>
        </div>
        <div style="background:var(--neria-bg-subtle,#f9f6f1);border:1px solid var(--neria-border,#e8d5b0);border-radius:6px;padding:11px 14px;">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--neria-accent,#b8976a);margin-bottom:4px;">Copywriter externe</div>
          <div style="font-size:12px;color:#666;line-height:1.6;">Exportez les textes en CSV, faites-les réécrire par un professionnel, réimportez. Zéro copier-coller.</div>
        </div>
        <div style="background:var(--neria-bg-subtle,#f9f6f1);border:1px solid var(--neria-border,#e8d5b0);border-radius:6px;padding:11px 14px;">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--neria-accent,#b8976a);margin-bottom:4px;">Multi-boutiques</div>
          <div style="font-size:12px;color:#666;line-height:1.6;">Exportez vos textes depuis une boutique et importez-les sur les autres en quelques secondes.</div>
        </div>
      </div>
      <div style="border-top:1px solid var(--neria-border,#e8d5b0);padding-top:12px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--neria-accent,#b8976a);margin-bottom:6px;">{neria_admin key='translations.howto_title'}</div>
        <ol style="margin:0 0 10px;padding-left:18px;line-height:1.9;font-size:13px;color:#666;">
          <li>{neria_admin key='translations.howto_step1'}</li>
          <li>{neria_admin key='translations.howto_step2'}</li>
          <li>{neria_admin key='translations.howto_step3'}</li>
        </ol>
        <p style="margin:0 0 5px;font-size:12px;color:#888;line-height:1.6;">📊 {neria_admin key='translations.subject_hint'}</p>
        <p style="margin:0;font-size:12px;color:#888;line-height:1.6;">🔒 <strong>Vos modifications sont protégées :</strong> les mises à jour de Neria ne les écrasent jamais. Chaque champ retravaillé est marqué <span style="background:#f3ede4;color:#b38b59;border-radius:3px;padding:1px 6px;font-size:11px;font-family:monospace;">PERSONNALISÉ</span> et toujours restaurable en un clic.</p>
      </div>
    </div>
  </div>

  {* — Recherche globale ——————————————————————————————————————————— *}
  <div style="padding:16px 20px;border-bottom:1px solid var(--neria-border,#e8d5b0);">
    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--neria-text-light,#aaa);margin-bottom:8px;">🔍 {neria_admin key='translations.search_title'}</div>
    <div class="neria-search-bar">
      <input type="text" id="neria-global-search" class="neria-input"
             placeholder="{neria_admin key='translations.search_placeholder'}" autocomplete="off">
      <button type="button" class="neria-btn neria-btn--secondary neria-btn--sm" id="neria-search-clear" style="display:none;">✕</button>
    </div>
    <div class="neria-search-results" id="neria-search-results"></div>
  </div>

  {* — Sélecteurs template / langue ———————————————————————————————— *}
  <div style="padding:16px 20px;border-bottom:1px solid var(--neria-border,#e8d5b0);">
    <div class="neria-trad-selectors" style="flex-wrap:wrap;gap:12px 16px;">
      <div class="neria-form-group">
        <label class="neria-label" for="neria-trad-template">{neria_admin key='common.template'}</label>
        <select id="neria-trad-template" name="trad_template" class="neria-select">
          <option value="">{neria_admin key='translations.choose_template'}</option>
          {foreach $template_labels as $key => $label}
            <option value="{$key}" {if isset($selected_template) && $selected_template === $key}selected{/if}>{$label}</option>
          {/foreach}
        </select>
      </div>
      <div class="neria-form-group">
        <label class="neria-label" for="neria-trad-lang">{neria_admin key='common.language'}</label>
        <select id="neria-trad-lang" name="trad_lang" class="neria-select">
          {foreach $lang_labels as $code => $name}
            <option value="{$code}" {if isset($selected_lang) && $selected_lang === $code}selected{/if}>{$lang_flags[$code]|default:''} {$name}</option>
          {/foreach}
        </select>
      </div>
      <div class="neria-form-group">
        <label class="neria-label" style="visibility:hidden">.</label>
        <button type="button" class="neria-btn neria-btn--primary" id="neria-trad-load">{neria_admin key='translations.load'}</button>
      </div>
      <div class="neria-form-group">
        <label class="neria-label" style="visibility:hidden">.</label>
        <button type="button" class="neria-btn neria-btn--secondary" id="neria-toggle-preview" title="Afficher/masquer l'aperçu en temps réel">⊞ Aperçu</button>
      </div>
      <div class="neria-form-group" style="margin-left:auto;">
        <label class="neria-label" style="visibility:hidden">.</label>
        <form method="post" style="margin:0;">
          <input type="hidden" name="neria_action" value="reload_all_translations">
          <button type="submit" class="neria-btn neria-btn--primary" title="{neria_admin key='translations.reload_all_title'}">↺ {neria_admin key='translations.reload_all'}</button>
        </form>
      </div>
    </div>
  </div>

  {* — Configuration DeepL ————————————————————————————————————————— *}
  <div style="padding:16px 20px;" id="neria-deepl-section">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
      <span class="neria-deepl-badge">DeepL</span>
      <span style="font-size:13px;font-weight:600;color:var(--neria-text);">{neria_admin key='translations.deepl_title'}</span>
      <a href="https://www.deepl.com/pro-api" target="_blank" style="font-size:11px;color:var(--neria-accent);text-decoration:none;margin-left:auto;">{neria_admin key='translations.deepl_get_key'} →</a>
    </div>
    <p style="margin:0 0 10px;font-size:13px;color:var(--neria-text);line-height:1.7;">
      <strong>DeepL</strong> est un service de traduction automatique de haute qualité — bien supérieur à Google Translate pour les textes professionnels. Il traduit vos emails Neria du français vers n'importe laquelle des 18 langues supportées en quelques secondes.
    </p>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 24px;font-size:12px;color:var(--neria-text-muted);line-height:1.6;margin-bottom:12px;">
      <div>🎁 <strong style="color:var(--neria-text);">Plan gratuit</strong> — 500 000 car./mois — suffisant pour vos 125 templates.</div>
      <div>🌍 <strong style="color:var(--neria-text);">18 langues supportées</strong> — dont japonais, coréen, arabe, chinois…</div>
      <div>🔒 <strong style="color:var(--neria-text);">Vos textes restent modifiables</strong> — DeepL pré-remplit, vous relisez et corrigez.</div>
      <div>↺ <strong style="color:var(--neria-text);">Réversible à tout moment</strong> — les textes Neria d'origine sont toujours restaurables.</div>
    </div>
    <p style="margin:0 0 10px;font-size:12px;color:var(--neria-text-muted);">
      <strong style="color:var(--neria-text);">Comment obtenir votre clé gratuite :</strong>
      créez un compte sur <strong>deepl.com</strong> → section <em>API</em> → copiez votre clé API Free (elle se termine par <code style="background:var(--neria-bg-hover);padding:1px 5px;border-radius:3px;">:fx</code>) → collez-la ci-dessous.
    </p>
    <form method="post" class="neria-deepl-key-row">
      <input type="hidden" name="neria_action" value="save_deepl_key">
      <input type="password" name="deepl_key" id="neria-deepl-key-input" class="neria-input"
             placeholder="xxxx-xxxx-xxxx-xxxx:fx  (clé gratuite) ou xxxx-xxxx-xxxx-xxxx (Pro)"
             value="{$deepl_key|default:''}">
      <button type="button" id="neria-deepl-toggle-vis" class="neria-btn neria-btn--secondary neria-btn--sm"
              title="Afficher/masquer"
              onclick="var f=document.getElementById('neria-deepl-key-input');f.type=f.type==='password'?'text':'password';">👁</button>
      <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">{neria_admin key='common.save'}</button>
      <span style="font-size:11px;color:var(--neria-text-muted);">
        {if $deepl_key|default:'' neq ''}✅ {neria_admin key='translations.deepl_configured'}
        {else}{neria_admin key='translations.deepl_hint'}{/if}
      </span>
    </form>
  </div>

  {* — Barre d'outils : Export / Import / Auto-translate / Reset ——— *}
  {if isset($translations) && $translations}
  <div style="border-top:1px solid var(--neria-border,#e8d5b0);padding:12px 20px;">
  <div class="neria-trad-toolbar" style="margin:0;border:none;background:none;padding:0;">

    {* Export CSV *}
    <div class="neria-trad-toolbar__group">
      <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--neria-text-light);">Export CSV</span>
      <form method="post" style="margin:0;">
        <input type="hidden" name="neria_action"   value="export_translations_csv">
        <input type="hidden" name="trad_template"  value="{$selected_template}">
        <input type="hidden" name="trad_lang"      value="{$selected_lang}">
        <input type="hidden" name="all_langs"      value="0">
        <button type="submit" class="neria-btn neria-btn--secondary neria-btn--sm"
                title="Télécharger le CSV de cette langue">
          ⬇ {$lang_flags[$selected_lang]|default:''} {neria_admin key='translations.export_lang'}
        </button>
      </form>
      <form method="post" style="margin:0;">
        <input type="hidden" name="neria_action"   value="export_translations_csv">
        <input type="hidden" name="trad_template"  value="{$selected_template}">
        <input type="hidden" name="trad_lang"      value="{$selected_lang}">
        <input type="hidden" name="all_langs"      value="1">
        <button type="submit" class="neria-btn neria-btn--secondary neria-btn--sm"
                title="Télécharger le CSV de toutes les langues">
          ⬇ {neria_admin key='translations.export_all_langs'}
        </button>
      </form>
    </div>

    <div class="neria-trad-toolbar__sep"></div>

    {* Import CSV *}
    <div class="neria-trad-toolbar__group">
      <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--neria-text-light);">Import CSV</span>
      <form method="post" enctype="multipart/form-data" style="margin:0;display:flex;gap:6px;align-items:center;">
        <input type="hidden" name="neria_action"  value="import_translations_csv">
        <input type="hidden" name="trad_template" value="{$selected_template}">
        <input type="hidden" name="trad_lang"     value="{$selected_lang}">
        <label class="neria-btn neria-btn--secondary neria-btn--sm" style="cursor:pointer;margin:0;">
          📂 {neria_admin key='translations.import_csv'}
          <input type="file" name="neria_csv" accept=".csv" style="display:none;"
                 onchange="this.form.submit();">
        </label>
      </form>
    </div>

    <div class="neria-trad-toolbar__sep"></div>

    {* Traduction auto DeepL *}
    <div class="neria-trad-toolbar__group">
      <span class="neria-deepl-badge">DeepL</span>
      <button type="button"
              class="neria-btn neria-btn--primary neria-btn--sm"
              id="neria-auto-translate"
              data-template="{$selected_template}"
              data-lang="{$selected_lang}"
              {if $deepl_key|default:'' eq ''}disabled title="Renseignez la clé API DeepL ci-dessus"{/if}>
        ✨ {neria_admin key='translations.auto_translate'}
      </button>
      <span id="neria-translate-status" style="font-size:11px;color:var(--neria-text-muted);"></span>
    </div>

    <div class="neria-trad-toolbar__sep"></div>

    {* Boutons réinitialiser *}
    <div class="neria-trad-toolbar__group">
      <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--neria-text-light);">Reset</span>
      <form method="post" style="margin:0;">
        <input type="hidden" name="neria_action"  value="reset_template_all_langs">
        <input type="hidden" name="trad_template" value="{$selected_template}">
        <button type="button" class="neria-btn neria-btn--warn neria-btn--sm"
                data-confirm="Réinitialiser ce template dans TOUTES les langues ? Vos textes personnalisés seront perdus."
                onclick="neriaConfirmDelete(this);">
          ↺ {neria_admin key='translations.reset_all_langs'}
        </button>
      </form>
      <form method="post" style="margin:0;">
        <input type="hidden" name="neria_action"  value="reset_all_translations">
        <button type="button" class="neria-btn neria-btn--danger neria-btn--sm"
                data-confirm="⚠ ATTENTION — Réinitialiser TOUTES les traductions de TOUS les templates ? Cette action est irréversible."
                onclick="neriaConfirmDelete(this);">
          ↺ {neria_admin key='translations.reset_all'}
        </button>
      </form>
    </div>

  </div>
  </div>{* fin wrapper toolbar *}
  {/if}

</div>{* fin neria-card *}
</div>{* fin neria-section *}

{* ── Empreinte vocale de la marque ──────────────────────────── *}
<div class="neria-section" id="neria-trad-voice">
  <h2 class="neria-section__title">🎙 {neria_admin key='translations.voice_title'}</h2>
  <p class="neria-section__desc">{neria_admin key='translations.voice_desc'}</p>

  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin:16px 0;font-size:13px;line-height:1.75;color:#4a3f35;">
    {neria_admin key='translations.voice_how'}
  </div>

  {if isset($neria_voice_warning)}
    <div class="neria-alert neria-alert--warning" style="margin-bottom:14px;">
      ⚠ {$neria_voice_warning|escape:'html'}
    </div>
  {/if}

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="save_voice_profile">
    <input type="hidden" name="neria_tab"    value="translations">
    <input type="hidden" name="trad_template" value="{$selected_template|escape:'html'}">
    <input type="hidden" name="trad_lang"      value="{$selected_lang|escape:'html'}">

    <div class="ns-row ns-row--2">
      <div class="neria-form-group">
        <label class="neria-label" for="neria-voice-banned">
          ❌ {neria_admin key='translations.voice_banned_label'}
        </label>
        <textarea id="neria-voice-banned" name="voice_banned_words" class="neria-input" rows="4"
                  placeholder="boutique&#10;produit&#10;achat">{$voice_profile.banned_words|default:''|escape:'html'}</textarea>
        <p class="neria-hint">{neria_admin key='translations.voice_banned_hint'}</p>
      </div>
      <div class="neria-form-group">
        <label class="neria-label" for="neria-voice-preferred">
          ✅ {neria_admin key='translations.voice_preferred_label'}
        </label>
        <textarea id="neria-voice-preferred" name="voice_preferred_words" class="neria-input" rows="4"
                  placeholder="création&#10;atelier&#10;savoir-faire">{$voice_profile.preferred_words|default:''|escape:'html'}</textarea>
        <p class="neria-hint">{neria_admin key='translations.voice_preferred_hint'}</p>
      </div>
    </div>

    <div class="neria-form-group" style="margin-top:12px;">
      <label class="neria-label" for="neria-voice-tone">
        🎯 {neria_admin key='translations.voice_tone_label'}
      </label>
      <textarea id="neria-voice-tone" name="voice_tone_notes" class="neria-input" rows="2"
                placeholder="{neria_admin key='translations.voice_tone_placeholder'}">{$voice_profile.tone_notes|default:''|escape:'html'}</textarea>
      <p class="neria-hint">{neria_admin key='translations.voice_tone_hint'}</p>
    </div>

    <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
      <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
        {neria_admin key='common.register'}
      </button>
      <button type="submit" name="neria_action" value="check_voice_profile" class="neria-btn neria-btn--ghost neria-btn--sm">
        🔍 {neria_admin key='translations.voice_check_btn'}
      </button>
    </div>
  </form>

  {if isset($voice_audit)}
    <div style="margin-top:20px;padding-top:16px;border-top:1px solid #e8d5b0;">
      <h3 style="font-size:14px;font-weight:700;color:#5c3d1e;margin:0 0 10px;">
        {neria_admin key='translations.voice_audit_title'}
      </h3>
      <p style="font-size:12px;color:#7a6a5a;margin:0 0 14px;">
        {$voice_audit.summary|default:''|escape:'html'}
      </p>

      {if $voice_audit.findings|@count > 0}
        <div class="neria-table-wrap">
          <table class="neria-table">
            <thead>
              <tr>
                <th>{neria_admin key='common.template'}</th>
                <th>{neria_admin key='translations.voice_audit_col_key'}</th>
                <th>{neria_admin key='translations.voice_audit_col_words'}</th>
              </tr>
            </thead>
            <tbody>
              {foreach $voice_audit.findings as $f}
                <tr>
                  <td>{$f.template|escape:'html'}</td>
                  <td style="font-family:monospace;font-size:11px;">{$f.key|escape:'html'}</td>
                  <td>
                    {foreach $f.words as $w}
                      <span class="neria-badge neria-badge--warn">{$w|escape:'html'}</span>
                    {/foreach}
                  </td>
                </tr>
              {/foreach}
            </tbody>
          </table>
        </div>
      {else}
        <p style="font-size:13px;color:#1a7a40;">✅ {neria_admin key='translations.voice_audit_clean'}</p>
      {/if}

      {if $voice_audit.preferred_word_hits|@count > 0}
        <p style="font-size:12px;color:#7a6a5a;margin-top:14px;">
          {neria_admin key='translations.voice_audit_preferred_used'}
          {foreach $voice_audit.preferred_word_hits as $word => $count}
            <span class="neria-badge" style="background:#e8f5e9;color:#1a7a40;">{$word|escape:'html'} × {$count|intval}</span>
          {/foreach}
        </p>
      {/if}
    </div>
  {/if}
</div>

{if isset($translations) && $translations}

  {* ── Layout split : éditeur + aperçu ──────────────────────────── *}
  <div class="neria-trad-layout" id="neria-trad-layout">

    {* Colonne éditeur *}
    <div class="neria-trad-editor-col">

      <div class="neria-card" id="neria-trad-editor" style="padding:0;overflow:hidden;">
        <div class="neria-trad-header" style="padding:16px 20px 0;">
          <h2 class="neria-section__title">
            {$template_labels[$selected_template]|default:$selected_template}
            <span class="neria-lang-chip">
              {$lang_flags[$selected_lang]|default:''}
              {$lang_labels[$selected_lang]|default:$selected_lang}
            </span>
          </h2>

          <div class="neria-trad-header__actions">
            <button type="button"
                    class="neria-section-reset"
                    id="neria-trad-reset"
                    data-template="{$selected_template}"
                    data-lang="{$selected_lang}">
              ↺ {neria_admin key='translations.reset_template'}
            </button>
          </div>
        </div>

        <form method="post" action="{$smarty.server.REQUEST_URI}" id="neria-trad-form">
          <input type="hidden" name="neria_action"   value="save_translations">
          <input type="hidden" name="neria_tab"       value="translations">
          <input type="hidden" name="trad_template"   value="{$selected_template}">
          <input type="hidden" name="trad_lang"       value="{$selected_lang}">

          <div class="neria-trad-fields" style="padding:0 20px;">
            {foreach $translations as $key => $value}
              <div class="neria-form-group neria-trad-field">

                <label class="neria-label" for="trad_field_{$key}">
                  {$key}
                  {if $is_custom[$key]|default:false}
                    <span class="neria-badge neria-badge--accent">
                      {neria_admin key='translations.custom_badge'}
                    </span>
                  {/if}
                </label>

                {assign var="is_subject_field" value=($key === 'greeting_main' || $key === 'fallback_subject' || $key === 'subject')}
                {if $value|strlen > 120}
                  <textarea id="trad_field_{$key}"
                            name="fields[{$key}]"
                            class="neria-textarea neria-textarea--auto"
                            rows="3"{if $is_subject_field} data-neria-subject="1"{/if}>{$value|escape:'html'}</textarea>
                {else}
                  <input type="text"
                         id="trad_field_{$key}"
                         name="fields[{$key}]"
                         class="neria-input"
                         value="{$value|escape:'html'}"{if $is_subject_field} data-neria-subject="1"{/if}>
                {/if}

                {if $is_subject_field}
                <div id="nsa_wrap_{$key}" style="margin-top:8px;">
                  <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                    <span id="nsa_title_{$key}" style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--neria-gold,#b38b59);">&#x2726;</span>
                    <span id="nsa_chars_{$key}" style="font-size:12px;color:var(--neria-text-muted,#888);"></span>
                    <div style="flex:1;min-width:80px;height:3px;border-radius:2px;background:var(--neria-border,#e8e0d5);overflow:hidden;">
                      <span id="nsa_bar_{$key}" style="display:block;height:100%;width:0;border-radius:2px;transition:width .25s,background .25s;background:#ccc;"></span>
                    </div>
                    <span id="nsa_score_{$key}" style="font-size:13px;font-weight:700;color:#ccc;min-width:52px;text-align:right;">—/100</span>
                  </div>
                  <div id="nsa_spam_{$key}" style="display:none;margin-top:5px;font-size:11px;color:#c0392b;line-height:1.4;"></div>
                  <p style="margin:6px 0 0;font-size:11px;color:var(--neria-text-muted,#aaa);line-height:1.5;">
                    Cette barre mesure la qualité de votre objet d'email : longueur idéale (20–50 car.), absence de mots spam, pas de majuscules excessives. <strong style="color:var(--neria-text,#555);">Visez 80/100 minimum</strong> pour maximiser le taux d'ouverture.
                  </p>
                </div>
                <script>
                (function() {
                  var SPAM = {$subject_spam_triggers_json|default:'[]'};
                  var lang  = '{$selected_lang|default:'en'}';
                  var NSA_L = {$nsa_labels_json|default:'{}'};
                  var L = NSA_L[lang] || NSA_L['en'];
                  var field  = document.getElementById('trad_field_{$key}');
                  var eTitle = document.getElementById('nsa_title_{$key}');
                  var eChars = document.getElementById('nsa_chars_{$key}');
                  var eBar   = document.getElementById('nsa_bar_{$key}');
                  var eScore = document.getElementById('nsa_score_{$key}');
                  var eSpam  = document.getElementById('nsa_spam_{$key}');
                  if (!field || !eChars) return;
                  if (eTitle) eTitle.textContent = '✦ ' + L.t;
                  function isCJK(str) { return /[　-鿿가-힯぀-ヿ＀-￯؀-ۿ]/.test(str); }
                  function run() {
                    var v = field.value, n = v.length, s = 100, cc, cl;
                    var cjk = isCJK(v);
                    if (n === 0) { s -= 20; cc = '#e05c5c'; cl = '0 ' + L.u + ' — ' + L.e; }
                    else if (cjk) {
                      if (n < 8)       { s -= 10; cc = '#b8600a'; cl = n + ' ' + L.u + ' — ' + L.c; }
                      else if (n <= 20){           cc = '#4a9e6b'; cl = n + ' ' + L.u + ' — ' + L.o; }
                      else if (n <= 35){ s -= 5;  cc = '#b8600a'; cl = n + ' ' + L.u + ' — ' + L.l1; }
                      else             { s -= 15; cc = '#e05c5c'; cl = n + ' ' + L.u + ' — ' + L.l2; }
                    } else {
                      if (n < 20)      { s -= 10; cc = '#b8600a'; cl = n + ' ' + L.u + ' — ' + L.c; }
                      else if (n <= 50){           cc = '#4a9e6b'; cl = n + ' ' + L.u + ' — ' + L.o; }
                      else if (n <= 70){ s -= 5;  cc = '#b8600a'; cl = n + ' ' + L.u + ' — ' + L.l1; }
                      else             { s -= 15; cc = '#e05c5c'; cl = n + ' ' + L.u + ' — ' + L.l2; }
                    }
                    var lc = v.toLowerCase(), found = [];
                    SPAM.forEach(function(w){ if (lc.indexOf(w) !== -1 && found.indexOf(w) === -1) found.push(w); });
                    s -= Math.min(24, found.length * 8);
                    var caps = 0, maxC = 0;
                    for (var i = 0; i < v.length; i++) {
                      if (v[i] >= 'A' && v[i] <= 'Z') { maxC = Math.max(maxC, ++caps); } else { caps = 0; }
                    }
                    if (maxC >= 6) s -= 10;
                    if (v.indexOf('!!!') !== -1) s -= 5;
                    s = Math.max(0, Math.min(100, s));
                    var bc = s >= 80 ? '#4a9e6b' : s >= 60 ? '#b8600a' : '#e05c5c';
                    eChars.textContent = cl; eChars.style.color = cc;
                    eScore.textContent = s + '/100'; eScore.style.color = bc;
                    eBar.style.width = s + '%'; eBar.style.background = bc;
                    eSpam.style.display = found.length ? '' : 'none';
                    eSpam.textContent = found.length ? L.s + ' ' + found.join(', ') : '';
                  }
                  field.addEventListener('input', run);
                  run();
                })();
                </script>
                {/if}

              </div>
            {/foreach}
          </div>

          <div class="neria-form-actions neria-form-actions--sticky">
            <button type="submit" class="neria-btn neria-btn--primary">
              {neria_admin key='translations.save'}
            </button>
          </div>

        </form>

        {* Changelog *}
        {if isset($translation_history)}
        <div class="neria-changelog" id="neria-changelog" style="margin-top:0;border-top:1px solid var(--neria-border,#e8d5b0);">
          <div class="neria-trad-header">
            <button type="button" class="neria-btn neria-btn--primary neria-btn--sm"
                    onclick="document.getElementById('neria-changelog-body').classList.toggle('neria-changelog--hidden');">
              {neria_admin key='translations.history_title'}
              <span class="neria-badge" style="margin-left:6px;font-size:11px;background:rgba(255,255,255,.2);color:#fff;border-radius:10px;padding:1px 7px;">{$translation_history|count}</span>
              <span style="margin-left:6px;font-size:10px;">&#9660;</span>
            </button>
          </div>

          <div id="neria-changelog-body">
            {if isset($translation_history) && $translation_history}
            <table class="neria-changelog-table">
              <thead>
                <tr>
                  <th>Date</th><th>Champ</th><th>Ancienne valeur</th><th>Nouvelle valeur</th><th>Auteur</th><th></th>
                </tr>
              </thead>
              <tbody>
                {foreach $translation_history as $entry}
                <tr>
                  <td class="neria-changelog__date">{$entry.date_formatted}</td>
                  <td class="neria-changelog__key"><code>{$entry.translation_key|escape:'html'}</code></td>
                  <td class="neria-changelog__val neria-changelog__val--old">{$entry.old_value|truncate:90|escape:'html'}</td>
                  <td class="neria-changelog__val">{$entry.new_value|truncate:90|escape:'html'}</td>
                  <td class="neria-changelog__author">{$entry.author|escape:'html'}</td>
                  <td class="neria-changelog__action" style="white-space:nowrap;display:flex;gap:6px;">
                    <form method="post" action="{$smarty.server.REQUEST_URI}#neria-changelog" style="margin:0;">
                      <input type="hidden" name="neria_action"  value="restore_translation">
                      <input type="hidden" name="neria_tab"      value="translations">
                      <input type="hidden" name="trad_template"  value="{$selected_template|escape:'html'}">
                      <input type="hidden" name="trad_lang"      value="{$selected_lang|escape:'html'}">
                      <input type="hidden" name="id_history"     value="{$entry.id_history|intval}">
                      <button type="button" class="neria-btn neria-btn--primary neria-btn--xs"
                              data-confirm="{neria_admin key='translations.restore_confirm'|escape:'html'}"
                              onclick="neriaConfirmDelete(this);">
                        {neria_admin key='translations.restore_btn'}
                      </button>
                    </form>
                    <form method="post" action="{$smarty.server.REQUEST_URI}#neria-changelog" style="margin:0;" class="neria-delete-history-form">
                      <input type="hidden" name="neria_action"  value="delete_history">
                      <input type="hidden" name="neria_tab"      value="translations">
                      <input type="hidden" name="trad_template"  value="{$selected_template|escape:'html'}">
                      <input type="hidden" name="trad_lang"      value="{$selected_lang|escape:'html'}">
                      <input type="hidden" name="id_history"     value="{$entry.id_history|intval}">
                      <button type="button" class="neria-btn neria-btn--danger neria-btn--xs"
                              data-confirm="{neria_admin key='translations.delete_history_confirm'}"
                              data-key="{$entry.translation_key|escape:'html'}"
                              onclick="neriaConfirmDelete(this);">
                        {neria_admin key='translations.delete_btn'}
                      </button>
                    </form>
                  </td>
                </tr>
                {/foreach}
              </tbody>
            </table>
            {else}
            <p class="neria-changelog__empty">Aucune modification enregistrée pour ce template dans cette langue.</p>
            {/if}
          </div>
        </div>
        {/if}

      </div>


      {* Variante B A/B test — dans la colonne éditeur, scrollable avec le reste *}
      {if isset($abtest_active) && $abtest_active && isset($translations_b) && $translations_b}
      <div class="neria-card" id="neria-trad-editor-b" style="padding:0;overflow:hidden;margin-top:16px;">
        <div class="neria-trad-header" style="padding:16px 20px 0;border-bottom:1px solid var(--neria-border,#e8d5b0);">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <div>
              <h2 class="neria-section__title" style="margin:0 0 4px;">
                {$template_labels[$selected_template]|default:$selected_template}
                <span class="neria-lang-chip">{$lang_flags[$selected_lang]|default:''} {$lang_labels[$selected_lang]|default:$selected_lang}</span>
                <span class="neria-badge neria-badge--accent" style="margin-left:8px;">Variante B</span>
              </h2>
              <p style="margin:0 0 12px;font-size:12px;color:var(--neria-text-muted,#888);">
                Textes alternatifs envoyés à la moitié de vos clients. Modifiez uniquement les champs que vous voulez tester.
              </p>
            </div>
            {* Toolbar Variante B — même structure que le toolbar A *}
            <div class="neria-trad-toolbar" style="margin:0 0 12px;padding:10px 16px;border:1px solid var(--neria-border,#e8d5b0);border-radius:var(--neria-radius,6px);background:var(--neria-bg,#fdf9f4);">

              {* Export CSV B *}
              <div class="neria-trad-toolbar__group">
                <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--neria-text-light);">Export CSV</span>
                <form method="post" style="margin:0;">
                  <input type="hidden" name="neria_action"   value="export_variant_b_csv">
                  <input type="hidden" name="trad_template"  value="{$selected_template}">
                  <input type="hidden" name="trad_lang"      value="{$selected_lang}">
                  <input type="hidden" name="id_abtest_b"    value="{$id_abtest_b}">
                  <button type="submit" class="neria-btn neria-btn--secondary neria-btn--sm"
                          title="Télécharger le CSV Variante B de cette langue">
                    ⬇ {$lang_flags[$selected_lang]|default:''} {neria_admin key='translations.export_lang'}
                  </button>
                </form>
              </div>

              <div class="neria-trad-toolbar__sep"></div>

              {* Import CSV B *}
              <div class="neria-trad-toolbar__group">
                <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--neria-text-light);">Import CSV</span>
                <form method="post" enctype="multipart/form-data" style="margin:0;display:flex;gap:6px;align-items:center;">
                  <input type="hidden" name="neria_action"   value="import_variant_b_csv">
                  <input type="hidden" name="trad_template"  value="{$selected_template}">
                  <input type="hidden" name="trad_lang"      value="{$selected_lang}">
                  <input type="hidden" name="id_abtest_b"    value="{$id_abtest_b}">
                  <label class="neria-btn neria-btn--secondary neria-btn--sm" style="cursor:pointer;margin:0;">
                    📂 {neria_admin key='translations.import_csv'}
                    <input type="file" name="neria_csv_b" accept=".csv" style="display:none;" onchange="this.form.submit();">
                  </label>
                </form>
              </div>

              <div class="neria-trad-toolbar__sep"></div>

              {* DeepL B *}
              <div class="neria-trad-toolbar__group">
                <span class="neria-deepl-badge">DeepL</span>
                <button type="button"
                        class="neria-btn neria-btn--primary neria-btn--sm"
                        id="neria-auto-translate-b"
                        data-template="{$selected_template}"
                        data-lang="{$selected_lang}"
                        data-idabtest="{$id_abtest_b}"
                        {if $deepl_key|default:'' eq ''}disabled title="Renseignez la clé API DeepL ci-dessus"{/if}>
                  ✨ {neria_admin key='translations.auto_translate'}
                </button>
                <span id="neria-translate-b-status" style="font-size:11px;color:var(--neria-text-muted);"></span>
              </div>

              <div class="neria-trad-toolbar__sep"></div>

              {* Reset B *}
              <div class="neria-trad-toolbar__group">
                <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--neria-text-light);">Reset</span>
                <form method="post" style="margin:0;">
                  <input type="hidden" name="neria_action"   value="reset_variant_b">
                  <input type="hidden" name="neria_tab"       value="translations">
                  <input type="hidden" name="trad_template"   value="{$selected_template}">
                  <input type="hidden" name="trad_lang"       value="{$selected_lang}">
                  <input type="hidden" name="id_abtest_b"     value="{$id_abtest_b}">
                  <button type="button" class="neria-btn neria-btn--warn neria-btn--sm"
                          data-confirm="Réinitialiser la Variante B ? Tous vos textes B seront supprimés et les champs afficheront à nouveau les textes de la Variante A."
                          onclick="neriaConfirmDelete(this);">
                    ↺ {neria_admin key='translations.reset_template'}
                  </button>
                </form>
              </div>

            </div>
          </div>
        </div>
        <form method="post" action="{$smarty.server.REQUEST_URI}">
          <input type="hidden" name="neria_action"   value="save_variant_b">
          <input type="hidden" name="neria_tab"       value="translations">
          <input type="hidden" name="trad_template"   value="{$selected_template}">
          <input type="hidden" name="trad_lang"       value="{$selected_lang}">
          <input type="hidden" name="id_abtest_b"     value="{$id_abtest_b}">
          <div class="neria-trad-fields" style="padding:0 20px;">
            {foreach $translations_b as $key => $value}
              <div class="neria-form-group neria-trad-field">
                <label class="neria-label" for="trad_b_{$key}">
                  {$key}
                  {if isset($is_custom_b[$key]) && $is_custom_b[$key]}
                    <span class="neria-badge neria-badge--accent">
                      {neria_admin key='translations.custom_badge'}
                    </span>
                  {/if}
                </label>
                {assign var="is_subject_field_b" value=($key === 'greeting_main' || $key === 'fallback_subject' || $key === 'subject')}
                {if $value|strlen > 120}
                  <textarea id="trad_b_{$key}" name="fields_b[{$key}]"
                            class="neria-textarea neria-textarea--auto" rows="3"
                            {if $is_subject_field_b}data-neria-subject="1"{/if}>{$value|escape:'html'}</textarea>
                {else}
                  <input type="text" id="trad_b_{$key}" name="fields_b[{$key}]"
                         class="neria-input" value="{$value|escape:'html'}"
                         {if $is_subject_field_b}data-neria-subject="1"{/if}>
                {/if}
                {if $is_subject_field_b}
                <div id="nsa_b_wrap_{$key}" style="margin-top:8px;">
                  <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                    <span id="nsa_b_title_{$key}" style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--neria-gold,#b38b59);">&#x2726;</span>
                    <span id="nsa_b_chars_{$key}" style="font-size:12px;color:var(--neria-text-muted,#888);"></span>
                    <div style="flex:1;min-width:80px;height:3px;border-radius:2px;background:var(--neria-border,#e8e0d5);overflow:hidden;">
                      <span id="nsa_b_bar_{$key}" style="display:block;height:100%;width:0;border-radius:2px;transition:width .25s,background .25s;background:#ccc;"></span>
                    </div>
                    <span id="nsa_b_score_{$key}" style="font-size:13px;font-weight:700;color:#ccc;min-width:52px;text-align:right;">—/100</span>
                  </div>
                  <div id="nsa_b_spam_{$key}" style="display:none;margin-top:5px;font-size:11px;color:#c0392b;line-height:1.4;"></div>
                  <p style="margin:6px 0 0;font-size:11px;color:var(--neria-text-muted,#aaa);line-height:1.5;">
                    Cette barre mesure la qualité de votre objet d'email : longueur idéale (20–50 car.), absence de mots spam, pas de majuscules excessives. <strong style="color:var(--neria-text,#555);">Visez 80/100 minimum</strong> pour maximiser le taux d'ouverture.
                  </p>
                </div>
                <script>
                (function() {
                  var SPAM = {$subject_spam_triggers_json|default:'[]'};
                  var lang  = '{$selected_lang|default:'en'}';
                  var NSA_L = {$nsa_labels_json|default:'{}'};
                  var L = NSA_L[lang] || NSA_L['en'];
                  var field  = document.getElementById('trad_b_{$key}');
                  var eTitle = document.getElementById('nsa_b_title_{$key}');
                  var eChars = document.getElementById('nsa_b_chars_{$key}');
                  var eBar   = document.getElementById('nsa_b_bar_{$key}');
                  var eScore = document.getElementById('nsa_b_score_{$key}');
                  var eSpam  = document.getElementById('nsa_b_spam_{$key}');
                  if (!field || !eChars) return;
                  if (eTitle) eTitle.textContent = '✦ ' + L.t;
                  function isCJK(str) { return /[　-鿿가-힯぀-ヿ＀-￯؀-ۿ]/.test(str); }
                  function run() {
                    var v = field.value, n = v.length, s = 100, cc, cl;
                    var cjk = isCJK(v);
                    if (n === 0) { s -= 20; cc = '#e05c5c'; cl = '0 ' + L.u + ' — ' + L.e; }
                    else if (cjk) {
                      if (n < 8)       { s -= 10; cc = '#b8600a'; cl = n + ' ' + L.u + ' — ' + L.c; }
                      else if (n <= 20){           cc = '#4a9e6b'; cl = n + ' ' + L.u + ' — ' + L.o; }
                      else if (n <= 35){ s -= 5;  cc = '#b8600a'; cl = n + ' ' + L.u + ' — ' + L.l1; }
                      else             { s -= 15; cc = '#e05c5c'; cl = n + ' ' + L.u + ' — ' + L.l2; }
                    } else {
                      if (n < 20)      { s -= 10; cc = '#b8600a'; cl = n + ' ' + L.u + ' — ' + L.c; }
                      else if (n <= 50){           cc = '#4a9e6b'; cl = n + ' ' + L.u + ' — ' + L.o; }
                      else if (n <= 70){ s -= 5;  cc = '#b8600a'; cl = n + ' ' + L.u + ' — ' + L.l1; }
                      else             { s -= 15; cc = '#e05c5c'; cl = n + ' ' + L.u + ' — ' + L.l2; }
                    }
                    var lc = v.toLowerCase(), found = [];
                    SPAM.forEach(function(w){ if (lc.indexOf(w) !== -1 && found.indexOf(w) === -1) found.push(w); });
                    s -= Math.min(24, found.length * 8);
                    var caps = 0, maxC = 0;
                    for (var i = 0; i < v.length; i++) {
                      if (v[i] >= 'A' && v[i] <= 'Z') { maxC = Math.max(maxC, ++caps); } else { caps = 0; }
                    }
                    if (maxC >= 6) s -= 10;
                    if (v.indexOf('!!!') !== -1) s -= 5;
                    s = Math.max(0, Math.min(100, s));
                    var bc = s >= 80 ? '#4a9e6b' : s >= 60 ? '#b8600a' : '#e05c5c';
                    eChars.textContent = cl; eChars.style.color = cc;
                    eScore.textContent = s + '/100'; eScore.style.color = bc;
                    eBar.style.width = s + '%'; eBar.style.background = bc;
                    eSpam.style.display = found.length ? '' : 'none';
                    eSpam.textContent = found.length ? L.s + ' ' + found.join(', ') : '';
                  }
                  field.addEventListener('input', run);
                  run();
                })();
                </script>
                {/if}
              </div>
            {/foreach}
          </div>
          <div class="neria-form-actions neria-form-actions--sticky">
            <button type="submit" class="neria-btn neria-btn--primary">
              {neria_admin key='translations.save'} — Variante B
            </button>
          </div>
        </form>

        {* Changelog Variante B *}
        {if isset($translation_history_b)}
        <div class="neria-changelog" id="neria-changelog-b" style="margin-top:0;border-top:1px solid var(--neria-border,#e8d5b0);">
          <div class="neria-trad-header">
            <button type="button" class="neria-btn neria-btn--primary neria-btn--sm"
                    onclick="document.getElementById('neria-changelog-b-body').classList.toggle('neria-changelog--hidden');">
              {neria_admin key='translations.history_title'} — Variante B
              <span class="neria-badge" style="margin-left:6px;font-size:11px;background:rgba(255,255,255,.2);color:#fff;border-radius:10px;padding:1px 7px;">{$translation_history_b|count}</span>
              <span style="margin-left:6px;font-size:10px;">&#9660;</span>
            </button>
          </div>
          <div id="neria-changelog-b-body">
            {if $translation_history_b}
            <table class="neria-changelog-table">
              <thead>
                <tr>
                  <th>Date</th><th>Champ</th><th>Ancienne valeur</th><th>Nouvelle valeur</th><th>Auteur</th><th></th>
                </tr>
              </thead>
              <tbody>
                {foreach $translation_history_b as $entry}
                <tr>
                  <td class="neria-changelog__date">{$entry.date_formatted}</td>
                  <td class="neria-changelog__key"><code>{$entry.translation_key|escape:'html'}</code></td>
                  <td class="neria-changelog__val neria-changelog__val--old">{$entry.old_value|truncate:90|escape:'html'}</td>
                  <td class="neria-changelog__val">{$entry.new_value|truncate:90|escape:'html'}</td>
                  <td class="neria-changelog__author">{$entry.author|escape:'html'}</td>
                  <td class="neria-changelog__action" style="white-space:nowrap;display:flex;gap:6px;">
                    <form method="post" action="{$smarty.server.REQUEST_URI}#neria-changelog-b" style="margin:0;">
                      <input type="hidden" name="neria_action"  value="restore_variant_b">
                      <input type="hidden" name="neria_tab"      value="translations">
                      <input type="hidden" name="trad_template"  value="{$selected_template|escape:'html'}">
                      <input type="hidden" name="trad_lang"      value="{$selected_lang|escape:'html'}">
                      <input type="hidden" name="id_abtest_b"    value="{$id_abtest_b}">
                      <input type="hidden" name="id_history"     value="{$entry.id_history|intval}">
                      <button type="button" class="neria-btn neria-btn--primary neria-btn--xs"
                              data-confirm="{neria_admin key='translations.restore_confirm'|escape:'html'}"
                              onclick="neriaConfirmDelete(this);">
                        {neria_admin key='translations.restore_btn'}
                      </button>
                    </form>
                    <form method="post" action="{$smarty.server.REQUEST_URI}#neria-changelog-b" style="margin:0;" class="neria-delete-history-form">
                      <input type="hidden" name="neria_action"  value="delete_history">
                      <input type="hidden" name="neria_tab"      value="translations">
                      <input type="hidden" name="trad_template"  value="{$selected_template|escape:'html'}">
                      <input type="hidden" name="trad_lang"      value="{$selected_lang|escape:'html'}">
                      <input type="hidden" name="id_history"     value="{$entry.id_history|intval}">
                      <button type="button" class="neria-btn neria-btn--danger neria-btn--xs"
                              data-confirm="{neria_admin key='translations.delete_history_confirm'}"
                              data-key="{$entry.translation_key|escape:'html'}"
                              onclick="neriaConfirmDelete(this);">
                        {neria_admin key='translations.delete_btn'}
                      </button>
                    </form>
                  </td>
                </tr>
                {/foreach}
              </tbody>
            </table>
            {else}
            <p class="neria-changelog__empty">Aucune modification enregistrée pour la Variante B de ce template.</p>
            {/if}
          </div>
        </div>
        {/if}

      </div>
      {/if}

    </div>{* /editor col *}

    {* Colonne aperçu (masquée par défaut) *}
    <div class="neria-trad-preview-col" id="neria-trad-preview-col" style="display:none;">
      <div class="neria-preview-header">
        <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--neria-text-light);">
          ⊞ Aperçu — {$template_labels[$selected_template]|default:$selected_template}
          <span class="neria-lang-chip" style="margin-left:6px;">
            {$lang_flags[$selected_lang]|default:''} {$lang_labels[$selected_lang]|default:$selected_lang}
          </span>
        </span>
        <div style="display:flex;align-items:center;gap:8px;">
          {if isset($abtest_active) && $abtest_active}
          <div id="neria-preview-tabs" style="display:flex;border:1px solid var(--neria-border,#e8d5b0);border-radius:4px;overflow:hidden;">
            <button type="button" id="neria-preview-tab-a"
                    style="padding:4px 12px;font-size:11px;font-weight:700;letter-spacing:.06em;background:var(--neria-gold,#b38b59);color:#fff;border:none;cursor:pointer;">
              A
            </button>
            <button type="button" id="neria-preview-tab-b"
                    style="padding:4px 12px;font-size:11px;font-weight:700;letter-spacing:.06em;background:transparent;color:var(--neria-text-muted,#888);border:none;cursor:pointer;">
              B
            </button>
          </div>
          {/if}
          <button type="button" class="neria-btn neria-btn--secondary neria-btn--sm"
                  onclick="document.getElementById('neria-trad-preview').contentWindow.location.reload();">
            ↺ Rafraîchir
          </button>
        </div>
      </div>
      <iframe id="neria-trad-preview"
              src="{$smarty.server.REQUEST_URI}&neria_action=preview&neria_template={$selected_template}&neria_lang={$selected_lang}"
              frameborder="0" scrolling="no"
              style="width:100%;height:1200px;border:1px solid var(--neria-border,#e8d5b0);border-radius:4px;background:#fff;display:block;"></iframe>
    </div>

  </div>{* /trad-layout *}

{else}

  <div class="neria-empty-state">
    <span class="neria-empty-state__icon">❡</span>
    <p>{neria_admin key='translations.empty'}</p>
  </div>

{/if}


<script>
function escHtml(s) {
  return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
document.addEventListener('DOMContentLoaded', function() {

  // ── Recherche globale ───────────────────────────────────────────
  var searchInput   = document.getElementById('neria-global-search');
  var searchResults = document.getElementById('neria-search-results');
  var searchClear   = document.getElementById('neria-search-clear');
  var searchTimer   = null;

  if (searchInput) {
    searchInput.addEventListener('input', function() {
      var q = this.value.trim();
      clearTimeout(searchTimer);
      searchClear.style.display = q ? '' : 'none';
      if (q.length < 2) { searchResults.classList.remove('active'); searchResults.innerHTML = ''; return; }
      searchTimer = setTimeout(function() {
        var url = window.neriaAjaxUrl('search_translations') + '&q=' + encodeURIComponent(q);
        fetch(url).then(function(r){ return r.json(); }).then(function(data) {
          var items = data.results || [];
          if (!items.length) {
            searchResults.innerHTML = '<div class="neria-search-empty">Aucun résultat pour « ' + escHtml(q) + ' »</div>';
          } else {
            var html = '';
            items.forEach(function(item) {
              var hl = escHtml(item.value).replace(new RegExp('(' + escHtml(q).replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + ')', 'gi'), '<mark>$1</mark>');
              html += '<div class="neria-search-result-item" data-template="' + escHtml(item.template) + '" data-lang="' + escHtml(item.lang) + '">'
                    + '<span class="neria-search-result-item__tpl">' + escHtml(item.template_label) + '</span>'
                    + '<span class="neria-search-result-item__lang">' + escHtml(item.lang) + '</span>'
                    + '<span class="neria-search-result-item__val">' + hl + '</span>'
                    + '<span class="neria-search-result-item__key">' + escHtml(item.key) + '</span>'
                    + '</div>';
            });
            searchResults.innerHTML = html;
            searchResults.querySelectorAll('.neria-search-result-item').forEach(function(el) {
              el.addEventListener('click', function() {
                var tpl  = this.getAttribute('data-template');
                var lang = this.getAttribute('data-lang');
                var sel  = document.getElementById('neria-trad-template');
                var lsel = document.getElementById('neria-trad-lang');
                if (sel) sel.value = tpl;
                if (lsel) lsel.value = lang;
                searchResults.classList.remove('active');
                searchInput.value = '';
                searchClear.style.display = 'none';
                document.getElementById('neria-trad-load').click();
              });
            });
          }
          searchResults.classList.add('active');
        }).catch(function(){ searchResults.innerHTML = '<div class="neria-search-empty">Erreur de recherche.</div>'; searchResults.classList.add('active'); });
      }, 300);
    });
    if (searchClear) {
      searchClear.addEventListener('click', function() {
        searchInput.value = ''; searchInput.focus();
        searchResults.classList.remove('active'); searchResults.innerHTML = '';
        this.style.display = 'none';
      });
    }
    document.addEventListener('click', function(e) {
      if (!searchResults.contains(e.target) && e.target !== searchInput) {
        searchResults.classList.remove('active');
      }
    });
  }

  // ── Traduction automatique DeepL ────────────────────────────────
  var btnTranslate = document.getElementById('neria-auto-translate');
  var translateStatus = document.getElementById('neria-translate-status');
  if (btnTranslate) {
    btnTranslate.addEventListener('click', function() {
      var self = this;
      neriaConfirmAction('Traduire automatiquement TOUS les champs depuis le français via DeepL ?\n\nLes champs existants seront écrasés.', function() {
        var tpl  = self.getAttribute('data-template');
        var lang = self.getAttribute('data-lang');
        btnTranslate.disabled = true;
        translateStatus.textContent = '⏳ Traduction en cours...';
        var url = window.neriaAjaxUrl('auto_translate_template') + '&trad_template=' + encodeURIComponent(tpl) + '&trad_lang=' + encodeURIComponent(lang);
        fetch(url).then(function(r){ return r.json(); }).then(function(data) {
          btnTranslate.disabled = false;
          if (data.error) {
            translateStatus.textContent = '❌ ' + data.error;
            translateStatus.style.color = '#c0392b';
          } else {
            translateStatus.textContent = '✅ ' + data.message;
            translateStatus.style.color = '#16a34a';
            setTimeout(function() { window.location.reload(); }, 1500);
          }
        }).catch(function() {
          btnTranslate.disabled = false;
          translateStatus.textContent = '❌ Erreur réseau';
          translateStatus.style.color = '#c0392b';
        });
      });
    });
  }

  // ── Traduction automatique DeepL — Variante B ───────────────────
  var btnTranslateB = document.getElementById('neria-auto-translate-b');
  var translateStatusB = document.getElementById('neria-translate-b-status');
  if (btnTranslateB) {
    btnTranslateB.addEventListener('click', function() {
      var self = this;
      neriaConfirmAction('Traduire automatiquement tous les champs de la Variante B depuis le français via DeepL ?\n\nSeuls les champs non renseignés seront traduits.', function() {
      var tpl      = self.getAttribute('data-template');
      var lang     = self.getAttribute('data-lang');
      var idAbtest = self.getAttribute('data-idabtest');
      btnTranslateB.disabled = true;
      translateStatusB.textContent = '⏳ Traduction en cours...';
      var url = window.neriaAjaxUrl('auto_translate_variant_b')
              + '&trad_template=' + encodeURIComponent(tpl)
              + '&trad_lang='     + encodeURIComponent(lang)
              + '&id_abtest_b='   + encodeURIComponent(idAbtest);
      fetch(url).then(function(r){ return r.json(); }).then(function(data) {
        btnTranslateB.disabled = false;
        if (data.error) {
          translateStatusB.textContent = '❌ ' + data.error;
          translateStatusB.style.color = '#c0392b';
        } else {
          translateStatusB.textContent = '✅ ' + data.message;
          translateStatusB.style.color = '#16a34a';
          setTimeout(function() { window.location.reload(); }, 1500);
        }
      }).catch(function() {
        btnTranslateB.disabled = false;
        translateStatusB.textContent = '❌ Erreur réseau';
        translateStatusB.style.color = '#c0392b';
      });
      });
    });
  }

  // ── Toggle aperçu (en bas) ──────────────────────────────────────
  var btnToggle  = document.getElementById('neria-toggle-preview');
  var previewCol = document.getElementById('neria-trad-preview-col');
  if (btnToggle && previewCol) {
    btnToggle.addEventListener('click', function() {
      var isHidden = previewCol.style.display === 'none';
      previewCol.style.display = isHidden ? 'flex' : 'none';
      btnToggle.textContent = isHidden ? '✕ Masquer aperçu' : '⊞ Aperçu';
      if (isHidden) {
        setTimeout(function() {
          previewCol.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 50);
      }
    });
  }

  // ── Redimensionner l'iframe à la hauteur de son contenu ─────────
  function neriaFitIframe(f) {
    try {
      var doc = f.contentWindow.document;
      var h = Math.max(
        doc.body.scrollHeight,
        doc.body.offsetHeight,
        doc.documentElement.scrollHeight,
        doc.documentElement.offsetHeight
      );
      if (h > 100) { f.style.height = (h + 80) + 'px'; }
    } catch(e) {}
  }
  var previewIframe = document.getElementById('neria-trad-preview');
  if (previewIframe) {
    previewIframe.addEventListener('load', function() {
      var f = this;
      setTimeout(function() { neriaFitIframe(f); }, 100);
      setTimeout(function() { neriaFitIframe(f); }, 600);
      setTimeout(function() { neriaFitIframe(f); }, 1500);
    });
  }

  // ── Onglets A / B aperçu ────────────────────────────────────────
  var tabA    = document.getElementById('neria-preview-tab-a');
  var tabB    = document.getElementById('neria-preview-tab-b');
  var iframe  = document.getElementById('neria-trad-preview');
  if (tabA && tabB && iframe) {
    var basePreviewSrc = iframe.src;
    var goldColor  = 'var(--neria-gold,#b38b59)';
    var mutedColor = 'var(--neria-text-muted,#888)';
    tabA.addEventListener('click', function() {
      tabA.style.background = goldColor; tabA.style.color = '#fff';
      tabB.style.background = 'transparent'; tabB.style.color = mutedColor;
      iframe.src = basePreviewSrc;
    });
    tabB.addEventListener('click', function() {
      tabB.style.background = goldColor; tabB.style.color = '#fff';
      tabA.style.background = 'transparent'; tabA.style.color = mutedColor;
      iframe.src = basePreviewSrc + '&neria_variant=b';
    });
  }

  // ── Auto-refresh aperçu après save ─────────────────────────────
  var tradForm = document.getElementById('neria-trad-form');
  if (tradForm) {
    tradForm.addEventListener('submit', function() {
      var iframe = document.getElementById('neria-trad-preview');
      if (iframe) {
        setTimeout(function() { iframe.contentWindow.location.reload(); }, 800);
      }
    });
  }

});
</script>
