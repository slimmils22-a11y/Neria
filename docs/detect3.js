const { Jimp } = require('jimp');

(async () => {
  const c4 = await Jimp.read('captures/raw/capture4.png');
  const W = c4.bitmap.width, H = c4.bitmap.height;

  // Chercher la limite basse du dialogue (changement de couleur de fond)
  // Scanner colonne x=400 de haut en bas
  console.log('C4 colonne x=400 (de y=700 à fin):');
  for (let y = 700; y < H; y += 10) {
    const idx = (y * W + 400) * 4;
    const r = c4.bitmap.data[idx], g = c4.bitmap.data[idx+1], b = c4.bitmap.data[idx+2];
    console.log('  y='+y, r, g, b);
  }

  // Trouver le bouton Ouvrir: chercher fond bleu #0078D4 avec tolérance large
  const ouvrir = [];
  c4.scan(0, 700, W, H-700, function(x, y, idx) {
    const r = this.bitmap.data[idx], g = this.bitmap.data[idx+1], b = this.bitmap.data[idx+2];
    // Bleu Win11 = #0067C0 ou variantes
    if (b > r + 60 && b > g + 30 && b > 120) {
      ouvrir.push({x,y,r,g,b});
    }
  });
  if (ouvrir.length > 0) {
    let mnX=9999,mxX=0,mnY=9999,mxY=0;
    ouvrir.forEach(p=>{if(p.x<mnX)mnX=p.x;if(p.x>mxX)mxX=p.x;if(p.y<mnY)mnY=p.y;if(p.y>mxY)mxY=p.y;});
    console.log('\nC4 blue-ish zone:', mnX, mnY, '->', mxX, mxY);
    console.log('Sample:', ouvrir.slice(0,5));
  } else {
    console.log('\nNo blue found in bottom half');
  }

  // C2: trouver transition fond clair→bouton foncé dans la zone header
  const c2 = await Jimp.read('captures/raw/capture2.png');
  const W2 = c2.bitmap.width;
  console.log('\nC2 colonne x=1750 (y=40 à 100):');
  for (let y2 = 40; y2 < 110; y2 += 3) {
    const idx = (y2 * W2 + 1750) * 4;
    console.log('  y='+y2, c2.bitmap.data[idx], c2.bitmap.data[idx+1], c2.bitmap.data[idx+2]);
  }
  // Chercher bouton distinct dans zone 1580-1875, y=40-99
  // Sommer les pixels foncés par colonne pour trouver le bouton
  console.log('\nC2 row y=68 (zone bouton):');
  for (let x2 = 1570; x2 < W2; x2 += 8) {
    const idx = (68 * W2 + x2) * 4;
    const r=c2.bitmap.data[idx], g=c2.bitmap.data[idx+1], b=c2.bitmap.data[idx+2];
    if (r < 100 && g < 100 && b < 100) process.stdout.write('D');
    else if (r > 200 && g > 200 && b > 200) process.stdout.write('W');
    else process.stdout.write('?');
  }
  console.log();
  console.log('D=dark(<100), W=white(>200), ?=other | x: 1570 to '+(W2-1));
})();
