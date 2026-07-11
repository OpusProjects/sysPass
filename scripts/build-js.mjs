import { build } from 'esbuild';
import { copyFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const dest = join(root, 'public/vendor/js');

const result = await build({
  entryPoints: [join(root, 'scripts/vendor-entry.mjs')],
  bundle: true,
  minify: true,
  outfile: join(dest, 'vendor.bundle.min.js'),
  format: 'iife',
  target: ['es2020'],
  alias: {
    'jquery': join(root, 'node_modules/jquery/dist/jquery.js'),
    'moment': join(root, 'node_modules/moment/min/moment-with-locales.js'),
  },
  metafile: true,
});

// zxcvbn is lazy-loaded by zxcvbn-async.min.js at runtime — keep it separate.
copyFileSync(
  join(root, 'node_modules/zxcvbn/dist/zxcvbn.js'),
  join(dest, 'zxcvbn.min.js')
);

const out = Object.values(result.metafile.outputs)[0];
console.log(`vendor.bundle.min.js  ${(out.bytes / 1024).toFixed(0)} KB`);
console.log(`zxcvbn.min.js         ${(821792 / 1024).toFixed(0)} KB (copied)`);
