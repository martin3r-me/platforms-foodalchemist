# Modernist-/Alchemist-Wissen → FA-Grounding: Plan pro Punkt (Stand 2026-07-16)

> **Auftrag:** Zu den 8 Punkten des Modernist-/Foodalchemist-Konzepts je ein Plan mit Datenquellen, „um eine KI damit zu trainieren".
> **Reframe (verbindlich, siehe Einschätzung):** FA/CJ **trainieren kein Modell** — sie **grounden**: OpenAI-Embeddings-RAG (siehe RAG-Plan E0–E6) + LLM nur für Sprache + **deterministische Rechner** + Referenzdaten. „Trainingsdaten" = (a) Wissens-Docs (`knowledge_documents`, RAG-Korpus), (b) deterministische Services (Formeln als Code, NIE im Prompt), (c) Referenz-/vocab-Tabellen.
> **Grammaturen-Regel (Dominique):** Das Datenmodell bleibt **gramm-/yield-basiert**. Prozente sind eine **abgeleitete Sicht** (Bäckerprozent), kein Ersatz.
> **Provenienz-Regel (Pflicht):** Jede Formel/Zahl auf reale Quelle stützen (Modernist Cuisine / Belitz-Grosch-Schieberle / Ahn 2011 / FooDB / FSA-USDA), fehlend = `low_evidence`/T3-Hypothese, nie erfinden (Q4-Evidenzstufen).

---

## Leit-Entscheid: die drei Landeplätze in FA

| Landeplatz | Wofür | FA-Mechanik (existiert) |
|---|---|---|
| **A — Deterministischer Rechner (Service)** | exakte Formeln (Skalierung, Brining, Bloom, Kerntemp) | neue `*Service.php`, wie MargeService/DarreichungService; Pest-getestet |
| **B — Wissens-Doc (RAG)** | Prosa/Prinzipien, die der LLM beim Generieren/Erklären zieht | `knowledge_documents` (category domain/regelwerk) via PDF-Destillation (Skripte 109/110/111) → knowledge-embed (RAG E1) |
| **C — Referenz-/vocab-Tabelle** | strukturierte Nachschlage-Werte (Dosierungen, cP, HLB, Kerntemp) | vocab-Tabelle o. Seed-Daten, deterministisch abfragbar |

**Regel:** Eine Formel geht IMMER nach A (Code), nie in einen Prompt. Eine Zahl-Tabelle nach C. Prinzipien/Kontext nach B. Der LLM ruft A/C als Fakten, liest B als Grounding.

---

## TIER 1 — jetzt bauen, echter BHG-Wert

### Punkt 1 — Prozentsystem / Skalierung · Landeplatz A (+ C) · ✅ GEBAUT (2026-07-18, Dev #513)

