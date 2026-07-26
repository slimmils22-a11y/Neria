const { Jimp } = require('jimp');

(async () => {
  // C1: étendue de l'icône zip (jaune-brun)
  const c1 = await Jimp.read('captures/raw/capture1.png');
  let minX=9999, maxX=0, minY=9999, maxY=0;
  c1.scan(0, 350, 250, 250, function(x, y, idx) {
    const r = this.bitmap.data[idx], g = this.bitmap.data[idx+1], b = this.bitmap.data[idx+2];
    if (r > 160 && g > 100 && g < 185 && b < 90) {
      if(x<minX) minX=x; if(x>maxX) maxX=x; if(y<minY) minY=y; if(y>maxY) maxY=y;
    }
  });
  console.log('C1 zip icon bounds:', minX, minY, '->', maxX, maxY, '|', c1.bitmap.width+'x'+c1.bitmap.height);

  // C2: bouton "Installer un module" (fond sombre >1400px de largeur)
  const c2 = await Jimp.read('captures/raw/capture2.png');
  let b2minX=9999, b2maxX=0, b2minY=9999, b2maxY=0;
  c2.scan(1400, 40, 450, 60, function(x, y, idx) {
    const r = this.bitmap.data[idx], g = this.bitmap.data[idx+1], b = this.bitmap.data[idx+2];
    if (r < 80 && g < 80 && b < 80) {
      if(x<b2minX) b2minX=x; if(x>b2maxX) b2maxX=x; if(y<b2minY) b2minY=y; if(y>b2maxY) b2maxY=y;
    }
  });
  console.log('C2 btn dark bounds:', b2minX, b2minY, '->', b2maxX, b2maxY, '|', c2.bitmap.width+'x'+c2.bitmap.height);

  // C4: zip icon dans le file picker
  const c4 = await Jimp.read('captures/raw/capture4.png');
  let z4minX=9999, z4maxX=0, z4minY=9999, z4maxY=0;
  c4.scan(80, 80, 700, 80, function(x, y, idx) {
    const r = this.bitmap.data[idx], g = this.bitmap.data[idx+1], b = this.bitmap.data[idx+2];
    if (r > 160 && g > 100 && g < 185 && b < 90) {
      if(x<z4minX) z4minX=x; if(x>z4maxX) z4maxX=x; if(y<z4minY) z4minY=y; if(y>z4maxY) z4maxY=y;
    }
  });
  console.log('C4 zip row bounds:', z4minX, z4minY, '->', z4maxX, z4maxY);

  // C4: bouton Ouvrir (bleu Windows) en bas à droite
  let ouvrirMinX=9999, ouvrirMaxX=0, ouvrirMinY=9999, ouvrirMaxY=0;
  // Scanner toute la zone basse du dialogue
  c4.scan(400, 860, 500, 120, function(x, y, idx) {
    const r = this.bitmap.data[idx], g = this.bitmap.data[idx+1], b = this.bitmap.data[idx+2];
    if (r < 90 && g > 80 && g < 190 && b > 150) {
      if(x<ouvrirMinX) ouvrirMinX=x; if(x>ouvrirMaxX) ouvrirMaxX=x; if(y<ouvrirMinY) ouvrirMinY=y; if(y>ouvrirMaxY) ouvrirMaxY=y;
    }
  });
  console.log('C4 Ouvrir bounds:', ouvrirMinX, ouvrirMinY, '->', ouvrirMaxX, ouvrirMaxY, '|', c4.bitmap.width+'x'+c4.bitmap.height);

  // Couleur dominante en bas à droite de C4 (pour repérer le bouton)
  const samples = [];
  for (let sy = 880; sy < 980; sy += 5) {
    for (let sx = 580; sx < 900; sx += 5) {
      const idx2 = (sy * c4.bitmap.width + sx) * 4;
      const r = c4.bitmap.data[idx2], g = c4.bitmap.data[idx2+1], b = c4.bitmap.data[idx2+2];
      if (r !== g || g !== b) samples.push({ x:sx, y:sy, r, g, b });
    }
  }
  console.log('C4 colored pixels bottom:', samples.slice(0,8));
})();
