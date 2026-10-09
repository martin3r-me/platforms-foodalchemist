# Spec 81 · Regeln als Daten

Stand 2026-10-09 · Branch `docs/spec81-regeln-als-daten` · Entscheid Dominique 09.10.: **Variante B**
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
| Mechanische Regel (Liste, Muster, Schwelle, Zuordnung) | **Regel-Tabelle** | Im Dossier als automatisch erzeugter Abschnitt |
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

## Teil A · Klären, bevor migriert wird

Die Bestandsaufnahme (09.10., alle drei Regelwerke, Vault-Stand gegen Export 05.10.) hat Widersprüche
gefunden. Ein Regel-Motor setzt jede Liste **streng** durch. Darum muss vorher entschieden sein, welche
Liste gilt. **Entscheidungen Dominique:**

| # | Widerspruch | Vorschlag |
|---|---|---|
| A1 | GP §4 kennt 8 Form-Werte, §9 nur 6 | §9 ist Wahrheit, §4 verweist nur noch |
| A2 | Die „verbindlichen" Beispiele GP §19, §6.2, §14 verstoßen gegen §9 und §6.1: `gemahlen`, `getrocknet`, `vorgegart`, `fein`, `geputzt`, `kg` im Namen, Plural-Doppelnamen | Je Token entscheiden: in §9 aufnehmen oder Beispiel korrigieren. Die korrigierten Beispiele werden die Testsätze der Regeln (Teil C4) |
| A3 | `derivat_typ` steht dreimal (GP §11.2, GP §17, BR §11), Allergen-Vererbung dreimal (GP §16, LA §10, BR §7) | Je **eine** Regel, die anderen Stellen verweisen |
| A4 | BR §5 Default-GPs: der Export (05.10.) ist neuer als der Vault (Olivenöl kalt/heiß, Sojasauce glutenfrei, Gelatine nach Fertigungstiefe, Zucker Raffinade) | Export gilt; Default-GPs werden **die erste Zuordnungs-Regel** und ersetzen die if-Kette |
| A5 | BR §4 F4.3: der Export ist älter („max. 3 Ebenen"), der Vault hat seit 03.08. nur Selbstreferenz/Zyklus hart | Vault gilt; Dossier auf demo nachziehen |
| A6 | BR §8.1/§8.2 (Pairing-Anker 3–7, Chemie-Komponenten) ist seit der Pairing-Umstellung 06.10. veraltet | Paragraf neu fassen oder streichen, **nicht** als Regel übernehmen |
| A7 | LA-Regelwerk beschreibt ein Schema, das es nicht mehr gibt (`wawi_gp_la`, Legacy-Klassen-Präfixe `OBST_`/`GEM_`…); §8 Lead-LA veraltet gegenüber `pick_lead_la` | LA bekommt in Spec 81 nur `match_method`-Vokabular, §5-Schwellen, §13-Convenience-Marker. Neufassung LA-Regelwerk = eigene Aufgabe |
| A8 | GP §6 „trennt Name, Untergruppe …" widerspricht dem Präfix-Verbot; Slot-Namen „Eigenschaft"/„Zustand/Zuschnitt" vertauscht | Wortlaut klären, bevor das Schema als Regel kodiert wird |
| A9 | GP §14 Synonym-Tabelle steht im Plural (gegen §6.1) | korrigieren |

Ohne A1–A4 startet Paket 3 (Migration) nicht. A5–A9 dürfen parallel laufen.

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

### C3 `foodalchemist_rule_paragraphs`

Je Paragraf eines Regelwerks: `regelwerk`, `paragraph`, `dossier_slug`, `durchsetzung` = `code \| ki \| beides`.

- `code`: alle Inhalte des Paragrafen sind Regeln → das Dossier geht **nicht** in den Konformitäts-Prompt.
- `ki`: reiner Text → geht in den Prompt wie bisher.
- `beides`: Regeln **und** Urteil (z. B. §8: Pflichtangabe flaggt der Code, Plausibilität beurteilt die KI)
  → Dossier geht in den Prompt, aber ohne den erzeugten Regel-Abschnitt (Teil E).

Gepflegt auf derselben Einstellungsseite. Ersetzt die hart codierte §-Liste in `vom_code_geprueft`.

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

## Teil E · Dossier zeigt die Regeln, schreibt sie aber nicht

Je Paragraf mit Regeln bekommt das Dossier einen **erzeugten Abschnitt** zwischen Markern:

```markdown
<!-- fa-regeln:start §9 -->
**Regeln (aus den Einstellungen, Stand 2026-10-12, nicht hier bearbeiten)**
| Regel | Art | Wirkung | Werte |
| Zustand | Vokabular | blockieren | frisch · TK (Alias: tiefgekühlt) · trocken · konserviert |
<!-- fa-regeln:end §9 -->
```

- Erzeugt beim Speichern einer Regel (eigene Dossier-Version, `created_via = regeln`).
- **Schutz vor Überschreiben:** `knowledge-import` und Wissens-Browser lassen den Bereich zwischen den Markern
  unverändert bzw. setzen ihn neu. Wer ihn im Browser ändert, bekommt einen Hinweis mit Link zur Regel.
- Der Vault-Spiegel bekommt denselben Abschnitt beim nächsten Export (Einbahn wie bisher).
- **Für den Prompt** wird der Bereich bei `durchsetzung = beides` herausgeschnitten, bei `code` fällt das ganze
  Dossier weg (Teil C3).

Damit liest ein Mensch im Dossier weiter das ganze Regelwerk; nur wo eine Liste steht, kommt sie aus den
Einstellungen.

---

## Teil F · Prompt schrumpft

1. `ConformanceService::ladeRegelwerke()` (GP/LA) und der Kanon-Pfad (Basisrezept/VK) lassen Dossiers mit
   `durchsetzung = code` weg und schneiden bei `beides` den Regel-Abschnitt heraus.
2. Der Satz `vom_code_geprueft` wird aus C3 erzeugt, nicht mehr hart codiert.
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
- Je Paragraf eine Zeile „Durchsetzung: Code / KI / beides" (C3), direkt umstellbar.

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

## Pakete

| # | Paket | Inhalt | Abhängig |
|---|---|---|---|
| 0 | Messung | Teil 0, Tabelle im PR, kein Code im Modul außer ggf. Mess-Kommando | – |
| 1 | Klärung | Teil A: Entscheidungstabelle mit Dominique, Dossiers auf demo korrigieren (per `knowledge-import`/MCP, nicht von Hand überschreiben) | – |
| 2 | Fundament | Tabellen C1–C4, Motor mit den sechs Arten, Tests je Art, noch **keine** Einsatzstelle | – |
| 3 | Seeds + erste Einsatzstellen | Migration mit den Regeln aus A1–A4 (zuerst §1.2, §2, §9, §10, BR §5); `GpKorrektur`, `RezeptTypVokabular`, `BestandsPassung`, `TokenEngine::CUT_FORM_MARKERS`, `MatchHeuristics::defaultGpAlias` lesen aus dem Motor; doppelte Wahrheiten weg | 1, 2 |
| 4 | Pflegeseite | Teil G ohne „Auf Bestand anwenden"; MCP `rules.*` | 2 |
| 5 | Dossier-Abschnitt | Teil E inkl. Import-Schutz | 2, 4 |
| 6 | Prompt schrumpft | Teil F, Messung gegen Paket 0 | 3, 5 |
| 7 | Breite | GP-/LA-`deterministischeBefunde`, `GpNamingService`, `DataQualityService`, BR §1.5a/§1.6/§1.10/§14, GP §7/§8/§12 | 3 |
| 8 | Bestand anwenden | Aktion „Auf Bestand anwenden" mit Bericht | 4, 7 |

Ein PR je Paket, gestapelt wie Spec 80.

## Abnahme

1. Teil-0-Tabelle und Teil-F-Tabelle für dieselben drei Aufrufe liegen nebeneinander; die Regelwerk-Tokens im
   Konformitäts-Prompt sind um den mechanischen Anteil gesunken.
2. Eine Regel in den Einstellungen ändern (z. B. Zustand „gefriergetrocknet" ergänzen) wirkt ohne Deploy auf
   die nächste GP-Anlage, steht im Dossier-Abschnitt und erscheint im Probelauf vorher mit Treffern.
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
- **Zwei Orte für ein Regelwerk.** Gegenmittel: Teil E (Dossier zeigt die Regel, Marker-Schutz), und der
  Wissens-Browser verlinkt auf die Regel statt eigene Bearbeitung zu erlauben.
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
