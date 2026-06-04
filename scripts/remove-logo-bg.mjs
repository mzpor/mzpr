import sharp from 'sharp';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = join(__dirname, '..');
const input = join(root, 'images', 'logo.png');
const output = join(root, 'images', 'logo.png');
const backup = join(root, 'images', 'logo-with-bg.png');

function dist(rgb, bg) {
  const dr = rgb[0] - bg[0];
  const dg = rgb[1] - bg[1];
  const db = rgb[2] - bg[2];
  return Math.sqrt(dr * dr + dg * dg + db * db);
}

function lum(r, g, b) {
  return 0.299 * r + 0.587 * g + 0.114 * b;
}

const img = sharp(input);
const { data, info } = await img
  .ensureAlpha()
  .raw()
  .toBuffer({ resolveWithObject: true });

const { width, height, channels } = info;
const out = Buffer.from(data);

const sample = (x, y) => {
  const i = (y * width + x) * channels;
  return [out[i], out[i + 1], out[i + 2]];
};

const corners = [
  sample(2, 2),
  sample(width - 3, 2),
  sample(2, height - 3),
  sample(width - 3, height - 3),
];

const bg = corners.reduce((a, c) => [a[0] + c[0], a[1] + c[1], a[2] + c[2]], [0, 0, 0]).map((v) => v / 4);

for (let y = 0; y < height; y++) {
  for (let x = 0; x < width; x++) {
    const i = (y * width + x) * channels;
    const r = out[i];
    const g = out[i + 1];
    const b = out[i + 2];
    const l = lum(r, g, b);
    const d = dist([r, g, b], bg);

    let alpha = 255;

    if (l < 18) {
      alpha = 0;
    } else if (d < 28) {
      alpha = Math.round((d / 28) * 255);
    } else if (l < 55 && d < 55) {
      const t = Math.min(1, (l - 18) / 37);
      alpha = Math.round(t * 255);
    }

    out[i + 3] = alpha;
  }
}

await sharp(input).toFile(backup);

await sharp(out, { raw: { width, height, channels: 4 } })
  .png({ compressionLevel: 9 })
  .toFile(output);

console.log('Saved transparent logo:', output);
console.log('Backup with background:', backup);
