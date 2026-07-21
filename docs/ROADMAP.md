# ROADMAP — Food Alchemist

> Ausführungsplan zu [[GOALS]] (Stand 2026-07-03). Jedes Arbeitspaket hat eine **Definition of Done (DoD)** —
> messbar, nicht verhandelbar. Ein Paket ohne erfüllte DoD ist „in Arbeit", nie „fertig".
> Tracking: Dev-Modul, Package `platforms-food-alchemisten` (ID 23). Diese Datei ist die Landkarte, das Dev-Modul der Tacho.
>
> **📁 Tiefe Feature-Specs → [`docs/PLANUNG/`](PLANUNG/00_Orchestrierung_Naechste_Schritte.md)** (seit 2026-07-18): pro Thema eine Datei (01–14), Einstieg über `00_Orchestrierung` (Phasen 0–5, Abhängigkeiten, Blocker). Die ROADMAP bleibt die Landkarte; die Detail-Pläne, DoDs je Etappe und Session-Stände leben dort.

---

## ⭐ Strategie-Update 2026-07-11 (überschreibt Sequenz-Annahmen von 2026-07-03)

**Einstiegspunkt für die nächste Session: [`_NEXT_SESSION_TODO.md`](_NEXT_SESSION_TODO.md)** (konkrete To-do).

Beschlossen (Dominique):
- **FUNKTION zuerst, auf kleinen sauberen TESTDATEN (Seeder).** NICHT die echten 600 MB in MySQL beim Feature-Bauen — Schema churnt, echte Daten jedes Mal neu importieren wäre Wahnsinn. `migrate:fresh --seed` = Sekunden-Reset. Echte Daten (`import-master`) kommen EINMAL am Ende, wenn Funktionen stabil.
- **Wert-Features (Horizont 1) sind datengated:** nur ~2/1037 Gerichte bepreist → **VK-Preise (R1.2) = Gate** — aber bewusst NACH der Funktions-Phase (Daten verbessern statt löschen).
- **Architektur:** EINE SQL (lokales MySQL = Kanon, Migration installiert/vorbereitet) = Wahrheit + Laufzeit + Rechenbasis. **Wissens-DB lebt IN FA** (`knowledge_documents/aliases/routings`, deterministisch, on-demand, pflegbar via #469 — kein separates Modul). **GRAPH KOMPLETT RAUS** — kein Kùzu/Neo4j/SPARQL, weder Runtime noch Linse noch Autoren-Schicht; Mehr-Hop/Bridging via MySQL-8.4-`WITH RECURSIVE`. Kùzu/Neo4j-Artefakte = reine Historie.
- **Datenmodell-Depth (Ebene 3–5: Muttersaucen-Vererbung, Geschmacks-Editoren, Gericht-Textur/SKF, Event-Dramaturgie) = nachrangig (R6-Thread)** gegenüber Funktion + Pricing-Gate.

Details/Historie: Memory `project_fa_klarschiff_cleanup.md` + `_MEMORY_FoodBrain.md`.

## ⭐ Update 2026-07-21 (Session: Bestellwesen mini-WaWi — Spec 17 + S0)

**Neuer N-Track angestoßen: mini-WaWi Bestellassistent mit Bestellschienen pro Lieferant** (der von R9 §7 bewusst ausgeklammerte N-Track; Dominique-Wunsch). Auslöser war die Kritik an den R7.1-Planungs-Blättern (100×-Rezept-Label, kg-statt-Gebinde, Bestellung==Einkauf). **OHNE Bestand** (Bestellassistent, kein Lager/Wareneingang) — ein FA-Bereich, kein zweites Composer-Modul. Spec-first: `docs/PLANUNG/17_Bestellwesen_MiniWaWi.md` (E1–E11), Dev **#549**.

- **S0 GEBAUT+GETESTET (dieser Commit):** `GebindeRechner` (pure/read-only: aggregierter GP-Bedarf → ganze Gebinde, Artikel-Nr/Gebinde-Preis/Zeilensumme, Überkauf-Rest; Stk via Stückgewicht sonst ehrlicher kg-Fallback; `qty=NULL`-Preisfalle transparent). `PlanungsblattService` verdrahtet — `gruppiereNachLieferant` liefert `gebinde` je Position + **echte** EK-Summe (ganze Gebinde statt Gramm-Theorie), `explodiere` gibt VK-`portionen` aus.
- **Blatt-Fix:** Bestell-Blatt (Live + PDF) auf Gebinde-Spalten (Artikel · Bestellen · Bedarf · EK); Produktion „100× Rezept" → **„N Portionen · gesamt kg"**; „Einkauf"-Dublette aus der UI raus (Livewire-Default `['produktion','bestellung']`, `einkaufsliste()`-Service bleibt für S2-Event-Aggregation). `BestellvorschlagGetTool` im Lockstep.
- **Verifikation:** `GebindeRechnerTest` 10/10 + `PlanungsblattServiceTest` 8/8 (Einkaufs-EK auf echte Gebinde-Summe nachgezogen); volle FA-Suite **906/908** (1 skip, 1 vorbestehender `VoiceHuellenTest`-Fehler ohne S0-Bezug), 0 Regressionen. Blade-Lehre: `@endif`/`@else` nie an ein Wort kleben (`kg@endif` → literal).
- **Offen:** Live-Klickstrecke demo (Martin-Deploy); S1 (`suppliers.delivery_days`/`order_cutoff`) → S2 (persistente Bestellschiene `orders`/`order_lines` = Kern, E1/E2/E9/E10/E11) → S3 (PDF/CSV/mailto + `orders.*` MCP).

## ⭐ Update 2026-07-21 (Session: Signale → Tab-Cockpit)

**Die „Signale"-Seite (`/zu-pruefen`, `ReviewQueue`) zum Tab-Cockpit umgebaut** — reine Darstellung/Read-only, Detektor/Signal-Emission/Services-Logik + MCP unverändert. Commit `39c5470`.

- **5 Tabs** (Überblick · Signale · KI-Vorschläge · Matches & Terminologie · Pflege), Start auf Signale; Überblick mit klickbaren KPI-Kacheln + kritischsten Signalen.
- **Signal-Zeilen house-style neu** (`partials/_signal-row.blade.php`): Severity-Akzentbalken, Typ-Icons (aus `SignalTyp::icon()`), klare Hierarchie, aufgeräumte Filter (Segment-Status + auf offene Typen getrimmte Pills).
- **„KI erledigen lassen"** je tauglichem Signaltyp (`Support\SignalCockpit`-Affordance-Map: Auto-Fix vs. KI-Assistenz) — Ausführung bewusst **nachgelagert** (Steuer-Rahmen, „kommt bald"). Nicht-fixbare Typen (Nährwert/Widerspruch/Vertragsfrist/veraltete Preise) bewusst ohne Knopf.
- **„Reinschauen"** je Signal: betroffene Objekte **on-demand read-only** aufgelöst (neu `DataQualityService::betroffene()` — gleiche Prädikate wie der Zähler, kein Drift), klickbar ins Rezept-Modal bzw. GP-Browser; >50 gekürzt + „N weitere".
- **Verifikation:** Sandbox visuell (alle Tabs, KPI-Klick, KI-Panel, Reinschauen für Rezepte + GPs mit 50/182-Kürzung), `php -l` 0 Fehler, keine Konsolen-Fehler.
- **Offen/nachgelagert:** echte KI-Fixer/Assistenzen pro Typ verdrahten (dann MCP-Lockstep + Dev-Issue); Foodbook-Cockpit-Session läuft parallel (andere Dateien).

## ⭐ Update 2026-07-21 (Session: Geschmacks-Profil entdoppelt → Spinnennetz)

**Dopplung im Rezept-/VK-/GP-Editor + Concepter beseitigt:** Der Sensorik-Tab zeigte zwei optisch identische 7-Achsen-Balkenblöcke — „Geschmacks-Balance · sensorisch" (gegarte KI-Sensorik, `SensorikService`, DB `recipe_taste_vectors`) und „Geschmacks-Profil · Aroma-Anker" (gemittelte Anker-Vektoren, `PairingService`, DB `anchor_taste_vectors`). Beide auf 0–1, aber verschiedene Quelle/Semantik (Abgrenzung stand als `#503`-Kommentar im Code). Ersetzt durch **ein** Spinnennetz (Coffee-Cupping-Optik, Brand-Violett).

- **Neu:** `resources/views/livewire/concepter/partials/geschmack-radar.blade.php` — server-gerendertes SVG-Radar (7 Achsen, PHP-berechnete Koordinaten, konzentrische Ringe + Speichen + Zentrum-Glow, dominant/Lücke-Punktfarben) + Alpine-Tooltip. Kein JS-Chart/CDN, kein `wire:ignore` nötig. Farben inline-literal (JIT-Klassen-Falle beachtet).
- **Fläche = gegarte Sensorik**, **Aroma-Anker-Wert je Achse im Tooltip** (Hover) — beide Quellen sichtbar, aber nur ein Polygon. Der separate Aroma-Anker-Block entfällt (2 `@include`-Zeilen aus `pairing.blade.php` raus, recipe- + gp-Branch).
- `sensorik.blade.php` (greift in Rezept/VK/GP) + Concepter-Inline-Block (`editor.blade.php`, `ankerGeschmack => []`, da im Sensorik-Tab kein `$pairing` im Scope) auf das Radar-Include umgestellt; Lücke/dominant-Chips + Textur-Profil bleiben. Altpartial `partials/geschmack.blade.php` gelöscht (verwaist).
- **Verifikation:** alle 4 Blades mit echtem Blade-Compiler übersetzt → `php -l` 0 Fehler; Radar-Partial (beide Pfade) + `sensorik`-Partial end-to-end mit realen Services (Rezept 461, `source=ki`) gerendert — 1 Polygon, alle 7 Anker-Achsen im Tooltip; `pairing`-Partial: Aroma-Anker-Card weg, Kohäsion/Kontrast intakt.
- **Layout-Iteration (gleiche Session):** Sensorik-&-Pairing-Tab entstapelt. Sensorik-Reihe = Grid (`lg:grid-cols-3`): Radar-Karte (`col-span-2`, Radar links + Beschreibung/Chips rechts) neben Textur-Profil — statt zwei voller Karten mit Leerraum. Pairing-Layer: Aroma-Kohäsion bleibt volle Breite (Headline-Score), der Rest (Kern-Anker/Passt-erprobt-zu/Verwandte/Molekular/Kontrast bzw. GP-Branch) in `lg:grid-cols-2 xl:grid-cols-3`-Grid. Karten-Ränder auf Grid-`gap` normalisiert. Real im Sandbox verifiziert (Rezept 461: Radar+Chips nebeneinander, 3-spaltige Pairing-Layer, Kohäsion full-width).
- **Offen (optional):** VK-„Teller-Profil" (`sensorik_komposition.blade.php`) noch Balken — Folge-Kandidat für Konsistenz.

## ⭐ Update 2026-07-21 (Session: Foodbook-PDF-Redesign — Book-Look + pro-Foodbook-Branding + Härten)

**Das versendbare Foodbook-Dokument (`/foodbooks/{id}/dokument`) neu gestaltet** — Ziel-Bar: **Vertriebs-Arbeitsdokument** (sauber/markengerecht/strukturiert), NICHT der End-Kunden-Showpiece (eigener, späterer Track). Engine bleibt **DomPDF** (kein Server-Zusatz). Referenz: die drei echten Caterer-Foodbooks (Broich/TM/DOEC) — ein Layout-Skelett, pro Marke andere Tokens.

