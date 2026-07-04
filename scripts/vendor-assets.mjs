// Copies vendored front-end libraries from node_modules into public/vendor/js.
// Run after `npm ci` (or `npm install`) whenever a library version changes:
//   npm run vendor
// The copied *.min.js files are committed; node_modules is not. Runtime needs no npm.
import { copyFileSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const nm = join(root, 'node_modules');
const dest = join(root, 'public', 'vendor', 'js');

// target filename in public/vendor/js  ->  source path within node_modules
// (extended as more libraries are brought under npm management)
const MAP = {
  'jquery.min.js': 'jquery/dist/jquery.min.js',
};

for (const [target, src] of Object.entries(MAP)) {
  copyFileSync(join(nm, src), join(dest, target));
  const pkg = src.split('/')[0];
  const ver = JSON.parse(readFileSync(join(nm, pkg, 'package.json'), 'utf8')).version;
  console.log(`vendored ${pkg}@${ver} -> public/vendor/js/${target}`);
}
