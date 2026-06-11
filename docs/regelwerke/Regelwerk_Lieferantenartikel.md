> **EINGEFRORENE KOPIE (2026-06-10)** — Quelle: Cooking-Jarvis-Vault `07_WISSEN/07.01_Lebensmittel_und_Gastronomie/`. Normative Referenz für den Food-Alchemist-Spec-Korpus. Änderungen NUR in der Vault-Quelle, dann neu einfrieren.

---
typ: Regelwerk
scope: Lieferantenartikel-Mapping
version: 1.0
stand: 2026-04-29
owner: Dominique Beutin
gilt_fuer: [Skripte_24, Skripte_26, Skripte_29, Skripte_43, Skripte_46, Cooking_Jarvis, alle_LA_Tasks]
referenziert: [Regelwerk_Grundprodukte]
tags: [regelwerk, lieferantenartikel, mapping, wawi_1494, necta]
---

# Regelwerk Lieferantenartikel — LA↔GP-Mapping

> **Cooking-Jarvis-Pflicht:** Bei jeder Aufgabe, die ein Lieferantenartikel-Mapping betrifft (Anlage, Match-Vorschlag, Disambiguierung, Lead-LA-Setzung, Mapping-Audit, Necta-Sync), ZUERST dieses Regelwerk lesen, dann ggf. das `[[Regelwerk_Grundprodukte]]` fuer Naming-/Warengruppen-Fragen.
>
> Das Regelwerk codifiziert die heute schon implementierte Matching-Logik in `wawi_1494.sqlite` (Tabelle `wawi_gp_la`) und in den Skripten 24/26/43/46. Wenn Skript-Code von diesem Regelwerk abweicht, ist beides zu pruefen — Regelwerk oder Skript anpassen, niemals divergieren lassen.

---

## §1 Ziel & Geltungsbereich

Dieses Regelwerk regelt die **Verknuepfung von Lieferantenartikeln (LAs) zu Grundprodukten (GPs)**. Es definiert:

- nach welchen Schluesseln ein LA einem GP zugeordnet wird (§3, §4)
- ab welcher Konfidenz ein Skript eigenstaendig matchen darf (§5)
- wie Mehrdeutigkeit aufgeloest wird (§6)
- wie der Lead-LA pro GP bestimmt wird (§8)
- wie Mapping-Ergebnisse in den Vault zurueckgespiegelt werden (§9)

**Abgrenzung zum GP-Regelwerk:**

| Frage | Regelwerk |
|---|---|
| Was ist ein Grundprodukt, wie heisst es, in welche Warengruppe? | `[[Regelwerk_Grundprodukte]]` |
| Welcher Lieferantenartikel gehoert zu welchem Grundprodukt? | DIESES Regelwerk |
| Welche Allergene/Preise hat das GP? | Quelle ist LA, Aggregation siehe §10 |
| Welcher Necta-Export-Spalten? | GP-Regelwerk §17 + DIESES §9 |

**Quelle der Wahrheit:** `00_SYSTEM/00.04_Scripts/_Data/wawi_1494.sqlite`, Tabelle `wawi_gp_la`. Vault-YAML ist gespiegelte Sicht, NICHT Master.

---

## §2 Datenmodell

Drei Ebenen, eine Mapping-Tabelle:

```
wawi_grundprodukte (5.161 GPs)
        ↑
        │  gp_id (FK)
        │
   wawi_gp_la  ──── match_method, bevorzugt, ausgelistet, original_*
        │
        │  supplier_item_id (NULL = ungemappt)
        ↓
  supplier_items (264.452 LAs aus Necta One 1494)
        │
        │  SupplierId (FK)
        ↓
       suppliers (162 Lieferanten)
```

**`wawi_gp_la`-Schluesselfelder** (vereinfacht, vollstaendiges Schema in Skript 46):

| Spalte | Pflicht | Bedeutung |
|---|---|---|
| `gp_id` | ja | FK auf Grundprodukt |
| `supplier_item_id` | nein | FK auf Lieferantenartikel; NULL = bewusst ungemappter Slot |
| `bevorzugt` | nein | 1 = Lead-LA fuer dieses GP (siehe §8) |
| `ausgelistet` | nein | 1 = LA ist beim Lieferant deaktiviert, nicht aus Mapping entfernen |
| `match_method` | ja | Wie wurde gemappt (siehe §3, §12) |
| `original_lieferant`, `original_artikelnummer`, `original_artikelbeschreibung`, `original_menge`, `original_einheit`, `original_ek_preis` | nein | Snapshot beim Mapping-Zeitpunkt fuer Audit/Drift-Erkennung |

