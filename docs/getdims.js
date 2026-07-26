const { Jimp } = require('jimp');
const path = require('path');
Jimp.read(path.join(__dirname, 'captures', 'image-7.png')).then(i => {
  console.log(i.bitmap.width, i.bitmap.height);
});
