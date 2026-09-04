# Spec 44 — Rezept-Tausch (Verwaltungs-Block für Basisrezepte)

> **Tracking:** Office Dev-Package 23, Features-Board. Kein DB-Schema — reine Service-/Livewire-/View-Arbeit plus ein MCP-Tool. Zieht das nach, was der **GP** seit 2026-07-02 hat (`GpService::ersetzeInRezepten`), und räumt die Auffindbarkeit des GP-Pendants auf. Das Löschen folgt im zweiten Schritt.

**Status:** ✅ Tausch umgesetzt auf `feat/rezept-tausch` (2026-09-04). Lösch-Teil folgt als zweiter Commit. Nicht deployt.

---

## Anlass

Beim Grundprodukt gibt es im Detail-Panel unter *Verwaltung* zwei Dinge: „In allen Rezepten ersetzen durch …" und „GP löschen" (blockiert, solange referenziert). Für **Basisrezepte** fehlte beides:

- Wer merkt, dass zwei Fonds dasselbe sind, musste **jedes Gericht einzeln öffnen** und die Zeile umhängen.
- `RecipeService::delete()` sagte dazu nur *„wird als Sub-Rezept referenziert von: … — erst dort lösen"* — ohne einen Weg anzubieten, genau das zu tun.

Zweiter Anlass (Dominique): „das aus dem Detail-Panel fehlt auch im Editor vom GP". **Befund: es fehlte nicht, es war unfindbar** — Commit `fe600db8` (2026-08-27) hatte den GP-Tausch in den Reiter **Kalkulation** gelegt, und die Kandidaten-Liste zeigte dort `{{ $k->status }}` auf einem Enum-Cast, also den rohen String `tentative` statt Label + Farb-Pill.

## Kern-Entscheidungen (User, live)

| # | Entscheidung | Begründung |
|---|---|---|
| 1 | Der Tausch fasst **nur die Komponenten-Zeilen** an (`recipe_ingredients.referenced_recipe_id`) | Alle sechs Ausgabe-Ebenen (Foodbook-Blöcke, Speisekarte-Positionen, Speiseplan-Zeilen, Angebot-Blöcke, Konzept-Slots, Paket-Gerichte) hängen an `sales_recipe_id`, also am **Gericht**. Ein Basisrezept steht in einem Foodbook nur ÜBER das Gericht — tausche ich es im Gericht, folgen die Ausgaben von selbst. |
| 2 | **`swap_locked` wird ignoriert** | Das Flag schützt die *Fertig-/Selbst*-Realisierung. Ein Rezept→Rezept-Tausch ändert nicht die Realisierung, sondern nur *welches* Rezept es ist — derselbe Fall wie beim GP-Tausch, der das Flag ebenfalls nicht beachtet. |
| 3 | Verwaltungs-Block liegt in Panel **und** Editor, im Editor als eigener Reiter **Verwaltung** | Genau die Lehre aus dem GP-Fall: in „Kalkulation" findet es niemand. Der GP-Block ist mitgewandert, Panel und Editor teilen jetzt **ein** Blade-Partial statt zweier Kopien. |

## Umsetzung

### Tausch

- `RecipeService::verwendungsBilanz($team, $id)` — trennt **eigene** (umhängbar) von **geerbten** Verwendungen.
- `RecipeService::ersetzeInVerwendungen($team, $von, $nach)` — mandantenscharf (D1): geschrieben wird nur in Eltern des eigenen Teams, geerbte Master-Eltern bleiben unberührt und werden **gezählt gemeldet**. Zyklus/Selbstreferenz je Eltern-Rezept über das vorhandene `RecipeRecomputeService::pruefeVerknuepfung()`; Menge, Einheit und Verlust-Overrides bleiben; `match_method` wandert auf `override_subrecipe` (dieselbe Provenienz wie der Hard-Stop-Resolver — ein stehengelassenes `gemini_proposed` wäre ein falsches Etikett); Recompute der betroffenen Menge **einmal topologisch** (`recomputeMany`, V-049).
- Rückmeldung nennt auch das Unangenehme: geerbte Eltern, Zyklus-Ablehnungen und „Ziel steckte hier schon drin — jetzt zwei Zeilen, Mengen prüfen".

### Oberfläche & MCP

- `src/Livewire/Concerns/TauschtRezept.php` — Mechanik einmal für Panel + Editor.
- `resources/views/livewire/recipes/partials/verwaltung.blade.php` — ein Partial, zwei Einbau-Orte (`kompakt` schaltet Panel- gegen Editor-Typo).
- `foodalchemist.recipes.REPLACE` (MCP-Lockstep zu `gps.REPLACE`): `from_id`/`to_id`/`confirm`, Rückgabe inkl. `geerbt_unberuehrt`, `uebersprungen_zyklus`, `ziel_war_schon_drin`.

## Tests

`tests/Feature/RezeptTauschTest.php` (7) + Ergänzungen in `McpRecipesLifecycleTest` und `GpTauschEditorTest` (Reiter-Position festgenagelt).

## Offen

- Lösch-Knopf mit Referenz-Bilanz (zweiter Commit).
- Volle Pest-Suite + Browser-Abnahme (Panel · Rezept-Editor · GP-Editor).
- Deploy demo (kein `migrate` nötig).

## Cross-Refs

- **Spec 36** Rezeptqualität (Matching-Achse) · **Spec 41** Planungsmodul-Qualität (Grounding) · **Spec 43** Schicht-3-Critic (Konformität)
