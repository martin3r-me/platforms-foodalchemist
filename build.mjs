import { build } from 'esbuild';
import { readFileSync, writeFileSync } from 'fs';
import { createHash } from 'crypto';

const pkg = JSON.parse(readFileSync('package.json', 'utf8'));
const version = pkg.version || '0.0.0';

const bundles = [
  {
    entryPoints: ['resources/js/pairing-netz/index.js'],
    globalName: 'PlatformFoodAlchemistPairingNetz',
    outfile: 'resources/dist/foodalchemist-pairing-netz.iife.js',
    banner: `/* foodalchemist-pairing-netz v${version} | MIT */`,
  },
];

const manifest = {};

for (const cfg of bundles) {
  await build({
    entryPoints: cfg.entryPoints,
    bundle: true,
    format: 'iife',
    globalName: cfg.globalName,
    outfile: cfg.outfile,
    minify: true,
    sourcemap: false,
    target: ['es2020'],
    loader: cfg.loader || {},
    define: {
      'process.env.NODE_ENV': '"production"',
    },
    banner: {
      js: cfg.banner,
    },
  });

  const bundle = readFileSync(cfg.outfile);
  const hash = createHash('md5').update(bundle).digest('hex').slice(0, 8);
  const filename = cfg.outfile.split('/').pop();
  manifest[filename] = hash;

  console.log(`Built: ${cfg.outfile} (hash: ${hash})`);
}

writeFileSync('resources/dist/manifest.json', JSON.stringify(manifest, null, 2) + '\n');
console.log('Manifest updated.');
