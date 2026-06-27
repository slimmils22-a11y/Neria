{**
 * NERIA — bounces.tpl
 * Gestion des adresses email invalides (bounces)
 *}

<style>
.nb-hero {
    background: linear-gradient(135deg, #1a2e1a 0%, #2d5a2d 100%);
    border-radius: 10px;
    padding: 24px 30px;
    margin-bottom: 22px;
    display: flex;
    align-items: center;
    gap: 18px;
}
.nb-hero__icon  { font-size: 34px; flex-shrink: 0; color: #ffffff !important; }
.nb-hero__title { font-size: 18px; font-weight: 700; color: #ffffff !important; margin: 0 0 4px; }
.nb-hero__sub   { font-size: 12px; color: rgba(255,255,255,.65); margin: 0; line-height: 1.6; }

.nb-stats {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 10px;
    margin-bottom: 22px;
}
.nb-stat {
    background: #fff;
    border: 1px solid var(--neria-border);
    border-radius: 8px;
    padding: 14px 16px;
    text-align: center;
}
.nb-stat__val  { font-size: 26px; font-weight: 700; color: var(--neria-dark); line-height: 1; margin-bottom: 4px; }
.nb-stat__lbl  { font-size: 10px; color: #999; text-transform: uppercase; letter-spacing: .06em; }
.nb-stat--hard .nb-stat__val { color: #c0392b; }
.nb-stat--soft .nb-stat__val { color: #e67e22; }
.nb-stat--ok   .nb-stat__val { color: #1a7a40; }

/* ── Alertes / boxes ───────────────────────────────────────────── */
.nb-box {
    border-radius: 8px;
    padding: 14px 16px;
    margin: 0 0 18px;
    font-size: 12px;
    line-height: 1.65;
}
.nb-box--info  { background: #e8f4fd; border-left: 4px solid #2980b9; color: #1a5276; }
.nb-box--warn  { background: #fef9e7; border-left: 4px solid #f39c12; color: #7d6608; }
.nb-box--ok    { background: #eafaf1; border-left: 4px solid #1a7a40; color: #1a5632; }
.nb-box--setup { background: var(--neria-light-bg); border: 2px solid var(--neria-accent); border-radius: 10px; padding: 20px 22px; margin-bottom: 22px; }
.nb-box h4     { margin: 0 0 10px; font-size: 13px; font-weight: 700; color: var(--neria-dark); }

/* ── Setup steps ───────────────────────────────────────────────── */
.nb-steps { counter-reset: nb-step; margin: 0; padding: 0; list-style: none; }
.nb-step  {
    counter-increment: nb-step;
    display: grid;
    grid-template-columns: 32px 1fr;
    gap: 14px;
    margin-bottom: 18px;
    align-items: start;
}
.nb-step::before {
    content: counter(nb-step);
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--neria-accent);
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    flex-shrink: 0;
}
.nb-step__title { font-weight: 700; font-size: 13px; color: var(--neria-dark); margin: 4px 0 5px; }
.nb-step__body  { font-size: 12px; color: #555; line-height: 1.65; margin: 0; }
.nb-code {
    display: block;
    background: #2b2520;
    color: #e8c87a;
    border-radius: 6px;
    padding: 10px 14px;
    font-family: monospace;
    font-size: 12px;
    margin: 8px 0;
    word-break: break-all;
}
.nb-copy-btn {
    display: inline-block;
    background: var(--neria-accent);
    color: #fff;
    border: none;
    border-radius: 4px;
    padding: 4px 12px;
    font-size: 11px;
    cursor: pointer;
    margin-left: 8px;
    vertical-align: middle;
}

/* ── Config form ────────────────────────────────────────────────── */
.nb-section {
    background: #fff;
    border: 1px solid var(--neria-border);
    border-radius: 10px;
    padding: 20px 22px;
    margin-bottom: 18px;
}
.nb-section__title {
    font-size: 14px;
    font-weight: 700;
    color: var(--neria-dark);
    margin: 0 0 16px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--neria-border);
    display: flex;
    align-items: center;
    gap: 8px;
}
.nb-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px 20px;
}
.nb-field label {
    display: block;
    font-size: 11px;
    font-weight: 700;
    color: #666;
    text-transform: uppercase;
    letter-spacing: .04em;
    margin-bottom: 5px;
}
.nb-field input[type="text"],
.nb-field input[type="number"],
.nb-field input[type="password"],
.nb-field select {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--neria-border);
    border-radius: 6px;
    font-size: 13px;
    color: var(--neria-dark);
    background: #fafafa;
    box-sizing: border-box;
}
.nb-field--full { grid-column: 1 / -1; }
.nb-field__note { font-size: 11px; color: #999; margin-top: 4px; }
.nb-toggle { display: flex; align-items: center; gap: 10px; }
.nb-toggle input { width: auto; }
.nb-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 20px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    transition: opacity .15s;
}
.nb-btn:hover { opacity: .85; }
.nb-btn--primary { background: var(--neria-accent); color: #fff; }
.nb-btn--test    { background: #2980b9; color: #fff; }
.nb-btn--danger  { background: #c0392b; color: #fff; font-size: 11px; padding: 5px 12px; }
.nb-btn--sm      { font-size: 11px; padding: 4px 10px; }

/* ── Bounce table ───────────────────────────────────────────────── */
.nb-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    margin-top: 14px;
}
.nb-table thead th {
    background: var(--neria-light-bg);
    padding: 9px 12px;
    text-align: left;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: #888;
    border-bottom: 2px solid var(--neria-border);
}
.nb-table tbody tr:nth-child(even) { background: #fafafa; }
.nb-table td {
    padding: 9px 12px;
    vertical-align: top;
    border-bottom: 1px solid #f0ece6;
    color: #333;
}
.nb-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.nb-badge--hard    { background: #fde8e8; color: #c0392b; }
.nb-badge--soft    { background: #fef3e8; color: #e67e22; }
.nb-badge--active  { background: #fde8e8; color: #c0392b; }
.nb-badge--ignored { background: #f0f0f0; color: #999; }
.nb-badge--imap    { background: #e8f4fd; color: #2980b9; }
.nb-badge--webhook { background: #f0e8fd; color: #8e44ad; }
.nb-badge--manual  { background: #f5f5f5; color: #666; }

.nb-empty { text-align: center; padding: 40px; color: #bbb; font-size: 13px; }
.nb-actions { display: flex; gap: 6px; flex-wrap: wrap; }

/* ── Add manual ─────────────────────────────────────────────────── */
.nb-add-row {
    display: flex;
    gap: 10px;
    margin-bottom: 16px;
    align-items: center;
}
.nb-add-row input { flex: 1; padding: 8px 12px; border: 1px solid var(--neria-border); border-radius: 6px; font-size: 13px; }
.nb-add-row select { padding: 8px 12px; border: 1px solid var(--neria-border); border-radius: 6px; font-size: 13px; }

/* ── Search ─────────────────────────────────────────────────────── */
.nb-search { display: flex; gap: 10px; margin-bottom: 14px; align-items: center; }
.nb-search input { flex: 1; padding: 8px 12px; border: 1px solid var(--neria-border); border-radius: 6px; font-size: 13px; }

#nb-test-result { margin-top: 10px; padding: 10px 14px; border-radius: 6px; font-size: 12px; display: none; }
#nb-test-result.ok  { background: #eafaf1; color: #1a5632; border: 1px solid #a9dfbf; }
#nb-test-result.err { background: #fde8e8; color: #a93226; border: 1px solid #f1948a; }
</style>

<div class="neria-section">

  {* ── Hero ─────────────────────────────────────────────────────── *}
  <div class="nb-hero">
    <span class="nb-hero__icon" style="color:#ffffff;">↩</span>
    <div>
      <div class="nb-hero__title">Détection des bounces</div>
      <p class="nb-hero__sub">Neria détecte automatiquement les adresses email invalides et les exclut des envois futurs — protégeant ainsi votre réputation de domaine.</p>
    </div>
  </div>

  {* ── Statistiques ─────────────────────────────────────────────── *}
  <div class="nb-stats">
    <div class="nb-stat nb-stat--hard">
      <div class="nb-stat__val">{$bounce_stats.hard|intval}</div>
      <div class="nb-stat__lbl">Hard bounces</div>
    </div>
    <div class="nb-stat nb-stat--soft">
      <div class="nb-stat__val">{$bounce_stats.soft|intval}</div>
      <div class="nb-stat__lbl">Soft bounces</div>
    </div>
    <div class="nb-stat">
      <div class="nb-stat__val">{$bounce_stats.total|intval}</div>
      <div class="nb-stat__lbl">Total bloqués</div>
    </div>
    <div class="nb-stat nb-stat--ok">
      <div class="nb-stat__val">{$bounce_stats.ignored|intval}</div>
      <div class="nb-stat__lbl">Ignorés (FP)</div>
    </div>
    <div class="nb-stat">
      <div class="nb-stat__val">{$bounce_stats.imap|intval} / {$bounce_stats.webhook|intval}</div>
      <div class="nb-stat__lbl">IMAP / Webhook</div>
    </div>
  </div>

  {* ── Explications : mode d'emploi ─────────────────────────────── *}
  <div class="nb-box--setup">
    <h4>📖 Mode d'emploi — comment fonctionne la détection de bounces ?</h4>

    <p style="font-size:12px;color:#555;margin:0 0 18px;line-height:1.7;">
      Un <strong>bounce</strong> (rebond) se produit quand un email ne peut pas être livré.
      Il existe deux types : les <strong>hard bounces</strong> (adresse inexistante, domaine invalide — échec permanent)
      et les <strong>soft bounces</strong> (boîte pleine, serveur temporairement indisponible — échec temporaire).
      Continuer à envoyer vers ces adresses <strong>dégrade votre réputation de domaine</strong> et fait chuter votre taux d'ouverture.
    </p>

    <p style="font-size:13px;font-weight:700;color:var(--neria-dark);margin:0 0 14px;">Neria propose deux mécanismes de détection :</p>

    <ol class="nb-steps">
      <li class="nb-step">
        <div>
          <p class="nb-step__title">Mécanisme 1 — Boîte IMAP/POP3 dédiée (recommandé)</p>
          <p class="nb-step__body">
            Quand un email rebondit, le serveur destinataire renvoie un message d'erreur (NDR / DSN)
            à l'adresse <code>Return-Path</code> de votre envoi.
            Neria se connecte automatiquement chaque nuit à cette boîte, parse les messages de rejet
            et bloque les adresses détectées.
          </p>
          <div class="nb-box nb-box--warn" style="margin-top:10px;">
            <strong>⚠ Prérequis :</strong> vous devez créer une boîte email dédiée (ex : <code>bounces@votre-boutique.com</code>)
            accessible en <strong>IMAP ou POP3</strong> depuis votre hébergement. Cette boîte sert uniquement à
            recevoir les notifications de rejet — elle ne reçoit aucun email de client.
            Chez la plupart des hébergeurs (cPanel, Plesk, OVH, o2switch…), cela prend 2 minutes.
          </div>
          <p class="nb-step__body" style="margin-top:8px;">
            Configurez ensuite les identifiants IMAP ci-dessous et activez le checker.
            Un cron Neria vérifie la boîte toutes les 24 heures.
          </p>
        </div>
      </li>

      <li class="nb-step">
        <div>
          <p class="nb-step__title">Mécanisme 2 — Webhook entrant (ESP tiers : Mailgun, SendGrid, Postmark…)</p>
          <p class="nb-step__body">
            Si vous utilisez un service d'envoi tiers, il détecte les bounces en temps réel
            et peut vous envoyer une notification automatique (webhook) à chaque rebond.
            Copiez l'URL ci-dessous dans la configuration de votre ESP.
          </p>
          <p style="font-size:12px;font-weight:700;color:var(--neria-dark);margin:10px 0 4px;">URL du webhook Neria :</p>
          <code class="nb-code" id="nb-webhook-url">{$bounce_webhook_url}</code>
          <button class="nb-copy-btn" onclick="nbCopy('nb-webhook-url', this)">Copier</button>
          <p class="nb-step__body" style="margin-top:10px;">
            <strong>Sécurité :</strong> optionnellement, configurez le secret HMAC ci-dessous et renseignez-le
            dans votre ESP (champ "Signing key" ou "Webhook secret"). Neria vérifiera chaque requête via
            le header <code>X-Neria-Signature</code>.
          </p>
          <p style="font-size:12px;margin:8px 0 0;">
            <strong>ESPs supportés :</strong> Mailgun · SendGrid · Postmark · format générique (tout ESP envoyant un JSON avec "event" et "email").
          </p>
        </div>
      </li>

      <li class="nb-step">
        <div>
          <p class="nb-step__title">Comportement de blocage automatique</p>
          <p class="nb-step__body">
            • <strong>Hard bounce</strong> : l'adresse est bloquée immédiatement et définitivement — aucun email ne lui sera plus envoyé.<br>
            • <strong>Soft bounce</strong> : l'adresse est bloquée après <strong>{$bounce_soft_threshold} échecs</strong> consécutifs (configurable ci-dessous).<br>
            • Un hard bounce prend toujours le dessus sur un soft bounce précédent.<br>
            • Les <strong>faux positifs</strong> peuvent être ignorés manuellement dans la liste ci-dessous.
          </p>
        </div>
      </li>
    </ol>
  </div>

  {* ── Section 1 : Configuration IMAP ─────────────────────────────── *}
  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="save_bounce_config">
    <input type="hidden" name="neria_tab" value="bounces">

    <div class="nb-section">
      <div class="nb-section__title">⚙ Configuration IMAP — boîte Return-Path</div>

      <div class="nb-form-grid">

        <div class="nb-field nb-field--full">
          <div class="nb-toggle">
            <input type="checkbox" id="nb_enabled" name="bounce_enabled" value="1" {if $bounce_enabled}checked{/if}>
            <label for="nb_enabled" style="text-transform:none;font-size:13px;color:var(--neria-dark);">
              Activer la vérification IMAP automatique (cron quotidien)
            </label>
          </div>
        </div>

        <div class="nb-field">
          <label>Hôte IMAP</label>
          <input type="text" name="bounce_imap_host" value="{$bounce_cfg.host|escape:'html'}" placeholder="imap.votre-hebergeur.com">
          <p class="nb-field__note">Ex : imap.gmail.com · mail.ovh.com · ssl0.ovh.net</p>
        </div>

        <div class="nb-field">
          <label>Port</label>
          <input type="number" name="bounce_imap_port" value="{$bounce_cfg.port|intval}" placeholder="993" min="1" max="65535">
          <p class="nb-field__note">993 (SSL) · 143 (sans SSL) · 995 (POP3 SSL)</p>
        </div>

        <div class="nb-field">
          <label>Adresse email (login)</label>
          <input type="text" name="bounce_imap_user" value="{$bounce_cfg.user|escape:'html'}" placeholder="bounces@votre-boutique.com">
          <p class="nb-field__note">L'adresse à utiliser comme Return-Path dans vos emails</p>
        </div>

        <div class="nb-field">
          <label>Mot de passe</label>
          <input type="password" name="bounce_imap_pass" value="{$bounce_cfg.pass|escape:'html'}" placeholder="••••••••••••">
          <p class="nb-field__note">Mot de passe de la boîte email dédiée</p>
        </div>

        <div class="nb-field">
          <label>Dossier IMAP</label>
          <input type="text" name="bounce_imap_folder" value="{$bounce_cfg.folder|escape:'html'}" placeholder="INBOX">
          <p class="nb-field__note">Laissez INBOX si vous n'avez pas créé de sous-dossier</p>
        </div>

        <div class="nb-field">
          <label>Chiffrement</label>
          <select name="bounce_imap_ssl">
            <option value="1" {if $bounce_cfg.ssl}selected{/if}>SSL/TLS (recommandé)</option>
            <option value="0" {if !$bounce_cfg.ssl}selected{/if}>Sans chiffrement (STARTTLS ou texte clair)</option>
          </select>
        </div>

        <div class="nb-field nb-field--full">
          <label>Seuil de blocage — soft bounces</label>
          <input type="number" name="bounce_soft_threshold" value="{$bounce_soft_threshold|intval}" min="1" max="20" style="width:100px;">
          <p class="nb-field__note">
            Nombre d'échecs de livraison temporaires avant de bloquer l'adresse définitivement.
            Recommandé : 3. Un hard bounce bloque toujours immédiatement, quel que soit ce seuil.
          </p>
        </div>

      </div>

      <div style="margin-top:16px;display:flex;gap:10px;align-items:center;">
        <button type="submit" class="nb-btn nb-btn--primary">💾 Enregistrer la configuration</button>
        <button type="button" class="nb-btn nb-btn--test" onclick="nbTestImap(this)">🔌 Tester la connexion IMAP</button>
      </div>
      <div id="nb-test-result"></div>
    </div>

    {* ── Section 2 : Webhook ───────────────────────────────────────── *}
    <div class="nb-section">
      <div class="nb-section__title">🔗 Webhook entrant — ESP tiers</div>

      <p style="font-size:12px;color:#555;margin:0 0 16px;line-height:1.7;">
        Copiez cette URL dans les paramètres de votre ESP (onglet Webhooks ou Callbacks).
        Sélectionnez les événements de type <strong>bounce</strong>, <strong>failed</strong> ou <strong>dropped</strong>.
      </p>

      <div class="nb-field nb-field--full" style="margin-bottom:14px;">
        <label>URL du webhook</label>
        <code class="nb-code">{$bounce_webhook_url}</code>
        <button type="button" class="nb-copy-btn" onclick="nbCopy(null, this, '{$bounce_webhook_url|escape:'javascript'}')">Copier l'URL</button>
      </div>

      <div class="nb-field" style="margin-bottom:14px;">
        <label>Secret HMAC (optionnel mais recommandé)</label>
        <div style="display:flex;gap:10px;align-items:center;">
          <input type="text" id="nb-webhook-secret" name="bounce_webhook_secret"
                 value="{$bounce_webhook_secret|escape:'html'}"
                 placeholder="Cliquez sur Générer →"
                 style="font-family:monospace;font-size:12px;">
          <button type="button" class="nb-btn nb-btn--test nb-btn--sm" onclick="nbGenerateSecret()">⟳ Générer</button>
        </div>
        <p class="nb-field__note">
          Renseignez ce secret dans votre ESP (champ "Signing key" / "Webhook secret").
          Neria vérifiera chaque notification via le header <code>X-Neria-Signature</code>.
        </p>
      </div>

      <div class="nb-box nb-box--info" style="margin-bottom:0;">
        <strong>Exemples de configuration par ESP :</strong><br>
        • <strong>Mailgun</strong> : Sending → Webhooks → Add webhook → "Permanent Fail" + "Temporary Fail"<br>
        • <strong>SendGrid</strong> : Settings → Mail Settings → Event Webhook → cocher "Bounces" et "Drops"<br>
        • <strong>Postmark</strong> : Servers → [Serveur] → Webhooks → Add webhook → "Bounces"
      </div>
    </div>

  </form>

  {* ── Section 3 : Liste des bounces ─────────────────────────────── *}
  <div class="nb-section">
    <div class="nb-section__title">📋 Liste des adresses bloquées ({$bounce_count|intval})</div>

    {* Ajouter manuellement *}
    <form method="post" action="{$smarty.server.REQUEST_URI}" style="margin-bottom:18px;">
      <input type="hidden" name="neria_action" value="add_manual_bounce">
      <input type="hidden" name="neria_tab" value="bounces">
      <div class="nb-add-row">
        <input type="email" name="bounce_email" placeholder="adresse@exemple.com" required>
        <select name="bounce_type">
          <option value="hard">Hard bounce</option>
          <option value="soft">Soft bounce</option>
        </select>
        <button type="submit" class="nb-btn nb-btn--primary nb-btn--sm">+ Ajouter manuellement</button>
      </div>
    </form>

    {* Recherche *}
    <form method="get" action="{$smarty.server.REQUEST_URI}">
      <input type="hidden" name="configure" value="AdminModules">
      <input type="hidden" name="module_name" value="neria">
      <input type="hidden" name="neria_tab" value="bounces">
      <div class="nb-search">
        <input type="text" name="nb_filter" value="{$bounce_filter|escape:'html'}" placeholder="Filtrer par email ou raison…">
        <button type="submit" class="nb-btn nb-btn--primary nb-btn--sm">Filtrer</button>
        {if $bounce_filter}<a href="?configure=AdminModules&module_name=neria&neria_tab=bounces" class="nb-btn nb-btn--sm" style="background:#999;color:#fff;text-decoration:none;">× Effacer</a>{/if}
      </div>
    </form>

    {if $bounce_list}
      <table class="nb-table">
        <thead>
          <tr>
            <th>Email</th>
            <th>Type</th>
            <th>Source</th>
            <th>Raison</th>
            <th>Échecs</th>
            <th>Dernier bounce</th>
            <th>Statut</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          {foreach from=$bounce_list item=b}
          <tr>
            <td><strong>{$b.email|escape:'html'}</strong></td>
            <td><span class="nb-badge nb-badge--{$b.type}">{$b.type}</span></td>
            <td><span class="nb-badge nb-badge--{$b.source}">{$b.source}</span></td>
            <td style="max-width:280px;color:#777;font-size:11px;">{$b.reason|escape:'html'|truncate:80:'…'}</td>
            <td style="text-align:center;">{$b.bounce_count|intval}</td>
            <td style="white-space:nowrap;">{$b.last_bounce_at|escape:'html'|date_format:'%d/%m/%Y'}</td>
            <td><span class="nb-badge nb-badge--{$b.status}">{$b.status}</span></td>
            <td>
              <div class="nb-actions">
                <form method="post" action="{$smarty.server.REQUEST_URI}" style="display:inline;">
                  <input type="hidden" name="neria_action" value="{if $b.status === 'active'}ignore_bounce{else}reactivate_bounce{/if}">
                  <input type="hidden" name="neria_tab" value="bounces">
                  <input type="hidden" name="bounce_email" value="{$b.email|escape:'html'}">
                  <button type="submit" class="nb-btn nb-btn--sm" style="background:{if $b.status === 'active'}#e67e22{else}#2980b9{/if};color:#fff;">
                    {if $b.status === 'active'}Ignorer{else}Réactiver{/if}
                  </button>
                </form>
                <form method="post" action="{$smarty.server.REQUEST_URI}" style="display:inline;"
                      onsubmit="return confirm('Supprimer {$b.email|escape:'javascript'} de la liste des bounces ?')">
                  <input type="hidden" name="neria_action" value="delete_bounce">
                  <input type="hidden" name="neria_tab" value="bounces">
                  <input type="hidden" name="bounce_email" value="{$b.email|escape:'html'}">
                  <button type="submit" class="nb-btn nb-btn--danger nb-btn--sm">✕</button>
                </form>
              </div>
            </td>
          </tr>
          {/foreach}
        </tbody>
      </table>

      {* Pagination *}
      {if $bounce_total_pages > 1}
      <div style="margin-top:14px;display:flex;gap:6px;align-items:center;font-size:12px;">
        {for $p=1 to $bounce_total_pages}
          <a href="?configure=AdminModules&module_name=neria&neria_tab=bounces&nb_page={$p}&nb_filter={$bounce_filter|urlencode}"
             style="padding:4px 10px;border-radius:4px;text-decoration:none;
                    background:{if $p == $bounce_page}var(--neria-accent){else}#eee{/if};
                    color:{if $p == $bounce_page}#fff{else}#333{/if};">{$p}</a>
        {/for}
      </div>
      {/if}

    {else}
      <div class="nb-empty">
        Aucune adresse bloquée pour l'instant.<br>
        <span style="font-size:11px;">Les bounces détectés via IMAP ou webhook apparaîtront ici automatiquement.</span>
      </div>
    {/if}

  </div>

  {* ── Bouton lancer le check manuellement ─────────────────────────── *}
  <form method="post" action="{$smarty.server.REQUEST_URI}" style="margin-top:4px;">
    <input type="hidden" name="neria_action" value="run_bounce_check">
    <input type="hidden" name="neria_tab" value="bounces">
    <button type="submit" class="nb-btn nb-btn--test">↩ Lancer la vérification IMAP maintenant</button>
    <span style="font-size:11px;color:#999;margin-left:10px;">Le cron tourne automatiquement chaque nuit. Ce bouton permet un lancement manuel immédiat.</span>
  </form>

  {if isset($bounce_run_result)}
  <div class="nb-box {if $bounce_run_result.errors}nb-box--warn{else}nb-box--ok{/if}" style="margin-top:14px;">
    Résultat : <strong>{$bounce_run_result.processed}</strong> messages traités ·
    <strong>{$bounce_run_result.bounces}</strong> bounces enregistrés.
    {if $bounce_run_result.errors}
      <br>Erreurs : {implode(', ', $bounce_run_result.errors)|escape:'html'}
    {/if}
  </div>
  {/if}

</div>

<script>
function nbCopy(id, btn, directVal) {
    var text = directVal || document.getElementById(id).textContent.trim();
    navigator.clipboard.writeText(text).then(function() {
        var orig = btn.textContent;
        btn.textContent = '✓ Copié !';
        setTimeout(function() { btn.textContent = orig; }, 1800);
    });
}

function nbTestImap(btn) {
    btn.disabled = true;
    btn.textContent = '⏳ Test en cours…';
    var result = document.getElementById('nb-test-result');
    result.style.display = 'none';

    var form = btn.closest('form');
    var data = new FormData(form);
    data.set('neria_action', 'test_imap_connection');

    fetch(form.action, { method: 'POST', body: data })
        .then(function(r) { return r.json(); })
        .then(function(json) {
            result.className = json.ok ? 'ok' : 'err';
            result.textContent = json.message;
            result.style.display = 'block';
        })
        .catch(function() {
            result.className = 'err';
            result.textContent = 'Erreur de communication avec le serveur.';
            result.style.display = 'block';
        })
        .finally(function() {
            btn.disabled = false;
            btn.textContent = '🔌 Tester la connexion IMAP';
        });
}

function nbGenerateSecret() {
    var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    var secret = '';
    for (var i = 0; i < 48; i++) {
        secret += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById('nb-webhook-secret').value = secret;
}
</script>