**Vault-Spiegel im GP-YAML** (Schreibrichtung: SQL → YAML, NIEMALS umgekehrt; siehe §9):

```yaml
wawi_gp_id: 3518
wawi_supplier_items: [34366883, 34366884, ...]
default_supplier_item_id: 34366883
default_lieferant: Rudolf Achenbach GmbH & Co. KG
n_lieferantenartikel: 3
n_aktive_lieferanten: 2
preis_default_netto: 3.45
preis_min_netto: 3.10
preis_max_netto: 3.80
preis_strategie: default
lieferantenartikel:
  - '[[Rudolf_Achenbach_GmbH_&_Co._KG]] (Item: 34366883, ArtNr: 88069, bevorzugt)'
  - '[[Transgourmet]] (Item: 34366884, ArtNr: T-2244)'
```

---

## §3 Match-Schluessel-Hierarchie

Wenn ein LA einem GP zugeordnet werden soll, wird die folgende Reihenfolge **strikt** durchlaufen. Erste Stufe mit Treffer gewinnt.

### §3.1 — `artno+supplier` (Primaer)

`(supplier_items.article_number, supplier_items.SupplierId)` ist innerhalb eines Lieferanten eindeutig. Wenn ein bereits gemapptes LA mit identischer ArtNr+Lieferant in `wawi_gp_la` existiert, wird der GP des bestehenden Eintrags uebernommen.

- **Konfidenz:** HIGH
- **Heutiger Anteil:** 98,6 % aller 7.505 Mappings
- **Skript:** Skript 46 baut Index `idx_items_artno`; Skript 24 nutzt das Tupel
- **Voraussetzung:** `article_number` ist gepflegt (~95 % der LAs)

### §3.2 — `ean_packaging` (Sekundaer)

Wenn ArtNr fehlt oder ambig ist: `supplier_items.EanPackagingUnit` gegen GP-bekannte EANs matchen.

- **Konfidenz:** HIGH bei 1:1-Match, MED bei EAN-Duplikaten (mehrere Items teilen dieselbe EAN, z.B. wenn Lieferanten Hersteller-EAN durchreichen)
- **Heutige Befuellung:** ~10 % der LAs haben EAN_packaging
- **Skript:** Skript 26 (EAN-First-Logik), Index `idx_items_ean_pkg`

### §3.3 — `ean_ordering` (Tertiaer)

Fallback wenn nur `EanOrderingUnit` gepflegt (Bestell-EAN, nicht Verpackungs-EAN).

- **Konfidenz:** MED — Bestell-EAN identifiziert die Verpackungseinheit, nicht zwingend das Produkt eindeutig
- **Heutige Befuellung:** ~30 % der LAs

### §3.4 — `name_fuzzy` (Quartaer)

Token-Overlap-Score nach Skript 24 `score_match()` (Zeile 208-252):

- exakter Match (norm-name == norm-gp): Score 1,00
- Wortgrenz-Containment (GP-Name als ganzes Wort im LA-Text, min. 4 Zeichen): 0,70 + Anteil
- Reverse-Containment (LA im GP): 0,60 + Anteil
- Token-Overlap (alle GP-Tokens im LA): 0,55 + Coverage
- darunter: Ablehnung

Stopwords (`ca`, `kg`, `g`, `ml`, `l`, `stk`, `und`, `mit` etc.) werden vorher entfernt.

- **Konfidenz:** LOW per Default, MED ab Score ≥ 0,80, HIGH ab Score ≥ 0,95 UND Allergen-Konsistenz (siehe §10)

### §3.5 — `manual` (User-Override)

Vom User explizit gesetzter Match. Ueberschreibt alle automatischen Matches und ist sticky (siehe §14).

- **Konfidenz:** HIGH (per Definition)
- **Marker:** `wawi_gp_la.match_method = 'manual'`

### §3.6 — `no_match` (bewusst unzugeordnet)

