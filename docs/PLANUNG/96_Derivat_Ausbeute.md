# Spec 96 · Derivate mit Ausbeute: Saft, Abrieb & Co. über die Mutter einkaufen

Stand 2026-11-05 · Entwurf · Auftraggeber Dominique · Kurator prüft und merged · Anlass: Pilot-Import KOCHBuch_2026, Zeile
„Limette (Saft), 1 Stück“ (Rezept 49, Chimichurri)

## Warum

Ein Rezept nennt oft den Teil einer Frucht: „Saft von 1 Limette“, „Abrieb einer Zitrone“. Dominique 05.11.: „Limettensaft hätte ich
als Derivat als Grundprodukt angelegt.“ Die Mutter (Limette) wird eingekauft, das Rezept rechnet mit dem Saft. Nach Regelwerk GP §11.2
ist das ein Nebenprodukt-Derivat (`is_derivat = 1`, `derivat_von_gp_id` = Mutter, `requires_la = 0`).

Heute geht das nicht sauber:

| Bereich | Stand heute (Code-Befund 05.11.) |
|---|---|
| Allergene | ✔ LIVE von der Mutter (`GpAggregateService` 58–60, `source = derivat`) |
| Anlage | ✔ `LaFirstGpService::mintDerivat` (automatisch, wenn kein LA passt) bzw. `gps.POST` mit `is_derivat` + `derivat_von_gp_id` |
| EK | ✘ `RecipeRecomputeService::zutatKosten` sucht nur LAs/Lead des Zeilen-GP — ein Derivat hat keine, die Zeile bleibt unbepreist |
| Ausbeute | ✘ kein Feld: `gps` kennt nur `is_derivat`/`derivat_von_gp_id`, `gp_forms` nur Gramm je Einheit |
| Planung/Bestellung | ✘ `PlanungsblattService::gruppiereNachLieferant` und `OrderService` (≈1106) melden das Derivat als „ohne Lieferant“ / `lead_la_fehlt` — keine Umrechnung auf die Mutter |
| Matcher | ✘ `MatchHeuristics::NEBENPRODUKT_MARKER` kennt Schale/Zeste/Knochen/Parüren …, „saft“ bewusst nicht (mehrdeutig) — „Limette (Saft)“ geht heute auf gekauften „Limettensaft: konserviert“ |
| Bestand Team 1 | kein Saft-Derivat für Limette/Zitrone/Orange; Mutter „Zitrone: frisch“ fehlt (nur „Amalfi-Zitrone“ 8534); Limette frisch 8431, Blutorange 8739 |

## Zwei Arten von Derivat (Dominique 05.11.)

| | (a) Nebenprodukt | (b) Zweckverarbeitung |
|---|---|---|
| Beispiele | Fleischabschnitte, Parüren, Knochen, Karkassen, Gemüseschalen | Zitronen-/Limettensaft, Abrieb, Zeste |
| Mutter wird … | ohnehin für ein anderes Produkt eingekauft — das Derivat fällt **übrig** | **gezielt** für das Derivat eingekauft |
| EK | **offen (Frage 5)**: 0 € oder anteilig | Mutter-Preis ÷ Ausbeute |
| Bedarf/Bestellung | **kein** Fallback auf die Mutter (sonst doppelt bestellt) | Mutter-Menge = Derivat-Menge ÷ Ausbeute |

Unterscheidung am Derivat: neues Feld `derivat_art` = `nebenprodukt` | `zweck`. Vorschlag für den Default: aus dem §11.2-Marker abgeleitet
(Abschnitte, Parüren, Knochen, Karkasse, Stiele → `nebenprodukt`; Saft, Abrieb, Zeste, Schale bei Obst → `zweck`), von Hand überschreibbar.
Alle Punkte unten gelten für `zweck`; für `nebenprodukt` nur Ausbeute (Information) und Reife-Hinweis.

**Nebenprodukt reicht nicht** (Rezept braucht mehr, als anfällt — Frage 6): ohne Gegenbuchung im Planungslauf merkt es heute niemand.
Vorschlag: im Planungsblatt (Spec Produktion) Bedarf des Nebenprodukts gegen den Anfall aus den Mutter-Mengen desselben Laufs rechnen
(Anfall = Mutter-Menge × Ausbeute); Unterdeckung als Hinweis „Parüren reichen nicht — Zukauf oder Mutter erhöhen“, nie still bestellen.

## Umfang

### 1. Ausbeute am Derivat (Migration)
- Neue Spalten an `foodalchemist_gps`: `derivat_art` (`nebenprodukt` | `zweck`), `derivat_ausbeute_wert` (decimal) und `derivat_ausbeute_basis` (`g_je_stueck` | `prozent`),
  `derivat_ausbeute_quelle` (`manual` | `ki` | `regelwerk`). Nur bei `is_derivat = 1` gesetzt.
- Bedeutung: `g_je_stueck` = Gramm Derivat aus einem Stück Mutter (Limette ≈ 30 g Saft); `prozent` = Anteil der Mutter-Masse
  (Parüren 15 %). Ohne Wert gilt das Derivat als unbepreist und unbestellbar (heutiger Stand, ehrlich sichtbar).
- Pflege im GP-Dialog (Derivat-Bereich) und per MCP (`gps.PUT`); KI-Schätzung optional über den bestehenden KI-Weg mit Herkunft `ki`.

