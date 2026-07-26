const { Jimp } = require('jimp');

(async () => {
  const c4 = await Jimp.read('captures/raw/capture4.png');
  const W = c4.bitmap.width, H = c4.bitmap.height;
  console.log('C4:', W, 'x', H);

  // Scanner la bande basse (y=850 à fin) en cherchant du bleu Windows (#0078D4 ~ r=0,g=120,b=212)
  const bluePixels = [];
  c4.scan(0, 800, W, H-800, function(x, y, idx) {
    const r = this.bitmap.data[idx], g = this.bitmap.data[idx+1], b = this.bitmap.data[idx+2];
    // Bleu Windows classique ou similaire
    if (r < 50 && g > 80 && g < 160 && b > 160 && b < 250) {
      bluePixels.push({x,y,r,g,b});
    }
  });
  console.log('C4 blue pixels bottom count:', bluePixels.length);
  if (bluePixels.length > 0) {
    let minX=9999,maxX=0,minY=9999,maxY=0;
    bluePixels.forEach(p=>{
      if(p.x<minX)minX=p.x; if(p.x>maxX)maxX=p.x;
      if(p.y<minY)minY=p.y; if(p.y>maxY)maxY=p.y;
    });
    console.log('C4 blue zone:', minX, minY, '->', maxX, maxY);
    console.log('Sample blue pixels:', bluePixels.slice(0,5));
  }

  // Chercher aussi couleur fond du dialog Windows (gris très léger #F3F3F3 ~ 243,243,243)
  // La barre de commandes Windows en bas est souvent gris clair avec bouton bleu
  // Scanner les colonnes 600-900 lignes 900-984
  console.log('\nC4 row y=940:');
  for (let x = 500; x < 900; x += 20) {
    const y = 940;
    const idx = (y * W + x) * 4;
    console.log('  x='+x, c4.bitmap.data[idx], c4.bitmap.data[idx+1], c4.bitmap.data[idx+2]);
  }

  // C2: trouver exactement le bouton "Installer un module"
  // Le bouton est probablement un fond légèrement différent dans la zone dark
  // Scanner ligne par ligne sur la droite pour trouver un changement
  const c2 = await Jimp.read('captures/raw/capture2.png');
  const W2 = c2.bitmap.width;
  console.log('\nC2 row sampling at y=70:');
  for (let x2 = 1550; x2 < W2; x2 += 15) {
    const idx = (70 * W2 + x2) * 4;
    console.log('  x='+x2, c2.bitmap.data[idx], c2.bitmap.data[idx+1], c2.bitmap.data[idx+2]);
  }
})();