LA bleibt im System, aber `gp_id` wird mit einem Sammel-GP `wawi_gp_id = 0` (Sentinel) verknuepft, oder der Eintrag wird mit `supplier_item_id = NULL` als bewusster Platzhalter gefuehrt. Begruendung Pflicht in `original_artikelbeschreibung`.

Typische Begruendungen: `convenience_pending_v2` (siehe §13), `kein_passendes_gp` (Lieferant fuehrt Sortiment ausserhalb unserer Auswahl), `pruefen_v2` (Edge Case).

---

## §4 Necta-Quellfelder fuer Matching

Welche `supplier_items`-Felder werden als Match-Schluessel verwendet, welche nicht.

| Necta-Feld | Match-Rolle | Befuellung | Hinweis |
|---|---|---|---|
| `Id` | Primary Key, NIE Match-Key | 100 % | Wawi-Item-ID, eindeutig pro Necta-Eintrag |
| `SupplierId` | Tupel-Key mit `ArticleNumber` | 100 % | Pflicht fuer §3.1 |
| `ArticleNumber` | Primaer-Match (§3.1) | ~95 % | Lieferanten-interne Nummer |
| `EanPackagingUnit` | Sekundaer-Match (§3.2) | ~10 % | Hersteller-EAN auf Verpackung |
| `EanOrderingUnit` | Tertiaer-Match (§3.3) | ~30 % | Bestell-EAN |
| `Designation` | Fuzzy-Name (§3.4) | 100 % | Lieferantenbezeichnung, oft mit Verpackungsangabe |
| `Brand` | NICHT Match | 2 % | Zu schlecht gepflegt |
| `Manufacturer` | NICHT Match | 0 % | In Necta praktisch leer |
| `MarketingName` | NICHT Match | <5 % | Nicht zuverlaessig |
| `Origin` | NICHT Match | optional | Nur fuer Anzeige im Vault |
| `UnitId` | Plausibilitaets-Filter (§6.4) | 100 % | 1001 = kg, 1004 = l, 1005 = Stk; muss zur GP-Kalkulationseinheit passen |

**Regel:** `Brand` und `Manufacturer` werden NICHT als Match-Keys verwendet, weil Necta sie nicht systematisch pflegt. Marken-Pflicht im GP-Naming (siehe GP-Regelwerk §5) bleibt davon unberuehrt — sie greift nur fuer GPs, nicht fuer LA-Matching.

---

## §5 Auto-Match-Schwellen

Ab wann darf ein Skript eigenstaendig einen Eintrag in `wawi_gp_la` schreiben (ohne User-Bestaetigung)?

Die heutige Praxis aus `auto_match_report.md` wird hier verbindlich:

| Bedingung | Schwelle | Quelle |
|---|---|---|
| Token-Coverage-Score | ≥ 95 (auf Skala 0-100) | Skript 24/26 |
| Score-Gap (Top1 − Top2) | ≥ 15 | Eindeutigkeit |
| Allergen-Konsistenz | pass (siehe §10) | Skript 24 |
| Plausibilitaets-Check (Klasse/Stueckgewicht) | pass (siehe §6) | Skript 24/26 |

**Wenn alle 4 erfuellt:** Auto-Insert mit `match_method = 'auto_eindeutig_v1'`.

**Wenn 1+ verletzt:** kein Insert. Stattdessen Eintrag in `auto_match_vorschlaege.csv` fuer manuelle Review, Status `needs_manual_review`.

**Schwellen-Aenderung** ist ein Governance-Eingriff (siehe §15) und erfordert: Aenderung im Regelwerk + Aenderung im Skript + Eintrag im `_Bootstrap_Log.md`.

---

## §6 Disambiguierung bei Mehrdeutigkeit

Wenn die Match-Strategie mehrere GP-Kandidaten liefert (heute: 120 Faelle `artno_only_ambiguous(2-9)` in der DB), wird die folgende Filter-Kette angewendet — solange bis nur noch ein Kandidat uebrig ist.

