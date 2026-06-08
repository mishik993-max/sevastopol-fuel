const fs = require('fs');
const path = require('path');

const src = path.join(__dirname, '..', 'public', 'build', 'sw.js');
const dest = path.join(__dirname, '..', 'public', 'sw.js');

if (!fs.existsSync(src)) {
    console.error('copy-sw: public/build/sw.js not found- run vite build first');
    process.exit(1);
}

fs.copyFileSync(src, dest);
console.log('copy-sw: public/sw.js updated');