> **Status 2026-07-18:** `ProportionService` gebaut — alle 4 Formeln (Bäckerprozent + Rückweg, Extraprozent, Brining Lake-Masse+Zielgewicht, Gelatine-Bloom via Wert oder Sorte bronze/silber/gold/platin) + Bäckerprozent-Sicht je Rezept (Masse via `RecipeRecomputeService::bruttoMasseG`, gleiche T1-Kaskade). MCP-Tool `foodalchemist.proportion.CALC` (read-only, 6 operations). Pest `ProportionServiceTest` gegen hand-gerechnete Fälle. Grammatur bleibt Master, Prozent = abgeleitete Sicht. Bloom-Sortenwerte als dokumentierte Referenz (DGF-Grade, Herstellerangabe hat Vorrang) — nicht erfunden.
>
> **Status 2026-07-18 (2. Slice) — %→Gramm-Rückschreiben GEBAUT:** `rescaleRecipe`/`rescaleToReferenceMass` (Modus A Batch-Skalierung, einheiten-neutral) + `setIngredientBakerPercent` (Modus B Einzel-Zutat, **Einheiten-Guard**: nur g/kg, Stück/Liter read-only weil % massebasiert) + MCP-Write-Tool `foodalchemist.proportion.APPLY` (rescale/set_baker_percent). Schreibt NUR Mengen, nie ein Prozent → Recompute. Owner-Team-Guard (D1). Pest deckt A/B/Guard/kg-Rückrechnung/MCP ab. **Damit ist der Rechner-Kern von Punkt 1 bidirektional komplett — Gramm↔% synchron über die Grammatur.**
>
> **Status 2026-07-18 (3. Slice) — Editor-UI GEBAUT:** Bäckerprozent-**Spalte im Zutaten-Editor** (neben Garverlust), `partials/zutaten-kern.blade.php`. Live-berechnet in Alpine (`bakerPct`, Masse = `mengeAvg×gFaktor` — spiegelt Server-`bruttoMasseG`), Referenz = schwerste Zutat (= 100 %, `🔒`). **Editierbar** (Modus B im Client): %-Eingabe schreibt Gramm in `zeile.quantity` zurück (`setBakerPct`), gespeichert wird über den bestehenden Save→syncIngredients — nie ein Prozent. **Einheiten-Guard** `istMasse`: nur g/kg editierbar, Stück/Liter read-only (`🔒`). `einheiten`-Map um `dim` erweitert; Colspans nachgezogen. Pest-Markup-Assertion in `IngredientEditorTest`.
>
> **Damit ist #513 Punkt 1 KOMPLETT — Rechner (read+write) + MCP + Editor-UI.** ⚠️ Server-Render + Alpine-Formeln sind Pest-/hand-verifiziert; die **Klick-Strecke im Browser** (Live-Eingabe, Referenz-Lock, Layout) steht als menschliche Gegenprobe aus (kein Browser im Build-Env).
>
> **Offen (eigene Themen, nach Bedarf):** Referenztabellen C — Punkt 2 Kerntemp (→ R5.3 HACCP) / Punkt 3+7 Hydrokolloid-Dosier+HLB. Tier 3 (PDE/Arrhenius/Henry/Neuro) = bewusst NICHT bauen.


**Was:** Bäckerprozent als abgeleitete Sicht (Referenzzutat = 100 %) + „Extraprozent" für hocheffektive Zutaten (Hydrokolloide, Salz, Säure — bezogen aufs Gesamtgewicht) + Brining-Formel + Gelatine-Bloom-Konvertierung.
**Existiert in FA?** Skalierung/Recompute ja (`RecipeRecomputeService`, yield/portionen). Bäckerprozent-Sicht, Extraprozent, Brining, Bloom = **nein**.
**Verdikt:** **Rechner bauen** — das sind exakte Formeln, die BHG-Köche real nutzen (Lake ansetzen, Gelatine-Marke tauschen, Rezept auf 100 Pax skalieren ohne Präzisionsverlust).
**Formeln (als Code, Quelle Modernist Cuisine):**
- Bäckerprozent: `pct_i = m_i / m_ref × 100` (m_ref = Referenzzutat in g). Rückweg: `m_i = pct_i/100 × m_ref`.
- Extraprozent (Hydrokolloide): `m_hydro = pct_extra/100 × Σ(alle anderen g)` — stabil, wenn Komponenten wegfallen.
- Brining-Zielgewicht: `T = M + (d · M / S)` (d = Ziel-Salinität, M = Startgewicht, S = Salzgehalt Lake).
- Gelatine-Bloom: `M_B = M_A · Bloom_A / Bloom_B` (Marke A→B umrechnen).
**Datenquellen:** Modernist Cuisine Bd. 2 (Ratios/Scaling) + Bd. 3 (Brining, Gele) — Formeln + typische Bloom-Werte (Gold ~200, Silber ~160, Blatt-DE). Für die Referenztabelle C: Bloom-Stärken gängiger Gelatine-Marken (Herstellerangaben, verifizierbar).
**FA-Integration:** `ProportionService` + optional Bäckerprozent-Spalte als **berechnete Sicht** im Rezept-Editor (Grammatur bleibt Master); Brining/Bloom als kleine Helfer im Editor bzw. als MCP-Tool. DoD: Pest gegen hand-gerechnete Fälle.