- **Härten (Phase 1):** DomPDF in `composer.json` deklariert (`^3.1`, war nirgends deklariert → plug-and-play); stiller HTML-Fallback bei fehlendem DomPDF → expliziter Fehler + `Log::warning` (foodbook + angebot + blatt); falscher „PDF-Bookmarks"-Kommentar korrigiert.
- **Pro-Foodbook-Branding (Phasen 2–3):** neue Spalten an `foodalchemist_foodbooks` (`brand_color` Default `#6d28d9`, `band_color`, `logo_path`, `cover_image_path`, `footer_text`; additiv, Bestand unverändert). UI-agnostische `FoodbookService`-API `setBranding`/`storeLogo`/`storeCover`/`clearLogo`/`clearCover` (Hex-Validierung, Owner-Guard D1, public-Disk). `dokumentDaten` liefert `branding`-Key; Logo/Cover als **base64-Data-URI** (DomPDF lädt keine http-URLs, `enable_remote` aus).
- **Blade-Redesign (Phase 4):** Farben tokenisiert (kein `var()` — DomPDF-Echo); gebrandete Cover-Seite; Inhaltsverzeichnis (klickbar); wiederkehrende Kopf-/Fußbänder full-bleed via `position:fixed` + Negativ-Offset; Seitenzahl „X / Y" auf jeder Seite (`page_text` am Body-Ende + `isPhpEnabled`); Konzept-Blöcke im Referenz-Layout (Preis links „x € pro Person", Inhalt rechts, Marken-farbige `|`-Pipes). Kunden-/interne Sicht + MwSt + Wareneinsatz-Tabelle erhalten.
- **Umbruch (Phase 4b, gegen mehrseitigen Inhalt gehärtet):** DomPDFs `page-break:avoid` (after/inside) UND `float` in `position:fixed`-Bändern lösen eine Paginierungs-Explosion aus (ein Block/Seite + Massen-Leerseiten — per Isolationstest bewiesen: 22 Blöcke → 70 Seiten). Fix: **keine `avoid`-Regeln**, **kein `float` in den fixed-Bändern** (Logo absolut, Footer als Block); nur das zuverlässige `page-break-after: always` trennt Cover/Inhaltsverzeichnis. Ergebnis: 22 Blöcke → **6 Seiten, null Leerseiten**, Blöcke fließen sauber über Seitengrenzen.
- **Verifikation:** real gerendert (Foodbook #1 + synthetischer Konzept-Block, rot/grün, Kunden-+interne Sicht) → Cover/IV/Bänder/Logo/Pipes/Preis-Spalten/Seitenzahl bestätigt. Pest `FoodbookBrandingTest` (4/17). Regression Foodbook+MCP+DELETE-Tools **30/30 grün** (1 Test-Wording „Navigation"→„Inhaltsverzeichnis" nachgezogen).
- **NACHGELAGERT (Phase 6):** Branding/CI-Tab im Foodbook-Cockpit — wird gezogen, wenn die parallele Cockpit-Umbau-Session fertig ist; dockt nur an die obige Service-API an (Cockpit-Dateien hier bewusst nicht angefasst).
- ⚠️ **Deploy-Blocker (Fremdbefund):** Migration `2026_07_19_000009` (terminology) erzeugt einen Index-Namen mit 71 Zeichen → **frischer MySQL-`migrate` bricht ab** (64-Zeichen-Limit); blockiert auch das Deploy dieser Branding-Migration. Auf SQLite (Testsuite) unkritisch. Muss vor demo-Deploy gefixt werden (expliziter kurzer Index-Name).

## ⭐ Update 2026-07-21 (Session: MCP DELETE-Tools — Foodbook-Block + Konzept löschen)

**Lücke geschlossen, die im MCP-Audit #504 offen blieb:** die FA-MCP-Oberfläche hatte modulweit **keinen DELETE-Verb** — Konzepte ließen sich per MCP nur anlegen, nicht aus Foodbooks nehmen und nicht löschen (Cleanup nur im Editor möglich). Ursache war rein die fehlende Tool-Hülle: die Services (`FoodbookService::deleteBlock`, `ConceptService::delete` inkl. Referenz-Schutz GT-FB-4/V-06, alle team- + owner-guarded, SoftDeletes) waren vollständig und sicher.

- **`foodalchemist.foodbook_blocks.DELETE`** (Schritt 1) — entfernt einen Block aus einem Kapitel (Soft-Delete). War es ein `concept_ref`, meldet das Ergebnis, ob das Konzept jetzt in keinem Foodbook mehr steckt (`concept_now_deletable` + Restliste).
- **`foodalchemist.concepts.DELETE`** (Schritt 2) — löscht ein Konzept (Soft-Delete). Referenz-Schutz: solange in Foodbook(s) referenziert → Fehler `HAS_REFERENCES` **mit Liste der blockierenden Foodbooks** (handlungsleitend statt bloßer Count).
- **Politik:** kein künstliches Draft-Gate auf Löschen (der Editor gated es auch nicht — sonst bliebe MCP für genau diesen Cleanup blockiert); `risk_level=destructive` + `confirmation_required`; Löschen nur per expliziter ID, kein Bulk-by-Filter. Damit ist der erste DELETE-Verb-Präzedenzfall im Modul gesetzt.
- **Test:** `FoodbookConceptDeleteToolsTest` (Registry-Smoke + kompletter Zwei-Schritt-Flow inkl. Referenz-Schutz + NOT_FOUND), 3 Pest / 21 Assertions. Regression MCP+Foodbook+Concepter 34/34 grün.
- **Live erst nach demo-Deploy (Martin)** — der Connector zeigt neue Tools erst dann.

## ⭐ Update 2026-07-12 (Session: Gesamt-Bug-Audit + Master-Vererbung)

Erledigt + beschlossen (Dominique). Details: Memory `project_fa_bug_audit_2026-07-12.md` + `feedback_mcp_lockstep.md`.
- **Master-Vererbung LIVE (Kern-Mechanik):** BHG.DIGITAL (Root/Team 9) = Master; globaler Seed (`team_id NULL`) + Master-Katalog kaskadieren zu den Kind-Teams (alle Caterer sind direkte Kinder von 9); jedes Team verwaltet Eigenes; Master/Seed sind für Kinder **read-only**. Trait `visibleToTeam` = NULL-OR-Ancestry + Helper `Support/TeamScope` + Write-Guards `isOwnedBy` (Settings/Knowledge/Services) + **MCP mitgezogen**. 623 Tests grün + 2-Team-MySQL-Smoke. Gepusht (`ce4d508`/`4db3e90`). → liefert Querschnitt-**#390** (Org→Team→Projekt-Vererbung) auf Team-Ebene. Offen: **#483** „Freischalten"-Admin-Flag (Master steuert, *was* kaskadiert) + **#484** Wissens-Sichtbarkeit definieren.
- **5 Bug-Fixes gepusht** (Board #477–479): 2 MySQL-only-Crashes (`category_id`, `||`→`CONCAT`), §7-Allergen-Konfidenz rekursiv „schwächstes Glied", Recompute topologisch (Diamond-sicher), MatchService Cross-Team-IDOR. **Regel:** MCP (`src/Tools/`) muss bei JEDEM Feature im Lockstep mit — kein Retrofit (Präzedenz R0.2).
- **R1.2 = nur noch Tuning** (Downgrade vom harten Gate): Aufschläge/Regler frei justierbar (Cost-plus-Baseline reicht zum Simulieren, R2.2). Echte Zielpreise optional — nur damit der Preis-Alarm (R2.1) nicht zirkulär gegen die eigene Baseline läuft.
- **R6-Depth:** Muttersaucen-Vererbung ✅ erledigt (liegt in der Wissens-DB, #469). **St.3-Rest** (Geschmacks-Editoren-Matrix, Hybrid-Fertigprodukte) + **St.4** (Gericht-Textur/SKF) + **St.5** (Event-/Trinitas-Hyperkanten) bleiben gültig — nur relevant, falls Foodpairing zum Schwerpunkt wird.
- **3-DB-Datenmodell endgültig RAUS** (veraltet — bestätigt; Chemie/Pairing SQL-nativ, Graph raus).
- **Bulk-Skripte (105/206/layer2): NICHT auf MySQL portieren** — 206-Recompute läuft in FA (`RecipeRecomputeService::recomputeAll`); 105/layer2 als Legacy/Beleg ablegen.
- **Board-Hygiene offen:** ~17 Issues im „Done"-Slot sind nie auf `is_done` geflippt (blähen die Feature-Zahl auf, „83" ≠ 83 offen); #470 „MySQL migrieren" ist erledigt, steht aber noch in „To Do".

## ⭐ Update 2026-07-13 (Session: FA-Demo-Testrunde abgearbeitet)

Dominiques Demo-Test auf demo.bhgdigital.de → 9 Befunde (#496–#504). **9/9 umgesetzt** (#504 als eigene Session, s. u.) (Details: Memory `project_fa_demo_testrunde_2026-07-13.md` + `project_fa_mcp_audit_504.md` + `00_INBOX/_FA_MCP_Audit_504_TODO.md`). Pest **668/669** grün (1 skip), je Fix MySQL-/Livewire-Smoke, null Regressionen.
- **#497** Aroma-Netz-Crash: `PairingService::aromaNetz` `distinct()` + `ORDER BY rp.type` (nicht im SELECT) = MySQL-3065 → `distinct()` raus (Downstream dedupt via `unique('id')`); Modul-`distinct()`-Sweep sauber.
- **#500** Foodbook-Dokument-Crash: Blade `$kunde` → `$customer` (dokumentDaten liefert seit #486-Rename `customer`).
- **#499** Alle KI-Funktionen auf demo down (kein LLM-Provider gebunden → un-catchbare `BindingResolutionException`): neue typisierte `KiNichtVerfuegbarException` (RuntimeException); `AiGatewayService::provider()` guarded (`app()->bound(...)`) + **vor** dem Backoff aufgelöst (28 ms statt 28 s Sinnlos-Sleeps); alle bare KI-Entry-Points gewrappt → graceful statt 500. **Martin-Teil offen: LLM-Provider auf demo binden** (entblockt zugleich R6.1-Blindtest #492).
- **#498** Basisrezepte-Liste: Feedback-Spalte raus (leer) + Name-Spalte flexibel (kein truncate); VK-Browser identisch nachgezogen; `feedbackAgg`-Query entfernt.
- **#496** MCP `knowledge.LIST` neu (`KnowledgeContextService::listDocuments`, Paging + Frontmatter-Parse thema/sub_thema/relevanz/recherche_datum/tags) — Bestand (~1.010 Docs) jetzt voll abrufbar (SEARCH cappt bei 50).
- **#503** Doppeltes Geschmacks-Chart: beide behalten, klar differenziert (Heading „· sensorisch" vs „· Aroma-Anker" + Skalen-/Quell-Subtitles).
- **#502** Kalkulations-Werkstatt aufgelöst: Regel-Editor zurück in Einstellungen → Herstellkosten; „Was-wäre-wenn" = eigener Preissimulations-Screen; Nav umbenannt; Autocomplete-Dropdown-`overflow`-Bug gefixt.
- **#501** Standalone interne R3.1-Ansicht **entfernt** (Route + Link + `Livewire\Foodbooks\Ansicht` + Blade + `FoodbookService::ansichtDaten`) — Kunden-Wording-Vorschau lebt im Editor-Menü-Toggle, Marge im Editor-Pax-Cockpit.
- **#504** MCP-Audit aller 49 Tools ✅ **ABGESCHLOSSEN 2026-07-13** (eigene Session). Alle 49 gegen 6 Dimensionen (Rename-Drift/MySQL-Kompat/Feature-Drift/Tenancy/LIST-Lücken/Contract-Hygiene) geprüft; ~25 komplett sauber. **Gefixt (je MySQL-Smoke, Write-Tools zusätzlich Cross-Team-Negativtest):** 4 HIGH Cross-Team-Write-IDOR (`concept_slots.POST` package_id, `foodbook_blocks.POST` concept_id, `canvas.PUT` owner isOwnedBy, `recipe_klasse.POST` acceptKlasse isOwnedBy) + 4 MED Tenancy (`canvas.GET` owner-Visibility, `speiseplan_eintraege.POST` 3 Ref-Guards, `signale.PUT` Ownership statt Ancestry, `foodbook_kapitel.POST` parent_id-Bindung); 1 Correctness (`foodbook_blocks.POST` Staffel-Desc `min_personen/preis`→`min_persons/price`); **2 MySQL-Crashes** in Browser-Services (`FoodbookService::paginateBrowser` `kunde`→`customer`, `SpeiseplanService::detail` `farbe/ist_vegetarisch`→`color/is_vegetarian`); 3 stale Descriptions (`anlass`→`occasion`). **7 neue LIST-Tools** (gps/artikel/recipes/verkaufsrezepte/concepts/angebote/signale — page/per_page-Paging, `read_only=true`, schließt die #496-LIST-Lücke katalogweit). **Entscheidungen Dominique:** deutsche Payload-Keys bleiben (Modul-Konvention); `foodbook_blocks.POST` dish-via-`text` entfernt (Doku-Regel „Foodbook komponiert Concepts", #11); `knowledge.PUT active` bleibt (dokumentiert gewollt, #9). Pest **668/669** grün, null Regressionen. **Live-Connector zeigt die 7 LIST-Tools erst nach demo-Deploy (Martin).**

## ⭐ Update 2026-07-15 (Session: Cooking-Jarvis-App ↔ FA Rezept-KI-Abgleich)

Abgleich der lokalen Cooking-Jarvis-App (Tauri = Referenz-Implementierung) gegen den FA-Stand für die drei KI-Rezept-Flächen — **Generator**, **„Alles anreichern"**, **„KI-Überarbeiten/Revise"**. Zwei verifizierte Lücken + eine Keystone-Erkenntnis. Details: Dev **#508** (neu) + **#505** (Kommentar) + **#507**; Memory `project_fa_507_semantic_search.md`.

- **Engine-Aufbau (App = Referenz):** Grounded Generation, reuse-first — Prompt = Vault-Wissen + Pairing (Anker-Graph) + Küchen-Profil + RICHTUNG-Hooks + Task + **VERFÜGBARE BAUSTEINE (reale GPs/Rezepte)** + Beschreibung → LLM → Proposal → pro Zutat matchen (gp/sub/none) → none = Hard-Stop („GP/Basisrezept-Stub anlegen") → Accept. FA hat das Gerüst (`RecipeGeneratorService` + `GenerationContextService` #505 + `IngredientMatchService` + `ConceptGeneratorService`).
- **Lücke 1 (#508, neu):** „KI-Überarbeiten" (`recipe.ueberarbeiten`) groundet neue/geänderte Zutaten NICHT — `syncIngredients` ist reiner Persister, KI-neue Zutaten landen als `match_method='unmatched'`, kein Matcher-Aufruf, kein Hard-Stop. Die App re-matcht die ganze revidierte Liste (`ai_revise_recipe`). Folge: EK-/Allergen-Aggregation bricht bis zum Hand-Mapping.
- **Lücke 2 (#505, offenes DoD „semantisch statt lexikalisch"):** Generator-Grounding ist rein lexikalisch (`candidatesFor` Token-F1 + name-basierter Anker-Lookup). Der hybride V-04-Embedding-Pass der App (`build_inventory_bausteine`, SEM_FLOOR 0.55; GL-04 §6.1) ist NICHT portiert → token-blinde Reuse-Liste → GP-/Rezept-Dubletten.
- **Keystone #507:** Beide Lücken + die `gps.MATCH`-Fuzziness teilen dieselbe Wurzel — der fehlende semantische Layer. Infra existiert (`KnowledgeEmbeddingService` + Cores `EmbeddingProviderRegistry`, config `semantic_search`), heute nur an die Wissens-Suche gebunden, nicht an GP-/Rezept-Retrieval; Provider auf demo aus. → #507 = fehlende Hälfte von #505 + Qualitätshebel für den ganzen Generator.
- **Kein Gap:** „Alles anreichern" (`BulkEnrichService`) ist sauber portiert.

## ⭐ Update 2026-07-18 (Session: 07·M1–M4 — LA-First-GP-Mint überall verdrahtet, Spec KOMPLETT)

Keystone aus [`docs/PLANUNG/07_LA_First_GP_Mint_ueberall.md`](PLANUNG/07_LA_First_GP_Mint_ueberall.md) gebaut. Der LA-First-Mint (`versucheLaZuGp`, #505 Slice 2) war `private` im Generator eingesperrt → jeder andere Pfad lief in Sackgassen (Ruby-Schokolade-Fall #76).

**Spec 07 KOMPLETT (M1–M4) in einer Session gebaut, getestet, gepusht.** Der Mint ist von einer `private` Generator-Methode zur überall-verdrahteten Fähigkeit geworden — Generator, Editor/Revise UND MCP-Assistent minten LA-First; der Ruby-Fall dead-endet nirgends mehr.

- **M1 (✅ `df4d875`):** extrahiert nach `LaFirstGpService::mintFromLa` (geteilte Fähigkeit); Generator injiziert + delegiert. Behaviour-erhaltend.
- **M2 (✅ `b0c1b59`):** in `RecipeService::syncIngredients` verdrahtet — der E3-Re-Grounding-Block (#508) mintet jetzt LA-First bei Bestand-Miss + passender LA (schließt die Revise-Lücke: matchte nur, mintete nicht). Keine LA → bleibt unmatched (Hard-Stop / Sourcing-Wunsch).
- **M3 (✅):** MCP — neues Tool `foodalchemist.gps.MINT_FROM_LA` + `gps.MATCH` `mint_if_missing`-Flag (bei target=none minten), im Provider registriert, MCP-Lockstep (Metadata ehrlich schreibfähig). Der Office-Assistent löst den Ruby-Fall selbst.
- **M4 (✅):** Proposal-Reframe — `gp_new_proposals` = **Beschaffungs-Wunsch (Sourcing-Backlog)** statt „GP wartet auf Freigabe"; `gp_proposals.POST` steuert den Flow (MATCH → MINT_FROM_LA → erst bei fehlender LA Wunsch erfassen), Antwort-Key `sourcing_request`. Kein Schema-Change.
- **Doktrin gewahrt:** kein GP ohne LA · Mint = `tentative` + ReviewQueue · Freigabe menschlich · unbelegter Wunsch wird NIE zum GP. Voll-Suite grün, 0 Regressionen.

**+ #513 Tier 1 (Grammaturen-Rechner):** blocker-freies Phase-1-Stück nachgezogen — `ProportionService` (Bäckerprozent + Rückweg · Extraprozent · Brining · Gelatine-Bloom, Quelle Modernist Cuisine) + Bäckerprozent-Sicht je Rezept (Masse via `bruttoMasseG`, eine Regel-Stelle) + MCP-Tool `foodalchemist.proportion.CALC` (read-only). Grammatur bleibt Master, Prozent = abgeleitete Sicht; Bloom-Sorten als dokumentierte Referenz, nicht erfunden. Pest `ProportionServiceTest`.
**+ #513 %→Gramm-Rückschreiben (2. Slice):** `rescaleRecipe`/`rescaleToReferenceMass` (Modus A Batch, einheiten-neutral) + `setIngredientBakerPercent` (Modus B Einzel-Zutat, Einheiten-Guard: nur g/kg) + MCP-Write-Tool `foodalchemist.proportion.APPLY`. Schreibt nur Mengen (nie %) → Recompute; Owner-Guard. Rechner-Kern damit bidirektional komplett (Gramm↔%).
**+ #513 Editor-UI (3. Slice):** Bäckerprozent-Spalte im Zutaten-Editor (neben Garverlust), Alpine-live berechnet (Referenz = schwerste Zutat = 100 %), **editierbar** = %→Gramm-Rückschreiben im Client (persistiert über Save→syncIngredients, nie %), Einheiten-Guard (Stück/Liter read-only). Pest-Markup-Test. **Damit Punkt 1 komplett (Rechner+MCP+UI); Browser-Klickstrecke = menschliche Gegenprobe offen.**
**+ #513 Punkt 2 Kerntemp-Referenz (4. Slice):** `CulinaryReferenceService` (32 Teilstücke) + MCP `reference.GET`. **Weich modelliert (Entscheid Dominique 2026-07-19):** Qualitäts-Zielwert primär (Rind rosa 52, Geflügel 68), Sicherheit = Zeit-Temperatur-Kontext, `is_hard_safety` nur bei durchmischter Masse (Hack/Brät). Quelle+Evidenz je Zeile, HACCP-Vorrang fest drin. Pest. Vorwärtskompatibel (`kind`).
**+ #513 Punkt 3+7 Hydrokolloid-Dosier + HLB (5. Slice):** `HYDROCOLLOID_DOSAGES` (14 Agenten, Dosier-% vom Ansatz + Cofaktor + thermoreversibel) + `HLB_VALUES` (8 Emulgatoren, o_w/w_o) im selben Service, MCP `reference.GET kind=hydrocolloid|hlb`. Dosierung = Extraprozent (Punkt-1-Verzahnung). Quelle+Evidenz je Zeile, „Herstellerangabe hat Vorrang". Pest. **→ #513 Referenztabellen C abgeschlossen (cP bewusst weggelassen); die ganze Spec 04 (Tier 1 + Referenz-C) ist durch. Tier-3 bleibt bewusst zu.**

## ⭐ Update 2026-07-20 (Session: KI-Rezept-502 auf demo — Root-Cause + Fix, FA-Code-Hälfte)

Follow-up zum Ops-Befund vom #512-Update (unten): demo „KI-Rezept erstellen" → **502 Bad Gateway**. Per SSH/tinker gegen demo durchdiagnostiziert (`foodalchemist_ai_call_log` + Live-Log). **502 ≠ fehlender Key** — App-Fehlerpfade degradieren sauber (200+Meldung); 502 = PHP-FPM-Worker läuft ins Web-Timeout.

- **Ursache 1 (env, schon behoben):** Modell `gpt-5.2-thinking` existiert nicht → 400 → Retry-Treppe → ~124 s → 502. Um 10:03 auf `gpt-5.5-2026-04-23` korrigiert (Core-Default; FA-Tiers/`services.openai.model` alle null).
- **Ursache 2 (FA-Code, GEFIXT):** `gpt-5.5` ist ein **Reasoning-Modell**; Core `OpenAiService` cappt `max_output_tokens ?? 1000`, FA übergab nie `max_tokens` → Rezept-JSON abgeschnitten → 3× Re-Roll (~56 s) → 502. → `propose()` gibt jetzt `max_tokens` mit (`$prompt['max_tokens'] ?? config('foodalchemist.ai.max_tokens_default', 4096)`); 5 Voll-Generatoren (recipe.generator/vk.generator/recipe.ueberarbeiten/recipe.preparation/concept.brief_geruest) auf 8000.
- **Ursache 3 (FA-Code, GEFIXT — beim Fixen entdeckt):** Modell liefert JSON **flach** statt im Umschlag `{werte,confidence,reasoning}` → `werte` leer (betraf ALLE Generatoren; unentdeckt, weil Sandbox=FakeProvider den Umschlag echot). → Umschlag-System-Prompt in `propose()` erzwingt die Form + Parser-Safety-Net (fehlt `werte` → flach übernehmen).
- **Verifiziert gegen Live-Modell** (beide Mechanismen einzeln + kombiniert): Rote-Bete-Carpaccio → §1-Name, 12 Zutaten, confidence 0.93, 1413 output-tok (kein Cap), 25,5 s, kein 502. Lint grün; bestehende Pest unberührt (liefern `werte` explizit). Kosten: höhere Caps = 0 extra (Abrechnung nach Ist-Tokens). 2 Dateien: `AiGatewayService.php` + `config/foodalchemist.php`.
- **URSACHE 4 (Infra + FA-Code, async GEBAUT):** max_tokens-Fix deployt, blieb aber inaktiv bis `sudo service php8.4-fpm reload` (stale OPcache — `update.sh` macht keinen FPM-Reload, Forge `opcache.validate_timestamps=0`). NACH dem Reload immer noch 502 — Browser-Klickstrecke bestätigt: der **synchrone** Generierungs-Request (LLM ~25 s + GP-Matching/Aggregation/Recompute) reißt den nginx-fastcgi-Timeout (60 s), Worker gekillt (kein ai_call_log). → **Async gebaut** (rein FA-Code): `GenerateRecipeJob` (database-Queue, demo-Worker-Timeout 600 s) + `GeneratorModal` dispatcht + pollt Ergebnis aus dem DB-Cache (`wire:poll`), Auth-Restore im Job für den AiGatewayService-Team-Kontext. Verifiziert: demo QUEUE=database (Worker läuft) + CACHE=database (cross-process). Scope: Rezept-Generator zuerst; VK + Concept spiegeln danach (gleiches Muster).\n- **URSACHE 5 (FA-Code, GEFIXT — nach Async-Deploy per Browser freigelegt):** async lief sauber (kein 502, Spinner→Ergebnis), aber der Insert crashte mit `SQLSTATE[22001] Data too long for 'taste_direction'`. Grund: `taste_direction` ist per Design ein 16er-Enum (`suess|herzhaft|neutral`, = GESCHMACK-Spalte, Klassifikator `recipe.geschmack`), aber die Generator-Prompts (`recipe.generator`/`vk.generator`) listeten `taste_direction` OHNE Enum-Constraint → Modell schrieb ein Freitext-Aroma-Profil rein. Fix: beide Prompts auf `suess|herzhaft|neutral` eingeschränkt (Aroma-Profil lebt in `description`) **+ Code-Guard** in `RecipeGeneratorService` (Whitelist, sonst null → kein Insert-Crash bei LLM-Drift). Kein Schema-Change. `generiere()` ist transaktional → der Fehlversuch rollte sauber zurück (kein Waisen-Rezept).\n- **OFFEN:** Deploy demo (Forge/Martin — `update.sh` + composer update + **PFLICHT php8.4-fpm reload**), danach Browser-Klickstrecke. Detail: Memory `project_fa_ki_502_maxtokens.md`.

## ⭐ Update 2026-07-20 (Session: #512 KI-Erstell-Flächen)

- **VK-„Gericht"-Generator auf Parität zum Basisrezept-Generator** (#512, Dominique-Fund „hier sind sogar noch Freitexte"): `VkGeneratorModal` + Blade auf strukturierte Eingaben umgestellt — Niveau/Convenience/Frische als Pills, Bio dreiwertig (Konventionell/Bio/Egal) statt bool, Sektor als Dropdown, Diät als **Multi-Select-Pills** (hart erzwungen) statt Einzel-String. Freitext blieb nur bei Aroma (legitim frei); VK-eigene Achsen (Anlass/Serviceform/Kompositions-Stil) bleiben Selects. Service unberührt — Werte fließen als Prompt-Kontext, `bio_praeferenz`→`bio` gemappt wie im Basis-Generator. 4 Pest (Render ohne Freitext-Platzhalter, togglePill, Multi-Diät, Bio-Dreiwert) + Convenience-Regression grün.
- **Ops-Befund (nicht Modul-Code):** LLM-Chat auf demo schlägt fehl — Modell `gpt-5.2-thinking` existiert nicht (`model_not_found`), Key selbst ok. Liegt in der demo-Env (`FOODALCHEMIST_AI_TIER_*`)/Core, nicht im Modul. Fix = echtes Chat-Modell setzen + `config:clear` (Forge/Martin). Embeddings/RAG davon getrennt (bereits fein).

## ⭐ Update 2026-07-19 (Session: 05·P5 Prozessanker-Parser + 06 Convenience-Highlights KOMPLETT)

Zwei blocker-freie Phase-1-Stücke aus `docs/PLANUNG/` autonom durchgebaut, getestet, gepusht.

- **05·P5 Prozessanker-Parser** (Etappe-1-Rest der DQ-Kaskade): deterministisch (0 LLM). `ProcessAnchorService` erdet die vier Prozess-/Kocharomen-Anker (roest/karamell/rauch/ferment) aus `preparation` — nur bei echten Markern (Rösten/Anbraten/Schmoren/Grillen/Karamellisieren/Räuchern/Fermentieren), kein Zwangs-Anker, „grill=roest+rauch"/„schmor=roest" gespiegelt aus dem Legacy-Gemini-Prompt (Skript 216). `source='parser'`, idempotent, fremde manual/ki/auto-Anker unangetastet. Command `foodalchemist:process-anchor-ground {--team --recipe --missing-only --limit --apply --verify}` + MCP `process_anchors.GROUND` (Lockstep). 10 Pest. MySQL-Smoke (Fixture 95 Rez.): +19 Anker (25/95, kein Über-Tagging), 13 fremde unberührt, Re-Run 0.
- **06 Convenience-Highlights** (opt-in KI-Baustein, H1–H4 KOMPLETT): kuratierte Haus-Convenience-Liste am GP (`is_favorite`+`favorite_rank`, orthogonal zu `tag_is_convenience`). Auto-Score (Nutzung×Lead-LA-Vollständigkeit×Lieferanten-Priorität) → `FavoriteGpService` (pin/exclude/reorder, Soft-Regel: nur Convenience-getaggte pinbar). Kuratierungs-Screen (`/convenience-highlights`, Sidebar Stammdaten) + Command `foodalchemist:convenience-highlights {--suggest --pin --exclude --rank}` + 2 MCP-Tools (`favorites.GET/PUT`). Opt-in-Generierungs-Modus `use_favorites_list` (Default AUS → byte-identisch, Leit-Invariante) an Rezept-/VK-/Konzept-Generator (separater Prompt-Block „bevorzugte Convenience-Bausteine", bevorzugt-nicht-hart) + GP-Picker-Filter „⭐ Convenience". 14 Pest.
- **Voll-Suite 779/780 grün** (1 begründet skipped), 0 Regressionen. Doktrin gewahrt: kein GP ohne LA (Highlight = kuratiertes Flag am bestehenden GP), draft/opt-in, MCP-Lockstep für jede neue Fähigkeit.

## ⭐ Update 2026-07-21 (Session: Foodbook-Editor Master-Detail — Kopf ⇄ Kapitel getrennt)

UX-Fund (Dominique): Der Foodbook-Kopf (Stammdaten/Phase/CRM/Briefing/Leitidee-Canvas/Planungs-Gerüst) klebte über *jeder* Kapitel-Ansicht — er gehört aber übergeordnet zum Foodbook, in den einzelnen Strukturen sollen nur die Speisen stehen.
- **Master-Detail-Split im Foodbook-Editor** (`Foodbooks\Index` + `index.blade.php`): Ansicht verzweigt jetzt an `selectedKapitelId`. **Kopf-Ansicht** (kein Kapitel gewählt) = Stammdaten · Phase-Stepper · CRM · Briefing · Buttons · Leitidee-Canvas · Planungs-Gerüst · Coverage/Generator · Menü-Vorschau (jetzt einklappbar). **Kapitel-Ansicht** = nur Kapitel-Kopf (Titel/Konsumententitel/Preis-Modus) + die Speisen/Concept-Blöcke.
- `waehle()` selektiert kein Kapitel mehr automatisch → Foodbook-Klick landet auf dem Kopf; neuer `kopfAnzeigen()` + Sidebar-Eintrag „📋 Foodbook-Kopf · Übersicht" für den Rücksprung. Der Bearbeiten⇄Menü-Toggle entfällt (Menü-Vorschau = ganzes Foodbook, gehört zum Kopf; Bearbeiten = kapitel-scoped).
- Rein UI/Livewire, kein DB-/Schema-/Service-Eingriff. `php -l` clean, Blade rendert (kein 500), beide Ansichten in der Sandbox visuell verifiziert.

## ⭐ Update 2026-07-21 (Session: Foodbook — Header-Rendering + Block-Drag&Drop + Dokument-Feinschliff)

Drei Demo-Befunde (Dominique) am Foodbook, alle gefixt + in der Sandbox verifiziert. Commit `a7f0ee1`.
- **„Header kommt nicht raus / nicht fett":** Konzept-Titel (`concept_ref`/`recipe_ref`) rendern jetzt **fett + mit Abstand** als eigene Zwischenüberschrift statt plain zwischen den Gericht-Zeilen — konsistent in **allen drei** Ansichten (`dokumente/foodbook.blade`, `praesentation.blade`, Editor-Menüvorschau `index.blade`). Vorher nur `header_*`-Typen fett, `concept_ref` blieb `ist_header=false`.
- **Drag & Drop im Block-Editor** (fehlte komplett — nur ▲▼): Ziehgriff ⠿ + neue Livewire-Methode `Foodbooks\Index::blockVerschiebenAuf` (insert-after, spiegelt Concepter `positionNach`); native HTML5-DnD-Verdrahtung 1:1 vom Concepter-Slot-Muster. ▲▼ bleibt als Kanten-Alternative. End-to-end verifiziert (dispatchte drag/drop-Events → DB-Reihenfolge korrekt) + `Livewire::test`.
- **Kundendokument-Feinschliff (Design):** `WordingResolver::fuerGericht` kappt führende interne Marker `[HG]`/`[KAE]`/… **nur im Namens-Fallback** (`source` bleibt `name` → „Wording fehlt"-Amber im Editor erhalten). Plus Kapitel-/Titel-Abstände, Gericht-Bullet, ruhigere Typo. 1-spaltig/druckstabil (bewusst kein Bild-Redesign — #461 später).
- Pest grün: FoodbookService (16), FoodbookUi (3), ConcepterWording (Teil der 16). **Offen (Daten, kein Code):** viele VK-Gerichte ohne `sales_wording_standard` → Kundensicht zeigt interne Pipe-Namen (Editor flaggt amber); echtes Wording pflegen bleibt To-do.

## ⭐ Update 2026-07-21 (Session: Foodbook-Planungs-Cockpit — Plan + Phase 1)

Großes Redesign der Foodbook-Hauptseite zum **Planungs-Cockpit** (mit Dominique durchgeplant). Ansatz: **Vorhandenes aufwerten, nicht neu bauen** (Canvas/Gerüst/Coverage/Generator existieren, lagen nur in Modals + waren als Monolith verdrahtet). Freigegebener Plan: 5 Phasen.

**Gelockte Entscheidungen:** 4 Tabs (Planung · Briefing · Kreativ · Trend) + ständige Kalkulations-Leiste; Tabs = auto-vorbefüllte Input-Flächen, füttern die LLM, User stimmt ab. Gerüst = Struktur (Slots = Kapitel, „Struktur anwenden"; Monolith-„Konzept aus Gerüst" fällt weg). Speisen-Flow: Vorschlag (Bestand+Wissen, in-voice) → abstimmen → übernehmen. **3 DNA-Ebenen** Team → Kunde (CRM, neu) → Foodbook; Tonalität (`WritingStyle.sprach_duktus`) folgt der Kette, angewandt beim Übernehmen. Auto-Kontext-Kaskade (CRM+Settings+DNA+Bestand → Segment). Skizze-PDF später.

**Reuse-Fundstücke (Exploration):** `CanvasService::cascadeKontext()` = Einhängepunkt der DNA-Kette; `owner_type` freier varchar → Kunde-DNA ohne Migration. `PlanningFrameSlot.chapter_id` existiert → Slot↔Kapitel-Kopplung im Schema angelegt; `CoverageService` matched chapter_id-first. Per-Slot-Selektion wiederverwendbar aus `ConceptGeneratorService::{kandidatenPool,filterFuerSlot,besterKandidat,slotSemantik}` + `PairingService`. Offene Weiche vor Phase 3: Slot nimmt Konzepte+Gerichte (A) vs. Slot = Konzept (B, empfohlen).

**Phase 1 GEBAUT + GEPUSHT (`6ea6b42`):** `resources/views/livewire/foodbooks/index.blade.php` — Kopf-Ansicht als 4-Tab-Layout, Leitidee-Canvas + Planungs-Gerüst **inline** (Modals `fb-leitidee`/`fb-geruest` entfernt), Coverage + R6.1-Generator im Planung-Tab, Kreativ/Trend als Platzhalter. Tab-Zustand via Alpine, überlebt Livewire-Morphs (verifiziert). Reiner Reuse, kein Modell-/Service-Eingriff. Sandbox-Smoke grün, keine Konsolenfehler. Plan-Datei: `~/.claude/plans/mach-einen-plan-breezy-star.md`.

**Phase 1 Politur GEPUSHT (`354d010`):** Tab-Reihenfolge Briefing→Planung; Canvas- + Briefing-Textareas wachsen mit dem Inhalt (CRM-autoGrow-Muster, `x-effect` auf $wire-Property, `min-h`-Boden) statt intern zu scrollen.

**Phase 6 — Branding/CI-Tab GEBAUT + GEPUSHT (`ec9b652`):** 5. Cockpit-Tab, verdrahtet ausschließlich die von Dominique parallel gebaute FoodbookService-Branding-API (Backend `87b0217`: `setBranding`/`storeLogo`/`storeCover`/`clear*` + `foodalchemist_foodbooks`-Spalten brand_color/band_color/logo_path/cover_image_path/footer_text). Marken-Farbe (Picker+Hex), Bandfarbe optional, Logo/Cover-Upload (`WithFileUploads`, Auto-Upload via updated-Hook), Footer, Live-Vorschau (Alpine-@entangle). RuntimeException (Hex/Owner-D1) als UI-Fehler. Gegenprobe: Dokument-PDF zeigt Marke. Pest `FoodbookBrandingTab` 4/4 (+ Dominiques Service-Test 4/4). Nur `Index.php`+`index.blade.php` angefasst.

**Phase 2 Kern GEBAUT + GEPUSHT (`9a0543f`):** 3-Ebenen-DNA Team → **Kunde** → Foodbook. Neues `kunde_dna`-Canvas-Template (Marke/Ziel-Gäste/Kommunikation-Ton/Schreibstil/No-Gos/Preis-Erwartung), `cascadeKontext` um Kunde-Ebene erweitert (`$crmCompanyId`, owner_type=crm_company), `AiGatewayService` reicht `food_dna_crm_company_id` durch, `kiKundentext` gibt die crm_company_id mit. Kunde-DNA-Board = neues Nested-Livewire `KundeDnaPanel` im Kreativ-Tab (an CRM-Kunde gebunden). Kein DB-Schema (owner_type freier varchar). Verifiziert: Board rendert+speichert, cascade zieht die Kunde-Ebene nachweislich in den KI-Kontext. Pest `FoodbookDnaCascade` 2/2.

**Offen:** Phase 2-Rest = **Team-DNA → Einstellungen verschieben** (Umzug des food-dna-Boards in einen Settings-Abschnitt + food-dna-Route/Sidebar auflösen; berührt `routes`/`config` — Martin/Parallel-WIP-Zone, wartet auf freie Bahn). Phase 3 (Struktur anwenden + per-Slot-Vorschläge, A/B-Entscheid), Phase 4 (Tonalität-Pass + Trend-Tab), Phase 5 (Kickoff-Flow). demo-Deploy = Martin.

## 🚉 Datenmodell-Fahrplan (Chemie/Pairing Phase 1–4 ⊕ 5 Produkt-Ebenen)

Quellen: `Datenmodell Food.Alchemist.md` (5 Ebenen) + `07.02_Flavor_Pairing/Datenbank Foodalchemist/_Plan_Datenmodell_Chemie-Pairing-DB.md` (Chemie-first Phase 1–4). Stationen von hier bis Voll-Ausbau:

**Station 0 — ERREICHT ✅**
- Ebene 1 Rohstoff (Anker/Moleküle/Chemie) · Ebene 2 Zustände (Prep-Delta, state-pairing 748/1000)
- Chemie-DB Phase 1: molecules 74k, `ingredient_aroma_vector`, 14/70-Ontologie, Klassifikator v2, computed pairings (Kalibrierung ρ 0,54 — ρ-Deckel strukturell)
- Ebene 3 Rezept: Signatur-Netz + Zustands-Charakter (Kern) · Know-how in FA-SQL (`knowledge_documents`)

**Station 1 — FUNKTION** → siehe `_NEXT_SESSION_TODO.md`
- MySQL: **migriert + Volldaten importiert** ✅ (Seeder-first verworfen — Dominique: „laden, dass es steht"; 121 Tabellen echt in MySQL, Canon).
- **#469 Wissens-Pflege-Modul: FERTIG ✅ + gepusht** (Browser + Kategorien/Einsatzorte + Bindungen grob/fein + Gateway-Injektion). Doku: `platforms-foodalchemist/docs/wissen.md`, Spec `_Wissensmodul_Spec.md`.
- #468 UI-Rendering (aroma/geschmack im Rezept-/GP-Panel): OFFEN.

> **Nachtrag 2026-07-11 Abend:** Der Seeder-first-Ansatz oben wurde in der Praxis übersprungen — Dominique wollte die lokale MySQL „so vorbereiten, dass sie steht", darum Volldaten via `import-master` importiert. #469 komplett gebaut. Nächster Hebel: Wissens-Modul auf demo sichtbar machen (Server-Schritte/Martin) + VK-Preise R1.2.

**Station 2 — Pairing-Projektion (Coverage-Loch schließen)** ✅ **DONE (2026-07-12)**
- computed-Kanten → FA `pairing_anchor_edges` als Lückenfüllung. Real **~145k** Kanten projiziert (nicht ~12k — die Label→Anker-Multiplizität + permissive harmonie-Kanten trieben die Menge), `source_slug='computed'` (keine `source`-Spalte), **gradiertes Gewicht** `weight = 0.6 × Molekül-confidence` (nullable Spalte) statt binärer Schwelle; `edgeBest()`/`componentSuggestions()`: `weight ?? GEWICHTE[type]`, **holes-only → kuratiert nie berührt** (Inv. 3+5). Gemessen am **Master (foodalchemist_full, 2.559 Rezepte): Coverage 36,6 %→58,1 %, Ø-Score 92→67 (ehrlich, kein Rauschen), 159 Rezepte aus 0 %-Coverage gerettet.** Command `foodalchemist:pairing-project-computed` (--apply/--purge, idempotent). Graph zudem **global** (`team_id=NULL`) + `import-master` bewahrt global.
  - **Station 3 (Anker-Reichweite):** molekular **ausgereizt** — von 187 unmapped Ankern nur 67 recipe-relevant, davon FooDB nur ~9 (grob); 8 saubere Mappings gesetzt → +2.642 Kanten. Exoten (yuzu/tomatillo/perilla/gochujang…) sind NICHT in FooDB, aber **kuratiert dicht** via `book_pairings` (9.034 geladene Buch-Kanten) — kein Mapping-Loch.
  - **Taxonomie (2026-07-12):** Kanten-Typen final **aroma / kontrast / erprobt** (klassisch+modern→`erprobt` verschmolzen, Ära ist kein Fit-Kriterium). Migration `000040` beide DBs + recipe_pairings; Code/Blades/Tests nachgezogen (627/628 grün).
  - **Graph-first Plattform-KI (2026-07-13):** `KnowledgeContextService::pairingBlock` zog die Pairing-Partner bisher aus dem **Markdown-Volltext** (`extractPairingNames`) — die in-App-KI (Rezept-Generator) „las die md" statt im Graphen zu denken. Jetzt aus dem **Anker-Graphen** (`PairingService::neighborsForName`), Typ-gefiltert je Stil (klassisch→erprobt, kreativ→erprobt+aroma, gewagt→aroma+kontrast); md-Prosa nur noch fürs Grounding. MCP-Pfad (Claude-Tools) war schon graph-first. Damit denken **beide** KIs im Gehirn.
  - **⚠️ „0,2 %-Kohärenz-Loch" war eine Metrik-Verwechslung:** Station 2 schloss das **Coverage/Dichte-Loch** (37 %→58 %). Die „0,2 %" aus Q5 ist etwas anderes — **% Rezepte mit *persistiertem* KI-Kohärenz-Score** (Tabelle `recipe_culinary_coherence`, aktuell 0 Zeilen). Das ist der **Q5-Batch-Lauf**, noch offen (KI-Judge, braucht echten Gemini-Provider — Dev = `fake`). Station 2 war die Vorarbeit; der Batch-Lauf ist die Ernte.

**Station 3 — Ebene 3 Rezept-Werkstatt komplett** ◻️
- Muttersaucen `ABGELEITET_VON` (Aroma/Allergen/Finanz-Vererbung) · **Geschmacks-Editoren als Kanten-Modifikatoren** (Säure→Frucht-Ester-OAV↑, Salz→Bitter↓, trigeminal-Multiplikator = Phase-4-Matrix-Effekte) · Hybrid-Fertigprodukte (virtuelles Aroma-Profil) · Rezept-als-eigene-Aroma-Identität (über Signatur-Netz hinaus).

**Station 4 — Ebene 4 Gericht** ◻️
- Konsistenz-Layer (role + texture als Kanten-Properties) · **SKF/Textur-Kontrast-Score** („Birnen-Bohnen-Speck": 5 Geschmäcker + Balance-Regeln + 60 Texturen, Buch S.36).

**Station 5 — Ebene 5 Event + Higher-Order** ◻️
- Menü-Dramaturgie (Intensitätskurve) · Buffet-Harmonie-Matrix · Flying-Sektoren-Verteilung · **Trinitas/Stacks als Hyperkanten** (CulinaryDB-Co-Occurrence + Buch-Verbund-Pairings).

**Station 6 — Volle Buch-Treue (Genauigkeits-Hebel, teils extern blockiert)** ◻️
- **OT(m)-Geruchsschwellen → echtes OAV** (blockiert auf externe OT-Tabelle) · **Food-Bridging** (Semi-Metric kürzester Pfad = Kontrast-Generator, NICHT Kosinus) · **Buch-Räder → scharfe `method='book'`-Vektoren** (hebt ρ-Deckel 0,54→0,60+) · Süße/Salz-Achsen sauber (USDA FoodData Central).

> Reihenfolge-Logik: Station 1 (Funktion) ist unabhängig; Station 2 ist der billigste Wert (Coverage); Station 3–5 sind der Produkt-Tiefgang (R6); Station 6 ist der Genauigkeits-Hebel (teils auf Datenbeschaffung wartend). „Keine Erfindungen" gilt durchgehend.

## Lesehilfe

| Feld | Bedeutung |
|---|---|
| **Größe** | S = Stunden · M = 1–2 Tage · L = 3–5 Tage · XL = >1 Woche |
| **Hängt an** | Harte Abhängigkeit — vorher nicht starten |
| **DoD** | Checkliste; alle Punkte erfüllt = Paket fertig |

### Globale DoD (gilt für JEDES Feature-Paket, zusätzlich zur Paket-DoD)

- [ ] Team-Scoping (`team_id`) + D1-Vererbung wo relevant
- [ ] Tool-fähig: Aktion ist als MCP-Tool aufrufbar oder bewusst als UI-only begründet (Dev-Modul-Discussion)
- [ ] KI-Schreibpfade: immer `status=draft` + `created_via`-Lineage, Freigabe nur menschlich
- [ ] `php -l` + Blade-Kompilierung grün, Pest-Tests für neue Services
- [ ] Lokal-verifiziert (UI-Klick → DB bewiesen). ⚠️ Migration 2026-07-11: Daten-Wahrheit wandert Sandbox-SQLite → **lokales MySQL (Kanon)**; bis abgeschlossen SQLite-Fallen UND MySQL-Zielverhalten mitdenken (siehe README-Architektur-Update + `_MEMORY_FoodBrain.md`)
- [ ] Committed + gepusht auf Modul-main, Dev-Modul-Issue aktualisiert
- [ ] Keine Core-/UI-/Fremdmodul-Änderung ohne Abstimmung (Goldene Regeln)

### Abhängigkeits-Kette (kritischer Pfad)

```
R0 Fundament ──► R1 Masse (994 VK) ──► R2 Wirtschaftlichkeit ──► R6 Alleinstellung
                     │                        ▲
                     ├──► R3 Digitales Foodbook│
                     ├──► R5 Compliance        │
                     └──► R4 Geführte Planung ─┘  (R4 liefert das Soll-Gerüst = Prompt-Material für R6 Brief→Konzept)
```

**Warum diese Reihenfolge:** Ohne Masse (R1) rechnen alle Features auf 5 Testgerichten — Preis-Alarm, Foodbook-Filter,
Coverage-Checks sind erst mit ~1.000 VK-Gerichten beweisbar. R4 vor R6, weil das Planungs-Gerüst die Messlatte ist,
gegen die die KI in R6 baut. R3 und R5 sind nach R1 parallelisierbar (unabhängige Datenpfade).

**Erweiterungen (Brainstorm 2026-07-04):** R2.4–R2.7 (Assemblierung, Auto-Pricing, Gericht-Bewertung, Benchmark) hängen an R1 und schärfen die Wirtschaftlichkeits-Maschine — R2.6 entkoppelt R2.3 sogar von der offenen Verkaufsdaten-Quelle. R6.8–R6.10 sind die **Pairing-Offense** (Graph als Waffe statt Wächter) auf dem R6-Track. R6.10 + der **Ausblick-Track N0–N2** (Nachbar-Modul Einkauf/Lager/Produktion/Event) hängen am Core-Contract (Q1/N0) — der ist damit vom „nice to have" zum Gründungsakt geworden. Die **FA-seitigen Planungs-Blätter (R7)** hängen nur an R1 und sind die Vorstufe, die N0 de-riskt: erst liefert FA die Blätter als Tools, dann kapselt der Contract sie — Berechnetes bleibt FA, operativer Zustand wird Nachbar-Modul. Der **Warum-Layer (R6-Querschnitts-DoD + R6.11)** hängt an **Q4** (Evidenz-Abdeckung) — ohne Evidenz-Fundament baut er auf Sand; der **A-Track** (Academy-Training) konsumiert ihn wie der N-Track den Contract. Die **Pairing-Offense (R6.8–R6.10) + Kohäsion** hängen an **Q5** (Konnektivität) — Baseline-Messung 2026-07-04 zeigt: Graph/GP-Erdung stark (98 %), aber Kohärenz nur 0,2 % berechnet und Rezept-Reichweite 60 % → Q5 ist die eigentliche Vorarbeit für R6.

---

## R0 — Fundament sichern *(sofort; alles hier blockiert Sichtbarkeit oder Datenvertrauen)*

### R0.1 Deploy auf demo.bhgdigital.de — Owner: Martin + Dominique · Größe S · ✅ **ABGESCHLOSSEN 2026-07-13 (inkl. DATEN)**

**DoD:**
- [x] Code live: demo läuft auf HEAD (519d7a6 inkl. R4/R6.1 — `concepts.GENERATE`/`coverage.GET` im Tool-Katalog live verifiziert); Schema-Reset + Frisch-Migrate durch Martin
- [x] Alle Modul-Migrationen fehlerfrei durch — `migrate:status` 0 pending, inkl. der 5 Migrationen vom 2026-07-13 (Forge-Deploy migriert jetzt automatisch)
- [x] MCP listet die FA-Tools (40+, Registry live geprüft)
- [x] Smoke: `foodbooks.POST` → `foodbook_kapitel` → `foodbook_blocks.POST` legt Draft-Foodbook mit echtem Gericht an (FB #9 auf demo; `recipes.POST`-Schreibpfad war schon durch R0.2-E2E bewiesen)
- [x] Queue-Worker läuft (2 Worker: database + attachments, per ps verifiziert)
- [x] **BONUS — Daten-Import (Etappe 2, war der eigentliche Rest):** `fa_master_export_2026-07-13.sqlite` (HEAD-Schema, R1.2-Preise) via `import-master --team=6 --fresh` auf demo — dry-run-gecheckt, Row-Count-Gate, Transport-Dateien wieder gelöscht. Live: 7.943 GPs, 3.220 Rezepte, 929 VK-Gerichte MIT Presentations+Preisen, 2.265 Basisrezepte in der UI. SSH-Zugang Dominique eingerichtet (Forge, Key auf BHG.DIGITAL.DEV1 = 49.13.90.76).

### R0.2 MCP-Darreichungs-Nachzug M1–M6 · Größe M · Hängt an: nichts (parallel zu R0.1) · ✅ **ABGESCHLOSSEN 2026-07-12**

Die Tools waren darreichungs-blind — für externe LLM-Clients existierte das neue Verkaufs-Modell nicht. Jetzt behoben.

**DoD:**
- [x] `verkaufsrezepte.SEARCH`/`GET` liefern Formen je Gericht (inkl. EK/VK je Form, Standard-Marker) — `presentations[]` via `FoodAlchemistTool::darreichungenSummary`
- [x] `kalkulation.GET` rechnet über den `DarreichungResolver`, nicht über `recipes.vk_netto` — `KalkulationService::recipeHk` (concept/paket liefen schon so)
- [x] `concepts.GET/POST` + `concept_slots.POST` können Facetten (Servierform/Eventtyp/Momente/Saisons) und Slot-Darreichung lesen/setzen (Slug/Name→id-Resolver)
- [x] `recipes.POST` erzeugt automatisch eine Standard-Darreichung (`created_via=mcp`) — `DarreichungService::ensureStandard`
- [x] GL-07-Klassifikator kennt die Bauart-Regel (E7: „Wie gebaut?", nie „Wo eingesetzt?") + nur aktive Hauptgruppen — Prompt + Aktiv-Filter; nebenbei latenter MySQL-`||`-Bug im Taxonomie-Label gefixt
- [x] E2E: MCP baut Konzept mit Buffet-Form → Resolver zieht Buffet-Preis (2,32 statt 25) — Pest `McpDarreichungenTest` + MySQL-Smoke (Beweis wie Phase 5)

> **✅ Abschluss 2026-07-12 (gepusht, Commit `d5409a6`):** 38 Tools darreichungs-fähig. ⚠️ Zwei-Darreichungen-Fall im automatisierten Test nur auf MySQL abbildbar (In-Memory-SQLite behandelt den partiellen Ein-Standard-Index wie ein volles `unique(recipe_id)` — R0.5-Testbasis); Beweis darum per MySQL-Smoke. Detail: Memory `project_fa_mcp_schreibkaskade`.

### R0.3 Datenqualitäts-Kaskade (Ampel + bottom-up Remediation) · Größe L · Hängt an: nichts · 🟢 **Etappe 1 GEBAUT 2026-07-14 (lokal, verifiziert am Master)**

**Neuzuschnitt 2026-07-14 (Dominique):** Statt Top-down-Flickerei die ganze Kaskade **bottom-up** heilen — Lieferantenartikel → GP → Basisrezept → VK-Gericht — plus Anker-Erdung + volle Anreicherung. Die „unbepreisten Ketten" oben sind Symptome von GP/LA-Lücken unten. Ausführungsplan (2 Etappen, KI-Schritte lokal via OpenAI): siehe Session-Memory `project_datenqualitaet_kaskade_2026-07-14` (folgt) + Plan-Datei. Die 2 WaWi-Ära-Punkte (FA↔WaWi-EK-Divergenz, nutri-Sync 235) sind **obsolet gestrichen** (FA=Master, WaWi eingefroren, kein Sync mehr).

**FA-native Commands (neu, thin wrappers um bestehende Services):**
| Command | wrappt | Zweck |
|---|---|---|
| `foodalchemist:data-quality {--team --json --signals}` | neuer `DataQualityService` | Ampel: per-Ebene-Counts (LA/GP/BR/VK/Quer); `--signals` schreibt Lücken über `SignalService` in die ReviewQueue-Inbox (dedup, MCP-sichtbar via `signale.SEARCH`); schedulebar |
| `foodalchemist:lead-la-repick {--team --used-only --apply}` | `LeadLaService::applyLeadLa` | chirurgischer Lead-Repick nur wo aktueller Lead nicht auflöst + ein bepreister LA existiert |
| `foodalchemist:gp-allergen-backfill {--chunk --apply}` | `GpAggregateService::allergenKonfidenz` | persistiert NUR Allergen-Metadaten (`allergens_source/_confidence/_aggregated_at`), NIE die Wert-Spalten (Override-Schutz); Konflikte → Signal |
| `foodalchemist:recompute {--all\|--recipe= --propagate --apply}` | `RecipeRecomputeService::recomputeAll` | fehlender Bulk-Recompute (war nur Golden-Test); propagiert geheilte GP-Preise nach oben |

**Etappe-1-DoD (deterministisch, kein LLM):**
- [x] **Mess-Ampel** gebaut (`DataQualityService` + Command + 3 Signal-Typen `AnkerFehlt`/`ServierformUnbestimmt`/`EkKetteUnvollstaendig`); 12 Befunde als dedup'te Signale am Master
- [x] **Lead-LA-Repick:** 90 GP-Leads gefixt (auflösend 4.900 → 4.990); 405 echte Lücken sauber als „Park" erkannt (kein bepreister LA → Sourcing = Etappe 2)
- [x] **GP-Allergen-Backfill:** „ohne Konfidenz" **6.947 → 0**; 289 Allergen-Konflikte (LA↔LA) als Signal; Wert-Spalten nachweislich unberührt (Guard-Test)
- [x] **Bulk-Recompute** gelaufen (3.218 Rezepte, 0 Zyklen); EK propagiert
- [x] Backups vor jedem Apply (`PRE_DQ_CASCADE` voll + `PRE_P3` gps); 13 neue Pest-Tests grün
- [x] **P5 Prozessanker-Parser** (`foodalchemist:process-anchor-ground`, Parser-Modus, 2026-07-19): deterministisch (0 LLM) — die vier Prozess-/Kocharomen-Anker (roest/karamell/rauch/ferment) aus `preparation`, hoch-präzise (nur echte Marker, kein Zwangs-Anker, Über-Tagging-Guard). Neuer `ProcessAnchorService` (source=`parser`, idempotent, fremde manual/ki/auto-Anker unangetastet) + MCP `process_anchors.GROUND` (Lockstep) + 10 Pest-Tests. MySQL-Smoke: Fixture 95 Rezepte → +19 Anker (25/95), 13 fremde unberührt, Re-Run 0. KI-Rest mehrdeutiger Prep-Texte bleibt Etappe 2.
- [ ] `unbestimmt`-Servierformen (329) kuratiert → **Etappe 2** (KI je Gericht)
- [ ] Rest-Stubs fb2027 (12) + tentative-in-Rezept (27) + itemisierte 405-Park-Sourcing-Liste → Review/Etappe 2
- [~] Anker-Erdung (84 GP + 91 BR + 151 VK) + volle Anreicherung → **Etappe 2** (lokaler OpenAI-Provider)
- ~~FA↔WaWi-EK-Divergenz~~ · ~~nutri-Sync 235~~ — obsolet (FA=Master)

> **Ehrlicher Befund:** Der große EK-Rest-Stau (219 VK / 788 BR teil-unbepreist) hängt strukturell an den **405 Park-GPs** (kein bepreister LA irgendwo) → LA-Sourcing = Etappe 2, nichts, was Lead-Repick/Recompute deterministisch heben könnte. Etappe 1 hat die deterministischen Free-Wins gehoben. Master-Daten-Heilung → demo per Re-Export + `import-master` (separat).

### R0.4 Skill-Infrastruktur (Phase D abschließen) · Größe S · **Entscheid: Dominique/Martin (S3)**

**DoD:**
- [ ] S3-Credentials-Entscheid gefallen, Obsidian-Vault mit `skills_enabled` auf office.bhgdigital.de existiert
- [ ] `foodalchemist.foodbook_anlegen` hochgeladen, via `skill_registry.SEARCH` auffindbar
- [ ] Ein externer LLM-Client hat den Skill einmal komplett durchlaufen (7 Schritte) → Draft-Foodbook entstanden

### R0.5 Testbasis reparieren · Größe S · ✅ **ABGESCHLOSSEN 2026-07-12** (Suite grün: 621, 620 ✓ / 1 begründet skipped)

**DoD:**
- [x] Pest-Runner-Problem gelöst (`tests/bootstrap.php` strippt das `15_GITHUB`-Segment; Suite läuft) — Standard dokumentiert (`_SANDBOX_NOTES.md`)
- [x] `DarreichungService` + `DarreichungResolver` haben Tests — `DarreichungServiceTest` (ensureStandard-Idempotenz/Ein-Standard, Resolver `standardFuer` + Fallback, Money-Path Preis-Wahrheit) + `McpDarreichungenTest` (M1–M4, Facetten, Fallback). ⚠️ Delta-Mischpreis + Zwei-Darreichungen-Fall (Buffet gewinnt) auf In-Memory-SQLite nicht abbildbar (partieller Ein-Standard-Index) → MySQL-Smoke (R0.2)
- [x] Money-Path-Regression: „Preis kommt aus der Standard-Darreichung, recipes.sales_net spiegelt" automatisiert (SQLite-tragfähig); der spezifische Zwei-Zeilen-Beweis (Buffet 2,32 € statt Standard 25 €) = MySQL-Smoke (SQLite-Grenze dokumentiert)

> **✅ Abschluss 2026-07-12:** Ganze FA-Pest-Suite von **26 rot → 0 rot** (621 Tests, 1 begründet skipped = Panel-KI-Marketing M6-05). Root-Cause fast durchgängig **English-Rename-Drift auf der Test-Seite** (Allergen-Keys, Kosten-Keys, Blade-Attribute, Result-Shape) — Produktivcode kanonisch, Fixes daher Test-seitig; 3 Diagnose-Subagenten + manuelle Cluster-Arbeit. **2 echte Code-Bugs mitbehoben:** `FoodAlchemistRecipeFeedback` fehlte `LogsActivity` (R2.6-Regression), `RecipeGeneratorService` Default-AK-Fallback jetzt Klasse-vor-Hauptgruppe. Detail: Memory `project_fa_r05_testbasis_2026-07-12`.

### R0.6 Komfort-Nachzüge A3 + A5 · Größe S · *optional, lückenfüllend*

**DoD:**
- [ ] A3: Kernrezept-Änderung erzeugt „Varianten prüfen"-Hinweis an allen Nicht-Standard-Darreichungen
- [ ] A5: Behälter/Regeneration/Vehikel je Darreichung im Darreichungen-Tab editierbar (Spalten existieren)
- [ ] **A6 Multi-Geschirr je Gericht (Modell-Erweiterung, Größe M):** heute nur EIN `serving_vehicle_vocab_id` pro Darreichung — reale Gerichte brauchen mehrere Geschirr-Teile (Bowl-Beispiel: 4 Teile, 2 davon für eine Sauce = Saucenbecher + Deckel). → **Geschirr-Bedarfs-Liste je Gericht** (n Positionen, Menge, optional „gehört zu Komponente X"), statt Einzel-Slot. Vokabular (Saucenbecher/Deckel/Salatschale/Schraubglas) als Geschirr anlegen. fb2027-Import: Verpackungs-Zeilen stehen solange auf `match_method='ignored'`. Passt zu R7 „Geschirr: Bedarf hier".

---

## R1 — Masse: Foodbook-2027 Phase 2 *(größter Hebel — alles Weitere braucht diese Daten)*

### R1.1 994 VK-Gerichte FA-nativ erstellen (mit Rezeptur + Mengen) · Größe L · Hängt an: R0.3 · ✅ **ABGESCHLOSSEN 2026-07-05**

**Ziel (Dominique):** Die 994 VK-Gerichte des Foodbook 2027 mit vollständiger **Rezeptur** anlegen — Inhalt = bestehende
Basisrezepte + direkte GPs, mit den korrekten Mengen. Direkt in die FA-Master-DB. **Kein Import, kein Sync** — es gibt nichts
zu promoten (die VK-Gerichte existieren noch nicht) und WaWi ist eingefroren (`chmod 444`, read-only Archiv).

**Quelle:** zwei PDFs im Foodbook-2027-Ordner (gleicher Menü-Export, 1.068 Seiten) — `A7716CF7_menu_…` (1 Portion) +
`große mengen…A7716CF7…` (Ansatz). Aus derselben Quelle kam auch ein Teil der Basisrezepte. Parser-Pipeline ist gehärtet
(Block-Bleed-Fix, 203c-Bio-Abwertung, 260-Mengen-Präfix-Fix) → für die FA-native Erstellung wiederverwenden, Schreibziel =
FA-Englisch-Schema, Recompute via `artisan`. Das ist das „disziplinierte Python-Fenster auf der Master-DB", das der
Migrationsplan erlaubt (wie der 105-Klassifikator) — kein WaWi-Auftauen.

**Mengen-Regel (Dominique):** Gerichte als **1 Portion** (`portionen=1`). **Mengen = Ansatz-PDF ÷ Portionszahl**,
das 1-Portions-PDF nur als Kreuz-Check — Lehre aus 271: die 1-Portions-Werte sind gerundet (Präzisionsverlust bei Gewürzen).

**DoD:**
- [x] Alle 994 VK-Rezepte in FA angelegt: `is_sales_recipe=1`, `status=review`, `created_via` gesetzt, `herkunft`-Slug, `portionen=1`
- [x] **Vollständige Rezeptur je Gericht:** Komponenten gegen **bestehende Basisrezepte gematcht** (`referenced_recipe_id`, kein Dubletten-Neubau), direkte Zutaten GP-gematcht (`gp_id`); ungemappte Zutaten = 0
- [x] Mengen aus dem Ansatz-PDF abgeleitet (skaliert auf 1 Portion), nicht aus den gerundeten 1-Portions-Werten
- [x] 0 zirkuläre Wrapper/Stub-Paare, 0 verwaiste Refs — **74 self-ref/leere Basisrezepte als Wurzel identifiziert + aufgelöst** (Skript 294)
- [x] Jedes VK-Gericht hat genau 1 Standard-Darreichung; Servierform `fingerfood`/`unbestimmt` → Review-Queue (Rest-Kuration R1.2)
- [x] Neue tentative GPs nur durch Review-Gate (kein stilles Anlegen — GPs bleiben kuratiert)
- [x] `artisan recompute` grün: **EK-Abdeckung 977/994 = 98,3 %; alle Einzelgerichte 100 % gekostet** (Rest = 15 Pakete + 2 Lunchpakete → Concepter-Composition); Allergen-/Zusatzstoff-/Nährwert-Aggregation vollständig
- [x] FA-Backup vor Lauf (`PRE_FB2027_VK` + zahlreiche `PRE_*`), Läufe resumefähig (Cache-Tabellen), idempotent

> **✅ Session-Abschluss 2026-07-05:** 994 VK-Gerichte FA-nativ angelegt (Skript 280) → EK von 860 → **977/994 (+117)**. Kette lückenlos: alle Einzelgerichte + alle 50 Paket-Komponenten existieren & gekostet.
> **Meilensteine der Session:** (1) **Wurzel-Reparatur** — 74 kaputte Basisrezepte (Self-Ref/leer, Slug-Bridge-Bug) gefunden & befüllt/gemergt; (2) **Import-Audit** — Necta-1494-Export vollständig & 1:1 (6 Tabellen matchen Manifest; „fehlende" Preise = quellseitige Status-2-Lücken, nicht Import); (3) **belegte → Fresh Company** (Skript 295), **Fertigsalate/Desserts/Snacks → GP**, **UniPek-Banichka-Filo** (8 GPs); (4) **GP-LA-Matching** der neuen GPs (94/118) + 20 Dubletten gemergt.
> **Skripte:** 280 (Anlage), 288 (GP-LA), 293 (Dedup), 294 (Broken-Basisrezepte), 295 (belegt→FC), 296 (Einzelgerichte-Match). **2 wiederkehrende Fallen dokumentiert** (Memory `project_fb2027_vk_anlage.md`): INSERT-Param-Reihenfolge (match_method-Korruption crasht Recompute, `try/catch` schluckt es); `lead_la` ohne `supplier_item_structures`-Zeile → Preis löst nicht auf (EK=0).

### R1.2 VK-Kuration: Servierformen + Klassen + W% · Größe L (verteilt) · Hängt an: R1.1

**DoD:**
- [ ] `unbestimmt`-Servierformen der 994 kuratiert (GL-07/Bauart-Regel als Vorschlag, Mensch entscheidet) — *teilweise; Rest offen*
- [x] Speisen-Klassen vergeben (nur aktive 11 HGs) — Skript 289 (dish_class HG×Diät)
- [ ] W%-Ampel übers neue Portfolio gesichtet; Ausreißer > 35 % geflaggt und entschieden — *offen (jetzt möglich, EK steht)*
- [x] Anreicherung gelaufen (Beschreibung/Kochanweisung, Niveau/Sektor, Anker, Pairing, Sensorik) — FA-nativ (Skripte 290/292)

> **Stand 2026-07-05:** Klassifikation + Anreicherung durch (994). Offen bleibt die **Servierform-Rest-Kuration** (`unbestimmt`) und der **W%-Ampel-Durchgang** — beides jetzt sinnvoll, da die EK-Basis vollständig steht.
>
> **Stand 2026-07-12 (VK-Baseline gesetzt):** Prämisse aus Discussion #17 korrigiert — das **Verkaufs-Foodbook-PDF liefert KEINE Pro-Gericht-VK** (bepreist Konzepte pro Person; verifiziert). Stattdessen **Cost-plus-Auto-VK** gesetzt: quantity_per_unit_g = yield×1000 + Aufschlagsklasse **Bankett 260 %** + Recompute → **870/929 Gerichte bepreist** (vorher 3), deterministisch, auto-mode (überschreibbar), Backup `PRE_R12_AUTOVK`. 32 Review-Fälle → `00_INBOX/_R12_VK_Review_2026-07-12.md`. **⚠️ Konsequenz für R2.1:** Cost-plus-VK folgt dem EK (Marge konstant) → der Preis-Alarm greift erst mit **fixem Kundenpreis** = die PDF-Konzept-Preis-Ebene (Discussion-#17-P3, an Konzepte/Pakete). Empfehlung dort: P3 von „optional" auf „R2.1-Voraussetzung" hochstufen.
>
> **DoD-Ergänzung:** [x] VK-Baseline je Gericht gesetzt (Cost-plus) · [ ] W%-Ampel (unter Cost-plus konstant → erst mit Fix-Preisen aussagekräftig) · [ ] Konzept-/Pro-Person-Preise (→ P3).

---

## R2 — Wirtschaftlichkeits-Maschine *(Horizont 1 — macht das System unverzichtbar)*

### R2.1 Preis-Alarm + Marge-Impact · Größe L · Hängt an: R1 (sonst rechnet er auf Testdaten) · ✅ **ABGESCHLOSSEN 2026-07-12** (gegen bepreiste Masse verifiziert)

**DoD:**
- [x] Trigger: LA-Preisänderung > X % (Schwelle team-konfigurierbar in `settings`) erzeugt Signal — `SignalDetektorService::preisSprungMargeImpact`, `TeamSettingsService::preisAlarmSchwellePct` (Default 15 %)
- [x] Impact-Ansicht: „betroffen: N Rezepte, M Konzepte, Marge-Delta in € und W%-Punkten" — klickbar bis ins Gericht (Signal-Payload + Impact-Block im ReviewQueue-Blade, Gericht-Links → Verkaufs-Browser)
- [x] Impact rechnet über Lead-LA-Logik UND zeigt, wenn ein Nicht-Lead-LA günstiger geworden ist (`guenstigereAlternative`, Chance-Fall)
- [x] Signal reversibel/abhakbar (bestehendes Signale-Muster), via `signale.SEARCH` MCP-sichtbar
- [x] Test: synthetischer Preissprung +25 % auf Massen-GP (Salz #13195, Reichweite 275 bepreiste Gerichte) → **Detektor-Signal `n_gerichte=275` == rekursive-CTE-Hand-Query 275 (MATCH ✓)**, gegen die 868-bepreiste FA-Voll-Masse, Transaktion zurückgerollt
- [x] Läuft automatisch beim Preis-Import — via Scheduler (`SignaleDetektorCommand`); engerer Event-Hook in `PriceService::createFor` bewusst nicht (feuert pro Zeile im Bulk)

> **✅ Abschluss 2026-07-12:** R2.1 war seit 2026-07-06 gebaut+gepusht, aber nur auf Testdaten belegt. Jetzt **gegen die R1.2-bepreiste Masse** (868/929 Gerichte, DB `foodalchemist_full` aus `fa_mysql_FULL_2026-07-12.sql.gz`) verifiziert: Betroffenen-Zahl exakt gegen Hand-Query bewiesen. Ehrlicher Nebenbefund: bei einem billigen Commodity (Salz) ist das Marge-Delta ≈ 0 € trotz +25 % — das Tool erfindet keinen Impact (exposure-korrekt). Detail: Memory `project_fa_r2_scharfstellen_2026-07-12`.

### R2.2 Was-wäre-wenn-Simulation · Größe L · Hängt an: R2.1 (nutzt dieselbe Impact-Rechnung) · ✅ **ABGESCHLOSSEN 2026-07-12** (UI-Panel + Massen-Perf)

**DoD:**
- [x] Szenario definierbar: Warengruppe ODER Einzelartikel ODER GP ± X % — UI-Panel `Kalkulation/Simulation` in der Kalkulations-Werkstatt (WG-Dropdown, GP-Schnellsuche, Artikel-id) + MCP-Tool
- [x] Portfolio-Antwort: Marge-Delta gesamt + Top-20-Betroffene, ohne Echtdaten zu verändern (reine Lese-Simulation) — KPI-Kacheln + Top-Tabelle, read-only-Marker
- [~] Ersatzvorschläge aus `component_equivalents` inline — Strecke steht (Panel zeigt Vorschläge); Katalog aktuell dünn befüllt (1 Zeile) → oft leer. Voll-Ausbau + „Tausch spart Y €"/Klick-Übernahme = R6.3/R6.8-Track
- [x] Simulation als MCP-Tool (`simulation.POST`, read-only-Semantik) — `SimulationPostTool`
- [x] Performance: Portfolio-Simulation über ~1.000 Gerichte < 10 s — **WG-Extremfall (Convenience, 1538 Lead-GPs → 599 betroffene Gerichte / 1392 Rezepte) = 8,7 s** gegen die Voll-Masse. **Speicher-Peak 245 → 111 MB** (Cache-Eviction im `MargeImpactService`/`SignalDetektorService`: schwere Rezept-Modelle nach dem Memoisieren freigegeben — kein Recompute, Ergebnis byte-identisch) → jetzt sogar unter 128 M, Server-Risiko behoben

> **✅ Abschluss 2026-07-12:** R2.2-Service+MCP-Tool waren seit 2026-07-06 da; das **fehlende UI-Panel** ist jetzt gebaut (Kalkulations-Werkstatt) und gegen die bepreiste Masse Perf-verifiziert. 4 neue Pest-Tests (`SimulationPanelTest`) grün. Speicher-Footprint bei Mega-WG von 245 → 111 MB gesenkt (result-preserving). Detail: Memory `project_fa_r2_scharfstellen_2026-07-12`.

### R2.3 Menu-Engineering mit Ist-Zahlen · Größe XL · Hängt an: R1 + **Vorentscheid Datenquelle**

⚠️ **Offene Vorfrage (vor Baustart klären):** Woher kommen Verkaufs-/Bankettdaten, seit Necta raus ist?
Realistisch: CSV/Excel-Export aus Bankettprofi o. ä. Format-Spec MUSS vor dem Bau stehen — sonst bauen wir einen Import ins Blaue.

**DoD:**
- [ ] Import-Format-Spec dokumentiert (Docs im Dev-Modul) + Beispieldatei eines echten Caterers erfolgreich geladen
- [ ] Matching Verkaufsposition → VK-Gericht mit Review-Queue für Unmatched (kein stilles Raten — Wording-Matcher-Muster aus Skript 250 wiederverwenden)
- [ ] Stars/Renner/Schläfer/Penner-Matrix (Popularität × DB) je Konzept/Zeitraum
- [ ] DB-Ranking + W%-Ampeln übers Portfolio, filterbar nach Facetten
- [ ] Mindestens 1 echter Datensatz eines BHG-Caterers durchgelaufen, Ergebnis mit Dominique plausibilisiert

### R2.4 Marge-optimale Menü-Assemblierung · Größe XL · Hängt an: R1 + R4.1

Aus dem Portfolio *lösen* statt *raten*: gegeben Rahmen (Preis/Gäste/Constraints) → DB-maximale Gericht-Kombination.

**DoD:**
- [ ] Solver: Zielpreis p. P. + Gästezahl + Coverage-Constraints (Diät-Quoten, Gang-/Stations-Gerüst, Preisspannen) → DB-maximale Kombination aus dem VK-Portfolio
- [ ] Nur echte VK-Gerichte, keine Halluzination; Slot ohne zulässigen Treffer bleibt leer + Begründung
- [ ] Lösung erklärt sich: welche Constraints bindend, wie weit vom Optimum bei Lockerung X
- [ ] Als MCP-Tool (`assemblierung.POST`, read-only-Semantik) — KI kann Varianten durchspielen
- [ ] Übernahme nur explizit (Konzept `status=draft`), kein Auto-Commit
- [ ] Performance: Portfolio ~1.000 Gerichte < 15 s
- [ ] Test: kleiner Constraint-Satz mit hand-gerechneter Optimallösung exakt reproduziert

### R2.5 Saison-Auto-Pricing (intern-vorschlagend) · Größe M · Hängt an: R2.1 + R3.1 · ✅ GEBAUT 2026-07-19 (Engine+MCP)

Löst den Vertrauensbruch durch **Trennung**: interne Live-Marge vs. veröffentlichter, freigegebener VK.

**DoD:**
- [x] Saubere Trennung: interne Marge (live, `recipe_darreichungen.sales_net`) ↔ veröffentlichter VK = freigegebener Snapshot (`foodalchemist_vk_price_snapshots`, `VkSnapshotService`)
- [x] Trigger: Live-VK verlässt Leitplanke ggü. Snapshot → Signal `VkAnpassungEmpfohlen` (N Gerichte, Richtung + Delta; Detektor in `laufen()` verdrahtet, R2.1-Muster)
- [x] Freigabe menschlich + als Batch: `VkSnapshotService::release` / MCP `vk_snapshots.RELEASE` schreibt Snapshot (isOwnedBy); kein stiller Kunden-Preissprung
- [~] Kundensicht (R3.2) zeigt ausschließlich freigegebenen VK — `publishedFor()` bereitgestellt; **View-Anschluss in R3.2 = Folge-Slice** (bewusst nicht in dieser Etappe, um die Kunden-Ansicht nicht blind umzuschreiben)
- [x] Leitplanken konfigurierbar: `min_margin_pct`, `max_vk_delta_pct`, `season_margin_band_min/max_pct` (TeamSettings-Migration + Accessoren)
- [x] Test: `VkSnapshotTest` (3 Pest) — VK-Sprung ohne Freigabe → Signal + veröffentlichter VK bleibt unverändert; release nur eigene Darreichungen

> **v1-Note:** Engine + MCP (`vk_snapshots.GET`/`RELEASE`) + Signal + Settings + 3 Pest stehen; 80er-Regression (Signale/Kalkulation/Darreichung) grün. **Offen (Folge-Slices):** Batch-Freigabe-Button im Signale-/Kalkulations-UI + R3.2-Kundensicht liest `publishedFor`. Band-Margin-Trigger (season_margin_band) ist als Setting da; der aktive Detektor nutzt v1 die Snapshot-Delta-Leitplanke (klarster „stiller Preissprung"-Schutz).

### R2.6 Feedback je Gericht/Rezept (Küche · Kunde · Event) · Größe M · Hängt an: nichts (FA-nativ; sinnvoll ab R1)

Feedback-Tab am Gericht UND am Basisrezept — **zwei Zwecke**: (1) Popularität für Menu-Engineering OHNE Verkaufsdaten-Import (entkoppelt R2.3), (2) **Küchenmitarbeiter-Feedback als Entwicklungs-Motor** — die Küche bewertet/kommentiert Rezepte & Gerichte aus der Praxis (Machbarkeit, Aufwand, Geschmack, Gäste-Reaktion), auf dieser Basis werden sie iterativ weiterentwickelt. Der Koch, der es kocht, ist die ehrlichste Quelle.

**DoD:**
- [x] Feedback-Tab am Gericht **und am Basisrezept**: Einträge mit Quelle (**Küche** · Kunde · Event), Score, Kommentar, optional Kontext — geteiltes `FeedbackPanel` in VkModal + RecipeModal
- [x] Küchen-Feedback strukturiert: Achsen Machbarkeit/Aufwand · Geschmack · Gäste-Reaktion (nur bei quelle=kueche); Score = Mittel aus Machbarkeit/Geschmack/Gäste, wenn nicht gesetzt
- [x] Aggregation: Ø-Score + Verteilung je Quelle + jüngste Kommentare, sichtbar in **Verkauf- und Rezept-Browser (Badge)** + **internem Foodbook (Menü-Ansicht)** + **im Editor**; on-read (kein Recompute)
- [x] Speist die Popularitäts-Achse des Menu-Engineering (R2.3) — Feedback als eigene Quelle, entkoppelt R2.3 vom offenen Verkaufsdaten-Import
- [x] „Weiterentwickeln"-Brücke: 1 Klick → Draft-Rezept-Iteration (via `RecipeService::duplicate` + status=draft), Lineage `feedback.spawned_recipe_id`, idempotent
- [x] MCP: `foodalchemist.recipe_feedback.POST` (created_via=mcp) + `.SEARCH` (Aggregat read-only), Quelle inkl. `kueche` — registriert
- [x] Team-Scoping + D1: **vertikaler Scope** (Ancestry ∪ Descendants) — Kind bewertet eigenständig + sieht geerbtes, Eltern sieht Kinder aggregiert, Geschwister isoliert
- [x] Test: 3 Feedback-Einträge (Küche/Kunde/Event) → korrekter Ø + korrekte Team-Sichtbarkeit — Pest `FeedbackTest` (7 Tests) + `SimulationPanelTest`-Muster

> **✅ Abschluss 2026-07-12:** FA-nativ gebaut (Tabelle `foodalchemist_recipe_feedback`, `FeedbackService`, Enum `FeedbackQuelle`, 2 MCP-Tools, geteiltes `FeedbackPanel` in beiden Editoren, Badges in beiden Browsern + Foodbook). 7 neue Pest-Tests grün, 0 Regressionen (Adjazenz-Suite). Drive-by-Fund: pränataler English-Rename-Drift (`diaetform`→`diet_form`, `is_organic/is_regional`→`tag_is_organic/tag_is_regional`) im VK-Editor-Pfad gefixt (VkModal 500te auf MySQL). ⚠️ **Offener Rest des Drift-Clusters** (nicht in R2.6-Scope): `IngredientEditor` GP-Bio/Regional-Filter (Zeile 219/220), `FoodAlchemistGp`-fillable, `ImportSliceCommand` nutzen weiter `is_organic/is_regional` → eigener Cleanup. Detail: Memory `project_fa_r26_feedback_2026-07-12`.

### R2.7 Portfolio-Benchmark (BHG-intern) · Größe M · Hängt an: R1 · ✅ **ABGESCHLOSSEN 2026-07-12**

Multi-Tenant *aggregieren* statt nur *trennen* — Netzwerk-Effekt, der mit jedem Caterer stärker wird.

**DoD:**
- [x] Kennzahlen je Team aggregiert: EK-Abdeckung, Allergen-Konfidenz „hoch", Formen-Vollständigkeit, Ø-Wareneinsatz, Ø-Bewertung, Gericht-Zahl — `BenchmarkService::kpisFuerTeam`
- [x] Vergleich Team vs. anonymisierter Peer-Median — nur Aggregat, keine Fremd-Gericht-Details, keine Peer-Namen (Leak-Grep-Test grün)
- [x] Datenschutz-Grenze: nur innerhalb der Root-Team-Kette (`netzTeamIds` = Root + Descendants); kein Cross-Kunde
- [x] Als Dashboard-Kachel (eigen vs. Peer-Median, Farbcode besser/schlechter) + MCP-Tool `foodalchemist.benchmark.GET` (read-only)
- [x] Extern-Benchmark bewusst NICHT enthalten
- [x] Test: `BenchmarkTest` (5) — 1-Peer- + 2-Peer-Median exakt, Einzel-Gastronom (0 Peers), Leak-Grep

> **✅ Abschluss 2026-07-12:** `BenchmarkService` + `BenchmarkGetTool` + Dashboard-Kachel. Peer = andere Teams derselben Root-Kette MIT Portfolio (n_dishes>0, anonym). Ø-W% engine-agnostisch in PHP gerechnet (SQLite-decimal-TEXT-Falle). 5 Pest-Tests grün, 0 Regressionen. Detail: Memory `project_fa_r26_feedback_2026-07-12`.

---

## R3 — Digitales Foodbook *(vorgezogen — interner Use Case zuerst; parallelisierbar zu R2 nach R1)*

### R3.1 Web-Foodbook intern · Größe XL · Hängt an: R1 · 🟢 **intern-Dokument GEBAUT 2026-07-13 (lokal ungepusht)**

> **Richtungs-Entscheid Dominique 2026-07-13 (#501-konform):** Das „interne Foodbook" ist **kein Standalone-Livewire-View** (der wurde in #501 bewusst gelöscht), sondern das **aufgewertete Dokument** — navigierbar/klickbar + Marge. Der Editor bleibt die Bau-/Filterfläche. Die *externe* Sicht (R3.2) wird eine eigene, gebrandete **Web-Seite** (Bilder/KI, Preise pro Person, kein Pax) — größerer Neubau.

**DoD:**
- [x] Navigierbares/klickbares Dokument: **Navleiste** (Kapitel-Sprungziele, klickbar in HTML UND PDF via Anker) + Kapitel-Baum mit Tiefe. Volltextsuche = Editor/Browser-Sache (Dokument ist Lese-/Versand-Fassung)
- [x] **Interne Sicht zeigt EK/VK/W% pro Person** je Kapitel + Gesamt (`dokumentDaten($intern=true)` → `/foodbooks/{id}/dokument?intern=1`, Kunde/Intern-Umschalter, „INTERN"-Badge + „nicht weitergeben"-Fuß). Marge NIE im Kundendokument (per-Test bewiesen: Kundensicht ohne `ek_pro_person`)
- [x] Preise/W% live aus der bestehenden Kaskade (`kapitelAggregat`/`gesamt`, Resolver) — kein Snapshot
- [ ] Facetten-Filter (Servierform/Eventtyp/Saison/Diät/Allergen) — **offen** (gehört eher zur R3.2-Web-Seite / einem filterbaren Foodbook-Browser; Taxonomie-Modelle da, nicht am Foodbook verdrahtet)
- [ ] Lasttest 500+ Blöcke < 3 s — offen (Dokument rendert derzeit voll; relevant erst bei der Web-Seite mit Lazy-Load)
- [x] Test: interne Projektion (EK/W%/Anker) + Blade-Render intern vs. Kunde — `FoodbookServiceTest` (2 neue Tests) grün; Editor-Link „Dokument (intern)"

### R3.2 Kunden-Ansicht = externe Web-Seite · Größe L · Hängt an: R3.1 · 🟢 **v1 layout-first GEBAUT 2026-07-14 (lokal)**

> **Block C der Ausgabe-Schicht (Dominique):** die *externe* Sicht ist eine eigene **gebrandete Web-Seite** (Bilder/KI, Preise pro Person, kein Pax), NICHT nur ein Doc-Toggle. v1 = Layout/Struktur (auth-gated), Bilder + per-Kunde-CI + Share-Link folgen.

**DoD:**
- [x] Eigenständige Kunden-Web-Seite `/foodbooks/{id}/praesentation` (Livewire-Full-Page): Hero + Kapitel (Konsumententitel + Preis pro Person) + Wording-Gericht-Zeilen + Preis-Fuß/MwSt. Serverseitige Kunden-Projektion (`dokumentDaten intern=false`) → **EK/W%/Interna nie im Response** (nicht CSS-versteckt)
- [x] Wording über WordingResolver-Kette; **Kunden-CI (Brand/Farben) offen** (Foodbook hat nur `writingStyle`, keine Brand-Relation → neutrale Gestaltung v1)
- [ ] **Bilder** (Hero/Gericht) — Platzhalter „Bild folgt"; echte Bilder = Iteration (kein Gericht-Bild-Feld; #461 Hero-Medien)
- [ ] Share-Link-Konzept entschieden (signierter Gast-Link vs. Kunden-Login — Discussion Martin, Core-Auth) — **aktuell auth-gated**; Entscheid offen
- [x] Sichtbarkeits-Test: Response zeigt Preis pro Person, aber nachweislich **kein „Wareneinsatz"/„INTERN"** (Response-Grep) — `FoodbookServiceTest`
- Editor-Link „Präsentation" neben „Dokument" / „Dokument (intern)"

---

## R4 — Geführte Planung *(die Vault-Skill-Kaskade wird Produkt; Fundament für R6 Brief→Konzept)* · ✅ **KOMPLETT 2026-07-13** (R4.4 mit benannter Teil-Abweichung → R6.3)

### R4.1 Planungs-Gerüst-Datenmodell (Canvas-Ausbau) · Größe L · Hängt an: R0 (Facetten live) · ✅ **ABGESCHLOSSEN 2026-07-13**

Kern des Pakets: Das Gerüst ist **strukturierte Daten**, kein Freitext-Canvas — sonst kann R4.2 nichts messen und R6 nichts prompten.

**DoD:**
- [x] Datenmodell: Mengengerüst (n Gerichte je Kapitel/Gang inkl. Diät-Quoten), Preisarchitektur (Anker, Spannen, Zielpreis p. P.), Kunden-Politik (No-Gos, Allergen-Linie), Saison-Abdeckung, Dramaturgie (Gang-Folge/Buffet-Stationen als Slot-Gerüst-Regel) — 3 Tabellen `planning_frames` (Kopf + Preis p. P.) / `planning_frame_slots` (Dramaturgie + Mengen + Preis je Slot) / `planning_frame_rules` (diet_quota gegen `diet_form`-Vokabular · season_coverage · nogo_ingredient/nogo_allergen (EU-14-Keys) · allergen_line; je Frame oder je Slot)
- [x] Am Foodbook UND am Konzept anhängbar (ein Gerüst, zwei Konsumenten) — owner polymorph, unique je Owner
- [x] Erfassungs-UI im Canvas-Kontext; jedes Feld optional (Gerüst wächst, zwingt nicht) — Trait `ManagesPlanningFrame` + Partial `planning/partials/frame-board` im Foodbook-Editor (Modal neben Leitidee-Canvas) und Concepter-Konzept-Tab
- [x] MCP: `foodalchemist.planning.GET/PUT` — PUT übersetzt ein Brief in EINEM Call (head + slots + rules deklarativ/idempotent), Lineage `created_via=mcp_tool` + draft, status-Freigabe bleibt menschlich; GET liefert zusätzlich `prompt_kontext` (fertiger R6-KI-Block)
- [x] Migration bestehender food_dna-Canvas-Werte kollisionsfrei — Canvas-Tabellen/-Templates unangetastet (Prosa bleibt Kontext-Ebene), per Test bewiesen

### R4.2 Soll/Ist-Coverage live · Größe L · Hängt an: R4.1 · ✅ **ABGESCHLOSSEN 2026-07-13**

**DoD:**
- [x] Coverage-Engine: vergleicht Foodbook-/Konzept-Ist gegen Gerüst-Soll je Dimension (Menge/Diät/Preis/Saison/Dramaturgie) — `CoverageService`, plus No-Gos (Zutat-Namens-Match über Gericht + direkte Zutaten, Allergen über EU-14-Felder); Diät-Ist über `dish_classes.diet_form` + spec-Flag-Fallback; Slot-Scope via chapter_id > Label-Match, ehrliche Degradation bei fehlendem Ist-Bezug/unbestimmter Diät
- [x] Live-Anzeige beim Befüllen — Coverage-Panel im Concepter-Aufbau-Tab UND im Foodbook-Editor (aufklappbar, bei Rot offen), nicht in einem Report versteckt
- [x] Coverage als MCP-Tool abrufbar (`foodalchemist.coverage.GET`) — dieselbe Messlatte für Mensch und KI, mit Aufruf-Pflicht-Hinweis nach KI-Befüllung
- [x] Ampel-Logik erfüllt/teilerfüllt/verletzt (+info für nicht messbare allergen_line) — Lücken-Klick: Concepter setzt den neuen Diät-Filter des Gericht-Pickers (`pickDiaet`, diet_form-Achse), Foodbook verlinkt den VK-Browser klassen-gefiltert
- [x] Test: absichtlich verletztes Gerüst zeigt exakt die erwarteten Warnungen (Positiv- + Negativ-Test, `CoverageTest`)

### R4.3 Phasen-Status je Foodbook/Konzept · Größe M · Hängt an: R4.1 · ✅ **ABGESCHLOSSEN 2026-07-13**

**DoD:**
- [x] Statusmaschine: Kontext → Struktur → Befüllung → Kalkulation → Freigabe (`phase`-Spalte an Foodbook + Konzept, ergänzt draft/aktiv) — `PhaseService` + Stepper-Partial in beiden Editoren
- [x] Gate: Freigabe nur ohne rote Coverage-Ampeln — Override mit Pflicht-Begründung, durabel protokolliert (`phase_override_note/_at` am Objekt + ActivityLog wo vorhanden; Sandbox-Stub-sicher). Rückwärts-Übergänge frei
- [x] Phase sichtbar in beiden Browser-Listen (Badge) + filterbar (`?phase=`-URL-Filter)
- [x] MCP: `foodalchemist.phase.PUT` (kontext…kalkulation) + Phase in `foodbook.GET`/`concepts.GET`; Freigabe doppelt gesichert menschlich (Schema-Enum + Service-Guard `via=mcp`)

### R4.4 Zutaten-/Artikel-Tausch im Concepter · Größe M · Hängt an: R1 (+ Varianten-Mechanik) · *(Dominique-Wunsch 2026-07-06)*

Die Zeilen-Funktionen des Zutaten-Editors (**⇄ Produkt tauschen, ♻ Äquivalenz-Ersatz, 📦 GP-Peek, 📖 Rezept einsehen**) existieren bereits in Basisrezept- **und** Verkauf-/Gericht-Editor (geteilter `IngredientEditor`) — **fehlen aber im Concepter**: dort werden Gerichte nur in Slots gesetzt (Darreichung/Geschirr/Wording/Facetten), die Zutaten-Zeilen eines Gerichts sind nicht bedienbar.

> ⚠️ **Scope-Entscheid vor Baustart (Sparring):** Ein Tausch im Concepter darf **nicht** das global geteilte VK-Gericht mutieren (es hängt in N anderen Konzepten/Foodbooks). → **konzept-lokale Variante** über die vorhandene `varianteAnlegen`-Mechanik (Slot-Variante am Kerngericht), NICHT direkte Bearbeitung des Quell-Gerichts. „Tauschen" im Concepter = „für dieses Konzept variieren".

**Status: ✅ ABGESCHLOSSEN 2026-07-13** *(mit einer benannten Teil-Abweichung, s. u.)*

**DoD:**
- [~] Gericht-Baum im Concepter zeigt die Zutaten-Zeilen (read-first, 🧾-Toggle je Gericht-Slot) — Zeilen-Aktionen: ♻ Äquivalenz-Ersatz (mit Ziel-Name), 📖 Sub-Rezept-Peek, 🔒 swap_locked-Anzeige. **Rest-Parität zum `IngredientEditor` (⇄ Produkt-/LA-Tausch, 📦 GP-Peek) bewusst offen → gehört zur R6.3-Tausch-Strecke** (dort kommen Kosten-Kontext + Caveats dazu)
- [x] ♻ Ersatz erzeugt/nutzt eine **Slot-Variante** (konzept-lokal, `ConceptVariantService`): Voll-Kopie per replicate (VK-/Allergen-/EK-Felder + Zutaten + Darreichungen), Quell-Gericht unangetastet — „variiert"-Badge + „↩ Original"-Rücksetzen (räumt die Variante weg)
- [x] EK/Marge des Slots rechnet live gegen die Variante (Slot referenziert sie; Recompute beim Anlegen + Tausch; Test: 500 g Butter→Margarine = EK 6,00 → 2,00 €)
- [x] Kein stiller Global-Edit — Varianten sind katalog-unsichtbar (`variant_source_recipe_id`-Filter in VK-Browser + allen Gericht-Pickern); globale Änderung = bewusst Verkauf-Editor
- [x] MCP: `foodalchemist.concept_slot_variante.POST` (variieren | ingredient_id-Tausch | zuruecksetzen); Test: Tausch in Konzept A ändert Konzept B / Quell-Gericht nachweislich nicht (`SlotVarianteTest`)

---

## R5 — Deklaration & Compliance *(Horizont 2 — parallelisierbar nach R1, hoher Vertriebswert)*

### R5.1 Buffet-Kärtchen & LMIV-Etiketten · Größe M · Hängt an: R1 (sinnvoll erst mit Masse)

**DoD:**
- [ ] Knopfdruck am Gericht/Konzept/Foodbook: druckfähige Buffet-Kärtchen (Name, Allergene, Zusatzstoff-Fußnoten) + LMIV-Etikett (Zutatenliste absteigend, Allergene hervorgehoben, Nährwerte je 100 g)
- [ ] Datenquelle ist ausschließlich die deklarationsfeste Aggregation (ALL-MAXIMAL + Konfidenz) — Gerichte mit `unbekannt`-Allergen-Konfidenz werden BLOCKIERT, nicht schöngedruckt
- [ ] Layout im Kunden-CI (Brand-Anbindung), PDF-Export
- [ ] Fachliche Abnahme: 10 Etiketten von Dominique gegen Regelwerk geprüft
- [ ] Als MCP-Tool verfügbar (`etiketten.POST` o. ä.)

### R5.2 CO₂e je Gericht/Konzept + Bio-%/Regional-% · Größe L · Hängt an: R1

**DoD:**
- [ ] CO₂e-Faktorquelle entschieden und dokumentiert (z. B. Eaternity/Klimatarier-Faktoren je GP-Warengruppe — Lizenz-/Quellen-Entscheid Dominique)
- [ ] Faktor am GP, Aggregation über Rezeptbaum analog Allergen-Logik (inkl. Konfidenz: geschätzt vs. belegt)
- [ ] Bio-%/Regional-% aus spec-Feldern aggregiert und am Gericht/Konzept angezeigt
- [ ] Ausschreibungs-tauglicher Export (Kennzahlen-Block je Konzept)
- [ ] Kein Greenwashing-Default: fehlender Faktor = „nicht bewertet", nie 0

### R5.3 HACCP-Doku generiert · Größe M · Hängt an: R1.2 (Regenerations-Daten je Darreichung gepflegt)

**DoD:**
- [ ] HACCP-Dokument je Gericht/Konzept aus Regenerations-/Kerntemperatur-Daten generiert
- [ ] Gerichte ohne Regenerations-Daten erscheinen als Lücken-Liste (Ampel), nicht mit Platzhaltern
- [ ] Vorlage mit einem BHG-Küchenleiter fachlich abgenommen
- [ ] PDF-Export + Ablage am Konzept

---

## R6 — Alleinstellung ausspielen *(Horizont 3 — hat kein Wettbewerber; braucht R1 + R4)*

> **Warum-Layer (Querschnitts-DoD für R6, hängt an Q4):** Jeder suggestionserzeugende R6-Output (R6.1 Konzept, R6.3/R6.8 Substitution, R6.4 Idee, R6.11 Hypothese) trägt eine **zitierte Begründung** — Mechanismus + Quelle + Evidenz-Stufe (aus Q4). Kein Beleg → als Hypothese (T3/T0) markiert, nie als Fakt. Kann als Begründungstext in Foodbook/Kundensicht (R3, im Kunden-Wording) einfließen. Gilt zusätzlich zur jeweiligen Paket-DoD.
>
> **Konnektivität (Pairing-Offense R6.8–R6.10 + Kohäsion, hängt an Q5):** Diese Pakete brauchen graph-*erreichbare* Gerichte — ohne Anker-Erdung (Q5) sieht der Graph das Gericht nicht. Baseline 2026-07-04: Kohärenz nur **0,2 %** berechnet, Rezept-Anker-Reichweite **60 %** → Q5 ist harte Voraussetzung, nicht Kür. → **Update 2026-07-12:** Die **Graph-Dichte/Coverage-Seite ist gelöst** (Station 2: 37 %→58 %, ~179k Kanten, global). Von Q5 offen bleiben nur noch (a) der **Kohärenz-Score-Batch-Lauf** (KI-Judge, blockiert auf echten Gemini-Provider) und (b) **Zutaten-Anker-Reichweite** (60 %). Damit sind R6.8–R6.10 **halb entblockt** — die Dichte steht, es fehlt der Score-Lauf.

### R6.1 Brief → fertiges Konzept mit Kohäsions-Beweis · Größe XL · Hängt an: R1 ✅, R4 ✅, R0.2 ✅ · **GEBAUT 2026-07-13 — offen: Blindtest**

**DoD:**
- [x] Input: Planungs-Gerüst (R4.1) oder Freitext-Brief → Konzept ausschließlich aus echten VK-Gerichten. `ConceptGeneratorService`: Brief → KI baut NUR das Gerüst (`concept.brief_geruest`, KI-Werte werden defensiv sanitized); die Gericht-Auswahl selbst ist **deterministisch**: harte Filter aus den Gerüst-Regeln (No-Gos/Allergene/Preisrahmen), Diät-Quoten zuerst, Ranking Slot-Semantik (Label↔Speisen-HG) → Pairing-Kanten-Gewinn → Anker-Dichte → Preis-Anker-Nähe. Slot ohne Treffer bleibt LEER mit Begründung (Protokoll + `slot.note`, im Editor sichtbar) — nie halluziniert
- [x] Pairing-Graph prüft die Menüfolge: `PairingService::menuCohesion` (Gericht = Komponente, Anker-Union) → Score + Graph-Abdeckung + schwächstes Paar + ehrlich unbewertete Paare; als Kohäsions-Panel im Concepter (on-demand) + im Generator-Ergebnis. Smoke am Dev-MySQL: Score 99–100 bei 81 % Graph-Abdeckung
- [x] Coverage-Check (R4.2) läuft automatisch — das Gerüst wird als Kopie ans generierte Konzept gehängt (`kopiereZu`), dieselbe Messlatte wie für menschliche Konzepte (Smoke: meldet ehrlich `verletzt` wo das Sortiment die Vorgaben nicht hergibt)
- [x] Ergebnis immer `status=draft` + `created_via`-Lineage (`concept_generator_ui|mcp`, `concept_generator_brief_*`; neue Spalte `concepts.created_via`)
- [ ] **Blindtest (Dominique): 3 echte Kunden-Briefs → mindestens 2 von 3 „mit Anpassung verwendbar".** UI: Concepts-Browser „✨ Konzept aus Brief" (braucht echten LLM-Provider — Dev-`fake` echo taugt nicht) bzw. Foodbook-Gerüst „✨ Konzept aus diesem Gerüst" (läuft OHNE KI). MCP: `foodalchemist.concepts.GENERATE`. ⚠ Hinweis: auf der kleinen Dev-Fixture (31 VK) ist der Pool dünn — Blindtest gegen den Master-Bestand (994 VK) fahren

### R6.2 Angebots-Funnel-Anfang (Brief-Parser) · Größe L · Hängt an: R6.1

**DoD:**
- [ ] Kunden-Anfrage (Mail-Text/Formular) → strukturiertes Event-Brief (Anlass, Gäste, Budget, Diät-Anforderungen, Termin) mit Konfidenz je Feld
- [ ] Unsichere Felder als Rückfrage-Liste, nicht geraten
- [ ] Brief mündet direkt in R6.1 (ein Klick: Brief → Konzept-Vorschlag)
- [ ] Grenze eingehalten: Angebots-FÜHRUNG bleibt Event-Modul — FA liefert Brief + Konzept zu (Zuarbeits-Schnittstelle dokumentiert)

### R6.3 „Kosten senken"-Assistent · Größe M · Hängt an: R2.2

**DoD:**
- [ ] Je Gericht/Konzept: Top-Kostentreiber-Komponenten absteigend
- [ ] Substitutions-Vorschläge aus Äquivalenz-Katalog + Substitutions-Wissen, IMMER mit Caveats (Sensorik/Allergen-Änderung/Qualität)
- [ ] Allergen-Neuberechnung im Vorschlag sichtbar BEVOR getauscht wird
- [ ] Übernahme nur explizit je Vorschlag (kein Bulk-Auto-Tausch), `swap_locked` respektiert

### R6.4 Ideen-Labor · Größe L · Hängt an: R1, R4.2 (Lücken-Begriff kommt aus Coverage)

**DoD:**
- [ ] Kreuzung Trend-Feed (Pulse) × Pairing-Graph × Portfolio-Lücken → konkrete Gericht-/Konzept-Vorschläge
- [ ] Frage beantwortbar: „Was fehlt uns zum Sommer-Trend X?" — Antwort referenziert echte GPs/Anker, keine Fantasie-Zutaten
- [ ] Vorschlag → 1 Klick → Draft-Rezept via bestehender `recipes.POST`-Strecke
- [ ] Wissens-Lineage: jeder Vorschlag nennt Trend-Quelle + Pairing-Kanten

### R6.5 Kunden-DNA als Steuerungsobjekt · Größe L · Hängt an: R3.2, R4.1

**DoD:**
- [ ] Kundenprofil (Vorlieben, No-Gos, CI, Schreibstil) als eigenes Objekt, an Konzept/Foodbook anhängbar
- [ ] Färbt nachweislich: Wording (Resolver-Kette), Gericht-Vorschläge (No-Go-Filter), Design (CI)
- [ ] No-Gos wirken hart: verbotene Zutat/Allergen erscheint nie in Vorschlägen (Testfall)
- [ ] Speist R4.1-Kunden-Politik automatisch vor

### R6.6 Konzept-Validator-Ausbau · Größe M · Hängt an: R6.5

**DoD:**
- [ ] `ConcepterBewertungService` erweitert: Machbarkeits-Check (unbepreiste Ketten, fehlende Formen, Regenerations-Lücken) + Zielgruppen-Check gegen Kunden-DNA
- [ ] Ergebnis als Score + konkrete Findings-Liste (klickbar), nicht nur Zahl
- [ ] Läuft automatisch bei Phasen-Übergang Kalkulation → Freigabe (R4.3-Gate)

### R6.7 Sensorik-Radar über die Menüfolge · Größe M · Hängt an: R1.2 (Sensorik-Daten der Masse)

**DoD:**
- [ ] Balance-Analyse über Gang-/Stations-Folge: Textur- und Geschmacks-Häufung erkannt (z. B. „3× Creme hintereinander", „alles säurelastig")
- [ ] Warnungen im Concepter inline, mit Vorschlag aus `suggest` (Pairing-Graph)
- [ ] Sensorik-Daten-Abdeckung als Ampel — Radar schweigt ehrlich bei dünner Datenlage statt zu raten

### R6.8 Aroma-treue Substitution · Größe M · Hängt an: R6.3 (nutzt dessen Tausch-Strecke) · ✅ GEBAUT 2026-07-19

Der Pairing-Graph offensiv: Ersatz, der den Geschmack *erhält*, nicht nur den Preis senkt.

**DoD:**
- [x] Ersatz-GP nach Kanten-Überlappung im Anker-Graph gerankt, nicht nur nach Äquivalenz/Preis (`PairingService::aromaTrueSubstitutes`)
- [x] Ausgabe zeigt: erhaltene vs. verlorene Aroma-Brücken + Kohäsions-Delta fürs Gesamtgericht (bei Rezept-Kontext)
- [x] Mit R6.3-Kosten kombiniert: „billiger UND aroma-treu" vs. Trade-off sichtbar (`cost`-Block, mode=cost/both; indikativer Lead-LA-Listen-EK)
- [x] Allergen-Neuberechnung im Vorschlag VOR Tausch (`GpAggregateService::allergene`-Diff → `allergen_warnungen`); `swap_locked` im Kontext gespiegelt
- [x] MCP-Tool (`substitution.SUGGEST`, Modi `flavor|cost|both`), read-only; eigentlicher Tausch bleibt `tauscheZutat`
- [x] Test: Klassiker-Tausch Estragon↔Kerbel rankt vor aroma-fernem, gleich teurem Ersatz (`AromaSubstitutionTest`, 3 Pest)

> **Bewusste v1-Abweichungen (verify-before-claiming):** (1) Ranking = graceful gewichtete Mischung `0.6·Kanten + 0.4·Cosinus` statt hartes Produkt — sonst kollabiert das Ranking überall dort auf 0, wo Aroma-Vektoren fehlen (sie sind dünn). (2) `swap_locked` wird im Vorschlag *gemeldet*, aber `ComponentEquivalentService::tauscheZutat` trägt noch KEINEN harten Guard (bestehende R6.3-Lücke) → Follow-up. (3) Cost = indikativer Listen-EK der Lead-LA, NICHT mengennormalisiert.

### R6.9 Dish-Reverse-Engineering · Größe L · Hängt an: R1 (Portfolio zum Nachbauen) · ✅ GEBAUT 2026-07-19

Fremdes Gericht → Aroma-Skelett → Nachbau aus eigenem Bestand.

**DoD:**
- [x] Input Text/fremde Karte → Zerlegung in GPs (`DishReverseService` via `matchIngredient`; Unmatched ohne LA → Beschaffungs-Wunsch-Liste, kein Raten; LA vorhanden → `mintFromLa` tentative)
- [x] Aroma-Skelett aus dem Pairing-Graph extrahiert (tragende Anker + Verbund-Kanten via `gpAnkers`/`edgesFor`)
- [x] Rekonstruktion aus eigenem VK-Portfolio: „nächstes Gericht bei uns" (Anker-Überlappung) + Lücken („dieser Anker fehlt im Bestand")
- [~] Ergebnis mündet per Klick in R6.4 / `recipes.POST`-Draft — Analyse read-only, Draft-Anlage = expliziter Folgeschritt (`recipes.POST`); UI-Klick = Folge-Slice
- [x] Foto-Input als Ausbaustufe markiert (Multimodal = Martin) — Textpfad zuerst
- [x] Test: `DishReverseTest` (2 Pest) — Zerlegung + Skelett + Nachbau-Kandidat + Lücken + Beschaffungs-Wunsch. Realdaten-Plausibilisierung mit Dominique = nach demo-Deploy

> **v1-Note:** MCP `dish.REVERSE` ist read-only → Beschaffungs-Wünsche werden im Response *gelistet*, nicht als Review-Queue-Zeilen geschrieben (Persistenz = explizite Aktion; Quer-Invariante „read-only bis Commit"). Foto-/#507-Recall greifen additiv, wenn Provider live.

### R6.10 Überschuss-zu-Gericht · Größe M · Hängt an: Q1 (Core-Contract) + Pairing-Graph · ✅ GEBAUT 2026-07-19 (Mock)

Erster bidirektionaler Contract-Fall: Lager meldet Überschuss, FA schlägt Verwertung vor.

**DoD:**
- [~] Input: Überschuss-Bestand über **Mock/Contract** `[{gp_id, menge}]` (`SurplusToDishService`) — produktiver Core-Contract-Anschluss = Q1/N-Track offen; FA-eigene Lagerhaltung bewusst NICHT
- [x] Graph schlägt Gerichte, die den GP geschmacklich *tragen* (Anker-Relevanz über `recipe_anchor_mappings`+`recipe_process_anchors`, nicht bloß „enthält") — Konzepte = Folge-Slice
- [x] Vorschlag mit Verwertungs-GP/-Menge + Kohäsions-Begründung; Draft-Konzept per Klick (`concepts.POST`, explizit)
- [x] Grenze gewahrt: FA rechnet/schlägt vor, Bestandsführung + Bestellung bleiben Nachbar-Modul
- [x] FA-seitige Logik baubar + testbar mit Mock-Bestand; produktiv erst mit Q1/Nachbar-Modul (N-Track)
- [x] Test: `SurplusToDishTest` (2 Pest) — Mock-Überschuss rein → tragendes Gericht + verwertete Menge raus; nicht verwertbare Überschüsse gelistet

> **Damit ist die Pairing-Offense (Trio R6.8–R6.10) FA-seitig komplett.** Voller Effekt: R6.10 produktiv = Q1-Contract (Martin/N-Track); Aroma-Reichweite/Kohärenz = Q5. Konzept-Kandidaten (neben Gerichten) + Portions-genaue Verwertungsmenge = Folge-Slices.

### R6.11 Hypothesen- & Widerspruchs-Modus (R&D) · Größe M · Hängt an: Q4 + Pairing-Graph · **S1–S4 ✅ GEBAUT 2026-07-19**

Der Warum-Layer offensiv: nicht erklären, was ist — sondern erforschen, was sein könnte.

> **S1–S3 gebaut 2026-07-19** (einziger Rest = optionales KI-Narrativ). **S1 Hypothesen-Modus:** `PairingService::sharedCompoundClasses` + `hypothesizeFor(gp/anchor,limit)` (Ranking nach geteilten Aroma-key_components + Molekül-chem_class, Mechanismus, Evidenz-Tier T3, Novität-Flag, graceful Aroma-Cosinus-Fallback) + MCP `knowledge.HYPOTHESIZE`. **S2 Widerspruchs-Detektor:** `SignalDetektorService::widerspruchWissenGraph` — `pairing`-Doc-Partner vs. `pairing_anchor_edges` Präsenz/Absenz → `SignalTyp::WiderspruchWissenGraph` (Info, dedup je Doc, in `laufen()`); nur belegt-ohne-Kante feuert, unauflösbare Namen = Lücke. **S3 Output-Senken:** Migration `foodalchemist_lab_notes` + Model + `LabNoteService` + MCP `lab_notes.POST` (write, isOwnedBy); Draft-Zweig via `recipes.POST`. `HypothesizeModeTest` (5) + `WissensWiderspruchTest` (5) = 10 Pest. **Vorbedingung E5 verifiziert:** Chem-/Pairing-Tabellen an Dev-DB voll (`molecules` 74.7k · `pairing_computed` 341k).

**DoD:**
- [x] Hypothesen-Modus: „paare X ungewöhnlich" → Kandidaten gerankt nach geteilten Volatil-Klassen, mit Mechanismus + Evidenz-Stufe — Experiment mit Absicht statt Zufall (S1 ✅)
- [x] Widerspruchs-Detektor: `pairing`-Doc ⇄ Graph-Kante Präsenz/Absenz → als R&D-Frage surfacen (nicht still auflösen) + in die Research-Queue (S2 ✅; Domain-Prosa = v2, E3)
- [x] Ergebnis immer mit Evidenz-Stufe; T3/T0 klar als Hypothese, nie als Fakt (S1 T3 · S2 doc_tier T0 · S3 Tier-Pflicht)
- [x] Vorschlag → 1 Klick → Draft-Rezept (`recipes.POST`) oder Lab-Journal-Eintrag (`foodalchemist_lab_notes`) — S3 ✅
- [x] **Kontrast-Hypothesen (S4 ✅):** Paarung über Geschmacks-GEGENSATZ (Fett↔Säure, Süß↔Bitter …) + kuratierte `kontrast`-Kanten offensiv — schließt die Lücke „Aroma-Harmonie findet nur Verwandtschaft" (`contrastHypothesesFor`, MCP `mode=kontrast`)
- [x] Als MCP-Tool (`knowledge.HYPOTHESIZE` read-only, `mode=harmonie|kontrast` + `lab_notes.POST` write), read-only bis Draft (S1/S3/S4 ✅)
- [ ] Test: bekannter strittiger Fall (Domain-Doc vs. Graph) wird korrekt als offene Frage geflaggt, nicht willkürlich entschieden

---

## Querschnitt (phasenunabhängig, aber terminkritisch)

### Q1 Core-Contract-Discussion an Martin — **VOR Event-Modul-Bau** · Größe S (Discussion, nicht Code)

**DoD:**
- [ ] Discussion im Dev-Modul: Interface-Entwurf `Konzept + Gästezahl → skalierte Komponenten-Mengen, Lead-LA-Bestellvorschlag je Lieferant, Arbeitszeiten, Regenerations-Parameter`
- [ ] Explizit als Resolver-Interface in `Platform\Core\Contracts` vorgeschlagen (nie Model-Zugriff)
- [ ] Martin hat geantwortet/entschieden BEVOR irgendwer Event-Modul-Code schreibt — sonst ist die Modul-Grenze Makulatur

### Q2 Eingangs-Schnittstelle Preise/Kataloge (Ex-Necta) · Größe M · laufend

**DoD:**
- [ ] Bestehende Import-Pipeline als reine EINGANGS-Schnittstelle dokumentiert (kein VK-Rückweg — FA ist Master)
- [ ] Katalog-Import-Lücken geschlossen (z. B. Grønn → entsperrt Petersilienöl 7900)
- [ ] Preis-Import triggert R2.1-Alarm

### Q3 KVP-Betrieb (Arbeitsprinzip aus GOALS)

**DoD (Dauerzustand, quartalsweise geprüft):**
- [ ] Jeder Live-Test-Reibungspunkt wird binnen Session Fix oder Dev-Modul-Issue — keine mündlichen „merken wir uns"
- [ ] Datenqualitäts-Signale (EK-Lücken, fehlende Formen, unbepreiste Ketten) laufen automatisch, Ampel im Dashboard sichtbar
- [ ] Regelwerke schlagen Memory schlagen Code — bei jedem Konflikt dokumentiert entschieden

### Q4 Evidenz-Abdeckung & Anreicherung (Wissensbasis) · Größe M (Aufbau) + laufend · **Fundament für den Warum-Layer (R6/R6.11)**

Der Warum-Layer ist nur so gut wie seine Evidenz. Statt dünne Datenlage zu verstecken: sichtbar machen, ehrlich abstufen, gezielt schließen, durch Nutzung verdicken.

**DoD:**
- [ ] **Evidenz-Ampel:** Abdeckungs-Index über Anker-GPs / Pairing-Kanten / Domain-Konzepte — je Knoten Anzahl + Qualitätsstufe der Belege, KI vs. verifiziert (Heatmap weißer Flecken; spiegelt die Datenqualitäts-Ampel)
- [ ] **Evidenz-Stufen definiert:** T1 verifizierte Primärquelle + Graph-Kante · T2 Graph-Kante quantitativ ODER aktiviertes Destillat · T3 einzelnes KI-Destillat = Hypothese · T0 nichts = still. Layer nennt IMMER die Stufe
- [ ] **Zwei Evidenz-Typen anerkannt:** quantitativ (geteilte Volatile im Ahn-Graph/FlavorDB2) UND prosaisch (Docs) — starke Graph-Kante ohne Prosa ist NICHT „dünn"
- [ ] **Lücken treiben Recherche:** Ampel erzeugt die Research-Queue — `food_research` / `109_destill_pdf` / Trend-Pulse werden auf weiße Flecken gezielt statt breit gestreut
- [ ] **Flywheel:** menschliche Bestätigung/Korrektur (inkl. „warum ging's / ging's nicht" aus R2.6-Bewertungen) wird zum verifizierten T1-Eintrag — tacit → explicit
- [ ] Ehrliche Degradation: bei T0/T3 sagt der Layer „dünne Evidenz / Hypothese", nie ein erfundener Mechanismus

> **Abgrenzung zu Q5:** Q4 = *ist die Aussage belegt* (Evidenz-Qualität). Q5 = *sieht der Graph das Gericht überhaupt* (Konnektivität/Reichweite). Konnektivität geht Evidenz voraus.

### Q5 Graph-Konnektivität & Mapping-Reichweite (Anker-Erdung) · Größe M · laufend · **Fundament für Pairing-Offense (R6.8–R6.10) + Kohäsion**

**Baseline gemessen 2026-07-04 (WaWi-DB) — was wir für dünn hielten, ist es nicht; dünn ist woanders:**

> *Werte unten = alte WaWi-DB. **FA-Master 2026-07-12: 1.000 Anker / ~179k Kanten (global, `team_id=NULL`)** nach Station 2 — die Graph-Dichte/Coverage-Seite (37 %→58 %) ist damit erledigt. Offen bleibt der Kohärenz-**Score-Lauf** (Zeile unten) + Anker-Reichweite.*

| Kennzahl | Ist | Urteil |
|---|---|---|
| GP-Erdung (approved mit Anker) | 6.679 / 6.802 = 98 % | ✅ stark |
| Genutzte GPs mit Anker | 1.674 / 1.735 = 96 % | ✅ stark |
| Zutaten-Mapping Coverage | 13.410 / 13.423 = 99,9 % | ✅ voll |
| Kanten-Graph | 23.951 Kanten / 767 Anker (~62/Anker) | ✅ gesund |
| Mapping-Qualität `gemini_proposed` unverifiziert | ~64 % aller Zutaten | ⚠️ Vertrauen dünn |
| Rezepte mit Anker-Mapping | 1.383 / 2.322 = 60 % | ⚠️ ~940 graph-blind |
| Rezepte mit Kohärenz-Score | 5 / 2.322 = 0,2 % | 🔴 Feature leer |

**DoD (nach Hebel sortiert):**
- [~] **Kohärenz-Score über das Portfolio berechnen** (heute 0,2 % = 0 Zeilen in `recipe_culinary_coherence`): Batch-Compute für alle Gerichte mit Ankern; Ziel ≥ 90 % der VK-Gerichte mit Score. **Größter Einzel-Hebel.** → **Stand 2026-07-12:** Graph-Dichte-Vorarbeit erledigt (Station 2, Coverage 58 %) → der Lauf liefert jetzt *belastbare* Scores. ABER: der Score ist ein **KI-Judge** (`CoherenceService::judge` via Gemini, 1 Call/Gericht) → **blockiert auf echten Gemini-Provider** (Dev = `FOODALCHEMIST_AI_PROVIDER=fake`). Batch-Command + Real-Lauf im Gemini-Env stehen aus.
- [~] **Rezept-Anker-Reichweite schließen** (heute 60 %): erst „sollte-Anker-haben"-Menge bestimmen (echte Gerichte, nicht triviale Ein-Zutat-Sub-Rezepte), dann Lücke erden → ~100 % der should-have. → **Stand 2026-07-12:** die **molekulare** Route ist ausgereizt (FooDB deckt die recipe-relevanten Reste nicht — Exoten kommen kuratiert übers Buch). Reichweite-Schließen heißt ab jetzt **Anker-Erdung der Zutaten** (Zutat→Anker-Auflösung), nicht mehr FooDB-Mapping.
- [ ] **Mapping-Qualität heben** (heute ~64 % unverifiziert): Verifikations-Ampel „% verifiziert" je Rezept; `gemini_proposed`-Zutaten nutzungspriorisiert auf `manual`/verifiziert heben (Muster Skript 215, §2-Kontext, Review-Gate)
- [ ] **NICHT blanket erweitern:** Kanten-Graph + GP-Erdung sind stark (98 %/gesund) → keine Blanket-Ausweitung; nur gemessene weiße Flecken aus FlavorDB2/Ahn ergänzen (fließt in Q4). Docs (836) bleiben niedrigste Prio.
- [ ] **Priorisierung nach Nutzung × Dünne:** erst Rezepte/GPs, die in vielen VK-Gerichten hängen
- [ ] Nach jedem Lauf: Count vorher/nachher auf `gp_anker_mapping`/`recipe_anker_mapping` (Lehre aus dem Subquery-Unfall)

---

## Bewusste NICHT-Ziele (Erinnerung — Grenze aus GOALS)

Produktion, Einkauf, Lager, Lieferscheine, Rechnungskontrolle: **nicht bauen**, auch nicht „nur ein kleines Feature davon".
FA rechnet, das Event-Modul führt aus. Geschirr: Bedarf hier, Beschaffung dort. Angebots-Führung: Event-Modul, FA liefert zu.

---

## R7 — Operative Planungs-Blätter (FA-seitig) *(die „linke Spalte": Berechnetes gehört FA; Vorstufe zum Nachbar-Modul)*

Reine Kaskaden-Ausgaben — Konzept + Gäste → Mengen/Listen/Blätter. Kein Modul, kein Contract; zugleich die Vorstufe, die N0 de-riskt (der Contract kapselt später genau diese Tools).

### R7.1 Blätter als read-only FA-Tools · Größe M · Hängt an: R1 (+ Darreichungs-Resolver) · 🟢 **GEBAUT 2026-07-13/14 (gepusht; nur echtes Step-Grouping offen — datenmodell-blockiert)**

**Kern-Entscheid Dominique 2026-07-13:** „so wie das Rezept in FA angelegt ist" — VK-Gericht linear auf die Menge skaliert, **Basisrezepte in GANZEN Ansätzen** (nicht runter-fraktioniert; man kocht keinen 20-g-Ansatz), Bedarf über Ziele **vor** der Rundung zusammengeführt. Skalierung frei wählbar: **Personen ODER Portionen** (Default 100). `PlanungsblattService` explodiert den Rezeptbaum über `RecipeRecomputeService::bruttoMasseG` (neuer Public-Helper, T1-Roh-Eingangsmasse) — eine Rechen-Wahrheit, kein Neubau.

**DoD:**
- [~] `produktionsblatt.GET`: Konzept/Gericht + Menge → skalierte Rezepturen über den Rezeptbaum. **Rezept-orientiert** (Top-Gericht + Basisrezepte in ganzen Ansätzen, „benötigt gesamt"-Vermerk) = Übergabe zum Nachbauen/Anlegen. **Zubereitungs-Freitext (`preparation`) jetzt je Rezept ausgegeben.** ⚠ Echtes „gruppiert nach Zubereitungsschritt" bleibt offen — Datenmodell hat keine strukturierten Steps (nur Freitext); bräuchte ein Schritt-Modell. Diese eine Zeile ist der einzige offene R7.1-Punkt.
- [x] `bestellvorschlag.GET`: Bedarf je GP → Lead-LA je Lieferant (`LeadLaService::rangliste`), gruppiert nach Lieferant, mit EK-Summe + **Ausweichquelle** (Rang 2 der Kette; Voll-Substitution = R6.3/R6.8)
- [x] `einkaufsliste.GET`: über mehrere Konzepte / ein Event aggregiert, Mengen zusammengeführt (Merge VOR Ansatz-Rundung = weniger Verschnitt)
- [x] Arbeitszeiten + Regenerations-Parameter je Darreichung: Arbeitszeit (je Rezept × Ansätze) **+ Regenerationstemp/-zeit/Kerntemp + Gerät/Behälter warm+kalt/Vehikel + Arbeitszeit-Zuschlag** der Standard-Darreichung im Produktionsblatt (Vokabel-Namen aufgelöst)
- [x] Strikt read-only, rein rechnend — kein Bestand, keine Bestellung, kein Schreib-Zustand
- [x] PDF/Export je Blatt (DomPDF, `/blaetter/dokument?typ=produktion|bestellung|einkauf&…&pdf=1`, Druck-HTML + istPdf-Flag) — alle drei inkl. Einkaufsliste
- [x] Test: Konzept/Gericht × Menge → Blätter gegen Hand-Rechnung (Skalierung + Ganze-Ansätze-Rundung 1,5→2 + Lead-LA-Gruppierung + Konzept×Pax) + Blätter-Filter — `PlanungsblattServiceTest` (8 Tests) grün, Voll-Suite grün, 0 Regressionen
- **Neu:** UI `/blaetter` (Sidebar „Planung") mit **Blätter-Filter** (Mehrfach-Auswahl Produktion/Bestellung/Einkauf — steuert welche Blätter erzeugt/gezeigt werden, Dominique-Wunsch 2026-07-14), 3 MCP-Tools (`produktionsblatt`/`bestellvorschlag`/`einkaufsliste.GET`, `read_only`) registriert (MCP-Lockstep)

---

## R8 — GP-Kuration FA-nativ *(LA-First ins Produkt holen; WaWi ist eingefroren)*

Die LA-First-GP-Kuration lebte im WaWi (jetzt read-only Archiv). Mit FA als Master muss die Kuration ins Produkt — als bediente UI-Strecke statt Python-Skript.

### R8.1 LA-Multi-Select → Bulk-GP-Erstellung/Matching · Größe L · Hängt an: nichts (FA-nativ)

In der Lieferantenartikel-Liste mehrere LAs markieren → **ein Bulk-Run** legt daraus GPs an bzw. matched sie gegen bestehende (approved) GPs. Bringt den LA-First-Workflow (Items strukturieren → tentative GPs → Review → approved) FA-nativ in die UI.

**DoD:**
- [ ] LA-Browser mit Mehrfach-Auswahl (Checkbox/Range) + Bulk-Aktion „GP erstellen / matchen"
- [ ] Bulk-Run über bestehende Queue-Strecke (`foodalchemist_bulk_runs`/Autopilot, Issue #403) — asynchron, Fortschritt sichtbar, resumefähig
- [ ] Matching gegen **approved** GPs zuerst (nur Neues wird tentative) — spiegelt LA-First-Kernprinzip; Regelwerk_Grundprodukte + Regelwerk_Lieferantenartikel maßgeblich
- [ ] Ergebnis ist **staging/Review-gated**: neue GPs = `status=tentative`/Proposal (`foodalchemist_gp_new_proposals`), kein stilles Anlegen; Mensch gibt frei (analog `gp_proposals.POST`)
- [ ] Lead-LA-Setzung + §8-Pflichtangaben-Prüfung im Lauf (Lead-LA-Heuristik `pick_lead_la`)
- [ ] Confidence + Begründung je Vorschlag (KI-gestützt, Muster Klassifikator 105/`gps.MATCH`)
- [ ] MCP: als Tool aufrufbar (`gps.bulk_match.POST` o. ä., staging-only) — KI-Client kann denselben Lauf triggern
- [ ] Team-Scoping + D1; Test: N markierte LAs → korrekte tentative-GP/Match-Verteilung gegen Hand-Prüfung

### R8.2 Convenience-Highlights (kuratierte Haus-Liste als opt-in KI-Baustein) · Größe M · Hängt an: nichts · ✅ **KOMPLETT 2026-07-19** (Spec [`06`](PLANUNG/06_Convenience_Highlights_GP_Liste.md))

Kuratierte, flache Liste der Convenience-„Lieblinge" auf GP-Ebene — verengt Generatoren **auf Knopfdruck** (opt-in) bewusst auf den Haus-Standard. Gegenläufiger, komplementärer Hebel zum #507-Reuse-Layer (der Vielfalt zeigt).

**DoD:**
- [x] **H1** Datenmodell: `is_favorite` (bool, index) + `favorite_rank` am GP (Migration `2026_07_18_000010`, orthogonal zu `tag_is_convenience`)
- [x] **H2** Kuratierung: `FavoriteGpService` (Auto-Score Nutzung×Lead-LA×Priorität + pin/exclude/reorder, Soft-Regel: nur Convenience-getaggte pinbar) + Kuratierungs-Screen `/convenience-highlights` (Sidebar Stammdaten) + Command `foodalchemist:convenience-highlights` + MCP `favorites.GET/PUT` (Lockstep)
- [x] **H3** Generierungs-Modus: `use_favorites_list` in `GenerationContextService` (Rezept/VK) + `ConceptGeneratorService` (Brief-Pfad) — separater Prompt-Block „bevorzugte Convenience-Bausteine" (bevorzugt, nicht ausschließlich); **Default AUS = byte-identisch** (Leit-Invariante, Regressions-getestet)
- [x] **H4** UI: Checkbox „⭐ Auf Basis meiner Convenience-Liste bauen" an Rezept-/VK-/Konzept-Generator + GP-Picker-Filter „⭐ Convenience" (browseKatalog)
- [x] 14 Pest (Service/Command/MCP/Screen/Generierung/Picker); Voll-Suite grün, 0 Regressionen
- Nicht-Ziele v1 (bewusst): keine Caterer-Overrides (global-only), kein „ausschließlich", kein Chips-Panel, kein Swap-am-Ergebnis, keine LA-Ebene

**Update 2026-07-20 — verallgemeinert Convenience-Highlights → Favoriten (Lieblings-GPs):** Auf Wunsch (Use-Case bleibt Convenience, aber es gibt auch andere Lieblings-GPs) ist der Pool jetzt allgemein: **jeder approved GP pinbar**, die §4-Convenience-Pflicht fällt. Kompletter Rename `is_convenience_highlight`→`is_favorite`, `highlight_rank`→`favorite_rank`, `ConvenienceHighlightService`→`FavoriteGpService`, Route `/favoriten` (`foodalchemist.favorites.index`, Alt-Route → Redirect), Command `foodalchemist:favorites`, MCP `favorites.GET/PUT`, Flag `use_favorites_list`. Convenience-Verengung bleibt als Sub-Option `favorites_convenience_only` (nutzt `tag_is_convenience`) + `Conv`-Badge im Screen. Rename-Migration `2026_07_20_000010` (idempotent). **H4b:** Pin direkt im GP-Editor (`GpModal::favoriteToggle`, kein §4-Gate) + GP-Name im Screen = Editor-Deeplink (`?gp=&edit=1` → `Browser::editOeffnen`). 37 Pest der betroffenen Suiten grün, Voll-Suite 882/883 (+1 skip), 0 Regressionen.
- **Fix 2026-07-20 (Screen zeigte ALLE 6951 GPs statt Rangliste):** `FavoriteGpService::suggest` hatte durch den Rename-Umbau einen **early `return` vor dem Score-Cap** — Sortierung + `$limit`-Cap lagen als toter Code dahinter → Pool ungekappt + unsortiert. Behoben (Cap greift, gepinnte immer dabei, Score-desc). Regressions-Guards ergänzt (Cap-Count + Sortier-Reihenfolge), die der alte 1-Element-Test nicht fing. **Offen (§10):** `suggest` zieht weiterhin den ganzen approved-Bestand per 3 Subqueries in PHP → auf großem Bestand (demo ~7k) DB-seitig kappen/kalibrieren.

## R9 — Lieferanten-Management *(kommerzielle Beziehungs-Ebene — heute nicht steuerbar; Dominique-Wunsch 2026-07-05)*

**Ziel (Dominique):** Die Beziehung zu einem Lieferanten aktiv **steuern** — Verträge, Konditionen, Absprachen, Zusagen, wer wofür Lead ist. Heute passiert das mündlich/verstreut und ist im System nicht führbar. Die **Lead-Lieferanten-Zuordnung** (`lead_la`, `pick_lead_la`) ist bereits ein kleiner Teil davon — R9 macht daraus eine bediente, nachvollziehbare Steuerungs-Strecke.

**Vorhandener Kern (Startpunkt, kein Neubau):** `lead_la_supplier_item_id` + `pick_lead_la`-Heuristik (Lead je GP), `supplier_priorities` (Umsatz-Ranking, Import Skript 92 aus Rückvergütungs-Forecast), `stamm_lieferant` + `stamm_lieferant_wg` (Lieferant×Warengruppe-Matrix). → R9 bündelt und bedient das, statt es in Skripten/Heuristik zu lassen.

> ⚠️ **Scope-Grenze (vor Baustart entscheiden — Sparring):** R9 ist die **kommerzielle/strategische Beziehungs-Ebene** (Konditionen, Verträge, Absprachen, Lead-Zuordnung, Volumen-Auswertung) — **NICHT** operatives Bestellen/Wareneingang/Lieferscheine/Rechnungskontrolle. Letzteres bleibt bewusstes NICHT-Ziel (s. o.) bzw. Nachbar-Modul (N-Track). Die Linie: **R9 pflegt „mit wem zu welchen Bedingungen", der N-Track/Event-Modul führt „was wann bestellt" aus.** Diese Abgrenzung ist der erste zu klärende Punkt.

### R9.1 Lieferanten-Stammblatt + Absprachen-Log · Größe L · Hängt an: nichts (FA-nativ) · ✅ KOMPLETT 2026-07-19 (Engine+MCP+UI)

**DoD:**
- [x] Lieferanten-Detailseite: Kontakte, Rollen, Status, WG-Abdeckung — **Aggregat (`SupplierService::stammblatt`) + MCP + getabtes `SupplierDetail`-Modal (`Suppliers/Index` „Beziehung"-Button)**
- [x] **Absprachen-/Zusagen-Log** je Lieferant: datierte Einträge, Wiedervorlage (`follow_up_at`) + Autor (`SupplierAgreementService`, Tabelle `foodalchemist_supplier_agreements`)
- [x] **Vertrags-/Dokumenten-Ablage** je Lieferant mit Laufzeit + Kündigungsfrist → Fristen-Signal `VertragsfristFaellig` (Detektor in `laufen()`; v1 Metadaten + File-Ref, S3-Upload später)
- [x] Konditionen strukturiert: Rückvergütung/Bonus %, Zahlungsziel, Mindestbestellwert, Frei-Haus-Grenze (Spalten auf `suppliers`, geteilt mit Q2/[13])
- [x] Team-scoped, LogsActivity, MCP `suppliers.GET`/`suppliers.PUT` + `supplier_agreements.POST` (D1-Schreibgate: nur eigene Lieferanten)

> **v1-Note:** 4 Migrationen (Status+Konditionen auf `suppliers`, `supplier_contacts`, `supplier_agreements`, `supplier_documents`) + 3 Models + `SupplierStatus`-Enum + `SupplierService`-Erweiterung (setStatus/updateConditions/addContact/stammblatt) + `SupplierAgreementService` + `SignalTyp::VertragsfristFaellig`+Detektor + 3 MCP-Tools + `SupplierRelationTest` (3 Pest). **UI-Slice 2026-07-19:** getabtes `SupplierDetail`-Modal (Stammblatt/Konditionen/Absprachen/Dokumente/Bündelung; lesen=Kette, schreiben=D1-gated) + `SupplierDetailUiTest` (5 Pest). Volle FA-Suite 807/808 grün (1 skip).

### R9.2 Lead-Lieferant-Steuerung als bediente Strecke · Größe M · Hängt an: R9.1 + R1 · ✅ KOMPLETT 2026-07-19 (Engine+MCP+UI)

**DoD:**
- [x] Lead-LA je GP **sichtbar + überschreibbar** — `LeadLaService::leadSteuerung` + `setLeadLa(reason)` + MCP `gp_lead.GET/PUT` + **`Gps/DetailPanel`-Lead-Override mit Begründung (`leadReason`)**
- [x] Vorschlag = `pickLeadLa`; Mensch übersteuert per `gp_lead.PUT`, Entscheid protokolliert (Begründung auf `gp_la_preferences.reason` → LogsActivity-Historie)
- [x] Zweit-/Ausweichquelle je GP (Rangliste ab Rang 2, `leadSteuerung.ausweichquellen`)
- [x] Auswertung: Volumen (**Nutzungs-Proxy** via Lead-LA, EHRLICH markiert) je Lieferant × Konditionen → „wo lohnt Bündelung" (`SupplierService::volumenProxyRanking` + MCP `suppliers.VOLUME`)
- [x] Test: `LeadSteuerungTest` (3 Pest) — Override setzt Lead korrekt (+reason), Recompute nutzt neuen Lead-EK (1,00→2,00 €), Historie über Pref-Zeile

> **v1-Note:** Volumen = Nutzungs-Proxy (kein echtes Spend/Umsatz — fehlt im Modul, `supplier_priorities` existiert nicht; E6). Echtes Spend erst mit Q2-Einkaufsdaten. UI-Überschreiben = Folge-Slice (Engine+MCP stehen). **Damit ist Spec 14 (R9) engine-/MCP-seitig komplett.**

---

## Ausblick-Track — Nachbar-Modul (Einkauf/Lager/Produktion/Event) *(außerhalb des kritischen FA-Pfads; eigenes Package, eigene Roadmap)*

Kein FA-Paket — hier nur die Andock-Bedingungen, damit FA-seitig heute nichts verbaut wird. Details → GOALS „Ausblick: Nachbar-Module". FA baut/ändert dieses Modul NICHT; es ist ein Geschwister-Modul, das FA über Core-Contracts konsumiert.

### N0 Core-Contract fixieren (= Q1) · Größe S · Hängt an: nichts · **Gate für alles Weitere**

Identisch mit Q1. Ohne entschiedenen Contract kein Modul-Code — sonst Model-Durchgriff und die Grenze ist Makulatur. **De-riskt durch R7:** die Ausgaben existieren dann schon als FA-Tools — N0 kapselt sie nur als `Platform\Core\Contracts`-Interface, erfindet nichts.

### N1 Modul-Gerüst + Contract-Konsument · Größe L · Hängt an: N0

**DoD (Skizze, wird eigene Roadmap):**
- [ ] `platforms-<produktion|event>` aus `module-template` erzeugt, im Dev-Modul als eigenes Package registriert
- [ ] Verbraucht den FA-Contract: Konzept + Gästezahl → skalierte Komponenten-Mengen + Lead-LA-Bestellvorschlag (nur lesend gegen FA, kein Model-Durchgriff)
- [ ] Kein eigener kulinarischer Rechenkern — jede Küchen-/Preis-Frage geht an FA
- [ ] Grenze dokumentiert: Ausführung (Bestellung/Lager/Belege) hier, Rechnen bei FA

### N2 Bidirektional: Überschuss-Rückkanal · Größe M · Hängt an: N1 + R6.10

**DoD (Skizze):**
- [ ] Nachbar-Modul meldet Überschuss-Bestand über den Contract → FA (R6.10) liefert Verwertungs-Gericht
- [ ] Erster produktiver Beweis des Contracts in BEIDE Richtungen

---

## Ausblick-Track — Academy als Wissens-Konsument (Training/R&D-Frontend) *(außerhalb des kritischen FA-Pfads; Modul Academy konsumiert FA)*

Gleiches Muster wie der N-Track: FA liefert den **Warum-Motor** (`knowledge.EXPLAIN` + Q4-Evidenz), das **Academy-Modul** (existiert auf office.bhgdigital.de, Lernpfad-Infra da) baut daraus Training. FA baut KEIN Training-Frontend.

### A1 Portfolio-Training · Größe L · Hängt an: R6-Warum-Layer + Q4

**DoD (Skizze, wird eigene Roadmap):**
- [ ] Micro-Lessons aus dem *eigenen* Bestand („warum funktioniert euer Renner") — personalisiert, zitiert (Evidenz-Stufe sichtbar)
- [ ] Konsumiert `knowledge.EXPLAIN` von FA — kein eigener Wissens-Motor im Academy-Modul
- [ ] Reduziert Key-Person-Risiko: tacit chef knowledge → explizit + abfragbar

### A2 Skill-Check / Quiz · Größe M · Hängt an: A1

**DoD (Skizze):**
- [ ] Gericht zeigen → „was trägt hier das Aroma?" → gegen den Graph benotet
- [ ] Onboarding-Pfad neue Küchenkräfte (Academy-Lernpfad-Infrastruktur nutzen)

---

## Meilenstein-Übersicht

| Meilenstein | Inhalt | Beweis („Demo-Satz") |
|---|---|---|
| **M-A: Live & vertrauenswürdig** | R0 komplett | „Ein externer LLM-Client legt auf demo ein Foodbook mit Darreichungspreisen an; alle Ampeln grün." |
| **M-B: Masse drin** | R1 komplett | „~1.000 VK-Gerichte mit Formen, Preisen, Allergenen — in WaWi und FA identisch." |
| **M-C: Unverzichtbar** | R2.1 + R2.2 | „Butterpreis +20 % → das System sagt in Sekunden, welche 87 Gerichte es trifft und was der Tausch spart." |
| **M-D: Portfolio lebt** | R3 komplett | „Der Caterer blättert im Web-Foodbook, filtert vegan+Herbst+Buffet — Preise live, Kunde sieht dieselbe Seite ohne Interna." |
| **M-E: Geführt statt gefühlt** | R4 komplett | „Das Foodbook meldet beim Befüllen selbst: HG vegan fehlt, Preisspanne gerissen, Herbst leer." |
| **M-F: Compliance auf Knopfdruck** | R5 komplett | „LMIV-Etikett, CO₂e und HACCP je Konzept — generiert, nicht gebastelt." |
| **M-G: Alleinstellung** | R6.1 + R6.2 | „Kunden-Mail rein → strukturiertes Brief → Konzept aus echten Gerichten mit Kohäsions-Beweis, gemessen an der Kunden-Messlatte." |
| **M-H: Aroma-Offense** | R6.8 + R6.9 | „Butter wird knapp → das System schlägt den aroma-treuen Ersatz vor, der die Menüfolge nicht bricht; ein Trend-Gericht wird aus unserem Bestand nachgebaut." |
| **M-O: Operativ anschlussfähig** | R7 | „Konzept + 120 Gäste → Einkaufsliste, Bestellvorschlag je Lieferant und Produktionsblatt fallen hinten raus — noch ohne Bestands-Modul, rein gerechnet." |
| **M-N: Contract lebt** | N1 (+ R6.10/N2) | „Das Event-/Produktions-Modul fragt FA: 120 Gäste → skalierte Mengen + Bestellvorschlag; Überschuss zurück → Verwertungs-Gericht. FA rechnet, das Nachbar-Modul führt aus." |
| **M-W: Wissen erklärt sich** | R6-Warum-Layer + Q4 (+ R6.11) | „Jeder Vorschlag kommt mit zitierter Begründung und Evidenz-Stufe; wo die Datenlage dünn ist, sagt das System es — und legt die Lücke in die Research-Queue." |

---

## Changelog

- 2026-07-20 (Spec 16 · Nachzügler): **Compound-Anti-Marker (S3) + WG-Hint im KI-Rezept-Schema.** (A) `TerminologyService::isAntiMarker` matcht Regel-Tokens jetzt am **Kompositum-Rand** (Präfix/Suffix, wenn Rest-Morphem ≥ 3 Zeichen) → „Brie" fängt jetzt auch „Kalbsbries" (Rest „kalbs"), ohne den legitimen „Bries"/„Bries"-Selbsttreffer zu blocken (Rest „s" < 3) und ohne die „tamarinde"↛„rind"-Interieur-Falle zu öffnen. Schließt die im Finder-Bau offengelassene S3-Lücke. (B) `recipe.generator`-Prompt liefert optional `commodity_group` je Zutat (WG-Code der Hauptzutat, 15er-§3-Liste inline); Generator normalisiert via `wgHint()` auf 2-stellig („01 Gemüse"→„01") → `mintFromLa`-WG-Scope; falscher/fehlender Code fällt sicher global zurück (Upside-only). +2 Pest (Kalbsbries-Compound + GeneratorWgHint), **volle Matching-Blast-Radius-Suite grün** (Golden/Terminology/Matcher-Eval/MatchService/SemanticGolden/Generator/MCP), 0 Regression. **Provider-gated (Key auf demo, Sandbox=fake):** Live-Smoke der LLM-`commodity_group`-Emission (B) + ClassifyLaJob-Inhalt (S4) erst nach demo-Deploy.
- 2026-07-20 (Spec 16 · S1–S5): **WG-Lead-gescopter LA-Kandidaten-Finder GEBAUT — `mintFromLa` schärft statt naiv `->items()[0]`.** Antwort auf den Use Case „wenn kein GP existiert, aber das Rezept die Zutat braucht, den passenden Artikel unter den WG-Leads finden". **Kein Qdrant/Vektor-Pool** (Spec 15 entkoppelt): der WG-Lead-Scope (`preferred_suppliers`) verengt den 264k-LA-Katalog auf wenige tausend → deterministisches Lexik-/Terminologie-Matching (Weg-2-Stack) reicht. Neu: `LaCandidateFinder::find/best` (Alias-/Decompound-erweiterter Such-Prefilter über `searchGlobal` je Phrase + Best-über-Varianten-Score aus `TokenEngine::matchScore` + `substringOverlap`, Anti-Marker-Filter, Ranking Score→Lead-Priorität→Preis) + `supplier_ids`-whereIn in `SupplierItemService::baseQuery` (1 Statement) + `wgHint`-Param in `mintFromLa` (aus Generator-Kontext durchgereicht, E1) + Fallback-Kaskade (E2: WG-Lead leer → global). **S1-Scope-Resolver war bereits da** (`StammLieferantService::stammSupplierIdsFor`, WG+global-NULL-Merge) → Reuse, kein Neubau. **S4 ClassifyLaJob** (async, idempotent, `gp.suggest`-Reuse = WaWi-105-Spiegel) nach dem Mint dispatched, nie inline — Lernschleife für die 93 % unklassifizierten Lead-Kataloge; LLM-Inhalts-Ableitung provider-gated (nur Dispatch/Guards getestet). **S5 MCP-Lockstep:** `gps.MINT_FROM_LA` + `gps.MATCH` um `commodity_group`-Hint + transparente Response (gewählter LA, Scope, Score). Anti-Marker Brie↛Bries greift auf Token-Ebene (Compound „kalbsbries" = bekannte S3-Decompounding-Lücke). **14 neue Pest + 29 Regression grün** (Generator/MCP/Match/Lead), 0 Regression. Doku: `docs/PLANUNG/16_LA_Candidate_Finder.md`.
- 2026-07-19 (#533/#507 Backfill-Fix): **Batch-Chunking im PoolEmbeddingService — OpenAI-300k-Token-Limit.** Der demo-Backfill (`--pool=all`) crashte mit HTTP 400 „maximum request size is 300000 tokens", weil `storeByTeam` alle Einträge einer Partition in EINEM Request schickte — mit der §5b-Rezept-Prosa sprengten 3204 Rezepte das Limit. Fix: `chunkByBudget` splittet vor `embedAndStoreBatch` nach Zeichen-Budget (500k ≈ 125–165k Tokens, sicher) + Item-Cap 1000 (Core bleibt unangetastet — Fremdmodul-Regel). +1 Test, **Suite 859/860 grün**. Danach demo re-deploy + `foodalchemist:embed --pool=all` erneut.
- 2026-07-19 (#507 §5b Embed-Tiefe): **Rezept-Embed-Text um Zubereitung/Beschreibung/Geschmacksrichtung erweitert (gedeckelt).** `recipeEmbedText` hängt jetzt Preparation + Description (je max. 220 Z.) + taste_direction an Name/Kategorie/Top-Zutaten → „finde Gericht mit Technik/Verfahren X" wird freitext-auffindbar (vorher nur Namen indiziert). Moderat gedeckelt, damit Name/Zutaten prominent bleiben (Zutat→Sub-Präzision). `embedRecipes`-Select + `queueRecipe` + RecipeEmbeddingObserver-RELEVANT um die 3 Felder erweitert (Prosa-Edits re-embedden). GPs unberührt (haben keine Prosa-Spalten). +2 Tests, **volle Suite 858/859 grün**. ⚠ Ändert ALLE Rezept-Embed-Hashes → demo braucht Voll-Re-Embed (`foodalchemist:embed --pool=recipes` bzw. `all`) + Floor-Neueichung. Semantik ist additiver Shortlist (Floor terminologie-getrieben) → Präzision gedeckt.
- 2026-07-19 (#507 E7-c): **Terminologie-Lernschleife an der ReviewQueue — der Loop für neue Namen ist zu.** Kurator lehrt beim Review Alias-Gruppen (Synonyme/Dialekt) + Anti-Marker (Verwechslungen) direkt im UI („🧠 Terminologie lernen"-Karte, Livewire-Actions `terminologieAlias`/`terminologieAntiMarker`), die via `TerminologyService::createAlias`/`createAntiMarker` (EINE Regel-Stelle, auch von MCP `terminology.POST` genutzt) in den globalen Master schreiben und **sofort** ins Matching einfließen (kein Deploy). Damit ist der Trichter komplett: neuer Name → Match (Terminologie+Semantik) → tentative GP (LA-First) → Kurator lehrt → wirkt sofort. +3 Tests (Livewire), **volle Suite 856/857 grün**, null Regression. Offen E7: nur noch E7-d Vault-Export (Weg bewusst SPÄTER, FA=Master). Dann Embed-Text-Tiefe (§5b) + demo-Migration/Backfill.
- 2026-07-19 (#507 E7-b): **TerminologyService → runtime-pflegbare DB-Tabellen + MCP (Promotion).** Zwei additive Tabellen `foodalchemist_terminology_aliases` (members json) + `foodalchemist_terminology_anti_markers` (trigger/forbid/unless), team_id NULL = globaler Master (Governance FA=Master). Konstanten bleiben **Baseline-Seed im Code**; DB wird additiv drübergemergt (`aliasGroups()`/`antiMarkerRules()`, request-gecacht, graceful ohne Tabelle → nur Konstanten). Neue MCP-Tools `terminology.LIST` (read) + `terminology.POST` (write alias|anti_marker, schreibt global, wirkt sofort ohne Deploy) — die runtime-Senke der E7-c-Lernschleife. Bewiesen: DB-Eintrag fließt bis in die matchIngredient-ENTSCHEIDUNG (Savoy→Wirsing über DB-Alias). +5 Tests, **volle Suite 853/854 grün**, null Regression (84 Goldens unberührt — Konstanten-Baseline steht). Offen: E7-c ReviewQueue-Lernschleife (3-Aktion), E7-d Vault-Export (Weg später), demo-Migration+Backfill.
- 2026-07-19 (#533 Spec 15 + #507 E7-a): **Semantische Abdeckung erweitert (4 kleine Pools) + S1-Alias in der matchIngredient-Entscheidung.** (1) **4 Geschwister-Pools** (Lieferant/Konzept/Foodbook/Lab-Note) in `PoolEmbeddingService` (ENTITY_TYPE_* + embed/queue/delete/embedText, generische Helfer) + 4 Observer (registriert) + `foodalchemist:embed --pool=suppliers|concepts|foodbooks|lab_notes` — LA-Pool bleibt bewusst draußen bis Store/Qdrant-Entscheid (Spec 15 §5c). (2) **Consumption:** `SupplierService::listWithCounts` zieht semantische Lieferanten-Treffer additiv nach (behebt „Lieferant nicht gefunden", Tenancy/Aktiv außen = kein Leak) + `ConceptsSearchTool` hybridisiert + neue MCP-Tools `suppliers.SEARCH`/`foodbooks.SEARCH`/`lab_notes.SEARCH` (E4-via-Marker). (3) **E7-a:** S1-Alias jetzt auch in der matchIngredient-ENTSCHEIDUNG (unter Schwelle je `aliasPhrasesFor`-Phrase, Max) → „Paradeiser"→Tomate/„Beef"→Rindfleisch im Urteil; additiv, 84 Goldens byte-identisch. **Prod-Bug gefangen:** concepts/foodbooks/lab_notes haben keine `is_inactive`-Spalte (SQLite-Doppelquote-Fallback maskierte es) → is_inactive-Gate nur bei suppliers. Arch-Entscheidung: UI-Browser bleiben lexikalisch, Semantik lebt in MCP-SEARCH (Lieferanten-UI = begründete Ausnahme). +15 Tests, **volle Suite 848/849 grün**, null Regression. Offen: E7-b TerminologyService→DB+MCP, E7-c ReviewQueue-Lernschleife, Embed-Tiefe, demo-Backfill der neuen Pools. Memory `project_fa_507_semantic_search.md`.
- 2026-07-19 (#507 Weg-2 S3 + LIVE): **RAG scharfgestellt auf demo + S3 Decompounding.** Nach S1/S2-Messung (det. 50% / hybrid 66% / 0 Leaks) Flag scharf (`FOODALCHEMIST_SEMANTIC_SEARCH=true`, floor 0.55), Smoke live bestanden (Brie↛Bries, Möhre→Karotten, Semantik feuert). **S3 Decompounding** (`TerminologyService::decompoundPhrasesFor`): Compound-Query → [Modifier, Kopf] über kuratierte §1-Köpfe (püree/jus/sugo/sauce/fond/…) inkl. Fugen-s/-n/-en-Varianten, gemerged in denselben Best-über-Varianten-Scoring-Pfad wie S1-Aliase (Kürbispüree→„kürbis püree" ⇒ GP „Püree: Kürbis"; Kalbsjus→„kalb jus"). Falscher Split matcht nichts (Max-Verfahren). 2 Tests, 832/833 grün. Offen: matchIngredient-Angleich, Freitext-SEARCH-Go, TerminologyService→DB+MCP, #533-Pools.
- 2026-07-19 (#507 Weg-2 S1+S2): **Deterministische Terminologie-Schicht — der ehrliche Fix nach der gescheiterten Slice-1-Eichung.** Diagnose der Golden-Set-Fehler: die Mehrheit ist gar nicht semantisch, sondern (a) Dialekt-/Übersetzungs-Synonyme (Paradeiser→Tomate, Beef→Rindfleisch) = Wörterbuch-Job, (b) lexikalische Verwechslungs-Fallen (Brie↔Bries) = harte-Negativliste-Job. Embeddings sind dafür das falsche Werkzeug (Band-Stauchung auf 3-large). **Fix nutzt bereits kuratiertes Vault-Wissen** (`Substitutionen.md` + `Anti_Marker.md`), statt es per Blackbox-Floor zu erhoffen:
  - **`TerminologyService`** (neu): `aliasTokensFor` (S1, Alias-Gruppen DE/AT/EN, Token-Grenzen gegen Falsch-Trigger à la „Tamarinde"⊃„rind") + `isAntiMarker` (S2, gerichtete Trigger→Forbid-Regeln mit Guard). Provenienz dokumentiert (Golden-Set ← Vault Cross_Cutting). Kuratierte PHP-Konstanten (DB-Promotion = späterer Slice).
  - **`IngredientMatchService::candidatesFor`**: S1 expandiert Query-Tokens additiv (Prefilter+Scoring), S2 `stripAntiMarkers` filtert Fallen aus BEIDER Pfade Shortlist (lexikalisch + semantik-injiziert) — **unabhängig vom Score. Das entsperrt das Flag-Scharfstellen: Leaks werden deterministisch gekillt, nicht per Floor gehofft.** `matchIngredient`-Entscheidung bewusst noch unangetastet (84 Goldens), Folge-Inkrement.
  - **`MatcherEvalCommand`** (neu, `foodalchemist:matcher-eval --team --semantic`): misst die VOLLE Shortlist (was embed-eval nicht kann) — Recall@K je Klasse + Anti-Leaks, Zeile „deterministisch" (Flag AUS) + optional „hybrid" (Flag AN).
  - 7 neue Tests (5 Terminology inkl. 8 Golden-Negative + Alias + Tamarinde-Guard; 2 MatcherEval-Auswertung), **830/831 Suite grün, null Regression.** Nächster Schritt online: deploy → `matcher-eval --team=6 --semantic` → Erwartung Anti-Leaks 0 + Regional/Übersetzung hoch → dann Flag scharf (Dominique-Go). Folge: S3 Decompounding, S4 Freitext-Suche-Go. Memory `project_fa_507_semantic_search.md`.
- 2026-07-19 (#507 B2/Slice-1): **E5-Floor-Eichung auf demo AUSGEFÜHRT → kein brauchbares Fenster → Embed-Text-Entrauschung (Slice 1).** Backfill live (12.885 Vektoren, OpenAI text-embedding-3-large 3072d, team 6); `embed-eval --team=6` detached gelaufen. **Befund:** kein Floor trennt echte Treffer von Anti-Markern — bei 0.40 Recall@15 53 % *aber* 4/8 Anti-Marker leaken (Brie↔Bries), bei 0.70 (0 Verletzungen) nur 3 % Recall; selbst `Aubergine→Aubergine` fällt bei 0.70. **Wurzel:** Embed-Text-Asymmetrie — rohe Query („Aubergine") vs. strukturiertes Ziel („Aubergine · frisch · 13"), Ähnlichkeitsband auf 3-large gestaucht, Anti-Marker im selben Band. **Fix (Slice 1, Flag bleibt AUS):** (a) `PoolEmbeddingService::normalizeForEmbedding` — symmetrischer Normalizer (Struktur-Separatoren `·,;:/|`→Space, Whitespace-Collapse) auf **Ziel-Embed-Text UND Suchquery** (`SemanticRetrievalService`); (b) Warengruppen-**Code** raus aus `gpEmbedText` (semantisches Rauschen); (c) Zustand nicht dupliziert, wenn schon im §6-Namen. `recipeEmbedText` ebenfalls normalisiert. **Bewusst noch KEINE Embed-Tiefe** (Prep/Description) — eine Variable, dann neu eichen. 822/823 Suite grün (+1 Symmetrie-Test), null Regression. **Offen (online):** re-deploy → `embed --pool=all` (alle Hashes geändert → Vollre-embed) → `embed-eval` neu → Floor entscheiden → B3 (Flag, Dominique-Go). Wenn Fenster weiter zu → Slice 1b (Zustand raus / §5b-Tiefe). Memory `project_fa_507_semantic_search.md`.
- 2026-07-16 (#507 E0–E4 + #508): **RAG-/Semantik-Retrieval-Layer lokal gebaut (provider-agnostisch).** Kern von #507 — die fehlende Retrieval-Hälfte über die GP-/Rezept-Pools; Augmentation stand bereits.
  - **E1** `PoolEmbeddingService`: GP-Pool (approved/tentative/review, ¬platzhalter/¬merged) + Basis/VK-Rezepte embedden (Embed-Text §6-Name·Hauptzutat·Zustand·WG bzw. Name(Kat):Top-Zutaten; metadata.is_sales_recipe). Command `foodalchemist:embed --pool=gps|recipes|knowledge|all`, 2 Observer (inkrementell, created/updated-Gate, delete bei merge), Team-Partition NULL→Sentinel, source_hash-idempotent.
  - **E2** `SemanticRetrievalService`: V-04-Port (GL-04 §6.1) — Query EINMAL embedden → Vektor-Suche je Partition (Ahnenkette ∪ Sentinel, Entscheid B modulseitig) → Merge. **Additiv** in `IngredientMatchService::candidatesFor`: Flag AUS = byte-identischer Legacy-Pfad (84 Goldens + candidatesFor-Golden unverändert); Flag AN = Hybrid-Re-Rank (both=max(lex,cos)·semantic=cos·lexical-only=lex×0.5) + `origin`-Marker. Config `pool_sem_floor` 0.55 (⚠ Gemini-geeicht → für OpenAI E5 neu eichen), `pool_lexical_floor` 0.40, `pool_cap` 15.
  - **E3 (#508)** Re-Grounding zentral in `RecipeService::syncIngredients`: KI-Zutat ohne gp/sub läuft durch den Resolver (gp→gp_v2_fk, sub→recipe_ref+Zyklus-Check) statt als `unmatched` zu landen; Bestands-`unmatched`-Rehab beim Re-Sync; explizites gp_id unangetastet. Profitiert Revise/MCP-Put/Generator automatisch. **Überarbeiten-Vorschau-Hard-Stop** (`RecipeModal::matchVorschau` + Blade-Badge matched/grounded/hardstop, Generator-Parität).
  - **E4** `gps.SEARCH` + `recipes.SEARCH` (basis-gefiltert) + `knowledge.SEARCH` hybridisiert (`FoodAlchemistTool::semanticPoolIds` + `KnowledgeContextService::searchDocuments`-Ergänzung, `via: lexical|semantic`-Marker, Tool-Beschreibungen aktualisiert, graceful).
  - **E0** Golden-Set-Fixture `tests/Fixtures/SemanticGoldenSet.php` (44 Fälle: translation/synonym/regional/compound + 8 Anti-Marker aus Anti_Marker.md) + Wohlgeformtheits-Test — Pflicht-Gate für die E5-Recall@15-Eichung.
  - **E5-Harness** `foodalchemist:embed-eval --team= --k=15 --floors=…`: fährt das Golden-Set gegen den echten Embedder + die embeddeten Pools, misst Recall@K je Fallklasse + Anti-Marker-Gegenprobe (Token-Subset-Match, „Brie"⊄„Bries") und schlägt den Floor-mit-0-Verletzungen + max Recall vor. Reine Auswertungslogik provider-los getestet.
  - **29 neue Tests, volle Suite grün, null Regression** (deterministische Match-Entscheidung + 84 Goldens byte-identisch). **Offen (online):** E5-Eichung *ausführen* (nach Backfill, braucht OpenAI-Key via Core-Contract) → dann `pool_sem_floor` setzen; E6-Deploy via `demo.bhgdigital.de/update.sh` (Push → Server-Auto-Deploy), danach serverseitig `foodalchemist:embed --pool=all` + Flag `FOODALCHEMIST_SEMANTIC_SEARCH=true`. Memory `project_fa_507_semantic_search.md`.
- 2026-07-16 (#511 + #509 gefixt): **Rezept-Editor-Strecke — Tausch-Kaskade sichtbar gemacht + Basisrezept-Create zum Voll-Writer.** Eine Session, F1/F2/F4/F5.
  - **#511 (b) Live-Refresh:** Repro-Test belegt zuerst die Server-These — ein Sub-Tausch propagiert den EK server-seitig sauber bis zum Eltern-Gericht (`recomputeAndPropagate`, topologisch). Bruch war das fehlende UI-Signal: `IngredientEditor::speichern` dispatcht jetzt zusätzlich `kosten-aktualisiert` mit `recipe_id` + den betroffenen Eltern-IDs. `recomputeAndPropagate(): array` gibt die betroffene Menge (Kind + transitive Eltern) zurück (neuer Helfer `betroffeneRezepte()`); alle Bestands-Caller ignorieren den Rückgabewert → abwärtskompatibel. `Recipes/DetailPanel` bekommt einen `recipe-gespeichert`-Re-Render-Hook, damit der Rezept-Kopf (EK/Yield/Allergene) auch im embedded Editor-Kontext frisch wird (wo `zeige()`/recipe-selected bewusst früh aussteigt). `Kalkulation/Index` hört bereits auf `kosten-aktualisiert` → jetzt auch nach Zutaten-Save bedient.
  - **#511 (a) Warnung:** unbepreiste Zutat (GP/Sub ohne auflösenden Preis) zeigt im Editor einen amber `⚠︎` je Zeile statt des stillen grauen „—" + eine Σ-Zeile „n von m Zutaten bepreist — EK unvollständig". Greift live beim ⇄/♻-Tausch (setzen `ek_pro_g=null`). Daten-Heilung selbst bleibt R0.3-Etappe-2 (Sourcing), kein Editor-Fix kann sie ersetzen — nur sichtbar machen.
  - **#509 Create-Parität:** `RecipeService::create` schreibt jetzt dieselben §4.2-Fachfelder wie `update()` (temperature/function/preparation/notes_manual/yield_pieces + Equipment-Sync) — Schluss mit dem stillen Datenverlust im Anlege-Modal. `RecipeModal::speichern` springt nach dem Anlegen nahtlos in den Edit-Modus (`ladeRezept($id)`, VkModal::anlegen-Muster) statt zu schließen → Zutaten/Deklaration/Darreichungen sofort befüllbar.
  - **Tests:** neu `IngredientSwapPropagationTest` (Server-Propagation, ID-Rückgabe, Event-Dispatch, F2-Warnung, F4 E2E durch den Livewire-Editor inkl. Eltern-EK ohne Reload) + `RecipeCreateParityTest` (Feld-Parität + Edit-Sprung). Kein Server-Recompute-Verhalten geändert (I8 Logging bleibt, I9 `vk_*` nie geschrieben).
- 2026-07-19 (R6.11 · S4): **Kontrast-Hypothesen GEBAUT** (aus User-Frage „werden die Kanten auch offensiv genutzt oder nur die Aromen?"). Zweiter offensiver Zug: Paarung über SPANNUNG statt Verwandtschaft — was die nicht-negativen Aroma-Vektoren prinzipiell nicht finden. `PairingService::contrastHypothesesFor(gp/anchor, limit)`: (1) kuratierte `kontrast`-Kanten offensiv (T0); (2) generativ über den 7-Achsen-Geschmacks-Vektor entlang kulinarischer Gegensatz-Paare (`GESCHMACK_GEGENSATZ`: Fett↔Säure, Süß↔Bitter/Schärfe/Salz, Umami↔Säure — Buch-Kontrast-Layer, keine Erfindung), `contrastScore` (Harmonie→0). MCP `knowledge.HYPOTHESIZE` um `mode=harmonie|kontrast` erweitert. `ContrastHypothesisTest` (4 Pest). Realdaten-Smoke: sumach → sardellenpaste/butterschmalz/bauchspeck (Säure gegen Fett/Umami). Damit S1–S4 durch (nur optionales KI-Narrativ offen).
- 2026-07-19 (R6.11 · S1–S3): **Hypothesen- & Widerspruchs-Modus GEBAUT (Pairing-Offense, R&D) — Spec 11 durch (bis auf optionales KI-Narrativ).** S1 Hypothesen-Modus: `PairingService::sharedCompoundClasses(a,b)` + `hypothesizeFor(gp/anchor, limit)` (Ranking nach geteilten Compound-Klassen, Mechanismus-Text, Evidenz-Tier **T3**, Novität-Flag `ist_etabliert`, graceful Aroma-Cosinus-Fallback) + MCP `knowledge.HYPOTHESIZE`. S2 Widerspruchs-Detektor: `SignalDetektorService::widerspruchWissenGraph` (pairing-Doc-Partner vs. `pairing_anchor_edges` Präsenz/Absenz → `SignalTyp::WiderspruchWissenGraph`, Info, dedup je Doc, in `laufen()`; nur belegt-ohne-Kante, kein still-Auflösen; Domain-Prosa = v2). S3 Output-Senken: Migration `foodalchemist_lab_notes` + Model + `LabNoteService` + MCP `lab_notes.POST` (write, isOwnedBy); Draft via `recipes.POST`. `HypothesizeModeTest` (5) + `WissensWiderspruchTest` (5) = 10 Pest. **Korrektur:** der vermeintliche „Chem-Import"-Blocker war eine Fehlannahme — Dev-DB-Zählung: `molecules` 74.7k · `ingredient_molecule` 97k · `pairing_computed` 341k · `edges` 33.8k; Realdaten-Smoke (ajvar → guave/orange über geteilte Pyrazine/Furane/Terpene) grün. Offen: optionales KI-Narrativ (Prompt `knowledge.hypothesize`, braucht Provider).
- 2026-07-19 (R9 UI-Slice): **Lieferanten-Management UI GEBAUT — Spec 14 (R9) jetzt KOMPLETT (Engine+MCP+UI).** Neu `Livewire/Suppliers/SupplierDetail` + Blade: getabtes Stammblatt-Modal (Stammblatt: Status-Setzung/Kontakte-Anlage/WG-Abdeckung/Volumen-Proxy · Konditionen · Absprachen mit Wiedervorlage-Highlight · Dokumente mit Kündigungs-Deadline · Bündelung = `volumenProxyRanking`), „Beziehung"-Button im `Suppliers/Index`, lesen für die Team-Kette / Schreiben D1-gated im Service. R9.2-UI: `Gps/DetailPanel` bekommt `leadReason`-Feld → `setLeadLa(reason, recompute:true)` + sichtbare Override-Begründung/Vorschlag/Ausweichquellen aus `leadSteuerung()`. `SupplierDetailUiTest` (5 Pest); volle FA-Suite **807/808 grün** (1 begründeter Skip). Reine Oberfläche der bereits getesteten Engine — kein neues Datenmodell.
- 2026-07-19 (R9.2): **Lead-Lieferant-Steuerung GEBAUT (Engine+MCP) — Spec 14 (R9) komplett.** `gp_la_preferences.reason` (Migration) + `LeadLaService::setLeadLa(+reason,+recompute)` (Override-Historie via LogsActivity, Recompute der GP-Nutzer) + `leadSteuerung()` (Lead/Vorschlag/Ausweichquellen) + `SupplierService::volumenProxyRanking` (Nutzungs-Proxy je Lieferant × Konditionen) + MCP `gp_lead.GET`/`gp_lead.PUT`/`suppliers.VOLUME`. `LeadSteuerungTest` (3 Pest) + 105er-Regression grün. Override→Recompute-Beweis: 1,00→2,00 €. Offen: UI-Überschreiben (Livewire-Tab, Folge-Slice); echtes Spend = Q2.
- 2026-07-19 (R9.1): **Lieferanten-Stammblatt + Absprachen-Log GEBAUT (Engine+MCP).** Kommerzielle Beziehungs-Ebene: 4 Migrationen (Status+Konditionen auf `suppliers`; `supplier_contacts`/`supplier_agreements`/`supplier_documents`) + 3 Models + `SupplierStatus`-Enum + `SupplierService` (setStatus/updateConditions/addContact/`stammblatt`-Aggregat inkl. WG-Abdeckung) + `SupplierAgreementService` (Absprachen/Dokumente/Wiedervorlage/`documentsDueForNotice`) + `SignalTyp::VertragsfristFaellig` + Detektor `vertragsfristFaellig()` in `laufen()` + MCP `suppliers.GET`/`suppliers.PUT`/`supplier_agreements.POST` (D1-Schreibgate). `SupplierRelationTest` (3 Pest) + 51er-Regression grün. Konditions-Spalten geteilt mit Q2/[13]. Offen: Livewire-Detail-Tabs (Folge-Slice); R9.2 Lead-Steuerung.
- 2026-07-19 (R2.5): **Saison-Auto-Pricing / VK-Snapshot-Governance GEBAUT (Engine+MCP).** Trennung interne Live-Marge ↔ veröffentlichter VK: neue Tabelle `foodalchemist_vk_price_snapshots` + `FoodAlchemistVkPriceSnapshot` + `VkSnapshotService` (release/publishedFor/pending) + TeamSettings-Leitplanken (`min_margin_pct`/`max_vk_delta_pct`/`season_margin_band_min/max_pct` + Accessoren) + `SignalTyp::VkAnpassungEmpfohlen` + Detektor `vkAnpassungEmpfohlen` in `laufen()` + MCP `vk_snapshots.GET`/`RELEASE` (isOwnedBy). `VkSnapshotTest` (3 Pest), 80er-Regression (Signale/Kalkulation/Darreichung) grün. Kernbeweis: VK-Sprung ohne Freigabe → Signal, veröffentlichter VK unverändert. Offen: Batch-Freigabe-UI + R3.2-Kundensicht liest `publishedFor` (Folge-Slices).
- 2026-07-19 (R6.10): **Überschuss-zu-Gericht GEBAUT (Pairing-Offense S3 — Trio komplett).** `SurplusToDishService::suggest(team, [{gp_id,menge}], limit)` — Mock/Contract-Bestand → GP-Anker (`gpAnkers`) → Portfolio-Gerichte, die die Anker TRAGEN (Relevanz über beide recipe-anchor-Tabellen, nicht bloß „enthält") + verwertete GPs/Menge + Kohäsions-Begründung + nicht-verwertbar-Liste. MCP `foodalchemist.surplus.SUGGEST` (read-only). `SurplusToDishTest` (2 Pest) grün. Grenze E4: FA schlägt vor, Bestand/Bestellung = Nachbar-Modul; produktiver Contract = Q1/N-Track. Damit ist die Pairing-Offense (R6.8/6.9/6.10) FA-seitig durch.
- 2026-07-19 (R6.9): **Dish-Reverse-Engineering GEBAUT (Pairing-Offense S2).** `DishReverseService::reverse(team, text, limit)` — fremdes Gericht (Text) → Zerlegung in eigene GPs (`IngredientMatchService::matchIngredient`; unmatched+keine LA → Beschaffungs-Wunsch, keine Erfindung; unmatched+LA → `LaFirstGpService::mintFromLa` tentative) → Aroma-Skelett (tragende Anker + Verbund-Kanten via `gpAnkers`/`edgesFor`) → Nachbau-Kandidaten aus dem eigenen VK-Portfolio (Anker-Überlappung über `recipe_anchor_mappings`+`recipe_process_anchors`) + Lücken-Report (Anker ohne Bestandsträger). MCP `foodalchemist.dish.REVERSE` (read-only; Draft-Anlage = expliziter `recipes.POST`-Folgeschritt). `DishReverseTest` (2 Pest) grün. Foto-Input + #507-Recall = additive Ausbaustufen (Provider = Martin).
- 2026-07-19 (R6.8): **Aroma-treue Substitution GEBAUT (Pairing-Offense S1).** `PairingService::aromaTrueSubstitutes(team, gpId, limit, ?recipeIngredientId)` — Ersatz-GPs gerankt nach ERHALTENEM Geschmack: Anker-Kanten-Überlappung (welche Aroma-Brücken des Quell-GP der Kandidat trägt/erreicht, via `edgeBest` über beide `gpAnkers`) graceful gemischt mit dem 14-Typ-Aroma-Vektor-Cosinus (`0.6·Kanten + 0.4·Cosinus`; nur Kanten wenn kein Vektor — bewusst kein hartes Produkt, sonst Ranking-Kollaps bei dünnen Vektoren). Kandidaten-Pool = Aroma-Geschwister (≥1 geteilter Anker) ∪ gleiche Warengruppe ∪ manuelle Äquivalente (letztere geboostet, Inv. 3). Ausgabe je Kandidat: erhaltene/verlorene Brücken, `flavor_score`, `allergen_warnungen` (Diff via `GpAggregateService::allergene` VOR Tausch), `cost` (indikativer Lead-LA-Listen-EK, mode cost/both), `evidenz` (kuratiert/abgeleitet), `kohaesions_delta` (bei `recipe_ingredient_id`). MCP `foodalchemist.substitution.SUGGEST` (read-only, modes flavor|cost|both). 3 Pest (`AromaSubstitutionTest`) grün + 24 Pairing-Regression grün. Der eigentliche Tausch bleibt `tauscheZutat`. **Offen (Follow-up):** harter `swap_locked`-Guard in `tauscheZutat` (R6.3-Altlücke, aktuell nur gemeldet); Aroma-Vektor-Coverage (Q5); mengennormalisierter EK.
- 2026-07-15 (Bug gemeldet): **IngredientEditor Zutaten-Tausch — Kaskade/Auto-Sync unvollständig (Dev-Modul-Issue).** Beim Tausch einer Zutat + Mengen-Anpassung im Editor treten ZWEI Dinge auf (beide user-bestätigt an Rezept `getreidesalat_bulgur_mit_berglinsen_und_cashews_2527`):
  - **(a) Daten:** `RecipeService::syncIngredients` persistiert beim Tausch nur den `gp_id` (`match_method='manual'`) und prüft NICHT auf einen auflösenden Preis → Tausch auf einen unbepreisten GP (hier „Petersilie glatt: frisch, ganz", einer der 661 „Lead ohne Preis" / 1.417 „ohne Lead") → Zutat bleibt unbepreist, EK partiell (7/8). **Fix:** GP-Preise heilen (Etappe 1 `lead-la-repick` / Etappe 2 GP-Lücken-Match) **+ Editor-Warnung** bei preislosem Tausch-Ziel (`IngredientEditor::ekFuerZiel()`=null → sichtbarer Hinweis statt stillem „—").
  - **(b) Propagation/UI:** nach dem Speichern aktualisieren sich EK / übergeordnete Gerichte nicht sichtbar. `syncIngredients` ruft zwar `recomputeAndPropagate()` (RecipeService:484), d.h. server-seitig läuft der Recompute + Eltern-Propagation — aber der **Live-Refresh im UI** (Detail-Panel/Eltern-Liste nach `recipe-gespeichert`/`recipe-selected`) greift nicht durch. **Fix:** reproduzieren (dieses Rezept + ein Eltern-VK-Gericht vorher/nachher), Refresh-/Event-Kette im IngredientEditor + Browser prüfen.
- 2026-07-15 (DQ-Deploy): **Etappe 1 auf demo deployed + DQ-Signale live.** Deploy via `php8.4 /usr/local/bin/composer update martin3r/platform-foodalchemist` auf dem Server (SSH `forge@demo.bhgdigital.de`; main-HEAD `3f8a373`, enthält den Scheduler-Fold-in `00ff706`). Dann `php8.4 artisan foodalchemist:data-quality --team=6 --signals` → **11 DQ-Signale live in demos „Signale"-Inbox**, per MCP `signale.SEARCH` verifiziert (neue Typen `anker_fehlt`/`ek_kette_unvollstaendig`/`servierform_unbestimmt` + `datenqualitaet_gp_la`). **Lehre (Deploy):** Server-CLI-Default = **PHP 8.3**, Web läuft auf **8.4** → composer/artisan IMMER mit `php8.4` fahren (nacktes `php`/`composer` bricht mit „requires PHP >= 8.4.1" ab — kein Schaden, nur falsche CLI-Version; die Fehldiagnose „demo kaputt" war genau das, demo war nie kaputt). Doku: `15_GITHUB/Composer_Update_FA.md`. **Offen:** demo-*Daten*-Heilung — die Signale zeigen aktuell den UNGEHEILTEN Stand (1.417 GP ohne Lead, 6.947 ohne Allergen-Konfidenz), weil Lead-Repick/Allergen-Backfill/Recompute nur am lokalen Master liefen; auf demo unter `php8.4 artisan … --apply` nachziehen ODER Master re-importieren.
- 2026-07-15: **Cooking-Jarvis-App ↔ FA Rezept-KI-Abgleich (Erkenntnis-Session, kein Code).** Generator/Anreichern/Revise gegen die Tauri-Referenz gespiegelt → 2 verifizierte Lücken im Dev-Modul hinterlegt: **#508** (Revise groundet neue Zutaten nicht — kein Re-Matching/Hard-Stop, `syncIngredients` = reiner Persister → `unmatched`) + **#505**-Kommentar (Generator-Grounding lexikalisch-only; V-04-Embedding-Pass `build_inventory_bausteine`/SEM_FLOOR 0.55 nicht portiert). Keystone **#507** (semantischer Layer) = gemeinsame Wurzel + fehlende Hälfte von #505; Embedding-Infra existiert (`KnowledgeEmbeddingService`/Cores `EmbeddingProviderRegistry`), nur an Wissens-Suche gebunden + Provider aus. „Alles anreichern" (`BulkEnrichService`) sauber portiert = keine Lücke. Details: Memory `project_fa_507_semantic_search.md`.
- 2026-07-14 (5): **DQ-Ampel in den geplanten Scheduler eingehängt.** `DataQualityService::emittiereSignale()` läuft jetzt als 8. Detektor in `SignalDetektorService::laufen()` mit → der bestehende `signale-detektor`-Scheduler (auf demo aktiv) füllt die DQ-Signale (Anker/Servierform/EK-Kette/Allergen-Konfidenz) automatisch, kein Extra-Job/launchd nötig. `gp_ohne_lead`-Signal aus der Ampel entfernt (Detektor `datenqualitaetGpLa` besitzt den Befund → kein Doppel). Verifikation via demo-MCP `signale.SEARCH`: vor dem Deploy nur alte Typen (preis/marge/wareneinsatz), meine neuen Typen erscheinen nach Deploy + nächstem Scheduler-Tick.
- 2026-07-14 (4): **R0.3 neu geschnitten zur Datenqualitäts-Kaskade + Etappe 1 GEBAUT (lokal, verifiziert am Master).** Bottom-up-Remediation LA→GP→Basisrezept→VK statt Top-down. 4 neue Commands (`data-quality`/`lead-la-repick`/`gp-allergen-backfill`/`recompute`) + `DataQualityService` + 3 Signal-Typen. Am Master `foodalchemist_full` appliziert+verifiziert: 90 Lead-LAs gefixt (auflösend 4.900→4.990), GP-Allergen-Konfidenz 6.947→0 (nur Metadaten, Wert-Spalten unberührt — Override-Schutz), Bulk-Recompute 3.218/0 Zyklen, 12 Datenqualitäts-Signale in der Inbox (reisen per Re-Export nach demo). 13 neue Pest-Tests grün. Ehrlicher Befund: EK-Rest-Stau (219 VK/788 BR teil-unbepreist) hängt an 405 Park-GPs (kein bepreister LA) → LA-Sourcing = Etappe 2 (lokaler OpenAI-Provider: Anker-Erdung + Serving-Form-KI + GP-Lücken-Match). 2 WaWi-Ära-DoD-Punkte obsolet gestrichen. Gelernt: `allergens_source` ist varchar(16) → `derivat` statt `derivat_inherited`; `loestAuf` (Preiszeile) ≠ Recompute-`vergleichspreis` (braucht qty+unit) — grobe „teil-unbepreist"-Metrik ist gegenüber verstreuten GP-Fixes unempfindlich.
- 2026-07-14 (3): **R7.1 Rest-Punkte geschlossen + Blätter-Filter.** Produktionsblatt zeigt jetzt Regenerations-/Behälter-/Vehikel-Parameter der Standard-Darreichung + Arbeitszeit-Zuschlag (Vokabel-Namen aufgelöst) + `preparation`-Freitext je Rezept. Einkaufsliste-PDF-Route (`typ=einkauf`) ergänzt. Neuer **Blätter-Filter** auf `/blaetter` (Mehrfach-Auswahl Produktion/Bestellung/Einkauf — steuert, welche Blätter erzeugt/gezeigt werden; Dominique-Wunsch). Einziger offener R7.1-Punkt bleibt echtes „Zubereitungsschritt"-Grouping (kein Schritt-Datenmodell). Tests erweitert (Filter + Regeneration/Einkauf-Blade), Voll-Suite grün.
- 2026-07-14 (2): **R3.2 externe Web-Seite v1 (layout-first) GEBAUT (lokal).** Block C der Ausgabe-Schicht: Livewire-Full-Page `/foodbooks/{id}/praesentation` (auth-gated) rendert die serverseitige Kunden-Projektion (`dokumentDaten intern=false`, EK-frei) als gebrandete Seite — Hero, Kapitel + Preis pro Person, Wording-Zeilen, Preis-Fuß/MwSt, Bild-Platzhalter. Editor-Link „Präsentation". Kein Pax (Preise pro Person). Test `FoodbookServiceTest` (EK-Leak-Guard: kein „Wareneinsatz"/„INTERN"). Offen: echte Bilder (kein Gericht-Bild-Feld, #461), per-Kunde-CI (keine Brand-Relation), öffentlicher Share-Link (= Martin/Core-Auth). Damit A→B→C v1 komplett; Feinschliff (Bilder/CI/Share-Link/Facetten) = Folge-Iterationen.
- 2026-07-14: **R3.1 intern-Dokument GEBAUT (lokal, ungepusht).** Das interne Foodbook = aufgewertetes **Dokument** (nicht der in #501 gelöschte Standalone-View, Entscheid Dominique): `FoodbookService::dokumentDaten($intern)` liefert EK/VK/W% pro Person je Kapitel + Gesamt + Kapitel-Anker; Blade `dokumente/foodbook` bekam **Navleiste** (klickbar HTML+PDF), Marge-Spalten (nur intern, NIE im Kundendokument), Kunde/Intern-Umschalter, „INTERN"-Badge. Route `?intern=1`, Editor-Link „Dokument (intern)". 2 neue Pest-Tests, Suite grün. Teil der R3+R7-Ausgabe-Schicht (Block B von A→B→C); als Nächstes Block C = externe gebrandete Web-Seite (Bilder/KI, pro Person, Share-Link = Martin). Offen R3.1: Facetten-Filter + Lasttest (gehören zur Web-Seite).
- 2026-07-13 (4): **R7.1 Operative Planungs-Blätter GEBAUT (lokal, ungepusht).** `PlanungsblattService` (Explosions-Engine über den Rezeptbaum) + 3 read-only MCP-Tools (`produktionsblatt`/`bestellvorschlag`/`einkaufsliste.GET`) + UI `/blaetter` (Sidebar „Planung") + DomPDF-Blätter + `RecipeRecomputeService::bruttoMasseG` (neuer Public-Helper). Kern-Entscheid Dominique: „so wie das Rezept angelegt ist" — VK linear, Basisrezepte in GANZEN Ansätzen, Merge vor Rundung, Skalierung Personen ODER Portionen. Ausweichquelle aus der Lead-Kette (Voll-Substitution → R6.3/R6.8). 8 neue Pest-Tests, Voll-Suite **678/679** (1 Skip), 0 Regressionen. Offen: „gruppiert nach Zubereitungsschritt" (keine strukturierten Steps im Datenmodell), Regenerations-/Behälter-Params je Darreichung im Blatt, Einkaufsliste-PDF-Route. Gelernt: Blade kompiliert `@directive` NICHT, wenn ein Wortzeichen direkt davorsteht (`\B@`-Regex) → `min@endif` blieb literal; Pest-Harness registriert Closure-`dokument`-Routen nicht (Blade per View-Render testen, nicht per HTTP-`get`).
- 2026-07-13 (3): **R6.1 GEBAUT (Blindtest offen)** — `ConceptGeneratorService`: Gerüst-Pfad (deterministisch, ohne KI lauffähig) + Brief-Pfad (KI übersetzt Brief→Gerüst via neuem Prompt `concept.brief_geruest`, Werte sanitized; Auswahl bleibt deterministisch = „Keine Erfindungen"). Slot-Semantik-Ranking (Label↔Speisen-HG via recipes.dish_main_group_id, Modell A) vor Pairing-Kanten-Gewinn. `PairingService::menuCohesion` + Kohäsions-Panel im Concepter; Gerüst-Kopie ans Konzept (`kopiereZu`) → Auto-Coverage. UI-Einstiege: Concepts-Browser (Brief-Modal) + Foodbook (aus Gerüst); MCP `concepts.GENERATE`. Neue Spalten: `concepts.created_via`, `concept_slots.note` (Leer-Begründung). 9 neue Tests, Suite 668/669 grün, MySQL-Smoke (Fixture) mit Draft-Aufräumung. Gelernt: Collection::merge renummeriert Integer-Keys (put() nutzen); Dev-Fixture hat nur 31 VK — Blindtest braucht Master.
- 2026-07-13 (2): **R4 KOMPLETT — R4.2 Coverage + R4.3 Phasen + R4.4 Slot-Varianten** (ein Zug nach R4.1, Entscheid Dominique „R4 komplett fertig"). R4.2: `CoverageService` misst Foodbook-/Konzept-Ist gegen das Gerüst (Menge/Diät/Preis/Saison/Dramaturgie/No-Gos, Ampeln + ehrliche Degradation), live in beiden Editoren, Lücken-Klick → Diät-gefilterte Gericht-Suche (neuer `pickDiaet`-Filter), MCP `coverage.GET`. R4.3: Phasen-Statusmaschine mit Freigabe-Gate gegen rote Ampeln (Override durabel protokolliert), Browser-Badges + Filter, MCP `phase.PUT` (Freigabe menschlich). R4.4: konzept-lokale Slot-Varianten (`ConceptVariantService`, Voll-Kopie + Katalog-Filter), 🧾 Zutaten-Baum im Concepter mit ♻ Äquivalenz-Tausch, MCP `concept_slot_variante.POST`; Rest-Parität der Zeilen-Aktionen → R6.3. 26 neue Tests, Gesamt-Suite 663/664 grün, MySQL-Kanon migriert (000020/000030) + Smoke (Coverage-Befunde + Gate auf FB 1). **Damit ist R6.1 nur noch durch R0.2 ✅ gedeckt → Brief→Konzept ist entblockt.**
- 2026-07-13: **R4.1 Planungs-Gerüst abgeschlossen** (Einstieg in den R4-Track als R6.1-Vorarbeit, Entscheid Dominique). Strukturierte Soll-Ebene neben dem Freitext-Canvas: `planning_frames`/`_slots`/`_rules` (Mengengerüst + Diät-Quoten, Preisarchitektur p. P. + je Slot, No-Gos/Allergen-Linie, Saison, Dramaturgie), Service mit D1-Write-Guard + deklarativem `replaceStructure`, UI in Foodbook-Editor + Concepter, MCP `planning.GET/PUT` im Lockstep (Brief→Gerüst in einem Call, `prompt_kontext` fürs R6-Prompting). 15 neue Pest-Tests (inkl. UI-Klick→DB via Livewire-Host + Kollisionsfreiheits-Beweis), MySQL-Kanon migriert + Smoke. Nächster Schritt: R4.2 Soll/Ist-Coverage misst gegen dieses Gerüst.

- 2026-07-12: **R0.2 abgeschlossen + Wissens-Modul komplett** (gepusht `178d299..d5409a6`). R0.2 MCP-Darreichungs-Nachzug M1–M6: die 38 Tools sind darreichungs-fähig (recipes.POST→Standard-Darreichung, SEARCH/GET liefern Formen, kalkulation.GET über Resolver, Concept-Facetten + Slot-Darreichung, Klassifikator Bauart-Regel + nur aktive HGs; latenter MySQL-`||`-Bug gefixt). Wissens-Modul #469: Import-Guard (`imported_hash`, App-wins) + Browser-Semantiksuche (alle Kategorien) + v3 MCP-Schreiben (`knowledge.POST/PUT`, `created_via`). Tests grün; Buffet-Preis-Beweis per MySQL-Smoke. Offen: demo-Deploy (R0.1, Martin) macht beides live sichtbar.
- 2026-07-05: **Zwei neue Pakete (Dominique).** (1) **R8.1 LA-Multi-Select → Bulk-GP-Erstellung/Matching** — LA-First-Kuration FA-nativ ins Produkt (mehrere LAs markieren → Bulk-Run legt tentative GPs an / matched gegen approved), neues Paket R8. (2) **R2.6 erweitert** von „Kunden-/Event-Bewertung" auf **Feedback je Gericht/Rezept (Küche · Kunde · Event)** — explizit Küchenmitarbeiter-Feedback als Entwicklungs-Motor (Rezepte auf Praxis-Basis weiterentwickeln), Feedback auch am Basisrezept + „Weiterentwickeln"-Brücke zur Rezept-Iteration. — Kontext: DB komplett auf Englisch gezogen (Batch 3, Commit 72ca7f1) + Migration-Drift-Deploy-Blocker gefixt (4bdb308); Master-Roadmap als Doc #227 im Dev-Modul gespiegelt.
- 2026-07-04 (Nachtrag 4, **R1 auf FA-nativ umgestellt**): Nach dem WaWi-Freeze (FA = alleinige Master-DB) ist Import/Sync obsolet. **R1.1 neu:** „994 VK-Gerichte FA-nativ erstellen (mit Rezeptur + Mengen)" aus den zwei Foodbook-2027-PDFs (1 Portion + Ansatz) — Komponenten gegen **bestehende Basisrezepte** + GPs gematcht, Mengen = Ansatz ÷ Portionszahl, Recompute via `artisan` inklusive. Altes **R1.2 (FA-Sync/ImportSliceCommand) gestrichen**; alte **R1.3 Kuration → R1.2** (Quer-Refs R5.3/R6.7 nachgezogen). Vorbedingung geprüft: Basisrezepte tragfähig (2.250, referentielle Integrität sauber, EK 95,5 %, Allergen-Konfidenz 92 % medium; Rest-To-Dos = R0.3-Ampel). Anlass: Klärung Dominique — die VK-Gerichte sind noch nicht erstellt, sie kommen (wie ein Teil der Basisrezepte) aus den zwei PDFs.
- 2026-07-04: Brainstorm-Erweiterung (Dominique + Cooking Jarvis). Neu: **R2.4** Marge-optimale Assemblierung, **R2.5** Saison-Auto-Pricing (intern-vorschlagend, entkoppelt vom Kunden-Preis), **R2.6** Kunden-/Event-Bewertung je Gericht (ersetzt Produktions-Feedback-Loop), **R2.7** Portfolio-Benchmark BHG-intern; **R6.8** Aroma-treue Substitution, **R6.9** Dish-Reverse-Engineering, **R6.10** Überschuss-zu-Gericht; **Ausblick-Track N0–N2** (Nachbar-Modul Einkauf/Lager/Produktion/Event, gated an Q1); Meilensteine M-H + M-N. Abhängigkeits-Kette + GOALS Horizont 1/3 + GOALS-Sektion „Ausblick: Nachbar-Module" entsprechend ergänzt.
- 2026-07-04 (Nachtrag): Kern-Entscheid „berechnete Blätter = FA, operativer Zustand = Nachbar-Modul, zwei Zeitpunkte". Neu: **R7** Operative Planungs-Blätter FA-seitig (`produktionsblatt.GET`/`bestellvorschlag.GET`/`einkaufsliste.GET`, read-only) als Vorstufe, die N0 de-riskt; Meilenstein M-O. GOALS Horizont 1 + Ausblick-Sektion entsprechend präzisiert.
- 2026-07-04 (Nachtrag 3, gemessen statt geraten): **Q5** Graph-Konnektivität & Mapping-Reichweite eingezogen, mit **echter Baseline** aus der WaWi-DB. Befund korrigiert die Annahme: Kanten-Graph (23.951/767) + GP-Erdung (98 %) sind stark — dünn sind Kohärenz-Score (0,2 % berechnet), Rezept-Anker-Reichweite (60 %) und Mapping-*Vertrauen* (~64 % unverifizierte Gemini-Vorschläge). Priorität: Kohärenz-Lauf > Reichweite > Mapping-Verifikation > kein Blanket-Graph-/Doc-Ausbau. R6-Header + Abhängigkeits-Note um Q5 ergänzt.
- 2026-07-04 (Nachtrag 2, „erklärendes Geschmacks-Gehirn", Option c): **Warum-Layer** als Querschnitts-DoD für R6 (zitierte Begründung + Evidenz-Stufe je Vorschlag) + **R6.11** Hypothesen- & Widerspruchs-Modus (R&D) + **Q4** Evidenz-Abdeckung & Anreicherung als Fundament (Evidenz-Ampel, T0–T3-Stufen, Lücken-treibt-Recherche, Flywheel über R2.6) + **A-Track** (Academy konsumiert `knowledge.EXPLAIN`); Meilenstein M-W. GOALS Horizont 3 + KVP-Prinzip ergänzt. Fix für „dünne Datenlage": sichtbar machen statt verstecken, ehrlich abstufen, gezielt schließen, durch Nutzung verdicken.
- 2026-07-03: Erstfassung aus GOALS.md (Stand gleicher Tag) + Projekt-Memory (FB2027, MCP-Kaskade, Darreichungen-Umbau). Autor: Cooking Jarvis + Dominique.
