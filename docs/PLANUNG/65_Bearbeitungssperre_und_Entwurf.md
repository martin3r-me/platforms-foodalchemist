# 65 · Bearbeitungssperre und Speichern erst auf Knopfdruck

**Stand 2026-10-07 · Status: Entwurf zur Freigabe (Dominique)**

> Bezug: [61 · Rollen und Rechte](61_Rollen_und_Rechte.md) (wer darf bearbeiten), MCP im Lockstep.
> Kein Eingriff in Core.

## Anlass

- Im Gericht-Editor eine Komponente eingesetzt, **nicht** gespeichert, trotzdem gespeichert (Dominique 2026-10-07).
- Mehrere Personen können dasselbe Rezept, dasselbe Foodbook, dieselbe Speisekarte usw. gleichzeitig öffnen.
  Wer zuletzt speichert, überschreibt die anderen, und das ohne jeden Hinweis.
- Das betrifft **jeden Editor mit „Speichern“**, nicht nur Rezepte.

## Entscheidungen (Dominique 2026-10-07)

| Frage | Entscheidung |
|---|---|
| Bearbeiten | Ein Editor öffnet **nur zum Lesen**. Erst **„Bearbeiten“** schaltet frei und sperrt den Datensatz für alle anderen. |
| Speichern | Im Bearbeiten-Modus gilt **alles erst mit „Speichern“**. „Verwerfen“ setzt zurück. Kein Sofort-Speichern mehr. |
| Ablauf | Die Sperre fällt nach **15 Minuten ohne Aktivität**. Solange man arbeitet, verlängert sie sich. |
| Gilt für | alle Editoren mit Speichern (Liste unten) |

## Verhalten

1. **Öffnen:** Der Editor zeigt alles, Felder sind schreibgeschützt. Oben steht der Knopf **„Bearbeiten“**.
2. **„Bearbeiten“:**
   - Ist der Datensatz frei, bekommt man die Sperre und die Felder werden aktiv.
   - Ist er gesperrt, erscheint der Hinweis „Wird gerade von *Name* bearbeitet (seit 10:42)“ und der Editor bleibt im Lesemodus.
3. **Während des Bearbeitens:**
   - Ein Herzschlag (alle 60 s, nur bei Aktivität) verlängert die Sperre.
   - Kopf des Editors: Status „Du bearbeitest · nicht gespeichert“.
4. **„Speichern“:** schreibt alle Änderungen in **einer** Transaktion und gibt die Sperre frei, der Editor geht zurück in den Lesemodus. Optional: „Speichern und weiter bearbeiten“ behält die Sperre.
5. **„Verwerfen“ / Schließen mit ungespeicherten Änderungen:**
   - Nachfrage „Änderungen verwerfen?“.
   - Danach Sperre frei, nichts geschrieben.
6. **Ablauf:**
   - Nach 15 Min ohne Aktivität fällt die Sperre.
   - Kommt die Person zurück, sagt der Editor „Deine Sperre ist abgelaufen“.
   - Ist der Datensatz noch frei, kann man neu sperren und speichern.
   - Hat inzwischen jemand anders gespeichert, wird **nicht** blind überschrieben (siehe Schutz 2).
7. **Lösen durch Admin:** Plattform-Admin und Team-Admin (Spec 61) sehen „Sperre lösen“. Das wird protokolliert.

### Zwei Schutzschichten

- **Schutz 1 – Sperre:** verhindert gleichzeitiges Bearbeiten im Normalfall.
- **Schutz 2 – Versionsprüfung beim Speichern:** Speichern prüft, ob der Datensatz seit dem Öffnen geändert wurde (`updated_at` bzw. eine Versionsnummer). Wenn ja, wird nichts geschrieben und es erscheint der Hinweis „Inzwischen von *Name* geändert“. Das fängt abgelaufene Sperren, MCP-Schreibzugriffe und Hintergrund-Jobs ab.

## Technik (Skizze)

- **Tabelle `foodalchemist_bearbeitungssperren`:**
  - `team_id`, `sperrbar_type`/`sperrbar_id` (morph), `user_id`
  - `seit`, `laeuft_ab`, `sitzung` (Browser-Tab, damit ein zweiter Tab derselben Person nicht doppelt sperrt)
  - unique auf `sperrbar_type` + `sperrbar_id`
- **`Services/Bearbeitungssperre`:** `sperren`, `verlaengern`, `freigeben`, `status`, `loesen` (Admin). Atomar über unique-Index und „abgelaufen = frei“.
- **Livewire-Trait `MitBearbeitungssperre`** für alle Editoren:
  - `bearbeiten()`, `verwerfen()`, Herzschlag (`wire:poll.60s`, nur im Bearbeiten-Modus)
  - `$istBearbeitbar` für die View, Freigabe beim Schließen
