> **EINGEFRORENE KOPIE (2026-06-10)** — Quelle: Cooking-Jarvis-Vault `07_WISSEN/07.01_Lebensmittel_und_Gastronomie/`. Normative Referenz für den Food-Alchemist-Spec-Korpus. Änderungen NUR in der Vault-Quelle, dann neu einfrieren.

---
typ: Regelwerk_Grundprodukte
zweck: Verbindliche Stammdaten-Struktur fuer GP-Layer + Necta-Export-Konformitaet
version: "3.4.0"
basiert_auf:
  - "00_INBOX/regelwerk_grundprodukte_wawi (1).md"
  - "00_SYSTEM/00.02_Templates/WaWi_Namenskonvention_Grundprodukte.md (v2.0)"
ersetzt: WaWi_Namenskonvention_Grundprodukte.md
letzte_sync: 2026-06-02
tags: [referenz, regelwerk, grundprodukte, stammdaten, necta]
---

# Regelwerk Grundprodukte v3.4

> Verbindliche Stammdaten-Struktur fuer den GP-Layer im Vault und beim Export nach Necta 2.0. Konsolidiert das Inbox-Regelwerk (Stand 2026-04-29) mit der WaWi-Namenskonvention v2.0 (2026-04-02). v3.2 (2026-05-22) ergaenzt §11 um Nebenprodukt-Derivate (Schalen, Karkassen, Paruren, Stiele etc.) als Spiegel zu [[Regelwerk_Basisrezepte]] §11. **v3.3 (2026-05-29) kehrt die Namens-Grundform um: GPs werden ab sofort im SINGULAR (Lemma-Form) benannt statt im Plural (§6.1) — User-Entscheidung, Singular ist die Truth.** Bei jeder GP-Aufgabe (Anlage, Umbenennung, Naming-Check, Strukturentscheidung, Necta-Export) ZUERST dieses Regelwerk lesen, dann ggf. die passende Domain-Datei unter `Domains/`.

---

## §1 Ziel & Geltungsbereich

Einheitliche, skalierbare und auswertbare Stammdatenstruktur fuer Catering. Ziel:
- einheitliche Daten ueber alle Caterer und Kunden
- klare Logik fuer Mitarbeiter (Naming, Anlage-Entscheidung)
- hohe Datenqualitaet (Allergen, Preis, Substitution)
- Skalierbarkeit Richtung Necta 2.0 + WaWi-Master-DB

Das Regelwerk gilt fuer:
- Alle GP-Anlagen unter `03_KUECHE/03.01_Grundprodukte/`
- Alle Skills, die GPs erzeugen oder umbenennen (`recipe_creator`, `briefing_parser`, `wawi_lookup`, ...)
- Alle Necta-Export-Operationen

---

## §2 Grundprinzip: WAS / ART / WIE

| Dimension | Bedeutung | Beispiel |
|-----------|-----------|----------|
| **Warengruppe** | WAS | `01 Gemuese & Blattsalat` |
| **Produkttyp** (= Warenklasse) | ART | `Gemuesepueree` |
| **Attribute** | WIE liegt es vor | `tiefgekuehlt, Pueree` |

> Warenklasse = Produkttyp (synonym)
> Struktur bleibt stabil – Attribute machen die Differenz.

---

## §3 Strukturbaum — 15 Warengruppen

⚠️ Der Baum enthaelt **nur fachliche Produkttypen** – keine Zustaende oder Verarbeitung.

```text
01 Gemüse & Blattsalat        (verfeinert 2026-06-02 — reine Produkttypen, kein Zustand)
   ├── 01.1 Fruchtgemüse           (Tomate, Paprika, Gurke, Zucchini, Aubergine, Kürbis, Chili)
   ├── 01.2 Wurzel- & Knollengemüse (Kartoffel, Karotte, Sellerieknolle, Rote Bete, Kohlrabi, Pastinake, Topinambur)
   ├── 01.3 Kohlgemüse             (Brokkoli, Blumenkohl, Wirsing, Rotkohl, Weißkohl, Rosenkohl)
   ├── 01.4 Zwiebelgemüse          (Zwiebel, Lauch/Porree, Knoblauch, Schalotte, Frühlingszwiebel)
   ├── 01.5 Blatt- & Stielgemüse   (Spinat, Mangold, Spargel, Fenchel, Staudensellerie, Rhabarber)
   ├── 01.6 Pilze
   ├── 01.7 Blattsalat
   ├── 01.8 Sprossen & Keimlinge
   └── 01.9 Gemüsepüree

02 Obst                       (verfeinert 2026-06-02 — reine Produkttypen, kein Zustand)
   ├── 02.1 Kernobst               (Apfel, Birne, Quitte)
   ├── 02.2 Steinobst              (Kirsche, Pflaume, Pfirsich, Aprikose, Mango, Nektarine, Dattel)
   ├── 02.3 Beerenobst             (Erdbeere, Himbeere, Heidelbeere, Brombeere, Trauben, Cranberry)
   ├── 02.4 Zitrusfrüchte          (Zitrone, Orange, Limette, Grapefruit, Mandarine, Yuzu)
   ├── 02.5 Exotische Früchte      (Ananas, Banane, Kiwi, Passionsfrucht, Papaya, Granatapfel, Feige)
   ├── 02.6 Melonen
   └── 02.7 Obstpüree

03 Kräuter
   ├── Kräuter
   └── Kräutermischungen

04 Fleisch, Geflügel & Wild   (Gliederung nach TIERART, User-Entscheidung 2026-06-02 —
                               Produkttyp/Zuschnitt → Feld verarbeitung/form, Allergen → tags)
   ├── 04.1 Rind
   ├── 04.2 Kalb
   ├── 04.3 Schwein
   ├── 04.4 Lamm
   ├── 04.5 Geflügel
   ├── 04.6 Wild
   └── 04.7 Wurstwaren             (tierübergreifend: Brüh-/Roh-/Kochwurst, Salami, Mettwurst)

05 Fisch & Meeresfrüchte
   ├── Fisch ganz
   ├── Fisch Filet
   ├── Fisch Portion
   ├── Fisch gegart
   ├── Fisch geräuchert
   └── Meeresfrüchte

06 Molkerei & Eier
   ├── Milchprodukte
   ├── Sahne & Rahm
   ├── Joghurt
   ├── Quark & Frischkäse
   ├── Käse
   ├── Butter & Fette
   ├── Eier
   └── Laktosefreie Produkte

07 Getreide & Hülsenfrüchte
   ├── Reis
   ├── Getreide
   ├── Pseudogetreide
   ├── Hülsenfrüchte trocken
   └── Hülsenfrüchte (vorgegart/Konserve)

08 Teigwaren
   ├── Teigwaren
   └── Teigwaren vorgegart

09 Backwaren & Süßwaren
   ├── Brot & Brötchen
   ├── Backwaren
   ├── Gebäck
   ├── Kuchen & Torten
   ├── Süßwaren
   └── Knabbereien

10 Gewürze & Würzmittel
   ├── Gewürze
   ├── Würzmittel flüssig
   ├── Würzpasten
   ├── Gewürzmischungen
   ├── Salze
   ├── Zucker & Süßungsmittel
   └── Sirupe (für Würzung)

11 Essig & Öl
   ├── Öl
   ├── Aromatisierte Öle
   └── Essig

12 Trockenprodukte
   ├── Mehl
   ├── Bindemittel & Backtriebmittel
   ├── Nüsse & Saaten
   └── Backzutaten

13 Convenience & Komponenten         (umgebaut, siehe §3.13)
   ├── 13.1 Komponenten warm
   ├── 13.2 Komponenten kalt
   ├── 13.3 Saucen & Fonds (fertig)
   ├── 13.4 Suppen & Brühen (fertig)
   ├── 13.5 Dessertkomponenten
   ├── 13.6 Dekokomponenten
   └── 13.7 Komplettgerichte & Fingerfood

14 Vegane Ersatzprodukte             (eigenständig, nicht aufgelöst)
   ├── Fleischersatz
   ├── Fischersatz
   ├── Milchersatz
   ├── Käsealternativen
   ├── Eierersatz
   └── Pflanzliche Proteine

15 Getränke                          (alle alkoholisch + nicht-alkoholisch)
   ├── Wein
   ├── Bier & Cidre
   ├── Spirituosen & Liköre
   ├── Aperitifs
   ├── Wasser
   ├── Säfte
   ├── Erfrischungsgetränke
   ├── Heißgetränke (Kaffee, Tee)
   └── Sirupe für Bar
```

### §3.13 Convenience-Klasse (Detail)

Neu strukturiert nach **Verwendungs-Logik** (logisch + wiederverwendbar):

| Subtyp | Definition | Beispiele |
|--------|------------|-----------|
| 13.1 Komponenten warm | Modul, das warm in ein Gericht eingebaut wird | Vorgegarte Kartoffelgratins, Polenta-Schnitten, Risotto-Basis |
| 13.2 Komponenten kalt | Modul, das kalt eingebaut wird | Vorfertige Salate, Aufstriche, vorgemischte Marinaden |
| 13.3 Saucen & Fonds (fertig) | Verzehrfertige Sauce, nur Erwaermung noetig | Demi-Glace fertig, Hollandaise-Konserve |
| 13.4 Suppen & Bruehen (fertig) | Verzehrfertige Suppe / Bruehe | Rinderbruehe Konserve, Fertig-Veloute |
| 13.5 Dessertkomponenten | Patisserie-Modul | Vorgebackene Boeden, Mousse-Pulver, Tortendekor-Set |
| 13.6 Dekokomponenten | Garnitur-Modul | Essbare Bluemchen-Mix, Crouton-Mischung, Dekor-Pulver |
| 13.7 Komplettgerichte & Fingerfood | Heat-and-eat ohne weitere Verarbeitung | Mini-Quiches, Vol-au-vent gefuellt, vorportionierte Lasagne |

