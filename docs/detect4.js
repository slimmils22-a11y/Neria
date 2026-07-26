const { Jimp } = require('jimp');

(async () => {
  // C2: scanner précisément la zone du bouton
  const c2 = await Jimp.read('captures/raw/capture2.png');
  const W2 = c2.bitmap.width;

  // Lignes 40-120, chercher des rectangles clairs sur fond sombre
  // (le bouton "Installer un module" est probablement fond sombre, texte blanc)
  console.log('C2 map 40-120 x 1300-1875 (D=dark, W=white, .=mid):');
  for (let y = 40; y < 120; y += 4) {
    let line = 'y='+String(y).padStart(3)+': ';
    for (let x = 1300; x < W2; x += 10) {
      const idx = (y * W2 + x) * 4;
      const r=c2.bitmap.data[idx], g=c2.bitmap.data[idx+1], b=c2.bitmap.data[idx+2];
      const avg = (r+g+b)/3;
      if (avg < 80) line += 'D';
      else if (avg > 200) line += 'W';
      else line += '.';
    }
    console.log(line + ' (x: 1300..1875)');
  }

  // C4: détecter le bouton "Ouvrir" dans Windows 11 file picker
  // Le bouton Ouvrir est blanc avec fond légèrement bleuté OU fond gris clair avec texte
  const c4 = await Jimp.read('captures/raw/capture4.png');
  const W = c4.bitmap.width, H = c4.bitmap.height;

  // La boîte de dialogue Windows ne dépasse pas ~750px de large
  // Chercher bouton dans la zone bas droite du dialogue (x=500-900, y=880-984)
  console.log('\nC4 map y=860-984 x=500-900 (W=light, D=dark, .=mid):');
  for (let y = 860; y < H; y += 6) {
    let line = 'y='+String(y).padStart(3)+': ';
    for (let x = 500; x < 950; x += 8) {
      const idx = (y * W + x) * 4;
      const r=c4.bitmap.data[idx], g=c4.bitmap.data[idx+1], b=c4.bitmap.data[idx+2];
      const avg = (r+g+b)/3;
      if (avg < 60) line += 'D';
      else if (avg > 200) line += 'W';
      else if (avg > 130) line += 'L';
      else line += '.';
    }
    console.log(line);
  }
})();
