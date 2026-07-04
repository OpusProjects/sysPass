// Minifies the material-blue theme CSS sources into their committed *.min.css
// counterparts, using esbuild's CSS minifier. Run whenever a theme *.css changes:
//   npm run build:css
// The concatenating resource/css route serves these pre-minified files as-is, so
// keeping them built (never hand-copied) prevents the min files from drifting out
// of sync with their source (as styles.min.css had).
import { transform } from 'esbuild';
import { readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const cssDir = join(root, 'public', 'themes', 'material-blue', 'css');

// Every *.css that isn't already a *.min.css is a source with a built counterpart.
const sources = readdirSync(cssDir).filter((f) => f.endsWith('.css') && !f.endsWith('.min.css'));

for (const src of sources) {
  const target = src.replace(/\.css$/, '.min.css');
  const code = readFileSync(join(cssDir, src), 'utf8');
  const out = await transform(code, { loader: 'css', minify: true });
  writeFileSync(join(cssDir, target), out.code);
  console.log(`minified ${src} -> ${target} (${code.length} -> ${out.code.length} bytes)`);
}
