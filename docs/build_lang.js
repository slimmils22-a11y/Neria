// Usage: node build_lang.js <lang>
// Example: node build_lang.js en
//          node build_lang.js de

const {
  Document, Packer, Paragraph, TextRun, HeadingLevel, TableOfContents,
  PageBreak, AlignmentType, BorderStyle, Table, TableRow, TableCell,
  WidthType, LevelFormat, convertInchesToTwip, ImageRun, Header, Footer,
  PageNumber, NumberFormat
} = require("docx");
const fs   = require("fs");
const path = require("path");

const lang = process.argv[2];
if (!lang) { console.error("Usage: node build_lang.js <lang>  (ex: en, de, it)"); process.exit(1); }

const stringsFile = path.join(__dirname, "strings", `${lang}.js`);
if (!fs.existsSync(stringsFile)) {
  console.error(`Strings file not found: ${stringsFile}`);
  process.exit(1);
}
const T = require(stringsFile);

const ACCENT = "B38B59";
const DARK   = "2B2520";
const GREY   = "666666";
const LGREY  = "999999";

const logoData = fs.readFileSync(path.join(__dirname, '..', 'logo.png'));

// ─── HELPERS ────────────────────────────────────────────────────────────────

function h1(text) {
  return new Paragraph({ text, heading: HeadingLevel.HEADING_1, spacing: { before: 500, after: 200 }, pageBreakBefore: true });
}
function h2(text) {
  return new Paragraph({ text, heading: HeadingLevel.HEADING_2, spacing: { before: 320, after: 140 } });
}
function h3(text) {
  return new Paragraph({ text, heading: HeadingLevel.HEADING_3, spacing: { before: 220, after: 100 } });
}
function p(text, opts = {}) {
  return new Paragraph({ children: [new TextRun({ text, ...opts })], spacing: { after: 160 } });
}
function bullet(text, level = 0) {
  return new Paragraph({
    children: [new TextRun({ text })],
    numbering: { reference: "puces", level },
    spacing: { after: 80 },
  });
}
function step(n, text) {
  return new Paragraph({
    children: [new TextRun({ text: `${n}. `, bold: true, color: ACCENT }), new TextRun({ text })],
    spacing: { after: 120 },
    indent: { left: 200 },
  });
}
function note(text) {
  return new Paragraph({
    children: [new TextRun({ text: T.meta.note_label, bold: true, color: ACCENT }), new TextRun({ text })],
    spacing: { before: 120, after: 180 },
    indent: { left: 360 },
  });
}
function warning(text) {
  return new Paragraph({
    children: [new TextRun({ text: T.meta.warning_label, bold: true, color: "CC4400" }), new TextRun({ text })],
    spacing: { before: 120, after: 180 },
    indent: { left: 360 },
  });
}
function capture(label) {
  return new Paragraph({
    children: [new TextRun({ text: `[ CAPTURE : ${label} ]`, italics: true, color: "888888", size: 20 })],
    spacing: { before: 140, after: 200 },
    alignment: AlignmentType.CENTER,
    border: {
      top:    { style: BorderStyle.DASHED, size: 4, color: "CCCCCC" },
      bottom: { style: BorderStyle.DASHED, size: 4, color: "CCCCCC" },
      left:   { style: BorderStyle.DASHED, size: 4, color: "CCCCCC" },
      right:  { style: BorderStyle.DASHED, size: 4, color: "CCCCCC" },
    },
  });
}
function img(filename, origW, origH, displayW = 500) {
  const displayH = Math.round(displayW * origH / origW);
  const fp = path.join(__dirname, 'captures', filename);
  if (!fs.existsSync(fp)) {
    return new Paragraph({
      children: [new TextRun({ text: `[ IMAGE : ${filename} ]`, italics: true, color: "888888", size: 20 })],
      spacing: { before: 120, after: 200 },
      alignment: AlignmentType.CENTER,
    });
  }
  const data = fs.readFileSync(fp);
  return new Paragraph({
    children: [new ImageRun({ data, transformation: { width: displayW, height: displayH } })],
    spacing: { before: 120, after: 200 },
    alignment: AlignmentType.CENTER,
  });
}
function sep() {
  return new Paragraph({
    border: { bottom: { style: BorderStyle.SINGLE, size: 2, color: "E8D5B0" } },
    spacing: { before: 200, after: 200 },
    text: "",
  });
}

// ─── HEADER / FOOTER ────────────────────────────────────────────────────────

