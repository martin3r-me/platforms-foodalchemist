# Spec 81 · Regeln als Daten

Stand 2026-10-09 · Branch `docs/spec81-regeln-als-daten` · Entscheid Dominique 09.10.: **Variante B**; Teil A an Claude delegiert; Dossiers bereinigen statt spiegeln; Umlaute
Vorläufer: Spec 80 Teil G (Richtig anlegen statt nachprüfen), Spec 41 A3 (Regelwerke im Wissensmodul), Spec 52 F4

## Warum

Eine Konformitätsprüfung kostet rund 80.000 Tokens. Der größte Teil davon prüft Dinge, die der Code
sicherer und kostenlos prüfen könnte: ob ein Präfix im Typ-Vokabular steht, ob eine Schnittform in der
Rezeptzeile steht, ob ein Bio-Grundprodukt ohne Bio-Wunsch gewählt wurde. Diese Tokens sollen in **Wissen**
fließen, also in Geschmack, Technik und Begründung, wo die KI ihren Wert hat.

Ist-Stand (Code-Recherche 09.10., Branch `feat/spec80-h-struktur`):

1. **Regeln liegen verstreut** in mindestens sechs Klassen: `GpNamingService` (§9-Zustände, §7.1-Verpackungswörter,
   §10-Generik, §6-Schema), `MatchHeuristics` (§5-Default-GPs als if-Kette, Schwellen), `TokenEngine`
   (`PROCESSED_MARKERS`, `CUT_FORM_MARKERS`), `RecipeConformanceAdapter` (§1.2, §2, §10, §8.3, §6-Konzentrat),
   `DataQualityService` (VK §1.2), `RecipeGeneratorService` (`ROLLEN_RANG` §12.2).
2. **Doppelte Wahrheiten:** die §2-Suffixe stehen im Dossier (gelesen über `RegelwerkLeser`) **und** in
   `TokenEngine::CUT_FORM_MARKERS`; die §5-Default-GPs stehen im Dossier **und** als Namenskette in
   `MatchHeuristics::defaultGpAlias()`. Ändert man eine Stelle, laufen beide still auseinander.
3. **Der einzige „Regel-Motor" ist ein Markdown-Parser** (`RegelwerkLeser::backtickListe`, Tabellen-Parse in
   `RezeptTypVokabular`). Ein falsch gesetzter Strich, und eine Regel fällt still weg (fail-open).
4. **Der KI-Prompt bekommt alles:** Basisrezept/VK über den Kanon die ganzen Pflicht-Dossiers; GP/LA über
   `ConformanceService::ladeRegelwerke()` **alle** Regelwerk-Dossiers ungekappt. Ein Paragraf, den der Code schon
   prüft, geht trotzdem vollständig an die KI. Der Hinweis `vom_code_geprueft` ist ein hart codierter Satz.
5. **GP und LA haben gar keine Code-Prüfung** (`deterministischeBefunde` = `[]`).
6. **Befunde wissen nicht, woher sie kommen:** `conformance_findings` hat keine Spalte für Quelle oder Regel.

## Entscheid: Variante B

Mechanische Regeln liegen in einer **eigenen Regel-Tabelle** mit **Pflegeseite in den Einstellungen**. Ein
**Regel-Motor** führt sie aus: beim Anlegen (korrigieren), beim Prüfen (Befund) und im Matching.

**SSOT-Folge (ändert Spec 41 A3 für den mechanischen Teil):**

| Inhalt | Wahrheit | Darstellung |
|---|---|---|
| Mechanische Regel (Liste, Muster, Schwelle, Zuordnung) | **Regel-Tabelle** | Pflegeseite „Regeln“; im Dossier **nur ein Verweis** (Entscheid 09.10.: nicht doppelt) |
| Begründung, Urteil, Technik, Beispiele-Prosa | **Wissensmodul** (unverändert) | Dossier |