### Punkt 2 — Wärmeübertragung → als **Kerntemperatur/Regeneration**, NICHT als PDE · Landeplatz C (+ B)

**Was (FA-tauglich):** Nicht die Wärmeleitungs-Differentialgleichung, sondern **Kerntemperatur-Ziele + Regenerations-Parameter je Produkt/Geometrie** — das ist der Wert für Event-Catering (Cook-&-Chill, Kombidämpfer, HACCP).
**Existiert in FA?** Teilweise: ROADMAP **R5.3 HACCP**, A5 Behälter/Regeneration je Darreichung, `recipe_darreichungen`. Datenbasis (Kerntemp-Tabellen) fehlt.
**Verdikt:** **Referenztabelle C** (Kerntemp-Ziele + sichere Temperaturen) + **Wissens-Doc B** (Konduktion/Konvektion/Strahlung als Prinzipien fürs Erklären). PDE bewusst **skip** (Tier 3).
**Datenquellen:** FSA/USDA/DGE sichere Kerntemperaturen (Geflügel 72–74 °C, Rind medium 54–56 °C, Fisch …), Zwangskonvektions-/Kombidämpfer-Prinzipien aus Modernist Cuisine Bd. 2. HACCP-Grenzwerte aus eurer bestehenden Compliance-Quelle.
**FA-Integration:** Kerntemp-vocab je Speisen-Hauptgruppe/GP → speist R5.3 HACCP-Generierung + Regenerations-Feld je Darreichung (A5). Direkt anschlussfähig.

---

## TIER 2 — existiert oder als Referenzdaten vertiefen

### Punkt 5 — Molekulares Flavor Pairing · **existiert = Anker-Graph** · Landeplatz B/C (kein Neubau)

**Was:** Schlüsselaromen-Überlappung (geteilte flüchtige Verbindungen) + Aromenfreisetzungs-Kinetik (Kopf/Herz/Basis über Dampfdruck).
**Existiert in FA?** **JA, das ist Station 0/2/6 der ROADMAP:** molecules 74k, `ingredient_aroma_vector`, computed pairings (ρ 0,54), `book_pairings` (9.034), Anker-Graph ~179k Kanten, FooDB/Ahn/FlavorDB2. `PairingService`, computed-Kanten-Projektion.
**Verdikt:** **NICHT neu bauen.** „Trainingsdaten" hier = die bereits geladenen Quellen. Offen ist nur **Station 6** (OAV-Schwellen, Food-Bridging als Kontrast-Generator, scharfe Buch-Vektoren) — das ist der bestehende Genauigkeits-Track, extern teils blockiert (OT-Tabelle). Aromenfreisetzungs-Kinetik (Dampfdruck-Sequenz) = **T3-Hypothese** (Wissens-Doc), kein Rechner.
**Datenquellen:** schon in FA (Ahn 2011, FooDB, Lahousse/Coucquyt Foodpairing, Belitz-Grosch-Schieberle). Für Station 6: OT(m)-Geruchsschwellen-Tabelle (extern beschaffen).
**FA-Integration:** Verweis auf Station 6 / #487 (Foodpairing-Profile → Wissensdatenbank). Nichts Neues für dieses Konzept.

### Punkt 3 + 7 — Viskosität/Textur (cP) & Emulsionen/Schäume (HLB) · Landeplatz C (+ B)

