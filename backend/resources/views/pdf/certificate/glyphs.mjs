// Regenerates glyphs.json: the certificate's fixed Arabic text (Basmala, the seal) as SVG outlines.
// dompdf cannot shape Aref Ruqaa or Reem Kufi (they need GSUB/GPOS contextual forms), so HarfBuzz shapes them here once.
// Each glyph is its own <path> (merged into one, overlapping contours cancel and the dots vanish), in page pixels with every
// transform baked in and numbers separated by spaces
// (dompdf's SVG parser drops paths written like "Q-12,54").
// Run from a folder with harfbuzzjs installed (npm i --no-save harfbuzzjs):
//   node backend/resources/views/pdf/certificate/glyphs.mjs
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import * as hb from 'harfbuzzjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const fonts = path.resolve(here, '../../../fonts');
const load = (file) => {
  const face = new hb.Face(new hb.Blob(fs.readFileSync(path.join(fonts, file)).buffer));
  return { font: new hb.Font(face), upem: face.upem };
};
const r2 = (n) => (Math.round(n * 100) / 100).toString();

function shape({ font, upem }, text, size) {
  const buffer = new hb.Buffer();
  buffer.addText(text);
  buffer.guessSegmentProperties();
  hb.shape(font, buffer);
  const k = size / upem;
  let pen = 0;
  const glyphs = [];
  for (const g of buffer.getGlyphInfosAndPositions()) {
    glyphs.push({ d: font.glyphToPath(g.codepoint), x: (pen + (g.xOffset || 0)) * k, y: (g.yOffset || 0) * k, pen: pen * k, adv: g.xAdvance * k });
    pen += g.xAdvance;
  }
  return { glyphs, width: pen * k, k };
}

// Rewrites a glyph path (font units, y up; commands M L Q C Z) through point transform fn.
function bake(d, fn) {
  const out = [];
  for (const [, cmd, args] of d.matchAll(/([MLQCZ])([^MLQCZ]*)/g)) {
    const nums = (args.match(/-?\d*\.?\d+(?:e-?\d+)?/g) || []).map(Number);
    const pts = [];
    for (let i = 0; i < nums.length; i += 2) pts.push(...fn(nums[i], nums[i + 1]).map(r2));
    out.push(cmd + (pts.length ? ' ' + pts.join(' ') : ''));
  }
  return out.join(' ');
}

// A straight run centred on x = 0 with the baseline on y = 0.
const run = (s) => ({
  w: Number(r2(s.width)),
  svg: s.glyphs.filter((g) => g.d).map((g) => `<path d="${bake(g.d, (x, y) => [g.x + x * s.k - s.width / 2, -(g.y + y * s.k)])}"/>`).join(''),
});

// Along the top of a circle of radius R centred on 0,0, letters standing outward and reading right to left.
const arc = (s, R) => {
  const total = s.width / R;
  return s.glyphs.filter((g) => g.d).map((g) => {
    const th = -total / 2 + (g.pen + g.adv / 2) / R; // the base glyph's centre sets the angle
    const cos = Math.cos(th), sin = Math.sin(th);
    const ox = g.x - g.pen - g.adv / 2; // offset from that centre (marks carry their GPOS offset)
    return `<path d="${bake(g.d, (x, y) => {
      const lx = ox + x * s.k, ly = -(g.y + y * s.k) - R;
      return [lx * cos - ly * sin, lx * sin + ly * cos];
    })}"/>`;
  }).join('');
};

const ruqaa = load('ArefRuqaa-Regular.ttf');
const ruqaaBold = load('ArefRuqaa-Bold.ttf');
const kufi = load('ReemKufi-Regular.ttf');

// Arabic-Indic digits for the Hijri year on the seal, composed at render time (advance w; outline with its left edge at 0).
const digits = {};
for (const ch of '٠١٢٣٤٥٦٧٨٩') {
  const s = shape(kufi, ch, 8);
  digits[ch] = { w: Number(r2(s.width)), svg: s.glyphs.filter((g) => g.d).map((g) => `<path d="${bake(g.d, (x, y) => [g.x + x * s.k, -(g.y + y * s.k)])}"/>`).join('') };
}

const out = {
  basmala: run(shape(ruqaa, 'بسم الله الرحمن الرحيم', 23)),
  sar: run(shape(ruqaaBold, 'سار', 27)),
  seal_arc: { svg: arc(shape(kufi, 'هيئة التعليم الديني', 9), 43) },
  digits,
};
fs.writeFileSync(path.join(here, 'glyphs.json'), JSON.stringify(out));
console.log('glyphs.json written', JSON.stringify(out).length, 'bytes');