1. **Lieferant-Filter** — bei `artno_only_ambiguous` ist das Tupel mit `SupplierId` per Definition eindeutig. Wenn der ambige Match aus historischer Multi-Lieferant-Verwendung stammt, wird der Kandidat des aktuellen Lieferanten gewaehlt.
2. **Allergen-Konsistenz-Filter** — Kandidaten, deren Allergen-Profil mit dem LA inkompatibel ist (siehe §10), fallen raus.
3. **Klassen-Filter** — Tokens in `Designation` bestimmen die GP-Klasse: `TK`, `frisch`, `geraeuchert`, `mariniert` → muss zur GP-Klasse (`OBST_`/`GEM_`/`FL_ROH_`/`FISCH_`/`MOPRO_`/...) passen.
4. **Stueckgewicht-/Einheit-Filter** — wenn LA Stueckgewicht im Namen (z.B. "180g", "Filet 6x200g") und GP eines hat, muessen beide matchen. `UnitId` muss zur GP-`kalkulationseinheit` passen (kg/l/Stk; siehe GP-Regelwerk §15.2).
5. **Manuelle Aufloesung** — wenn 1-4 nicht eindeutig: `match_method = 'needs_manual_review'`, Eintrag in Reviewer-Queue. KEIN automatischer Default-Match auf "ersten Kandidaten".

---

## §7 LA-Granularitaet: 1 Necta-Artikelnummer = 1 LA

Verbindliche Regel: **Jeder Necta-Eintrag mit eigener `(ArticleNumber, SupplierId)` ist ein eigener LA.** Wir folgen dem Lieferanten-Katalog 1:1, keine Eigen-Konsolidierung.

**Konsequenzen:**

- Andere Verpackungsgroesse (Olivenoel 500 ml vs. 1 l vs. 5 l) = anderer LA, weil der Lieferant eigene ArtNrn vergibt — alle drei zeigen aber auf dasselbe GP `Olivenoel: nativ extra`
- Andere Stueckgewicht-Variante (Lachsfilet 150 g vs. 200 g) = anderer LA UND anderer GP (siehe GP-Regelwerk §7.1)
- Aktion vs. Listenpreis = SELBER LA (nur `aktionspreis`/`aktion_von`/`aktion_bis` unterscheiden sich)
- Auslistung beim Lieferant = SELBER LA, Flag `wawi_gp_la.ausgelistet = 1`. Mapping bleibt fuer Historie/Reaktivierung erhalten.
- Lieferant aendert ArtNr (z.B. neuer Sortimentscode) = neuer LA. Alter LA bekommt `ausgelistet = 1`. Neuer LA bekommt eigenen Match-Eintrag.

**Anti-Regel:** Keine `verpackungs_varianten[]`-Sammelarrays. Jeder LA bleibt atomar.

---

## §8 Lead-LA / `bevorzugt`-Flag

Pro GP wird genau ein LA mit `wawi_gp_la.bevorzugt = 1` ausgezeichnet. Dieser Lead-LA liefert:

- `default_supplier_item_id` und `default_lieferant` im GP-YAML
- `preis_default_netto` (gespiegelt von `supplier_items.ek_preis` des Lead-LA)
- Default-Allergen-Profil fuer GP-Aggregation (siehe §10)

**Setzungs-Heuristik (idempotent ausfuehrbar):**

1. **Aktivitaets-Filter:** `ausgelistet = 0`
2. **Vollstaendigkeits-Filter:** `ek_preis` vorhanden UND alle 14 EU-Allergene gepflegt (kein `unbekannt`)
3. **Aktualitaets-Filter:** `letzte_aktualisierung` innerhalb der letzten 365 Tage
4. **Tiebreaker 1:** niedrigster `ek_preis / mg_ae` in GP-Kalkulationseinheit (kg/l/Stk-normiert)
5. **Tiebreaker 2:** alphabetisch nach Lieferantenname

**Manueller Override:** `wawi_gp_la.bevorzugt_lock = 1` setzt den Lead-LA fest. Skript-Reruns ignorieren diesen Eintrag bei der Heuristik. Audit-Output: GPs ohne `bevorzugt` (alle Kandidaten in den Filtern rausgefallen) → Status `needs_lead_la_review`.

> **Hinweis Kunden-Ebene:** Die Kunden-Lieferanten-Praeferenz (siehe Memory `project_lieferanten_pref_pro_kunde.md`) ueberschreibt diesen System-Default pro Kunde fuer Kalkulationen. Das beruehrt das hier definierte System-Lead-LA NICHT — es bleibt der Default fuer alle GPs ohne Kunden-Kontext (Foodbook-Drafts, Stamm-GP-Anzeige, Necta-Export).

