# Spec 28 — Editor-Design-Rollout (Rezept-Editor → alle Editoren)

**Stand:** 2026-07-31 · **Status:** Planung · **Anlass:** Der Rezept-Editor-Umbau (`7ec8c99` + `eb6804a`)
hat einen neuen Editor-Standard gesetzt. Er lebt bisher nur in einer Datei. Ziel: EIN Editor-Gefühl
über alle Datensatz-Editoren des FA — ohne das Dark-CSS und die Tab-Mechanik zu duplizieren.

**Nicht-Ziel:** plattformweiter Dark Mode (offene Martin-Entscheidung, README §159). Der Editor bleibt
eine gescopete Insel (`.fa-editor-panel`) auf der hellen Shell.

---

## 1 · Ist-Stand (kartiert 2026-07-31)

Der Baustein `resources/views/components/modal.blade.php` trägt schon die halbe Miete:
`fullscreen` · `darkCanvas` (raw-CSS-Block, `.fa-editor-panel`-gescopet) · `titleName` (Akzent-Chip) ·
`tabInit`/`x-slot:tabs` · `x-slot:actions` · `x-slot:kpiHeader` · `closeVia` · `modal.closed`-Vertrag.

| Editor | Datei | Z. | fullscreen | dark | KPI-Kopf | sticky Tabs | Titel-Chip | Reife |
|---|---|---:|---|---|---|---|---|---|
| **Rezept** (Referenz) | `livewire/recipes/recipe-modal` | 573 | ✅ `!$neu` | ✅ | ✅ semantisch | ✅ | ✅ | **100 %** |
| Gericht/VK | `livewire/verkauf/vk-modal` | 752 | ✅ | ✅ | ⚠️ alte `kpiTile` | ✅ eigene Kopie | ❌ | ~60 % |
| Concepter | `livewire/concepter/editor` | 1234 | ✅ | ✅ | ⚠️ alte Kacheln | ✅ eigene Kopie | ❌ | ~55 % |
| **Grundprodukt** | `livewire/gps/gp-modal` | 357 | ❌ `max-w-4xl` | ❌ hell | ❌ | ⚠️ Tabs ohne sticky | ❌ | ~20 % |
| **Lieferantenartikel** | `livewire/suppliers/item-modal` | 275 | ❌ | ❌ hell | ❌ | ❌ 8 Sektionen am Stück | ❌ | ~10 % |
| Zutaten (standalone) | `livewire/recipes/ingredient-editor` | — | ❌ `max-w-[100rem]` | ❌ | ❌ | n/a | ⚠️ Name im Titel-String | ~15 % |
| Produktionsauftrag | `livewire/produktion/editor` | 175 | ❌ | ❌ | ❌ | ❌ | ❌ | Dialog-Klasse |
| Lieferant / Geschirr / Foodbook / Angebot / Generatoren / Voice / Template / Platzhalter / Pairing-Netz | div. | — | — | — | — | — | — | Dialog-Klasse |

**Duplikate heute:** sticky Tab-Leiste 3× copy-paste (Rezept/VK/Concepter) + 1 Sonderform (GP);
KPI-Kachel-CSS 1× im Rezept-Editor, alte `kpiTile`-Token in VK/Concepter; Dark-Selektoren wachsen
pro neuer Fläche in `modal.blade.php` mit.

---

## 2 · Klassen-Regel (was bekommt welche Behandlung)

Entscheidend ist **nicht** die Zeilenzahl, sondern was der Nutzer tut:

- **A · Voll-Editor** — pflegt einen Bestands-Datensatz mit ≥3 Sektionen oder eingebettetem Sub-Editor.
  → `fullscreen` + `darkCanvas` + KPI-Kopf + sticky Tabs + Titel-Chip.
  Bei **Neuanlage** hell + klein (`:fullscreen="! $neu"` — Muster Rezept-Editor: leere Tabs sind Ballast).
  Mitglieder: Rezept, Gericht/VK, Concepter, **Grundprodukt**, **Lieferantenartikel**, Zutaten (standalone).
- **B · Dialog** — ein Zweck, eine Entscheidung, Footer-Buttons. Bleibt **hell + `max-w-*`**.
  Mitglieder: Produktionsauftrag, Lieferant neu/edit, Geschirr-Lieferant, Kapitel anlegen,
  fb-concept/fb-gericht, angebot-canvas/katalog, Generator/VK-Generator, Voice, Template-Instanz,
  Platzhalter, Pairing-Netz, Supplier-Detail.
  Aus dem Rollout bekommen sie nur: **Aktionsleisten-Reihenfolge**, **Heroicons statt Emoji**,
  **Footer-Konvention** — kein Dark, kein Fullscreen.

Rechte Detail-Panels (`*/detail-panel`) sind **außerhalb** dieser Spec (v3-Umbau `249dd8e` schon
vereinheitlicht), teilen aber die Tokens aus Stufe 0 → dort nur nachziehen, nicht umbauen.

---

## 3 · Etappen

### E0 · Bausteine extrahieren (Fundament, **null Sichtwechsel**)

Ohne diese Etappe wird der Rollout 4 Kopien desselben CSS.

- **E0.1 `<x-foodalchemist::editor-tabs>`** — sticky Leiste + Alpine-`tab`-Scope in *einem* Element:
  Props `:tabs="['aufbau' => 'Aufbau', …]"`, `:init="'aufbau'"`, `key="rezept-{{ $id }}"`.
  Enthält die drei gelernten Fallen fest verdrahtet: `wire:key` (Morph ersetzt Element bei
  Datensatz-Wechsel), `x-effect="if (open) tab = init"` (Tab-Reset bei jedem Öffnen),
  ein Alpine-Scope für Leiste **und** Panels (Header/Body-Split desynct unter Livewire-Morph).
  Panels bleiben beim Aufrufer (`<div x-show="tab === '…'">`), damit eingebettete Livewire-Kinder
  im DOM bleiben.