Schreiben dürfen nur FA-Admins, globale Regeln nur das Master-Team (Zielmodell „global + Admin").

Verworfen, **Variante A** (Regeln als Tabellen im Dossier): kein Probelauf, keine Prüfung beim Speichern,
Parser-Bruch bleibt still.

---

## Teil 0 · Messen vor dem Bauen

Bevor irgendetwas umgebaut wird, zerlegen wir auf demo je **einen** echten Aufruf von `conformance.check`
(Basisrezept, Gericht, GP) und `recipe.generator` in seine Teile, jeweils mit Zeichen und Tokens:

| Teil | Quelle |
|---|---|
| Core-Kopf (Zeit, Persona, Tools) | Core-Contract |
| Regelwerk-Dossiers (Kanon bzw. `ladeRegelwerke`) | Wissensmodul |
| Geroutetes Wissen (Qdrant) | `KnowledgeContextService` |
| Artefakt (Rezept, Zeilen, GP) | Adapter |
| Antwort | Provider |

Ergebnis: eine Tabelle im PR. Sie ist die **Ausgangszahl**, gegen die Teil F gemessen wird. Ohne sie gibt es
keine Aussage „X Tokens gespart". Als Nebenprodukt fällt die Antwort für das Gespräch mit Martin zum
Prompt-Caching ab: Wie groß ist der Teil, der bei jedem Aufruf gleich ist?

---

## Teil A · Entschieden, bevor migriert wird

Die Bestandsaufnahme (09.10., alle drei Regelwerke, Vault-Stand gegen Export 05.10.) hat Widersprüche
gefunden. Ein Regel-Motor setzt jede Liste **streng** durch, darum muss vorher feststehen, welche gilt.
**Dominique hat die Entscheidung am 09.10. delegiert („nach Logik").** Die Leitlinien dafür:

- **Spätere bewusste Entscheidung schlägt älteren Stand**, egal ob Vault oder Export.
- **Ein Wort, ein Begriff:** Bedeutungsgleiches wird Alias, nicht zweiter Wert.
- **Ins Vokabular kommt, was ein kaufbares, anderes Produkt bezeichnet** (anderer Preis, anderer Einsatz),
  nicht jede Beschreibung.
- **Feldnamen folgen der Datenbank** (`zustand`, `processing`, `form`), damit Regel, Dossier und Code
  dieselbe Sprache sprechen.

| # | Widerspruch | Entscheidung | Begründung |
|---|---|---|---|
| A1 | GP §4 nennt 8 Form-Werte, §9 nur 6 | **§9 gilt.** §4 verliert die Werte-Tabelle und verweist auf §9 | „Halbiert", „Geviertelt" stehen in §9 bereits unter *Verarbeitung*, sie sind ein Schnitt, keine Geometrie. Damit gibt es eine Liste statt zwei |
| A2 | Beispiele (§19, §6.2, §14) nutzen Tokens außerhalb §9 und Pluralformen | **Token-weise, nach Bestand** (Zählung siehe unten): aufnehmen `gemahlen`, `vorgegart`, `geröstet`, `getrocknet`, `gesalzen`, `geputzt`, `gefriergetrocknet`; Alias `gekocht`→`gegart`, `entsteint`→`entkernt`, `tiefgekühlt`→`TK`; **nicht** aufnehmen `fein` (nur als Körnung bei Salz/Zucker/Mehl, §8) und `kg` (Gebinde, bleibt §7.1-Verbot). Plural in Beispielen wird auf Singular korrigiert | Die aufgenommenen Wörter trennen echte Produkte (Kreuzkümmel ganz ≠ gemahlen, TK-Rigatoni vorgegart ≠ roh). `fein` ist mehrdeutig („Champignons fein", „Geflügelsalat fein"). `kg` steht fast nur als Gebindegröße („Boiron 1 kg") |
| A3 | `derivat_typ` dreimal (GP §11.2, GP §17, BR §11), Allergen-Vererbung dreimal (GP §16, LA §10, BR §7) | **Quelle GP §11.2** für `derivat_typ` (eine Vokabular-Regel), **Quelle GP §16** für Allergen-Vererbung; die anderen Stellen verweisen nur | Das GP-Regelwerk definiert das Objekt, die anderen nutzen es. Allergen-Vererbung ist `prozess` (Berechnung im Code), keine Regel-Zeile |
| A4 | BR §5 Default-GPs: Export (05.10.) neuer als Vault | **Export gilt** (Olivenöl nativ extra kalt / raffiniert heiß, Sojasauce glutenfrei, Gelatine nach Fertigungstiefe, Zucker Raffinade weiß). Wird die erste `zuordnung`-Regel und ersetzt `MatchHeuristics::defaultGpAlias()` | Der Export räumt den Widerspruch zwischen Vault-Tabelle und Matcher-Notiz auf. Die Kontextfälle (kalt/heiß, Fertigungstiefe) trägt das Feld `kontext` der Zuordnung |
| A5 | BR §4 F4.3: Export noch „max. 3 Ebenen", Vault seit 03.08. nur Selbstreferenz/Zyklus hart | **Vault gilt**; Dossier auf demo per `knowledge-import` nachziehen, dazu F4.4 `BUTTERZUBEREITUNG` | Spätere bewusste Entscheidung; der Code (`pruefeVerknuepfung`) prüft heute schon nur Zyklus und Selbstreferenz |
| A6 | BR §8.1/§8.2 (Pairing-Anker 3–7, Chemie-Komponenten) | **Neu fassen, nicht als Regel:** Pairing kommt aus Foodpairing Inspire, nur 3 Sterne zählen (Spec 60). Die Zahl 3–7 entfällt | Das alte Anker-System ist seit 06.10. archiviert; eine Zählregel darauf wäre eine Regel ohne Gegenstand |
| A7 | LA-Regelwerk beschreibt `wawi_gp_la`, Legacy-Präfixe (`OBST_`, `GEM_` …), §8 Lead-LA veraltet | **In Spec 81 nur:** `match_method`-Vokabular (§12), Auto-Match-Schwellen (§5), Convenience-Marker (§13). Die Neufassung des LA-Regelwerks ist eine eigene Aufgabe | Was ein gedropptes Schema beschreibt, darf kein Motor durchsetzen |
| A8 | GP §6: „trennt Name Untergruppe, Produktname …" widerspricht dem Präfix-Verbot; Slots „Eigenschaft" / „Zustand/Zuschnitt" vertauscht benannt | Satz wird „Doppelpunkt trennt Produktname und Angaben". **Slots heißen wie die Felder:** `Zustand` (frisch/TK/trocken/konserviert), `Verarbeitung`, `Form` | Das Präfix-Verbot ist die neuere Regel; Slot = Feld macht `felderAusName`/`renderGpName` (Spec 80 K) eindeutig |
| A9 | GP §14 Synonym-Tabelle und §6.2-Beispiele im Plural | **Singular:** Cherrytomate, Möhre, Aubergine, Garnele / Shrimp, Grüne Bohne … | §6.1 Singular ist User-Entscheidung 29.05.; Beispiele müssen die Regel erfüllen, sonst sind sie als Testsatz wertlos |

**Zählung zu A2** (lokale Plattform-Datenbank, 7.948 Grundprodukte, 09.10.; demo kann abweichen, der Probelauf in
Paket 3 zählt dort nach). Treffer als ganzes Wort im GP-Namen:

| Token | Treffer | Entscheidung | | Token | Treffer | Entscheidung |
|---|---|---|---|---|---|---|
| gemahlen | 75 | aufnehmen | | gekocht | 13 | Alias → gegart |
| fein | 32 | nein (§8-Körnung) | | getrocknet | 11 | aufnehmen |
| vorgegart | 28 | aufnehmen | | entsteint | 11 | Alias → entkernt |
| kg | 21 | nein (§7.1-Verbot) | | gesalzen | 10 | aufnehmen |
| geröstet | 20 | aufnehmen | | geputzt | 8 | aufnehmen |
| | | | | gefriergetrocknet | 5 | aufnehmen |

**Vergleich ohne Umlaut- und Großschreibungs-Falle:** Der Bestand schreibt überwiegend umschrieben
(`geschaelt` 82 ×, `geschält` 0 ×). Jede Vokabular- und Verbotsprüfung vergleicht darum normalisiert
(Kleinschreibung, ä/ae, ö/oe, ü/ue, ß/ss). Die Regel speichert die Schreibweise mit Umlaut, der Vergleich
ist tolerant.

Die Entscheidungen werden in Paket 1 in die Dossiers auf demo übernommen (Versionssprung GP v3.5, BR v1.11,
Changelog „Spec 81 A1–A9") und bilden die Seeds in Paket 3.

---

## Teil B · Regel-Arten

Der Code kennt eine **feste, kleine Zahl** an Regel-Arten. Jede Art ist eine Klasse mit klarer Eingabe und
Ausgabe. Neue Regeln sind Zeilen, neue Arten sind Code (bewusst selten).

| Art | Was der Motor tut | Parameter (`params` JSON) | Beispiele |
|---|---|---|---|
| `vokabular` | prüft, dass ein Feld/Slot nur erlaubte Werte trägt; Aliase → kanonisch | `werte: [{wert, aliase[], gruppe?}]` | §1.2 Typ-Präfixe je Hauptgruppe (~130), §9 Zustand (4 + Alias TK), §9 Verarbeitung (29, `<mm>`-Muster), §9 Form, §18 Status, LA §12 `match_method` |
| `ersetzung` | normalisiert eine Schreibweise; beim Anlegen **korrigierbar** | `paare: [{von, nach}]` oder `suffixe: []` + `ziel` (Rohform), `modus: token\|wort` | §2 Schnittform → Rohform (12 Suffixe), §6.1 Singular (+ Ausnahmelisten), §6.3 Fremdwörter, §10 Bio-Tausch, `gewürfelt` → `Würfel` |
| `pflichtangabe` | prüft, dass bei Bedingung X ein Token/Feld da ist; **nur flaggen** (Wert wird nie erfunden) | `bedingung: {warengruppe?, sub_kategorie?, form?, typ?}`, `muster` | GP §7 Grammatur bei Portion, §8.x (Kartoffel-Kochtyp, Käse-Fett, Reis-Sorte, Mehl-Type, Wein …), BR §1.5a FF/HF-Marker, §14.1 Regenerationszeile |
| `verbot` | lehnt ein Muster ab | `muster` (Token-Liste oder geprüftes Regex), `ausnahmen[]` | GP §7.1 Gebinde im Namen, §6.2 > 2 Doppelnamen, §12 Anti-Patterns (regex-fähige Hälfte), BR §1.6 Herkunft im Namen, §5 kein Bio als Default |
| `zuordnung` | Begriff → festes Ziel | `eintraege: [{begriff, aliase[], ziel_typ: gp\|rezept, ziel_id?, ziel_name, kontext?}]` | BR §5 Default-GPs, §3 Frucht-Pürees → TK, §4 Default-Sub-Rezepte, §6.3 Stückgewicht, §12.2 Rolle → Rang, GP §14 Synonyme |
| `schwelle` | Zahl, die eine Entscheidung trägt | `wert`, `einheit`, `vergleich` | LA §5 Auto-Match (Score ≥ 95, Abstand ≥ 15), GP §7.2 Stückgewicht 10 %, BR §6 Konzentrat-Anteil 20 %, §8.3 3–5 Sätze |

**Nicht** als Regel: `text` (Urteil, Geschmack, Begründung) und `prozess` (Sync-Richtung, Migrationen,
Allergen-Berechnung). Die bleiben im Dossier; `prozess` steckt ohnehin im Code der Berechnung.

**Umfang laut Bestandsaufnahme:** mechanisierbar sind etwa 54 % (GP), 60 % (BR) und 37 % (LA) des
Regelwerk-Fließtexts. Davon sicher „beim Anlegen korrigierbar" etwa 1.800 / 4.400 / 1.700 Tokens. Das ist
der Teil, der **nicht mehr an die KI gehen muss**.

---

## Teil C · Datenmodell

### C1 `foodalchemist_rules`

| Spalte | Typ | Zweck |
|---|---|---|
| `id`, `uuid` | | |
| `team_id` | null = global | v1 nur global (Master-Team schreibt) |
| `regelwerk` | `gp \| basisrezept \| la \| vk` | |
| `paragraph` | string, z. B. `§2`, `§8.12` | Herkunft, Filter, Befund-Zuordnung |
| `dossier_slug` | string, nullable | Dossier, aus dem die Regel stammt; steuert das Bereinigen (Teil E) |
| `titel` | string | Anzeige |
| `art` | enum aus Teil B | wählt die Motor-Klasse |
| `ziel` | string, z. B. `gp.name`, `gp.zustand`, `rezept.name`, `rezeptzeile`, `la.match` | wo die Regel greift |
| `wirkung` | `korrigieren \| warnen \| blockieren` | korrigieren nur bei `ersetzung`/`zuordnung` erlaubt |
| `params` | JSON | Schema je Art (Teil B), beim Speichern validiert |
| `beispiele` | JSON `{richtig: [], falsch: []}` | Testsatz, läuft bei jedem Speichern |
| `aktiv` | bool | **Neue Regeln starten inaktiv**; Aktivieren ist Kuration (Dominique) |
| `version` | int | +1 je Speichern |
| `notiz` | text | Begründung, sichtbar im Dossier |
| `created_via` | `seed \| einstellungen \| mcp` | |
| timestamps, softDeletes | | |

### C2 `foodalchemist_rule_versions`

Je Speichern eine Kopie (`rule_id`, `version`, `params`, `wirkung`, `aktiv`, `user_id`, `created_at`).
Zurückrollen = alte Version wieder speichern. (Das Wissensmodul hat keine Versionstabelle; hier brauchen wir
sie, weil eine Regel sofort auf jede Anlage wirkt.)

### C3 Paragraf-Zuordnung

Jede Regel trägt `regelwerk` + `paragraph` + `dossier_slug` (das Dossier, aus dem sie stammt). Daraus ergibt
sich ohne eigene Tabelle:
- welche Paragrafen der Code durchsetzt (für den Satz `vom_code_geprueft` und die Statistik),
- welches Dossier nach der Aktivierung bereinigt werden muss (Teil E).

### C4 Befunde mit Herkunft

`foodalchemist_conformance_findings` bekommt `quelle` (`code \| ki`) und `rule_id` (nullable). Damit:
Befund verlinkt auf die Regel; „Regel nachprüfen" kann Code-Befunde, die nicht mehr zutreffen, auf
„erledigt" setzen (Spec 80 G6); Statistik „wie viel prüft der Code, wie viel die KI".

---

## Teil D · Regel-Motor

`Services/Regeln/RegelMotor` mit je einer Klasse pro Art (`Vokabular`, `Ersetzung`, `Pflichtangabe`, `Verbot`,
`Zuordnung`, `Schwelle`), gemeinsame Schnittstelle:

```php
pruefe(Regel $r, Kontext $k): list<Befund>          // alle Arten
korrigiere(Regel $r, Kontext $k): ?Korrektur        // nur ersetzung/zuordnung mit wirkung=korrigieren
```

- **Laden:** aktive Regeln je `ziel`, Container-Memo mit Ablauf wie `RegelwerkLeser` (Queue-Worker leben
  lange), beim Speichern einer Regel sofort verworfen.
- **Fail-closed statt fail-open:** Die Seeds (Paket 3) liegen in einer Migration. Eine leere Regel-Tabelle ist
  damit ein Fehler, der laut gemeldet wird, kein stiller Normalbetrieb.
- **Regex-Sicherheit:** Muster werden beim Speichern kompiliert und gegen die Beispiele geprüft; Laufzeit
  begrenzt (`pcre.backtrack_limit`), ein kaputtes Muster deaktiviert nur seine Regel und meldet das.
- **Korrekturen sind sichtbar:** jede Korrektur landet im Protokoll des Artefakts (heute
  `statistik['regelwerk_korrigiert']`) mit Regel-ID und Paragraf, wie in Spec 80 Teil G.

**Einsatzstellen** (ersetzt die verstreuten Konstanten):

| Stelle | heute | danach |
|---|---|---|
| GP anlegen/umbenennen (`GpNamingService::validateGpName`, `createGp`, GP-Modal) | `ZUSTAND_VOCAB`, `VERPACKUNGSWOERTER`, `GENERIK_MARKER` hart | Motor, `ziel = gp.*` |
| Rezept-Generator, Zeile für Zeile (`GpKorrektur`, `kanonischerTyp`, `ROLLEN_RANG`) | teils Dossier-Parser, teils hart | Motor, `ziel = rezeptzeile`, `rezept.name` |
| Matching (`TokenEngine`, `MatchHeuristics::defaultGpAlias`, Schwellen) | hart | Motor liefert Listen/Schwellen; Matching-Logik bleibt Code |
| Bestandsprüfung (`BestandsPassung`, `RezeptTypVokabular`) | Dossier-Parser | Motor (`vokabular` §1.2) |
| Konformität (`*ConformanceAdapter::deterministischeBefunde`) | nur Basisrezept, GP/LA leer | Motor für alle Artefakte |
| Datenqualität (`DataQualityService`) | hart | Motor |

**Grenze:** Der Motor liefert Werte und Befunde. **Wie** gematcht wird (Scores, Kandidaten, Reihenfolge)
bleibt Code, sonst wird die Regel-Tabelle zur zweiten Programmiersprache.

---

## Teil E · Dossiers bereinigen: Regel raus, Text bleibt

**Entscheid Dominique 09.10.:** Was als Regel in der Tabelle steht, wird **aus dem Dossier entfernt**. Keine
Kopie, kein erzeugter Abschnitt. Das Dossier behält nur, was die KI braucht: Begründung, Urteil, Technik,
Grenzfälle. An der Stelle der Liste steht **ein Satz**:

```markdown
Die Werte pflegt Food Alchemist unter Einstellungen › Regeln (§9 Zustand, Verarbeitung, Form).
```

**Reihenfolge je Paragraf** (nie Wissen verlieren):
1. Regel ist in der Tabelle, **aktiv**, Beispiele grün, Probelauf auf demo gesehen.
2. Vault-Spiegel des Dossiers gesichert (Einbahn-Export wie bisher).
3. Dossier neue Version: Liste raus, Verweis-Satz rein, Text bleibt. Über `knowledge-import`/MCP mit
   `created_via = regeln`, nie per Hand-PUT auf große Dossiers.
4. Bleibt im Dossier **nichts** außer dem Verweis, wird es deaktiviert statt geleert (Aktivieren/Deaktivieren
   bleibt Kuration, darum als Vorschlag in der Liste „bereit zum Bereinigen").

**Wo Menschen die Regeln lesen:** auf der Pflegeseite, gefiltert nach Regelwerk und Paragraf. Für das Vault
gibt es beim Export zusätzlich `Regeln_Stand_<Datum>.md`. Das ist eine Sicherung zum Nachlesen, keine zweite
Wahrheit, und sie wird nie zurückimportiert.

**Folge für den Prompt:** Was nicht mehr im Dossier steht, geht auch nicht mehr an die KI. Ein Ausschneide-
Mechanismus im Prompt (Marker, `durchsetzung`-Schalter) entfällt.

---

## Teil F · Prompt schrumpft

1. Die Regelwerk-Dossiers sind nach Teil E schlanker; `ConformanceService::ladeRegelwerke()` (GP/LA) und
   der Kanon-Pfad (Basisrezept/VK) laden sie unverändert, sie sind nur kürzer. Deaktivierte Dossiers fallen weg.
2. Der Satz `vom_code_geprueft` wird aus den aktiven Regeln erzeugt (Paragrafen je Artefakt), nicht mehr hart
   codiert. Damit weiß die KI, dass sie diese Paragrafen nicht prüfen muss.
3. Der Nachfilter (KI-Befund mit gleichem Paragraf/Feld wie ein Code-Befund fliegt raus) bleibt als Netz.
4. **Messung gegen Teil 0**, dieselben drei Aufrufe. Der frei gewordene Platz geht ins Wissensbudget
   (Spec „Wissensbudget einstellbar"), nicht ins Nichts. Ob mehr Wissen die Prüfung besser macht, zeigt der
   Vergleich der Befunde auf denselben Testrezepten.

---

## Teil G · Pflegeseite „Regeln"

Einstellungen › KI & Wissen › **Regeln** (`Settings\Regeln`, Eintrag in `Settings\Index::SEKTIONEN`/`GRUPPEN`).

**Liste**
- Gruppiert nach Regelwerk, darin nach Paragraf (echte Gliederung, keine Pseudo-Gruppen).
- Spalten: Paragraf · Titel · Art · Ziel · Wirkung · aktiv · Treffer im Bestand (aus dem letzten Probelauf).
- Filter links: Regelwerk, Art, Wirkung, aktiv/inaktiv.
- Je Paragraf ein Hinweis, ob das Dossier noch bereinigt werden muss („bereit zum Bereinigen", Teil E).

**Editor** (Seitenpanel, Formular je Art, kein JSON-Feld für Menschen)
- `vokabular`: Werteliste mit Aliasen und Gruppe, Zeilen ziehbar.
- `ersetzung`: Paare *von → nach* bzw. Suffixliste + Zielform.
- `pflichtangabe`: Bedingung (Warengruppe/Unterkategorie/Form/Typ als Auswahl) + Muster + Hinweistext.
- `verbot`: Token-Liste oder Muster (Regex nur mit Prüfung), Ausnahmen.
- `zuordnung`: Begriff + Aliase → Ziel per Suche (GP oder Rezept, mit ID); kaputte Ziel-ID = rot.
- `schwelle`: Zahl + Einheit.
- Immer: Wirkung (korrigieren nur, wo die Art es kann), Notiz, Beispiele *richtig / falsch*.

**Probelauf** (vor jedem Speichern, Pflicht bei aktiven Regeln)
- Läuft die geänderte Regel über den Bestand (GP-Namen, freigegebene Rezepte, Rezeptzeilen).
- Zeigt: „betrifft 214 Grundprodukte", Liste der ersten 50 mit *vorher → nachher* bzw. Befundtext,
  Vergleich zur alten Version („+12 neu betroffen, −3 nicht mehr").
- Beispiele, die nicht mehr stimmen, blockieren das Speichern.

**Wichtig:** Speichern ändert **nur künftige** Anlagen und Prüfungen. Den Bestand anzufassen ist eine eigene,
bewusste Aktion „Auf Bestand anwenden" (nur `korrigieren`-Regeln, mit Bericht, Backup-Hinweis, Rückgängig über
die Versions-Tabelle der betroffenen Artefakte, wo vorhanden). Kein stiller Massenumbau.

**Rechte:** Lesen alle; Schreiben `FaRechte::darf(Admin)` + `TeamScope::mayWrite(null)` (globale Regeln nur
Master-Team), Bereichssperre `settings.regeln` über `MitEinstellungsSperre`. Aktivieren einer Regel ist eine
eigene Aktion mit Bestätigung.

**MCP im Lockstep:** `foodalchemist.rules.GET/PREVIEW/PUT` (PUT legt eine neue Version **inaktiv** an, wie
`knowledge.POST`; Aktivieren bleibt in der UI). Probelauf auch per MCP, damit Agenten Regeln vorschlagen können.

---

## Teil H · Schreibweise mit Umlaut

**Entscheid Dominique 09.10.:** Namen werden mit **ä, ö, ü, ß** geschrieben, nicht mit ae, oe, ue, ss.

**Bestand** (lokale Plattform-DB, 09.10.): **3.815 von 7.948 Grundprodukten** enthalten ae/oe/ue, **keines**
einen echten Umlaut. Rezeptnamen sind schon fast durchgehend mit Umlaut (105 mit ae/oe/ue). Häufigste Wörter:
`stueck` (647), `fluessig` (470), `wuerfel` (216), `broetchen`, `moehren`, `gruen`, `kaese`, `geschaelt` …

**Stumpfes Ersetzen ist falsch.** Gleiche Buchstabenfolge, kein Umlaut: `sauer` (Sauerbraten, Sauerkraut,
Sauerkirsche), `Bauernsalat`, `Landfrauenkuchen`, `Caesar`, `Baguette`, `Merguez`, `Quenelle`, `Bœuf`,
`Kroepoek`, `Aloe`, `Paella`, `neue`, `Feuer`.

**Regel** (`ersetzung`, Ziel `gp.name`, `rezept.name`, Wirkung *korrigieren*):
1. Kandidat ist jedes ae/oe/ue/ss **innerhalb** eines Wortes, **nicht** direkt nach einem Vokal oder `q`
   (fängt `sauer`, `Bauer`, `Frauen`, `neue`, `Feuer`, `Quenelle`).
2. Umgewandelt wird nur, wenn die Umlaut-Form **belegt** ist: Sie steht in einer Wortliste, die aus Wörtern
   mit echtem Umlaut im eigenen Bestand gebaut wird (Rezeptnamen, Inspire-Anker, Wissens-Dossiers, LA-Namen
   der Lieferanten), plus kuratierten Ergänzungen.
3. **Ausnahmen** als eigene Liste in der Regel (Fremdwörter: `Caesar`, `Baguette`, `Merguez`, `Bœuf`,
   `Kroepoek`, `Aloe`, `Paella` …). Erweiterbar auf der Pflegeseite.
4. Nicht belegt und keine Ausnahme ⇒ **nicht** umwandeln, auf die Prüfliste.
5. `ss` → `ß` nur, wenn belegt (`Fluss`/`Fuß`, `Masse`/`Maße` sind beide richtig). Im Zweifel bleibt `ss`.

**Matching bleibt unberührt:** Der Vergleich ist ohnehin umlaut-tolerant (Teil A), ein Name mit ä findet
denselben GP wie der mit ae. Geändert wird nur die Anzeige-Schreibweise.

**Bestandsumbau (Paket 9)** über „Auf Bestand anwenden" (Paket 8):
- Probelauf zeigt *vorher → nachher* für alle 3.815 Namen, getrennt nach *sicher* (belegt) und *Prüfliste*.
- Übernommen wird nur *sicher*; die Prüfliste entscheidet ein Mensch, die Entscheidung erweitert die Wortliste
  oder die Ausnahmen.
- **Nebenwirkungen**, die der Umbau mitnimmt:
  - Embeddings der umbenannten GPs neu rechnen (Qdrant), **außerhalb der Nutzungszeit** (demo-Erfahrung: 502).
  - `gp_key`/Slugs bleiben ASCII und unverändert, damit Verweise nicht brechen.
  - Vault-Spiegel und Necta-Export bekommen die neue Schreibweise beim nächsten Lauf.
- Neue Namen (Anlage, Umbenennen, Generator) werden ab Paket 3 schon richtig geschrieben, der Bestand folgt
  in Paket 9.

---

## Teil I · Weitere Dossiers

Außer den drei Regelwerken gibt es Dossiers mit Listen und Tabellen, die Regeln oder Nachschlage-Daten sind
(z. B. Zutaten-Defaults im Regelwerk-Ordner, Anti-Marker, Mengen-Defaults, Garpunkt-Tabellen). Die
Bestandsaufnahme dazu läuft (09.10.); ihr Ergebnis wird hier eingetragen. Unterschieden wird:

- **Regel** (eine der sechs Arten, der Code setzt sie durch) → Tabelle, Dossier bereinigen wie Teil E.
- **Nachschlage-Daten** (der Code schlägt nach, setzt aber nichts durch, z. B. Kerntemperaturen) → eigene
  Datentabelle, nur wenn eine Funktion sie wirklich nutzt. Ohne Nutzer bleibt es Text; eine Tabelle ohne
  Leser wäre ein Etikett ohne Landebahn.
- **Text** → bleibt im Dossier.

---

## Pakete

| # | Paket | Inhalt | Abhängig |
|---|---|---|---|
| 0 | Messung | Teil 0, Tabelle im PR, kein Code im Modul außer ggf. Mess-Kommando | – |
| 1 | Klärung umsetzen | Teil A in die Dossiers auf demo übernehmen (per `knowledge-import`/MCP, nicht von Hand überschreiben), Vault-Spiegel mitziehen | – |
| 2 | Fundament | Tabellen C1–C4, Motor mit den sechs Arten, Tests je Art, noch **keine** Einsatzstelle | – |
| 3 | Seeds + erste Einsatzstellen | Migration mit den Regeln aus A1–A4 (zuerst §1.2, §2, §9, §10, BR §5); `GpKorrektur`, `RezeptTypVokabular`, `BestandsPassung`, `TokenEngine::CUT_FORM_MARKERS`, `MatchHeuristics::defaultGpAlias` lesen aus dem Motor; doppelte Wahrheiten weg | 1, 2 |
| 4 | Pflegeseite | Teil G ohne „Auf Bestand anwenden"; MCP `rules.*` | 2 |
| 5 | Dossiers bereinigen | Teil E: je aktivierter Regel Liste aus dem Dossier, Verweis-Satz, leere Dossiers deaktivieren (Vorschlag); Vault-Export `Regeln_Stand` | 3, 4 |
| 6 | Prompt schrumpft | Teil F, Messung gegen Paket 0 | 3, 5 |
| 7 | Breite | GP-/LA-`deterministischeBefunde`, `GpNamingService`, `DataQualityService`, BR §1.5a/§1.6/§1.10/§14, GP §7/§8/§12 | 3 |
| 8 | Bestand anwenden | Aktion „Auf Bestand anwenden" mit Bericht | 4, 7 |
| 9 | Umlaute | Teil H: Regel für neue Namen (mit Paket 3), Bestandsumbau mit Prüfliste und Neu-Einbettung | 3, 8 |
| 10 | Weitere Dossiers | Kandidaten außerhalb der drei Regelwerke (Teil I) als Regeln bzw. Nachschlage-Daten | 3, 5 |

Ein PR je Paket, gestapelt wie Spec 80.

## Abnahme

1. Teil-0-Tabelle und Teil-F-Tabelle für dieselben drei Aufrufe liegen nebeneinander; die Regelwerk-Tokens im
   Konformitäts-Prompt sind um den mechanischen Anteil gesunken.
2. Eine Regel in den Einstellungen ändern (z. B. Zustand „gefriergetrocknet" ergänzen) wirkt ohne Deploy auf
   die nächste GP-Anlage und erscheint im Probelauf vorher mit Treffern. Im Dossier steht die Liste nicht mehr.
3. Ein Test sucht im Code nach den alten Konstanten (`ZUSTAND_VOCAB`, `CUT_FORM_MARKERS`, `defaultGpAlias`-Namen,
   `VERPACKUNGSWOERTER`, `GENERIK_MARKER`) und schlägt an, wenn eine zurückkommt.
4. Befunde tragen `quelle`; auf demo ist sichtbar, wie viel der Code und wie viel die KI findet.
5. Abnahme-Beispiel aus Spec 80: „Püree: Petersilienwurzel (grün)" mit Zeile „Schalotten, gewürfelt" wird beim
   Anlegen auf die Rohform korrigiert, **ohne** dass die KI-Prüfung den Paragrafen §2 noch sieht.

## Risiken

- **Strenge deckt alte Daten auf.** Sobald §9 hart gilt, melden sich viele Bestands-GPs (vgl. A2). Darum:
  Regeln starten inaktiv, Probelauf zeigt die Zahl, Bestand nur über Paket 8.
- **Regel-Tabelle als zweite Programmiersprache.** Gegenmittel: nur sechs Arten, keine Bedingungslogik über
  die festen Felder hinaus; was mehr braucht, bleibt Code oder KI.
- **Zwei Orte für ein Regelwerk.** Gegenmittel: Teil E (Liste nur in der Tabelle, im Dossier nur der Verweis).
  Risiko bleibt beim Vault-Import: ein alter Vault-Stand mit Liste darf das bereinigte Dossier nicht
  zurückschreiben. `knowledge-import` vergleicht darum `imported_hash` und bricht bei Dossiers mit
  `created_via = regeln` ab, statt zu überschreiben.
- **Bereinigen vor dem Aktivieren verliert Wissen.** Gegenmittel: feste Reihenfolge in Teil E.
- **Team-eigene Regeln** (z. B. eigene Warengruppen, GP §3 seit v3.4.1 team-änderbar) sind in v1 nicht drin;
  `team_id` ist vorbereitet.
- **Prompt-Caching** (Core stellt Zeit/Persona vor jeden Prompt) ist ein getrennter Hebel und liegt bei Martin;
  Teil 0 liefert die Zahlen dafür.

## Offen

- Gilt `blockieren` auch für die KI-Generierung (Zeile wird verworfen) oder nur für Menschen in der Anlage?
  Vorschlag: KI-Pfad korrigiert oder meldet, blockiert nie (sonst scheitern Läufe still).
- VK-Regelwerk (`DataQualityService` VK §1.2): Ist es ein eigenes Regelwerk in der Tabelle oder Teil von BR?
- Versionsangaben in CLAUDE.md sind veraltet (GP v3.4.1, BR v1.10 laut Vault) – Vault-Pflege, nicht Teil
  dieser Spec.