- **Blade-Baustein `x-fa::bearbeiten-leiste`:** Knopf, Sperr-Hinweis, Status, Admin-Lösen. Gleich in allen Editoren.
- **Entwurf statt Sofort-Speichern:**
  - Teil-Editoren (Schritte, Komponenten, Fotos, Eignung …) schreiben nicht mehr direkt.
  - Sie halten ihren Stand im Editor (wie heute schon der Zutaten-Editor, Alpine-first).
  - Das Haupt-„Speichern“ sammelt alles ein und schreibt es in einer Transaktion.
  - **Das ist der große Teil der Arbeit** und wird je Editor umgestellt.
- **MCP:** Schreibende Tools prüfen die Sperre. Ist der Datensatz von einer Person gesperrt, kommt die Antwort `gesperrt` mit Name und Ablaufzeit statt eines stillen Überschreibens. Lesen bleibt erlaubt.
- **Hintergrund-Jobs** (Recompute, Anreicherung) sind keine Bearbeitung durch Personen. Sie laufen weiter, und die Versionsprüfung (Schutz 2) fängt Überschneidungen ab.

## Betroffene Editoren

| Editor | Komponente | Sofort-Speichern heute (bekannt) |
|---|---|---|
| Basisrezept | `Recipes/RecipeModal` (+ `IngredientEditor`, `StepEditor`, Fotos) | Schritte, Fotos, Eignung |
| Gericht | `Verkauf/VkModal` | **Komponenten** (Befund), Darreichungen u. a. |
| Grundprodukt | `Gps/GpModal` | Aroma-Anker, … |
| Lieferantenartikel | `Suppliers/ItemModal` | Allergene, Deklarationen, Nährwerte, Preise |
| Foodbook | `Foodbooks/Index` | zu erheben |
| Speisekarte | `Speisekarte/Index` | zu erheben |
| Speiseplan | `Speiseplan/Editor` | zu erheben |
| Angebot | `Angebote/Editor` | zu erheben |
| Concepter | `Concepter/Editor` | zu erheben |
| Konzepte / Pakete | `Concepts/Index`, `Pakete/Index` | zu erheben |
| Formate | `Formate/Editor` | zu erheben |
| Produktion | `Produktion/Editor` | zu erheben |
| Einstellungen | `Settings/*` | **später/optional:** selten parallel, meist eine Person je Team |

## Umsetzung in Paketen

| Paket | Inhalt | Wirkung |
|---|---|---|
| **A** | Sperre-Fundament: Tabelle, Service, Trait, Bearbeiten-Leiste, Admin-Lösen, Versionsprüfung beim Speichern, MCP-Prüfung. Alle Editoren bekommen „Bearbeiten“ + Sperre. Sofort-Speicherstellen sind im Lesemodus deaktiviert und funktionieren nur mit Sperre. | Paralleles Bearbeiten ist ab sofort verhindert. |
| **B** | Gericht-Editor: Komponenten, Darreichungen usw. auf Entwurf, schreiben erst mit Speichern. | Behebt den gemeldeten Befund. |
| **C** | Basisrezept-Editor: Schritte, Fotos, Eignung auf Entwurf. | |
| **D** | Foodbook, Speisekarte, Speiseplan, Angebot | je Editor eigener PR |
| **E** | Concepter, Konzepte, Pakete, Formate, Produktion, GP, Lieferantenartikel | je Editor eigener PR |

Paket A ist der schnelle Schutz. B bis E sind die gründliche Umstellung „erst mit Speichern“. Jedes Paket ist ein eigener PR mit Vollsuite, danach der demo-Pin.

## Offen

- **O1:** „Speichern und weiter bearbeiten“ als zweiter Knopf, oder bleibt man nach Speichern immer im Bearbeiten-Modus?
- **O2:** Fotos: Ein Upload braucht eine Datei auf dem Speicher. Entwurf heißt, die Datei liegt vorläufig da und wird bei „Verwerfen“ gelöscht. Ist das so in Ordnung?
- **O3:** KI-Aktionen im Editor („KI-Assistent“, Anreichern): Ergebnis als Entwurf zeigen und erst mit Speichern übernehmen? Heute schreiben einige direkt.
- **O4:** Einstellungen mit Sperre, ja oder nein?

## Changelog

- 2026-10-07: Entwurf (Claude) nach Befund Dominique + Entscheidungen Bearbeiten-Knopf / erst mit Speichern / 15 Min.