---

## §9 Vault-Sync: SQL → GP-YAML

**Schreibrichtung ist eine Einbahn:** SQL ist Master, YAML ist Spiegel. Das Sync-Skript ueberschreibt YAML-Felder bei jedem Lauf, manuelle Edits an gespiegelten Feldern gehen verloren.

**Gespiegelte Felder im GP-YAML:**

```yaml
wawi_gp_id: <gp_id>                           # konstant
wawi_supplier_items:                          # alle aktiven Items (ausgelistet=0)
  - <item_id_1>
  - <item_id_2>
default_supplier_item_id: <bevorzugter>       # genau eins, aus §8
default_lieferant: <name>                     # aus suppliers
n_lieferantenartikel: <count>                 # Anzahl aktive
n_aktive_lieferanten: <distinct supplier_id>
preis_default_netto: <ek_preis_des_lead_la>
preis_min_netto: <min ueber alle aktiven>
preis_max_netto: <max ueber alle aktiven>
preis_strategie: default                       # default | guenstigster | durchschnitt
lieferantenartikel:                            # menschenlesbare Liste
  - '[[Lieferant_Name]] (Item: <id>, ArtNr: <nr>, bevorzugt)'
  - '[[Lieferant_Name]] (Item: <id>, ArtNr: <nr>)'
```

**Zeilenformat fuer `lieferantenartikel`:**
`[[<Lieferant_Slug>]] (Item: <wawi_id>, ArtNr: <article_number>[, bevorzugt])`

- `bevorzugt`-Suffix nur beim Lead-LA
- Ausgelistete LAs werden NICHT in der Liste gefuehrt, bleiben aber in `wawi_gp_la` mit `ausgelistet = 1` erhalten

**Nicht-gespiegelte Felder** (bleiben Vault-only): `aroma`, `textur`, `typische_kochtechniken`, `referenzen`, `tags`, `synonyme` (siehe GP-Regelwerk §17).

**Verbot:** Direktes Editieren von `wawi_supplier_items`, `default_supplier_item_id`, `lieferantenartikel`, `preis_*` im YAML. Aenderungen ausschliesslich ueber SQL-Manipulation + Sync-Rerun.

---

## §10 Allergen-Aggregation & Konflikt-Behandlung

Wenn mehrere LAs auf ein GP zeigen, koennen ihre Allergen-Profile divergieren (z.B. Hersteller A garantiert glutenfrei, Hersteller B nur "Spuren moeglich"). Skript 24 erkennt das via `allergen_fingerprint()`.

**Aggregations-Regel:** ALL-MAXIMAL pro Allergen-Key, Hierarchie:

```
enthalten  >  spuren  >  nicht_enthalten  >  unbekannt
```

(konsistent mit GP-Regelwerk §16). Wenn ≥2 LAs unterschiedliche Werte fuer denselben Key haben, gewinnt der hoechste Rang.

**Konfidenz-Logik:**

| Situation | `allergene_konfidenz` |
|---|---|
| Alle aktiven LAs mit identischem Profil | HIGH |
| LAs unterscheiden sich nur in `unbekannt` vs. konkreter Wert | HIGH (konkreter gewinnt) |
| LAs unterscheiden sich auf gleicher Hierarchie-Stufe | MED |
| Konflikt `enthalten` ↔ `nicht_enthalten` (kein Spuren-Mittelweg) | LOW + GP-Status `needs_allergen_review` |
| GP hat keinen LA mit gepflegten Allergenen | NONE |

**Konflikt-Logging:** Allergen-Konflikte werden in `allergen_konflikte.csv` geloggt (Skript 24/43). GPs mit `needs_allergen_review` blockieren weitere Auto-Matches auf neue ambig-allergene LAs (siehe §5).

---

## §11 Anti-Patterns — diese Matches NIE

Konkrete Faelle, die NICHT als Match akzeptiert werden duerfen — abgeleitet aus heutigen Fehl-Matches und Domain-Wissen:

