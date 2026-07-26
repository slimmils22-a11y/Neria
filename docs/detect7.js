const { Jimp } = require('jimp');

(async () => {
  // C4: Localiser le texte "Glissez l'archive..." dans la modale PS (zone droite basse)
  // La modale PS est visible à droite / en dessous du dialogue Windows
  const c4 = await Jimp.read('captures/raw/capture4.png');
  const W = c4.bitmap.width, H = c4.bitmap.height;

  // Le texte bleu "sélectionnez un fichier" dans la modale PS
  // Chercher pixels bleus dans la zone droite (x>900) et basse (y>400)
  let lminX=9999, lmaxX=0, lminY=9999, lmaxY=0;
  c4.scan(900, 400, W-900, H-400, function(x, y, idx) {
    const r = this.bitmap.data[idx], g = this.bitmap.data[idx+1], b = this.bitmap.data[idx+2];
    if (b > 150 && b > r + 60 && b > g + 30 && r < 100) {
      if(x<lminX)lminX=x; if(x>lmaxX)lmaxX=x; if(y<lminY)lminY=y; if(y>lmaxY)lmaxY=y;
    }
  });
  console.log('C4 lien bleu PS modal:', lminX, lminY, '->', lmaxX, lmaxY);

  // Chercher aussi le fond blanc de la modale PS (à droite, y>400)
  // La modale a un fond blanc avec un X de fermeture
  // Trouver la limite gauche du fond blanc de la modale
  let modalLeftX = 9999;
  for (let y = 450; y < 650; y += 5) {
    for (let x = 800; x < W; x += 2) {
      const idx = (y * W + x) * 4;
      const r=c4.bitmap.data[idx], g=c4.bitmap.data[idx+1], b=c4.bitmap.data[idx+2];
      if (r > 240 && g > 240 && b > 240) {
        if (x < modalLeftX) modalLeftX = x;
        break;
      }
    }
  }
  console.log('C4 modale PS - limite gauche approximative:', modalLeftX);

  // Carte de la zone y=680-800 pour voir si la modale PS est là
  console.log('\nC4 map y=680-780 x=900-1900 (W=white/modal, .=mid):');
  for (let y = 680; y < 780; y += 8) {
    let line = 'y='+y+': ';
    for (let x = 900; x < W; x += 15) {
      const idx = (y * W + x) * 4;
      const r=c4.bitmap.data[idx], g=c4.bitmap.data[idx+1], b=c4.bitmap.data[idx+2];
      const avg=(r+g+b)/3;
      if (avg > 240) line += 'W';
      else if (avg < 60) line += 'D';
      else line += '.';
    }
    console.log(line);
  }
})();
