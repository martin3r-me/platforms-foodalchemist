# 50 · Gericht: Anleitungs-Ebenen, KI-Briefing und Deklaration auf Angebotsebene

**Stand:** 2026-09-04 · umgesetzt und getestet · Regelwerke: `Regelwerk_Verkaufsgerichte` v1.6 (§3, §4), `Regelwerk_Concept` v1.1 (§7)

Zwei Runden mit derselben Wurzel: Felder lagen ohne Kontrakt nebeneinander, deshalb konnte
weder der Druck filtern noch die KI trennen noch die Oberfläche eine brauchbare Aussage
machen.

---

## Teil A — Drei Anleitungs-Ebenen am Gericht

| Ebene | Frage | Feld | Adressat |
|---|---|---|---|
| Herstellen (Basisrezept) / **Fertigstellen** (Gericht) | Wie entsteht es? | `recipe_steps` (`ebene='produktion'`), Spiegel `preparation` | Küche |
| **Regeneration** | Wie wird es auf Temperatur gebracht? | `recipe_regenerations` je Komponente | Küche vor Ort |
| **Anrichten** | Wie kommt es auf den Teller? | `recipe_steps` (`ebene='anrichten'`), Spiegel `plating_text` | Pass |

**Was das gelöst hat**

- Die gepflegte Regenerations-Liste erschien in **keinem** Druckstück: der Druck las die
  Darreichungs-Skalare, die kein Schreibpfad füllt. Jetzt zwei zuschaltbare Report-Flags
  (`regeneration`, `anrichten`) plus `regen_snapshot` / `plating_snapshot` an der
  Auftragszeile, damit ein Nachdruck nicht driftet.
- `recipe.steps` forderte selbst einen „Service-, Regenerations- und Anrichteablauf" —
  dieselbe Angabe stand damit zweimal im System (Prosa + Datensatz). Der Prompt ist auf
  FERTIGSTELLEN geschnitten, die Abgrenzung ist im Prompt verbindlich formuliert, und der
  Kontext-Satz hat mit `config('foodalchemist.step_kontext')` **eine** Quelle für Editor und
  One-Shot.
- Anrichten war die einzige Ebene ohne Bilder, obwohl sie der visuellste Arbeitsgang ist.
  Sie läuft jetzt über dieselbe Schritt-Mechanik (Foto je Schritt, Postenzettel, Reorder).