**Definition "fertig":** ohne weitere Zubereitung nach Erwaermung verzehrfertig.

**Doppelung-Aufloesung Knabbereien (09) vs. Snacks (13):**
- `09 Knabbereien` = klassischer Snack-Riegel oder Knabber-Artikel (Chips, Cracker, Nuesse als Snack-Mix, Salzstangen).
- `13.7 Fingerfood` = vorgefertigte Mini-Speisen die als Gericht-Ersatz dienen (Mini-Quiches, Tartelettes, Wraps).

---

## §4 Attribute (KEINE Klassen)

Attribute beschreiben den Zustand, **sie definieren keinen eigenen Produkttyp**. Sie kommen ins Benennungsschema (§6).

| Attribut-Klasse | Werte |
|-----------------|-------|
| **Zustand** | frisch, tiefgekuehlt, trocken, konserviert |
| **Verarbeitung** | roh, gegart, blanchiert, sous-vide, mariniert, fermentiert, geraeuchert, eingelegt, gepoekelt, pasteurisiert, konzentriert, pulverfoermig, fluessig, luftgetrocknet, geschnitten/Wuerfel/Brunoise/Streifen/Scheiben/geraspelt/gerieben/pueriert/gehackt/filetiert/Mittelstueck/geschaelt/entkernt |
| **Form (Geometrie)** | Ganz, Halbiert, Geviertelt, Filet, Portion, Pueree, Pulver, Granulat |
| **Zusatz (Diaet/Religion)** | vegan, vegetarisch, halal, koscher, glutenfrei, laktosefrei |
| **Zusatz (Nachhaltigkeit/Bio)** | Bio, Demeter, Bioland, Naturland, MSC, ASC |

> Mass-Angaben (z. B. `Wuerfel 10 mm`, `Streifen 8 mm`, `Brunoise 2 mm`) gehoeren in die Verarbeitungs-Bezeichnung.

---

## §5 Markenregel

### Schema

```text
Produktname [Marke]: Zustand, Verarbeitung, Form / (Zusatz)
```

### Grundregel

- **austauschbar** → generischer Name, keine Marke
- **nicht austauschbar** → Marke wird Bestandteil des Produktnamens

### 4-Punkt-Tiebreaker — Wann ist die Marke Pflicht?

Marke kommt in den GP-Namen, sobald **mindestens eines** zutrifft:

1. **Sensorik** — Geschmacks- oder Texturdifferenz fuer den Endgast spuerbar (z. B. Boiron-Frucht-Pueree, San-Marzano-Tomaten).
2. **Preis** — Preisunterschied zu Alternativen >20 %.
3. **Kundenpraeferenz** — Marke ist vom Kunden vertraglich oder als Praeferenz festgelegt.
4. **Lebensmittelchemie** — Chemisch/aromatisch unterscheidbar (z. B. Cointreau vs. generischer Triple Sec — Vol.-% und Aromaprofil).

**Beispiele:**
```text
Milch 3,5 %: frisch, fluessig                    ← austauschbar, keine Marke
Mangopueree Boiron: tiefgekuehlt, Pueree         ← Sensorik (Pkt. 1)
Cointreau: fluessig, 40 vol-%                    ← Lebensmittelchemie (Pkt. 4)
San_Marzano_Tomaten Mutti: Konserve, ganz        ← Sensorik + Kundenpraeferenz
```

---

## §6 Benennungsschema

### Vollstaendige Syntax

```text
[Produktname [Marke]]: [Eigenschaft], [Herkunft], [Zustand/Zuschnitt], [Grammatur] / ([Dosierung], [Zusatz], [Bio])
```

> **Kein Untergruppen-Praefix.** Der Produkttyp aus §3 ist die Ordner-Zuordnung im Vault (`03.01_Grundprodukte/01_Gemuese_Blattsalat/`) und das `produkttyp`-Feld im YAML — er steht NICHT vor dem Produktnamen.

| Feld | Pflicht? | Beschreibung | Beispiel |
|------|----------|-------------|----------|
| Produktname | Pflicht | Grundbezeichnung im Singular / Lemma-Form (siehe §6.1) | `Moehre`, `Rinderfilet` |
| Marke | Bedingt | Nur wenn §5 Tiebreaker greift | `Boiron`, `San Marzano Mutti` |
| Eigenschaft | Pflicht | Zustand aus §4 | `frisch`, `tiefgekuehlt` |
| Herkunft | Optional | Region/Land | `regional`, `Argentinien`, `Nordsee` |
| Zustand/Zuschnitt | Pflicht | Verarbeitung aus §4 | `ganz`, `Wuerfel 10 mm`, `filetiert` |
| Grammatur | Bedingt | Pflicht bei Portion (§7) | `200 g`, `180 g pro Stueck` |
| Dosierung | Optional | In Klammern, Einsatzmenge | `20 g pro 1 l`, `30 ml pro 1 l Wasser` |
| Zusatz | Optional | Diaet/Religion + Nachhaltigkeit | `vegan`, `glutenfrei`, `Bio`, `MSC` |

**Trennzeichen:**
- Doppelpunkt `:` — trennt Name Untergruppe, Produktname und Eigenschaften
- Komma `,` — trennt Eigenschaften
- Schraegstrich `/` — leitet Klammer-Block ein
- Runde Klammern `()` — umschliessen Dosierung, Zusatz, Bio

### §6.1 Singular-Pflicht

Grundprodukte werden **immer im Singular** (Lemma-Form) benannt. Diese Regel ersetzt ab v3.3 (2026-05-29) die fruehere Plural-Pflicht — **User-Entscheidung: Singular ist die Truth.** Unzaehlbare Stoffe (Mehl, Zucker, Milch) sind ohnehin Singular und erfuellen die Regel automatisch.

| Richtig | Falsch |
|---------|--------|
| Zwiebel | Zwiebeln |
| Kartoffel | Kartoffeln |
| Moehre | Moehren, Karotte |
| Tomate | Tomaten |
| Rinderfilet | Rinderfilets |
| Dattel | Datteln |
| Garnele | Garnelen |
| Apfel | Aepfel |

**Ausnahmen (bleiben Plural) — zwei Klassen:**

**(a) Pluralia tantum / Lehnwoerter** ohne brauchbaren deutschen Singular:
- Pasta-Lehnwoerter: `Spaghetti`, `Penne`, `Tagliatelle`, `Gnocchi`, `Tortellini`
- Weitere: `Antipasti`, `Cornflakes`, `Pommes frites`

**(b) Sammelware** — Stueckware, die im Handel/Gastro praktisch ausschliesslich als Schuettgut/Kollektiv gefuehrt wird; die §8-Pflichtangaben (Groesse, Sortierung) haengen an der Charge, nicht am Einzelstueck. Genau **vier Gruppen** (geschlossene Liste, User-Entscheidung 2026-05-29):
- **Eier** (alle Eier-GPs): `Eier`
- **Huelsenfruechte** (Schuettgut): `Linsen` (rot/gruen/Beluga), `Kichererbsen`, getrocknete `Bohnen`, getrocknete `Erbsen`
- **Nuesse & Kerne** (Schuettgut): `Mandeln`, `Walnuesse`/`Walnusskerne`, `Haselnuesse`, `Pinienkerne`, `Cashewkerne`, `Pistazien`, `Sonnenblumenkerne`, `Kuerbiskerne`
- **Beeren** (Schale/Schuettgut): `Erdbeeren`, `Himbeeren`, `Heidelbeeren`, `Brombeeren`, `Johannisbeeren`, `Cranberries`

**Echte Stueckware mit sinnvoller Einzel-Einheit bleibt Singular** — auch gastro-typisch plural gehandelte Meeresfruechte (`Garnele`, `Jakobsmuschel`, `Miesmuschel`, `Scampo`), Steinobst/Trockenfruechte ausser den o.g. Beeren (`Dattel`, `Aprikose`, `Pflaume`), Wurzel-/Knollen-/Fruchtgemuese (`Kartoffel`, `Moehre`, `Tomate`, `Apfel`).

> **Grenzfaelle** (z.B. Trockenfruechte ausser den genannten, exotische Kerne): im Phase-G.1-Preview flaggen und vom User entscheiden lassen; die Entscheidung wird in diese geschlossene Liste uebernommen — nicht ad-hoc raten.

**Match-Semantik (Singular ↔ Plural):** Der GP-Name folgt §6.1, aber Zutat-Strings aus Rezepten/KI kommen mal Singular, mal Plural. Damit der Matcher beide als gleich erkennt, reduziert er sie vor dem Vergleich über den zentralen Stemmer `stemming::stem_german` (App-Code). Der Matcher ist richtungsneutral — Ziel ist **Konvergenz**, nicht korrekte Lemmatisierung: „Tomate" und „Tomaten" ergeben beide den Stem „tomat". Umlaut-Plurale (Walnüsse↔Walnuss) werden über einen kuratierten Lookup gefangen. Die Singular-Umstellung der GP-Namen ist daher reine Naming-Konsistenz und aendert am Matching nichts. Details + Grenzen → [[Matching_Logik]].

### §6.2 Doppelnamen

Wenn ein Produkt zwei gleichwertige gaengige Namen hat → beide durch `/` getrennt, gaengigerer zuerst:
```text
Garnelen / Shrimps: TK, geschaelt, 16/20
Auberginen / Melanzani: frisch, ganz / (vegan)
Sahne / Rahm: frisch, 30 % / (vegetarisch)
```

Maximal 2 Namen — weitere Synonyme ins YAML-Feld `synonyme` (siehe §14).

