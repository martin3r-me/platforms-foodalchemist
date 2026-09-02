# 44 · Voice als Produkt-Agent + Briefing → Leitplanken

**Stand 2026-09-02 · gebaut, Browser-Abnahme offen**

Dominique: *„Das Mikrofon ist der eigentliche MCP-Agent im System — da ist die Spiegelung
zu System in der UI."* Und: *„Ich gebe der KI ein Briefing und die Leitplanken und sie baut
ein vernünftiges Rezept."*

## Die Arbeitsteilung — mit Zahlen belegt

| | Runden | Token | Zeit |
|---|---|---|---|
| `voice.command`, 1 Tool (gemessen) | 2 | 4.687 | 18–21 s |
| Rezept agentisch (geschätzt daraus) | 6–10 | 25.000–40.000 | 1–2 min |
| Rezept Single-Shot (gemessen) | 1 | **13.417** | — |

Ein 30-Zellen-Speiseplan agentisch: ~50 min, ~1 Mio Token. Dazu drei Gründe, die nicht
Kosten sind: die deterministische Schicht **nach** der Generierung (Matcher §4/§5,
Band-Gates, Diät-/Allergen-Gate, Dedup, Recompute) braucht einen vollständigen Vorschlag;
`knowledge_used` würde modellabhängig statt reproduzierbar (Conformance-Critic und
Golden-Tests verlieren ihre Grundlage); und der Tool-Katalog steckt im Prompt, jede Runde
zahlt die Schemas mit.

⇒ **Agent ans Briefing, Single-Shot an die Produktion.** Die Leitplanken sind die Brücke.

## Ebene 1 — globale Sprachsteuerung (Sidebar)

`AiGatewayService::callWithTools($auftrag, $toolNames, $maxRuns, $optionen)`.

**Warum eine Policy und keine Whitelist** (demo, live erhoben):

| | |
|---|---|
| FA-Tools in der Registry | 429 (Registry gesamt 1.736) |
| davon `read_only = true` | 111 |
| Schemas dieser 111 | **78.348 Zeichen ≈ 26.000 Token — pro Runde** |
| FA-Tools ohne das Flag | **0** |
| Basiskatalog (Warmstart + Discovery) | 6.366 Zeichen ≈ 2.122 Token |

`$optionen['policy']`: FA-Namespace **und** `read_only === true`, oder explizit ein
Proposal-Tool. Fail-closed. Die Freischaltung wächst im **Gate**, nicht im Prompt — der
Katalog in der System-Message bleibt der Basiskatalog (byte-stabil, W3-1), ein zugelassenes
Tool kostet null Prompt-Zeichen. Ein morgen hinzukommendes lesendes Tool ist sofort
erreichbar, ein schreibendes strukturell nicht.

`$optionen['arg_guard']`: Commit-Flags werden vor der Ausführung auf `false` gezwungen.
Liste aus den echten Schemas erhoben: `confirm` (24 Tools), `accept` (4), `apply` (3),
`force` (2).

### Zwei Fallen, die GL-07 aufweichen würden

1. **`match_proposals.PUT`** trägt „proposals" im Namen, **übernimmt** aber einen
   LA→GP-Match. **Kein** Proposal-Tool. Ein Namensfilter hätte dem Agenten erlaubt,
   Mappings festzuschreiben.
2. **`recipe_klasse.POST`** ist ein Proposal-Tool, **schreibt aber bei `accept: true`** —
   das Loch bestand seit M8-01, weil das Tool in der Voice-Whitelist stand und der
   Aufrufer (das Modell) das Flag setzen konnte.

### Platzierung

`Livewire\VoiceModal` (vorher `Livewire\Recipes\VoiceModal`), global in
`livewire/sidebar.blade.php` gemountet, Knopf auf Betriebs-Wähler-Ebene. Der Knopf auf der
Basisrezepte-Seite ist entfernt — eine Modal-Identität, ein Mount.

**Grenze, die bleibt:** `x-ui-sidebar` rendert den Modul-Slot in
`<template x-if="!collapsed">`. Eingeklappt ist der FA-Sidebar-Inhalt aus dem DOM — Knopf
und Modal teilen dieselbe Sichtbarkeit. Eine eingeklappte Variante wäre nie gerendert
(wie FAs `x-show="collapsed"`-Icon-Leiste, toter Code). Kein Teleport nötig: `aside` trägt
nur `relative`, kein `transform`.

**Nebenbefund, offen:** der globale `saved-toast` hängt an derselben Stelle ⇒ eingeklappte
Sidebar heisst heute **keine Speicher-Toasts**.

## Ebene 2 — Sprache fürs Briefing

Dort ist Sprache **Eingabe**, nicht Steuerung: reines STT, kein Tool-Loop. Das Briefing soll
exakt das sein, was der Mensch gesagt hat. Diktat hängt an, überschreibt nie.

Gedeutet wird danach in **einem** sichtbaren Schritt: `leitplankenAusBriefing($scope)` →
Prompt `planung.leitplanken` (Tier B, 900 Tk, temp 0) → Regler dieses Tabs. Die Sitzung
wird absichtlich nicht mitgegeben; persistiert wird am Go (Start-Tab-Regel).

**Die Leitplanke gegen Halluzination ist die Wert-Prüfung, nicht der Prompt.** Vorher prüfte
`ALLOWED_GENERATION_PARAMS` nur Keys — ein erfundener Wert lief stumm durch *und ins Leere*,
weil das Achsen-Mapping für Unbekanntes nichts findet. Jetzt: kanonisches Vokabular in
`FoodAlchemistPlanningSession::ALLOWED_GENERATION_VALUES` (7 Regler / 29 Werte), Prüfung
eintragsweise, und das Befund-Panel trennt **gesetzt · offen · verworfen · nicht auf diesem
Tab**. Ein Formtreue-Guard hält Multi-Regler als Array (ein String in `diaet_hart` würde
`reglerPill()` zerlegen).

Bewusst **nicht** validiert: `frische`, `sektor`, `kompositions_stil` — je zwei
konkurrierende Wertesätze im Code. Eine Validierung, die Legitimes verwirft, ist schlimmer
als keine. Die Vokabular-Widersprüche sind ein eigener Aufräum-Schritt.

## Offen

- **Browser-Abnahme der Platzierung** — auf der Entwicklungsmaschine fehlt Dev-MySQL,
  Platzierung ist am CSS belegt, aber nicht gesehen. `Livewire::test` ist layout-blind.
- Agentischer Briefing-Dialog (Rückfragen als Gespräch statt als `unklar`-Liste).
- `saved-toast` im eingeklappten Zustand (siehe Nebenbefund).
