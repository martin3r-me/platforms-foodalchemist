---
typ: Referenz-Inventar (Ist-App-Screens)
stand: 2026-06-11
status: von Dominique nachgereichte Original-Screenshots — verbindliche Abgleich-Basis
---

# 13 — Referenz-Screens der Cooking-Jarvis-App (Abgleich-Inventar)

> Die Original-Screenshots existieren nur als Chat-Uploads (Dominique, 2026-06-11).
> Dieses Inventar hält ihren Inhalt strukturiert fest, damit jedes Roadmap-Paket
> dagegen abgleichen kann. Ergänzt 11_UI_PATTERNS (P-1…P-8) um die Detail-Wahrheit.

## Screen 2/3 — Lieferanten + LA-Modal (M2-Abgleich ✅ erledigt)

Abgleich 2026-06-11 abgeschlossen → Lücken als M2-14/15/16 umgesetzt. Inventar:
Lieferanten-Kopf (Name, Standard-Lieferant-Badge, „n Artikel · m gemapped", Bearbeiten,
Deaktivieren), lokale Artikel-Suche, Nur-aktive, + Neuer Artikel, Bulk-Leiste bei
Selektion (GP zuweisen · Mapping entfernen · Einstellen · Reaktivieren · Löschen ·
Auswahl löschen → M3-11-Nachtrag), Tabelle (★Lead-Spalte → M3-07-Nachtrag, ArtNr,
Bezeichnung, Gebinde „8 KA", Status AKTIV, EK, Vergleichspreis €/Stk, GP-Link).
LA-Modal: Kopf (Lieferant · Art-Nr · EK + Kategorie), Stammdaten (+Zusatztext),
Verpackung (VPE/Bestelleinheit/Menge in Stk/VPE pro BE/EANs), Eigenschaften (Herkunft,
Ursprungsland-Select, MwSt %, Bio-Kontrollnr, Bio/Vegan/Vegetarisch/Halal/GVO-frei/
Vorbestellung, Zutatenliste), Preise (gültig von/bis, Kategorie-Pill, Preis/KA mit
€/Stk-Zweitzeile, Notiz, edit/löschen, „+ Neuer Preis"), Allergene 14 (−/≈/✓),
Zusatzstoffe 18 (−/✓), GP-Mapping-Sektion („— kein GP zugeordnet —", ✨ KI-Vorschlag,
+ GP zuweisen → M3), Footer Löschen/Abbrechen/Speichern.

## Screen 1 — GP-Browser (M3-Abgleich-Basis)

- **Topbar:** Modul-Tabs (Lieferanten · Grundprodukte · Basisrezepte · Verkaufsrezepte ·
  Klasse · KI · Chat · Portfolio) · KPI-Leiste rechts (120 Lieferanten · 6.930 GPs ·
  9.803 LAs · 1.407 Rezepte).
- **Links:** GP-Suche (Cmd+F) · Status-Filter-Select („approved") · „Alle Warengruppen
  (7704)" · WG-Liste 00–15 mit Counts · daneben Sub-Kategorie-Spalte („Warengruppe
  wählen → Sub-Kategorien erscheinen hier").
- **Kopf:** „✨ KI-Vorschlag" (= gp_suggest, NEUE GPs per KI!) · „+ Neues GP" · „Bearbeiten".
- **Tabelle:** Name · Warengruppe · Status (APPROVED-Badge) · LAs · **Lead-Preis**
  (€/kg bzw. €/Stk bzw. €/l!) · **Rezepte** (Verwendungs-Zähler) · **Allergene**
  (rote Pills, z. B. „Sellerie", mehrere möglich: „Gluten Eier Fisch Milch").
- **DetailPanel rechts** (Reihenfolge!): Titel + APPROVED + WG + Sub-Kat + gp_key-Slug ·
  **Flavor-Pairing** (Doku-Status: „Keine Pairing-Dokumentation" + slug) ·
  **Kern-Anker** (★-Chips, Reset/Manuell/✨Autopilot) ·
  **Vault-Domain** (KI-Vorschlag · 100 %, Chip „Obst Kernobst", Reset/✨) ·
  **Natürliche Einheit & Gewicht** (KI-Vorschlag · 100 %, „1 Stück ≈ 180 g",
  Button „Einheiten verwalten") ·
  **Tags** (KI-Vorschlag · 100 %, Chips „Vegan ✓ Vegetarisch ✓ Halal ✓ Laktosefrei ✓
  Glutenfrei ✓", Reset/✨) ·
  **Allergene** („aggregiert aus LAs (3/3)", 14 als gegraute/aktive Worte, ✨Autopilot) ·
  **Zusatzstoffe** („LMIV, aggregiert aus LAs — 2/3 LAs mit Daten", 18 Worte,
  aktiv rot z. B. „Gewachst") ·
  **Nährwerte** („Ø aus LAs, je 100 g — 3/3 LAs mit Daten": Energie kcal, Eiweiß,
  Fett, Kohlenhydrate) ·
  **Verknüpfte Lieferantenartikel (n)** (★ Lead orange hervorgehoben, Bezeichnung,
  Lieferant · Gebinde · ArtNr, Preis + €/kg; Aktionen „✨ KI-Vorschlag" + „+ LA verknüpfen").

## GP-Edit-Modal (M3-09-Abgleich-Basis)

- **Naming-Builder (§6):** Basis-Name* · Zusatz (optional, in Klammern, „z.B. Bio,
  vegan, MSC") · GP-Name* (auto-generiert nach §6) · AUTO-SYNC-Checkbox (GP-Name aus
  Basis + Eigenschaften) · „↓ Vorschlag übernehmen: …" · Warengruppe* (§3, 15 PKL) ·
  Sub-Kategorie (n aus dieser WG).
- **Eigenschaften (§4/§9):** Zustand-Select (frisch/…) · Verarbeitung/Schnitt-Freitext
  (Hint: „Wuerfel 5 mm, sous-vide, filetiert" inkl. Schnittgrößen) · Form (Geometrie)
  Select („— offen —") · Hauptzutat-Slug (monospace).
- **Tags (Diät/Tier):** je —unbekannt—/Ja/Nein-Selects: Bio, Regional (DE), Vegan,
  Vegetarisch, Halal, Laktosefrei, Glutenfrei, Enthält Schwein, Enthält Rind.
  Hinweis: „Initial aus aktiven LAs befüllt (MIN für Diät, MAX für Tier) · zuletzt
  befüllt: <Datum>". Pflegen die Spec-Aggregation aller Rezepte mit diesem GP.
- **Derivat (§11):** Checkbox „Nebenprodukt-Derivat (Schale/Karkasse/Parüren/Saft …)" ·
  Checkbox „Braucht Lieferantenartikel-Mapping" · Default-Garverlust (%) („wird beim
  Hinzufügen als Zutat ins Rezept vorbefüllt — pro Rezept übersteuerbar").
- **Workflow**-Sektion (wie Rezept, s. u.).

## Screen 4/5 — Basisrezept-Editor (M4-Abgleich-Basis)

- **Stammdaten:** Name* (§1.2-Syntax-Hint „Typ: Bezeichnung (Variante), Title Case,
  recipe_key automatisch") · Herkunft/Quelle („nicht im Namen — §1.6") · Checkbox
  BASISREZEPT („verwendbar als Sub-Rezept in Verkaufsrezepten") · Hauptgruppe* (30
  kuratiert) + Kategorie* (n in dieser HG) · KI-Kopf-Buttons „✨ Name putzen" ·
  „✨ Kategorie" · „✨ Fertigung".
- **Zutaten (n):** Kopf-Buttons „🧑‍🍳 Copilot" + „✨ KI-Überarbeiten". Tabelle: Drag · # ·
  Menge · Einheit-Select · Beschreibung/Verknüpfung (GP-Link rot) · Hinweis (+ Lineage
  kursiv „[via per_instance_proposed]", „[phase11 tentative GP]") · Garv. % · EK € (mit
  ⓘ) · edit/×. Add-Zeile: Menge · bis (opt.) · Einheit · Beschreibung ([]) ·
  OPTIONAL-Checkbox · Hinweis („z.B. gewürfelt 5mm, kalt zugeben") · Toggle
  Grundprodukt|Sub-Rezept · GP-Suche (Name oder Slug) · „+ Zutat hinzufügen".
- **KPI-Karten:** Yield kg · EK gesamt € · EK/kg (highlight) · mit Preis n/m ·
  Allergen-Konf. (MEDIUM).
- **14 EU-Allergene** (Pills, aktiv rot „Sellerie") + **18 LMIV-Zusatzstoffe** (Pills:
  aktiv rot umrandet „Antioxidationsmittel/Süßungsmittel", unbekannt gestrichelt kursiv
  „Milcheiweiß/Chinin/Taurin/…").
- **EQUIPMENT:** „✨ Equipment"-Button; Chip-Gruppen BACKEN (Backblech/Kuchenform/
  Tarteform/Brotbackofen) · GAREN (Pfanne/Kasserolle/✓Topf/Wok/Bräter/Kombidämpfer/
  Dampfgarer/Fritteuse/Plancha/✓Induktionsplatte) · HEISSLUFT (Konvektomat/Salamander/
  Holzkohlegrill) · NIEDRIG-TEMP (Sous-Vide-Bad/Bain-Marie/Slow Cooker) · SPEZIAL
  (Räucherofen/Smoker/Vakuumiergerät/Schockfroster/Dehydrator/Eismaschine/Dörrgerät/
  Flambierbrenner) · VORBEREITUNG (Mixer/Stabmixer/Mörser/Pürierstab/Pacojet/Thermomix/
  Chinois/Passiermühle/Mandoline/Fleischwolf/Küchenmaschine/Spritzbeutel) ·
  „2 ausgewählt".
- **EIGENSCHAFTEN:** „✨ Eigenschaften"; Arbeitszeit (min) · Temperatur („heiß
  servieren") · Funktion („Sauce") · Geschmacksrichtung-Select („Herzhaft", via ✨ oder
  manuell) · Fertigungstiefe-Select („Teilfertig", via ✨ Fertigung oder manuell).
- **KI-BESCHREIBUNG** (3–5 Sätze nüchtern, §8.3, „wird in Phase 4 KI-generiert").
- **ZUBEREITUNG:** „✨ Zubereitung"; Markdown-Editor (Schreiben/Vorschau-Tabs, Toolbar
  B/I/H2/H3/Listen/Code; Cmd+B/I/K) — Struktur „## Mise en Place / ## Zubereitung"
  mit nummerierten Schritten.
- **NOTIZEN:** Markdown („§9.1 bleibt erhalten beim Sync").
- **WORKFLOW/STATUS:** Badge (REVIEW) · v1 · zuletzt <ts> · Reviewer-Notiz-Feld („z.B.
  ‚Zutaten geprüft, freigegeben für Foodbook'") · Buttons „→ Approved" „→ Draft"
  „→ Deprecated" · Footer: 🗑 Löschen · Schließen · „Stammdaten speichern".
- **Rechtes Panel (Browser):** Titel + REVIEW + HG-Breadcrumb + Kategorie-Chip + v1 ·
  „✨ Alles anreichern" · „Als Template" · KPI-Karte · 9/10 mit Preis · 15 min ·
  heiß servieren · Sauce · Beschreibung (✨) · Kern-Anker 5 (★-Chips + Verknüpfen + ✨) ·
  Sektor-Eignung 4 (Chips ✨Business/Office · Event/Privat · Crew/Personal ·
  Restaurant/à la carte + „+ manuell…"-Select + 🗑) · Niveau-Eignung 1 (✨Klassisch) ·
  Pairing 26 (Chips Chili/Currypulver/Rauchpaprika/Schwein/… + Verknüpfen + ✨).

## Daraus abgeleitete Roadmap-Nachträge (2026-06-11)

1. **M2-17 Nährwerte-Import** — `nutritional` (127.644) fehlte komplett: GL-08-Quelle!
2. **M3:** gp_suggest („✨ KI-Vorschlag" für NEUE GPs) als Nachtrag an M3-09/M3-10;
   Lead-Preis-Spalte mit Einheiten-Mix €/kg|€/Stk|€/l (M3-02-Detail).
3. **M4:** Equipment-Welt (vocab_kochequipment 40 + recipe_equipment 836 + Chip-Sektion
   + ✨) als Nachtrag an M4-01/02/05; Workflow-Buttons/Reviewer-Notiz an M4-12 bestätigt.

## Nachlieferung 2 (2026-06-11): Basisrezept-Panel-Details · Aroma-Netz · VK-Browser

### Basisrezept-Browser — rechtes Panel (Fortsetzung) + Tabelle
- Listen-Ebene: „✨ Bulk anreichern"-Button; Tabellen-Spalten u. a. Zutaten · Yield (kg,
  3 Nachkommastellen) · Allergen-Konf. (MEDIUM-Badge).
- **VERWANDTE REZEPTE (10)** + „🕸 Aroma-Netz"-Button: Liste mit Kohäsions-Score
  (20/15/14/13/12) · Rezeptname · rechts „/27, /32, …" (Anker-Gesamtzahl des Rezepts).
- **ZUTATEN (n)** read-only: Menge + Einheit (grau) · GP-Link · Lineage kursiv
  („[via per_instance_proposed]", „[phase11 tentative GP]") · EK € je Zeile
  (Leitungswasser 0.00 €, preislos „—").
- **ZUBEREITUNG** gerendert: nummerierte Schritte, H2-Überschriften farbig
  („Zubereitung", „Finish"), Temperatur-/Zeitangaben im Fließtext.
- **DIÄT & SPEZIFIKATION:** grüne ✓-Liste (VEGAN/VEGETARISCH/HALAL/GLUTENFREI/
  LAKTOSEFREI) — Rezept-Ebene = Spec-Aggregation aus GP-Tags (MIN-Logik)!
- **ALLERGENE MEDIUM:** 14 Worte, aktive rot hinterlegt (Sellerie). Danach
  ZUSATZSTOFFE (LMIV) analog.

### Aroma-Netz-Modal (D-7 — Graph-Visualisierung!)
- Titel „AROMA-NETZ: <REZEPT>"; Kopf: Checkbox „Alle Aroma-Brücken (88)" ·
  Select „Pairing-Vorschläge pro Anker: aus (0)" · Hint „Hover über Anker = dessen
  Brücken · Klick auf Rezept = öffnen".
- Layout: Quell-Rezept ZENTRAL (orange, groß) · Ring aus Pairing-Ankern (rosa:
  zitrone, ahornsirup, banane, zimt, butter, weizen, ei, walnuss, ingwer, vanille,
  kaffee, tonkabohne, karamell, schokolade, kokos, salz, muskatnuss, rum-dunkel,
  rohrzucker, pekannuss) · äußerer Ring verwandte Rezepte (grün = Basisrezept,
  blau = VK-Rezept) mit Kanten zu gemeinsamen Ankern.
- Legende: Quell-Rezept · VK-Rezept · Basisrezept · Pairing-Anker · Vorschlag über
  Anker | Aroma-Brücken: **klassisch** (durchgezogen magenta) · **modern**
  (gepunktet) · **kontrast** (gepunktet cyan) — GL-10-Kanten-Typen!

### VK-Browser (D-6, Screen „Verkaufsrezepte")
- Links: VK-Suche · Geschmacks-Pills (Süß/Herzhaft/Neutral) · „Alle Hauptgruppen" ·
  **VK-HAUPTGRUPPEN mit Codes** (16): [APE] Apéro & Welcome · [AMU] Amuse-Bouche ·
  [FIN] Fingerfood · [VOR] Vorspeise · [SUP] Suppe & Eintopf · [ZWG] Zwischengang ·
  [HG] Hauptgang · [BEI] Beilage · [DES] Dessert · [KAE] Käse · [ALC] À la carte ·
  [BVK] Barverkauf · [SNK] Konferenz-Snack · [BRO] Brot & Backwaren ·
  [ALL] Allergiker-Essen · [GET] Getränke — je mit Count + Kategorie-Spalte daneben.
- Kopf: „✨ KI-Rezept" (= VK-Generator M6-06!) · „+ Neues Verkaufsrezept". Subtitle:
  „Speisen mit VK-Preis. Zutaten = Grundprodukte und/oder Basisrezepte. Live-Marge
  aus EK × Aufschlagsklasse."
- Tabelle: Name (Komponenten-Syntax „HG: Rinderfilet | Rotwein-Jus | Kartoffelgratin
  | Brokkoli") · Hauptgruppe · Kategorie · Geschm.-Pill · Status (DRAFT) · VK netto ·
  Zutaten · Allergen-Konf. (HIGH).
- **DetailPanel:** Titel + kursive Komponentenliste · DRAFT + HG + Fleisch ·
  „✨ Klassifizieren" · **VERKAUFT ALS 1.0 Stück · ≈ 420 g pro Stück · Yield 0.42 kg**
  (Orange-Box) · KPI-Karten: EK GESAMT · VK NETTO (MANUELL) · **VK BRUTTO**
  (Highlight) · **WARENEINSATZ %** · Reihe 2: VK NETTO/STÜCK · VK BRUTTO/STÜCK ·
  Formel-Klartext: „ALC · À la Carte · VK = EK × (1 + 420%) · brutto × (1 + 19% MwSt)" ·
  BESCHREIBUNG (✨) · MARKETING (✨) ·
  **KULINARISCHE KOHÄRENZ 95 % + Badge „KLASSISCHER TELLER" (KI-URTEIL)** mit
  Begründung, Datum + Modell, „Erneut prüfen" · darunter „WAS HEBT DEN TELLER?
  KI-VORSCHLAG".

## Nachträge aus Nachlieferung 2

4. **M5:** Aroma-Netz-GRAPH (interaktive Visualisierung mit Brücken-Typen
   klassisch/modern/kontrast, Hover/Klick, Vorschlags-Modus) fehlte als Paket → M5-07 neu.
5. **M6:** VK-Hauptgruppen-Taxonomie (16 Codes [APE]…[GET]) explizit in M6-01;
   Geschmacks-Filter-Pills in M6-03; **Kulinarische-Kohärenz-Check** (KI-Urteil mit
   Score/Label/Begründung/Re-Run) + **„Was hebt den Teller?"** als KI-Features → M6-Nachtrag.
6. **M4:** Diät-&-Spezifikations-Aggregation (Rezept-Ebene aus GP-Tags, MIN) als
   sichtbare Panel-Sektion → M4-03/05-Nachtrag.