**Was:** Fließverhalten (Newton'sch vs. strukturviskos) in cP + HLB-Werte von Emulgatoren + **Hydrokolloid-Dosierungen** (der praktische Kern).
**Existiert in FA?** Nein.
**Verdikt:** **Referenztabellen C** — das ist der eigentliche Wert: **Dosier-Tabellen** (Agar 0,5–2 %, Xanthan 0,1–0,5 %, Gellan, Alginat/Calcium für Sphärifikation) + HLB-Werte (Sojalecithin ~8, Polysorbat) + cP gängiger Flüssigkeiten. Verzahnt mit Punkt-1-„Extraprozent" (Dosierung IST Extraprozent). Nicht-Newton'sch/Phasenübergänge = Wissens-Doc B (Prinzip), kein Rechner.
**Datenquellen:** Modernist Cuisine Bd. 4 (Dosier-Charts, HLB), Khymos/Martin Lersch Texture-Referenzen, Hersteller-Dosierangaben (Willy/Modernist Pantry, verifizierbar), cP-Tabellen Belitz-Grosch-Schieberle.
**FA-Integration:** Hydrokolloid-Dosier-vocab → nutzbar im Generator (wenn KI ein Gel/Espuma-Rezept baut, zieht sie die reale Dosier-Range statt zu raten) + als Extraprozent-Rechner (Punkt 1). Ehrlicher Nutzen nur, wenn BHG avantgardistische Komponenten baut — Prio nach echtem Bedarf.

---

## TIER 3 — NICHT als Engine bauen (max. 1 Wissens-Doc, T3-markiert)

Faszinierend, aber für einen Volumen-Caterer Scope-Creep + hohes Halluzinations-/Pflege-Risiko. **Kein Rechner, keine UI, kein vocab.** Wenn überhaupt: je ein Destillat-Doc, klar als T3-Hypothese markiert (Q4), damit die KI beim *Erklären* darauf zugreifen kann — aber nichts „berechnet".

- **Punkt 2 (Wärmeleitungs-PDE):** ein Volumen-Caterer braucht Kerntemp-Tabellen (Tier 1 C), keine Differentialgleichung. **Skip als Rechner.**
- **Punkt 6 (Arrhenius / Enzym-Engine — Transglutaminase, Pektinase):** Reaktionskinetik-Engine ist Forschung, nicht Catering-Betrieb. Transglutaminase-**Anwendungshinweise** (pH/Salz/Temp-Fenster) höchstens als Wissens-Doc B. **Kein k=A·e^(−Ea/RT)-Rechner.**
- **Punkt 7-Henry (Gaslöslichkeit N₂O-Siphon):** Wissens-Doc-Fußnote, kein Rechner.
- **Punkt 8 (Neurogastronomie — Farb-Geschmacks-Interferenz, Knusper-Hertz):** spannend fürs Marketing/Plating-Wording, aber **kein berechenbarer Parameter** für FA. Als Prinzip höchstens im Sensorik-/Plating-Prompt-Kontext (B), nie als Zahl.

**Begründung (Sparring):** BHG-Leitbild „nie Produktivität ohne Wertschöpfung". Eine Physik-/Neuro-Engine ist Produktivität ohne Wertschöpfung für Event-/Volumen-Catering. Der Wert liegt in Tier 1 (Rechner, die Köche nutzen) + Tier 2 (Referenzdaten, die den Generator erden).

---

## Machbarkeit: Könnten wir das überhaupt abdecken? (Frage Dominique 2026-07-16)

Ehrliche Antwort: **Die Grenze ist nicht Engineering, sondern Daten — und die „keine Erfindungen"-Regel.** Deshalb spaltet sich „abdecken" in zwei Fälle:

- **Abdeckbar (bounded, publiziert):** Alles, was aus **endlichen, veröffentlichten Referenztabellen** kommt — Kerntemperaturen, cP-Werte, HLB-Werte, Hydrokolloid-Dosier-Ranges, Bloom-Stärken. Das sind Dutzende Konstanten aus Modernist Cuisine / Belitz-Grosch-Schieberle, keine Messungen pro Zutat. Abschreiben + strukturieren = machbar, überschaubar. (Tier 1 + Tier-2-Tabellen.)
- **NICHT abdeckbar (Daten existieren nicht pro Zutat/Gericht):** Alles, was einen **physikalischen Parameter je einzelnem GP/Gericht** braucht — Wärmeleitungs-PDE (thermische Diffusivität + Geometrie je Gericht), Arrhenius (Aktivierungsenergie je Reaktion je Lebensmittel), Gaslöslichkeit je Rezeptur, Knusprigkeit in Hz (akustische Messung). Diese Werte gibt es für **7.900 GPs / 3.200 Rezepte schlicht nicht** — und laut Regelwerk dürfen wir sie nicht erfinden. Also: technisch programmierbar, praktisch **nicht befüllbar** → nicht ehrlich abdeckbar.

**„Erklären" vs. „Berechnen":** Die KI *kann* diese Konzepte als Prosa erklären (T3-Wissens-Doc, billig). Sie *kann nicht* das Ergebnis eines konkreten Gerichts wahrheitsgemäß berechnen/steuern. Coverage heißt hier „berechnen", nicht „erklären".

**Der Pairing-Beweis (warum das genau EINE Ausnahme ist):** Molekulares Pairing deckt FA ab — aber **nur, weil ihr über Jahre die Datenschicht gebaut habt** (74k Moleküle, aroma_vector, FooDB/Ahn, book_pairings). Um Wärme/Enzyme/Neuro auf gleicher Tiefe „abzudecken", bräuchte man je Domäne eine **äquivalente mehrjährige Daten-Akquise pro Zutat** — die es nirgends publiziert gibt. Pairing ist die Ausnahme, die die Regel beweist: **Abdeckung = Daten-Investment, und dieses Investment existiert nur fürs Pairing.**

→ Dein Bauchgefühl ist richtig: der Rest ist „over the top" — nicht weil er schwer zu bauen wäre, sondern weil wir ihn mit ehrlichen Daten gar nicht füttern könnten. Tier 1 (Grammaturen) bauen wir, Tier-2-Tabellen bei Bedarf, Tier 3 bleibt Prosa-Wissen oder gar nichts.

## Umsetzungs-Reihenfolge

1. **PDF-Destillation gezielt** (bestehende Skripte 109/110/111): Modernist Cuisine relevante Kapitel + Belitz-Grosch-Schieberle → Wissens-Docs B (category `domain`/`regelwerk`), dann RAG-E1-embedden. Liefert Grounding für alle Tiers auf einen Schlag.
2. **`ProportionService`** (Punkt 1): Bäckerprozent-Sicht + Extraprozent + Brining + Bloom — kleiner, klar testbarer Rechner, sofortiger Küchen-Nutzen.
3. **Referenztabellen C**: Kerntemp (Punkt 2) → speist R5.3 HACCP; Hydrokolloid-Dosier + HLB (Punkt 3/7) → speist Generator.
4. **Station 6** (Punkt 5): im bestehenden Pairing-Track, wenn OT-Tabelle beschafft — kein Teil dieses Konzepts.
5. **Tier 3**: nur bei konkretem Bedarf je ein T3-Wissens-Doc, nie als Rechner.

## Bewusste Nicht-Ziele

- Kein Modell-Fine-Tuning (FA groundet, trainiert nicht).
- Keine Formel im LLM-Prompt (immer Service).
- Kein Ersatz des Gramm-Datenmodells durch Prozente (Prozent = abgeleitete Sicht).
- Keine Physik-/Reaktionskinetik-/Neuro-Engine (Tier 3 = max. Wissens-Doc).
- Keine erfundenen Zahlen — jede Dosierung/Temperatur/Bloom-Wert mit Provenienz oder `low_evidence`.

---

*Erstellt 2026-07-16 (Planungs-Session). Verzahnt: RAG-Plan E1 (Wissens-Docs embedden), ROADMAP R5.3 HACCP / A5 Regeneration / Station 6 Pairing, Skripte 109/110/111 (PDF-Destillation). Quelle-Konzept: Modernist-/Foodalchemist-Notiz (extern, Gemini-generiert) — hier auf FA-Realität + „keine Erfindungen" heruntergebrochen.*