- **Aequivalenz-Tarnung** — LA "Olivenoel 500 ml" matcht NICHT auf GP "Olivenoel: nativ extra", wenn der Lieferant "raffiniert" liefert. Klassen-Filter §6.3 muss greifen.
- **Marken-Tarnung** — Generische LAs duerfen NICHT auf marken-pflichtige GPs (siehe GP-Regelwerk §5) gemappt werden. Beispiel: generische "Triple Sec"-LAs matchen NICHT auf GP "Cointreau".
- **Convenience-Tarnung** — LAs mit eindeutigen Convenience-Markern (Tokens: `Pizza`, `Pfannkuchen`, `gefuellt`, `mariniert`, `gewuerzt`, `paniert`, `fertig`, `Auflauf`, `Gratin`, `Wrap`, `Bowl`) duerfen NICHT auf Roh-GPs gemappt werden. Stattdessen `match_method = 'no_match'` mit Begruendung `convenience_pending_v2` (siehe §13).
- **Synonym-Falschmatch** — `Bries` (Kalbs-Innerei) NIE auf `Brie` (Kaese), `Triple Sec` NIE auf `Triple Chocolate`, `Pfeffer` NIE auf `Paprika`. Pflichtcheck gegen `Cross_Cutting/Anti_Marker.md`.
- **Verpackungs-Verwechslung** — Stueckgewicht-LAs (Lachsfilet 200 g, Filet 6x180 g) duerfen NICHT auf gewichts-offene GPs (Lachsfilet kg) ohne `kalkulationseinheit`-Pruefung gemappt werden.
- **Cross-Klassen-Match** — LA aus `FL_ROH_` darf NIE auf GP aus `FISCH_` oder `MOPRO_` gemappt werden, auch wenn Token-Overlap hoch ist (Beispiel: "Lachsschinken" → ist Schinken, nicht Lachs).
- **EAN-Bluff** — Wenn zwei Lieferanten dieselbe EAN durchreichen aber Designations widerspruechlich sind, KEIN Auto-Match auf EAN-Basis. Eintrag `needs_manual_review`.

---

## §12 Status-Flags & `match_method`-Werte

Vollstaendige Liste der zulaessigen `wawi_gp_la.match_method`-Werte. **Wenn ein neuer Wert in der DB auftaucht, ist die Tabelle hier zu erweitern (Pflicht-Update).**

| `match_method` | Bedeutung | Einleitende Stufe (§3) | Aktion |
|---|---|---|---|
| `artno+supplier` | Sauber gemappt via Primaer-Tupel | §3.1 | keine |
| `ean_packaging` | Via Verpackungs-EAN gemappt | §3.2 | regelmaessig EAN-Duplikate pruefen |
| `ean_ordering` | Via Bestell-EAN gemappt | §3.3 | bei naechster Audit-Welle Plausibilitaet checken |
| `auto_eindeutig_v1` | Skript-Auto-Match nach §5 | §3.4 + §5 | Sample-Audit alle 30 Tage |
| `manual` | User-Override | §3.5 | sticky, nie ueberschreiben (siehe §14) |
| `no_artno` | LA ohne ArtNr, Fallback gemappt | §3.4 | Lieferant kontaktieren, ArtNr-Pflege einfordern |
| `artno_only_ambiguous(N)` | Mehrdeutig, N Kandidaten | §6 | Disambiguierung-Filterkette anwenden |
| `artno_only_unique` | ArtNr eindeutig, kein Lieferant-Match noetig | §3.1 | keine |
| `needs_manual_review` | Skript hat keine sichere Entscheidung | §6.5 | Reviewer-Queue, Frist 14 Tage |
| `no_match` | Bewusst unzugeordnet | §3.6 | Begruendung in `original_artikelbeschreibung` Pflicht |
| `convenience_pending_v2` | Convenience-LA, ausgeklammert | §13 | wartet auf v2-Regelwerk |

**Audit-Query** (gehoert in den Quartals-Review):

```sql
SELECT match_method, COUNT(*) AS n
FROM wawi_gp_la
GROUP BY match_method
ORDER BY n DESC;
```

Jeder Wert in der Ergebnis-Spalte `match_method` MUSS in der obigen Tabelle stehen. Sonst: Regelwerk-Update Pflicht.

---

## §13 Convenience-LAs — Platzhalter v1

Convenience-LAs sind zubereitete Necta-Artikel ohne klares Roh-GP-Aequivalent (z.B. "Spaghetti Pesto Garnelen", "Nuss-Nougat-Pfannkuchen", "Lasagne Bolognese 350 g portioniert"). Sie machen einen substanziellen Anteil der heute 256.953 ungemappten LAs aus.

