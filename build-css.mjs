// Baut resources/dist/foodalchemist.css aus resources/css/foodalchemist-modul.css.
// Klassen-Erkennung: alle Modul-Views + PHP-Quellen (Klassen in Livewire-Komponenten).
// Aufruf: npm run build:css  (braucht tailwindcss, @tailwindcss/node, @tailwindcss/oxide)
import { compile, optimize } from '@tailwindcss/node';
import { Scanner } from '@tailwindcss/oxide';
import { readFileSync, writeFileSync, mkdirSync } from 'fs';
import { dirname, resolve } from 'path';
import { fileURLToPath } from 'url';

const root = dirname(fileURLToPath(import.meta.url));
const eingang = resolve(root, 'resources/css/foodalchemist-modul.css');
const ausgang = resolve(root, 'resources/dist/foodalchemist.css');

const compiler = await compile(readFileSync(eingang, 'utf8'), {
  base: dirname(eingang),
  onDependency: () => {},
});

const scanner = new Scanner({
  sources: [
    { base: resolve(root, 'resources/views'), pattern: '**/*.blade.php', negated: false },
    { base: resolve(root, 'src'), pattern: '**/*.php', negated: false },
  ],
});
const kandidaten = scanner.scan();
const css = optimize(compiler.build(kandidaten), { minify: true }).code;

mkdirSync(dirname(ausgang), { recursive: true });
writeFileSync(ausgang, `/* foodalchemist.css — generiert von build-css.mjs, nicht von Hand ändern */\n${css}`);
console.log(`foodalchemist.css: ${kandidaten.length} Klassen, ${(css.length / 1024).toFixed(0)} KB`);
