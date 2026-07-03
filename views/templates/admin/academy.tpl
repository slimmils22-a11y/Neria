{**
 * NERIA — academy.tpl
 * Onglet Académie : 8 guides pratiques (ouverture, objet, RGPD, délivrabilité,
 * segmentation, fidélité/upsell, A/B testing, panier abandonné) — multilingue via {$ac}
 *}

{assign var="na_base" value=$smarty.server.REQUEST_URI|regex_replace:'/&neria_tab=[^&]*/':''}

<style>
/* ── Neria Academy (na- prefix) ──────────────────────────────── */
.na-hero {
    background: linear-gradient(135deg, #3e2d1c 0%, #6b4a28 100%);
    border-radius: 10px;
    padding: 30px 36px;
    margin-bottom: 26px;
    display: flex;
    align-items: center;
    gap: 22px;
}
.na-hero__badge { font-size: 38px; flex-shrink: 0; line-height: 1; }
.na-hero__title {
    font-size: 20px;
    font-weight: 700;
    color: var(--neria-accent);
    margin: 0 0 5px;
    letter-spacing: .04em;
}
.na-hero__sub {
    font-size: 12px;
    color: rgba(255,255,255,.7);
    margin: 0;
    line-height: 1.65;
}

/* ── Progress ────────────────────────────────────────────────── */
.na-progress {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 18px;
    font-size: 12px;
    color: #888;
}
.na-progress__bar {
    flex: 1;
    height: 4px;
    background: var(--neria-border);
    border-radius: 2px;
    overflow: hidden;
}
.na-progress__fill {
    height: 100%;
    background: var(--neria-accent);
    border-radius: 2px;
    transition: width .4s;
    width: 0%;
}

/* ── Guide cards ─────────────────────────────────────────────── */
.na-guides {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 26px;
}
@media (max-width: 1100px) {
    .na-guides { grid-template-columns: repeat(2, 1fr); }
}
.na-guide-card {
    background: #fff;
    border: 2px solid var(--neria-border);
    border-radius: 10px;
    padding: 18px 18px 14px;
    cursor: pointer;
    transition: border-color .15s, box-shadow .15s, background .15s;
    position: relative;
    user-select: none;
}
.na-guide-card:hover { border-color: var(--neria-accent); box-shadow: 0 4px 14px rgba(179,139,89,.12); }
.na-guide-card.na--active {
    border-color: var(--neria-accent);
    background: #fdf8f2;
    box-shadow: 0 4px 18px rgba(179,139,89,.2);
}
.na-guide-card__done {
    position: absolute;
    top: 11px;
    right: 13px;
    font-size: 14px;
    color: #1a7a40;
    display: none;
}
.na-guide-card.na--read .na-guide-card__done { display: block; }
.na-guide-card__icon {
    font-size: 26px;
    display: block;
    margin-bottom: 10px;
    line-height: 1;
}
.na-guide-card__title {
    font-size: 13px;
    font-weight: 700;
    color: var(--neria-dark);
    margin: 0 0 5px;
    line-height: 1.35;
    padding-right: 20px;
}
.na-guide-card__meta { font-size: 11px; color: #aaa; margin: 0; }

/* ── Panel ───────────────────────────────────────────────────── */
.na-panel { display: none; }
.na-panel.na--active { display: block; }

.na-guide-header {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 20px;
    padding-bottom: 18px;
    border-bottom: 2px solid var(--neria-border);
}
.na-guide-header__icon { font-size: 32px; line-height: 1; flex-shrink: 0; }
.na-guide-header__title { font-size: 19px; font-weight: 700; color: var(--neria-dark); margin: 0 0 4px; }
.na-guide-header__intro { font-size: 12px; color: #666; margin: 0; line-height: 1.7; max-width: 640px; }

/* ── Section headers ─────────────────────────────────────────── */
.na-h2 {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
    font-weight: 700;
    color: var(--neria-dark);
    margin: 26px 0 14px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--neria-border);
}
.na-h2__num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: var(--neria-accent);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    flex-shrink: 0;
}

/* ── Cause cards ─────────────────────────────────────────────── */
.na-cause {
    background: #fff;
    border: 1px solid var(--neria-border);
    border-left: 4px solid #ccc;
    border-radius: 0 8px 8px 0;
    padding: 14px 16px;
    margin-bottom: 10px;
}
.na-cause--red   { border-left-color: #c0392b; }
.na-cause--orange{ border-left-color: #e67e22; }
.na-cause--blue  { border-left-color: #2980b9; }
.na-cause--green { border-left-color: #1a7a40; }
.na-cause__title { font-weight: 700; font-size: 13px; color: var(--neria-dark); margin: 0 0 5px; }
.na-cause__body  { font-size: 12px; color: #555; line-height: 1.65; margin: 0; }
.na-cause__fix   { margin-top: 8px; font-size: 12px; color: #333; }

.na-tab-link {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    color: var(--neria-accent);
    text-decoration: none;
    border: 1px solid var(--neria-accent);
    border-radius: 20px;
    padding: 2px 10px;
    margin-top: 6px;
    font-weight: 600;
    transition: background .12s, color .12s;
}
.na-tab-link:hover { background: var(--neria-accent); color: #fff; }

/* ── Rules ───────────────────────────────────────────────────── */
.na-rules { margin: 0; padding: 0; }
.na-rule {
    display: grid;
    grid-template-columns: 38px 1fr;
    gap: 14px;
    align-items: start;
    padding: 14px 0;
    border-bottom: 1px solid #f0ece6;
}
.na-rule:last-child { border-bottom: none; }
.na-rule__num {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: var(--neria-light-bg);
    border: 2px solid var(--neria-accent);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 700;
    color: var(--neria-accent);
    flex-shrink: 0;
}
.na-rule__title { font-weight: 700; font-size: 13px; color: var(--neria-dark); margin: 0 0 5px; }
.na-rule__body  { font-size: 12px; color: #555; line-height: 1.65; margin: 0; }

/* ── Examples table ──────────────────────────────────────────── */
.na-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    margin: 14px 0;
}
.na-table thead th {
    background: var(--neria-light-bg);
    padding: 8px 12px;
    text-align: left;
    font-weight: 700;
    font-size: 11px;
    color: #888;
    text-transform: uppercase;
    letter-spacing: .05em;
    border-bottom: 2px solid var(--neria-border);
}
.na-table tbody tr:nth-child(even) { background: #fafafa; }
.na-table td {
    padding: 10px 12px;
    vertical-align: top;
    border-bottom: 1px solid #f0ece6;
    color: #333;
    line-height: 1.55;
}
.na-bad  { color: #c0392b; font-style: italic; }
.na-good { color: #1a7a40; font-weight: 600; }

/* ── Spam tags ───────────────────────────────────────────────── */
.na-spam-wrap { margin: 10px 0; }
.na-spam-tag {
    display: inline-block;
    background: #ffeee8;
    border: 1px solid #f5c6bb;
    color: #c0392b;
    font-size: 11px;
    border-radius: 4px;
    padding: 2px 8px;
    margin: 3px 3px 3px 0;
    font-family: monospace;
}

/* ── Info boxes ──────────────────────────────────────────────── */
.na-box {
    border-radius: 8px;
    padding: 12px 15px;
    margin: 14px 0;
    font-size: 12px;
    line-height: 1.65;
    display: flex;
    gap: 10px;
    align-items: flex-start;
}
.na-box__ico { font-size: 15px; flex-shrink: 0; margin-top: 1px; }
.na-box--tip  { background: #e8f4fd; border-left: 4px solid #2980b9; color: #1a5276; }
.na-box--warn { background: #fef9e7; border-left: 4px solid #f39c12; color: #7d6608; }
.na-box--ok   { background: #eafaf1; border-left: 4px solid #1a7a40; color: #1a5632; }

/* ── Legal grid ──────────────────────────────────────────────── */
.na-legal-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin: 14px 0;
}
.na-legal-card {
    background: #fff;
    border: 1px solid var(--neria-border);
    border-radius: 8px;
    padding: 14px 16px;
}
.na-legal-card__badge {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .07em;
    padding: 3px 9px;
    border-radius: 20px;
    margin-bottom: 10px;
    display: inline-block;
}
.na-badge--green  { background: #eafaf1; color: #1a7a40; }
.na-badge--blue   { background: #e8f4fd; color: #1a5276; }
.na-badge--orange { background: #fef9e7; color: #9a6800; }
.na-legal-card__title { font-weight: 700; font-size: 13px; color: var(--neria-dark); margin: 0 0 8px; }
.na-legal-card__list  { list-style: none; margin: 0; padding: 0; font-size: 12px; color: #555; line-height: 1.6; }
.na-legal-card__list li { padding: 2px 0; }

/* ── Checklist ───────────────────────────────────────────────── */
.na-checklist { list-style: none; margin: 0; padding: 0; }
.na-checklist__item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 0;
    border-bottom: 1px solid #f5f2ee;
}
.na-checklist__item:last-child { border-bottom: none; }
.na-check {
    width: 18px;
    height: 18px;
    min-width: 18px;
    border: 2px solid #ccc;
    border-radius: 4px;
    cursor: pointer;
    position: relative;
    margin-top: 2px;
    transition: border-color .15s, background .15s;
}
.na-check.na--checked { background: #1a7a40; border-color: #1a7a40; }
.na-check.na--checked::after {
    content: '✓';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    line-height: 1;
}
.na-check-label { font-size: 13px; color: #333; line-height: 1.5; }
.na-check-note  { font-size: 11px; color: #999; margin-top: 3px; }

/* ── Obligation blocks ───────────────────────────────────────── */
.na-obligation {
    background: #fff;
    border: 1px solid var(--neria-border);
    border-radius: 8px;
    padding: 16px 18px;
    margin-bottom: 12px;
}
.na-obligation__head {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
}
.na-obligation__icon { font-size: 18px; }
.na-obligation__title { font-weight: 700; font-size: 13px; color: var(--neria-dark); margin: 0; }
.na-obligation__body { font-size: 12px; color: #555; line-height: 1.7; margin: 0; }
.na-obligation__list { list-style: none; margin: 8px 0 0; padding: 0; font-size: 12px; color: #333; }
.na-obligation__list li { padding: 3px 0 3px 16px; position: relative; }
.na-obligation__list li::before { content: '→'; position: absolute; left: 0; color: var(--neria-accent); }

@media (max-width: 860px) {
    .na-guides       { grid-template-columns: 1fr; }
    .na-legal-grid   { grid-template-columns: 1fr; }
}
</style>

<div class="neria-section">

  {* ── Hero ─────────────────────────────────────────────────────── *}
  <div class="na-hero">
    <div class="na-hero__badge" style="color:#ffffff;">✦</div>
    <div>
      <div class="na-hero__title">{$ac.hero_title}</div>
      <p class="na-hero__sub">{$ac.hero_sub|nl2br}</p>
    </div>
  </div>

  {* ── Progress bar ──────────────────────────────────────────────── *}
  <div class="na-progress">
    <span id="na-progress-label">0 / 6</span>
    <div class="na-progress__bar"><div class="na-progress__fill" id="na-progress-fill"></div></div>
  </div>

  {* ── Guide selector cards ──────────────────────────────────────── *}
  <div class="na-guides">

    <div class="na-guide-card" id="na-card-openrate" onclick="naShow('openrate')">
      <span class="na-guide-card__done">✓</span>
      <span class="na-guide-card__icon">↘</span>
      <p class="na-guide-card__title">{$ac.card1_title}</p>
      <p class="na-guide-card__meta">{$ac.card1_meta}</p>
    </div>

    <div class="na-guide-card" id="na-card-subject" onclick="naShow('subject')">
      <span class="na-guide-card__done">✓</span>
      <span class="na-guide-card__icon">✎</span>
      <p class="na-guide-card__title">{$ac.card2_title}</p>
      <p class="na-guide-card__meta">{$ac.card2_meta}</p>
    </div>

    <div class="na-guide-card" id="na-card-gdpr" onclick="naShow('gdpr')">
      <span class="na-guide-card__done">✓</span>
      <span class="na-guide-card__icon">⚖</span>
      <p class="na-guide-card__title">{$ac.card3_title}</p>
      <p class="na-guide-card__meta">{$ac.card3_meta}</p>
    </div>

    <div class="na-guide-card" id="na-card-deliverability" onclick="naShow('deliverability')">
      <span class="na-guide-card__done">✓</span>
      <span class="na-guide-card__icon">🛡</span>
      <p class="na-guide-card__title">{$ac.card4_title}</p>
      <p class="na-guide-card__meta">{$ac.card4_meta}</p>
    </div>

    <div class="na-guide-card" id="na-card-segmentation" onclick="naShow('segmentation')">
      <span class="na-guide-card__done">✓</span>
      <span class="na-guide-card__icon">🎯</span>
      <p class="na-guide-card__title">{$ac.card5_title}</p>
      <p class="na-guide-card__meta">{$ac.card5_meta}</p>
    </div>

    <div class="na-guide-card" id="na-card-loyalty" onclick="naShow('loyalty')">
      <span class="na-guide-card__done">✓</span>
      <span class="na-guide-card__icon">💎</span>
      <p class="na-guide-card__title">{$ac.card6_title}</p>
      <p class="na-guide-card__meta">{$ac.card6_meta}</p>
    </div>

    <div class="na-guide-card" id="na-card-abtest" onclick="naShow('abtest')">
      <span class="na-guide-card__done">✓</span>
      <span class="na-guide-card__icon">🔬</span>
      <p class="na-guide-card__title">{$ac.card7_title}</p>
      <p class="na-guide-card__meta">{$ac.card7_meta}</p>
    </div>

    <div class="na-guide-card" id="na-card-cart" onclick="naShow('cart')">
      <span class="na-guide-card__done">✓</span>
      <span class="na-guide-card__icon">🛒</span>
      <p class="na-guide-card__title">{$ac.card8_title}</p>
      <p class="na-guide-card__meta">{$ac.card8_meta}</p>
    </div>

  </div>

  {* ═══════════════════════════════════════════════════════════════ *}
  {* Guide 1 — Taux d'ouverture                                      *}
  {* ═══════════════════════════════════════════════════════════════ *}
  <div class="na-panel" id="na-panel-openrate">

    <div class="na-guide-header">
      <span class="na-guide-header__icon">↘</span>
      <div>
        <h2 class="na-guide-header__title">{$ac.g1_title}</h2>
        <p class="na-guide-header__intro">{$ac.g1_intro}</p>
      </div>
    </div>

    <div class="na-box na-box--tip">
      <span class="na-box__ico">ℹ</span>
      <span>{$ac.g1_tip}</span>
    </div>

    <div class="na-h2"><span class="na-h2__num">1</span> {$ac.g1_h1}</div>
    <div class="na-cause na-cause--red">
      <p class="na-cause__title">{$ac.g1_c1_t}</p>
      <p class="na-cause__body">{$ac.g1_c1_b}</p>
      <div class="na-cause__fix">
        <strong>{$ac.lbl_solution} :</strong> {$ac.g1_c1_f}
        <br><a href="{$na_base}&neria_tab=stats" class="na-tab-link">{$ac.tab_stats_rep}</a>
      </div>
    </div>

    <div class="na-h2"><span class="na-h2__num">2</span> {$ac.g1_h2}</div>
    <div class="na-cause na-cause--orange">
      <p class="na-cause__title">{$ac.g1_c2_t}</p>
      <p class="na-cause__body">{$ac.g1_c2_b}</p>
      <div class="na-cause__fix">
        <strong>{$ac.lbl_solution} :</strong> {$ac.g1_c2_f}
        <br><a href="{$na_base}&neria_tab=stats" class="na-tab-link">{$ac.tab_stats_del}</a>
      </div>
    </div>

    <div class="na-h2"><span class="na-h2__num">3</span> {$ac.g1_h3}</div>
    <div class="na-cause na-cause--blue">
      <p class="na-cause__title">{$ac.g1_c3_t}</p>
      <p class="na-cause__body">{$ac.g1_c3_b}</p>
      <div class="na-cause__fix">
        <strong>{$ac.lbl_solution} :</strong> {$ac.g1_c3_f}
        <br><a href="{$na_base}&neria_tab=stats" class="na-tab-link">{$ac.tab_stats_lang}</a>
      </div>
    </div>

    <div class="na-h2"><span class="na-h2__num">4</span> {$ac.g1_h4}</div>
    <div class="na-cause na-cause--orange">
      <p class="na-cause__title">{$ac.g1_c4_t}</p>
      <p class="na-cause__body">{$ac.g1_c4_b}</p>
      <div class="na-cause__fix">
        <strong>{$ac.lbl_solution} :</strong> {$ac.g1_c4_f}
        <br><a href="{$na_base}&neria_tab=configure" class="na-tab-link">{$ac.tab_cfg}</a>
      </div>
    </div>

    <div class="na-h2"><span class="na-h2__num">5</span> {$ac.g1_h5}</div>
    <div class="na-cause na-cause--blue">
      <p class="na-cause__title">{$ac.g1_c5_t}</p>
      <p class="na-cause__body">{$ac.g1_c5_b}</p>
      <div class="na-cause__fix">
        <strong>{$ac.lbl_solution} :</strong> {$ac.g1_c5_f}
        <br><a href="{$na_base}&neria_tab=translations" class="na-tab-link">{$ac.tab_trad}</a>
      </div>
    </div>

    <div class="na-h2"><span class="na-h2__num">6</span> {$ac.g1_h6}</div>
    <div class="na-cause na-cause--orange">
      <p class="na-cause__title">{$ac.g1_c6_t}</p>
      <p class="na-cause__body">{$ac.g1_c6_b}</p>
      <div class="na-cause__fix">
        <strong>{$ac.lbl_solution} :</strong> {$ac.g1_c6_f}
        <br><a href="{$na_base}&neria_tab=segments" class="na-tab-link">{$ac.tab_segs_gh}</a>
      </div>
    </div>

    <div class="na-h2"><span class="na-h2__num">7</span> {$ac.g1_h7}</div>
    <div class="na-cause na-cause--blue">
      <p class="na-cause__title">{$ac.g1_c7_t}</p>
      <p class="na-cause__body">{$ac.g1_c7_b}</p>
      <div class="na-cause__fix">
        <strong>{$ac.lbl_solution} :</strong> {$ac.g1_c7_f}
        <br><a href="{$na_base}&neria_tab=segments" class="na-tab-link">{$ac.tab_segs_camp}</a>
      </div>
    </div>

    <div class="na-h2"><span class="na-h2__num">8</span> {$ac.g1_h8}</div>
    <div class="na-cause na-cause--red">
      <p class="na-cause__title">{$ac.g1_c8_t}</p>
      <p class="na-cause__body">{$ac.g1_c8_b}</p>
      <div class="na-cause__fix">
        <strong>{$ac.lbl_solution} :</strong> {$ac.g1_c8_f}
        <br><a href="{$na_base}&neria_tab=stats" class="na-tab-link">{$ac.tab_stats_rep}</a>
      </div>
    </div>

    <div class="na-box na-box--ok" style="margin-top:22px;">
      <span class="na-box__ico">✓</span>
      <span>{$ac.g1_final}</span>
    </div>

  </div>{* /panel openrate *}


  {* ═══════════════════════════════════════════════════════════════ *}
  {* Guide 2 — Objet email                                           *}
  {* ═══════════════════════════════════════════════════════════════ *}
  <div class="na-panel" id="na-panel-subject">

    <div class="na-guide-header">
      <span class="na-guide-header__icon">✎</span>
      <div>
        <h2 class="na-guide-header__title">{$ac.g2_title}</h2>
        <p class="na-guide-header__intro">{$ac.g2_intro}</p>
      </div>
    </div>

    <div class="na-box na-box--tip">
      <span class="na-box__ico">ℹ</span>
      <span>
        {$ac.g2_tip}
        <a href="{$na_base}&neria_tab=stats" class="na-tab-link" style="display:inline;">{$ac.tab_stats_del}</a>
      </span>
    </div>

    <div class="na-h2"><span class="na-h2__num" style="background:#2c2c2c;"></span> {$ac.g2_rules_h}</div>

    <div class="na-rules">
      <div class="na-rule">
        <div class="na-rule__num">1</div>
        <div>
          <p class="na-rule__title">{$ac.g2_r1_t}</p>
          <p class="na-rule__body">{$ac.g2_r1_b}</p>
        </div>
      </div>
      <div class="na-rule">
        <div class="na-rule__num">2</div>
        <div>
          <p class="na-rule__title">{$ac.g2_r2_t}</p>
          <p class="na-rule__body">{$ac.g2_r2_b}</p>
        </div>
      </div>
      <div class="na-rule">
        <div class="na-rule__num">3</div>
        <div>
          <p class="na-rule__title">{$ac.g2_r3_t}</p>
          <p class="na-rule__body">{$ac.g2_r3_b}</p>
        </div>
      </div>
      <div class="na-rule">
        <div class="na-rule__num">4</div>
        <div>
          <p class="na-rule__title">{$ac.g2_r4_t}</p>
          <p class="na-rule__body">{$ac.g2_r4_b}</p>
        </div>
      </div>
      <div class="na-rule">
        <div class="na-rule__num">5</div>
        <div>
          <p class="na-rule__title">{$ac.g2_r5_t}</p>
          <p class="na-rule__body">{$ac.g2_r5_b}</p>
          <a href="{$na_base}&neria_tab=abtest" class="na-tab-link">{$ac.tab_abtest}</a>
        </div>
      </div>
    </div>{* /rules *}

    <div class="na-h2"><span class="na-h2__num" style="background:#2c2c2c;"></span> {$ac.g2_ex_h}</div>

    <table class="na-table">
      <thead>
        <tr>
          <th>{$ac.g2_col1}</th>
          <th>{$ac.g2_col2}</th>
          <th>{$ac.g2_col3}</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td class="na-bad">"{$ac.g2_ex1_b}"</td>
          <td class="na-good">"{$ac.g2_ex1_a}"</td>
          <td>{$ac.g2_ex1_r}</td>
        </tr>
        <tr>
          <td class="na-bad">"{$ac.g2_ex2_b}"</td>
          <td class="na-good">"{$ac.g2_ex2_a}"</td>
          <td>{$ac.g2_ex2_r}</td>
        </tr>
        <tr>
          <td class="na-bad">"{$ac.g2_ex3_b}"</td>
          <td class="na-good">"{$ac.g2_ex3_a}"</td>
          <td>{$ac.g2_ex3_r}</td>
        </tr>
        <tr>
          <td class="na-bad">"{$ac.g2_ex4_b}"</td>
          <td class="na-good">"{$ac.g2_ex4_a}"</td>
          <td>{$ac.g2_ex4_r}</td>
        </tr>
        <tr>
          <td class="na-bad">"{$ac.g2_ex5_b}"</td>
          <td class="na-good">"{$ac.g2_ex5_a}"</td>
          <td>{$ac.g2_ex5_r}</td>
        </tr>
        <tr>
          <td class="na-bad">"{$ac.g2_ex6_b}"</td>
          <td class="na-good">"{$ac.g2_ex6_a}"</td>
          <td>{$ac.g2_ex6_r}</td>
        </tr>
      </tbody>
    </table>

    <div class="na-h2"><span class="na-h2__num" style="background:#2c2c2c;"></span> {$ac.g2_spam_h}</div>

    <p style="font-size:12px;color:#555;margin:0 0 10px;">{$ac.g2_spam_intro}</p>

    <div class="na-spam-wrap">
      {foreach from=$ac.g2_spam_words item=word}
        <span class="na-spam-tag">{$word}</span>
      {/foreach}
    </div>

    <div class="na-box na-box--warn" style="margin-top:16px;">
      <span class="na-box__ico">⚠</span>
      <span>{$ac.g2_warn}</span>
    </div>

    <div class="na-box na-box--tip" style="margin-top:10px;">
      <span class="na-box__ico">ℹ</span>
      <span>{$ac.g2_emoji}</span>
    </div>

  </div>{* /panel subject *}


  {* ═══════════════════════════════════════════════════════════════ *}
  {* Guide 3 — RGPD / Protection des données                         *}
  {* ═══════════════════════════════════════════════════════════════ *}
  <div class="na-panel" id="na-panel-gdpr">

    <div class="na-guide-header">
      <span class="na-guide-header__icon">⚖</span>
      <div>
        <h2 class="na-guide-header__title">{$ac.g3_title}</h2>
        <p class="na-guide-header__intro">{$ac.g3_intro}</p>
      </div>
    </div>

    <div class="na-box na-box--warn">
      <span class="na-box__ico">⚠</span>
      <span>{$ac.g3_warn}</span>
    </div>

    <div class="na-h2"><span class="na-h2__num" style="background:#2c2c2c;"></span> {$ac.g3_legal_h}</div>

    <div class="na-legal-grid">

      <div class="na-legal-card">
        <span class="na-legal-card__badge na-badge--green">{$ac.g3_lc1_badge}</span>
        <p class="na-legal-card__title">{$ac.g3_lc1_title}</p>
        <ul class="na-legal-card__list">
          {foreach from=$ac.g3_lc1_items item=item}<li>{$item}</li>{/foreach}
        </ul>
      </div>

      <div class="na-legal-card">
        <span class="na-legal-card__badge na-badge--blue">{$ac.g3_lc2_badge}</span>
        <p class="na-legal-card__title">{$ac.g3_lc2_title}</p>
        <ul class="na-legal-card__list">
          {foreach from=$ac.g3_lc2_items item=item}<li>{$item}</li>{/foreach}
        </ul>
      </div>

      <div class="na-legal-card">
        <span class="na-legal-card__badge na-badge--orange">{$ac.g3_lc3_badge}</span>
        <p class="na-legal-card__title">{$ac.g3_lc3_title}</p>
        <ul class="na-legal-card__list">
          {foreach from=$ac.g3_lc3_items item=item}<li>{$item}</li>{/foreach}
        </ul>
      </div>

    </div>

    <div class="na-h2"><span class="na-h2__num" style="background:#2c2c2c;"></span> {$ac.g3_oblig_h}</div>

    <div class="na-obligation">
      <div class="na-obligation__head">
        <span class="na-obligation__icon">📧</span>
        <h3 class="na-obligation__title">{$ac.g3_o1_title}</h3>
      </div>
      <p class="na-obligation__body">{$ac.g3_o1_body}</p>
      <ul class="na-obligation__list">
        {foreach from=$ac.g3_o1_items item=item}<li>{$item}</li>{/foreach}
      </ul>
      <a href="{$na_base}&neria_tab=gdpr" class="na-tab-link" style="margin-top:10px;display:inline-flex;">{$ac.tab_gdpr}</a>
    </div>

    <div class="na-obligation">
      <div class="na-obligation__head">
        <span class="na-obligation__icon">🕐</span>
        <h3 class="na-obligation__title">{$ac.g3_o2_title}</h3>
      </div>
      <p class="na-obligation__body">{$ac.g3_o2_body}</p>
      <ul class="na-obligation__list">
        {foreach from=$ac.g3_o2_items item=item}<li>{$item}</li>{/foreach}
      </ul>
    </div>

    <div class="na-obligation">
      <div class="na-obligation__head">
        <span class="na-obligation__icon">🔒</span>
        <h3 class="na-obligation__title">{$ac.g3_o3_title}</h3>
      </div>
      <p class="na-obligation__body">{$ac.g3_o3_body}</p>
      <ul class="na-obligation__list">
        {foreach from=$ac.g3_o3_items item=item}<li>{$item}</li>{/foreach}
      </ul>
      <a href="{$na_base}&neria_tab=gdpr" class="na-tab-link" style="margin-top:10px;display:inline-flex;">{$ac.tab_gdpr_purge}</a>
    </div>

    <div class="na-obligation">
      <div class="na-obligation__head">
        <span class="na-obligation__icon">📅</span>
        <h3 class="na-obligation__title">{$ac.g3_o4_title}</h3>
      </div>
      <p class="na-obligation__body">{$ac.g3_o4_body}</p>
      <ul class="na-obligation__list">
        {foreach from=$ac.g3_o4_items item=item}<li>{$item}</li>{/foreach}
      </ul>
      <a href="{$na_base}&neria_tab=segments" class="na-tab-link" style="margin-top:10px;display:inline-flex;">{$ac.tab_segs2}</a>
    </div>

    <div class="na-h2"><span class="na-h2__num" style="background:#2c2c2c;"></span> {$ac.g3_cl_h}</div>

    <p style="font-size:12px;color:#888;margin:0 0 14px;">{$ac.g3_cl_note}</p>

    <ul class="na-checklist" id="na-gdpr-checklist">
      <li class="na-checklist__item">
        <div class="na-check" data-key="gdpr_1" onclick="naToggleCheck(this)"></div>
        <div>
          <p class="na-check-label">{$ac.g3_ch1_l}</p>
          <p class="na-check-note">{$ac.g3_ch1_n}</p>
        </div>
      </li>
      <li class="na-checklist__item">
        <div class="na-check" data-key="gdpr_2" onclick="naToggleCheck(this)"></div>
        <div>
          <p class="na-check-label">{$ac.g3_ch2_l}</p>
          <p class="na-check-note">{$ac.g3_ch2_n}</p>
        </div>
      </li>
      <li class="na-checklist__item">
        <div class="na-check" data-key="gdpr_3" onclick="naToggleCheck(this)"></div>
        <div>
          <p class="na-check-label">{$ac.g3_ch3_l}</p>
          <p class="na-check-note">{$ac.g3_ch3_n}</p>
        </div>
      </li>
      <li class="na-checklist__item">
        <div class="na-check" data-key="gdpr_4" onclick="naToggleCheck(this)"></div>
        <div>
          <p class="na-check-label">{$ac.g3_ch4_l}</p>
          <p class="na-check-note">{$ac.g3_ch4_n}</p>
        </div>
      </li>
      <li class="na-checklist__item">
        <div class="na-check" data-key="gdpr_5" onclick="naToggleCheck(this)"></div>
        <div>
          <p class="na-check-label">{$ac.g3_ch5_l}</p>
          <p class="na-check-note">{$ac.g3_ch5_n}</p>
        </div>
      </li>
      <li class="na-checklist__item">
        <div class="na-check" data-key="gdpr_6" onclick="naToggleCheck(this)"></div>
        <div>
          <p class="na-check-label">{$ac.g3_ch6_l}</p>
          <p class="na-check-note">{$ac.g3_ch6_n}</p>
        </div>
      </li>
      <li class="na-checklist__item">
        <div class="na-check" data-key="gdpr_7" onclick="naToggleCheck(this)"></div>
        <div>
          <p class="na-check-label">{$ac.g3_ch7_l}</p>
          <p class="na-check-note">{$ac.g3_ch7_n}</p>
        </div>
      </li>
      <li class="na-checklist__item">
        <div class="na-check" data-key="gdpr_8" onclick="naToggleCheck(this)"></div>
        <div>
          <p class="na-check-label">{$ac.g3_ch8_l}</p>
          <p class="na-check-note">{$ac.g3_ch8_n}</p>
        </div>
      </li>
    </ul>

    <div class="na-box na-box--ok" style="margin-top:22px;">
      <span class="na-box__ico">✦</span>
      <span>{$ac.g3_final}</span>
    </div>

  </div>{* /panel gdpr *}


  {* ═══════════════════════════════════════════════════════════════ *}
  {* Guide 4 — Délivrabilité / spam                                  *}
  {* ═══════════════════════════════════════════════════════════════ *}
  <div class="na-panel" id="na-panel-deliverability">

    <div class="na-guide-header">
      <span class="na-guide-header__icon">🛡</span>
      <div>
        <h2 class="na-guide-header__title">{$ac.g4_title}</h2>
        <p class="na-guide-header__intro">{$ac.g4_intro}</p>
      </div>
    </div>

    <div class="na-box na-box--tip">
      <span class="na-box__ico">ℹ</span>
      <span>{$ac.g4_tip}</span>
    </div>

    <div class="na-h2"><span class="na-h2__num">1</span> {$ac.g4_h1}</div>
    <div class="na-cause na-cause--blue">
      <p class="na-cause__title">{$ac.g4_c1_t}</p>
      <p class="na-cause__body">{$ac.g4_c1_b}</p>
      <div class="na-cause__fix">
        <strong>{$ac.lbl_solution} :</strong> {$ac.g4_c1_f}
        <br><a href="{$na_base}&neria_tab=stats" class="na-tab-link">{$ac.tab_stats_rep}</a>
      </div>
    </div>

    <div class="na-h2"><span class="na-h2__num">2</span> {$ac.g4_h2}</div>
    <div class="na-cause na-cause--orange">
      <p class="na-cause__title">{$ac.g4_c2_t}</p>
      <p class="na-cause__body">{$ac.g4_c2_b}</p>
      <div class="na-cause__fix">
        <strong>{$ac.lbl_solution} :</strong> {$ac.g4_c2_f}
        <br><a href="{$na_base}&neria_tab=stats" class="na-tab-link">{$ac.tab_stats_rep}</a>
      </div>
    </div>

    <div class="na-h2"><span class="na-h2__num">3</span> {$ac.g4_h3}</div>
    <div class="na-cause na-cause--red">
      <p class="na-cause__title">{$ac.g4_c3_t}</p>
      <p class="na-cause__body">{$ac.g4_c3_b}</p>
      <div class="na-cause__fix">
        <strong>{$ac.lbl_solution} :</strong> {$ac.g4_c3_f}
        <br><a href="{$na_base}&neria_tab=segments" class="na-tab-link">{$ac.tab_segs_gh}</a>
      </div>
    </div>

    <div class="na-h2"><span class="na-h2__num">4</span> {$ac.g4_h4}</div>
    <div class="na-cause na-cause--orange">
      <p class="na-cause__title">{$ac.g4_c4_t}</p>
      <p class="na-cause__body">{$ac.g4_c4_b}</p>
      <div class="na-cause__fix">
        <strong>{$ac.lbl_solution} :</strong> {$ac.g4_c4_f}
        <br><a href="{$na_base}&neria_tab=stats" class="na-tab-link">{$ac.tab_stats_del}</a>
      </div>
    </div>

    <div class="na-box na-box--ok" style="margin-top:22px;">
      <span class="na-box__ico">✓</span>
      <span>{$ac.g4_final}</span>
    </div>

  </div>{* /panel deliverability *}


  {* ═══════════════════════════════════════════════════════════════ *}
  {* Guide 5 — Segmentation comportementale                          *}
  {* ═══════════════════════════════════════════════════════════════ *}
  <div class="na-panel" id="na-panel-segmentation">

    <div class="na-guide-header">
      <span class="na-guide-header__icon">🎯</span>
      <div>
        <h2 class="na-guide-header__title">{$ac.g5_title}</h2>
        <p class="na-guide-header__intro">{$ac.g5_intro}</p>
      </div>
    </div>

    <div class="na-box na-box--tip">
      <span class="na-box__ico">ℹ</span>
      <span>{$ac.g5_tip}</span>
    </div>

    <div class="na-h2"><span class="na-h2__num" style="background:#2c2c2c;"></span> {$ac.g5_seg_h}</div>

    <table class="na-table">
      <thead>
        <tr>
          <th>{$ac.g5_col1}</th>
          <th>{$ac.g5_col2}</th>
          <th>{$ac.g5_col3}</th>
        </tr>
      </thead>
      <tbody>
        <tr><td><strong>{$ac.g5_seg1_t}</strong></td><td>{$ac.g5_seg1_c}</td><td>{$ac.g5_seg1_s}</td></tr>
        <tr><td><strong>{$ac.g5_seg2_t}</strong></td><td>{$ac.g5_seg2_c}</td><td>{$ac.g5_seg2_s}</td></tr>
        <tr><td><strong>{$ac.g5_seg3_t}</strong></td><td>{$ac.g5_seg3_c}</td><td>{$ac.g5_seg3_s}</td></tr>
        <tr><td><strong>{$ac.g5_seg4_t}</strong></td><td>{$ac.g5_seg4_c}</td><td>{$ac.g5_seg4_s}</td></tr>
        <tr><td><strong>{$ac.g5_seg5_t}</strong></td><td>{$ac.g5_seg5_c}</td><td>{$ac.g5_seg5_s}</td></tr>
      </tbody>
    </table>

    <div class="na-h2"><span class="na-h2__num" style="background:#2c2c2c;"></span> {$ac.g5_rules_h}</div>

    <div class="na-rules">
      <div class="na-rule">
        <div class="na-rule__num">1</div>
        <div>
          <p class="na-rule__title">{$ac.g5_r1_t}</p>
          <p class="na-rule__body">{$ac.g5_r1_b}</p>
        </div>
      </div>
      <div class="na-rule">
        <div class="na-rule__num">2</div>
        <div>
          <p class="na-rule__title">{$ac.g5_r2_t}</p>
          <p class="na-rule__body">{$ac.g5_r2_b}</p>
        </div>
      </div>
      <div class="na-rule">
        <div class="na-rule__num">3</div>
        <div>
          <p class="na-rule__title">{$ac.g5_r3_t}</p>
          <p class="na-rule__body">{$ac.g5_r3_b}</p>
          <a href="{$na_base}&neria_tab=segments" class="na-tab-link">{$ac.tab_segs_camp}</a>
        </div>
      </div>
    </div>

    <div class="na-box na-box--ok" style="margin-top:22px;">
      <span class="na-box__ico">✓</span>
      <span>{$ac.g5_final}</span>
    </div>

  </div>{* /panel segmentation *}


  {* ═══════════════════════════════════════════════════════════════ *}
  {* Guide 6 — Fidélité & upsell                                     *}
  {* ═══════════════════════════════════════════════════════════════ *}
  <div class="na-panel" id="na-panel-loyalty">

    <div class="na-guide-header">
      <span class="na-guide-header__icon">💎</span>
      <div>
        <h2 class="na-guide-header__title">{$ac.g6_title}</h2>
        <p class="na-guide-header__intro">{$ac.g6_intro}</p>
      </div>
    </div>

    <div class="na-box na-box--tip">
      <span class="na-box__ico">ℹ</span>
      <span>{$ac.g6_tip}</span>
    </div>

    <div class="na-h2"><span class="na-h2__num" style="background:#2c2c2c;"></span> {$ac.g6_loyalty_h}</div>

    <div class="na-legal-grid">
      <div class="na-legal-card">
        <span class="na-legal-card__badge na-badge--orange">{$ac.g6_lc1_badge}</span>
        <p class="na-legal-card__title">{$ac.g6_lc1_title}</p>
        <ul class="na-legal-card__list">
          {foreach from=$ac.g6_lc1_items item=item}<li>{$item}</li>{/foreach}
        </ul>
      </div>
      <div class="na-legal-card">
        <span class="na-legal-card__badge na-badge--blue">{$ac.g6_lc2_badge}</span>
        <p class="na-legal-card__title">{$ac.g6_lc2_title}</p>
        <ul class="na-legal-card__list">
          {foreach from=$ac.g6_lc2_items item=item}<li>{$item}</li>{/foreach}
        </ul>
      </div>
      <div class="na-legal-card">
        <span class="na-legal-card__badge na-badge--green">{$ac.g6_lc3_badge}</span>
        <p class="na-legal-card__title">{$ac.g6_lc3_title}</p>
        <ul class="na-legal-card__list">
          {foreach from=$ac.g6_lc3_items item=item}<li>{$item}</li>{/foreach}
        </ul>
      </div>
    </div>

    <div class="na-h2"><span class="na-h2__num" style="background:#2c2c2c;"></span> {$ac.g6_points_h}</div>

    <div class="na-obligation">
      <p class="na-obligation__body">{$ac.g6_points_intro}</p>
      <ul class="na-obligation__list">
        {foreach from=$ac.g6_points_items item=item}<li>{$item}</li>{/foreach}
      </ul>
      <p class="na-obligation__body" style="margin-top:8px;">{$ac.g6_points_note}</p>
    </div>

    <div class="na-h2"><span class="na-h2__num" style="background:#2c2c2c;"></span> {$ac.g6_upsell_h}</div>

    <div class="na-rules">
      <div class="na-rule">
        <div class="na-rule__num">1</div>
        <div>
          <p class="na-rule__title">{$ac.g6_ur1_t}</p>
          <p class="na-rule__body">{$ac.g6_ur1_b}</p>
        </div>
      </div>
      <div class="na-rule">
        <div class="na-rule__num">2</div>
        <div>
          <p class="na-rule__title">{$ac.g6_ur2_t}</p>
          <p class="na-rule__body">{$ac.g6_ur2_b}</p>
        </div>
      </div>
      <div class="na-rule">
        <div class="na-rule__num">3</div>
        <div>
          <p class="na-rule__title">{$ac.g6_ur3_t}</p>
          <p class="na-rule__body">{$ac.g6_ur3_b}</p>
        </div>
      </div>
    </div>

    <div class="na-box na-box--ok" style="margin-top:22px;">
      <span class="na-box__ico">✓</span>
      <span>{$ac.g6_final}</span>
    </div>

  </div>{* /panel loyalty *}


  {* ═══════════════════════════════════════════════════════════════ *}
  {* Guide 7 — A/B Testing                                           *}
  {* ═══════════════════════════════════════════════════════════════ *}
  <div class="na-panel" id="na-panel-abtest">

    <div class="na-guide-header">
      <span class="na-guide-header__icon">🔬</span>
      <div>
        <h2 class="na-guide-header__title">{$ac.g7_title}</h2>
        <p class="na-guide-header__intro">{$ac.g7_intro}</p>
      </div>
    </div>

    <div class="na-box na-box--tip">
      <span class="na-box__ico">ℹ</span>
      <span>{$ac.g7_tip}</span>
    </div>

    <div class="na-h2"><span class="na-h2__num" style="background:#2c2c2c;"></span> {$ac.g7_rules_h}</div>

    <div class="na-rules">
      <div class="na-rule">
        <div class="na-rule__num">1</div>
        <div>
          <p class="na-rule__title">{$ac.g7_r1_t}</p>
          <p class="na-rule__body">{$ac.g7_r1_b}</p>
        </div>
      </div>
      <div class="na-rule">
        <div class="na-rule__num">2</div>
        <div>
          <p class="na-rule__title">{$ac.g7_r2_t}</p>
          <p class="na-rule__body">{$ac.g7_r2_b}</p>
        </div>
      </div>
      <div class="na-rule">
        <div class="na-rule__num">3</div>
        <div>
          <p class="na-rule__title">{$ac.g7_r3_t}</p>
          <p class="na-rule__body">{$ac.g7_r3_b}</p>
        </div>
      </div>
      <div class="na-rule">
        <div class="na-rule__num">4</div>
        <div>
          <p class="na-rule__title">{$ac.g7_r4_t}</p>
          <p class="na-rule__body">{$ac.g7_r4_b}</p>
        </div>
      </div>
    </div>

    <div class="na-h2"><span class="na-h2__num" style="background:#2c2c2c;"></span> {$ac.g7_ex_h}</div>

    <div class="na-obligation">
      <p class="na-obligation__body">{$ac.g7_ex_intro}</p>
      <ul class="na-obligation__list">
        {foreach from=$ac.g7_ex_items item=item}<li>{$item}</li>{/foreach}
      </ul>
      <a href="{$na_base}&neria_tab=abtest" class="na-tab-link" style="margin-top:10px;display:inline-flex;">{$ac.tab_abtest}</a>
    </div>

    <div class="na-box na-box--ok" style="margin-top:22px;">
      <span class="na-box__ico">✓</span>
      <span>{$ac.g7_final}</span>
    </div>

  </div>{* /panel abtest *}


  {* ═══════════════════════════════════════════════════════════════ *}
  {* Guide 8 — Panier abandonné                                      *}
  {* ═══════════════════════════════════════════════════════════════ *}
  <div class="na-panel" id="na-panel-cart">

    <div class="na-guide-header">
      <span class="na-guide-header__icon">🛒</span>
      <div>
        <h2 class="na-guide-header__title">{$ac.g8_title}</h2>
        <p class="na-guide-header__intro">{$ac.g8_intro}</p>
      </div>
    </div>

    <div class="na-box na-box--tip">
      <span class="na-box__ico">ℹ</span>
      <span>{$ac.g8_tip}</span>
    </div>

    <div class="na-h2"><span class="na-h2__num" style="background:#2c2c2c;"></span> {$ac.g8_rules_h}</div>

    <div class="na-rules">
      <div class="na-rule">
        <div class="na-rule__num">1</div>
        <div>
          <p class="na-rule__title">{$ac.g8_r1_t}</p>
          <p class="na-rule__body">{$ac.g8_r1_b}</p>
        </div>
      </div>
      <div class="na-rule">
        <div class="na-rule__num">2</div>
        <div>
          <p class="na-rule__title">{$ac.g8_r2_t}</p>
          <p class="na-rule__body">{$ac.g8_r2_b}</p>
        </div>
      </div>
      <div class="na-rule">
        <div class="na-rule__num">3</div>
        <div>
          <p class="na-rule__title">{$ac.g8_r3_t}</p>
          <p class="na-rule__body">{$ac.g8_r3_b}</p>
        </div>
      </div>
    </div>

    <div class="na-h2"><span class="na-h2__num" style="background:#2c2c2c;"></span> {$ac.g8_stop_h}</div>

    <div class="na-obligation">
      <ul class="na-obligation__list">
        {foreach from=$ac.g8_stop_items item=item}<li>{$item}</li>{/foreach}
      </ul>
      <a href="{$na_base}&neria_tab=stats" class="na-tab-link" style="margin-top:10px;display:inline-flex;">{$ac.tab_stats_top}</a>
    </div>

    <div class="na-box na-box--ok" style="margin-top:22px;">
      <span class="na-box__ico">✓</span>
      <span>{$ac.g8_final}</span>
    </div>

  </div>{* /panel cart *}

</div>{* /neria-section *}

<script>
(function () {
  'use strict';

  var STORE_KEY = 'neria_academy';
  var GUIDE_KEY = 'neria_academy_guide';
  var CHECK_KEY = 'neria_academy_checks';

  function load(key, def) {
    try { var v = localStorage.getItem(key); return v ? JSON.parse(v) : def; } catch(e) { return def; }
  }
  function save(key, val) {
    try { localStorage.setItem(key, JSON.stringify(val)); } catch(e) {}
  }

  var state = {
    read   : load(STORE_KEY, {}),
    checks : load(CHECK_KEY, {}),
    guide  : load(GUIDE_KEY, 'openrate')
  };

  function updateProgress() {
    var n = Object.keys(state.read).filter(function(k){ return state.read[k]; }).length;
    var fill  = document.getElementById('na-progress-fill');
    var label = document.getElementById('na-progress-label');
    if (fill)  fill.style.width = Math.round(n / 8 * 100) + '%';
    if (label) label.textContent = n + ' / 8';
  }

  window.naShow = function(id) {
    ['openrate','subject','gdpr','deliverability','segmentation','loyalty','abtest','cart'].forEach(function(g){
      var p = document.getElementById('na-panel-' + g);
      var c = document.getElementById('na-card-'  + g);
      if (p) p.classList.toggle('na--active', g === id);
      if (c) c.classList.toggle('na--active', g === id);
    });
    state.read[id] = true;
    save(STORE_KEY, state.read);
    ['openrate','subject','gdpr','deliverability','segmentation','loyalty','abtest','cart'].forEach(function(g){
      var c = document.getElementById('na-card-' + g);
      if (c) c.classList.toggle('na--read', !!state.read[g]);
    });
    state.guide = id;
    save(GUIDE_KEY, id);
    updateProgress();
  };

  window.naToggleCheck = function(el) {
    var key = el.getAttribute('data-key');
    state.checks[key] = !state.checks[key];
    save(CHECK_KEY, state.checks);
    el.classList.toggle('na--checked', state.checks[key]);
  };

  function restoreChecks() {
    document.querySelectorAll('.na-check[data-key]').forEach(function(el){
      if (state.checks[el.getAttribute('data-key')]) el.classList.add('na--checked');
    });
  }

  naShow(state.guide);
  restoreChecks();
  updateProgress();

}());
</script>
