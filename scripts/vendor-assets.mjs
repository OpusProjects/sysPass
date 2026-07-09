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
// Note: jquery-ui appears in package-lock.json but is deliberately absent here and from
// package.json — it is an optionalDependencies entry of @selectize/selectize (drag_drop
// plugin support), resolved into the lock like any transitive dep (`npm ls jquery-ui`).
// It is never vendored or served; do not "clean" it from the lock.
const MAP = {
  'clipboard.min.js': 'clipboard/dist/clipboard.min.js',
  'jquery.min.js': 'jquery/dist/jquery.min.js',
  'jsencrypt.min.js': 'jsencrypt/bin/jsencrypt.min.js',
  'selectize.min.js': '@selectize/selectize/dist/js/selectize.min.js',
  'moment.min.js': 'moment/min/moment-with-locales.min.js',
  // with-data 10-year rolling range — matches the data-bearing build we shipped
  'moment-timezone.min.js': 'moment-timezone/builds/moment-timezone-with-data-10-year-range.min.js',
  'toastr.min.js': 'toastr/build/toastr.min.js',
  'jquery.magnific-popup.min.js': 'magnific-popup/dist/jquery.magnific-popup.min.js',
  'spark-md5.min.js': 'spark-md5/spark-md5.min.js',
  'zxcvbn.min.js': 'zxcvbn/dist/zxcvbn.js',
};

for (const [target, src] of Object.entries(MAP)) {
  copyFileSync(join(nm, src), join(dest, target));
  const parts = src.split('/');
  const pkg = parts[0].startsWith('@') ? `${parts[0]}/${parts[1]}` : parts[0];
  const ver = JSON.parse(readFileSync(join(nm, pkg, 'package.json'), 'utf8')).version;
  console.log(`vendored ${pkg}@${ver} -> public/vendor/js/${target}`);
}