**Feld-Bereinigung am Gericht** (§3.4a–d): Rüstzeit und Vorproduzierbarkeit raus
(Herstellungs-Eigenschaften, die Komponenten tragen sie) · Arbeitszeit = Fertigstellungszeit ·
Posten = **optionaler** Finalisierungs-Posten, leer ist der Normalfall (am Pass arbeitet das
Team, `StationLaborRateService` rechnet dann mit dem Team-Satz; beteiligte Posten werden aus
den Komponenten abgeleitet und nur angezeigt) · `additional_costs_eur` ist ein **Ansatz**-Betrag
(das Label „€/Portion" war um den Faktor der Portionen je Ansatz falsch) · Temperatur und
Funktion raus.

**Verkaufseinheit** auf Portion/Stück/kg/l begrenzt (Whitelist `config('foodalchemist.sales_units')`,
mit Bestandsschutz für abweichend zugewiesene Alt-Einheiten). Vorher hing dort das komplette
Zutaten-Vokabular inklusive Prise und Messerspitze.

**Review-Ausgang** (§4): die Servierform einer Darreichungs-Zeile ist umstellbar
(`VkModal::darreichungForm`), das Vokabular-Label wird nie umbenannt.

---

## Teil B — KI-Briefing je Schrittfolge (§3.7)

Jede der drei Schrittfolgen hat am KI-Knopf ein **Briefing-Feld** — Tippen oder Diktat
(`SttServiceContract`, Baustein `recipes/partials/diktat-knopf.blade.php`).

- gefüllt = Vorgabe des Kochs für **dieses** Rezept; leer = die KI entscheidet fachlich frei
- der übrige Kontext bleibt in beiden Fällen vollständig; bei Widerspruch gewinnt die
  Ebenen-Abgrenzung
- **transient**: nicht am Rezept gespeichert, nach der Übernahme geleert. Es beschreibt einen
  Auftrag, kein Rezeptmerkmal — ein viertes, ungepflegtes Wissensfeld entsteht nicht

Zugleich fragt der Knopf jetzt den Prompt **seiner** Ebene (`recipe.steps` bzw. `vk.plating`).
Vorher lief er auf beiden Ebenen gegen `recipe.steps`, weshalb der Anrichte-Knopf abgeschaltet
war und das Plating nur über den Panel-Knopf lief. Die Herkunftsfelder sind ebenengetrennt
(`preparation_source` vs. `plating_source`), damit ein Plating-Klick die Zubereitung nicht als
KI-erzeugt markiert.

---

## Teil C — Deklaration auf Angebotsebene (Regelwerk Concept §7)

Der Deklarations-Tab im Concepter zeigte einen reinen ALL-MAXIMAL-Rollup: zwei triviale
„enthält"-Pills und `Konf. low`, dazu eine Nährwert-Summe über neun à-la-carte-Gerichte, von
denen eines Werte hatte.

**Drei Zonen** (`ConcepterAggregateService::deklarationsblatt`):

1. **Tags/Quoten** übers Angebot — `3/9 vegetarisch` statt Alles-oder-nichts, Schwein/Rind mit
   Nennung der Gerichte, Konfidenz mit schwächstem Glied im Klartext
2. **Deklaration je Gericht** — Ziffern-Kennzeichnung (Allergen-Buchstaben + Zusatzstoff-Nummern,
   `*` = Spuren) plus Legende, nur real Vorkommendes. Dieselbe Code-Quelle wie Foodbook,
   Speisekarte und Speiseplan
3. **Nährwerte nur, wo sie stimmen** — Summe/Person bei Gesamtpreis und Paket, Spanne je Portion
   bei Einzelpreisen (`price_display='einzel'`: niemand isst alle Positionen, „Untergrenze" wäre
   dort sachlich falsch), und bei Lücken **keine Zahl**, sondern eine namentliche Aufgabenliste

**Nachzug:** die Concept-**Karte** (Kunden-Ausgabe) trug keine Deklaration. Sie bekommt Codes je
Zeile und die Legende in Foodbook-Optik. Der technische Report hatte sie schon (ausgeschrieben
über `report-declaration`).

**Fallstrick, dokumentiert:** `ConcepterAggregateService::recipeCols()` führt die 14 `allergen_*`
und 18 `additive_*` Spalten nicht. Ein nicht geladenes Eloquent-Attribut liefert still `null` —
`gerichtCodes()` hätte für jedes Gericht **leere** Codes gemeldet, ohne Fehler. Das Blatt lädt
die Spalten über `mitDeklarationsSpalten()` selbst nach; ein Test nagelt es fest.

---

## Tests

| Datei | Deckt ab |
|---|---|
| `AnleitungsEbenenTest` | Prompt-Trennung, Ebenen-Kontrakt, Feld-Bereinigung, Team-am-Pass |
| `StepBriefingTest` | Briefing im Kontext + Gesamtblick, Prompt je Ebene, Herkunftsfelder, Diktat, Transienz |
| `ConcepterDeklarationTest` | Zeilen je Gericht, Codes/Legende, Quoten, Modus, Lücken, Karte |
| `VkEditorVollausbauTest`, `RecipeStepEditorTest`, `VkBulkEnrichTest` | angrenzende Verträge |

Ein Fake-Matcher in `VkBulkEnrichTest` hing am Prompt-**Wortlaut** („Plating-Anweisung"); der
Prompt-Umbau ließ ihn still in den default-Zweig rutschen, wodurch der Plating-Vorschlag gar
nicht mehr entstand. Der Marker ist jetzt strukturell (`portion_g` im Kontext) — Prompt-Texte
sind Formulierung, keine Schnittstelle.

---

## Offen

- **Behälter-Logik** — eigener Plan, macht Dominique separat
- **Anreicherung** (Paket E des Ursprungsplans): Vollständigkeit gegen den Kontrakt, inklusive
  der Frage, ob `voll_anreichern` für Gerichte Default an wird
- Massen-`recompute --all` bewusst nicht gefahren (Bestand wandert Rezept für Rezept)
