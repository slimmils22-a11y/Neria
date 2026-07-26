const { Jimp } = require('jimp');

(async () => {
  // C4: le dialogue se termine aux alentours de y=695 (teal après)
  // Bouton Ouvrir = zone y=640-700, droite du dialogue
  const c4 = await Jimp.read('captures/raw/capture4.png');
  const W = c4.bitmap.width, H = c4.bitmap.height;

  console.log('C4 map y=620-710 x=400-900 (W=light, .=mid, D=dark, T=teal):');
  for (let y = 620; y <= 710; y += 4) {
    let line = 'y='+String(y).padStart(3)+': ';
    for (let x = 400; x < 920; x += 8) {
      const idx = (y * W + x) * 4;
      const r=c4.bitmap.data[idx], g=c4.bitmap.data[idx+1], b=c4.bitmap.data[idx+2];
      const avg = (r+g+b)/3;
      // Teal = (105, 120, 123) range
      const isTeal = (r > 90 && r < 125 && g > 105 && g < 140 && b > 110 && b < 145 && g > r && b > r);
      if (isTeal) line += 'T';
      else if (avg < 60) line += 'D';
      else if (avg > 210) line += 'W';
      else if (avg > 140) line += 'L';
      else line += '.';
    }
    console.log(line);
  }

  // Chercher les pixels du bouton Windows "Ouvrir" (hover state = bleu vif)
  // ou fond blanc/gris clair entouré de pixels gris foncé (bordure bouton)
  console.log('\nC4 colonne x=700 y=600-720:');
  for (let y = 600; y <= 720; y += 5) {
    const idx = (y * W + 700) * 4;
    const r=c4.bitmap.data[idx], g=c4.bitmap.data[idx+1], b=c4.bitmap.data[idx+2];
    console.log('  y='+y+': '+r+','+g+','+b);
  }

  console.log('\nC4 colonne x=800 y=600-720:');
  for (let y = 600; y <= 720; y += 5) {
    const idx = (y * W + 800) * 4;
    const r=c4.bitmap.data[idx], g=c4.bitmap.data[idx+1], b=c4.bitmap.data[idx+2];
    console.log('  y='+y+': '+r+','+g+','+b);
  }
})();
