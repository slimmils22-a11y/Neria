const { Jimp } = require("jimp");
const fs = require("fs");
const path = require("path");

const dir = path.join(__dirname, "captures");
const files = fs.readdirSync(dir).filter(f => f.toLowerCase().endsWith(".png"));

(async () => {
  let count = 0;
  for (const file of files) {
    const fp = path.join(dir, file);
    try {
      const img = await Jimp.read(fp);
      const w = img.bitmap.width;
      const h = img.bitmap.height;

      // Zone filigrane : 430px depuis la droite, 85px depuis le bas
      const zoneH = 85;
      const zoneX = Math.max(0, w - 430);
      const y0 = Math.max(0, h - zoneH);

      // Remplissage colonne par colonne : chaque colonne prend la couleur
      // du pixel situé 8px au-dessus de la zone
      for (let x = zoneX; x < w; x++) {
        const sampleY = Math.max(0, y0 - 8);
        const bgColor = img.getPixelColor(x, sampleY);
        for (let y = y0; y < h; y++) {
          img.setPixelColor(bgColor, x, y);
        }
      }

      await img.write(fp);
      count++;
      if (count % 10 === 0) process.stdout.write(`${count}/${files.length}...\n`);
    } catch (e) {
      console.error(`Erreur sur ${file}: ${e.message}`);
    }
  }
  console.log(`✓ Terminé — ${count} images nettoyées.`);
})();