### §6.3 Fremdwoerter

Fachbegriff bleibt, deutsche Uebersetzung in Klammern:
```text
Brunoise (Feinwuerfel) 2 mm
Julienne (Streifen) 3 mm
Concassée (Tomatenwuerfel)
Mirepoix (Suppengemuese)
```

---

## §7 Grammatur-Regeln

### Grundregel

> Grammatur **nur bei Portionen** (Stueckgewicht).

### Pflicht

> Portion **muss immer** Grammatur haben.

### Beispiele

```text
Rinderfilet: frisch, roh, ganz                           ← keine Grammatur (gewichtsoffen)
Rinderfilet: frisch, roh, Portion, 200 g pro Stueck      ← Grammatur Pflicht
Karotten: tiefgekuehlt, blanchiert, Wuerfel 10 x 10 mm   ← Mass-Angabe statt Grammatur
```

### §7.1 Stueckgewicht vs. Verpackungsgewicht

> **WICHTIG:** Verpackungsgewicht (z. B. "Glas 250 g", "Dose 500 ml", "Beutel 1 kg") gehoert NICHT ins Grundprodukt — das ist ein Attribut des Lieferantenartikels.

Nur Stueckgewicht (z. B. TK-Schnitzel 120 g, Filet 180 g) ist GP-relevant, weil es die Zubereitung/Portionierung beeinflusst.

GP-relevant:
- TK-Schnitzel 120 g vs. 180 g → zwei GPs
- Lachsfilet 150 g vs. 200 g → zwei GPs
- Burger-Patty 100 g vs. 150 g → zwei GPs

NICHT GP-relevant (= Lieferantenartikel-Attribut):
- Olivenoel 500 ml vs. 5 l → gleiches GP
- Mehl 1 kg vs. 25 kg → gleiches GP

### §7.2 Stueckgewicht-Schwelle

Stueckgewicht-Unterschied **>10 %** → eigenes GP.

---

## §8 Produktspezifische Pflichtangaben

| § | Kategorie | Pflichtangabe |
|---|-----------|---------------|
| 8.1 | Milch & Milchprodukte | Fettstufe (z. B. `Milch 3,5 %`, `Sahne 30 %`) |
| 8.2 | Eier | Groesse (`M`, `L`, `XL`) |
| 8.3 | Fleisch | Grammatur bei Portion + Zuschnitt-Mass |
| 8.4 | Fisch | Grammatur bei Filet/Portion + Garnelen-Kaliber (`16/20`, `21/25`) |
| 8.5 | Backwaren | Stueckgewicht (`Broetchen: 70 g pro Stueck`) |
| 8.6 | Getraenke | Gebinde + Liter + **Vol-% bei Spirituosen** + **Rebsorte/Region/Jahrgang bei Wein** + **Roestgrad/Sorte (Arabica/Robusta) bei Kaffee** |
| 8.7 | Allgemein | Produktspezifische Pflichtangaben ueberschreiben generische Regeln |
| **8.8** | **Kaese** | **Reifegrad (jung/gereift/lange gereift) + Festigkeit (Hart/Schnitt/Halbfest/Weich/Frisch) + Pasteurisierung (Rohmilch/pasteurisiert)** |
| **8.9** | **Reis** | **Sorte (Basmati / Jasmin / Arborio / Sushi / Wildreis / Vollkorn)** |
| **8.10** | **Schokolade** | **Kakaoanteil + Couverture-Status (Couverture / Tafel / Kakaomasse / Kakaobutter)** |
| **8.11** | **Mehl** | **Type (405 / 550 / 1050 / 00 / Vollkorn)** |
| **8.12** | **Kartoffeln** | **Kochtyp Pflicht: mehlig / vorwiegend festkochend / festkochend** (NICHT bei Süßkartoffeln / Topinambur — andere Pflanzenart). Sortenangabe wenn bekannt (Linda, Belana, Cilena, Agria, Drilling-Sorten). Wenn Kochtyp aus Designation NICHT ableitbar → Status `needs_review` mit Grund `kochtyp_pflicht`. |

---

## §9 Pflicht-Vokabular

### Zustand
`frisch, tiefgekuehlt (Kurzform: TK), trocken, konserviert`

> `TK` ist die zugelassene Abkuerzung fuer `tiefgekuehlt`. Im Produktnamen kann beides verwendet werden — bevorzugt `TK` (kuerzer, in Catering-Praxis etabliert).

### Verarbeitung
`roh, gegart, blanchiert, sous-vide, mariniert, fermentiert, geraeuchert, eingelegt, gepoekelt, pasteurisiert, konzentriert, pulverfoermig, fluessig, luftgetrocknet, geschnitten, Wuerfel <mm>, Brunoise <mm>, Streifen <mm>, Scheiben <mm>, geraspelt, gerieben, pueriert, gehackt, filetiert, Mittelstueck, geschaelt, entkernt, halbiert, geviertelt`

### Form (Geometrie)
`Ganz, Filet, Portion, Pueree, Pulver, Granulat`

> `Granulat` = koerniges/krosses Schuettgut: Crunch, Knusperstreusel, Streusel, Crumble, Knusperperlen, Dekor-Granulat (typisch WG 13.6 Dekokomponenten / 13.5 Dessertkomponenten). Abgrenzung: `Pulver` = fein/staubfein (Mousse-Pulver, Dekor-Pulver), `Granulat` = koernig/krosse Stueckchen.
> **`Portion` ist KEIN Default fuer Schuettgut:** `Portion` nur fuer einzeln portionierte Stuecke MIT Stueckgrammatur (§7). Lose Ware (Dosen/Eimer/Streusel) bekommt `Granulat` / `Pulver` / keine Form — nie `Portion`.

### Zusatz — Diaet/Religion
`vegan, vegetarisch, halal, koscher, glutenfrei, laktosefrei`

### Zusatz — Nachhaltigkeit / Qualitaetszeichen
`Bio, Demeter, Bioland, Naturland, MSC, ASC, Dry Aged <Tage>, Sashimi-Qualitaet, ohne Zusatzstoffe, Convenience`

> Bei neuen Begriffen, die noch nicht im Vokabular stehen → Owner (siehe §18) entscheidet ueber Aufnahme.

---

## §10 Entscheidungsregel (Marke vs. generisch)

```text
Austauschbar?
→ JA  → generisch (kein Marken-Eintrag im Namen)
→ NEIN → Marke wird Bestandteil des Namens (siehe §5 Tiebreaker)
```

---

## §11 Derivate

> „Derivat" hat im GP-Regelwerk **zwei Bedeutungen**, je nach Entstehungsart. Beide bekommen eigene GPs, aber mit unterschiedlicher Modellierung.

### §11.1 Konservierungs-Derivate (Säure / Salz / Fermentation / Konservierung)

> **Sobald Saeure / Salz / Fermentation / Konservierung den Charakter eines Produkts wesentlich veraendert → eigener GP, nicht Verarbeitungs-Attribut.**

Verarbeitete Produkte (mit Essig / Salz / Oel / Fermentation) sind keine Variante des Roh-Produkts, sondern eigene Familie. **Sie bekommen `is_derivat = 0`** — sind also eigenständige GPs ohne `derivat_von_gp_id`-Verweis (die Verarbeitung hat sie zu einem neuen Produkt gemacht).

#### Trigger-Liste

| Roh-Produkt | Derivat → eigenes GP |
|-------------|----------------------|
| Weisskohl | Sauerkraut |
| Chinakohl | Kimchi |
| Gurken | Saure Gurken / Senfgurken / Gewuerzgurken |
| Mixed Vegetables | Mixed Pickles |
| Auberginen / Zucchini / Paprika | Antipasti in Oel |
| Sardellen | Sardellenfilets in Oel / Salzsardellen |
| Heringe | Eingelegte Heringe / Bismarckheringe / Bratheringe |
| Gemuese generell | Fermentiertes Gemuese (Kraut, Karotten, Rote Bete) |
| Oliven (frisch) | Oliven eingelegt (Lake / Oel) |
| Kapern (frisch) | Kapern in Lake / Kapern in Salz |

#### Begründung

- Andere Saeure-/Salzkonzentration → andere Mikrobiologie + Lagerung
- Andere Allergen-Cross-Effekte (Fermentation kann Histamin bilden, Lake kann Sulfite enthalten)
- Andere Kalkulationsbasis (1 kg Sauerkraut ≠ 1 kg Weisskohl, anderer Putzverlust, anderer Geschmacksimpuls im Rezept)

---

### §11.2 Nebenprodukt-Derivate (Zerlegungs- / Verarbeitungs-Reste) — NEU 2026-05-22

> **Reststoffe und Nebenprodukte, die beim Zerlegen oder Verarbeiten eines Mutter-GPs in der Küche entstehen, bekommen eigene GPs mit Verweis auf den Mutter (`is_derivat = 1`).**

