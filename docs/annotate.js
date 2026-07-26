const { Jimp } = require('jimp');
const path = require('path');
const fs = require('fs');

const RAW = path.join(__dirname, 'captures', 'raw');
const OUT = path.join(__dirname, 'captures');
if (!fs.existsSync(OUT)) fs.mkdirSync(OUT, { recursive: true });

// ── Helpers ────────────────────────────────────────────────────────────────

function hexToRgb(hex) {
  const n = parseInt(hex.replace('#', ''), 16);
  return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
}

// Rectangle coloré semi-transparent (fill)
function fillRect(img, x, y, w, h, hex, alpha) {
  const { r, g, b } = hexToRgb(hex);
  const a = Math.round(alpha * 255);
  img.scan(x, y, w, h, function (px, py, idx) {
    const or = this.bitmap.data[idx];
    const og = this.bitmap.data[idx + 1];
    const ob = this.bitmap.data[idx + 2];
    const t = alpha;
    this.bitmap.data[idx]     = Math.round(or * (1 - t) + r * t);
    this.bitmap.data[idx + 1] = Math.round(og * (1 - t) + g * t);
    this.bitmap.data[idx + 2] = Math.round(ob * (1 - t) + b * t);
  });
}

// Bordure épaisse autour d'un rectangle
function strokeRect(img, x, y, w, h, hex, thick = 4) {
  const { r, g, b } = hexToRgb(hex);
  const paint = (px, py) => {
    if (px < 0 || py < 0 || px >= img.bitmap.width || py >= img.bitmap.height) return;
    const idx = (py * img.bitmap.width + px) * 4;
    img.bitmap.data[idx] = r;
    img.bitmap.data[idx + 1] = g;
    img.bitmap.data[idx + 2] = b;
    img.bitmap.data[idx + 3] = 255;
  };
  for (let t = 0; t < thick; t++) {
    for (let i = x - t; i <= x + w + t; i++) { paint(i, y - t); paint(i, y + h + t); }
    for (let j = y - t; j <= y + h + t; j++) { paint(x - t, j); paint(x + w + t, j); }
  }
}

// Ligne épaisse entre deux points (Bresenham)
function drawLine(img, x0, y0, x1, y1, hex, thick = 5) {
  const { r, g, b } = hexToRgb(hex);
  const dx = Math.abs(x1 - x0), dy = Math.abs(y1 - y0);
  const sx = x0 < x1 ? 1 : -1, sy = y0 < y1 ? 1 : -1;
  let err = dx - dy;
  const paint = (px, py) => {
    for (let tx = -thick; tx <= thick; tx++) {
      for (let ty = -thick; ty <= thick; ty++) {
        const nx = px + tx, ny = py + ty;
        if (nx < 0 || ny < 0 || nx >= img.bitmap.width || ny >= img.bitmap.height) continue;
        const idx = (ny * img.bitmap.width + nx) * 4;
        img.bitmap.data[idx] = r;
        img.bitmap.data[idx + 1] = g;
        img.bitmap.data[idx + 2] = b;
        img.bitmap.data[idx + 3] = 255;
      }
    }
  };
  let cx = x0, cy = y0;
  for (let step = 0; step < 2000; step++) {
    paint(cx, cy);
    if (cx === x1 && cy === y1) break;
    const e2 = 2 * err;
    if (e2 > -dy) { err -= dy; cx += sx; }
    if (e2 < dx)  { err += dx; cy += sy; }
  }
}

// Flèche : ligne + pointe triangulaire
function drawArrow(img, x0, y0, x1, y1, hex, thick = 5, headSize = 28) {
  drawLine(img, x0, y0, x1, y1, hex, thick);
  const angle = Math.atan2(y1 - y0, x1 - x0);
  const a1 = angle + (2.8);
  const a2 = angle - (2.8);
  drawLine(img, x1, y1, Math.round(x1 + headSize * Math.cos(a1)), Math.round(y1 + headSize * Math.sin(a1)), hex, thick);
  drawLine(img, x1, y1, Math.round(x1 + headSize * Math.cos(a2)), Math.round(y1 + headSize * Math.sin(a2)), hex, thick);
}