- **E0.2 `<x-foodalchemist::kpi-tiles>`** — `:tiles="[['label'=>'EK / kg','value'=>'…','tone'=>'accent'], …]"`,
  Tones `neutral|accent|good|warn|bad`, Hell- **und** Dunkel-Palette einmalig in der Komponente
  (aus `recipe-modal` 33–58 herausgelöst). Marker-Attribut bleibt konfigurierbar
  (`data-editor-kpis` / `data-vk-editor-kpis`), damit bestehende Pest-Marker nicht brechen.
  Semantik-Regel: **ein** Leitwert pro Editor bekommt `accent`, Vollständigkeit/Schwellen `good/warn`,
  nie mehrere Alarmfarben nebeneinander.
- **E0.3 Dark-CSS konsolidieren** — Block aus `modal.blade.php` 64–97 in
  `components/partials/editor-dark.blade.php`; dabei die Flächen ergänzen, die B-Editoren-Rollout
  braucht: Tabellen (`th/td/tr`-Tokens), Concepter-Asides (`bg-gray-500/[0.07]`), `chip`/`chips`,
  `tri-state`, `meter`, `tree-node`, `panel-section`. Jede Ergänzung mit Kommentar *warum*
  (die `!important`-Kaskade ist sonst nicht wartbar).
- **E0.4 ~~Ui.php-Tokens~~ — gestrichen (Befund 2026-07-31).** Die Sandbox-`app.css` listet als
  `@source` nur `resources/views/**/*.blade.php`; `src/Support/Ui.php` steht **nicht** drin.
  Klassen-Strings dorthin zu verschieben hätte sie aus dem Kompilat entfernt (Build-Falle,
  README §234 — `max-h-[92vh]` war der Präzedenzfall). Die Tab-/Kachel-Klassen bleiben deshalb
  literal in den neuen Baustein-Blades, die gescannt werden. Bestehende `Ui.php`-Tokens
  funktionieren nur, weil dieselben Klassen zusätzlich in Blades vorkommen — kein Vorbild.
- **DoD:** `git diff` zeigt am Rezept-Editor **nur** Zeilen-Entfall, kein Pixel-Unterschied;
  Suite grün; alle `data-*`-Marker im **gerenderten** Output vorhanden (nicht per grep über die
  Datei prüfbar — sie kommen nach dem Refactor aus den Bausteinen).

**Ergebnis E0 (umgesetzt 2026-07-31):** `components/editor-tabs.blade.php` ·
`components/kpi-tiles.blade.php` · `partials/editor-dark.blade.php` ·
`tests/Feature/EditorBausteineTest.php` (3 Tests / 29 Assertions).
Master −69 Zeilen, `modal.blade.php` −39 Zeilen, Verhalten unverändert.
Neu im Baustein: `md:grid-cols-{2,3,4,6}` für andere Kachel-Zahlen → **einmal `npm run build`**
nötig, bevor ein Editor mit ≠5 Kacheln im Browser abgenommen wird.

### E1 · Referenz einfrieren — Editor-Anatomie (Abnahme-Gate)

Referenz ist **immer der Master** (`livewire/recipes/recipe-modal`), nicht `DESIGN.md`.
Jede Etappe hakt diese 12 Punkte ab; was nicht zutrifft, wird begründet übersprungen.

**Hülle**
1. `<x-foodalchemist::modal>` mit `:fullscreen="! $neu"` + `:dark-canvas="! $neu"` bei
   Voll-Editoren. Neuanlage bleibt hell und klein — leere Tabs sind Ballast.