const pageHeader = new Header({
  children: [
    new Paragraph({
      children: [
        new ImageRun({ data: logoData, transformation: { width: 36, height: 36 } }),
        new TextRun({ text: `   ${T.meta.header_text}`, size: 16, color: LGREY }),
      ],
      border: { bottom: { style: BorderStyle.SINGLE, size: 3, color: ACCENT } },
      spacing: { after: 80 },
    }),
  ],
});

const pageFooter = new Footer({
  children: [
    new Paragraph({
      alignment: AlignmentType.CENTER,
      children: [
        new TextRun({ text: `${T.meta.footer_text}  |  `, size: 16, color: LGREY }),
        new TextRun({ children: [PageNumber.CURRENT], size: 16, color: LGREY }),
        new TextRun({ text: " / ", size: 16, color: LGREY }),
        new TextRun({ children: [PageNumber.TOTAL_PAGES], size: 16, color: LGREY }),
      ],
      border: { top: { style: BorderStyle.SINGLE, size: 3, color: ACCENT } },
    }),
  ],
});

// ─── SECTIONS ───────────────────────────────────────────────────────────────
const S = [];

// ══════════════════════════ COVER PAGE ══════════════════════════════════════
S.push(
  new Paragraph({ text: "", spacing: { before: 1800 } }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    children: [new ImageRun({ data: logoData, transformation: { width: 110, height: 110 } })],
    spacing: { after: 400 },
  }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    children: [new TextRun({ text: T.meta.cover_name, bold: true, size: 80, color: DARK })],
  }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    children: [new TextRun({ text: T.meta.cover_subtitle, size: 36, color: ACCENT, italics: true })],
    spacing: { after: 600 },
  }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    children: [new TextRun({ text: T.meta.doc_title, bold: true, size: 44 })],
    spacing: { after: 160 },
  }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    children: [new TextRun({ text: T.meta.doc_subtitle, size: 24, color: GREY })],
    spacing: { after: 160 },
  }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    children: [new TextRun({ text: T.meta.doc_tagline, size: 22, color: LGREY })],
    spacing: { after: 2400 },
  }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    children: [new TextRun({ text: T.meta.footer_text, size: 18, color: LGREY })],
  }),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ TABLE OF CONTENTS ════════════════════════════════
