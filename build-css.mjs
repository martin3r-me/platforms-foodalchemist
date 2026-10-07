// Baut resources/dist/foodalchemist.css aus resources/css/foodalchemist-modul.css.
// Klassen-Erkennung: alle Modul-Views + PHP-Quellen (Klassen in Livewire-Komponenten).
// Aufruf: npm run build:css  (braucht tailwindcss, @tailwindcss/node, @tailwindcss/oxide)
//
// Plattform-Modus (demo/office): Die Datei wird auf FA-Seiten ZUSÄTZLICH zum Host-CSS geladen.
// Sie darf nur den FA-Inhalt gestalten, nie die Core-Hülle (Kopfzeile, Terminal) — Befund demo
// 2026-10-07: Modul-`.hidden` stand in derselben Ebene wie die Host-Utilities, aber später, und schlug
// `md:block`/`lg:inline` der Core-Kopfzeile; Palette (violet → Blau) und Geist-Schrift lagen auf :root.
// Deshalb wird das gebaute CSS hier nachbearbeitet:
//   1. Theme-Variablen (Palette, Schrift, Größen) gelten nur im FA-Inhalt (BEREICH), in der Core-Kopfzeile
//      (GRENZE) stehen wieder die Tailwind-Standardwerte — der Host hat kein eigenes @theme.
//   2. Utilities liegen in der Unterebene `utilities.fa`: bei gleicher Klasse gewinnt immer die
//      Host-Utility (richtige Reihenfolge `hidden md:block`), das Modul füllt nur Lücken.
//   3. Ungeschichtete Regeln ohne FA-Bezug (button, h1–h3, Labels, Fokus …) gelten nur von BEREICH bis
//      GRENZE (`@scope`); `html, body` wird zur Schrift auf BEREICH.
import { compile, optimize } from '@tailwindcss/node';
import { Scanner } from '@tailwindcss/oxide';
import { readFileSync, writeFileSync, mkdirSync } from 'fs';
import { createRequire } from 'module';
import { dirname, resolve } from 'path';
import { fileURLToPath } from 'url';

// Der Inhaltsbereich im Core-Layout (platform::layouts.app: <main> → Scroll-Container mit {{ $slot }}).
// Das Core-Terminal ist Geschwister davon und bleibt damit draußen.
const BEREICH = 'main>.order-first';
// Die Core-Kopfzeile innerhalb einer FA-Seite (shell/page-navbar.blade.php umschließt sie damit).
const GRENZE = '[data-fa-core]';

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
const roh = optimize(compiler.build(kandidaten), { minify: true }).code;

/** Zerlegt CSS in Blöcke der obersten Ebene: [{kopf, inhalt}] bzw. {anweisung} für `@layer a, b;`. */
function bloecke(css) {
  const out = [];
  let i = 0;
  while (i < css.length) {
    if (css.startsWith('/*', i)) { i = css.indexOf('*/', i) + 2; continue; }
    if (/\s/.test(css[i])) { i++; continue; }
    let j = i, tiefe = 0, kopfEnde = -1, str = null;
    for (; j < css.length; j++) {
      const c = css[j];
      if (str) { if (c === '\\') j++; else if (c === str) str = null; continue; }
      if (c === '"' || c === "'") { str = c; continue; }
      if (c === ';' && tiefe === 0) break;
      if (c === '{') { if (tiefe === 0) kopfEnde = j; tiefe++; }
      else if (c === '}') { tiefe--; if (tiefe === 0) break; }
    }
    if (kopfEnde < 0) out.push({ anweisung: css.slice(i, j + 1) });
    else out.push({ kopf: css.slice(i, kopfEnde).trim(), inhalt: css.slice(kopfEnde + 1, j) });
    i = j + 1;
  }
  return out;
}

const variablen = (text) => {
  const m = new Map();
  for (const [, k, v] of text.matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)) m.set(k, v.replace(/\s+/g, ' ').trim());
  return m;
};

// Tailwind-Standardwerte (der Host baut ohne eigenes @theme) für die Rückstellung an der GRENZE.
const require = createRequire(import.meta.url);
const standard = variablen(readFileSync(require.resolve('tailwindcss/theme.css'), 'utf8'));

const faBezug = (sel) => /fa-|data-fa|x-cloak/.test(sel) || sel === ':root';
const teile = [];
const scoped = [];
let theme = 0, utilities = 0;

for (const b of bloecke(roh)) {
  if (b.anweisung) { teile.push(b.anweisung); continue; }
  const { kopf, inhalt } = b;

  if (kopf === '@layer theme') {
    const innen = bloecke(inhalt);
    if (innen.length !== 1 || innen[0].kopf !== ':root,:host') throw new Error(`Theme-Ebene unerwartet: ${innen.map((x) => x.kopf).join(' | ')}`);
    const fa = variablen(innen[0].inhalt + ';');
    const zurueck = [...fa].filter(([k, v]) => standard.has(k) && standard.get(k) !== v).map(([k]) => `${k}:${standard.get(k)}`);
    teile.push(`@layer theme{${BEREICH}{${innen[0].inhalt}}${GRENZE}{${zurueck.join(';')}}}`);
    // Schrift wird vererbt als fertiger Wert — an der Grenze neu aus der zurückgestellten Variable ziehen
    teile.push(`${GRENZE}{font-family:var(--font-sans);-webkit-font-smoothing:auto}`);
    theme++;
  } else if (kopf === '@layer utilities') {
    teile.push(`@layer utilities{@layer fa{${inhalt}}}`);
    utilities++;
  } else if (kopf.startsWith('@layer') || kopf.startsWith('@property') || kopf.startsWith('@keyframes')) {
    teile.push(`${kopf}{${inhalt}}`);
  } else if (kopf === 'html,body') {
    scoped.push(`:scope{${inhalt}}`);
  } else if (kopf.startsWith('@')) {
    // @media/@supports: FA-eigen bleibt global, alles andere in den Bereich
    const sels = bloecke(inhalt).map((x) => x.kopf ?? '');
    (sels.every(faBezug) ? teile : scoped).push(`${kopf}{${inhalt}}`);
  } else {
    (faBezug(kopf) ? teile : scoped).push(`${kopf}{${inhalt}}`);
  }
}
if (theme !== 1 || utilities !== 1) throw new Error(`erwartet je 1 Theme-/Utility-Ebene, gefunden ${theme}/${utilities}`);
teile.push(`@scope (${BEREICH}) to (${GRENZE}){${scoped.join('')}}`);

const css = teile.join('');
mkdirSync(dirname(ausgang), { recursive: true });
writeFileSync(ausgang, `/* foodalchemist.css — generiert von build-css.mjs, nicht von Hand ändern */\n${css}`);
console.log(`foodalchemist.css: ${kandidaten.length} Klassen, ${(css.length / 1024).toFixed(0)} KB, ${scoped.length} Regeln nur im FA-Bereich`);
