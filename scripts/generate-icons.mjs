import { mkdir, copyFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const iconsDir = join(root, 'public', 'icons');
const svgPath = join(iconsDir, 'app-icon.svg');

async function generateWithSharp() {
    const sharp = (await import('sharp')).default;
    const svg = await import('node:fs/promises').then((fs) => fs.readFile(svgPath));

    await mkdir(iconsDir, { recursive: true });

    await sharp(svg).resize(192, 192).png().toFile(join(iconsDir, 'icon-192.png'));
    await sharp(svg).resize(512, 512).png().toFile(join(iconsDir, 'icon-512.png'));
    await sharp(svg).resize(32, 32).png().toFile(join(iconsDir, 'favicon-32.png'));
    await copyFile(join(iconsDir, 'favicon-32.png'), join(root, 'public', 'favicon.ico'));

    const badgeSvg = await import('node:fs/promises').then((fs) => fs.readFile(join(iconsDir, 'notification-badge.svg')));
    await sharp(badgeSvg).resize(96, 96).png().toFile(join(iconsDir, 'notification-badge.png'));

    console.log('Icons generated: icon-192.png, icon-512.png, notification-badge.png, favicon.ico');
}

try {
    await generateWithSharp();
} catch (error) {
    console.warn('sharp not installed - run: npm install --save-dev sharp && npm run icons');
    console.warn(error.message);
    process.exitCode = 0;
}