// ── Traitement de chaque capture ──────────────────────────────────────────

async function annotate(filename, fn, outname) {
  const img = await Jimp.read(path.join(RAW, filename));
  fn(img);
  await img.write(path.join(OUT, outname));
  console.log(`✓ ${outname}`);
}

(async () => {

  // C1 — Bureau Windows : zip sur le bureau
  await annotate('capture1.png', img => {
    // Icône zip détectée précisément à y≈555-650 (pas y=399 = Adobe Acrobat)
    strokeRect(img, 5, 548, 90, 110, '#E8003A', 3);
    fillRect(img,   5, 548, 90, 110, '#FF0044', 0.12);
    // Flèche rouge fine venant de la droite
    drawArrow(img, 320, 602, 100, 602, '#E8003A', 3, 18);
  }, 'c1_bureau_zip.png');

  // C2 — Gestionnaire de modules : cliquer sur "Installer un module"
  await annotate('capture2.png', img => {
    // Bouton "Installer un module" détecté : x=1648, y=74, w=160, h=36
    strokeRect(img, 1643, 72, 165, 40, '#E85000', 3);
    fillRect(img,   1643, 72, 165, 40, '#FF6600', 0.20);
    // Flèche fine depuis le milieu de la page
    drawArrow(img, 900, 280, 1648, 92, '#E85000', 2, 14);
  }, 'c2_installer_module.png');

  // C3 — Modale upload vide : "sélectionnez un fichier"
  await annotate('capture3.png', img => {
    // Lien bleu détecté précisément : x=776,y=448 → x=1114,y=456
    strokeRect(img, 770, 442, 348, 20, '#0066CC', 3);
    fillRect(img,   770, 442, 348, 20, '#3399FF', 0.30);
    // Flèche bleue fine venant du bas pointant vers le lien
    drawArrow(img, 944, 580, 944, 468, '#0066CC', 3, 18);
    // Cadre léger sur la zone de dépôt entière (ajustée au lien détecté)
    strokeRect(img, 545, 310, 810, 180, '#0066CC', 2);
  }, 'c3_upload_dialog.png');

  // C4 — Explorateur Windows : sélectionner le zip et cliquer Ouvrir
  // Ligne "neria-1.0.31.zip" dans la liste principale : y≈163-200 (en dessous du toolbar)
  // Bouton Ouvrir : bas droite du dialogue, y≈655-695, x≈700-820
  await annotate('capture4.png', img => {
    // Ligne "neria-1.0.31.zip" : en dessous des en-têtes de colonnes
    strokeRect(img, 210, 195, 660, 42, '#E85000', 3);
    fillRect(img,   210, 195, 660, 42, '#FF6600', 0.22);
  }, 'c4_selectionner_zip.png');

  // C5 — Module installé !
  await annotate('capture5.png', img => {
    // Surligné vert autour du message de succès
    strokeRect(img, 640, 320, 280, 105, '#007700', 4);
    fillRect(img,   640, 320, 280, 105, '#00BB00', 0.12);
    // Flèche verte vers le bouton "Configurer"
    drawArrow(img, 950, 408, 845, 408, '#007700', 5, 26);
    // Surligné vert bouton Configurer
    strokeRect(img, 712, 393, 135, 36, '#007700', 3);
  }, 'c5_module_installe.png');

  // C7 — Page de configuration Neria : bannière licence
  await annotate('capture7.png', img => {
    // Surligné orange sur la bannière d'activation de licence
    strokeRect(img, 160, 233, 1345, 36, '#E85000', 4);
    fillRect(img,   160, 233, 1345, 36, '#FF8800', 0.20);
    // Flèche orange vers le bouton ACTIVER
    drawArrow(img, 800, 310, 897, 258, '#E85000', 5, 26);
    // Surligné rouge sur le champ NERIA-XXXX
    strokeRect(img, 700, 237, 195, 28, '#CC0000', 3);
    // Surligné vert sur ACTIVER
    strokeRect(img, 895, 237, 92, 28, '#007700', 3);
    fillRect(img,   895, 237, 92, 28, '#00AA00', 0.20);
  }, 'c7_activation_licence.png');

  console.log('\nTerminé. Images annotées dans docs/captures/');
})();
