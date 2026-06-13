# 16 — Masterplan: Kalkulation / Herstellkosten im Modul

> **Auslöser (Dominique, 2026-06-13):** Vorbild ist die echte DOEC-Excel
> `DOEC_WITHE_LABEL_v2.xlsx` (Oceandiva). „Sowas in der Art, nur **nicht so komplex** —
> wir müssen die Komplexität rausnehmen oder zumindest die **Herstellkosten im Modul
> rechnen** können." Dieser Masterplan beschreibt, wie ein **vereinfachtes, aber
> strukturiertes** HK-Modell in `platforms-foodalchemist` eingebunden wird —
> aufbauend auf M12 (HK1→HK2), eingehängt in GP → Rezept → Gericht → Paket →
> Concept → Foodbook. **UI-Vorbild = die bestehenden Module** (3-Panel · Detail-Panel ·
> Settings-CRUD wie die Vokabular-Seiten · Concepter-Editor-Tab).
> **Status:** Entwurf zur Abstimmung — KEIN Code, erst die Gates entscheiden.

---

## 0. Die eine Kernerkenntnis

Die DOEC-Excel ist im Kern **keine Hexerei**, sondern eine **Zuschlagskalkulation mit
benannten Kostenblöcken** auf den Wareneinsatz, plus eine Marge:

```
            Wareneinsatz (WE)              ← Lebensmittel-Kosten (= unser ek_total)
          + Verpackung                     ← €/kg bzw. €/Portion
          + Lohn / Produktion              ← Aktivzeit × Stundensatz  ◀── „Energy" = ZEIT×SATZ, NICHT Strom
          + Logistik                       ← Transport (Touren/Distanz)
          + Lager                          ← Einlagerung (nach Modulgröße)
          + Schwund                        ← % auf WE (5 %)
          ─────────────────────────────────
          = Herstellkosten (HK)
          × (1 + Marge 15 %)
          = EK an DOEC  (= unser VK)
```

**Unser M12 macht das heute mit EINEM Regler:** `HK2 = HK1 × (1 + hk2_zuschlag_pct) +
nebenkosten_eur`. Das ist korrekt, aber **opak** — alle Blöcke (Verpackung, Lohn,
Logistik, Lager, Schwund, Gemeinkosten) verschwinden in einem Prozentsatz. Der Kunde
sieht nicht, *woraus* die HK bestehen, und kann keinen Block einzeln steuern.

**Konsequenz für diesen Plan:** Wir ersetzen den einen Regler durch ein **kleines,
benanntes, team-konfigurierbares Kostenblock-Schema** (wenige Stellschrauben, klare
Defaults). Das ist „sowas in der Art" — die *Struktur* der Excel, ohne ihre 37 Tabs.

> **Wichtige Korrektur (aus dem Excel-Audit):** Die Tabs „Energy" / „Energy (ware + 6
> Stunden)" sind **Konzept-Namen** (das Event-Konzept „Connecting Energy by Flavour") +
> die **Personal-Stunden** (8 h vs. 6 h Vor-Ort). „Energy" ≠ Strom. Die teure
> Energie-Komponente ist **Personal-/Produktionszeit**. → Der frühere M15-Ansatz
> „Strom je Garmethode" war eine Fehllesung und ist verworfen; an seine Stelle tritt
> ein **Lohn-Block = Arbeitszeit × Stundensatz** (Daten haben wir: `arbeitszeit_min`).

---

## 1. Was die echte DOEC-Excel rechnet (Referenz)

37 Tabs, drei fachliche Ebenen — wir bilden nur die **Produkt-/HK-Ebene** sauber ab und
verweisen die Event-Ebene auf das Foodbook (später).

### 1.1 Ebene A — Produkt-Stammdaten (`Produkte`) = unsere GP/Rezepte
Pro Produkt: **Garungstyp** (Kalt/Mixen, …), **WE Rohstoff €/kg**, **Verpackung €/kg**,
WE+Verp €/kg, **Aktivzeit Vorbereitung [h]** je Charge. → entspricht unseren
Grundprodukten (Preis) + Rezepten (`ek_total_eur`, `arbeitszeit_min`).