S.push(
  new Paragraph({ text: T.meta.toc_title, heading: HeadingLevel.HEADING_1, spacing: { before: 400, after: 200 } }),
  new TableOfContents(T.meta.toc_title, { hyperlink: true, headingStyleRange: "1-2" }),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 1. ABOUT NERIA ══════════════════════════════════
{ const X = T.s1; S.push(
  h1(X.title),
  p(X.p1), p(X.p2),
  h2(X.h_compat),
  bullet(X.b_compat1), bullet(X.b_compat2), bullet(X.b_compat3), bullet(X.b_compat4),
  h2(X.h_auto), p(X.p_auto),
  h2(X.h_network), p(X.p_network),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 2. REQUIREMENTS ═════════════════════════════════
{ const X = T.s2; S.push(
  h1(X.title),
  h2(X.h_server),
  bullet(X.b1), bullet(X.b2), bullet(X.b3), bullet(X.b4),
  bullet(X.b5), bullet(X.b6), bullet(X.b7),
  h2(X.h_hooks),
  bullet(X.bh1), bullet(X.bh2), bullet(X.bh3), bullet(X.bh4),
  bullet(X.bh5), bullet(X.bh6), bullet(X.bh7), bullet(X.bh8),
  bullet(X.bh9), bullet(X.bh10), bullet(X.bh11),
  h2(X.h_tables), p(X.p_tables),
  bullet(X.bt1), bullet(X.bt2), bullet(X.bt3), bullet(X.bt4), bullet(X.bt5),
  bullet(X.bt6), bullet(X.bt7), bullet(X.bt8), bullet(X.bt9), bullet(X.bt10),
  bullet(X.bt11), bullet(X.bt12), bullet(X.bt13), bullet(X.bt14), bullet(X.bt15),
  bullet(X.bt16), bullet(X.bt17), bullet(X.bt18), bullet(X.bt19), bullet(X.bt20),
  bullet(X.bt21), bullet(X.bt22), bullet(X.bt23), bullet(X.bt24), bullet(X.bt25),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 3. INSTALLATION ═════════════════════════════════
{ const X = T.s3; S.push(
  h1(X.title),
  capture("C3-01 — Module catalog view before installation"),
  h2(X.h_addons),
  step(1, X.step1), step(2, X.step2), step(3, X.step3), step(4, X.step4),
  h2(X.h_manual),
  step(1, X.mstep1),
  img('image1-.png', 1786, 1019, 260),
  step(2, X.mstep2),
  img('image-2.png', 1875, 926, 500),
  step(3, X.mstep3),
  img('image-3.png', 1899, 922, 500),
  step(4, X.mstep4),
  img('image-4.png', 1899, 984, 500),
  step(5, X.mstep5),
  img('image-5.png', 1892, 916, 500),
  step(6, X.mstep6),
  img('image-7.png', 1882, 920, 500),
  h2(X.h_creates), p(X.p_creates),
  bullet(X.bc1), bullet(X.bc2), bullet(X.bc3),
  note(X.note),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 4. LICENCE ACTIVATION ═══════════════════════════
{ const X = T.s4; S.push(
  h1(X.title), p(X.p_intro),
  h2(X.h_activate),
  step(1, X.step1), step(2, X.step2), step(3, X.step3),
  img('image-6.png', 1881, 924, 500),
  capture("C4-02 — Green 'Licence active' banner after successful activation"),
  h2(X.h_domain), p(X.p_domain),
  h2(X.h_fault), p(X.p_fault),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 5. BACK-OFFICE OVERVIEW ═════════════════════════
{ const X = T.s5; S.push(
  h1(X.title), p(X.p_intro),
  img('image-accueil.png', 1911, 935, 500),
  h2(X.h_tabs),
  bullet(X.b1), bullet(X.b2), bullet(X.b3), bullet(X.b4), bullet(X.b5),
  bullet(X.b6), bullet(X.b7), bullet(X.b8), bullet(X.b9), bullet(X.b10),
  bullet(X.b11), bullet(X.b12), bullet(X.b13), bullet(X.b14), bullet(X.b15),
  bullet(X.b16), bullet(X.b17), bullet(X.b18), bullet(X.b19), bullet(X.b20),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 6. HOME TAB ═════════════════════════════════════
{ const X = T.s6; S.push(
  h1(X.title), p(X.p_intro),
  img('image-accueil.png', 1911, 935, 500),
  h2(X.h_kpis), p(X.p_kpis),
  img('image-accueil2.png', 1896, 935, 500),
  h2(X.h_lang), p(X.p_lang),
  img('image-accueil3.png', 1896, 935, 500),
  h2(X.h_salut), p(X.p_salut),
  img('image-accueil4.png', 1897, 926, 500),
  img('image-accueil5.png', 1898, 940, 500),
  h2(X.h_params),
  bullet(X.b1), bullet(X.b2), bullet(X.b3), bullet(X.b4),
  img('image-accueil6.png', 1883, 935, 500),
  img('image-accueil7.png', 1898, 935, 500),
  img('image-accueil8.png', 1898, 935, 500),
  img('image-accueil9.png', 1901, 935, 500),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 7. DESIGN ════════════════════════════════════════
{ const X = T.s7; S.push(
  h1(X.title), p(X.p_intro),
  img('image-design.png', 1911, 935, 500),
  h2(X.h_colors),
  bullet(X.b1), bullet(X.b2), bullet(X.b3),
  step(1, X.step1), step(2, X.step2), step(3, X.step3),
  img('image-design2.png', 1911, 935, 500),
  h2(X.h_logo),
  step(1, X.lstep1), step(2, X.lstep2), step(3, X.lstep3),
  h2(X.h_dark), p(X.p_dark),
  h2(X.h_width), p(X.p_width),
  img('image-design3.png', 1911, 935, 500),
  h2(X.h_presets), p(X.p_presets),
  bullet(X.bp1), bullet(X.bp2), bullet(X.bp3),
  bullet(X.bp4), bullet(X.bp5), bullet(X.bp6),
  step(1, X.pstep1), step(2, X.pstep2), step(3, X.pstep3), step(4, X.pstep4),
  h2(X.h_sig), p(X.p_sig),
  step(1, X.sstep1), step(2, X.sstep2), step(3, X.sstep3), step(4, X.sstep4),
  img('image-design4.png', 1911, 935, 500),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 8. TYPOGRAPHY ═══════════════════════════════════
{ const X = T.s8; S.push(
  h1(X.title), p(X.p_intro),
  img('image-typo.png', 1911, 935, 500),
  h2(X.h_config),
  step(1, X.step1), step(2, X.step2), step(3, X.step3), step(4, X.step4),
  img('image-typo2.png', 1911, 935, 500),
  note(X.note),
  img('image-typo3.png', 1911, 935, 500),
  img('image-typo4.png', 1911, 935, 500),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 9. SOCIAL MEDIA ═════════════════════════════════
{ const X = T.s9; S.push(
  h1(X.title), p(X.p_intro),
  img('image-rs.png', 1911, 935, 500),
  step(1, X.step1), step(2, X.step2), step(3, X.step3),
  img('image-rs2.png', 1911, 935, 500),
  capture("C9-02 — Email footer with injected social media icons (live preview)"),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 10. TRANSLATIONS ════════════════════════════════
{ const X = T.s10; S.push(
  h1(X.title), p(X.p_intro),
  img('image-trad.png', 1911, 935, 500),
  h2(X.h_edit),
  step(1, X.step1), step(2, X.step2), step(3, X.step3), step(4, X.step4), step(5, X.step5),
  img('image-trad2.png', 1911, 935, 500),
  h2(X.h_deepl),
  step(1, X.dstep1), step(2, X.dstep2), step(3, X.dstep3),
  note(X.note),
  h2(X.h_csv),
  step(1, X.cstep1), step(2, X.cstep2), step(3, X.cstep3),
  h2(X.h_blacklist), p(X.p_blacklist),
  step(1, X.bstep1), step(2, X.bstep2), step(3, X.bstep3),
  img('image-trad3.png', 1911, 935, 500),
  h2(X.h_history), p(X.p_history),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 11. VOICE FINGERPRINT ═══════════════════════════
{ const X = T.s11; S.push(
  h1(X.title), p(X.p_intro),
  img('image-vocal.png', 1911, 935, 500),
  step(1, X.step1), step(2, X.step2), step(3, X.step3),
  step(4, X.step4), step(5, X.step5), step(6, X.step6),
  img('image-vocal2.png', 1911, 935, 500),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 12. MANUAL SEND ═════════════════════════════════
{ const X = T.s12; S.push(
  h1(X.title), p(X.p_intro),
  img('image-envoi.png', 1911, 935, 500),
  step(1, X.step1), step(2, X.step2), step(3, X.step3),
  step(4, X.step4), step(5, X.step5), step(6, X.step6),
  note(X.note),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 13. MULTI-CLIENT PREVIEW ════════════════════════
{ const X = T.s13; S.push(
  h1(X.title), p(X.p_intro),
  img('image-preview.png', 1911, 935, 500),
  step(1, X.step1), step(2, X.step2), step(3, X.step3), step(4, X.step4),
  img('image-preview2.png', 1911, 935, 500),
  h2(X.h_dark), p(X.p_dark),
  img('image-preview3.png', 1911, 935, 500),
  h2(X.h_pdf), p(X.p_pdf),
  img('image-preview4.png', 1911, 935, 500),
  img('image-preview5.png', 1911, 935, 500),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 14. BEHAVIOURAL AUTOMATIONS ═════════════════════
{ const X = T.s14; S.push(
  h1(X.title), p(X.p_intro),
  img('image-auto.png', 1536, 768, 500),
  img('image-auto2.png', 1536, 768, 500),
  note(X.note),
  h2(X.h_cron), p(X.p_cron), note(X.note_cron),
  h2(X.h_birthday), p(X.p_birthday),
  bullet(X.b1), bullet(X.b2), bullet(X.b3),
  h2(X.h_cart), p(X.p_cart),
  bullet(X.bc1), bullet(X.bc2), bullet(X.bc3),
  note(X.note_cart),
  h2(X.h_checkout), p(X.p_checkout),
  h2(X.h_postpurchase),
  bullet(X.bp1), bullet(X.bp2),
  h2(X.h_reorder), p(X.p_reorder),
  h2(X.h_winback), p(X.p_winback),
  h2(X.h_delay), p(X.p_delay),
  h2(X.h_ghost), p(X.p_ghost),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 15. ORDER TRIGGERS ══════════════════════════════
{ const X = T.s15; S.push(
  h1(X.title), p(X.p_intro),
  h2(X.h_milestone), p(X.p_milestone),
  h2(X.h_hold), p(X.p_hold),
  h2(X.h_partial), p(X.p_partial),
  h2(X.h_refund), p(X.p_refund),
  h2(X.h_return), p(X.p_return),
  note(X.note),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 16. CALENDAR ════════════════════════════════════
{ const X = T.s16; S.push(
  h1(X.title), p(X.p_intro),
  img('image-cal.png', 1911, 935, 500),
  p(X.p_list),
  h2(X.h_toggle),
  step(1, X.tstep1), step(2, X.tstep2),
  h2(X.h_custom),
  step(1, X.cstep1), step(2, X.cstep2), step(3, X.cstep3),
  img('image-cal1.png', 1911, 935, 500),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 17. SEASONAL CAMPAIGNS ══════════════════════════
{ const X = T.s17; S.push(
  h1(X.title), p(X.p_intro),
  img('image-saison.png', 1911, 935, 500),
  h2(X.h_create),
  step(1, X.step1), step(2, X.step2), step(3, X.step3),
  step(4, X.step4), step(5, X.step5), step(6, X.step6),
  img('image-saison2.png', 1911, 935, 500),
  img('image-saison3.png', 1911, 935, 500),
  note(X.note),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 18. LOYALTY PROGRAMME ═══════════════════════════
{ const X = T.s18; S.push(
  h1(X.title), p(X.p_intro),
  img('image-fidelite.png', 1536, 768, 500),
  h2(X.h_points),
  bullet(X.bp1), bullet(X.bp2), bullet(X.bp3),
  p(X.p_realtime),
  h2(X.h_tiers), p(X.p_tiers),
  bullet(X.bt1), bullet(X.bt2), bullet(X.bt3),
  step(1, X.tstep1), step(2, X.tstep2), step(3, X.tstep3),
  h2(X.h_emails),
  bullet(X.be1), bullet(X.be2), bullet(X.be3),
  h2(X.h_multi), p(X.p_multi),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 19. SEGMENTS ════════════════════════════════════
{ const X = T.s19; S.push(
  h1(X.title), p(X.p_intro),
  img('image-segments.png', 1911, 935, 500),
  h2(X.h_segs),
  bullet(X.b1), bullet(X.b2), bullet(X.b3), bullet(X.b4), bullet(X.b5),
  h2(X.h_use), p(X.p_use),
  img('image-segments2.png', 1911, 935, 500),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 20. GOLDEN HOUR ═════════════════════════════════
{ const X = T.s20; S.push(
  h1(X.title), p(X.p_intro),
  img('image-golden.png', 1536, 234, 500),
  p(X.p_reading),
  note(X.note),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 21. PURCHASE WINDOW ═════════════════════════════
{ const X = T.s21; S.push(
  h1(X.title), p(X.p_intro),
  img('image-fenetre.png', 1536, 350, 500),
  p(X.p_dashboard),
  h2(X.h_enable),
  step(1, X.step1), step(2, X.step2),
  note(X.note),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 22. UPSELL & COLLECTION ═════════════════════════
{ const X = T.s22; S.push(
  h1(X.title),
  h2(X.h_upsell), p(X.p_upsell),
  bullet(X.b1), bullet(X.b2), bullet(X.b3),
  img('image-upsell.png', 1536, 530, 500),
  p(X.p_dashboard),
  note(X.note),
  h2(X.h_look), p(X.p_look),
  img('image-look.png', 1536, 430, 500),
  step(1, X.lstep1), step(2, X.lstep2), step(3, X.lstep3),
  note(X.note_look),
  h2(X.h_collection), p(X.p_collection),
  img('image-collection.png', 1536, 430, 500),
  step(1, X.cstep1), step(2, X.cstep2), step(3, X.cstep3),
  note(X.note_collection),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 23. WAITLIST ════════════════════════════════════
{ const X = T.s23; S.push(
  h1(X.title), p(X.p_intro),
  img('image-attente.png', 1436, 520, 500),
  h2(X.h_config),
  step(1, X.step1), step(2, X.step2), step(3, X.step3),
  img('image-attente2.png', 1536, 650, 500),
  h2(X.h_manage), p(X.p_manage),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 24. A/B TESTS ═══════════════════════════════════
{ const X = T.s24; S.push(
  h1(X.title), p(X.p_intro),
  img('image-ab.png', 1911, 935, 500),
  h2(X.h_create),
  step(1, X.step1), step(2, X.step2), step(3, X.step3), step(4, X.step4), step(5, X.step5),
  img('image-ab2.png', 1911, 935, 500),
  img('image-ab3.png', 1911, 935, 500),
  h2(X.h_results), p(X.p_results),
  img('image-ab4.png', 1911, 935, 500),
  h2(X.h_winner),
  step(1, X.wstep1), step(2, X.wstep2), step(3, X.wstep3),
  img('image-ab5.png', 1911, 935, 500),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 25. STATISTICS ══════════════════════════════════
{ const X = T.s25; S.push(
  h1(X.title), p(X.p_intro),
  img('image-stats.png', 1911, 935, 500),
  img('image-stats2.png', 1911, 935, 500),
  h2(X.h_kpis),
  bullet(X.bk1), bullet(X.bk2), bullet(X.bk3), bullet(X.bk4),
  img('image-stats3.png', 1911, 935, 500),
  img('image-stats4.png', 1911, 935, 500),
  h2(X.h_tracking), p(X.p_tracking),
  img('image-stats5.png', 1911, 935, 500),
  h2(X.h_attribution), p(X.p_attribution),
  img('image-stats6.png', 1911, 935, 500),
  img('image-stats7.png', 1911, 935, 500),
  h2(X.h_deliverability), p(X.p_deliverability),
  img('image-stats8.png', 1911, 935, 500),
  img('image-stats9.png', 1911, 935, 500),
  h2(X.h_churn), p(X.p_churn),
  img('image-stats10.png', 1911, 935, 500),
  img('image-stats11.png', 1911, 935, 500),
  h2(X.h_clv), p(X.p_clv),
  img('image-stats12.png', 1911, 935, 500),
  img('image-stats13.png', 1911, 935, 500),
  h2(X.h_propensity), p(X.p_propensity),
  img('image-stats14.png', 1911, 935, 500),
  img('image-stats15.png', 1911, 935, 500),
  h2(X.h_history), p(X.p_history),
  img('image-stats16.png', 1911, 935, 500),
  img('image-stats17.png', 1911, 935, 500),
  img('image-stats18.png', 1911, 935, 500),
  img('image-stats19.png', 1911, 935, 500),
  img('image-stats20.png', 1911, 935, 500),
  img('image-stats21.png', 1911, 935, 500),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 25b. CUSTOMER HISTORY TAB ═══════════════════════
{ const X = T.s25b; S.push(
  h1(X.title), p(X.p_intro),
  img('image-histo.png', 1911, 935, 500),
  h2(X.h_filters),
  bullet(X.b1), bullet(X.b2), bullet(X.b3), bullet(X.b4), bullet(X.b5),
  h2(X.h_export), p(X.p_export),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 26. MONTHLY REPORT ══════════════════════════════
{ const X = T.s26; S.push(
  h1(X.title), p(X.p_intro),
  img('image-rapport.png', 1536, 768, 500),
  h2(X.h_config),
  step(1, X.step1), step(2, X.step2), step(3, X.step3),
  img('image-rapport2.png', 1536, 400, 500),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 27. DOMAIN REPUTATION ═══════════════════════════
{ const X = T.s27; S.push(
  h1(X.title), p(X.p_intro),
  img('image-reputation.png', 1536, 768, 500),
  h2(X.h_results),
  bullet(X.b1), bullet(X.b2), bullet(X.b3), bullet(X.b4),
  note(X.note),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 28. GOOGLE / SEO ════════════════════════════════
{ const X = T.s28; S.push(
  h1(X.title),
  h2(X.h_postmaster), p(X.p_postmaster),
  step(1, X.pstep1), step(2, X.pstep2), step(3, X.pstep3),
  img('image-seo.png', 1536, 768, 500),
  h2(X.h_console), p(X.p_console),
  step(1, X.cstep1), step(2, X.cstep2), step(3, X.cstep3),
  img('image-seo2.png', 1536, 768, 500),
  h2(X.h_pagespeed), p(X.p_pagespeed),
  step(1, X.psstep1), step(2, X.psstep2),
  img('image-seo3.png', 1536, 768, 500),
  h2(X.h_seo), p(X.p_seo),
  step(1, X.sstep1), step(2, X.sstep2),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 29. BOUNCES ═════════════════════════════════════
{ const X = T.s29; S.push(
  h1(X.title), p(X.p_intro),
  img('image-bounces.png', 1911, 935, 500),
  h2(X.h_imap),
  step(1, X.step1), step(2, X.step2), step(3, X.step3),
  img('image-bounces2.png', 1911, 935, 500),
  h2(X.h_webhook), p(X.p_webhook),
  img('image-bounces3.png', 1911, 935, 500),
  h2(X.h_types),
  bullet(X.b1), bullet(X.b2),
  img('image-bounces4.png', 1911, 935, 500),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 30. CERTIFICATES ════════════════════════════════
{ const X = T.s30; S.push(
  h1(X.title), p(X.p_intro),
  img('image-cert.png', 1911, 935, 500),
  h2(X.h_config),
  step(1, X.step1), step(2, X.step2), step(3, X.step3), step(4, X.step4),
  img('image-cert2.png', 1911, 935, 500),
  h2(X.h_manual), p(X.p_manual),
  warning(X.warning),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 31. PREFERENCE CENTRE ═══════════════════════════
{ const X = T.s31; S.push(
  h1(X.title), p(X.p_intro), p(X.p_access),
  h2(X.h_categories),
  bullet(X.b1), bullet(X.b2), bullet(X.b3), bullet(X.b4),
  bullet(X.b5), bullet(X.b6), bullet(X.b7),
  h2(X.h_unsub), p(X.p_unsub), p(X.p_unsub2),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 32. GDPR ════════════════════════════════════════
{ const X = T.s32; S.push(
  h1(X.title), p(X.p_intro),
  img('image-rgpd.png', 1911, 935, 500),
  h2(X.h_score), p(X.p_score),
  img('image-rgpd2.png', 1911, 935, 500),
  h2(X.h_retention), p(X.p_retention),
  img('image-rgpd3.png', 1911, 935, 500),
  h2(X.h_purge),
  step(1, X.pstep1), step(2, X.pstep2),
  img('image-rgpd4.png', 1911, 935, 500),
  img('image-rgpd6.png', 1911, 935, 500),
  note(X.note),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 33. ENCRYPTION ══════════════════════════════════
{ const X = T.s33; S.push(
  h1(X.title), p(X.p_intro),
  img('image-crypto.png', 1536, 200, 500),
  p(X.p_red),
  img('image-crypto2.png', 1536, 200, 500),
  h2(X.h_what),
  bullet(X.b1), bullet(X.b2), bullet(X.b3), bullet(X.b4), bullet(X.b5),
  h2(X.h_how),
  bullet(X.bh1), bullet(X.bh2), bullet(X.bh3),
  h2(X.h_watchdog), p(X.p_watchdog),
  bullet(X.bw1), bullet(X.bw2), bullet(X.bw3), bullet(X.bw4),
  p(X.p_alert),
  h2(X.h_migration), p(X.p_migration),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 34. WEBHOOKS ════════════════════════════════════
{ const X = T.s34; S.push(
  h1(X.title), p(X.p_intro),
  img('image-webhook.png', 1536, 768, 500),
  h2(X.h_events),
  bullet(X.b1), bullet(X.b2), bullet(X.b3), bullet(X.b4), bullet(X.b5),
  h2(X.h_config),
  step(1, X.step1), step(2, X.step2), step(3, X.step3), step(4, X.step4), step(5, X.step5),
  note(X.note),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 35. SILENT WITNESS ══════════════════════════════
{ const X = T.s35; S.push(
  h1(X.title), p(X.p_intro),
  step(1, X.step1), step(2, X.step2), step(3, X.step3),
  capture("C35-01 — Silent witness configuration in general settings"),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 36. FALLBACK EMAIL ═══════════════════════════════
{ const X = T.s36; S.push(
  h1(X.title), p(X.p_intro), p(X.p_log),
  capture("C36-01 — Watchdog entry for a triggered fallback email with error context"),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 37. SILENCE MODE ════════════════════════════════
{ const X = T.s37; S.push(
  h1(X.title), p(X.p_intro),
  step(1, X.step1), step(2, X.step2),
  capture("C37-01 — Silence mode configuration with the cooldown window"),
  note(X.note),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 38. WATCHDOG & HELP ═════════════════════════════
{ const X = T.s38; S.push(
  h1(X.title), p(X.p_intro),
  img('image-aide.png', 1911, 935, 500),
  h2(X.h_log), p(X.p_log),
  bullet(X.b1), bullet(X.b2), bullet(X.b3),
  img('image-aide2.png', 1911, 935, 500),
  img('image-aide3.png', 1911, 935, 500),
  h2(X.h_alerts),
  bullet(X.ba1), bullet(X.ba2),
  img('image-aide4.png', 1911, 935, 500),
  h2(X.h_selfheal), p(X.p_selfheal),
  img('image-aide5.png', 1911, 935, 500),
  h2(X.h_diag),
  step(1, X.dstep1), step(2, X.dstep2),
  img('image-aide6.png', 1911, 935, 500),
  img('image-aide7.png', 1911, 935, 500),
  h2(X.h_emergency), p(X.p_emergency),
  step(1, X.estep1), step(2, X.estep2), step(3, X.estep3),
  img('image-aide8.png', 1911, 935, 500),
  img('image-aide9.png', 1911, 935, 500),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 39. CONTROL CENTRE ══════════════════════════════
{ const X = T.s39; S.push(
  h1(X.title), p(X.p_intro),
  img('image-ctrl.png', 1911, 935, 500),
  step(1, X.step1), step(2, X.step2), step(3, X.step3),
  img('image-ctrl2.png', 1911, 935, 500),
  img('image-ctrl3.png', 1911, 935, 500),
  img('image-ctrl4.png', 1911, 935, 500),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 40. ACADEMY ═════════════════════════════════════
{ const X = T.s40; S.push(
  h1(X.title), p(X.p_intro),
  img('image-academy.png', 1911, 935, 500),
  bullet(X.b1), bullet(X.b2), bullet(X.b3), bullet(X.b4),
  bullet(X.b5), bullet(X.b6), bullet(X.b7), bullet(X.b8),
  step(1, X.step1), step(2, X.step2),
  img('image-academy2.png', 1911, 935, 500),
  img('image-academy3.png', 1911, 935, 500),
  img('image-academy4.png', 1911, 935, 500),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 41. TEMPLATE CATALOGUE ══════════════════════════
{ const X = T.s41; S.push(
  h1(X.title), p(X.p_intro),
  h2(X.h_transac),  bullet(X.bt1),
  h2(X.h_behavioral), bullet(X.bb1),
  h2(X.h_luxury),   bullet(X.bl1),
  h2(X.h_loyalty),  bullet(X.blo1),
  h2(X.h_calendar), bullet(X.bc1),
  h2(X.h_b2b),      bullet(X.b2b1),
  h2(X.h_system),   bullet(X.bs1),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 42. FAQ ═════════════════════════════════════════
{ const X = T.s42; S.push(
  h1(X.title),
  h2(X.q1),
  bullet(X.a1b1), bullet(X.a1b2), bullet(X.a1b3), bullet(X.a1b4),
  h2(X.q2), p(X.a2),
  h2(X.q3), p(X.a3),
  h2(X.q4), p(X.a4),
  h2(X.q5), p(X.a5),
  h2(X.q6), p(X.a6),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ 43. SUPPORT ═════════════════════════════════════
{ const X = T.s43; S.push(
  h1(X.title),
  h2(X.h_support),
  bullet(X.b1), bullet(X.b2), bullet(X.b3),
  h2(X.h_care), p(X.p_care),
  bullet(X.bc1), bullet(X.bc2), bullet(X.bc3),
  h2(X.h_renew), p(X.p_renew),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ APPENDIX A ══════════════════════════════════════
{ const X = T.annexeA; S.push(
  h1(X.title),
  h2(X.h_license), p(X.p_license),
  h2(X.h_copyright), p(X.p_copyright),
  h2(X.h_fonts),
  bullet(X.bf1), bullet(X.bf2),
  h2(X.h_libs),
  bullet(X.bl1), bullet(X.bl2),
  h2(X.h_privacy), p(X.p_privacy),
  new Paragraph({ children: [new PageBreak()] })
); }

// ══════════════════════════ DOCUMENT GENERATION ═════════════════════════════

const doc = new Document({
  numbering: {
    config: [{
      reference: "puces",
      levels: [{ level: 0, format: LevelFormat.BULLET, text: "•", alignment: AlignmentType.LEFT,
        style: { paragraph: { indent: { left: convertInchesToTwip(0.35), hanging: convertInchesToTwip(0.2) } } } }],
    }],
  },
  styles: {
    default: { document: { run: { font: "Calibri", size: 22 } } },
    paragraphStyles: [
      { id: "Heading1", name: "Heading 1", basedOn: "Normal", next: "Normal", quickFormat: true,
        run: { size: 34, bold: true, color: DARK, font: "Calibri" },
        paragraph: { spacing: { before: 500, after: 200 }, outlineLevel: 0,
          border: { bottom: { style: BorderStyle.SINGLE, size: 3, color: ACCENT } } } },
      { id: "Heading2", name: "Heading 2", basedOn: "Normal", next: "Normal", quickFormat: true,
        run: { size: 26, bold: true, color: ACCENT, font: "Calibri" },
        paragraph: { spacing: { before: 300, after: 150 }, outlineLevel: 1 } },
      { id: "Heading3", name: "Heading 3", basedOn: "Normal", next: "Normal", quickFormat: true,
        run: { size: 23, bold: true, italics: true, color: DARK, font: "Calibri" },
        paragraph: { spacing: { before: 200, after: 100 }, outlineLevel: 2 } },
    ],
  },
  sections: [{
    properties: {
      page: { size: { width: 11906, height: 16838 } },
    },
    headers: { default: pageHeader },
    footers: { default: pageFooter },
    children: S,
  }],
});

const outFile = path.join(__dirname, `Neria_Notice_Utilisation_${lang.toUpperCase()}.docx`);
Packer.toBuffer(doc).then((buffer) => {
  fs.writeFileSync(outFile, buffer);
  console.log(`✓ Document generated: ${path.basename(outFile)}`);
});