**Behandlung in v1:**

- Marker-basierte Erkennung (siehe §11 "Convenience-Tarnung")
- `match_method = 'convenience_pending_v2'`
- `original_artikelbeschreibung` enthaelt den vollen Designation-Text fuer spaeteres Re-Mapping
- KEIN Mapping auf Roh-GPs, auch nicht "weichen" Match auf Hauptkomponente

**v2-TODO** (geklaert im naechsten Sprint, 2026-04-29):

- Eigenes GP-Klassen-System fuer Convenience? (z.B. neue Klasse, NICHT der alte `CONV_`-Praefix der bereits verworfen ist)
- Multi-Komponenten-GP-Modell?
- Pro-Kunde-Convenience-Whitelist (manche Kunden nutzen Convenience, andere nie)?

Bis v2 stehen Convenience-LAs auf "Park" — sie blockieren die Pipeline nicht.

---

## §14 Re-Mapping-Regeln

Wann darf ein bestehender Mapping-Eintrag in `wawi_gp_la` geaendert werden?

| Bestehender Status | Re-Mapping erlaubt? |
|---|---|
| `match_method = 'manual'` | NEIN (User-sticky, nur via expliziten User-Override) |
| `bevorzugt_lock = 1` (siehe §8) | NEIN fuer `bevorzugt`-Flag, JA fuer `gp_id`-Aenderung |
| `match_method = 'auto_eindeutig_v1'` | JA bei Skript-Rerun, wenn neuer Score hoeher ODER wenn Quell-LA aktualisiert |
| `match_method = 'artno+supplier'` | JA wenn `(article_number, supplier_id)` sich geaendert hat (selten, aber moeglich bei Lieferant-Sortimentswechsel) |
| `match_method = 'no_match' / 'convenience_pending_v2'` | JA wenn neuer GP entsteht der passt |
| `ausgelistet = 1` | NEIN (historischer Eintrag, nur Reaktivierung wenn LA wiederkommt) |

**Aenderungs-Audit:** Aenderungen sollten in `wawi_gp_la_history` (zu erstellen, optional) protokolliert werden — Felder: `id`, `gp_id`, `supplier_item_id`, `old_match_method`, `new_match_method`, `changed_at`, `changed_by` (skript_name oder user). Aktuell nicht implementiert; v2-TODO.

---

## §15 Governance

**Owner:** Dominique Beutin (Digital F&B Manager).

**Skript-Verantwortung:**

| Skript | Funktion | Owner |
|---|---|---|
| `00_SYSTEM/00.04_Scripts/24_cluster_artikel_grundprodukte.py` | Token-Score-Matching | Dominique |
| `00_SYSTEM/00.04_Scripts/26_ean_allergen_clustering.py` | EAN-First-Matching | Dominique |
| `00_SYSTEM/00.04_Scripts/29_lieferanten_coverage_audit.py` | Read-only Audit | Dominique |
| `00_SYSTEM/00.04_Scripts/43_propagate_allergene_la_to_gp.py` | Allergen-Aggregation `la_union` | Dominique |
| `00_SYSTEM/00.04_Scripts/46_build_master_db_1494.py` | DB-Schema-Quelle | Dominique |

**Review-Cycle:**

- **Monatlich:** Audit-Sample 100 Random-Mappings, Stichprobe gegen Anti-Patterns §11 pruefen
- **Quartalsweise:** vollstaendiger `match_method`-Verteilungs-Check (§12-Audit-Query) + ungemappte LAs neu durchrechnen
- **Halbjaehrlich:** Schwellen-Review (`min_score = 95`, `min_gap = 15`) — passen die Werte noch zur Datenqualitaet, oder zu konservativ/zu liberal?

**Aenderungs-Prozess** fuer dieses Regelwerk:

1. Aenderung im Markdown
2. Version-Bump im YAML-Header (v1.0 → v1.1)
3. Eintrag im `00_SYSTEM/00.05_Referenz/Lebensmittel_Wissen/_Bootstrap_Log.md`
4. Wenn Schwelle/Match-Logik geaendert: Skript-Code anpassen ODER Skript-Code anpassen + Regelwerk anpassen (nie nur eines)

---

## §16 Verifikation & Test-Run

Nach jeder substanziellen Skript- oder Regelwerk-Aenderung:

