# Food.Alchemist — Designsystem „Labor und Tageslicht"

> Stand 2026-10-05 · Branch `design/pass` (fa-pass) · ersetzt den früheren Brief „Linear / Raycast" (Violett, Milchglas).
> Lebende Referenz mit allen Bausteinen: **`/foodalchemist/_ui`** (Navigation → System → Designsystem).

Der Food.Alchemist ist ein eigenständiges Produkt, kein Plattform-Modul. Er hat eine eigene Hülle
(`layouts/standalone`), eigene Tokens und eine eigene Bausteinbibliothek `<x-fa::…>`.

## Leitbild

- **Rahmen dunkel, Arbeit hell.** Die Navigation ist tintenblau, die Arbeitsfläche hell. Wer den
  ganzen Tag Mengen und Preise pflegt, liest helle Tabellen besser.
- **Werkbank-Modus für Editoren.** Editoren dürfen dunkel sein — in den Farben der Navigation,
  damit der Wechsel kein „Programmwechsel" ist. Technisch nur über Tokens (siehe unten).
- **Eine Akzentfarbe: Logo-Blau `#033CDB`.** Nur für Klickbares und die eine Hauptzahl einer Ansicht.
- **Zustände haben eigene Farben** (Freigegeben grün · Prüfen bernstein · Fehlt/Enthalten rot · Info),
  auch in der Helligkeit unterscheidbar, nie mit dem Akzent verwechselbar.