2. `:title-name="$name"` setzt den Datensatz-Namen als Akzent-Chip. Der Titel selbst bleibt
   generisch („Rezept bearbeiten"), der Name ist die Information.
3. `:close-via` nur, wenn ein Nav-Stack zurückspringen muss; Backdrop/Escape bleiben hartes
   Schließen. Beim Schließen feuert `modal.closed` → Komponente setzt ihren Form-State zurück
   (kein State-Leak).

**Kopf (scrollt nie weg)**
4. Reihenfolge fest: Titel + Chip → `x-slot:actions` → `x-slot:kpiHeader` → Tab-Leiste.
5. Aktionsleiste in dieser Ordnung: `Speichern` (primary) · Destruktives (`text-rose-600` +
   `wire:confirm` mit konkreter Folge, nicht „wirklich?") · `|` · KI-Chips (`btnAi`).
6. KPI-Streifen über `<x-foodalchemist::kpi-tiles>`; 3–6 Kacheln, **genau eine** `accent`
   (der Leitwert), Lücken `warn`, keine zwei Alarmfarben nebeneinander.

**Körper**
7. Tabs über `<x-foodalchemist::editor-tabs>` mit `wire-key` je Datensatz. Panels als
   `x-show`-Divs (nie `@if`) — eingebettete Livewire-Kinder dürfen nicht neu mounten.
8. Tab-Ordnung nach Änderungshäufigkeit: **Aufbau/Inhalt zuerst**, dann Stammdaten,
   dann Ableitungen (Deklaration, Preis, Sensorik), **Notizen zuletzt**.
9. Verwandte Felder in **eine** `modal-section`. 8 flache Sektionen hintereinander sind das
   Anti-Pattern (LA-Editor heute).
10. Sektions-eigene Aktionen in `x-slot:actions` der Sektion, nicht in die Kopfleiste.

**Sprache & Zeichen**
11. Keine Emoji im UI — `@svg('heroicon-o-…', 'w-3.5 h-3.5')`. Regelwerks-Paragraphen dürfen
    im Label stehen, wenn sie Arbeitsmittel sind (§6/§9/§11 beim GP); Erklärungen gehören ins
    `title`-Attribut, nicht in Fließtext neben das Feld.
12. Jede neue Fläche im dunklen Editor braucht einen Selektor in `partials/editor-dark.blade.php`
    — **mit Begründung**. Sonst grau auf grau.

**Vor der Browser-Abnahme:** Kompilat-Test (`Blade::compileString` + `php -l`) und
`npm run dev`/`build` — sonst fehlen neue Klassen und der Umbau sieht fälschlich kaputt aus.

### E2 · Halbfertige Voll-Editoren nachziehen (VK, Concepter)

- **E2.1 VK/Gericht** — `kpi-tiles` statt eigener Kacheln (Leitwert = **Marge %** `accent`;
  Wareneinsatz `good/warn` nach Schwelle statt fix Orange), `title-name` = Gerichtname,
  `editor-tabs` statt lokaler Kopie, Emoji-Sweep, Aktionsleisten-Ordnung.
- **E2.2 Concepter** — dito; zusätzlich die beiden Bau-Asides (`data-konzept-basisliste`,
  `data-konzept-gerichtliste`, `data-paket-gerichtliste`) dark-fähig machen (E0.3-Selektoren),
  KPI-Streifen auf die Komponente heben.
- Reine Anpassung, **kein Feature** — jede Verhaltensänderung hier ist ein Fehler.

**Ergebnis E2 (umgesetzt 2026-07-31):**
- VK: Kacheln auf `kpi-tiles` (Leitwert **Marge %**), Titel-Chip, `editor-tabs` — dadurch bekommt
  der VK-Editor `wire:key` und Tab-Reset, die dort **fehlten** (Tab blieb beim Gericht-Wechsel stehen).
- Concepter: Kacheln auf `kpi-tiles` (Leitwert VK €/Person), Titel-Chip (Titel benennt jetzt
  Konzept vs. Paket), Leiste auf den Baustein.
- **Befund unterwegs:** der Concepter fährt seine Tabs **serverseitig**
  (`wire:click="setTab"` + `@if($tab === …)`), nicht über Alpine wie Rezept und VK. Eine Umstellung
  wäre ein Verhaltens-Umbau (alle Panels samt Coverage/Kohäsion/Picker gleichzeitig im DOM) und
  fällt damit unter die E2-Regel „kein Feature". Der Baustein hat deshalb einen **Server-Modus**
  (`action` + `active`) bekommen: eine Leisten-Optik, zwei Mechaniken.
- Zwei Baustein-Ergänzungen aus echtem Bedarf: `hint`/`hint_title` (das „~" bei unvollständigem
  Gewicht war rohes HTML im Wert) und `tabular-nums` + Ellipsis auf `.kpi-value` (Live-Rechnen
  ließ den Streifen wackeln; Textwerte wie Lieferantennamen sprengten das Raster).

### E3 · Voll-Editoren neu erschließen (GP, LA, Zutaten)

- **E3.1 Grundprodukt-Editor** — `:fullscreen="! $neu"` + `:dark-canvas="! $neu"`,
  `title-name` = GP-Name, lokale Tabs → `editor-tabs` (sticky), KPI-Kopf:
  *LAs verknüpft (n/gesamt · `good/warn`)* · *Lead-LA €/Einheit (`accent`)* ·
  *Allergen-Konfidenz* · *Status (approved/tentative)* · *Rezept-Verwendungen*.
  Tab-Ordnung neu: `Benennung · Klassifikation · Lieferantenartikel · Deklaration
  (Allergene+Zusatzstoffe zusammen) · Preis · Sensorik & Pairing · Kalkulation · Ersatz`.
  Achtung: Regelwerk-Paragraphen (§6/§9/§11) bleiben sichtbar — sie sind Arbeitsmittel, nicht Deko.

  **Ergebnis E3.1 (umgesetzt 2026-07-31):** Voll-Editor nur im Bestand (`! $neu`), Titel-Chip =
  GP-Name. **Status-Regler und «Alles anreichern» lagen im scrollenden Body** — jetzt in der
  Aktionsleiste im Kopf (Reihenfolge Speichern · Status · KI). KPI-Kopf übernimmt exakt die
  Größen des bestehenden GP-Cockpits aus dem Detail-Panel, nichts neu erfunden:
  *Lead-Preis* (accent) · *Lieferantenartikel* · *Allergen-Konf.* · *Warengruppe* · *Zustand §9*.
  Dafür zwei zusätzliche Reads in `GpModal::render()` (`PriceService::activeFor` über `leadLa`,
  `GpAggregateService::allergenKonfidenz`) — die Tab-Panels mounten ohnehin schwerere
  Detail-Panel-Kinder. **Wichtig bei „0 LAs":** bei `requires_la = false` (Derivate/Platzhalter,
  GP-Regelwerk §11.2) ist das kein Mangel → neutral statt warn, sonst hätte der Editor 
  Derivate dauerhaft falsch angemahnt.
  Tab-Leiste auf den Baustein (vorher eigene Variante **ohne sticky, ohne `wire:key`, ohne
  Reset**). Baustein-Ergänzung: bei nur **einer** Lasche zeichnet er keine Leiste — die
  GP-Neuanlage hat nur „Allgemein", eine Ein-Laschen-Navigation ist Rauschen.
  Die Tab-Ordnung aus der Planung habe ich **nicht** umgesetzt: sie hätte Panels zusammengelegt
  (Allergene + Zusatzstoffe) und damit die eingebetteten Detail-Panel-Sektionen umgebaut — das ist
  Struktur, nicht Design. Bestehende Ordnung bleibt, Umbau wäre ein eigener Schritt.
- **E3.2 Lieferantenartikel-Editor** — der datendichteste Editor ohne jede Struktur
  (8 Sektionen linear). `fullscreen` + `dark` + Tabs
  `Stammdaten · Verpackung & Mengen · Deklaration · GP-Mapping · Preise`,
  `title-name` = Artikel-Designation, KPI-Kopf: *EK/Einheit (`accent`)* · *Preis-Stand (Datum,
  `good/warn` nach Alter)* · *Allergene vollständig (n/14)* · *GP-Mapping (gesetzt/offen)* ·
  *Lieferant*. Höchster Nutzen pro Aufwand im ganzen Rollout.

  **Ergebnis E3.2 (umgesetzt 2026-07-31):** Hülle auf Voll-Editor (`fullscreen` + `dark-canvas`,
  Titel-Chip = Designation), alte Kopfzeile (`data-modal-kopf`: Lieferant · EK · Vergleichspreis ·
  GP-Pill) durch den KPI-Kopf ersetzt — dieselben Angaben, aber fix und bewertet:
  *EK aktuell* (accent) · *Vergleichspreis* · *Grundprodukt* (good/warn) ·
  *Allergene n/14* (good/warn, alles außer `unbekannt` zählt — GL-01-4-Wert-Modell) · *Lieferant*.
  Acht lineare Sektionen → 4 Tabs (Stammdaten · Deklaration · GP-Mapping · Preise), Alpine-Modus,
  damit das `entangle`-Binding der Zusatzstoffe beim Umschalten nicht verloren geht.
  `read-only` wanderte in die Aktionsleiste. Emoji raus (🧺 ✨ ✎ ✕ → Heroicons); die Tri-State-
  Zeichen −/≈/✓ bleiben, sie tragen Bedeutung. Regressionstest deckt Tabs, KPI-Tones,
  Voll-Editor-Hülle und Emoji-Freiheit gerendert ab.
- **E3.3 Zutaten-Editor standalone** — eingebettet erbt er den Editor-Grund automatisch; die
  Standalone-Hülle (`max-w-[100rem]`) auf `fullscreen` + `dark` ziehen, Rezeptname aus dem
  Titel-String in `title-name`.

### E4 · Dialog-Klasse harmonisieren (breit, flach)

Pro Datei nur: Emoji → Heroicon (`📐`, `✨`, `🎙` raus), Aktionsleisten-/Footer-Ordnung,
`x-slot:footer`-Konvention (`Abbrechen` ghost links, primary rechts). Kein Dark, kein Tab, kein KPI.

**Ergebnis E4 (umgesetzt 2026-07-31)** — die **Dialog-Editoren**:
`produktion/editor` · `gps/platzhalter-modal` · `recipes/template-instantiate-modal` ·
`recipes/generator-modal` · `verkauf/vk-generator-modal` · `recipes/voice-modal`.
Modal-Titel emoji-frei, `✎`/`🗑`/`✕` → Heroicons, `✨ Generieren` → Sparkles-Icon + Text.
Voice-Aufnahmeknopf: beide Icons im DOM, Alpine schaltet nur Sichtbarkeit — `x-text` kann kein
Markup tragen. Icon-Namen gegen `blade-heroicons/resources/svg` geprüft (`@svg` wirft erst beim
Rendern, nicht beim Kompilieren).

> **Befund: der Emoji-Bestand ist app-weit, nicht editor-weit.** Inventur 2026-07-31:
> **231 Emoji in 53 Blade-Dateien**. Schwerpunkte außerhalb dieser Spec:
> `concepter/editor` (33) · `foodbooks/index` (28) · `gps/detail-panel` (16) · `dashboard` (15) ·
> `verkauf/vk-modal` (15) · `recipes/browser` (10). Typen mit unterschiedlichem Charakter:
> `✨` (KI-Marker, ~55× — klar ersetzbar), `⚠` (Warnung in Fließtext — Ersatz ändert Textfluss),
> `✎ 🗑 ✕` (Aktions-Icons — klar ersetzbar), `🍽 📖 📦 🎭 🧺 🚚` (Typ-Marker in Listen — brauchen
> eine Icon-Zuordnung pro Domäne), `🔴 🟣 🔵 🟢` (Status-Punkte — gehören als CSS-Punkt, nicht als
> Zeichen). Das ist ein eigener Typografie-Sweep mit ~53 Dateien Regressionsfläche und wird
> **nicht** unter „Editor-Rollout" mitgenommen. Eigenes Ticket, am besten in der Reihenfolge
> KI-Marker → Aktions-Icons → Status-Punkte → Typ-Marker.

### E5 · Abnahme

1. `./fa_test.sh` (parallel, geteilte Helfer nur in `tests/Support/`).
2. **Marker-Diff:** `grep -o 'data-[a-z-]*' | sort -u` vor/nach je Datei — kein Marker darf fallen.
3. Zwei neue Pest-Tests: `editor-tabs` (Reset bei Öffnen, `wire:key` bei Datensatz-Wechsel),
   `kpi-tiles` (Tone-Mapping).
4. `npm run dev` **läuft während der Arbeit** (sonst Tailwind-Build-Falle → Layout wirkt
   fälschlich kaputt).
5. Browser-Klickstrecke je Voll-Editor: öffnen → Tab wechseln → schließen → erneut öffnen
   (Tab muss zurückgesetzt sein) → anderer Datensatz (kein State-Leak, `modal.closed`-Vertrag).
   Sichtbarkeits-Sonden: `checkVisibility()`/`elementFromPoint`, **nicht** `offsetParent`
   (bei `position:fixed` immer `null`).
6. `docs/ROADMAP.md` + Office-Dev-Package 23 mitziehen (Commit-Sync-Regel).

**Ergebnis E5 (Browser-Abnahme 2026-07-31) — alle 6 Editoren bestanden.**
Aufbau: `php artisan serve --port=8765` + `/sandbox-login`, System-Chrome headless mit **eigenem
Profil** unter `--remote-debugging-port=9222`, getrieben per `playwright-core` über CDP
(im Scratchpad installiert, **nicht** in die Sandbox — sonst wandert es in `package.json`).
Geöffnet wurde je Editor über das dokumentierte Livewire-Event, also denselben Eingang wie der
Listen-Klick.

| Editor | Panel dunkel | Titel-Chip | KPI | Leiste klebt nach Scroll | Tab-Wechsel | Esc schließt | Reset beim Wiederöffnen |
|---|---|---|---|---|---|---|---|
| Rezept | ✅ 1568×968 | ✅ | 5 | ✅ 1566×50, Hit-Test | ✅ | ✅ | ✅ → aufbau |
| Gericht/VK | ✅ | ✅ | 10 | ✅ | ✅ | ✅ | ✅ → aufbau |
| GP | ✅ | ✅ | 5 | ✅ | ✅ | ✅ | ✅ → allgemein |
| LA | ✅ | ✅ | 5 | ✅ | ✅ | ✅ | ✅ → stammdaten |
| Zutaten | ✅ | ✅ | — | — | — | — | — |
| Concepter | ✅ | ✅ | 7 | ✅ (Server-Modus: Wurzel *ist* die Leiste) | ✅ | ✅ | ✅ → aufbau |

Keine JS-Fehler, keine 500er. Sichtbarkeit gemessen mit `checkVisibility()` + `elementFromPoint`,
**nicht** `offsetParent` (für `position:fixed` immer null — Messfalle MVP-045).

**Zwei Befunde, die nur der Browser gezeigt hat:**
1. **Concepter-KPI brach um:** 6 Kacheln + eine verwaiste in Reihe 2. Die Spalten-Klammer in
   `kpi-tiles` kappte bei 6, der Streifen führte vorher 7 in EINER Reihe. Klammer auf 7 gehoben
   (`md:grid-cols-7`) → verifiziert 7 Kacheln / 1 Reihe / 7 Spalten.
2. **Sichtbare Emoji in den Voll-Editoren**, die E4 nicht abdeckte (E4 war auf die Dialog-Editoren
   gescopet): GP „Name aus Lieferantenartikel ableiten", Master-Textverweise „(via ✨ …)",
   VK `🎭 Rollen verteilen` / `🎭 Rollen-Verteilung` / `⚙`-Delta-Knopf / `🗑`, Zutaten-Kern `📦`.
   Alle auf Heroicons bzw. Wortlaut umgestellt. Der **Concepter** behält seine ~25 Typ-Marker
   (🍽 📦 📖 🍴 …) — die brauchen eine Icon-Zuordnung pro Domäne und gehören in den Sweep aus §4-E4.

**Nicht abgedeckt** (bewusst): Licht-Theme der Dialog-Editoren, Mobile-Breakpoints, Drag-&-Drop im
Concepter-Aufbau, echte KI-Läufe (brauchen Provider).

---

## 4 · Reihenfolge & Aufwand

| Etappe | Aufwand | Abhängigkeit |
|---|---|---|
| E0 Bausteine | ~3–4 h | **Blocker für alles**; braucht `recipe-modal` konfliktfrei |
| E1 Referenz-Doku | ~1 h | nach E0 |
| E2 VK + Concepter | ~3 h | E0 |
| E3.2 LA-Editor | ~3 h | E0 · *höchster Nutzen* |
| E3.1 GP-Editor | ~3 h | E0 |
| E3.3 Zutaten standalone | ~1 h | E0 |
| E4 Dialoge | ~2 h | E1 |
| E5 Abnahme | ~2 h | alle |

**Empfohlene Reihenfolge:** E0 → E1 → **E3.2 (LA)** als erster Beweis am fremden Editor →
E2 → E3.1 → E3.3 → E4 → E5. Der LA-Editor zuerst, weil er der einzige Voll-Editor ohne jede
Struktur ist — wenn der Baustein *dort* trägt, trägt er überall.

---

## 5 · Risiken

- **Konflikt mit WIP Spec 27.** `recipe-modal.blade.php` ist auf `feat/spec-27-step-by-step`
  ungecommittet geändert. E0 refactort genau diese Datei → **erst nach Merge/Commit von Spec 27
  starten**, eigener Branch `feat/spec-28-editor-rollout`. Nur eigene Dateien stagen.
- **Alpine × Livewire-Morph.** Tab-State desynct ohne `wire:key`; `x-data`/`x-effect` werden bei
  morphdom nicht neu ausgewertet. In E0.1 einmal richtig lösen statt 6× falsch. ✅ erledigt.
- **Zwei Blade-Kommentar-Fallen** (beide beim E0-Bau eingetreten, Kompilat-Test hat sie gefangen).
  Compiler-Reihenfolge ist `storeUncompiledBlocks` → `compileComments` → `compileComponentTags`:
  1. `{{-- … --}}` **innerhalb** eines `@php`-Blocks wird **nie** gestrippt (der Block ist vorher
     geschützt) → landet als literaler PHP-Müll. In `@php` nur `//` verwenden.
  2. Ein verschachteltes `--}}` beendet einen Doku-Kommentar vorzeitig — der Rest leckt ins
     Template, und `<x-…>`-Beispiele darin werden als **echte** Komponenten kompiliert.
  Deshalb: Kompilat-Test (`Blade::compileString` + `php -l`) vor jedem Browser-Blick, pro Etappe.
- **Tailwind-Build-Falle.** Neue arbitrary values fehlen im Kompilat und lassen den Umbau
  „kaputt" aussehen. Regel: bestehende Tokens kombinieren oder gescopetes rohes CSS.
- **`!important`-Kaskade.** Das Dark-CSS gewinnt per `!important` gegen die Hell-Utilities. Jede
  neue Fläche in einem Editor braucht Selektor-Pflege — deshalb E0.3 mit Begründungs-Kommentaren.
- **`DESIGN.md` ist stale.** Sie zeigt `dark:`-Varianten; FA ist seit `977a301` `dark:`-frei.
  Beim Rollout **nicht** danach arbeiten — Referenz ist der Rezept-Editor + diese Spec.
  Nachtrag in `DESIGN.md` als eigener kleiner Commit.
- **Regression durch Übereifer.** E2/E3 sind Design-Rollout, keine Feature-Runde. Alles, was
  Verhalten ändert (Feldbedeutung, Speicher-Pfad, Tab-Inhalte), wird als eigenes Ticket notiert,
  nicht mitgemacht.

---

## 5a · E6/E7 — Nachtrag Dominique (2026-07-31)

**E6 · Tab-Zuschnitt in Gericht und Concepter**
- **Gericht/VK:** «Aufbau» ist jetzt reiner Bau (nur Komponenten), Stammdaten + Klassifikation
  liegen in neuer Lasche «Stammdaten» direkt dahinter — Master-Parität.
  *Rest-Delta zum Master:* die Sektion «Eigenschaften» (Arbeitszeit u. a.) sitzt weiter im
  Service-Tab. Bewusst nicht mitverschoben, das wäre eine Inhalts-Umsortierung im Service-Tab.
- **Concepter:** die Feldleiste klebte **permanent über den Tabs** und drückte den Aufbau nach
  unten → eigene Lasche «Stammdaten». Coverage (Planungs-Gerüst-IST) → «Konzept & Planung»,
  direkt unter den SOLL-Rahmen, gegen den sie misst. Menü-Kohäsion → «Sensorik & Pairing».
  «Aufbau» beginnt jetzt oben mit den Positionen.
- **Falle dabei:** `Editor::setTab()` hat eine Whitelist. Ohne `'stammdaten'` darin hätte die
  neue Lasche **stillschweigend nichts** getan — kein Fehler, nur Wirkungslosigkeit.
- `CoverageTest` kodierte die alte Platzierung (Coverage im Aufbau-Tab). Sichtbarkeits-Prüfung
  auf «Konzept & Planung» gezogen, **Verhaltens-Prüfung unverändert**: Lücken-Klick springt in
  den Aufbau-Tab und setzt den Diät-Filter — mit dem Umzug ist genau das die tragende
  Verbindung zwischen SOLL und Bau, deshalb zusätzlich `assertDontSee` im Aufbau.

**E7 · Positions-Zeile im Concepter atmet (Dominique: „innere Zeile presst, links/rechts zu viel Platz")**
Gemessen statt geschätzt: die Tabelle hatte 918 px, aber die Namensspalte nur **230 px** — die
übrigen Spalten (Menge, Einheit, Rolle, Zahlen, Aktionen) fraßen ~580 px. Da die Namensspalte die
einzige `w-full`-Spalte ist, fließt jede gesparte Breite genau dorthin.
- Einfüge-Listen `w-72 → w-56`, Paket-Liste `w-80 → w-60` (bewusst **flach**, ohne `2xl:`-Bonus —
  ein Bonus auf breiten Schirmen hätte gegen den Wunsch gearbeitet).
- Menge `!w-16 → !w-14`, Rolle `!w-28 → !w-20`.
- **Einheit bewusst NICHT verschmälert:** das Vokabular enthält `flasche`, `kanister`, `portion` —
  bei 80 px schneidet die geschlossene Auswahl sie ab, und `scrollWidth` verrät das bei `<select>`
  nicht (die Sonde meldet fälschlich „nicht beschnitten").
- Ergebnis: Mitte 918 → **1046 px**, Namensspalte 230 → **392 px**, langer Gerichtname von zwei
  Zeilen auf **eine**, Zeilenhöhe von vier auf drei Ebenen.
- *Verbleibender Kompromiss:* Rolle bei 80 px schneidet lange Freitexte wie „komponente" optisch
  knapp ab (Eingabe bleibt scrollbar), und die schmaleren Seitenlisten brechen Namen häufiger um.
  Rückweg: Rolle `!w-24`, Listen `w-60`.

**Lange Gerichtnamen (Frage Dominique) — geprüft, kein Umbau nötig.**
Bestand: **17 von 31** VK-Gerichten über 60 Zeichen, 6 über 90, Maximum **173** Zeichen. Lange
Namen sind hier die Regel, nicht der Sonderfall — die Pipe-Syntax (§4.4) listet ja die Komponenten
im Namen. Am Bestands-Maximum gemessen: bricht an den Pipes auf **3 Zeilen**, Zelle bleibt 465 px,
Tabelle bleibt 1046 px, Zahlenspalten stehen, **kein Querscrollen**. Die Zeile wächst nach unten,
das Layout hält.
- Ein Loch war da: `overflow-wrap: normal` — ein Name **ohne Trenner** (eingefügt, Klebe-Fall)
  hätte die Zelle überlaufen. `break-words` auf Positions- und Paket-Namen ergänzt; gegen 120
  Zeichen ohne Leerzeichen nachgemessen: kein Überlauf, kein Querscrollen.
- **Absichtlich NICHT gekürzt** (kein `line-clamp`/Ellipse): im Concepter *ist* der Name die
  Komposition. Wer hier plant, braucht die Komponentenliste — Kürzen würde genau die Information
  verstecken, um die es geht. Preis: Zeilen sind 2–3 Ebenen hoch. Falls die Tabelle bei 20+
  Positionen zu lang wird, wäre `line-clamp-2` + `title` der Rückweg — dann aber als bewusste
  Entscheidung, nicht als Nebenwirkung.

### E11 · Listen-Bausteine (2026-07-31)

**Befund, der den Umbau auslöste:** der „aktiv"-Zustand stand **30× handgeschrieben in 18 Dateien**
— dieselbe Krankheit, die der Editor vor Spec 28 hatte. Die Behandlung 13× per Hand nachzuziehen
hätte die Kopien festgeschrieben. Deshalb erst Bausteine, dann migrieren.

Inventur der 33 Listen-/Sidebar-Schirme: **echte Bäume mit Eltern/Kind gibt es nur 3** (GPs,
Rezepte, Gerichte) — Concepter, Foodbook, Konzepte, Pakete, Angebote, Produktion, Speiseplan,
Lieferanten und Geschirr haben **flache** Filter-Listen. Das Eltern/Kind-Kontrastproblem ist damit
vollständig abgedeckt; für die übrigen 9 geht es nur um Akzentbalken und Zähler.

**Drei Bausteine:**
- `components/filter-row.blade.php` — eine Zeile in der Filter-Sidebar. Trägt das Kontrast-Modell
  (**Balken = offener Zweig · Füllung = Auswahl**), `level="top|child"`, gedämpfte Null-Zähler,
  Tausenderpunkte an einer Stelle (vorher waren Baum-Zähler roh `6930` und die „Alle …"-Zeile
  formatiert `6.930` — dieselbe Zahl in zwei Schreibweisen).
- `components/filter-ast.blade.php` — die Führungslinie der Kind-Ebene.
- `components/table-row.blade.php` — die klickbare Tabellenzeile: Auswahl = Füllung + Balken,
  inaktiv = transparenter Balken gleicher Breite (kein Layout-Sprung).

`wire:click`, `wire:key`, `x-data` und `data`-Marker fließen über `$attributes` durch — die
Aufrufer behalten ihre Livewire-Verdrahtung und ihre Test-Marker.

**Migriert:** GPs, Rezepte, Gerichte — Bäume, Reset-Zeilen („Alle …") und Tabellenzeilen.
Handgeschriebene aktiv-Zustände in diesen drei Dateien: **0**.
`ListenBausteineTest` (9 Tests) hält vor allem das Kontrast-Modell fest, weil genau das der Grund
für den Umbau war.

**Gleichheits-Nachweis im Browser** — dieselben Sonden wie vor der Migration, identische Werte:
aufgeklappt → Balken **und** Füllung; Kind gewählt → Eltern nur Balken, Kind gefüllt;
Tabellenzeile `2px oklch(0.606 0.25 292.717)` vs. `2px` transparent. Keine JS-Fehler.

**Vier Fallen unterwegs:**
1. Ein Regex zog den **Blade-Echo in eine gebundene Prop** (`:count="{{ … }}"`) — ungültiges PHP.
   Gebundene Props nehmen den Ausdruck **ohne** `{{ }}`.
2. Pauschales `</tr>` → Baustein-Ende hätte den `thead` mitgerissen; ebenso `</div>` → `filter-ast`.
   Beides nur mit exakten Grenzen ersetzt.
3. Ein Regex verschluckte den Marker `data-gesamt-count` in der Verkaufs-Reset-Zeile. Kein Test
   nutzt ihn — aber ein stillschweigend verlorener Marker verstößt gegen die E0-Abnahmeregel,
   deshalb zurück auf die Baustein-Wurzel.
4. Der Marker-Test am GP-Browser konnte `data-fa-filter-row` **nicht** zusichern: das Fixture
   seedet kein Warengruppen-Vokabular, der Baum-`@foreach` läuft null Mal. Die Zusicherung wäre
   eine Fixture-Aussage gewesen, keine Code-Aussage — entfernt und begründet.

**Offen (bewusst):** die 9 flachen Filter-Sidebars und 4 weiteren Tabellen sind **noch nicht**
migriert. Der Weg ist jetzt aber ein Einzeiler pro Zeile statt einer Kopie.
Ebenfalls offen: das **Scroll-Modell der Tabelle** — ohne begrenzte Höhe auf dem
`overflow-x-auto`-Wrapper gibt es keinen sticky Tabellenkopf (siehe E7-Nachtrag). Das ist eine
Layout-Entscheidung und gehört an einem Schirm erprobt, bevor sie in einen Baustein wandert.

## 6 · Offene To-dos, die beim Rollout aufgefallen sind

Bewusst **nicht** im Rollout mitgemacht — jedes ändert Verhalten oder Daten, nicht Aussehen.

1. ✅ **Wareneinsatz-Ampel im Gericht-Editor — erledigt 2026-07-31 (E8).**
   Die Leiter lag als **private** Kopie im `RecipeOneShotService`, deshalb konnte die Kachel gar
   nicht ampeln. Jetzt public im **`MargeService`** (der `wareneinsatz_pct` ohnehin rechnet) — eine
   Wahrheit für Wirtschaftlichkeits-Glied (03·L8), Signale (Entscheidung 4) und Editor;
   `RecipeOneShotService` delegiert nur noch.
   `SalesRecipeService::cockpit()` nimmt ein **optionales `?Team`** und liefert `ziel_pct` + `ampel`.
   Ohne Team → `ziel_pct = null`, `ampel = 'unbekannt'`: der Service holt sich **kein** Team über die
   Hintertür, und die Kachel bleibt dann neutral statt geraten. Aufrufer (VkModal, VK-DetailPanel)
   geben ihr Team mit. Tooltip nennt die Vorgabe, damit die Farbe erklärbar ist.
   `WareneinsatzAmpelTest` hält die **Grenzen** fest: genau auf Ziel = grün, genau Ziel × 1,5 = noch
   gelb, kein Ziel/kein Wareneinsatz = unbekannt. Browser: 27,7 % gegen Ziel 30,0 % → grün.
   *Nebeneffekt:* das VK-Detail-Panel bekommt `ziel_pct`/`ampel` jetzt ebenfalls — dort noch
   ungenutzt, wäre ein Einzeiler.

### E9 · KI-Knöpfe modulweit angleichen (2026-07-31)

Inventur über alle Blades: **75 KI-Knöpfe in 19 Dateien**, 41 Auffälligkeiten — davon aber nur ein
Teil echt. Die Klassifikation ist der eigentliche Ertrag:
- **Echte KI-Auslöser** (starten einen KI-Vorgang) → `$btnAi`-Chip + `heroicon-o-sparkles`.
  Angepasst: `ki-header` (Autopilot) · `foodbooks/index` (KI-Text ×2, Kundentext, Gerüst, KI-Ideen) ·
  `gps/detail-panel` (KI-Vorschlag, „per KI schätzen" ×2) · `recipes/browser` (KI-Rezept,
  Bulk anreichern, **Sprachbedienung**) · `verkauf/browser` (KI-Rezept) · `verkauf/detail-panel`
  (Klassifizieren, Rollen, Kohärenz prüfen, Heber vorschlagen, Eignung) · Sensorik-Bewertung in
  Rezept- und Gericht-Editor. 🎭 → `heroicon-o-user-group`, 🎙 → `heroicon-o-microphone`.
- **NICHT angefasst, weil kein KI-Auslöser:** „Übernehmen"/„Verwerfen"/„Reset" (Ergebnis-Aktionen
  auf einen Vorschlag) bleiben Ghost — `btnAi` würde behaupten, sie starten KI.
- **NICHT angefasst, weil Haupt-Aktion:** „Generieren" in den Generator-Dialogen und
  „Gerüst vorschlagen" bleiben `btnPrimary` — die tragende Aktion einer Fläche ist kein Chip.

**Zwei Fallen dabei:**
1. `components/ki-header.blade.php` bezieht Stile über `$ui['…']`, **nicht** per `extract()`.
   Ein `{{ $btnAi }}` dort wäre eine undefinierte Variable gewesen.
2. Ein Textersatz hat `@svg(…)` in zwei **`placeholder`-Attribute** geschoben (Blade kompiliert
   Direktiven auch dort → ein ganzes SVG im Platzhaltertext). Zurückgedreht auf reinen Text;
   Regel: in Attributen niemals Blade-Icons.

Browser-Kontrolle über Gerichte · Rezepte · GPs · Foodbooks · Konzepte · Lieferanten:
**0 Emoji im sichtbaren Text, 0 rohe `@svg(`, keine JS-Fehler.**

### E10 · Typ-Marker → Icons, app-weit (2026-07-31)

**Zuordnung pro BEDEUTUNG, aufgebaut auf dem vorhandenen Haus-Vokabular** (das die App über
`<x-…::section icon="…">` schon führt: Grundprodukte=`cube`, Basisrezepte=`book-open`,
Gerichte=`banknotes`, Lieferanten=`truck`, Pakete=`puzzle-piece`, Zutaten=`list-bullet`, …) —
nicht Zeichen für Zeichen geraten. 43 Icon-Namen vorher gegen
`blade-heroicons/resources/svg` geprüft, weil `@svg` erst beim **Rendern** wirft.

Auszug: 🧺→`cube` · 📖→`book-open` · 🍽→`banknotes` · 📦→`archive-box` · 🧩→`puzzle-piece`
(bewusst getrennt: Gebinde ≠ Baustein) · 🍴→`rectangle-stack` (Darreichung) · 📐→`square-2-stack`
· ⚙→`adjustments-horizontal` · 📍→`map-pin` · 💶→`currency-euro` · ⚠→`exclamation-triangle`
· 🎭→`user-group` · 🎙→`microphone`.
**Status-Punkte** (🔴🟣🔵🟢) wurden **keine Icons**, sondern CSS-Punkte
(`w-1.5 h-1.5 rounded-full bg-…`) — wie im GP-Cockpit schon üblich.

Ergebnis: **128 Vorkommen mechanisch ersetzt** + 10 Stellen umgebaut, die in PHP-Ausdrücken
steckten (Dashboard-KPI-Array und Foodbook-Reiter tragen jetzt Icon-**Namen**, ternäre
Emoji-Labels wurden zu `@if/@else` mit echten Icons). 19 Vorkommen bleiben in **Blade-Kommentaren**
— unsichtbar, absichtlich unangetastet.

**Drei Fallen, alle vom Werkzeug gefangen, nicht vom Auge:**
1. **Erster Durchlauf war falsch** und wurde per `git checkout` zurückgedreht: der Schutz kannte
   nur Attribute und `@php`-Blöcke, nicht `{{ … }}`-Echos und **Direktiven-Argumente**
   (`@foreach([… '⚠ Text' …])`). Fünf Dateien brachen mit „unexpected identifier heroicon".
   Der Guard prüft jetzt zusätzlich Echo-Blöcke und balancierte Direktiven-Argumente.
2. **Geklebte Direktive:** `@else@svg(…)` kompiliert **nicht** — Blade verlangt ein
   Nicht-Wortzeichen vor der Direktive, und `@else` endet auf `e`. 5 Stellen entklebt.
   `BladeCompilesTest` hat das gemeldet, der reine `php -l`-Kompilat-Test **nicht** (das Kompilat
   war syntaktisch gültig, nur stand `@svg` als Text drin).
3. Icons gehören **nie** in Attribute (`title`, `placeholder`) — dort landet sonst ein ganzes SVG
   im Text. Zwei Fälle abgefangen.

**Grenze gezogen:** das letzte sichtbare Emoji der Oberfläche (`💬`, Terminal-Leerzustand) liegt in
**`platforms-core`** — Fremdmodul, nicht angefasst (Regel „keine Fremdmodul-Änderungen"), gehört
an Martin. Das FA-Modul selbst ist im sichtbaren UI emoji-frei; 12 Modul-Seiten im Browser geprüft.
2. **Concepter-Tabs: Server- oder Alpine-Mechanik?** Heute Server (nur aktives Panel im DOM).
   Alpine wäre schneller im Umschalten und würde ungespeicherte Eingaben halten, kostet aber alle
   Panels gleichzeitig (Coverage, Kohäsion, zwei Picker, eingebettete Livewire-Kinder). Eigene
   Messung nötig, kein Bauchentscheid.
3. **⚠ Mandantentrennung Einkauf (nicht aus dieser Spec, aber beim ersten Suite-Lauf aufgefallen).**
   `PolicyTest` meldet drei Models **ohne `BelongsToTeamHierarchy`**:
   `FoodAlchemistPurchaseTransaction`, `FoodAlchemistSupplierRebateConfig`,
   `FoodAlchemistSupplierRebateTier` (aus den Einkauf-E1/E2-Commits). Einkaufsjournal und
   Rückvergütungs-Konfiguration hängen damit nicht an der Team-Hierarchie. Gehört geprüft, **bevor**
   Einkauf auf demo geht — das ist kein Kosmetik-Befund.

## Changelog

- 2026-07-31 — Spec angelegt (Ist-Stand kartiert, Klassen-Regel A/B, Etappen E0–E5).
- 2026-07-31 — **E0 + E1 + E2 + E3.2 umgesetzt.** Bausteine `editor-tabs` (Alpine- und
  Server-Modus) + `kpi-tiles` + `partials/editor-dark`; Master, VK, Concepter und LA-Editor
  darauf gezogen; `EditorBausteineTest` (4 Tests / 45 Assertions). E0.4 (Ui.php-Tokens)
  gestrichen — Tailwind scannt Ui.php nicht. §6 mit drei Folge-To-dos ergänzt.
- 2026-07-31 — **E3.1 + E3.3 + E4 umgesetzt.** GP-Editor (Status/KI aus dem Body in den Kopf,
  KPI aus dem GP-Cockpit, sticky Leiste, `requires_la`-Sonderfall), Zutaten-Editor standalone,
  sechs Dialog-Editoren emoji-frei. Baustein-Regel „eine Lasche = keine Leiste".
  `EditorBausteineTest` auf 6 Tests / 63 Assertions. Suite **1860/1862** — der eine Fehler ist
  der vorbestehende Einkauf-Tenancy-Gap (§6.3), plus 1 skipped.
  **Damit sind E0–E4 durch; offen bleibt allein E5 (Browser-Abnahme).**