### 1.2 Ebene B — Withe-Label-Produktpreis (`Withe Label` + `Kalkulation`)
Pro Produkt → Charge (5/20/50 kg): `HK = (WE+Verp)×kg + Lohn`, `VK an DOEC = HK×1,15`,
`Staffelpreis €/kg = VK/Charge`. **Lohn** = Σ Produktionszeiten (Warenannahme,
Küchenlogistik, Küchenadmin, Produktion, Spüle, Abfüllung) × **Stundensätze** (29–38 €/h)
+ Fixzeiten je Charge (je 15 min) + Spüle-Skalierung. Konstanten: Verpackung 0,25 €/kg,
Marge 15 %, Charge 5/20/50 kg.

### 1.3 Ebene C — Modul-HK je Pax-Staffel (`HK_Kap*` + `HK_Module_Master`)
Pro Modul (= unser Paket/Concept-Slot): Liste von Positionen mit **WE-Basis €/Pers**.
Dann (Beispiel „Connecting Fire & Flavour", `HK_Kap8`):
```
HK €/Pers   = Σ WE  +  Logistik  +  Lager(Modulgröße S/M/L)  +  Schwund(% × ΣWE)
EK an DOEC  = HK × (1 + 15 %)
```
Parametrisiert nach **Pax-Staffel** (100–199 / 200–500 / 500–800 / 800+ → günstigerer WE
bei mehr Menge) und **Modulgröße** (S < 8 Pos · M 8–20 · L > 20 → Lager-Tier).

### 1.4 Ebene D — Event-Cockpit (`Event_Cockpit`, `Energy`)
Self-Service: Module per Dropdown → `EK/Pers × Pax × Anteil`. Plus **Personal**
(Rollen × Anzahl × Stunden × €/h + Aufschlag %), **Logistik** (LKW/Distanz), **Equipment**
(Pauschalen), je mit eigenem Aufschlag → Event-Summe + Deckungsbeitrag.
→ Das ist bei **uns die Foodbook-/Angebots-Ebene** (Pax bindet erst dort, D-CON-5/F-12).

---

## 2. Vereinfachung — „die Komplexität rausnehmen"

| Aus der Excel | Im Modul v1 | Begründung |
|---|---|---|
| WE €/Pers, verlustkorrigiert | ✅ **da** (`ek_total_eur`, GL-02) | Kern existiert |
| Lohn = Σ 6 Produktionszeiten × 6 Stundensätze + Fixzeiten + Spüle-Skalierung | ◐ **vereinfacht:** EIN Lohn-Block = `arbeitszeit_min × Stundensatz` | wir haben *eine* Arbeitszeit je Rezept + Rollup (M10R); 6 Zeitarten = zu fein |
| Verpackung €/kg + Schalengröße + Zeit/Schale | ◐ **vereinfacht:** Verpackung als €/Portion-Block (optional) | Detailmodell später |
| Logistik (Touren, Distanz, Maut, Broich-Anteile) | ⏸ **Event-Ebene → Foodbook** (später) | gehört ans Angebot, nicht an die Produkt-HK |
| Lager nach Modulgröße S/M/L | ◐ **vereinfacht:** ein Lager-Block (% oder €/Portion), optional | Größenstaffel später |
| Schwund % auf WE | ✅ als eigener Block ODER aus GL-02 (Gate D-K3) | Doppelzählung vermeiden |
| Marge 15 % auf HK | ✅ **da** (heute `hk2_zuschlag_pct`, wird zur „Marge") | |
| Pax-Staffeln (Mengen-Degression WE) | ⏸ **später** (Gate D-K4) | v1 rechnet 1 Preis/Portion |
| Charge-Staffelpreise (5/20/50 kg, €/kg) | ⏸ **später / White-Label-Schicht** | B2B-Produktpreis, nicht Menü-HK |
| Event-Personal/Equipment je Event | ⏸ **Foodbook-/Angebots-Kalkulation** (eigene Phase) | |

**v1-Fokus:** HK **pro Portion (Rezept/Gericht) bzw. pro Person (Paket/Concept)** als
**strukturierte Zuschlagskalkulation mit benannten Blöcken** — sichtbar aufgeschlüsselt,
team-konfigurierbar. Mengen-/Event-/White-Label-Logik kommt obendrauf, wenn v1 steht.

---

## 3. Ziel-Kostenmodell im Modul

### 3.1 Das Kalkulations-Schema (team-konfigurierbar, wenige Blöcke)
Eine **geordnete, benannte Liste von Kostenblöcken** (Team-Setting, mit sinnvollen
Defaults — nicht frei erfindbar, sondern an/aus + Wert je Block):

| Block | Typ | Default | Quelle / Berechnung |
|---|---|---|---|
| **Wareneinsatz** | Basis | — | `ek_total_eur` / Portion (GL-02, verlustkorrigiert) |
| **Lohn / Produktion** | `arbeitszeit` | Stundensatz z. B. 35 €/h | `arbeitszeit_min/60 × Stundensatz` (Rollup Gericht→Paket→Concept aus M10R) |
| **Verpackung** | `eur_pro_portion` | 0,25 € | fix je Portion (optional) |
| **Schwund** | `pct_we` | 5 % | `% × WE` |
| **Lager** | `eur_pro_portion` / `pct_hk` | 0 | optional |
| **Gemeinkosten** | `pct_hk` | (heutiger `hk2_zuschlag_pct`) | Overhead-Zuschlag |
| **Marge** | `pct_hk` (Aufschlag) | 15 % | → VK / „EK an DOEC" |

```
HK1 (Wareneinsatz)               = ek_total / Portion
HK2 (Herstellkosten) = HK1 + Lohn + Verpackung + Schwund + Lager + Gemeinkosten
VK  ( = EK an DOEC ) = HK2 × (1 + Marge)
Deckungsbeitrag      = VK_gesetzt − HK2     (gegen den real gesetzten VK/Concept-Preis)
```

Jeder Block-Typ: `pct_we` (% auf Wareneinsatz) · `pct_hk` (% auf Zwischensumme) ·
`eur_pro_portion` (Fixbetrag) · `arbeitszeit` (Zeit×Satz). Das deckt die ganze
Excel-Logik ab — mit **6–7 Reglern** statt 37 Tabs.

### 3.2 Anbindung an die gegebene Umgebung (was schon da ist)
- **WE:** `FoodAlchemistRecipe::ek_total_eur` (GL-02), `Paket::ek_pro_person`,
  `ConcepterAggregateService::conceptAggregat()['ek_pro_person']`.
- **Arbeitszeit:** `recipes.arbeitszeit_min` + **Rollup** (M10R: `arbeitszeit_min` Gericht→
  Paket→Concept im Aggregat) → Lohn-Block ohne neue Datenerfassung.
- **Bestehender Service:** `KalkulationService` (M12) wird vom Ein-Regler auf das
  Block-Schema umgestellt; `recipeHk()`/`conceptHk()` liefern künftig die **Block-
  Aufschlüsselung** statt nur Summen.
- **Team-Settings:** `TeamSettingsService` (heute `hk2Zuschlag()`) wird Träger des
  Schemas + Stundensatz.

---

## 4. Datenmodell-Deltas (additiv, klein)

- `foodalchemist_team_settings`: + `kalkulation_schema_json` (die Block-Liste:
  `[{key,label,typ,wert,aktiv,sort}]`) + `stundensatz_eur` (Default-Lohnsatz).
  *(Der bestehende `hk2_zuschlag_pct` bleibt als Default-Wert des Gemeinkosten-Blocks —
  rückwärtskompatibel.)*
- `foodalchemist_recipes`: + `arbeitszeit_min` ist **vorhanden**; optional + `garungstyp`
  (Tag wie in der Excel — treibt später einen Lohn-/Energie-Faktor, Gate D-K7).
  `nebenkosten_eur` bleibt (wird zum Fallback/Override des Lohn-/Energie-Blocks).
- Optional `foodalchemist_stundensaetze` (mehrere Rollen-Sätze wie die Excel:
  Warenannahme/Produktion/Spüle …) — **erst wenn ein Pauschal-Stundensatz nicht reicht**
  (Gate D-K2). v1: ein Team-Stundensatz.

> Keine neue Tabelle zwingend nötig für v1 — das Schema ist JSON am Team-Setting, der
> Lohn kommt aus vorhandenem `arbeitszeit_min`. Maximal additiv, kein Umbau.

---

## 5. UI — Vorbild: die bestehenden Module

1. **Settings → „Kalkulation" (erweitern, wie die Vokabular-/Settings-Seiten):**
   Block-Schema pflegen (Block an/aus, Wert, Reihenfolge — wie Einheiten/Warengruppen-
   CRUD), Default-**Stundensatz**, Marge. Frosted Cards, `x-ui-*`, Jarvis-Dichte.
2. **Kalkulation-Browser (`/kalkulation`, 3-Panel im VK-Stil):** links Filter
   (Gerichte | Pakete | Concepts), Mitte dichte Tabelle (HK1 · HK2 · VK · DB € · DB %),
   rechts **Detail-Panel = Block-für-Block-Aufschlüsselung** (Wasserfall: WE → +Lohn →
   +Verpackung → +Schwund → +Gemeinkosten → ×Marge → VK). Das ersetzt die heutige
   flache M12-Tabelle.
3. **Concepter-Editor (M10R) — Tab „Kalkulation":** zeigt dieselbe Aufschlüsselung pro
   Person; bereits vorhandener Tab wird um die Block-Sicht ergänzt.
4. **Detail-Panels (Gericht/Paket/Concept):** HK2 + DB als KPI (heute teils schon da).

---

## 6. Phasen (M-K1 … M-K5)

> **Bau-Stand (2026-06-13): v1 GEBAUT (M-K1 + M-K2 + M-K3 ✓). Suite 480/480 grün, live.**
> M-K1/M-K2 (Commit 4cf695f): `team_settings.kalkulation_schema`/`stundensatz_eur`/
> `marge_pct` (Migration 000050) · `TeamSettingsService` (defaultSchema/kalkulationSchema/
> stundensatz/margePct) · `KalkulationService::berechne` (Block-Wasserfall) + recipeHk/
> conceptHk auf Schema umgestellt (M12-Werte erhalten) + `paketHk` · Aggregat-
> `arbeitszeit_min_pro_portion`. M-K3 (Commit b528609): Settings-Block-Schema-Editor +
> HK2-Wasserfall im Concepter-Editor-Tab.

| Phase | Inhalt | Status |
|---|---|---|
| **M-K1** | **Block-Schema + Service-Umbau:** `kalkulation_schema` + `stundensatz_eur` am Team-Setting; `KalkulationService` rechnet HK aus den Blöcken (pct_we/pct_hk/eur_pro_portion/arbeitszeit) und liefert die Aufschlüsselung. Rückwärtskompatibel (alter Zuschlag = Gemeinkosten-Block). | ✓ |
| **M-K2** | **Lohn-Block aus Arbeitszeit:** `arbeitszeit_min × Stundensatz` für Rezept; Rollup für Paket/Concept (`arbeitszeit_min_pro_portion`). | ✓ |
| **M-K3** | **UI:** Settings-Schema-CRUD + HK2-Wasserfall im Concepter-Editor-Tab. (Browser-3-Panel-Wasserfall = optionaler Schliff, recipeHk/conceptHk liefern die Blöcke schon.) | ✓ |
| **M-K4** | **Politur:** Wasserfall im `/kalkulation`-Browser-Detail ✓ · K-07 markStale-Wiring (Auto-Pakete bei Preis-Änderung) ✓ · Verpackung/Schwund/Lager als Blöcke im Schema vorhanden+pflegbar ✓. Offen: Garungstyp-Tag + Faktor (Gate D-K7, eher KI). | ◐ |
| **M-K5** *(später)* | **Mengen-/Event-Schicht:** Pax-Staffel-Degression (D-K4) und Event-/Angebots-Kalkulation (Personal/Logistik/Equipment) **am Foodbook** (D-K5) — die Excel-Ebene D. | offen |

> v1 = **M-K1 + M-K2 + M-K3 ✓**: strukturierte, sichtbare HK je Portion/Person, team-
> konfigurierbar, im Settings + Concepter-Editor aufgeschlüsselt. „Die Herstellkosten im
> Modul rechnen" — schlank, erledigt.

---

## 7. Entscheidungs-Gates (für Dominique)

| Gate | Frage | Empfehlung |
|---|---|---|
| **D-K1** | Kostenblöcke **feste benannte Liste** (an/aus + Wert) oder frei erfindbar? | **Feste Liste** (WE·Lohn·Verpackung·Schwund·Lager·Gemeinkosten·Marge) — einfacher, verständlicher |
| **D-K2** | Lohn: **ein** Team-Stundensatz oder mehrere Rollen-Sätze (wie Excel: Annahme/Produktion/Spüle)? | **Ein Satz** in v1; Rollen-Sätze später, falls nötig |
| **D-K2b** | Woher kommt die **Arbeitszeit** je Gericht (inkl. Basisrezepte, Batch-Größe)? | **KI-ENGINE (Dominique 2026-06-13):** Arbeitszeit ist **nicht-linear** skalierbar (5 kg → 100 kg ≠ ×20) und muss die Sub-Rezept-Zeiten realistisch zusammenfassen → das ist eine KI-Schätzung (LLM-Blocker), KEINE deterministische Σ. **v1 nutzt den manuell gepflegten `arbeitszeit_min`** je Rezept; die automatische Zeit-Schätzung/-Skalierung kommt mit dem KI-Batch. |
| **D-K3** | Schwund: steckt er schon in GL-02 (WE verlustkorrigiert) — zusätzlicher Block = Doppelzählung? | **Default-Block = 0 %** (an, aber 0), damit keine Doppelzählung; pflegbar wenn GL-02 den Schwund nicht abdeckt |
| **D-K4** | Pax-Staffel-Degression (günstigerer WE bei mehr Menge) jetzt oder später? | **Später** (M-K5) — v1 ohne Mengenstaffel |
| **D-K5** | Event-Kosten (Personal/Logistik/Equipment je Event) — gehören sie an die **Produkt-HK** oder ans **Foodbook/Angebot**? | **Ans Foodbook** (Angebotsebene, Pax bindet dort) — HK bleibt produktbezogen |
| **D-K6** | White-Label „EK an DOEC" als €/kg-Charge-Staffel — brauchen wir das im Modul? | **Nein in v1** — VK/Person reicht; Charge-Staffel ist eine B2B-Sonderschicht |
| **D-K7** | **Garungstyp** (Kalt/Mixen/Backen …) als Tag am Rezept — soll er einen Lohn-/Energie-Faktor treiben? | **Optional M-K4** — erst als reines Tag, Faktor später |

---

## 8. Governance

- **Alles im Modul** (`platforms-foodalchemist`), Domäne „Kalkulation" baut auf M12 auf —
  kein eigenes Composer-Paket. **Core/UI tabu** (`x-ui-*` nutzen).
- **Dev-Modul:** dieser Plan als **Doc-Seite**; Gates D-K1…D-K7 als **Discussions**;
  M-K1…M-K5 als **Issues** aufs Features-Board (Package `platforms-food-alchemisten`).
- **Bezug:** ersetzt/präzisiert den groben M12-Ausblick „später differenziert (M15)" —
  **M15 (Energie je Garmethode) entfällt** zugunsten des Lohn-Blocks (Arbeitszeit×Satz).
- **Reihenfolge:** erst Schema+Service (M-K1/2) headless testbar, dann UI (M-K3).

---

## 9. Was schon steht (Bezug zum Bestand)

- **M12 (gebaut):** `KalkulationService` (HK1→HK2 Zuschlag, `recipeHk`/`conceptHk`),
  `team_settings.hk2_zuschlag_pct`, `recipes.nebenkosten_eur`, Browser `/kalkulation`,
  Settings-Sektion „Kalkulation". → wird in M-K1 vom Ein-Regler aufs Block-Schema gehoben.
- **M10R (gebaut):** Concepter-Aggregat rollt **EK + Arbeitszeit** Gericht→Paket→Concept
  hoch → Lohn-Block bekommt seine Datenbasis geschenkt. Concepter-Editor hat bereits
  einen **Kalkulation-Tab** (wird um die Block-Aufschlüsselung erweitert).
- **GL-02:** WE ist verlustkorrigiert (Garverlust/Putzverlust je Zutat) — die HK1-Basis
  ist also schon „ehrlich".

---

## 10. Ausbau v2 — Einzelkosten vs. Gemeinkosten + Fixkosten-Ableitung (Dominique 2026-06-13)

> **Strategischer Zweck (Dominique):** BHG/Broich kalkuliert echte **White-Label-
> Produktionen** — dafür braucht es einen **System-Kostenrechner**, der die Kosten
> erfasst UND rechnet (das Excel-pro-Auftrag ohne System fällt weg). Weil das Modell
> **team-scoped & generisch** ist (keine kundenspezifischen Hardcodings — alles über
> Team-Settings/Fixkosten), ist es **auch für andere Kunden/Teams anbietbar**; die
> mehrstufige Zuschlagskalkulation macht die Kalkulation **genauer** als ein
> Pauschalsatz. → Daten erfassen (Fixkosten/Sätze in Settings, Einzelkosten am Produkt)
> + reproduzierbar rechnen, statt Excel.

> **Leitbild: produzierendes Gewerbe / klassische Zuschlagskalkulation.** Dominique:
> „Einen Editor, in den alle Infos reingehen und man Sachen einträgt; die Daten müssen
> in den Einstellungen gepflegt sein — eigene, viel detailliertere Settings-Seite; und
> **Dinge, die NICHT direkt mit dem Produkt zu tun haben (Logistik, Spüle …), gehören in
> die Einstellungen**; zu den **Fixkosten brauchen wir Daten, um Prozente abzuleiten**."

### 10.1 Die Trennung (verbindlich)
| Ebene | Was | Wo gepflegt |
|---|---|---|
| **Einzelkosten** (direkt am Produkt) | Materialeinzelkosten = **Wareneinsatz** (aus Zutaten, GL-02) · Fertigungseinzelkosten = **direkte Produktionszeit × Stundensatz** (Arbeitszeit; Schätzung später KI) · ggf. produktdirekte Verpackung | **Per-Item-Kalkulations-Editor** (am Gericht) |
| **Gemeinkosten** (indirekt, NICHT produktbezogen) | **Logistik · Spüle · Lager · Warenannahme/Einkauf · Energie-Grundlast · Verwaltung/Vertrieb · Schwund** | **Einstellungen** (als Zuschlagssätze) |
| **Marge/Gewinn** | Aufschlag auf die Selbstkosten | Einstellungen |

```
Materialeinzelkosten (WE)
+ Fertigungseinzelkosten (direkte Zeit × Satz)        ← Produkt (Editor)
+ Σ Gemeinkosten-Zuschläge (Logistik/Spüle/Lager/…)   ← Einstellungen (abgeleitet o. manuell)
= Selbstkosten (HK2)
+ Marge
= VK / EK an DOEC
```

### 10.2 Fixkosten → Zuschlag-% ableiten (das Kern-Neue)
Gemeinkosten-Blöcke sollen nicht geraten, sondern aus **echten Fixkosten** abgeleitet werden:

- **Fixkosten-Erfassung** (Settings, neue Tabelle `foodalchemist_fixkosten`): Zeilen mit
  `bezeichnung · betrag · periode (monatlich/jährlich) · gemeinkosten_block` (Zuordnung
  zu Logistik/Spüle/Lager/Verwaltung/…). Beispiele: Miete 3.000/Mt, Spülpersonal 2.000/Mt,
  LKW/Logistik 1.500/Mt, Verwaltung 2.500/Mt.
- **Bezugsbasis/Periode** (Team-Setting): worüber die Fixkosten verteilt werden →
  **abgeleiteter Zuschlag-% je Block = Σ Fixkosten(Block) ÷ Bezugsbasis × 100**
  (klassischer Gemeinkostenzuschlagssatz).
- Jeder Gemeinkosten-Block im Schema: **`abgeleitet`** (aus Fixkosten) **ODER** `manuell %`
  (Override). Die Settings-Seite zeigt die abgeleiteten Sätze live.

> **Bezugsbasis (D-K8) — ENTSCHIEDEN (Dominique): mehrere Basen je Block** (mehrstufige
> Zuschlagskalkulation wie in der Industrie): **Material-GK** (Einkauf/Lager/Warenannahme/
> Schwund) auf **Wareneinsatz (MEK)** · **Fertigungs-GK** (Spüle/Energie/Maschinen) auf
> **Fertigungslohn (FEK)** · **Verwaltung/Vertrieb + Logistik** auf **Herstellkosten (HK)**.
> Stufen: `MEK + MGK(%·MEK) + FEK + FGK(%·FEK) = HK → +VwGK/VtGK/Logistik(%·HK) =
> Selbstkosten(HK2) → ×Marge = VK`. Jeder GK-Block: abgeleitet (Σ Fixkosten ÷ Basis-
> Periodenwert) oder manuell. Bezugsbasen (erwarteter WE/FEK/HK je Periode) = Team-Settings.

### 10.3 Detaillierte Settings-Seite (Kalkulation)
1. **Einzelkosten-Parameter:** Stundensatz(e) Fertigung.
2. **Fixkosten-Liste** (CRUD) + Bezugsbasis → **abgeleitete Gemeinkosten-Sätze** (live).
3. **Gemeinkosten-Block-Schema:** je Block abgeleitet/manuell, an/aus, Wert.
4. **Marge.**
(UI wie die Vokabular-/Settings-Seiten: Karten, dichte Tabellen, x-ui.)

### 10.4 Per-Item-Kalkulations-Editor (am Gericht, in `/kalkulation`)
Zeile anklicken → Editor (Modal/Inline) mit: **HK2-Wasserfall live** + editierbaren
**Einzelkosten-Feldern** (direkte Produktionszeit, produktdirekte Verpackung/Nebenkosten);
die Gemeinkosten-Zuschläge kommen read-only aus den Settings (mit Hinweis „in
Einstellungen pflegen"). Concept/Paket: aggregierte Sicht; editierbar auf Gericht-Ebene.

### 10.5 Phasen
> **Bau-Stand (2026-06-13): Ausbau v2 GEBAUT (M-K6…M-K9 ✓). Suite 491/491, live.**
> P1 (c318bb5) mehrstufiges Rechenmodell · P2 (08c6bc0) Fixkosten+Ableitung ·
> P3 (c25bd71) detaillierte Settings · P4 Per-Item-Editor.

| Phase | Inhalt | Status |
|---|---|---|
| **M-K6** | `foodalchemist_fixkosten` + FixkostenService (Σ je Block, Zuschlag-% = Σ/Basis je MEK/FEK/HK) + Bezugsbasen-Setting; Blöcke `abgeleitet`/`manuell`; mehrstufige `berechne`. | ✓ |
| **M-K7** | Detaillierte Settings-Seite: Fixkosten-CRUD + Bezugsbasen + abgeleitete Sätze live + Block-Schema (Basis/Modus) + Marge. | ✓ |
| **M-K8** | Per-Item-Editor in `/kalkulation`: direkte Einzelkosten (Fertigungszeit, Nebenkosten) am Gericht eintragbar, Wasserfall live; Gemeinkosten read-only aus Settings. | ✓ |
| **M-K9** | Gemeinkosten-Blöcke Material-GK · Fertigungs-GK · Verwaltung/Vertrieb · Logistik (aus Fixkosten gespeist) — im Default-Schema. | ✓ |

> Arbeitszeit-Schätzung (nicht-linear) bleibt KI (D-K2b). Foodbook-Anbindung separat.