- **Der Food.Alchemist schätzt nicht.** Fehlende Werte werden gezeigt (`x-fa::money` → „Preis fehlt"),
  nicht versteckt und nicht als 0,00 € dargestellt.

## Tokens (eine Quelle)

Datei: `resources/css/foodalchemist-pass.css`. Views verwenden **nur** Tokens oder Bausteine.

| Gruppe | Tokens |
|---|---|
| Flächen | `--fa-ground` · `--fa-surface` · `--fa-line` · `--fa-line-strong` · `--fa-hover` |
| Text | `--fa-ink` · `--fa-ink-2` · `--fa-ink-3` |
| Akzent | `--fa-accent` · `--fa-accent-hover` · `--fa-accent-soft` · `--fa-accent-line` · `--fa-on-accent` |
| Zustände | `--fa-ok` · `--fa-warn` · `--fa-crit` · `--fa-info` (+ `-soft`), `--fa-neutral-soft` |
| Schrift | `--fa-text-sm` 12 · `-md` 13 · `-base` 14 · `-lg` 16 · `-2xl` 24 · `-3xl` 28 (px) |
| Formen | `--fa-radius-control` 6 · `--fa-radius-surface` 10 · `--fa-radius-pill` |
| Navigation | `--fa-rail*` |

Schriftgrößen in Tailwind immer als `text-[length:var(--fa-text-md)]` — `text-[var(…)]` liest
Tailwind v4 als **Farbe**.

Bildschirm-Bereiche: `sm md lg xl 2xl` plus **`wide` (1.800 px)** für große Monitore. Nie `min-[…px]:` mit
`lg:` mischen — arbiträre Breakpoints sortiert Tailwind VOR `lg` und verlieren dann gegen `lg:`-Regeln
(Zutaten-Editor 2026-10-05: rechte Spalte rutschte auf großen Monitoren weg). Gegenstück: `max-wide:`.

Die alten Tailwind-Paletten sind umgemappt (violet/indigo/purple → Logo-Blau, gray/slate → Blaugrau),
damit nicht umgebaute Views schon im neuen Bild erscheinen. Das ist eine Brücke, kein Ziel:
neue Arbeit nutzt die `--fa-*`-Tokens.

## Bausteine `<x-fa::…>`

Ort: `resources/views/components/fa/`. Registrierung: `Blade::anonymousComponentPath(…, 'fa')`.

| Baustein | Zweck |
|---|---|
| `button` · `icon-button` | Knöpfe: primary (eine je Fläche) · secondary · ghost · danger · ai; `icon-button` braucht `label` |
| `field` · `input` · `select` · `textarea` | Formular: Label oben, Hilfetext/Fehler darunter; `numeric` für Zahlen |
| `choice` | 2–6 Optionen als Chips (Radio/Checkbox), ab 7 → `select` |
| `badge` · `status` · `signal` · `notice` | Etikett · Lebenszyklus-Status (eine Zuordnung für GP/Rezept/Gericht) · Hinweis im Text · Hinweisfläche |
| `kpis` | Kennzahl-Leiste, höchstens eine `primary`-Zahl |
| `section` · `page-header` · `empty` | Abschnitt (card/plain) · Seitenkopf mit Aktionen · Leerzustand |
| `money` · `menge` | Geld (de-Format, fehlend → Signal) · Menge ohne überflüssige Nullen |
| `deklaration` | Allergene, Zusatzstoffe, Diät — Enthalten/Spuren zuerst, Vollliste eingeklappt |

CSS-Klassen für Massen-Markup: `fa-table` (+ `num`-Zellen, `fa-table--compact`), `fa-surface`, `fa-control`.

## Detail-Panels (Anatomie, Dominique 2026-10-05)

Zweck: auf einen Blick sehen, wie das Element ist (Preis, EK, Allergene, Aufbau …), OHNE in den Editor zu klicken.
Inhalt also vollständig, nicht gekürzt. Alle Panels laufen im dunklen Rahmen (`detail-sidebar` setzt
`data-fa-theme="dark"`), wie Navigation und Chat-Leiste. Unter 1.536 px Schublade über dem Inhalt.

Feste Reihenfolge:
1. **Kopf** `<x-fa::detail-kopf>`: Name, Untertitel, Zustands-Chips; GENAU EINE Hauptaktion („Im Editor öffnen");
   alles Weitere (Drucken, Report, Duplizieren, Vorlage …) im Menü „Weitere Aktionen" mit `<x-fa::menu-item>`,
   Löschen als letzter Eintrag (`danger`).
2. **Kennzahlen** `<x-fa::kpis>`: höchstens 3, eine Hauptzahl; fehlende Werte als „Preis fehlt"/„fehlt".
3. **Offene Punkte** `<x-fa::signal>`: was vor der Verwendung fehlt.
4. **Deklaration**: Allergene, Diät (`<x-fa::deklaration>` bzw. Diät-Chips) mit Konfidenz-Satz.
5. **Inhalt**: Aufbau / Zutaten / Artikel / Positionen (`<x-fa::section variant="plain">`).
6. **Fachabschnitte**: Bewertung, Nährwerte, Sensorik, Preise …
7. **Verwendung**: „Wo verwendet?".

Titel der Rail: „Detail" (Ausnahme nur, wenn der Typ-Name mehr sagt: „Tagesdetail", „Auftrag" …).
Muster: `livewire/concepter/detail-panel.blade.php`.

## Werkbank-Modus

Ein Container mit `data-fa-theme="dark"` schaltet **nur die Tokens** um. Alle Bausteine färben sich mit.
Keine Überschreib-Regeln, kein `!important` — der alte dunkle Editor brauchte 43 davon und war
deshalb nicht wartbar (jede neue Fläche brauchte eine eigene Regel). Wirksam wird der Modus für
einen Editor, sobald dessen View auf Bausteine umgestellt ist.

## Regeln (vom Test erzwungen)

`tests/Unit/UiRegelnTest.php` + `tests/Support/UiRegeln.php` — Sperrklinke:

1. Keine Hex-Farben in Views.
2. Kein `!important`, keine Tailwind-`!`-Modifier.
3. Keine Emoji als Symbole (Heroicons nutzen).
4. Keine Schrift unter 12 px (`text-[8–11px]`).
5. Keine `<style>`-Blöcke in Views.
6. `style="…"` nur mit Laufzeitwert (`{{ … }}`).

Bausteine unter `components/fa/` müssen alle Regeln zu 100 % einhalten. Alle anderen Views dürfen
nicht schlechter werden als ihr eingefrorener Stand (`tests/Fixtures/ui_regeln_baseline.json`).
Nach jedem Umbau den Stand neu einfrieren — die Klinke wird enger, nie lockerer:

```bash
FA_UI_BASELINE_SCHREIBEN=1 vendor/bin/pest --filter=UiRegeln
```

Ausgangsstand 2026-10-05: 194 Dateien mit Altlasten — 1.956 Mini-Schriften, 714 `!important`/`!`,
536 Hex-Farben, 229 Emoji, 186 statische Inline-Stile, 24 Style-Blöcke.

## Eine View umbauen (Checkliste)

1. Funktion bleibt: `wire:*`, `data-*`-Marker (Tests!), Event-Namen und Feldnamen unverändert.
2. Klassen-Strings aus `Ui::maps()` → Bausteine ersetzen.
3. Hex/Emoji/Mini-Schrift/Style-Blöcke entfernen → Tokens, Heroicons, Skala.
4. Status-Dropdown je Zeile → `x-fa::status`; Änderung über Mehrfachauswahl oder Detail.
5. Auswahl mit 2–6 Optionen → `x-fa::choice`; mehr → `x-fa::select`.
6. Eine Hauptaktion je Fläche (`primary`, rechts); Löschen nie direkt neben Speichern.
7. Copy: Deutsch, Sprache der Küche, keine Entwicklerbegriffe, keine Gedankenstriche als Stilmittel.
8. Reiter: keine nackten Mengen-Zähler („Aufbau 1"). Eine Zahl am Reiter nur für Offenes/Handlungsbedarf und dann mit Bedeutung (z. B. Signal-Zähler).
9. Suite + Regel-Test grün, Baseline neu einfrieren, Vorher/Nachher-Screenshot.

## Logos

`resources/brand/` — `fa-mark.png` (Zeichen), `fa-mark-on-dark.png` (für die Navigation),
`fa-wordmark.png`. Quelle: `09_MEDIEN/Fooa.Al Logo einzl.png`, `09_MEDIEN/Food.Alchmist.png`.
