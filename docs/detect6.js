const { Jimp } = require('jimp');

(async () => {
  // C1: trouver le zip icon SANS inclure Adobe Acrobat
  // L'icône zip (dossier compressé orange) est SOUS le dossier Adobe Acrobat (rouge)
  // Chercher pixels orange/jaune (dossier zip) dans la colonne gauche
  const c1 = await Jimp.read('captures/raw/capture1.png');
  const W1 = c1.bitmap.width, H1 = c1.bitmap.height;
  console.log('C1:', W1, 'x', H1);
  // L'icône neria zip est jaune-orange. Scan colonne x=50 pour trouver la transition
  console.log('C1 col x=50 (icône):');
  for (let y = 300; y < 700; y += 8) {
    const idx = (y * W1 + 50) * 4;
    const r=c1.bitmap.data[idx], g=c1.bitmap.data[idx+1], b=c1.bitmap.data[idx+2];
    const marker = (r > 160 && g > 100 && g < 185 && b < 90) ? 'ORANGE' :
                   (r > 100 && r < 200 && g < 50 && b < 50) ? 'RED' :
                   (r > 200 && g > 200 && b > 200) ? 'WHITE' : `(${r},${g},${b})`;
    if (marker !== 'WHITE') console.log('  y='+y+': '+marker);
  }

  // C4: trouver la ligne neria-1.0.31.zip dans la ZONE PRINCIPALE (pas la navigation gauche)
  // La zone navigation gauche fait ~185px de large
  const c4 = await Jimp.read('captures/raw/capture4.png');
  const W4 = c4.bitmap.width, H4 = c4.bitmap.height;
  console.log('\nC4:', W4, 'x', H4);
  // Chercher l'icône zip dans la zone x=200-900 (liste principale du file picker)
  let z4minX=9999, z4maxX=0, z4minY=9999, z4maxY=0;
  c4.scan(200, 100, 750, 600, function(x, y, idx) {
    const r = this.bitmap.data[idx], g = this.bitmap.data[idx+1], b = this.bitmap.data[idx+2];
    if (r > 160 && g > 100 && g < 185 && b < 90) {
      if(x<z4minX)z4minX=x; if(x>z4maxX)z4maxX=x; if(y<z4minY)z4minY=y; if(y>z4maxY)z4maxY=y;
    }
  });
  console.log('C4 zip icon (main list x>200):', z4minX, z4minY, '->', z4maxX, z4maxY);

  // C4: carte de l'intensité verticale pour trouver bouton Ouvrir
  // Scanner colonne x=620 (milieu-droit du dialogue) pour voir la hauteur
  console.log('\nC4 col x=620 (milieu-droit):');
  for (let y = 580; y < H4; y += 8) {
    const idx = (y * W4 + 620) * 4;
    const r=c4.bitmap.data[idx], g=c4.bitmap.data[idx+1], b=c4.bitmap.data[idx+2];
    const avg=(r+g+b)/3;
    const m = avg<60?'D': avg>200?'W': avg>140?'L':'.';
    if (m !== 'W') console.log('  y='+y+': '+m+' ('+r+','+g+','+b+')');
  }

  // C3: trouver le lien "sélectionnez un fichier" dans la modale PS
  // La modale est centrée. Le lien est en bleu/souligné
  const c3 = await Jimp.read('captures/raw/capture3.png');
  const W3 = c3.bitmap.width, H3 = c3.bitmap.height;
  console.log('\nC3:', W3, 'x', H3);
  // Chercher pixels bleus (lien hypertexte) dans la zone centrale de la modale
  let lminX=9999, lmaxX=0, lminY=9999, lmaxY=0;
  c3.scan(400, 200, 1100, 400, function(x, y, idx) {
    const r = this.bitmap.data[idx], g = this.bitmap.data[idx+1], b = this.bitmap.data[idx+2];
    // Bleu lien ~(0, 100, 220) ou (13, 110, 253)
    if (b > 150 && b > r + 60 && b > g + 30 && r < 100) {
      if(x<lminX)lminX=x; if(x>lmaxX)lmaxX=x; if(y<lminY)lminY=y; if(y>lmaxY)lmaxY=y;
    }
  });
  console.log('C3 blue link bounds:', lminX, lminY, '->', lmaxX, lmaxY);
})();