### 2. EK-Fallback über die Mutter (nur `zweck`, eigene Klasse)
- `Support\DerivatPreis` (eigene Klasse — `RecipeRecomputeService` steht unter dem Größen-Wächter, Spec 85 §4):
  Preis/g Derivat = Preis/g Mutter ÷ Ausbeute-Anteil (bei `g_je_stueck`: Preis je Stück Mutter ÷ g je Stück).
- Eingehängt in `preisProGrammMitBasis` / `preisProStueckMitBasis` und den Team-Kosten-Pfad (`kostenFuerTeam`) als Rückfall, wenn das GP
  ein Derivat ohne eigenen LA ist. Basis/Herkunft des EK erbt die Mutter (`EkPriceBasis`), plus Kennzeichen „über Mutter“.
- Einheit Stück an einer Derivat-Zeile („Limette (Saft), 1 Stück“) = g je Stück aus der Ausbeute.

### 3. Bedarf und Bestellung auf die Mutter (nur `zweck`)
- Vor der Lead-Suche: Derivat-Bedarf → Mutter-GP, Menge Mutter = Menge Derivat ÷ Ausbeute (Stück aufgerundet).
  Stellen: `PlanungsblattService::gruppiereNachLieferant`, `OrderService` (Auflösung der Positionen), Bestellliste.
- Die Produktion zeigt beides: Mutter zum Einkauf, Derivat als Arbeitsschritt („Limetten auspressen“).

### 4. Matcher „saft“ nur für Obst
- `saft` wird Nebenprodukt-Marker **nur** bei Mutter-Warengruppe 02 (Obst) — Gemüse-/Getränke-Säfte bleiben Produkte.
- „Frucht (Saft)“, „Saft von N Frucht“, „Frucht, nur Saft“ → Derivat „<Frucht>: frisch, Saft“, wenn es existiert; sonst wie heute
  (gekaufter Saft nur, wenn die Zeile ihn nennt, z. B. „Limettensaft“). Abrieb/Schale analog (Spec 95c: Abrieb = Schale).
- Die Einheit der Zeile bleibt (Stück); die Gramm kommen aus der Ausbeute (Punkt 2).

### 5. Datenanlage nur nach Ansage
- Kein Datenlauf im Code-PR. Derivate (Limette/Zitrone/Orange: Saft, Abrieb) und ihre Ausbeute legt Dominique bzw. der Kurator nach
  Freigabe an; die fehlende Mutter „Zitrone: frisch“ ebenso (LA-First: aus einem Lieferantenartikel).

## Abnahme
- Rezeptzeile „Limette (Saft), 1 Stück“ → Derivat „Limette: frisch, Saft“; EK = Preis 1 Limette; Allergene der Limette.
- Planungsblatt/Bestellung: 10 Rezepte à 1 Limette (Saft) → 10 Limetten beim Lead-Lieferanten der Mutter, kein „ohne Lieferant“.
- Ohne Ausbeute: Zeile bleibt unbepreist und meldet „Ausbeute fehlt“ (Reife-Lücke), nie ein geratener Wert.
- „Limettensaft“ (ohne Mutter-Nennung) bleibt der gekaufte Saft; „Tomatensaft“, „Karottensaft“ werden nie Derivat.
- Größen-Wächter, Spec90-KI-Pflichtliste (falls KI-Schätzung), KiUmleitung, Matching-Kreis grün.

## Offene Fragen an Dominique
1. **Ausbeute-Werte und Quelle:** Feste Werte je Frucht (z. B. Limette 30 g, Zitrone 40 g, Orange 80 g Saft; Abrieb 2–5 g) aus dem
   Regelwerk, KI-geschätzt mit Kennzeichnung oder nur von Hand? Je Team überschreibbar?
2. **Fehlende Mutter „Zitrone: frisch“:** Aus welchem Lieferantenartikel anlegen (LA-First)? Oder „Amalfi-Zitrone“ als Mutter?
3. **Gekaufter Saft vs. Derivat:** Wenn ein Rezept „Limettensaft, 100 ml“ sagt — gekaufter Saft (heute) oder auch Derivat (frisch
   gepresst)? Entscheidet das die Leitplanke Eigenleistung (from_scratch → Derivat, Convenience → gekauft)?
4. **Mehrere Derivate einer Mutter** (Saft + Abrieb derselben Limette im selben Rezept): Bedarf aus dem größeren Anteil oder addiert?
5. **EK eines Nebenprodukts (a):** 0 € (fällt ohnehin an, die Mutter trägt die Kosten) oder anteilig (Mutter-Preis × Anteil, damit
   das Rezept mit Parüren nicht künstlich billig wirkt)? Bei „anteilig“: wird die Mutter-Kalkulation dann um denselben Betrag entlastet?
6. **Nebenprodukt reicht nicht (a):** Hinweis im Planungslauf (Vorschlag oben) — und dann? Zukauf eines Ersatz-GP (z. B. Kalbsknochen
   als LA), Mutter-Menge erhöhen oder nur melden?
7. **Default der Art:** Passt die Ableitung aus dem Marker (Abschnitte/Parüren/Knochen/Karkasse/Stiele = Nebenprodukt; Saft/Abrieb/Zeste
   bei Obst = Zweck)? Grenzfälle: Gemüseschalen (Fond) eher Nebenprodukt, Zitronenschale für Abrieb Zweck.
