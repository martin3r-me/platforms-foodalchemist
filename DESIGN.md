# Food Alchemist — Design Brief

## Personality: Linear / Raycast

Dieses Modul demonstriert, wie ein eigenes visuelles Design-System innerhalb der Platform-Shell funktioniert. Die Page-Shell-Komponenten (`x-ui-page`, `x-ui-page-navbar`, `x-ui-page-actionbar`, `x-ui-page-container`, `x-ui-page-sidebar`, `x-ui-sidebar-list`, `x-ui-sidebar-item`) bleiben unverändert. Alles im Content-Bereich ist custom.

Vorbild: Linear, Raycast, Vercel — clean, modern, confident.

---

## Farben

| Token | Wert | Verwendung |
|-------|------|------------|
| Accent Gradient | `from-violet-500 to-indigo-500` | Primäre CTAs, aktive Elemente |
| Subtle Gradient BG | `from-violet-500/5 to-indigo-500/5` | Card-Hintergründe, Hover-States |
| Surface | `white/60` (light) / `white/5` (dark) | Card-Backgrounds (frosted glass) |
| Border | `white/20` oder `black/5` | Sehr subtil, kaum sichtbar |
| Text Primary | `gray-900` / `gray-100` | Überschriften |
| Text Secondary | `gray-500` / `gray-400` | Beschreibungen, Labels |

## Typografie

- Überschriften: `font-medium tracking-tight` (nicht bold — eher confident-clean)
- Body: `text-sm text-gray-500`
- Labels/Captions: `text-xs font-medium uppercase tracking-wider text-gray-400`
- Zahlen/Stats: `text-3xl font-semibold tracking-tight`

## Borders & Shadows

- Borders: `border border-white/10` (dark) oder `border border-black/5` (light) — fast unsichtbar
- Shadows: `shadow-sm shadow-black/5` — soft, layered
- Kein harter `border-gray-200` Look

## Cards (Frosted Glass)

```html
<div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-white/20 dark:border-white/10 shadow-sm shadow-black/5 p-5">
```

Effekt: Leicht transparent, weich, schwebt über dem Hintergrund.

## Buttons

**Primary (Gradient):**
```html
<button class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-gradient-to-r from-violet-500 to-indigo-500 rounded-lg shadow-sm shadow-violet-500/25 hover:shadow-md hover:shadow-violet-500/30 transition-all duration-150">
```

**Secondary (Ghost):**
```html
<button class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-600 dark:text-gray-300 bg-white/60 dark:bg-white/5 backdrop-blur-sm border border-black/5 dark:border-white/10 rounded-lg hover:bg-white/80 dark:hover:bg-white/10 transition-all duration-150">
```

## Inputs

Minimal, borderless mit subtle background:
```html
<input class="w-full px-3 py-2 text-sm bg-black/[0.03] dark:bg-white/5 rounded-lg border-0 placeholder-gray-400 focus:ring-2 focus:ring-violet-500/20 focus:bg-white dark:focus:bg-white/10 transition-all duration-150" />
```

## Stat Cards

Gradient-Accent am oberen Rand:
```html
<div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-white/20 dark:border-white/10 shadow-sm shadow-black/5 p-5">
    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/50 to-transparent"></div>
    <!-- content -->
</div>
```

## Interaktion

- Hover: `transition-all duration-150`
- Cards hover: Subtle lift `hover:-translate-y-0.5 hover:shadow-md`
- Buttons hover: Shadow intensiviert sich
- Focus: `ring-2 ring-violet-500/20` (soft glow, kein harter outline)

## Regeln

1. **Keine `x-ui-*` Komponenten im Content-Bereich** (außer Page-Shell)
2. **Nur Tailwind-Klassen** für Styling
3. **Dark-Mode immer mitdenken** (`dark:` Variants)
4. **Gradient-Accents sparsam** — als Highlight, nicht überall
5. **Whitespace großzügig** — Content atmet