Im Gegensatz zu §11.1 verändert die Verarbeitung den Charakter NICHT fundamental — es ist nur ein Teil des Mutter-Produkts. Aber im Rezept werden sie als eigene Zutat referenziert (z.B. „20 g Gurkenschale", „1600 g Kalbsparüren", „Zitronensaft"). **Sie bekommen `is_derivat = 1`** + `derivat_von_gp_id` als FK zum Mutter-GP.

#### Datenfelder (`wawi_gp_v2`-Erweiterung)

```sql
ALTER TABLE wawi_gp_v2 ADD COLUMN is_derivat INTEGER DEFAULT 0;
ALTER TABLE wawi_gp_v2 ADD COLUMN derivat_von_gp_id INTEGER REFERENCES wawi_gp_v2(gp_v2_id);
ALTER TABLE wawi_gp_v2 ADD COLUMN derivat_typ TEXT;          -- Vokabular siehe unten
ALTER TABLE wawi_gp_v2 ADD COLUMN derivat_anteil_pct REAL;   -- 0-100, optional
ALTER TABLE wawi_gp_v2 ADD COLUMN requires_la INTEGER DEFAULT 1;  -- Derivate: 0
```

#### Kontrolliertes Vokabular `derivat_typ`

| derivat_typ | Beschreibung | Beispiele |
|---|---|---|
| `schale` | Ganze Schale inkl. Albedo (z.B. für Öl-Aromatisierung) | Gurkenschale, Orangenschale (Öl), Kürbisschale |
| `zeste` | Oberste aromatische Schicht (= `abrieb`, mild) | Zitronenzeste, Yuzu-Zeste, Mandarinenabrieb |
| `karkasse` | Skelett mit Restfleisch | Geflügel-, Fisch-, Krustentier-, Hummer-Karkasse |
| `knochen` | Reine Knochen | Kalbsknochen, Wildknochen, Markknochen |
| `paruere` | Fleisch-Putzabschnitte (Sehnen, Fett, Knorpel) | Kalbsparüren, Rinderparüren, Lammparüren |
| `abschnitt` | Allgemeine Verarbeitungsreste | Gemüseabschnitte, Fleischabschnitte, Leberabschnitte |
| `stiel` | Kraut-/Pflanzenstiele | Petersilien-, Basilikum-, Brunnenkresse-, Majoranstiele |
| `strunk` | Pflanzen-Strunk | Blumenkohlstrunk |
| `gruen` | Kraut-Grün | Karottengrün, Selleriegrün |
| `saft` | Selbstgepresst (Default-Frisch) | Zitronensaft, Orangensaft, Mangosaft |
| `fett` | Ausgelassen aus Tier/Produkt | Hühnerfett, Speckfett, Schweinefett, Parmesanfett |
| `haut` | Tierische Haut | Hühnerhaut, Fischhaut |
| `innerer_kern` | Innenliegender Pflanzenteil | Zitronengras-Innerer Kern |

#### Eigenschaften

| Aspekt | Verhalten |
|---|---|
| **Naming** | Bestehendes Schema (§6): `<Mutter>: frisch, <Derivat-Form>` z.B. `Gurke: frisch, Schale`, `Kalb: frisch, Parüren`, `Zitrone: frisch, Zeste`, `Geflügel: frisch, Karkasse` |
| **Allergene** | **LIVE vom Mutter-GP gelesen** (siehe §16). Kein eigener Snapshot. Konsistenz garantiert: ändern sich Mutter-Allergene, ändern sich Derivat-Allergene automatisch. |
| **LA-Match** | `requires_la = 0` → wird in LA-First-Pipeline übersprungen. Kein Lieferanten-Match nötig (Derivate werden nicht zugekauft). |
| **Preis** | Default `preis_default_netto = NULL` (Abfall-Verwertung, im Mutter-Preis enthalten). Bei kommerzieller Großverwertung (z.B. Knochen-Großeinkauf) manueller Override möglich. |
| **Putz-/Garverlust** | Nicht relevant — das Derivat IST schon der Putzverlust. |
| **Necta-Export** | Derivat-GPs werden **nicht exportiert** (Necta kennt nur einkaufbare Produkte). |

#### Spezialregeln

- **Saft-Default = frisch gepresst** (Derivat). Override-Marker im Rezept-Text („konserviert", „Tetra", „im Glas") wechselt zum Industrie-GP (eigener Stamm-GP, `is_derivat=0`).
- **Fett-Disambiguierung:** „Hühnerfett" = Default-Derivat (selbst ausgelassen). „Hühnerschmalz im Glas" → Industrie-GP.
- **Anti-Pattern:** Pinienkerne / Walnusskerne / Kürbiskerne sind **KEINE Derivate** — eigene Stamm-GPs (gekauft als Nüsse/Kerne).
- **Grün-Disambiguierung:** „Karottengrün" = Derivat von Karotte. „Grüner Spargel" = eigene Sorte (eigener GP). „Grüne Chili" = eigene Variante.
- **Schale vs. Zeste:** kulinarisch unterschiedlich (Schale = ganz inkl. bitterem Albedo, Zeste = nur oberste aromatische Schicht). Zwei separate `derivat_typ`-Werte.

#### Begründung für eigene GPs (statt Inline-Note im Rezept)

- ~500 Vorkommen im Kochbuch (1 alle 2.7 Rezepte) — zu häufig für ad-hoc-Behandlung
- Match-Logik im Rezept braucht stabilen GP-Anker (`recipe_ingredients.gp_v2_id` FK)
- Allergen-Aggregation braucht eindeutige GP-Identität
- Konsistenz mit bestehendem Naming-Schema (§6) ohne Sonderfälle

#### Querverweis

Vollständige Match-Logik + Migrations-Vorgehen siehe `[[Regelwerk_Basisrezepte]]` §11.

---

## §12 Anti-Patterns (so NICHT)

| Falsch | Problem | Richtig |
|--------|---------|---------|
| `Zwiebel_fein_gewuerfelt` | Zuschnitt im Namen statt in Attributen | `Zwiebel: frisch, Wuerfel 5 mm` |
| `Currypulver_zum_Abschmecken` | Verwendungszweck im Namen | `Currypulver Madras: trocken, gemahlen` |
| `fetter_Fischfilet` | Adjektiv statt Zustand | `Fischfilet: frisch, fettreich, filetiert` |
| `Saft_von_3_Zitronen` | Verarbeitungsanweisung | `Zitrone: frisch, Saft` mit `is_derivat=1`, `derivat_von_gp_id` → Zitrone (siehe §11.2) |
| `getr._Aprikosen` | Abkuerzung + Plural | `Aprikose: getrocknet, ganz` |
| `Zweige_Rosmarin` | Verpackungsform im Namen | `Rosmarin: frisch, ganz` |
| `Alter_Balsamico` | Unspezifisch | `Balsamico: fluessig, gereift / (Aceto Balsamico Tradizionale)` |
| `A1_Steak_Sauce` | Markenname als Produktname | → Lieferantenartikel, generisches GP `Steaksauce` |
| `Ei` | Singular statt Plural bei Sammelware (§6.1b) | `Eier: frisch, Groesse M` |
| `Linse` | Singular statt Plural bei Sammelware (§6.1b) | `Linsen: rot, trocken` |
| `doppelter_Espresso` | Zubereitungsanweisung | gehoert ins Rezept, nicht GP |
| `karamellisierte_Mandeln` | Verarbeitung = Basisrezept | `Mandeln: trocken, ganz` als GP (Sammelware §6.1b) |
| `pH-neutrales_Pulver` | Keine Produktbezeichnung | spezifischen Produktnamen verwenden |

---

## §13 Entscheidungsbaum: Grundprodukt — ja oder nein?

```
Ist es ein einzelner, kaufbarer Rohstoff?
├── JA → Grundprodukt
│   └── Hat es einen Markennamen?
│       ├── JA → §5 Tiebreaker pruefen
│       │       ├── trifft 1 von 4 Punkten zu → Marke ins GP
│       │       └── trifft keiner zu → generisches GP, Marke nur am Lieferantenartikel
│       └── NEIN → direkt als generisches GP
│
├── NEIN, es ist verarbeitet (z. B. karamellisiert, mariniert in Sauce)
│   └── Veraendert Saeure/Salz/Fermentation den Charakter? (§11)
│       ├── JA → eigener GP (Derivat)
│       └── NEIN → Basisrezept mit GP-Zutaten
│
└── NEIN, es ist eine Zubereitungsanweisung
    └── gehoert ins Rezept, NICHT als GP
```

---

## §14 Synonyme-Logik

Produkte mit mehreren gaengigen Namen fuehren **Synonyme im YAML-Feld `synonyme`**, NICHT im Produktnamen.

| Kanonischer Name | Synonyme (`synonyme:`) |
|------------------|------------------------|
| Cherrytomaten | `[Kirschtomaten, Sherrytomaten]` |
| Moehren | `[Karotten, Gelbe Rueben]` |
| Auberginen | `[Melanzani]` |
| Zucchini | `[Courgette]` |
| Rucola | `[Rauke]` |
| Koriander | `[Cilantro]` |
| Garnelen | `[Shrimps, Crevetten]` |
| Sahne | `[Rahm]` |
| Quark | `[Topfen]` |
| Gruene Bohnen | `[Fisolen]` |

Doppelnamen mit `/` im Namen (z. B. `Garnelen / Shrimps`) sind nur fuer wirklich gleichwertig gaengige Begriffe — alles andere geht ins YAML.

---

## §15 Dateiname-Konvention

Dateiname wird aus dem Necta-Namen abgeleitet:

```
Necta-Name:  Moehren: frisch, Streifen 3mm
Dateiname:   Moehren_frisch_Streifen_3mm.md

Necta-Name:  Rinderfilets: frisch, Argentinien, Mittelstueck, 300g / (Dry Aged 28 Tage)
Dateiname:   Rinderfilets_frisch_Argentinien_Mittelstueck_300g.md

Necta-Name:  Xanthan: trocken, pulverfoermig / (20g pro 1l, vegan, Bio)
Dateiname:   Xanthan_trocken_pulverfoermig.md
```

**Regeln:**
- **Pflichtfelder mit Unterstrichen** verbinden, optionale Klammer-Infos NICHT im Dateinamen.
- **Umlaute aufgeloest** im Dateinamen (`ae`/`oe`/`ue`/`ss`) — Markdown-Body darf Umlaute behalten fuer Lesbarkeit.
- **Erster Buchstabe des Produktnamens gross**, Eigenschaften klein (Ausnahme: Abkuerzungen wie `TK`).
- **Doppelpunkt** durch Unterstrich ersetzen.

### §15.1 Selbsterklaerend

Der GP-Name allein muss das Produkt identifizieren — kein Wissen ueber Familie oder YAML noetig.

```
Schlecht:  Filet_classic.md                    ← welche Tierart? welcher Schnitt?
Gut:       Rinderfilet_frisch_Mittelstueck.md  ← alles klar
```

### §15.2 Ordner-Mapping aus YAML

Der vollstaendige Datei-Pfad eines GP ergibt sich deterministisch aus `warengruppe` + `produkttyp`:

```
03_KUECHE/03.01_Grundprodukte/<warengruppe-slug>/<produkttyp-slug>/<dateiname>.md
```

| YAML-Feld | Beispiel | Ordner-Slug |
|-----------|----------|-------------|
| `warengruppe: "01 Gemuese & Blattsalat"` | → | `01_Gemuese_Blattsalat/` |
| `produkttyp: "Gemuese"` | → | `Gemuese/` |
| `name: "Moehren: frisch, ganz"` | → | `Moehren_frisch_ganz.md` |

**Slug-Regeln:**
- `&` und `und` → entfernen
- Mehrfach-Spaces → einzelner Underscore
- Umlaute aufloesen (`ae`/`oe`/`ue`/`ss`)
- Leerzeichen → Underscore
- Erstes Wort gross

**Validierung:** `vault_audit_sync` prueft, ob jede GP-Datei im erwarteten Pfad liegt. Falls nicht → Status `needs_review`.

**Skript-Logik (Pseudocode):**
```python
expected_path = (
    "03_KUECHE/03.01_Grundprodukte/"
    + slug(yaml.warengruppe) + "/"
    + slug(yaml.produkttyp) + "/"
    + slug(yaml.name) + ".md"
)
```

Damit kann das Verschieben/Anlegen voll automatisiert werden — sobald `warengruppe` + `produkttyp` im YAML stehen, weiss das Skript, wohin die Datei gehoert.

---

## §16 Allergen-Vererbung

> Detaillierte Aggregations-Regeln + Konfidenz-Logik siehe `[[Regelwerk_Lieferantenartikel]]` §10. Hier nur Kurzform + GP-spezifische Aspekte.

**Vererbungs-Pfad:**
```
Lieferantenartikel (LA)  →  Grundprodukt (GP)  →  Basisrezept (BR)
   (Quelle)                  (Aggregation §10)        (Snapshot)
```

- Allergene werden auf **LA-Ebene** gepflegt (14 EU-Keys, Werte: `enthalten` / `spuren` / `nicht_enthalten` / `unbekannt`).
- Mehrere LAs pro GP werden via **ALL-MAXIMAL** aggregiert (LA-Regelwerk §10):
  - Hierarchie: `enthalten > spuren > nicht_enthalten > unbekannt`
  - Bei Konflikt zwischen LAs gewinnt der hoechste Rang
- Aggregations-Ergebnis landet im GP-YAML als `allergene` + `allergene_quelle: la_union` + `allergene_konfidenz: HIGH | MED | LOW | NONE`.
- Beim Basisrezept-Snapshot wird die Vereinigung aller Zutaten-GP-Allergene gebildet (gleiche Hierarchie).

**Konfidenz-Logik (LA-Regelwerk §10):**

| Situation | `allergene_konfidenz` |
|---|---|
| Alle aktiven LAs mit identischem Profil | HIGH |
| LAs unterscheiden sich nur in `unbekannt` vs. konkretem Wert | HIGH |
| LAs unterscheiden sich auf gleicher Hierarchie-Stufe | MED |
| Konflikt `enthalten` ↔ `nicht_enthalten` | LOW + Status `needs_allergen_review` |
| GP hat keinen LA mit gepflegten Allergenen | NONE |

**Bei Markendifferenzen** (siehe §5): Wenn die Marke Pflicht-Bestandteil des GP-Namens ist, ist der Lead-LA (LA-Regelwerk §8 Heuristik) die Marken-Variante. Allergen-Profil weicht u. U. von generischer Variante ab.

**Owner pro GP:** Status `needs_allergen_review`, sobald `allergene_quelle = unbekannt` ODER `allergene_konfidenz = LOW` ODER LA-Allergene unvollstaendig.

### Sonderfall: Nebenprodukt-Derivate (`is_derivat = 1`)

> Für Derivat-GPs nach §11.2 (Schalen, Karkassen, Parüren, Stiele, Knochen, Säfte, Zesten, Fett etc.) gilt eine **LIVE-Vererbung** statt Snapshot-Aggregation.

| Aspekt | Verhalten für Derivat-GPs |
|---|---|
| `allergene` | **NICHT als Snapshot ins YAML schreiben.** Stattdessen zur Laufzeit aus `derivat_von_gp_id` (Mutter-GP) ziehen. |
| `allergene_quelle` | `derivat_inherited` (statt `la_union`) |
| `allergene_konfidenz` | Vererbt vom Mutter-GP (keine eigene Berechnung). Falls Mutter `LOW` → Derivat `LOW`. |
| Auslöser bei Änderung | Wenn Mutter-Allergene sich ändern (LA-Update), aktualisieren sich Derivat-Allergene **automatisch** beim nächsten Lesen. Kein Re-Sync nötig. |
| LA-Aggregation | Wird übersprungen (`requires_la = 0`). |

**Begründung:** Derivate sind Bestandteile des Mutter-Produkts — Allergen-Profil identisch per Definition. Snapshot würde Drift erzeugen und Wartungsaufwand verdoppeln.

**Implementierung im Vault-Sync (Skript 208 / Phase 7):** Beim MD-Generieren für Derivat-GPs wird der Mutter-GP geladen und seine Allergen-Werte ins YAML kopiert, mit Hinweis-Kommentar `# inherited from <mutter_gp_name>`. Keine eigene Aggregation in der DB.

---

## §17 Necta-Export-Mapping

Nur die **Necta-Pflichtfelder** im GP-Frontmatter werden exportiert. Vault-only-Felder bleiben im Vault.

> **Schreibrichtungs-Verbot fuer LA-Felder:** Die `wawi_*` + `lieferantenartikel` + `preis_*`-Felder werden vom Sync-Skript aus der SQL-Master-DB geschrieben (LA-Regelwerk §9). Manuelle YAML-Edits an diesen Feldern werden beim naechsten Sync-Lauf ueberschrieben.

### Necta-Export-Felder (GP-eigene Daten)

| YAML-Feld (GP) | Necta-Spalte | Pflicht | Hinweis |
|----------------|--------------|---------|---------|
| `name` | Produktname | ✅ | Vollstaendiger Name nach §6 |
| `produktcode` | Produktcode | ✅ | wird in Necta vergeben |
| `warengruppe` | Warengruppe | ✅ | 01-15 nach §3 |
| `produkttyp` | Produktklasse | ✅ | Subtyp innerhalb der Warengruppe |
| `sub_kategorie` | Produktkategorie | optional | z. B. `13.1 Komponenten warm` |
| `marke` | Marke | bedingt | nur wenn §5 Tiebreaker greift |
| `produktbereich` | Produktbereich | ✅ | Default: `Lebensmittelgrundartikel` |
| `klassifizierung` | Klassifizierung | optional | freier Text |
| `kalkulationseinheit` | Kalkulationseinheit | ✅ | `kg` / `l` / `Stk` / `Bund` / `Pkt` |
| `putzverlust` | Putzverlust % | ✅ | 0 wenn nicht relevant |
| `garverlust` | Garverlust % | ✅ | 0 wenn nicht relevant |
| `relationen` | Einheiten-Relation | bedingt | Pflicht wenn Kalkulationseinheit ≠ kg/g |
| `allergene` | Allergene (14 EU) | ✅ | siehe §16 Vererbung |
| `allergene_konfidenz` | Allergen-Konfidenz | ✅ | HIGH/MED/LOW/NONE (LA-Regelwerk §10) |
| `naehrwerte` | Naehrwerte | optional | pro 100 g |
| `haltbarkeit_tage` | Haltbarkeit | optional | Lagerlogistik |
| `lagertemperatur_min/max` | Lagertemperatur | optional | Lagerlogistik |
| `herkunft` | Herkunft | optional | Land/Region |
| `eigenschaften` | Eigenschaften-Flags | optional | bio/vegan/vegetarisch/glutenfrei/laktosefrei/regional/saisonal |

### LA-Mapping-Felder (gespiegelt aus SQL, LA-Regelwerk §9)

| YAML-Feld | Quelle | Hinweis |
|-----------|--------|---------|
| `wawi_gp_id` | `wawi_grundprodukte.gp_id` | konstant nach Anlage |
| `wawi_supplier_items[]` | aktive `wawi_gp_la.supplier_item_id` | nur `ausgelistet=0` |
| `default_supplier_item_id` | Lead-LA per LA-Regelwerk §8 | genau eines |
| `default_lieferant` | `suppliers.name` des Lead-LA | menschenlesbar |
| `n_lieferantenartikel` | COUNT aktiver LAs | |
| `n_aktive_lieferanten` | COUNT distinct supplier_id | |
| `preis_default_netto` | `ek_preis` des Lead-LA | EUR/kalkulationseinheit |
| `preis_min_netto` | min ueber alle aktiven LAs | |
| `preis_max_netto` | max ueber alle aktiven LAs | |
| `preis_aktualisiert_am` | letzter Sync-Lauf | ISO-Date |
| `lieferantenartikel[]` | formattierte Liste § 9 | `'[[Lieferant]] (Item: <id>, ArtNr: <nr>[, bevorzugt])'` |

**Necta-Export der LA-Felder:** `default_supplier_item_id`, `default_lieferant`, `preis_default_netto` werden in Necta-Stammdaten gespiegelt. Lieferantenartikel-Stammdaten selbst leben in Necta — im Vault nur Mapping + Snapshot fuer Kalkulation.

### Vault-only (NICHT exportiert)

`aroma`, `textur`, `typische_kochtechniken`, `saison`, `referenzen`, `tags`, `status`, `synonyme`, `letzte_sync`, `angelegt`.

### Derivat-GP-Felder (Vault-only, NICHT in Necta)

| Feld | Quelle | Zweck |
|---|---|---|
| `is_derivat` | `wawi_gp_v2.is_derivat` | 0/1-Flag |
| `derivat_von_gp_id` | `wawi_gp_v2.derivat_von_gp_id` | FK zum Mutter-GP |
| `derivat_typ` | Vokabular §11.2 | schale / zeste / karkasse / paruere / abschnitt / stiel / strunk / gruen / saft / fett / haut / knochen / innerer_kern |
| `derivat_anteil_pct` | optional 0-100 | Anteil am Mutter-Gewicht |
| `requires_la` | Default 1, Derivate 0 | Steuert LA-First-Pipeline |

**Necta-Export-Filter:** GPs mit `is_derivat=1` werden vom Necta-Export ausgeschlossen (`WHERE is_derivat=0 OR is_derivat IS NULL`). Derivate sind Vault-interne Modellierung für Rezept-Match + Allergen-Vererbung, kein einkaufbares Produkt.

---

## §18 Governance

**Owner:** Dominique Beutin.

**Review-Cycle:** Quartalsweise via Skill `vault_audit_sync` — prueft:
- GPs ohne Pflicht-Pflichtangaben (§8) → Status `needs_review`
- GPs ohne `default_lieferantenartikel` → Status `needs_supplier_match`
- GPs mit `allergene_quelle: null` oder `unbekannt` → Status `needs_allergen_review`
- Doppelte GP-Namen
- Anti-Pattern-Verstoss (§12) durch Regex-Check

**Bei Verstoss:** Status auf `needs_review`, kein automatischer Block — die Datei bleibt nutzbar, taucht aber im Audit-Report auf.

**Status-Werte (vollstaendige Liste):**

| Status | Bedeutung | Quelle |
|--------|-----------|--------|
| `entwurf` | GP angelegt, weder LA noch alle Pflichtfelder | GP-Anlage initial |
| `needs_supplier_match` | LA-Match steht aus, Domain-Defaults aktiv | nach Anlage ohne LA |
| `needs_allergen_review` | Allergen-Quelle `unbekannt` oder Konfidenz `LOW` | LA-Regelwerk §10 |
| `needs_lead_la_review` | Kein Lead-LA bestimmbar (alle Kandidaten in Filtern rausgefallen) | LA-Regelwerk §8 |
| `needs_review` | Generischer Audit-Treffer, manuelle Pruefung noetig | vault_audit_sync |
| `aktiv` | Alle Pflichtfelder erfuellt + Lead-LA zugeordnet + Allergene HIGH/MED | Steady State |
| `archiviert` | GP nicht mehr aktiv, bleibt fuer Historie | manuell |

**Neue Pflichtangaben (§8.X) entstehen, wenn:**
- Ein neuer Begriff 3× in Excel/Briefing/Kunden-Anfrage auftaucht und in keine bestehende §8-Regel passt.

**Vokabular-Erweiterung (§9):** Owner entscheidet ueber Aufnahme neuer Verarbeitungs-/Zustands-Werte.

---

## §19 Beispiele pro Warengruppe

### 01 Gemuese & Blattsalat

```text
Moehre: frisch, ganz
Moehre: frisch, Streifen 8 mm / (Convenience)
Moehre: frisch, regional, ganz / (Bio)
Moehre: TK, Wuerfel 10 mm
Blattspinat: frisch, ganz
Blattspinat: TK, gehackt
Feldsalat: frisch, ganz
Feldsalat: frisch, geputzt / (Convenience)
Paprika rot: frisch, ganz
Paprika rot: frisch, Streifen 5 mm
Kartoffel: festkochend, frisch, ganz                               ← §8.12 Kochtyp Pflicht
Kartoffel: vorwiegend festkochend, frisch, geschaelt, Wuerfel 15 mm / (Convenience)
Kartoffel: mehlig, frisch, ganz
Kartoffel Linda: festkochend, frisch, ganz                         ← Sortenname Bestandteil
Kartoffel Belana: festkochend, frisch, ganz
Kartoffel Agria: vorwiegend festkochend, frisch, ganz
Suesskartoffel: frisch, ganz                                       ← KEIN Kochtyp (Ipomoea batatas, andere Art)
Suesskartoffel: frisch, geschaelt, Wuerfel 10 mm
Tomate: frisch, ganz
Cherrytomate: frisch, ganz
Dosentomate: konserviert, ganz
Getrocknete Tomate: getrocknet, eingelegt    ← Derivat (§11)
```

### 02 Obst

```text
Apfel Boskoop: frisch, ganz                     ← Sortenname Bestandteil
Apfel Granny Smith: frisch, ganz
Apfel: frisch, Brunoise (Feinwuerfel) 3 mm
Zitrone: frisch, ganz
Limette: frisch, ganz
Erdbeeren: frisch, ganz                         ← §6.1b Sammelware (Beeren)
Erdbeeren: TK, ganz
Himbeeren: TK, ganz
Mango Kent: frisch, ganz                        ← Sortenname Bestandteil
Mango Tommy Atkins: frisch, ganz
Ananas: frisch, ganz
Ananas: konserviert, Wuerfel
Mangopueree Boiron: TK, Pueree                  ← Marke (§5 Sensorik)
Dattel: getrocknet, entkernt                    ← Steinobst = Stueckware, Singular
Cranberries: getrocknet, ganz                   ← §6.1b Sammelware (Beeren)
```

### 03 Kraeuter

```text
Basilikum: frisch, ganz
Basilikum: TK, gehackt
Petersilie glatt: frisch, ganz
Petersilie kraus: frisch, ganz
Dill: frisch, ganz
Rosmarin: frisch, ganz
Rosmarin: getrocknet, ganz
Thymian: frisch, ganz
Koriander / Cilantro: frisch, ganz
Minze: frisch, ganz
Schnittlauch: frisch, ganz
Schnittlauch: TK, geschnitten
```

### 04 Fleisch, Gefluegel & Wild

```text
Rinderfilet: frisch, Mittelstueck, Portion, 300 g
Rinderfilet: frisch, Argentinien, Mittelstueck / (Dry Aged 28 Tage)
Entenkeule: frisch, Portion, 350 g
Entenbrust: frisch, Portion, 400 g
Haehnchenbrust: frisch, Portion, 180 g
Kalbsruecken: frisch, ganz
Lammkeule: frisch, entbeint
Rehruecken: frisch, pariert
Hackfleisch gemischt: frisch, ganz
```

### 05 Fisch & Meeresfruechte

```text
Zanderfilet: frisch, filetiert, Portion, 160 g
Lachsfilet: frisch, Norwegen, filetiert, Portion, 180 g
Lachsfilet: frisch, filetiert, Portion, 180 g / (Bio)
Kabeljaufilet: frisch, Nordostatlantik, filetiert / (MSC)
Thunfischfilet: roh, filetiert / (Sashimi-Qualitaet)
Garnele / Shrimp: TK, geschaelt, 16/20
Jakobsmuschel: frisch, ohne Schale
Tintenfischring: TK, geschnitten
Raeucherlachs: geraeuchert, geschnitten
```

### 06 Molkerei & Eier

```text
Vollmilch: frisch, pasteurisiert, 3,5 %
Sahne / Rahm: frisch, 30 %
Schlagsahne: frisch, 35 %
Butter: frisch, 82 %
Creme fraiche: frisch, 30 %
Mascarpone: frisch, ganz
Parmesan / Parmigiano Reggiano: frisch, gerieben, 24 Monate gereift, Hartkaese, Rohmilch
Mozzarella: frisch, Bueffelmilch, 125 g, jung, Weichkaese, pasteurisiert
Eier: frisch, Groesse M                          ← §6.1b Sammelware (Eier)
Eier: frisch, Groesse L / (Bio, Freiland)
Eigelb: frisch, pasteurisiert                    ← Masse-Derivat, Singular
```

### 07 Getreide & Huelsenfruechte

```text
Linsen rot: trocken, ganz / (glutenfrei)                 ← §6.1b Sammelware (Huelsenfruechte)
Linsen gruen: trocken, ganz / (glutenfrei)
Kichererbsen: trocken, ganz / (glutenfrei)
Kichererbsen: konserviert, ganz
Reis Basmati: trocken, ganz / (glutenfrei)               ← Sorte Pflicht (§8.9)
Reis Jasmin: trocken, ganz / (glutenfrei)
Reis Sushi: trocken, ganz / (glutenfrei)
Reis Arborio: trocken, ganz / (glutenfrei)
Couscous: trocken, ganz
Bulgur: trocken, ganz
Quinoa: trocken, ganz / (Bio, glutenfrei)
```

### 08 Teigwaren

```text
Spaghetti: trocken, lang                  ← §6.1-Ausnahme: Pluralia tantum (Lehnwort, kein dt. Singular)
Penne: trocken, ganz
Tagliatelle: trocken, breit
Gnocchi: vorgegart, ganz
Tortellini Ricotta: vorgegart, gefuellt
```

### 09 Backwaren & Suesswaren

```text
Brioche-Broetchen: frisch, 70 g pro Stueck
Sauerteigbrot: frisch, ganz
Tortenboden: trocken, rund 26 cm
Bitterschokolade: trocken, Kuvertuere, 70 % Kakao   ← §8.10 Kakaoanteil
Vollmilchschokolade: trocken, Tafel, 35 % Kakao
Salzbrezel: trocken, ganz
Mandeln gesalzen: trocken, ganz                    ← Snack-Mix, §6.1b Sammelware (Nuesse)
```

### 10 Gewuerze & Wuerzmittel

```text
Salz: trocken, fein
Fleur de Sel: trocken, fein
Schwarzer Pfeffer: trocken, ganz
Schwarzer Pfeffer: trocken, gemahlen
Paprikapulver edelsuess: trocken, gemahlen
Kurkuma: trocken, gemahlen
Cumin / Kreuzkuemmel: trocken, ganz
Cumin / Kreuzkuemmel: trocken, gemahlen
Currypulver Madras: trocken, gemahlen
Zimtstange: trocken, ganz
Vanilleschote: trocken, ganz
Sojasauce: fluessig, dunkel
Worcestersauce: fluessig
Senf Dijon: fluessig
Tabasco: fluessig
```

### 11 Essig & Oel

```text
Olivenoel: fluessig, extra vergine
Olivenoel: fluessig, Arbequina, extra vergine
Rapsoel: fluessig, raffiniert
Sesamoel: fluessig, geroestet
Trueffeloel: fluessig
Balsamico: fluessig, dunkel
Balsamico: fluessig, weiss
Apfelessig: fluessig
Reisessig: fluessig
Sherry-Essig: fluessig
```

### 12 Trockenprodukte

```text
Weizenmehl Type 405: trocken, fein            ← §8.11 Type Pflicht
Weizenmehl Type 550: trocken, fein
Weizenmehl Type 1050: trocken, fein
Pizzamehl Type 00: trocken, fein
Mandelmehl: trocken, fein / (glutenfrei)
Speisestaerke: trocken, pulverfoermig / (glutenfrei)
Gelatine: trocken, Blaetter
Agar: trocken, pulverfoermig / (5 g pro 1 l)
Xanthan: trocken, pulverfoermig / (2 g pro 1 l, glutenfrei)
Zucker: trocken, fein
Puderzucker: trocken, fein
Paniermehl: trocken, fein
Semmelbroesel: trocken, grob
Mandeln: trocken, ganz                             ← §6.1b Sammelware (Nuesse)
Walnusskerne: trocken, ganz
Sesamsaat: trocken, ganz                           ← Saat = Masse-Begriff, Singular
```

### 13 Convenience & Komponenten

> Subtyp (13.1-13.7) wird im YAML-Feld `sub_kategorie` gefuehrt, NICHT im Produktnamen.

```text
Kartoffelgratin: TK, vorgegart, Portion 200 g                        ← sub_kategorie: 13.1
Polenta-Schnitte: TK, vorgegart, Portion 80 g                        ← sub_kategorie: 13.1
Kartoffelsalat: frisch, fertig, kg                                   ← sub_kategorie: 13.2
Demi-Glace fertig: konserviert, fluessig                             ← sub_kategorie: 13.3
Hollandaise: konserviert, fluessig                                   ← sub_kategorie: 13.3
Rinderbruehe fertig: konserviert, fluessig                           ← sub_kategorie: 13.4
Veloute Champignon: konserviert, fluessig                            ← sub_kategorie: 13.4
Tortenboden Mandel: trocken, vorgebacken, rund 26 cm                 ← sub_kategorie: 13.5
Mousse au Chocolat Pulver: trocken, pulverfoermig                    ← sub_kategorie: 13.5
Essbare Bluemchen-Mix: frisch, ganz                                  ← sub_kategorie: 13.6
Crouton-Mischung: trocken, gewuerfelt                                ← sub_kategorie: 13.6
Knusperstreusel Himbeer-Joghurt: trocken, Granulat                   ← sub_kategorie: 13.6  (Crunch/Dekor-Schuettgut — Form=Granulat, NICHT Portion)
Mini-Quiche: TK, vorgebacken, Portion 30 g pro Stueck                ← sub_kategorie: 13.7
Vol-au-vent gefuellt: TK, vorgebacken                                ← sub_kategorie: 13.7
```

### 14 Vegane Ersatzprodukte

```text
Beyond Meat Patty: TK, Portion, 113 g pro Stueck / (vegan)
Hackfleisch-Alternative Soja: TK, ganz / (vegan)
Lachs-Alternative pflanzlich: TK, filetiert / (vegan)
Hafermilch: fluessig / (vegan)
Mandelmilch: fluessig / (vegan)
Pflanzendrink-Kaese Cheddar-Style: frisch, geschnitten / (vegan)
Ei-Alternative pflanzlich: fluessig / (vegan)
Tofu natur: frisch, fest, Block 400 g / (vegan)
Tempeh: frisch, fest, Block 200 g / (vegan)
```

### 15 Getraenke

```text
Riesling Mosel: fluessig, trocken, 12,5 vol-%, Jahrgang 2023
Pinot Noir Burgund: fluessig, trocken, 13 vol-%, Jahrgang 2022
Pils: fluessig, 4,9 vol-%
Cidre Bretagne: fluessig, halbtrocken, 4,5 vol-%
Cointreau: fluessig, 40 vol-%       ← Marke + Vol-% (§8.6)
Triple Sec generisch: fluessig, 30 vol-%
Wodka Premium: fluessig, 40 vol-%
Aperol: fluessig, 11 vol-%
Mineralwasser still: fluessig, kg
Mineralwasser sprudelnd: fluessig, kg
Orangensaft: fluessig, frisch gepresst / (Bio)
Apfelsaft naturtrueb: fluessig
Cola: fluessig
Espresso-Bohne Arabica: trocken, ganz, dunkle Roestung    ← §8.6 Kaffee
Filter-Kaffee Arabica/Robusta-Mix: trocken, gemahlen, mittlere Roestung
Schwarztee Earl Grey: trocken, lose
Zuckersirup: fluessig
Grenadine: fluessig
```

---

## §20 Merksaetze

- **Warengruppe = WAS**, Produkttyp = ART, Attribute = WIE.
- **Singular** (Lemma-Form) verwenden — ausser (a) Pluralia tantum / Lehnwoerter (Spaghetti, Gnocchi, Penne) und (b) **Sammelware** in vier Gruppen: Eier, Huelsenfruechte (Linsen, Kichererbsen), Nuesse/Kerne (Mandeln, Walnuesse), Beeren (Erdbeeren, Himbeeren). Echte Stueckware (Apfel, Garnele, Dattel) bleibt Singular. → §6.1.
- **Grammatur** nur bei Portion.
- **Marke** nur wenn 4-Punkt-Tiebreaker (§5) greift.
- **Derivate** sind eigene GPs (§11): Konservierungs-Derivate wie Sauerkraut/Kimchi/Pickles (§11.1, `is_derivat=0`) ODER Nebenprodukt-Derivate wie Schalen/Karkassen/Parüren/Säfte (§11.2, `is_derivat=1` + `derivat_von_gp_id`).
- **Sorte** ist Bestandteil des Produktnamens, wo relevant (Apfel Boskoop, Reis Basmati, Mango Kent).
- **Verpackungsgewicht** ≠ GP-Stueckgewicht.
- **Allergene** vererben von LA → GP → Basisrezept (§16, CLAUDE.md).
- **Beispiele in §19 sind verbindlich.**
- **Struktur bleibt stabil – Attribute machen die Differenz.**

---

## Aenderungshistorie

| Datum | Version | Aenderung |
|-------|---------|-----------|
| 2026-06-02 | v3.4.0 | **§3 Subkategorien als kontrolliertes Vokabular für ALLE 15 WG formalisiert + WG01/02/04 verfeinert.** Bisher hatte nur WG13 nummerierte Subkategorien (13.1-13.7); WG1-12/14/15 waren in `wawi_gp_v2.sub_kategorie` zu 100 % NULL. Single Source of Truth ist jetzt die DB-Tabelle `lookup_produkttyp` (kanonischer String = `sub_code + ' ' + name`, z.B. `01.1 Fruchtgemüse`), gelesen von App-Dropdown (`list_produkttypen`), GP-Suggest-Prompt (`build_subkat_block`) UND Bulk-Klassifikator (Skript 107). Migrationen: `214` (sub_code-Vergabe §3-Reihenfolge + WG13-`(fertig)`-Angleich an Bestand), `215` (Verfeinerung). **WG01 Gemüse 3→9** (Frucht-/Wurzel-Knollen-/Kohl-/Zwiebel-/Blatt-Stiel-Gemüse, Pilze, Blattsalat, Sprossen, Püree — reine Produkttypen, kein Zustand). **WG02 Obst 2→7** (Kern-/Stein-/Beeren-/Zitrus-/exotisch/Melonen/Püree). **WG04 Fleisch: Gliederung von Produkttyp auf TIERART umgestellt** (Rind/Kalb/Schwein/Lamm/Geflügel/Wild/Wurstwaren — User-Entscheidung; Zuschnitt→verarbeitung/form, Allergen→tags). WG03,05-15 unverändert §3. Neue Spalte `lookup_produkttyp.hinweis` (Glossar je Subkategorie) für treffsichere KI-Einsortierung. App-Seite: `sub_kategorie` ist Pflichtfeld-Whitelist in `validate_gp_input` (Phase A weich: wenn gesetzt → muss gültig sein; Derivate ausgenommen), GpEditor-Dropdown ist constrained `<select>` mit Alt-Wert-Injektion, toter Rust-`master_sub_kategorien()` gelöscht. Anlass: User-Review-Gate Task 1 (saubere GP-Datenbasis, LA-First). |
| 2026-04-02 | v1.0/v2.0 | WaWi-Namenskonvention v2.0 (PKL-Nummerierung, Necta-Syntax, Anti-Patterns, Beispiele pro PKL) |
| 2026-04-29 | v3.0 | Konsolidierung mit Inbox-Regelwerk: 15 Warengruppen statt 14 PKL, einheitliche Getraenke-Klasse, Convenience neu strukturiert (13.1-13.7), Marken-4-Punkt-Tiebreaker, Derivate-Trennregel §11, Pflichtangaben fuer Kaese/Reis/Schoko/Mehl ergaenzt, Necta-Export-Mapping §17, Light-Governance §18 |
| 2026-04-29 | v3.0.1 | Korrektur: Untergruppen-Praefix vor Produktnamen entfernt (war Fehler aus alter WaWi-v2-Konvention) — Produkttyp gehoert in Ordner-Pfad und YAML-Feld `produkttyp`, NICHT in den Necta-Namen. TK als zugelassene Abkuerzung fuer `tiefgekuehlt` ergaenzt (§9). Convenience-Subtyp 13.1-13.7 wandert ins YAML-Feld `sub_kategorie`, nicht in den Namen. |
| 2026-04-29 | v3.0.2 | Cross-Verweise auf `[[Regelwerk_Lieferantenartikel]]` v1.0 aufgenommen: §16 Allergen-Vererbung verweist auf §10 Aggregation ALL-MAXIMAL + Konfidenz-Logik (HIGH/MED/LOW/NONE), `allergene_konfidenz`-Feld ergaenzt. §17 Necta-Export-Mapping erweitert: `wawi_gp_id`, `wawi_supplier_items`, `default_supplier_item_id`, `default_lieferant`, `n_lieferantenartikel`, `n_aktive_lieferanten`, `preis_default/min/max_netto`, formattierte `lieferantenartikel`-Liste — alle gespiegelt aus SQL (Schreibrichtungs-Verbot, LA-Regelwerk §9). §18 Status-Werte vollstaendige Liste inkl. `needs_lead_la_review` + `needs_supplier_match`. |
| 2026-05-19 | v3.1.0 | **§8.12 Kartoffeln: Kochtyp-Pflichtangabe** (mehlig / vorwiegend festkochend / festkochend) ergaenzt. Gilt fuer Solanum tuberosum, NICHT fuer Suesskartoffeln (Ipomoea batatas) oder Topinambur. Sortenangabe wenn bekannt (Linda, Belana, Cilena, Agria). Wenn Kochtyp aus Designation NICHT ableitbar → Status `needs_review` mit Grund `kochtyp_pflicht`. §19 Beispiele Klasse 01 entsprechend ergaenzt. Anlass: Kluth-Klassifikator-Test zeigte dass Catering-Frischlieferanten keinen Kochtyp in Designation angeben — explizite Regel + needs_review-Markierung statt stille Default-Annahme. |
| 2026-05-22 | v3.2.0 | **§11 Derivate restrukturiert in zwei Sub-Sektionen** zur Konflikt-Aufloesung. §11.1 = bisherige Konservierungs-Derivate-Trennregel (Sauerkraut/Kimchi/Pickles, `is_derivat=0`). §11.2 NEU = Nebenprodukt-Derivate (Schalen, Karkassen, Paruren, Stiele, Knochen, Saefte, Zesten, Fett, Haut, Strunk, Gruen, Innerer Kern) mit `is_derivat=1` + `derivat_von_gp_id`-FK zum Mutter-GP + `requires_la=0`. Spiegelung der §11-Logik aus [[Regelwerk_Basisrezepte]] v1.1. Schema-ALTER fuer `wawi_gp_v2`: 5 neue Spalten (is_derivat, derivat_von_gp_id, derivat_typ, derivat_anteil_pct, requires_la). **§16 Allergen-Vererbung** erweitert um LIVE-Vererbung fuer Derivat-GPs (kein eigener Snapshot, Werte vom Mutter zur Laufzeit gelesen, `allergene_quelle='derivat_inherited'`). **§17 Necta-Export-Mapping** erweitert: Derivat-Felder als Vault-only markiert, Necta-Export-Filter `WHERE is_derivat=0 OR is_derivat IS NULL`. **§12 Anti-Patterns** Saft-Beispiel aktualisiert auf `Zitrone: frisch, Saft`-Schema. **§20 Merksaetze** Derivate-Punkt um Doppel-Logik ergaenzt. Anlass: User-Frage zu „leeren GPs" wie Fleisch-Abschnitten + Gurkenschale; Kochbuch-Scan zeigt ~500 Derivat-Vorkommen (1 alle 2.7 Rezepte). |
| 2026-05-29 | v3.3.0 | **§6.1 Plural-Pflicht → Singular-Pflicht umgekehrt.** GP-Namen ab sofort im SINGULAR (Lemma-Form): `Moehre`, `Tomate`, `Kartoffel`, `Ei`, `Rinderfilet`, `Garnele`. Einzige Ausnahme = Pluralia tantum / Lehnwoerter ohne brauchbaren deutschen Singular (`Spaghetti`, `Penne`, `Tagliatelle`, `Gnocchi`, `Tortellini`, `Antipasti`, `Cornflakes`, `Pommes frites`). Auch gastro-typisch plural gehandelte Meeresfruechte werden Singular (`Garnele`, `Jakobsmuschel`). Betroffen: §6.1 (Tabelle + Ausnahmeliste + Match-Semantik-Note), Produktname-Zeile §6-Tabelle, §12 Anti-Patterns (`Ei`/`Eier`, `Aprikose`, `Fischfilet`, `Zwiebel`, `Mandel`), §19 alle Warengruppen-Beispiele, §20 Merksatz. Matching unveraendert — `stemming::stem_german` konvergiert beidseitig, Umstellung ist reine Naming-Konsistenz. gp_naming.rs erzwingt keinen Plural (nur Kommentar-Hinweise angepasst). Anlass: User-Entscheidung „Singular ist die Truth" (2026-05-29) — vorausgehende Daten-/Code-Pruefung zeigte, dass alter Plural-Standard zwar konsequent gepflegt war, der User aber Singular als saubere Grundform festlegt. Nachgelagert: Phase G.1 benennt alle bestehenden GPs in der DB per Gemini um (Preview + Accept, kein Auto-Write). |
| 2026-06-01 | v3.3.2 | **§9 Form-Vokabular um `Granulat` erweitert + Portion-Schuettgut-Verbot.** Form (Geometrie) hatte nur 5 Tokens (Ganz/Filet/Portion/Pueree/Pulver) — fuer koerniges/krosses Schuettgut (Crunch, Knusperstreusel, Streusel, Crumble, Knusperperlen, Dekor-Granulat) gab es keinen Token, die KI wurde faelschlich in `Portion` gezwungen. NEU: `Granulat` (Abgrenzung zu `Pulver`=fein). Klargestellt: `Portion` NUR fuer einzeln portionierte Stuecke MIT Grammatur (§7), NIE fuer lose Ware. Betroffen: §9 (Form-Liste + Erklaerung), §3.13-Tabelle (Form (Geometrie)-Zeile), §19 WG-13-Beispiel `Knusperstreusel Himbeer-Joghurt: trocken, Granulat ← 13.6`. App-Seite: `ai_suggest_gp`-Prompt (TASK_PROMPT_GP_SUGGEST) um Convenience/Dekor-Guidance + Granulat geschaerft (gp_naming.rs validiert Form ohnehin nicht hart — reine Doku/Prompt-Aenderung). Anlass: LA-First-„KI-Vorschlag"-Test mit JORDA Crunch Raspberry & Yoghurt → KI schlug `Gebaeck mit Himbeere und Joghurt: trocken, Portion` vor (Produktname zu generisch, Form falsch). |
| 2026-05-29 | v3.3.1 | **§6.1 Ausnahme-Klasse (b) Sammelware ergaenzt.** Die Plural-Ausnahme ist nun zweigeteilt: (a) Pluralia tantum / Lehnwoerter (unveraendert) + (b) **Sammelware** — Stueckware, die im Handel ausschliesslich als Schuettgut/Kollektiv gefuehrt wird (§8-Angabe haengt an der Charge), in genau **vier geschlossenen Gruppen**: Eier; Huelsenfruechte (Linsen, Kichererbsen, getr. Bohnen/Erbsen); Nuesse & Kerne (Mandeln, Walnuesse, Haselnuesse, Pinien-/Cashew-/Kuerbis-/Sonnenblumenkerne, Pistazien); Beeren (Erd-, Him-, Heidel-, Brom-, Johannisbeeren, Cranberries). Echte Stueckware mit Einzel-Einheit bleibt Singular (Apfel, Garnele, Dattel, Aprikose). Betroffen: §6.1 (Tabelle ohne `Ei`-Zeile + neue Ausnahme-(b)-Liste + Grenzfall-Note), §12 Anti-Patterns (`Ei`/`Linse` jetzt Singular-statt-Plural-Fehler, `karamellisierte_Mandeln`→`Mandeln`), §19 (02 Erdbeeren/Himbeeren/Cranberries, 06 Eier, 07 Linsen/Kichererbsen, 09 Mandeln gesalzen, 12 Mandeln/Walnusskerne), §20 Merksatz. Matching weiterhin unveraendert (Stemmer konvergiert). Grenzfaelle (Trockenfruechte ausser den genannten, exotische Kerne, Espresso-Bohne) werden im Phase-G.1-Preview geflaggt + vom User in die geschlossene Liste uebernommen, nicht ad-hoc geraten. Anlass: User-Praezisierung „bei manchen macht Plural Sinn (Eier)" → Wahl der Sammelware-Kategorie statt Einzel-Ausnahme, damit G.1 deterministisch bleibt. |