1. **Stichprobe 20 Random-Mappings** aus `wawi_gp_la` ziehen, mit Regelwerk-§§ erklaeren. Mindestens 18/20 muessen unter eine §-Regel fallen. Wenn nicht: Regelwerk-Luecke.
2. **10 ungemappte LAs ziehen**, mit Regelwerk-§§ einer Strategie zuordnen (oder bewusst als `no_match`/`convenience_pending_v2` klassifizieren). Wenn keine Klassifizierung moeglich: §3 oder §13 erweitern.
3. **Skript-Konsistenz** — alle in den Skripten 24/26/43/46 hartcodierten Schwellen pruefen, ob sie hier codifiziert sind. Divergenz = Pflicht-Korrektur.
4. **Audit-Query §12** ausfuehren, alle `match_method`-Werte gegen §12-Tabelle abgleichen.

---

## §17 Offene Punkte (TODO)

| TODO | Stand | Notiz |
|---|---|---|
| Convenience-Strategie v2 | offen | siehe §13, naechster Sprint nach 2026-04-29 |
| EAN-Duplikate-Behandlung | offen | aktuell keine Logik fuer "selbe EAN, verschiedene Produkte" |
| `manufacturer` als Match-Key fordern | offen | Lieferanten-Onboarding-Anforderung; aktuell zu 100 % NULL in Necta |
| `wawi_gp_la_history`-Tabelle | offen | Versionierung von Mapping-Aenderungen, siehe §14 |
| 5.161 GPs vs. 256.953 ungemappte LAs | aktiv | Naechster Mapping-Sweep auf Basis dieses Regelwerks |

---

## §18 Merksetze

1. **SQL ist Master, YAML ist Spiegel.** Nie umgekehrt.
2. **`(article_number, supplier_id)` schlaegt alles.** EAN ist Plan B, Name-Fuzzy ist Plan C.
3. **Marke und Hersteller sind KEINE Match-Keys** — Necta pflegt sie nicht.
4. **Auto-Match nur ab Score 95 + Gap 15 + Allergen-OK + Plausibilitaet.** Sonst Reviewer-Queue.
5. **Lead-LA-Heuristik ist System-Sache.** Kunden-Praeferenz greift eine Ebene hoeher.
6. **Convenience-LAs nie auf Roh-GPs.** `convenience_pending_v2` ist die einzige zulaessige Antwort in v1.
7. **Allergen-Hierarchie:** `enthalten > spuren > nicht_enthalten > unbekannt`. Konflikt = `MED` oder `LOW`, nie `HIGH`.
8. **Ausgelistete LAs bleiben im Mapping.** `ausgelistet = 1`, nicht loeschen.
9. **Bei Mehrdeutigkeit: Filterkette §6, dann Reviewer-Queue.** NIE "ersten Kandidaten nehmen".

---

## Changelog

- **2026-06-02 v1.1** — **§8 Lead-LA-Heuristik endlich CODIERT** (war bis dato nur Prosa; Code machte „erster LA gewinnt"). `pick_lead_la` (commands.rs) wählt: 1. **Stamm-Lieferant** der die GP-Warengruppe abdeckt (neue `stamm_lieferant`/`stamm_lieferant_wg`-Matrix, LA-First-Spec v1.5/1.6), 2. §8-Tiebreaker (aktiv → hat Aktiv-Preis → günstigster Preis-pro-Einheit → alphabetisch). Eingehängt in alle LA→GP-Link-Pfade + Commands `apply_lead_la` / `recompute_all_lead_las` (+ Skript 213 für den Bestand). `bevorzugt`-Flag-Lock + manueller Override (`set_gp_lead_la`) unverändert. Stamm-Präferenz ist die „Kunden-Präferenz eine Ebene höher" aus §20.5.
- **2026-04-29 v1.0** — Initialversion. Codifiziert die heute in `wawi_1494.sqlite` und Skripten 24/26/43/46 implementierte Matching-Logik. Schwellen `min_score = 95`, `min_gap = 15` aus `auto_match_report.md` uebernommen. Convenience-Strategie ausgeklammert (§13, v2). User-bestaetigte Designentscheidungen: Hybrid-Detail, 1 Necta-ArtNr = 1 LA, GPs global, Lead-LA = Systemfrage.