# Spec 82 · Team-Architektur

Stand 2026-10-13 · Status: **Entwurf, Architektur abgestimmt mit Dominique**; offen nur die Punkte unter „Offen“ · Branch `docs/spec82-team-architektur`
Bezug: Spec 61 §13, Spec 64 §1b, Spec 65, Spec 77 (77b–d) · Diagramm: [82_Team_Architektur.drawio](82_Team_Architektur.drawio)

> **Einstieg für die bauende Session:**
> 1. Zuerst die vier Punkte unter „Offen“ mit Dominique klären: Entscheidung 1, 3, 4 und die Spuren-Frage.
> 2. Danach mit §10 Schritt 1 beginnen (Kaskaden-Fixes, unabhängig).
> 3. Der Kurator-Aufsatz startet erst mit dem Durchstich §12 F.
> 4. Die Kern-Szenarien §11 werden in einer Sandbox mit Test-Mandant und globalem Stamm abgenommen, nicht auf demo.

> Entstanden im Brainstorming am 13.10.2026 (Anlass: Soulkitchen / LAGO, Projekt-Canvas-Idee).
> **Noch nicht abgestimmt.** Die Punkte unter „Offen / messen“ werden vor dem Bau geprüft.
> Aufbau: **§0 sagt, was Kern und was Aufsatz ist.** Der Kern funktioniert ohne Master-Team.

## Anlass

Bei der Frage „Wie bilde ich ein Kundenprojekt im FA ab?“ zeigte sich, dass die Grundbegriffe nicht sauber getrennt sind:
- „Betrieb“ bedeutet im Code ein Kalkulationsprofil (Outlet) des Oberteams, im Sprachgebrauch aber die autonome Einheit.
- „Kunde“ bedeutet mal den Mandanten (aus Sicht BHG.DIGITAL), mal den Eventkunden (aus Sicht des Mandanten).
- Master-Inhalte sind heute für alle Mandanten sichtbar (Team-Kette bis BHG.DIGITAL).
- Die KI kennt keine Küchen-Fakten (Equipment, Flächen, Kapazität).
- Dazu kommen fünf Fehler in der DNA-Kaskade (§7).

## §0 · Kern und Aufsatz

Die Architektur hat zwei Teile. Der **Kern** gilt für jeden Kunden. Der **Kurator-Aufsatz** gibt es nur, weil BHG.DIGITAL für mehrere Mandanten kuratiert.

| | Kern (jeder Kunde) | Kurator-Aufsatz (nur BHG.DIGITAL) |
|---|---|---|
| Teams | Oberteam + optionale Unter-Teams | Master-Team BHG.DIGITAL |
| Stamm | Plattform-Stamm: Basis + optionales Stamm-Paket | – |
| Rezepte | gemeinsam im Oberteam, lokal im Unter-Team | Master-Bibliothek, Abo an Mandanten |
| Preise | je Mandant | – |
| Wissen über die Küche | Team-DNA, Teamprofil, Machbarkeits-Check | Modul „Mandanten“ (Pflege + Rückschlüsse quer) |
| KI | KontextResolver, Kaskade | – |
| Projekte | – | Projekt-Canvas am früh angelegten Oberteam |

**Ist das Master-Team nötig?**
- **Für den Kern nicht.** Den Plattform-Stamm pflegt eine Rolle (Plattform-Admin bzw. FA-Admin), kein Team. Eine autarke Firma berührt den Aufsatz nie.
- **Für die Kurator-Arbeit ja.** Wer für mehrere Mandanten kuratiert, braucht einen Ort für die wiederverwendbare Bibliothek und das Abo. Ohne Master-Team müsste jede Rezeptur pro Mandant neu gebaut oder von Hand kopiert werden, und zehn Kopien lassen sich nicht pflegen.
- **Folge für den Bau:** erst der Kern, dann der Aufsatz (§10). Wenn sich die Kurator-Arbeitsweise ändert, fällt nur der Aufsatz weg, und der Kern bleibt.

## §1 · Begriffe

| Begriff | Bedeutung |
|---|---|
| **Oberteam** | Der Mandant (z. B. Broich Catering, TM Food Solutions, Soulkitchen Group). Arbeitet voll, auch ohne Unter-Teams. |
| **Unter-Team** | Frei benannte Einheit unter dem Oberteam (Standort, Kostenstelle: WCCB, Kita, Gladbach …). Arbeitet autonom. |
| **Master-Team** | BHG.DIGITAL. Ein Team mit FA-Admin-Rechten, keine eigene Ebene. Nur Kurator-Aufsatz. |
| **Kalkulationsprofil** | Neuer Name für das heutige Outlet / „Betrieb“. Nur Rechenbrille. Nur die **Beschriftung** ändert sich (entschieden Dominique 13.10.); Klassen, Tabellen, Routen und MCP-Toolnamen bleiben. |
| ~~Betrieb~~ | Entfällt als Begriff. |
| **Kunde** | Immer relativ zum Team: Aus Sicht des Master-Teams ist ein Mandant ein Kunde, aus Sicht des Mandanten ist es ein Eventkunde. Das einzige FA-Objekt mit Endkunde ist das **Angebot**. Ein Foodbook wird für ein **Team** freigeschaltet, nicht für einen Kunden. |

**Betriebsarten** (Spec 61 §13):
- **Kuratiert:** Das Master-Team arbeitet im Mandanten mit, als Mitglied mit Rolle; es wechselt ins Team.
- **Autark:** Eine Firma arbeitet allein und bekommt nur den Plattform-Stamm.

## §2 · Plattform-Stamm (Kern)

Global (`team_id NULL`), live, für Teams nur lesend, gepflegt nur durch den FA-Admin.

**Basis – bekommt jedes Team:**
- **Regeln:** Regelwerke, Hausstandards (Spec 81)
- **Vokabular:** Einheiten, Klassen, Kochequipment, Container, Regeneration, Rollen …
- **Wissen:** Dossiers, Pairing (Inspire-Matrix)

**Stamm-Paket – Opt-in je Mandant:**
- **Entschieden (Dominique 13.10.):** Das Stamm-Paket ist Teil der FA-Gebühren. Es enthält **Grundprodukte**, **Lieferanten und Artikel** (Artikelnummer, Bezeichnung, Allergene, Nährwerte, EAN) und die **Verknüpfung Artikel → GP**. Es enthält **keine Preise**. Rezepturen kommen über das Abo (§8).
- **Grundprodukte:** der kuratierte, sichere GP-Stamm, an dem Dominique gerade arbeitet
- **Artikel aller Stamm-Lieferanten** mit **kuratierter Verknüpfung Artikel → GP**. Das teure Mapping passiert einmal zentral.
- Der **Artikelstamm entsteht in vier Schritten:**
  1. Das Oberteam schaltet seine Lieferanten über das Paket in der Stamm-Lieferant-Matrix frei (Lieferant × Warengruppe).
  2. Es lädt seine **eigene** Preisliste mit Artikelnummern hoch (CSV/Excel, später aus Necta per Schnittstelle). Preise kommen ausschließlich vom Mandanten.
  3. Der Abgleich läuft über Artikelnummer + Lieferant (Regelwerk LA §3).
  4. Damit steht sofort ein arbeitsfähiger Artikelstamm.
- **Ungeprüft:** ob die Artikeldaten aus Necta weitergegeben werden dürfen (mit Necta klären).
- **Ohne Stamm-Paket:** leeres System mit nur der Basis. Der Mandant baut Grundprodukte, Lieferanten und Artikel selbst auf.

**Harte Regel: Ein GP ist allergen- und zusatzstoffgleich** (Dominique 13.10.):
- Ein Lieferantenartikel darf **nur dann unter einem GP hängen, wenn er dieselben Allergene und Zusatzstoffe hat** wie alle anderen Artikel dieses GP.
- Weicht er ab, gehört er zu einem anderen GP, oder es wird ein neues GP angelegt.
- **Warum:** Nur so hat ein GP eindeutige Allergene. Die Wahl des Lead-Artikels oder ein Team-Pin kann dann das Allergenbild eines Rezepts **nie** verändern. Das gilt auch für Abo-Rezepte.
- **Durchsetzung beim Verknüpfen** (Artikel → GP): Weicht der Artikel ab, wird die Verknüpfung abgelehnt und ein passendes bzw. neues GP vorgeschlagen. Das gilt für Stamm und Mandant gleichermaßen.
- **Laufende Prüfung:** Necta liefert Artikeldaten täglich. Ändert ein Lieferant die Rezeptur seines Artikels, markiert die Prüfung den Artikel als „passt nicht mehr zum GP“ und legt ihn in eine Prüfliste. Er wird nicht still weitergerechnet.
- **Bestand:** Vor dem Stamm-Umzug auf den eigenen Server läuft ein Bericht „GPs mit uneinheitlichen Artikeln“. Die Bereinigung gehört zur Stamm-Pflege.
- **Regelwerke nachziehen:**
  - `Regelwerk_Lieferantenartikel` §6/§10: Heute wird ALL-MAXIMAL aggregiert, künftig gilt Homogenität als Pflicht.
  - `Regelwerk_Grundprodukte` §16: entsprechend anpassen.
  - Beide werden im FA-Wissensmodul (SSOT) gepflegt.
- **Entschieden (Dominique 13.10.):** Beim Vergleich zählen **Spuren nicht mit**, verglichen wird nur „enthalten“. Fehlende Allergendaten gelten als **unbekannt, nicht als gesperrt**. Die Durchsetzung gilt nur beim **neuen** Verknüpfen; Bestandsverknüpfungen werden nicht automatisch gelöst oder umgehängt. Der Bericht „GPs mit uneinheitlichen Artikeln“ weist zusätzlich aus, wie viele Artikel unbekannt sind. (Ersetzt den früheren Vorschlag „Spuren zählen mit, fehlende Daten = nicht verknüpfbar“.)

**Ersatzprodukte und Ersatzrezepte:**
- Gebaut ist `ComponentEquivalent`: GP↔GP, GP↔Rezept, Rezept↔Rezept, team-weit, mit Umrechnungsfaktor.
- Ersatz-Beziehungen gehören zur **Hülle eines Abos** (§8) und kommen mit.
- Ein Ersatz mit **anderem Allergenbild** ist erlaubt, wird aber beim Tausch als Allergen-Änderung angezeigt.

**Ist-Stand, schon gebaut:**
- GP, Lieferant, Lieferantenartikel und Rezept nutzen `BelongsToTeamHierarchy`: Sichtbar ist `team_id NULL` oder das eigene Team plus Vorfahren, schreiben darf nur das eigene Team.
- `SupplierItem` und `Prices` haben `team_id NULL` = global.
- `StammLieferant` je Team: NULL = globaler Stamm, Kind-Teams erben lesend und ergänzen.
- `GpLaPreference`: Team-Overlay über der globalen Lead-Kette (Artikel locked / gepinnt).

## §3 · Preise (Kern)

- **Entschieden (Dominique 13.10.): Jeder Mandant hat seine eigenen Preise.**
- **Umsetzung:**
  - Preiszeilen mit `team_id` = Oberteam. **Es gibt keinen Listenpreis-Fallback aus BHG-Konditionen** (Dominique 13.10.): Ohne eigenen Preis lautet das Ergebnis „kein Preis, Kosten unvollständig“.
  - Die Preissuche (`activePriceSubquery`) geht in dieser Reihenfolge: Team → Vorfahren (bis zur Kundengrenze). Globale Preiszeilen (`team_id NULL`) gibt es im Zielbild nicht.
  - **Pflicht beim Stamm-Umzug (M2):** Die BHG-Preise (heute Team 6) werden **nicht** als globale Zeilen mitgenommen.
  - Das revidiert Spec 77 „Bewusst nicht: Team-Preis je Artikel“ für die Mandanten-Ebene.
- **Preislisten-Import (Excel/CSV)**, gehört fest zum Kern und ist der Weg, wie ein Mandant zu seinen Preisen kommt:
  - **Spalten:** Lieferant, Artikelnummer, Bezeichnung, Gebinde/Einheit, Preis netto, gültig ab. Die Spaltenzuordnung erfolgt beim Hochladen.
  - **Abgleich** über Artikelnummer + Lieferant (Regelwerk LA §3), danach über EAN.
  - **Treffer** schreiben Preise mit `team_id` = Mandant (append-only mit `valid_from`). Eine globale Zeile wird nie angefasst.
  - **Ohne Treffer:** Die Zeilen landen in einer Prüfliste. Daraus wird ein eigener Artikel des Mandanten, der an ein Grundprodukt gehängt wird (Stamm-GP oder eigenes GP, siehe §4).
  - **Wiederholbar:** Ein neuer Upload aktualisiert die Preise, die Historie bleibt erhalten (append-only wie `foodalchemist_prices`).
  - Später kommt derselbe Weg automatisch über die Necta-Schnittstelle, sobald sie geklärt ist.
- **Unter-Teams erben** die Preise des Oberteams. Abweichen können sie wie heute über den Pin eines anderen Lead-Artikels (Spec 77). Eigene Preise je Unter-Team kommen nur, wenn ein Fall es verlangt.
- **Kostenschicht in zwei Stufen** (aus Review 13.10.):
  - **3a:** Preise mit `team_id`, Preissuche Team → Vorfahren → global. Billig, kommt sofort.
  - **3b:** Kosten je Rezept × Team, zusammen mit dem Abo (Schritt 7).
    - Gilt für **jedes Team mit eigenem Pin oder eigenen Preisen**, nicht nur für Oberteams.
    - Wird **bei Bedarf gerechnet und zwischengespeichert**, nicht für 3.748 Rezepte × n Teams dauerhaft vorgehalten.
  - **Bestehende Lücke** (Code 13.10.): `RecipeRecomputeService::leadPrefs` nimmt nur die Pins des Rezept-Besitzers (`recomputeTeam`). Die Pins eines Unter-Teams wirken also nicht auf geerbte Rezepte. Das ist heute unsichtbar, weil es keine Unter-Teams gibt (M1). 3b schließt die Lücke.

## §4 · Oberteam und Unter-Teams (Kern)

- **Gemeinsame Rezepte pflegt das Oberteam.** Unter-Teams sehen sie standardmäßig alle (77d: keine Zeile = übernimmt alles).
- **Freigaben dienen nur zum Einschränken**, z. B. sieht die Kita nur die Kita-Sammlung. Nichts muss freigeschaltet werden, damit es ankommt.
- **Lokale Rezepte legt das Unter-Team selbst an.** Das Oberteam sieht sie über die Team-Brille („alle“ / „team“).
  - Es gibt **kein Hochspülen und keine Kopie nach oben**.
  - So existiert jede Rezeptur genau einmal.
- **Foodbook:** Das Oberteam gibt es an Unter-Teams frei, oder jedes Unter-Team baut aus den gemeinsamen Rezepten sein eigenes.
- **Artikel** vererbt das Oberteam an die Unter-Teams (77d: Stammdaten voll geerbt), Preise siehe §3.
- **Oberteam ohne Unter-Teams** ist der Normalfall für kleine Mandanten (TM Food Solutions). Es braucht kein künstliches Unter-Team.
- **Eigene Grundprodukte, Lieferanten und Artikel (entschieden, Dominique 13.10.):**
  - Ein Mandant darf eigene Grundprodukte anlegen.
  - Er darf eigene Lieferanten mit ihren Artikeln anlegen und selbst mit Grundprodukten verknüpfen, egal ob mit Stamm-GPs oder eigenen GPs.
  - Das alles gehört ihm (Kunden-IP).
  - Gegen Dubletten: Beim Anlegen eines eigenen GP zeigt das System ähnliche Stamm-GPs an („Gibt es schon: Zanderfilet: frisch …“). Das ist ein Hinweis, keine Sperre.

## §5 · Navigation (Kern)

- **Einstellungs-Abschnitt „Unter-Teams & Freigaben“ im Oberteam** (Dominique 13.10.):
  - **Automatisch** für jedes Oberteam.
  - **Zusätzlich** in der Plattform-Verwaltung (`/verwaltung`, Spec 77b) schaltbar, z. B. wenn ein Mandant keine Standorte gebucht hat.
  - **Inhalt:** Unter-Teams mit Name und Kalkulationsprofil, Schnellstart-Haken, Freigaben, Sammlungen.
  - Das ist der heutige Controlling-Reiter „Standorte & Freigaben“, umgezogen. Bis zum Nachtrag vom 08.10. lag er schon einmal in den Einstellungen.
- **Controlling gibt es für jedes Team.** Das Unter-Team sieht nur sich, das Oberteam sieht per Brille alle bzw. nebeneinander (`jeStandort`). Der Reiter „Standorte & Freigaben“ fällt dort weg.
- Die Einstellungen des Unter-Teams zeigen sein Kalkulationsprofil (heute nur in der Leiste).
- **Brillen** (Ist-Stand Code 13.10., bleibt so):
  - Die **Team-Brille** (eigen / alle / ein Unter-Team) erscheint **nur in einem Team mit Unter-Teams**, also im Oberteam (`TeamBrilleBar`, `StandortService::brille`: ohne Unter-Teams immer „eigen“). Im Unter-Team ist man automatisch in seiner eigenen Sicht und hat keinen Schalter.
  - Die heutige **Betriebs-Brille** wird zur Auswahl des **Kalkulationsprofils**.
    - Im Oberteam ist sie frei wählbar.
    - Im Unter-Team ist sie fest auf das zugeordnete Profil gesetzt und erscheint dort nur als Anzeige, nicht als Schalter. So gibt es im Unter-Team keinen Brillen-Schalter mehr.

## §6 · Teamprofil (Kern, neu)

Beschreibt, was die Küche kann. Das ist keine DNA.
- **Wer pflegt** (Dominique 13.10.):
  - Jedes Team pflegt **sein eigenes** Profil in seinen Einstellungen, ob Oberteam oder Unter-Team.
  - Das Oberteam sieht per Brille die Profile aller Unter-Teams. Es bearbeitet sie im jeweiligen Unter-Team, so wie sein Oberhaupt laut Spec 61 in allen Unter-Teams arbeitet.
  - Der Master **liest** die Profile im Modul „Mandanten“, bearbeitet sie nach einem Wechsel ins Team und kann eine **Profil-Vorlage** mitgeben.
  - Vor der Lizenz gibt es noch kein Team: die **Kunden-Standorte** liegen am Projekt im Master-Team (§9) und werden bei „Lizenz erteilt“ zu Teamprofilen des neuen Oberteams bzw. der Unter-Teams.
- **Ort:** Tabelle `team_profile` (team_id unique), je Oberteam bzw. Unter-Team.
- **Inhalt:**
  - Equipment-Bestand im **gleichen Vokabular** wie der Equipment-Bedarf der Rezepte (`VocabKochequipment`)
  - Flächen (Name, max. Pax, Saison)
  - max. Pax parallel zum Tagesgeschäft
  - Convenience-Grad
  - Personal
- **Eingebunden** werden vorhandene Werte: `kitchen_type`, Posten mit Kapazität und Besetzung, Rollen.
- **Machbarkeits-Check ohne KI:** Ein Rezept braucht Equipment X, das Team hat X nicht → Warnung.
- **Entschieden (Dominique 13.10.):**
  - **Standardausstattung:** Flag `is_standard` am Equipment-Vokabular (Default aus). Standard gilt als vorhanden, solange das Team es nicht ausdrücklich als `nicht_vorhanden` markiert. Warnung nur bei ausdrücklich `nicht_vorhanden`. Welche Einträge Standard sind, bestätigt Dominique.
  - **Convenience-Grad:** die vorhandenen DNA-Werte `from_scratch | teil_convenience | voll_convenience`, keine freie Skala.
  - **Profil-Vorlage** in die Mandanten-Vorlage mitkopieren: erst mit dem Modul „Mandanten" (Schritt 8).
- **Gebaut (Schritt 5, Backend):** Tabellen `team_profiles`, `team_profile_equipment` (Zeile = vorhanden/nicht_vorhanden, keine Zeile = unbekannt, bewusst ohne FK auf das Vokabular), `team_profile_areas`; `TeamProfilService`, `MachbarkeitService` (inkl. Unter-Rezepte, 3 Ebenen), `MachbarkeitKontext`; MCP `team_profile.GET/PUT`, `machbarkeit.CHECK`. Fläche gegen Pax kommt mit dem KontextResolver (Schritt 6).
- **Gebaut (Schritt 5, Oberfläche):** Einstellungen → Betrieb & Küche → „Teamprofil“ (Kennzahlen, Ausstattung vorhanden/fehlt/unbekannt, Flächen) und der Machbarkeits-Hinweis im Rezept-Detail (Warnung nur bei „fehlt“, bei „unbekannt“ ein ruhiger Hinweis).
- **Prosa** (Besonderheiten, Wege, Engpässe) als Canvas am Team.

## §7 · DNA und KI-Kaskade (Kern)

**DNA:**
- **Oberteam-DNA = Vorgaben.** Einzelne Felder sind als **verbindlich** markierbar.
- **Unter-Team-DNA** erbt und überschreibt **feldweise** alles, was nicht verbindlich ist. (Früher wurden die Ebenen nur aneinandergehängt bzw. „nächster Canvas gewinnt“; seit Schritt 6 gilt die feldweise Regel.)
  - **Skalar-Felder:** ein verbindlicher Wert des obersten Teams gewinnt und sperrt das Feld nach unten, sonst gewinnt der nächstliegende nicht-leere Wert.
  - **Repeatable-Felder** (z. B. Geschmackswelten): Vereinigung über die Ebenen, Oberteam zuerst, gleiche Einträge einmal. Das Unter-Team **darf ergänzen**. Verbindliche Einträge des Oberteams bleiben immer drin und sind im Unter-Team nicht entfernbar (Dominique 13.10.).
  - Gebaut als `DnaResolver` (Schritt 6, Kette endet an der Kundengrenze); verbindlich setzt nur das Oberteam.
- **Vorlagen als Daten:** Tabelle `canvas_templates` nach dem Muster der Brief-Vorlagen.
  - Kern-Felder bleiben fest (Spec 64 §1b, Variante A), Zusatzfelder sind pflegbar.
  - Feld-Gruppen werden als Reiter dargestellt.
- **Gebaut (Schritt 6, 13.10.):**
  - **Schema:** `canvas_entries.is_binding`, Tabelle `foodalchemist_canvas_templates` (Zusatzfelder je `canvas_type`, Oberteam-eigen, Unique auf `team_id` + `canvas_type` + `field_key`); `CanvasService::felder(type, team)` mischt Kern- und Zusatzfelder der Team-Kette; `zusatzfeldAnlegen` prüft Team, Kern-Key, Duplikat und belebt gelöschte Felder wieder.
  - **`DnaResolver`:** feldweise Vererbung bis zur Kundengrenze (`kundenHauptTeam`), Zyklus und Tiefe gedeckelt; Ergebnis mit Herkunft (`quelle_team_id`, `verbindlich`).
  - **`KontextResolver`:** setzt den Marken-Kontext in der Reihenfolge unten zusammen, mit Quellenliste und Budget-Bilanz; `CanvasService::cascadeKontext` ist nur noch Adapter (alle Aufrufer unverändert). **Gesamtbudget** `foodalchemist.kontext.budget_zeichen` (12.000); Verbindliches und die Tonalität werden nie gekürzt, sonst von unspezifisch nach spezifisch. **Teamprofil-Block** nur mit ausdrücklichen Fakten („unbekannt“ ist nie „fehlt“). Die einzige gewollte Abweichung zum früheren Kontext: das 3.000er-Limit je Ebene entfällt.
  - **MCP:** `canvas.PUT/ENTRY_ADD` mit `verbindlich` (nur Oberteam + Admin, nur Team-DNA), `canvas.GET` zeigt es; `foodalchemist.kontext.RESOLVE` (lesend) mit Sichtbarkeit nach §13 G (`KontextSicht`): auch der Plattform-Admin sieht autarke Mandanten nicht.
  - **Oberfläche:** Verbindlich-Schalter an der Oberteam-DNA, „Geerbt von …“ und gesperrte Felder im Unter-Team, geerbte Listen-Einträge, Feldgruppen als Reiter (Team-DNA), Sektion „DNA-Zusatzfelder“ (nur Oberteam).
  - **Nicht gebaut / bewusst offen:** Zusatzfelder vom Typ „Liste“ gibt es in der Pflegeseite nicht (nur über MCP/Service); das Board zeigt je Canvas eine Liste. Die Master-Vorlagen für DNA/Teamprofil/Canvas als Kopie an ein Team sind Schritt 8.

**Kaskade:**
- **Zentraler KontextResolver:** Aufrufer übergeben nur das Objekt (Foodbook, Angebot, Concept). Den Rest löst er selbst auf.
- **Reihenfolge:**
  1. Oberteam-Vorgaben (verbindlich)
  2. Team-DNA
  3. Teamprofil
  4. Endkunde, **sobald die Ausgabe eine CRM-Firma trägt** (entschieden Dominique 13.10., Entscheidung 4): Angebot immer, Foodbook und Speisekarte nur mit verknüpfter CRM-Firma (Spec 64), ohne Firma entfällt die Ebene.
  5. Foodbook / Concept
- **Agentur-Akte (Spec 82 §9, Schritt 9 PR 5, gebaut):** Mit `projekt_id` (Foodbook aus der Akte trägt es) wächst die Kaskade zu **Team → Standort → Kunde → Projekt**: Der Standort-Block ist das Profil des Pilot-Standorts (oder `standort_id`), der Projekt-Block nennt Zielbild in Kundenworten, Ziel, Anlässe mit Gästezahl, Paketstufen, Stufe × Anlass und Musterseite (Rollen, Termine, Material, Begehung, Übergabe sind Agentur-intern und bleiben draußen). Beides liest nur das Team, dem das Projekt gehört.
- **Vorrang Kunde vor Team (entschieden Dominique 13.10.):** Bei Widerspruch zwischen Team-„verbindlich“ und Kunden-„Bleibt“ **gewinnt der Kunde für Ausgaben dieses Kunden**, und der Konflikt wird sichtbar gemacht. Umsetzung im `KontextResolver`: der Kunden-Bleibt-Block steht **ganz vorn** und trägt die ausdrückliche Vorrang-Anweisung samt Auftrag, den Widerspruch im Ergebnis zu benennen; der Team-Vorgaben-Block erhält nur dann den Vorbehalt „Bei Widerspruch gelten für diesen Kunden die Kunden-Vorgaben“. Ohne Kunden-Bleibt bleibt der Team-Block unverändert. Der Widerspruch wird nicht maschinell erkannt, sondern der KI zur Benennung aufgetragen.
- **Gesamtbudget** statt nur 3.000 Zeichen je Ebene (gebaut, 12.000 Zeichen, siehe oben).

**Vorab-Fixes** (eigene Aufgabe, unabhängig, bereits als Karte angelegt):
- Food-DNA wird nicht vererbt (`find` exakt) · **erledigt (#298)**
- Angebots-Ebene ist tot · **erledigt (#298)**
- `kunde_dna` fehlt im MCP · **erledigt (#298)**
- DNA-Einstellungen haben keine `MitEinstellungsSperre` · **erledigt (#298)**
- Foodbook-Prompts laufen ohne Kunde. **Nach Entscheidung 4:** Die Kunden-Ebene greift, sobald die Ausgabe eine CRM-Firma trägt. Die bestehenden Foodbook-Übergaben (Spec 42 F3) bleiben. Zu schließen sind die Stellen, an denen die Firma des Foodbooks nicht ankommt (Kapitel-Wording `concept.wording`, Kapitel-Ideen `foodbook.kapitel_ideen`); die Angebots-Ebene (`food_dna_angebot_id`) ist mit Schritt 1 gebaut.

## §8 · Kurator-Aufsatz: Master-Bibliothek und Abo

- **Master-Bibliothek** (`team_id` = BHG.DIGITAL): Rezepturen, Konzepte, Formate und Vorlagen. Sie sieht **nur der Master**, außer was freigegeben ist.
- **Änderung gegenüber heute:** Master-Inhalte sind nicht mehr über die Team-Kette für alle Mandanten sichtbar, sondern nur noch für die, denen sie freigegeben sind.
  - Das ist derselbe Mechanismus wie 77d (Sammlung + Hülle), nur eine Ebene höher.
  - Die Kunden-IP-Regel gilt damit in beide Richtungen: Was für Broich gebaut wird, sieht Soulkitchen nie.
- **Abo als Standard** (Dominique 13.10.: „Bei 10 Kunden müsste ich jede Kopie einzeln ändern“):
  - Eine Sammlung wird an einen Mandanten freigegeben und ist dort live und nur lesend.
  - Änderungen im Master wirken sofort bei allen Abonnenten.
  - Gerechnet wird mit den Artikeln und Preisen des Mandanten.
- **Eigene Kopie als Ausnahme:** nur wenn der Mandant bewusst abweichen muss (`kopieAnlegen`, merkt Original und Stand).
  - `originalGeaendert` meldet, wenn sich das Original geändert hat, und der Mandant kann die Änderung übernehmen.
  - Ein Sammel-Update gilt nur für Kopien, die seit dem Kopieren unverändert sind.
- **Faustregel:**
  - Basisrezepte (Fonds, Jus, Saucen, Teige) laufen fast immer als Abo.
  - Gerichte laufen als Abo, solange der Kunde nichts Eigenes will.

**Entschieden (Dominique 13.10.): Mandanten stehen NEBEN dem Master-Team**, also kein `parent_team_id` zum Master. Ein Mandant sieht vom Master nur, was per Abo freigegeben ist. Kuratierte und autarke Kunden sind technisch gleich, der einzige Unterschied ist, ob Abos existieren.

**Abo und Modus sind unabhängig (Dominique 13.10.):** Ein Abo ist die Richtung **Master → Mandant** (der Mandant liest Master-Inhalte). Der Modus „kuratiert“ (§13 G) ist die Gegenrichtung **Mandant → Master** (der Master liest Mandanten-Inhalte). Ein autarker Mandant kann also ein Abo haben, und ein kuratierter muss keins haben. `AboService` prüft den Modus deshalb nicht.

**Das Abo „schlau“ machen** (Entwurf zur Abstimmung):
1. **Einheit ist die Sammlung**, nicht das Einzelrezept. Abo heißt: Freigabe einer Sammlung an einen Mandanten. Wiederverwendet werden `sammlungen` und die Freigabe aus 77d.
2. **Abhängigkeiten kommen automatisch mit:** Ein abonniertes Gericht zieht seine Basisrezepte und Unter-Rezepte mit, wie bei der 77d-Hülle. Der Mandant sieht nie ein Gericht, dessen Bestandteile ihm fehlen.
3. **Live heißt nicht „sofort halbfertig“:**
   - Änderungen am Master-Rezept gehen erst an die Abonnenten, wenn der Master sie **veröffentlicht**. Dafür wird Entwurf / Bearbeitungssperre aus Spec 65 wiederverwendet.
   - Der Mandant sieht ein Änderungsprotokoll („Jus: Röstgemüse angepasst, 14.10.“).
4. **Ohne Kopie anpassen, wo es geht:** Lead-Artikel bzw. Lieferant wählt der Mandant über seine Pins und seine Matrix. Das ist **keine Abweichung** und das Abo bleibt. Eine eigene Kopie braucht es erst, wenn die **Rezeptur** (Zutaten, Mengen, Schritte) anders sein soll.
5. **Kündigung eines Abos:**
   - Rezepte, die der Mandant in eigenen Ausgaben (Foodbook, Speisekarte …) nutzt, werden **automatisch zur eigenen Kopie** und verschwinden nicht.
   - Ungenutzte verschwinden.
   - Vor der Kündigung zeigt das System an, was betroffen ist.
6. **Transparenz beim Mandanten:** Jedes Abo-Rezept trägt den Hinweis „Abo · BHG.DIGITAL · Stand …“ und ist nur lesbar, mit dem Knopf „Eigene Kopie“.
7. **Preise:** Gerechnet wird immer mit der Kostenschicht des Mandanten (M3).
8. **VK eines Abo-Gerichts (entschieden 13.10., Dominique):** Der VK wird beim Mandanten **nicht gepflegt, sondern berechnet**.
   - Die Fassung übergibt die **Standard-Darreichung** (Form, Portion, Anzahl, Behälter, Deltas) **ohne** Master-Preise und **ohne** Master-Preisklasse.
   - Weitere Darreichungen gehen nur mit, wenn andere abonnierte Inhalte (Paket, Konzept, Menü) sie referenzieren.
   - VK = EK je Portion aus der Kostenschicht des Mandanten × **sein** Basissatz × **seine** Preisklasse, dieselbe Formel wie der Auto-VK.
   - Es gibt keine Overlay-Tabelle. Ohne vollständigen EK steht dort „kein VK“, nie 0 €.

**Ergänzungen aus der Review vom 13.10.:**
- **Zwei Grundeinstellungen, ein Mechanismus. Bewusst so, nicht vereinheitlichen!**
  - Im Mandanten gilt (Oberteam → Unter-Team): keine Freigabe-Zeile = **sieht alles**, eine Freigabe schränkt ein.
  - Vom Master aus gilt (Master → Mandant): keine Abo-Zeile = **sieht nichts**.
  - Beide Fälle bekommen eigene Tests, die genau diesen Unterschied festhalten.
- **Kündigung tief kopieren:** Wird ein genutztes Gericht zur eigenen Kopie, kommen seine abonnierten Basis- und Unter-Rezepte sowie die Ersatz-Beziehungen mit.
- **Löschen im Master:** Ein abonniertes Rezept lässt sich nicht löschen, nur **zurückziehen**. Das löst bei den Abonnenten dieselbe Logik wie eine Kündigung aus.
- **Ersatzprodukte und Ersatzrezepte** (`ComponentEquivalent`) gehören zur Hülle und kommen mit dem Abo mit.
- **Entschieden (Dominique 13.10., Entscheidung 1 = Variante C):** Ein Abo setzt ein gebuchtes Stamm-Paket voraus. Das Paket ist Teil der FA-Gebühren. Die Master-Bibliothek verwendet nur globale Stamm-GPs.

**Ein Food Alchemist, nicht zwei:** Master-Team und Mandant nutzen dieselbe Software, dieselbe Datenbank und dasselbe Datenmodell. Der Unterschied liegt nur in **Bereichen und Rechten** (Spec 77):
- Das Master-Team hat zusätzlich die Bereiche „Mandanten“ und „Abos“ freigeschaltet, dazu die FA-Admin-Rolle (Plattform-Stamm schreiben).
- Zusätzliche Daten des Masters (Abo-Freigaben, Veröffentlichungsstand, Mandanten-Übersicht) liegen in **eigenen Tabellen**, nicht als Zusatzfelder an Rezepten. Ein Mandant hat diese Bereiche nicht und sieht sie nicht.

## §9 · Kurator-Aufsatz: Modul „Mandanten“ und Projekte

**Modul „Mandanten“** (Bereich, nur im Master-Team freigeschaltet):
- **Nur lesend** (entschieden 13.10., aus Review): alle Mandanten mit Unter-Teams in einer Liste, ihr Teamprofil und ihre DNA.
  - Bearbeitet wird **nach einem Wechsel ins Team**, über Knöpfe „Im Team bearbeiten“, die direkt dorthin springen.
  - Ein Schreibrecht über die Mandantengrenze gibt es **nicht**. Es kommt erst, wenn es im Alltag nachweislich fehlt.
- **Rückschlüsse** quer über alle Teams, z. B. Machbarkeits-Check eines Foodbooks gegen alle Küchen.
- **Abo- und Kopie-Stand** je Master-Rezept.
- **Vorlagen** (DNA, Teamprofil, Canvas) als Kopie an ein Team geben. Die DNA wird **nicht live** vererbt, weil die BHG-Handschrift nicht die des Mandanten ist.

**Projekte** (entschieden 13.10., Dominique, Variante B — ersetzt „Projekt hängt an Teams“):
- Ein Projekt ist eine **Agentur-Akte im Master-Team**, verknüpft mit einer **CRM-Firma im CRM des Master-Teams**. Es entsteht **kein Mandant und kein Team**, solange keine Lizenz erteilt ist; der Projektstatus (`aktiv | pausiert | abgeschlossen`) liegt am Projekt selbst. `mandanten.status = projekt` bleibt ein erlaubter Wert, „Projekt anlegen“ schreibt aber keine Mandanten-Zeile.
- **Gebaut (Schritt 9, PR 1):** Tabellen `foodalchemist_projekte` (Firma, Name, Phase, Status, Ziel, Pilot-Standort, Verweis auf das Planner-Projekt) und `foodalchemist_projekt_standorte` (Kunden-Standorte ohne Team, genau einer ist Pilot); Menüpunkt „Projekte“ (Zusatz „Agentur“) unter Übersicht mit Liste und Detail (Brotkrumen, Projektkopf, Phasen als Reiter, Seitenleiste „Verknüpft“); Bereich `projekte`, **nur im Master-Team** (auch ein Plattform-Admin sieht ihn in anderen Teams nicht); MCP `projekte.GET/POST/PUT`; Canvas-Typ `projekt` (`canvas.GET/PUT/ENTRY_ADD` mit `type=projekt`, `owner_id` = Projekt) mit **Listen-Feldern** (Anlässe, Rollen, Material, Termine …), die mehrere Werte je Zeile tragen (Zahl, Haken, CRM-Kontakt).
- **Phasen:** 1 Zielbild & Umfang · 2 Standortprofil · 3 DNA & Handschrift · 4 Paketlogik (mit hartem Gate „Struktur + Musterseite freigeben“, Person = CRM-Kontakt + Zeitpunkt, „Zurücknehmen“) · 5 Foodbook (im Master-Team angelegt, vorbefüllt) · 6 Übergabe & Pflege.
- **Gebaut (Schritt 9, PR 2) · Standortprofil:** Die drei Profil-Tabellen (`team_profiles`, `_equipment`, `_areas`) tragen `standort_id` (NOT NULL, Default 0): 0 = Teamprofil wie bisher, > 0 = Kunden-Standort eines Projekts im Master-Team. Unique-Indizes laufen über (team_id, standort_id[, equipment_id]); bewusst 0 statt NULL, damit „ein Profil je Träger“ in MySQL vom Index garantiert bleibt. Ein Wertobjekt `Profiltraeger` (Team oder Standort) schlüsselt alle Lese- und Schreibwege im `TeamProfilService`; ein Standort als Träger wird gegen Master-Team und vorhandenen Standort geprüft. Menüpunkt „Standortprofil“ unter Übersicht: „Eigene Standorte“ (Überblick über das Teamprofil) und im Master-Team „Kunden-Standorte · aus Projekten“ mit demselben Editor wie das Teamprofil; kein Rauschen in Kontingenten. Löscht man einen Standort, geht sein Profil mit.
- **Gebaut (Schritt 9, PR 3) · Kunden-DNA „Bleibt“ + Phase 3:** Phase „DNA & Handschrift“ der Projekt-Akte bettet das Board der **bestehenden** `kunde_dna` der CRM-Firma ein (eine Kunden-DNA je Firma, dieselbe wie in Einstellungen und Foodbook). Ein Skalar-Feld der Kunden-DNA lässt sich als **„Bleibt – unverhandelbar“** markieren (= `is_binding`, ab Rolle Kuratieren; an der Team-DNA bleibt „verbindlich“ Admin im Oberteam). Der KontextResolver stellt Bleibt-Felder des Kunden als eigenen, **geschützten** Block „Verbindliche Vorgaben des Kunden“ (Rang 0, nie gekürzt) voran; der Rest bleibt im Kunden-Block. Ohne Bleibt-Feld ist der Kunden-Block unverändert. MCP: `canvas.PUT` mit `verbindlich` gilt auch für `type=kunde_dna`. Das Listen-Feld-Board (mehrere Werte je Zeile) kam schon mit PR 1.
- **Gebaut (Schritt 9, PR 4) · Paketlogik, hartes Gate, Foodbook:** Phase „Paketlogik“ im Projekt-Canvas: Paketstufen (Beschreibung, Preis je Gast), Anlässe, Stufe × Anlass (Zellen) und die **Musterseite**. **Gate „Struktur + Musterseite freigeben“** (`struktur_freigegeben_at` + CRM-Kontakt + festgehaltener Name am Projekt): möglich erst mit mindestens einer Stufe, einem Anlass und der Musterseite; „Zurücknehmen“ schließt es wieder (bestehende Foodbooks bleiben, neue entstehen erst nach neuer Freigabe). Das Gate steht **am Modell** (`FoodAlchemistFoodbook::saving` mit gesetztem `projekt_id`), nicht nur in der Oberfläche, damit MCP und Import es nicht umgehen. Phase 5: `foodbookAnlegen` erzeugt im Master-Team ein Foodbook mit `projekt_id` und befüllt dessen Canvas aus der Akte vor (Leitidee ← Zielbild in Kundenworten, Zweck ← Ziel, Pflicht-Konzepte ← Stufen und Anlässe, Struktur ← Musterseite, Spektrum ← Anlässe, Umfang ← Linien). MCP: `projekte.GET` meldet Gate-Stand und was fehlt, `projekte.PUT` kennt `struktur_freigeben_kontakt_id`, `struktur_zuruecknehmen`, `foodbook_anlegen`.
- **Gebaut (Schritt 9, PR 5) · KI-Kaskade der Akte:** `KontextResolver` mit Standort- und Projekt-Block und Kunden-Vorrang (siehe §7); `kontext.RESOLVE` kennt `projekt_id`/`standort_id`; in der Projekt-Akte zeigt die Seitenleiste „Was die KI daraus liest“ die Ebenen mit Zeichenzahl, „nie gekürzt“ an geschützten Blöcken und das Budget-Kürzen.
- **Gebaut (Schritt 9, PR 6) · Lizenz erteilt = Übernahme:** `ProjektUebernahmeService` (nur Plattform-Admin, weil der Modus „kuratiert“ dem Master Inhaltszugriff gibt, §13 G; einmalig je Projekt, eine Transaktion, protokolliert). Das Oberteam ist ein **vorhandenes Host-Team** (Hauptteam, nicht Master, nicht schon einem anderen Projekt zugeordnet); das Modul legt nur Unter-Teams an (`TeamAnlegenService`, aus dem Onboarding-Kommando herausgelöst, beide nutzen ihn). Je Kunden-Standort entsteht ein **Unter-Team** (Pilot zuerst) mit dem Standortprofil als **Teamprofil** (Kennzahlen, Ausstattung, Flächen; Kopie). Die **Kunden-DNA wird Team-DNA des Oberteams**: marke_positionierung → leitbild, ziel_gaeste_anlaesse → ziel_gaeste, kommunikation_ton → signature_stil, erwartungen_nogos → no_gos, preis_erwartung → preis_positionierung; „Bleibt“ wird **verbindlich**; vom Oberteam schon gefüllte Felder bleiben unberührt und werden gemeldet; der Default-Schreibstil wird nicht kopiert (Schreibstile gehören dem Master). **Die Feld-Zuordnung ist entschieden (Dominique 13.10.).** Die **Mandanten-Zeile** wird aktiv + kuratiert mit CRM-Firma; das Projekt bekommt `mandant_team_id` und wechselt in „Übergabe & Pflege“. Alles ist **Kopie, nicht live**; Akte und Foodbook bleiben im Master. Oberfläche: Phase „Übergabe & Pflege“ mit Oberteam-Suche, Vorschau, Hindernissen und Bestätigung; MCP `projekte.UEBERNAHME_GET` (Vorschau) und `projekte.UEBERNAHME_POST`.
- **Gebaut (Schritt 9, PR 7) · Kontingent und Abnahme:** Die Übernahme prüft das Kontingent „Standorte (Unter-Teams)“ aus Spec 77b am Oberteam: bereits vorhandene Unter-Teams zählen mit; würde die Übernahme es überschreiten, ist das ein **Hindernis** in der Vorschau (nichts wird angelegt). Szenario 5 ist neu gefasst (§11) und per Ende-zu-Ende-Test abgenommen, ebenso 7.
- **Verknüpfen statt kopieren:** CRM-Firma, CRM-Kontakte (Rollen) und Planner-Projekt bleiben, wo sie geführt werden; das Modul speichert nur den Verweis und schreibt weder ins CRM noch in den Planner (Fremdmodule).
- **DNA und Standortprofil** (folgen in PR 2/3): die Kunden-DNA schreibt die bestehende `kunde_dna` der CRM-Firma („Bleibt – unverhandelbar“ = verbindlich); Kunden-Standortprofile liegen am Projekt und erscheinen unter dem eigenen Menüpunkt „Standortprofil“ (Übersicht) neben den eigenen Standorten.
- **Lizenz erteilt = Übernahme (PR 6):** Das Oberteam ist das **vorhandene Host-Team**, das Modul legt nur Unter-Teams an (`TeamAnlegenService`). Kunden-Standorte werden zu Teamprofilen, die Kunden-DNA zur Team-DNA (Kopie, nicht live), die Mandanten-Zeile wird aktiv + kuratiert, das Projekt bekommt `mandant_team_id`. Danach trägt das Projekt nur noch die Agentur-Akte.
- **Projekt-Foodbook (entschieden 13.10., Dominique):** bleibt auch nach der Lizenz im **Master-Team** (Agentur-Arbeit); der Kunde bekommt es über die öffentliche Präsentation. „Foodbook an Mandanten übergeben“ als tiefe Kopie ist ein späterer, eigener Schritt und **offen**.
- **Rollen** des Projekts sind CRM-Kontakte, die nur verknüpft werden (Vorschlag „neuen Kontakt anlegen“ später).
- Termine und Aufgaben bleiben im Office-Planner, die Akte verweist nur darauf.
- Click-Dummy: Artifact „FA Projekte & Standortprofil“ (claude.ai, privat bei Dominique).

## §10 · Bau-Reihenfolge

**Kern:**
1. Kaskaden-Fixes (§7, vorab)
2. Navigation + Begriffe: „Unter-Teams & Freigaben“ in die Einstellungen, Outlet → Kalkulationsprofil (§1, §5)
3. Preise je Mandant (§3), **Stufe 3a** (Preissuche mit Team). Stufe 3b kommt mit Schritt 7.
4. **Voraussetzung:** Migrationsstand demo ↔ main abgleichen (M4: erledigt 13.10., demo = main). Dann Allergen-Homogenität beim Verknüpfen durchsetzen (§2), globale Zeilen in `basis` und `stamm_paket` einteilen und Opt-in-Regel im Scope (§12 A3), und Stamm-Paket Opt-in + Preislisten-Import (§2), zuerst CSV/Excel, Necta-Schnittstelle sobald geklärt. Code und Tests gegen `team_id NULL`, **ohne demo-Daten zu verschieben**.

**Daten-Umzug Plattform-Stamm (M2) – erst auf dem eigenen Server** (Dominique 13.10.): Der Stamm (GP, Lieferanten, Artikel; **keine Preise**, siehe §3) wird **nicht auf demo** nach global verschoben. Die BHG-Preise (heute Team 6) werden dabei nicht als globale Zeilen mitgenommen. Er wird beim Aufbau des eigenen Servers sauber als globaler Stamm eingespielt, aus der lokalen GP-/Basisrezept-Pflege (lokale GP-/Basisrezept-Pflege für den neuen Server). Bis dahin gilt auf demo Team 6 als Stamm.
5. Teamprofil + Machbarkeits-Check (§6)
6. DNA feldweise + `canvas_templates` + KontextResolver (§7) · **gebaut** (#300, #303, #307, #311)

**Kurator-Aufsatz:**

7. **Durchstich zuerst (§12 F)**, danach ausbauen. Inhalt:
   - Abo-Tabelle + Sichtbarkeits-Zweig (§12 A)
   - Fassungen und Veröffentlichung (§12 B)
   - Kosten je Fassung × Team inkl. Auslöser-Matrix (§12 C, = Stufe 3b)
   - Kündigung/Zurückziehen mit tiefer Kopie (§12 D)
   - **Größter Baustein der Spec, Schätzung 8–12 Arbeitstage** (vorher 4–6). Fassungen und tiefe Kopie waren vorher nicht eingerechnet.

**Abnahme:** Die Kern-Szenarien (§11) werden in einer **Sandbox mit Test-Mandant und globalem Stamm** geprüft, **nicht auf demo**. Dort liegt der Stamm bis zum eigenen Server in Team 6, und Mandanten ohne Eltern-Team sehen ihn nicht (M1/M2).
8. Modul „Mandanten“ (§9) mit Plattform-Steuerung, Host-Anteil und Gebühren (§13)
9. Projekte (§9)

**Vorgezogen aus §13 (vor Schritt 7, weil sonst Gebautes nicht bedienbar ist):** §13 B1 Stamm-Paket buchen, §13 B2 Bereich „Unter-Teams & Freigaben“, §13 B4 Master-Team festlegen.

## §11 · Prüf-Szenarien

Bevor die Spec freigegeben wird, muss jedes Szenario ohne Widerspruch durchlaufen:

| # | Szenario | Erwartung |
|---|---|---|
| 1 | **Kleine autarke Firma** meldet sich an, will keinen Stamm | Bekommt nur die Basis (Regeln, Vokabular, Wissen), baut alles selbst, sieht nichts vom Master |
| 2 | **TM Food Solutions**, kein Standort, Stamm-Paket gewählt | Arbeitet komplett im Oberteam, wählt Lieferanten, lädt Preisliste, rechnet mit eigenen Preisen |
| 3 | **Broich** mit WCCB, Kita, Gladbach | Rezepte im Oberteam, alle sehen sie; Kita per Freigabe eingeschränkt; Gladbach legt ein lokales Rezept an, Broich sieht es per Brille; Controlling je Standort + nebeneinander |
| 4 | **Master ändert den Jus und veröffentlicht**, Broich und Soulkitchen haben ihn im Abo, Foodpol hat eine eigene Kopie | **Nach der Veröffentlichung** haben Broich und Soulkitchen in ihrer Bibliothek den neuen Jus mit eigenen Preisen; bereits freigegebene Angebote und Produktionsunterlagen behalten ihren Stand (§12 B); Foodpol bekommt „Original geändert“ |
| 5 | **Soulkitchen-Projekt** vor und nach der Lizenz (neu gefasst, Variante B, Dominique 13.10.) | **Vor der Lizenz:** Agentur-Akte im Master-Team, CRM-Firma verknüpft, Kunden-Standort LAGO mit Profil, Kunden-DNA mit „Bleibt“, Paketlogik, Gate (ohne Freigabe kein Foodbook), Foodbook im Master; kein Mandant, kein Team. **Nach „Lizenz erteilt“:** Unter-Team LAGO unter dem vorhandenen Soulkitchen-Oberteam mit Teamprofil (Kopie), Kunden-DNA als Team-DNA des Oberteams („Bleibt“ verbindlich), Mandant aktiv + kuratiert mit CRM-Firma; Akte und Foodbook bleiben im Master. **Abgenommen durch Test** `Spec82AbnahmeSzenario5Test` (PR 7). |
| 6 | **Soulkitchen will Broich-Rezepte sehen** | Geht nicht: Kundeninhalte bleiben im Mandanten, aus dem Master sieht Soulkitchen nur, was an Soulkitchen freigegeben ist Für die Akte gilt: Projekt, Standort-Profile und Foodbook liegen beim Master, nichts davon im Mandanten (im Test belegt). |
| 7 | **KI plant ein Rooftop-Buffet für LAGO** | Resolver zieht Soulkitchen-Vorgaben, LAGO-DNA und Teamprofil; der Machbarkeits-Check warnt bei fehlendem Equipment **Abgenommen durch Test** `Spec82AbnahmeSzenario5Test` (nach der Übernahme; Gäste über der gepflegten Kapazität werden im Kontext benannt). |
| 8 | **Master tauscht im Abo-Jus eine Zutat, das Allergenbild ändert sich** | Die Änderung wird als Allergen-Änderung markiert; was der Mandant sieht, hängt von Entscheidung 2 ab (siehe „Offen“). Die betroffenen Ausgaben des Mandanten werden aufgelistet. |
| 9 | **Unter-Team Gladbach pinnt bei Butter einen anderen Artikel, nutzt Broichs Abo-Rezept** | Gladbach sieht Kosten mit seinem Pin, Broich mit seinem (Stufe 3b). Das Allergenbild ist identisch (homogenes GP, §2). |
| 10 | **Mandant ohne Stamm-Paket abonniert eine Sammlung** | Nicht möglich: Ein Abo setzt ein gebuchtes Stamm-Paket voraus (Entscheidung 1, Variante C). Ohne eigenen Preis: „kein Preis, Kosten unvollständig“, kein Listenpreis-Fallback |
| 11 | **Master will ein abonniertes Rezept löschen** | Löschen gesperrt, nur „zurückziehen“; Mandanten, die es nutzen, bekommen eine tiefe Kopie |
| 12 | **Lieferant ändert die Rezeptur seines Artikels (neue Allergene)** | Die tägliche Prüfung markiert den Artikel als „passt nicht mehr zum GP“ und legt ihn in eine Prüfliste; er wird nicht still weitergerechnet |

## §12 · Verbindlich vor dem Bau: Zugriff, Veröffentlichung, Berechnung

Nach zwei unabhängigen Reviews am 13.10. Die Code-Belege wurden geprüft.

### A · Zugriffsregeln

1. **Das Abo ist eine neue Zugriffsregel und keine 77d-Freigabe.**
   - `InhaltsFreigabeService::freigeben` prüft ausdrücklich `pruefeUnterTeam`.
   - `InhaltsFreigabe::anwenden` hängt an der Vorfahren-Kette.
   - Ein Mandant neben dem Master ist kein Unter-Team und kein Nachfahre.
   - **Wiederverwendet** werden Sammlungen und die Hüllen-Berechnung. **Neu** ist die Tabelle `abos` (master_team, mandant_team, sammlung, seit), dazu ein eigener Sichtbarkeits-Zweig im Scope: „sichtbar, wenn in der Hülle eines Abos des Mandanten“.
2. **Weitergabe an Unter-Teams:**
   - Abo-Inhalte gelten für die 77d-Logik **wie Inhalte des Oberteams**: Unter-Teams sehen sie standardmäßig, und das Oberteam schränkt per Haken bzw. Sammlung ein.
   - Ein Unter-Team kann nicht selbst abonnieren, das Abo hat immer das Oberteam.
3. **Stamm-Paket als echte Regel:**
   - `team_id NULL` heißt heute „für alle sichtbar“ (`BelongsToTeamHierarchy` :47). Das reicht nicht für „Basis für alle, Paket nur bei Opt-in“.
   - Neu: Globale Zeilen bekommen eine Klasse (`basis` | `stamm_paket`), und das Opt-in steht in einer Tabelle je Mandant.
   - Der Scope blendet `stamm_paket`-Zeilen nur bei Opt-in ein.
   - Das betrifft GP, Lieferanten und Artikel (Preise gehören nicht ins Paket, siehe §2); Vokabular, Regeln und Wissen sind immer `basis`.
   - **Gebaut (Schritt 4b):** Spalte `stamm_class` auf GP, Lieferant, Artikel (Default `basis`), Opt-in-Tabelle `foodalchemist_team_stamm_optins`, Scope in `BelongsToTeamHierarchy` und `TeamScope::applyVisible`. **Preiszeilen bekommen keine eigene Spalte:** Das Paket enthält keine Preise (Dominique 13.10.). Solange globale Preiszeilen (Bestand) existieren, folgt ihre Sichtbarkeit dem Artikel über `FoodAlchemistPrice::stammSichtbar` (Abstimmung mit Stufe 3a; eine zweite Spalte wäre eine zweite Wahrheit). Abweichung von der ursprünglichen Fassung dieses Punkts. Das Master-Team (Config) sieht das Paket immer.
4. **Zwei Grundeinstellungen bewusst getrennt** (siehe §8): im Mandanten „sieht alles“, vom Master „sieht nichts“, mit eigenen Tests.
5. **Kein Schreiben über die Mandantengrenze** (entschieden, §9). Kommt es je dazu: je Mandant vergeben und protokolliert, nicht nur über einen Navigationsbereich.

### B · Veröffentlichungsstände

1. **Arbeitsstand und veröffentlichte Fassung sind getrennt.**
   - Spec 65 regelt nur Sperre und Speichern auf Knopfdruck.
   - Neu für Master-Rezepte: Du kannst den Jus tagelang bearbeiten und speichern. Die Abonnenten nutzen weiter die **veröffentlichte Fassung**. Erst „Veröffentlichen“ erzeugt eine neue Fassung.
2. **Fassungen sind versioniert, einschließlich der Unter-Rezepte.** Eine Fassung friert den ganzen Baum ein, den sie braucht.
3. **Wer welchen Stand bekommt:**

| Wo | Stand |
|---|---|
| Bibliothek des Mandanten (Rezept-Browser, Planung, neue Foodbooks) | aktualisiert sich **automatisch** auf die neueste Fassung |
| **Freigegebene Angebote, Produktionsunterlagen, gedruckte bzw. versandte Ausgaben** | **behalten ihre Fassung**; Hinweis „neuere Fassung verfügbar“ mit Knopf „übernehmen“ |
| Allergen- oder Zusatzstoff-Änderung in einer neuen Fassung | Wird besonders markiert. Die betroffenen Ausgaben werden aufgelistet und erst nach Bestätigung des Mandanten übernommen. |

Damit erledigt sich Entscheidung 2 („Offen“): Eine zentrale Veröffentlichung verändert nie rückwirkend die Arbeitsgrundlage eines Kunden.

### C · Berechnungskontext

1. **Eine Kostenberechnung hat einen vollständigen Kontext:**
   - Rezept-Fassung
   - Team (Preise, Pins, Matrix)
   - Preisstichtag
   - optional Kalkulationsprofil (Marge, Zuschläge)
   - Kosten werden je **(Rezept-Fassung × Team)** gespeichert bzw. zwischengespeichert, nicht mehr am Rezept.
2. **Auslöser-Matrix** (was löst welche Neuberechnung aus):
   - Preis-Import oder Pin eines Teams → nur Rezepte, die **dieses Team** nutzt, und nur für dieses Team
   - Neue Fassung im Master → alle Abonnenten, aber nur ihre Bibliotheks-Sicht. Eingefrorene Ausgaben bleiben, wie sie sind.
   - Änderung am Stammartikel (Allergene, Nährwerte, Verknüpfung) → alle Teams, die den Artikel nutzen. Preise ändern nur der Mandant selbst.
3. **VK aus dem Kontext (13.10.):** Der VK eines Abo-Gerichts ist Teil desselben Kontexts. Gerechnet wird er so: EK je Portion des Teams (Standard-Darreichung der Fassung) × Basissatz des Teams × Preisklasse des Teams. Ohne vollständigen EK gibt es „kein VK“. Food-Cost-Quote, Wareneinsatz und Deckungsbeitrag entfallen, sobald EK oder VK unvollständig sind (Auflage 4: 0 € ist kein Preis).
4. **Abnahmetest** (Zahlen beispielhaft):
   - Derselbe Jus kostet bei Broich 4,20 €, bei Soulkitchen 5,10 €.
   - Ändert Broich einen Einkaufspreis, werden Broichs abhängige Gerichte neu berechnet, und Soulkitchens Werte bleiben unverändert.

### D · Kündigung und Zurückziehen als Ablauf

1. **„Genutzt“ heißt:** in einer aktiven Ausgabe des Mandanten (Foodbook, Speisekarte, Speiseplan, offenes Angebot) oder in einem eigenen Rezept als Zutat. Dazu zählen Entwürfe.
   - Archivierte Ausgaben und historische Angebote bekommen **keine Kopie**. Sie behalten ihre eingefrorene Fassung (B).
2. **Tiefe Kopie ohne Doppel:**
   - Kopiert wird die ganze benötigte Rezeptkette.
   - Ein Basisrezept, das mehrfach vorkommt, wird **einmal** kopiert, und alle Verweise zeigen auf diese eine Kopie.
   - Ersatz-Beziehungen kommen mit.
3. **Zweites Abo:** Ist dasselbe Rezept über ein anderes, weiterhin aktives Abo verfügbar, wird nichts kopiert.
4. **Vorschau:** Vor der Kündigung zeigt das System, was kopiert wird und was verschwindet.

### E · KontextResolver mit Ausführungskontext

1. **Der Resolver bekommt nicht nur das Objekt**, sondern einen Ausführungskontext:
   - Ziel-Team
   - berechtigter Benutzer
   - optional Termin und Gästezahl
   - Grund: Dasselbe Foodbook kann für LAGO oder eine andere Küche geprüft werden.
2. **Unbekannt heißt nicht Nein:**
   - Teamprofil-Werte sind dreiwertig: vorhanden / nicht vorhanden / unbekannt.
   - Der Machbarkeits-Check warnt nur bei „nicht vorhanden“. Bei „unbekannt“ gibt er einen Hinweis „Profil unvollständig“.

### F · Erster technischer Durchstich (vor allen weiteren Schritten des Aufsatzes)

- **Aufbau:** ein Master-Rezept mit Unter-Rezept, zwei unabhängige Mandanten mit unterschiedlichen Preisen, ein eingeschränktes Unter-Team.
- **Muss sauber laufen:**
  1. Abo
  2. Kosten je Mandant (C3)
  3. Arbeitsstand, dann Veröffentlichung (B)
  4. eingefrorenes Angebot
  5. Allergen-Bestätigung
  6. Abo-Entzug mit tiefer Kopie (D)
- Erst wenn dieser Fall trägt, werden Modul „Mandanten“ und Projekte gebaut.

## §13 · Plattform-Steuerung, Host-Anteil und Gebühren

Ergänzt 13.10.2026 (Dominique: „die komplette Spec umgesetzt, sodass ich alles steuern kann“; Abstimmung Kurator mit Spec-Autor).

### A · Grenze Host und Modul

**Regel: Der Host bettet ein, das Modul besitzt.**
- Im **FA-Modul** liegt alles Fachliche: Tabellen, Services, Rechteprüfung (über `FaRechte`, nicht im Host), MCP-Tools und die Livewire-Komponenten selbst.
- Der **Host** (food-alchemist.de, `/verwaltung` aus Spec 77b) hat nur, was eine Plattform auch ohne FA hätte: Teams und Mitglieder anlegen, Rollen, Modulfreigaben, Login, Hülle. FA-Verwaltung bindet er als dünne Einbettung einer Modul-Komponente ein (z. B. `<livewire:foodalchemist.verwaltung.stamm-paket>`).
- **Prüffrage je Punkt:** Bräuchte ein anderer Host (z. B. office), der den FA betreibt, das auch? Dann gehört es ins Modul, sonst ist es beim Host-Wechsel weg.
- food-alchemist.de ist ein lokales Repo ohne Remote: Host-Arbeit läuft als lokale Commits, vom Kurator gegengeprüft.

### B · Steuerpunkte

| # | Steuerpunkt | Wo | Wer | Regel |
|---|---|---|---|---|
| B1 | **Stamm-Paket buchen / abbestellen** | Modul-Komponente + MCP, im Host in `/verwaltung` eingebettet | Plattform-Admin | Beim Abbestellen zeigt das System, welche Abos dadurch ungültig werden (Abo setzt Paket voraus, Entscheidung 1 = C). |
| B2 | **„Unter-Teams & Freigaben“ schaltbar** (§5) | Kein neues UI: weiterer Eintrag im Bereichs-Katalog `FaBereiche` (Spec 77b), `/verwaltung` → Freigaben schaltet ihn | Plattform-Admin | — |
| B3 | **Mandant anlegen** | Team: Host (`/verwaltung` → Teams, Core), ohne Eltern-Team = neben dem Master. Status „Projekt“ und CRM-Firma: FA-Tabelle, gepflegt im Modul „Mandanten“ | Plattform-Admin bzw. Master | CRM-Firma stammt aus dem CRM des Master-Teams. Kontingente bleiben 77b. |
| B4 | **Master-Team festlegen** | Plattformweite FA-Einstellung (eigene FA-Tabelle ohne `team_id`), ENV `FOODALCHEMIST_MASTER_TEAM_ID` bleibt Fallback | Plattform-Admin | Ein Wechsel ändert die Sichtbarkeit aller Abos: erlaubt nur ohne Abos, sonst mit Wirkungs-Vorschau und Bestätigung. |
| B5 | **Gebühren-Preispflege** | Modul-Komponente (Variante B, §13 D), im Host eingebettet | Plattform-Admin | Je Mandant Gebühren-Vorschau aus `team_billable_usages`. |
| B6 | **Abos verwalten** | **Nur im FA-Modul**, Bereich „Abos“ im Master-Team | Master, Rolle „Freigeben“ | Kuration, keine Plattform-Verwaltung. In `/verwaltung` höchstens lesend als Zahl. |

### C · Was für „alles steuern“ zusätzlich gebaut wird
1. **Mandanten-Stammblatt** im Modul „Mandanten“ (lesend): gebuchte Bereiche, Stamm-Paket ja/nein, Abos, Unter-Teams, Kontingente, Gebühren-Vorschau, Projekt-Status, CRM-Firma. Ein Blick je Mandant, mit Sprung zu den jeweiligen Schaltern.
2. **Rechte im Master-Team:** Veröffentlichen (Fassung, §12 B) und Abos vergeben nur mit FA-Rolle „Freigeben“, nicht jedes Master-Mitglied.
3. **Mandant beenden als Ablauf**, in dieser Reihenfolge mit Vorschau: Abos kündigen (tiefe Kopie, §12 D) → Stamm-Paket abbestellen → **Datenexport** → Bereiche aus → Team sperren (Kurator 13.10.: Der Export steht vor „Bereiche aus“ und vor dem Sperren; sein Download hängt an keinem Bereich, eigene Route/Tool, nur Inhaber).
   - **Nur Plattform-Admin.** §13 G: Der Admin löst aus und sieht nur Manifest/Zählung; die Datei ist nur für Inhaber/Admin des Mandanten-Teams herunterladbar, der Master nur bei kuratierten Mandanten.
   - **Sperren = Vollsperre, rückgängig** (Dominique 13.10.): kein FA-Zugriff außer dem Export-Download; rückgängig heißt Status zurück auf „aktiv“, die Bereiche bleiben aus, bis sie neu gebucht werden.
   - **Löschen der Daten gehört NICHT zum Ablauf** (Dominique 13.10.): eigener späterer Schritt nach Vertrag/AVV. **Offen.**
   - **Gebaut (PR 1):** Vorschau + Lauf-Gerüst (`MandantBeendenService`, Tabelle `foodalchemist_mandant_beenden_laeufe`, MCP `mandanten_beenden.GET`); Abos entfallen als Schritt, solange es das Abo-Modell nicht gibt (`AboAuskunft::verfuegbar()`).
   - **Gebaut (PR 2, #320):** `fuehreAus` (Bestätigung Pflicht, idempotent, fortsetzbar; ein fehlender Export hält den Lauf **vor** dem Sperren an), `entsperren` (Status zurück auf „aktiv“, Bereiche bleiben aus), **Vollsperre** `TeamSperre` an drei Stellen (Seiten-Middleware, Livewire-`call`-Haken, MCP-Hülle; Ausnahmen Export, Plattform-Steuerung, öffentliche Präsentationen; keine Ausnahme für den Plattform-Admin), MCP `mandanten_beenden.POST`, Verwaltungs-Komponente `foodalchemist.verwaltung.mandant-beenden` (Einbetten in `/verwaltung` ist Host-Arbeit).
   - **Gebaut (PR 3):** **Datenexport** `MandantExportService`/`MandantExportJob`: ZIP je Mandant (JSON-Lines je Tabelle `foodalchemist_*` mit `team_id`, dazu Kind-Tabellen über den Eltern-Datensatz, `manifest.json` mit Zählung und SHA-256, `LIESMICH.txt`); nie Stamm, Fremdes, Gelöschtes, Verwaltungs-/Technik-Tabellen. **Nicht im ZIP: hochgeladene Dateien (Fotos, Logos).** Auslösen: Plattform-Admin oder Mandanten-Inhaber; **Laden nur, wer wirklich Inhaber/Admin im Mandanten ist** (Mitgliedschaft, nicht die Plattform-Admin-Abkürzung), Master nur bei kuratierten. Download-Route `foodalchemist.mandant-export.download` (bereichsfrei, auch gesperrt erreichbar), MCP `mandant_export.GET/POST`. Der Sperr-Merker wird bei Queue-Ereignissen und `terminating` geleert (wie `StammPaket`).
   - **Offen:** Datei-Anhänge im Export; Aufbewahrung/Löschen alter Exporte (mit dem späteren Lösch-Schritt nach AVV); Export-Schaltfläche in der Verwaltung.
4. **Protokoll** aller Plattform-Admin-Aktionen (Paket, Master, Gebühren, Abos) über das vorhandene activity-log.
5. **MCP-Tools für jede Steueraktion B1–B6** (Lockstep), damit Steuern auch per Claude geht.
6. **Platzhalter Necta-Zugang je Mandant** (später Import): nur als Tabelle/Feld vormerken, nicht bauen.

### D · Gebühren (Variante B, entschieden 13.10.)
- **Preismodell (Platzhalter außer zwei bestätigten Werten):** Basis je Oberteam 79 € inkl. 2 User · **jeder weitere User 19 €** (bestätigt) · **jedes Unter-Team 50 €** (bestätigt) · Stamm-Paket 149 € · Master-Abo alle Sammlungen 199 € · KI: Kontingent inklusive, darüber Selbstkosten plus Aufschlag. Beispiele: autarke Firma 3 User 98 €; TM Foodsolutions (Paket+Abo, 5 User) 484 €; Broich (Paket+Abo, 12 User, 3 Unter-Teams) 767 €.
- **Bau (Variante B):** FA-eigene Tabelle `foodalchemist_plan_preise` (Baustein, Betrag/Monat, gültig ab/bis, Notiz, geändert_von; append-only) · Pflegeseite (B5) · täglicher Zähl-Command je Kunden-Hauptteam schreibt in die Core-Tabelle `team_billable_usages` (Monatspreis als Tageswerte; je Baustein eigener `billable_model`-Schlüssel `foodalchemist.*`). FA übergibt `billables` **nicht** an `registerModule` (sonst Doppelzählung). Keine Core-Änderung.
- **Geprüft 13.10.:** `InvoiceService::createInvoiceForTeam` gruppiert nach `billable_model|billable_type|label` und summiert; Tageswerte ergeben je Baustein eine korrekte Monatsposition.
- **Anzeige:** „Plan und Nutzung“ in den Einstellungen des Oberteams (Inhaber/Admin), Master sieht dieselbe Sicht je Mandant (C1).
- Unter-Teams zahlen nicht selbst, ihre Gebühr läuft über das Kunden-Hauptteam.
- **Entschieden (Dominique 14.10.): Abgerechnet wird nur Status „aktiv“.** Projekt (vor der Lizenz) und beendet kosten nichts.
- **Sicherheitsregeln des Zähl-Laufs (Review Kurator 14.10.):**
  1. **Fürs Geld gilt „ohne Zeile“ nie als aktiv.** Gezählt wird nur, wer eine ausdrückliche Zeile in `foodalchemist_mandanten` mit Status `aktiv` hat. Auf der Plattform ist jedes persönliche Team ein Haupt-Team ohne Eltern-Team und würde sonst abgerechnet. Für die Anzeige (Stammblatt) bleibt „ohne Zeile = aktiv + autark“.
  2. **Der Zähl-Lauf ist ausgeschaltet, bis Dominique die Abrechnung bewusst einschaltet:** `config foodalchemist.gebuehren.zaehlen_aktiv` (ENV `FOODALCHEMIST_GEBUEHREN_ZAEHLEN`, Default **aus**). Er schreibt in die Core-Tabelle `team_billable_usages`, die der Core in `ModalPricing` anzeigt und im `InvoiceService` abrechnet. Aus = der Scheduler registriert nichts, der Befehl läuft nur mit `--dry-run` oder `--force`.

### G · Betreiber und Kurator getrennt (Dominique 13.10. abends, entspricht Spec 61 §13 / F8)

Der Master sieht Inhalte **nur bei kuratierten Mandanten**. Bei autarken Mandanten sieht er nichts, **auch nicht als Plattform-Admin**.

| Rolle | Darf sehen | Darf nicht |
|---|---|---|
| **Betreiber / Plattform-Admin** (Verwaltung) | je Mandant nur Verwaltungsdaten: Teams, Unter-Teams, gebuchte Bereiche, Stamm-Paket, Kontingente, Gebühren, Projekt-Status, Anzahl Abos | Rezepte, Preise, Foodbooks, Angebote, DNA, Teamprofile |
| **Kurator** (Master-Team, Inhalt) | Inhalte nur bei Mandanten im Modus „kuratiert“ (vertraglich vereinbart), lesend | Schreiben nur als Mitglied nach Team-Wechsel |

**Folgen für den Bau:**
1. Das Mandanten-Stammblatt (C1) zeigt bei autarken Mandanten nur die Verwaltungsspalten. Rückschlüsse quer über Teams (Teamprofile, Machbarkeit) gelten nur für kuratierte Mandanten.
2. **Modus kuratiert / autark** ist ein FA-Feld je Mandant (gleiche FA-Tabelle wie Projekt-Status, B3). Umschalten nur durch den Plattform-Admin, mit Protokoll (C4).
   - Der Modus regelt nur, ob der Master Inhalte des Mandanten lesen darf. Er ist **unabhängig vom Abo** (Master → Mandant, §8). Ein Abo ist auch bei „autark“ möglich.
3. Der Plattform-Admin bekommt **keine Inhalts-Ausnahme** in `FaRechte`. Bestehende Stellen „Plattform-Admin sieht alles“ werden geprüft: für Bereiche (`darfBereich`) ok, für Inhaltslesen nicht.
4. **Pflicht-Test:** Plattform-Admin + autarker Mandant ergibt 0 Rezepte, 0 Preise, 0 Foodbooks über Oberfläche und MCP.
5. **Bekannte Grenze:** Server- bzw. DB-Zugriff des Betreibers wird nicht technisch gelöst, sondern über Vertrag (AVV, Vertraulichkeit) plus Protokoll.

### H · Wissen: Master-Wissen wirkt, ist aber unsichtbar (Dominique 13.10., bestätigt das Zielmodell vom 06.09.)

- **Master-Wissen** (Regelwerke, Dossiers, Kanon, Routings) liegt global und wird nur vom Master gepflegt. Es wirkt in **jedem** Prompt aller Mandanten, sonst arbeitet der FA nicht gut.
- **Für Mandanten unsichtbar:** nicht im Wissens-Browser, nicht per MCP (`knowledge.GET/SEARCH/LIST`), nicht in Vorschau-Tools. Der Kontext-Inspektor zeigt höchstens „Plattform-Wissen“ ohne Inhalt. Gilt auch für den Plattform-Admin als Betreiber (§13 G) — nur der Master pflegt.
- **Eigenes Wissen des Mandanten** (z. B. „unsere Tomaten schneiden wir so“): eigene Team-Dossiers, nur für dieses Team (und seine Unter-Teams) sichtbar, kommen **zusätzlich** in den eigenen Prompt. Sie überschreiben den Kanon des Masters **nicht** (Kanon = nur Master).
- **Schreibpfad global:** nur Master-Team (B4) bzw. FA-Admin. Ersetzt die heutige Regel „global = für alle eingefroren“ (`TeamScope::owns(null)`), Tests entsprechend.
- **Sofort-Befund:** `Settings\Wissenskategorien::render()` liest `knowledge_categories` ohne Team-Filter → Kategorie-Namen fremder Teams sichtbar. Wird geschlossen.
- **Umzug** des heutigen Wissens (Team 6 → global, inkl. Kanon und Embedding-Partition) zusammen mit dem Stamm-Umzug auf dem eigenen Server, nicht auf demo.

### I · Einstellungen: Basis, Paket „Grundeinstellungen“, Team (Dominique 13.10., **Grundsatz (a)** entschieden)

**Grundsatz (a):** Was der FA zum Laufen braucht **und worauf Master-Inhalt zeigt** (Master-Rezepte im Abo, Stamm-GPs, Regelwerk, Rechnen), ist **Basis**: global (`team_id` NULL), **alle lesen, nur der Master schreibt, das Team ergänzt eigene Zeilen**. Ein **Paket „Grundeinstellungen“** (Kopie beim Buchen, danach gehört es dem Team) bekommt nur **reine Team-Arbeit**, auf die kein Master-Inhalt zeigt. Alles, was Betrieb und Hardware eines Kunden beschreibt, gehört dem **Team selbst**.

**Warum Basis statt Paket-Kopie:** Eine Kopie vergibt **neue IDs**. Abo-Rezepte (§8) und Stamm-GPs zeigen aber auf die **Master-IDs** von Kategorien, Klassen, Einheiten, Warengruppen, Servier-Vehikeln, Equipment (`recipes.category_id`, `dish_class_id`, `markup_class_id`, `recipe_ingredients.unit_vocab_id`, `gps.warengruppe`, `recipe_equipment.equipment_id`, `*.serving_form_id`). Als Team-Kopie zeigten sie auf Zeilen, die das Team nicht hat. Weg (a) braucht deshalb **keine ID-Abbildung beim Abo**.

**Die Tabelle je Einstellung** (heute `team_id` / NOT NULL / global möglich, Vorschlag, Begründung, was darauf zeigt): [82a_Grundeinstellungen_Entscheidungstabelle.md](82a_Grundeinstellungen_Entscheidungstabelle.md). Sie ist die **Grundlage für den Bau**; was dort ohne Kreuz steht, ist noch in Durchsicht bei Dominique.

| Ebene | Inhalt (Kurzfassung, Details in der Tabelle) |
|---|---|
| **Master / Basis** (global, nur Master pflegt, alle lesen, Team ergänzt eigene Zeilen) | Regeln · Wissen (H) · KI-Anbieter/Modelle · Trend-Taxonomie · **Rezept-Kategorien, Gerichte-Klassen, Preisklassen-Struktur, Einheiten, Warengruppen, Servier-Vehikel, Koch-Equipment, Behälter, Regenerationsgeräte** · Pairing-Anker · Stückgewicht-Standards · Komponenten-Äquivalenz |
| **Paket „Grundeinstellungen“** (Kopie ins Oberteam, Unter-Teams erben; nur reine Team-Arbeit) | Konzept-Kategorien und Konzept-Merkmale · Schreibstile · Rollen & Stundensätze · Posten & Kapazität · Speiseplan-Chips · Zuschlagsschema/Fixkosten-Beispiele · Planungsrahmen · Trend-Startbestand · Präsentations-Designs · Werte der Preisklassen (Struktur ist Basis) |
| **Team / Unter-Team selbst** | Kalkulationsprofile · Zugriffsrechte · Küchen-Profil · Teamprofil · Food DNA · Kunden-DNA · Lagerorte · Etiketten/Drucker · Geschirr-Katalog · Stamm-Lieferanten-Matrix · KI ein/aus |

**Technische Folgen von (a):**
1. **Migration `team_id` nullable** für Basis-Kataloge, die heute NOT NULL sind (`serving_forms`, `component_equivalents`; die Kataloge `vocab_containers`, `vocab_regeneration_devices`, `vocab_serving_vehicles` sind schon nullable); **kein Datenumzug auf demo** — das Heben der Team-6-Zeilen nach global kommt mit dem Stamm-Umzug-Befehl auf dem eigenen Server.
2. **Scope und Schreibrecht** je Basis-Katalog: Lesen = global + eigene Kette (`applyVisible`), Schreiben global nur Master (wie Wissen, §H), Team schreibt nur eigene Zeilen.
3. **Paket „Grundeinstellungen“**: buchen per Kopie über `/verwaltung` (wie B1, nur Plattform-Admin, Protokoll, MCP im Lockstep), Kopie ins Oberteam, Unter-Teams erben, **idempotent** (zweites Buchen kopiert nicht doppelt).

**Befunde aus der Inventur:**
- Ein neues Haupt-Team sieht heute nur globale Zeilen; fast alles liegt in Team 6 → neues Team ist faktisch leer (Messung 13.10.: außer `rules`, `terminology_*`, `brief_templates` und 8 von 23 Wissens-Kategorien ist auf demo **nichts** global).
- Konzept-Merkmale (`target_groups`) haben `team_id NOT NULL` und wurden nur per Migration für damals vorhandene Teams angelegt → später angelegte Teams haben leere Listen (Paket löst das).
- `serving_forms` (Befund Lisa) und `component_equivalents` sind `team_id NOT NULL` → „Basis live“ braucht die Migration aus Folge 1. (`recipe_containers`/`recipe_regenerations` sind Verknüpfungen am Rezept, keine Kataloge.)
- Es gibt keinen allgemeinen Seed/Kopier-Mechanismus für Einstellungen; Teil-Seeds nur per Befehl/Knopf (behaelter-katalog, trends-startbestand, Speiseplan-Chips, Fixkosten-Beispiele).

**Offen:** Die übrigen Zeilen der Tabelle sieht Dominique noch durch (gebaut wird nach Vorschlag). Zwei Einzelfragen: Struktur der Preisklassen als Basis mit Werten je Team überschreibbar (Vorschlag) oder ganz Paket; Pflege der Trend-Taxonomie nur durch den Master.

### E · Nicht in Spec 82
- **Selbstregistrierung** (Signup, E-Mail-Bestätigung, AGB, Zahlung) ist Host/Core und eine eigene spätere Spec. Spec 82 stellt nur sicher, dass ein von Hand angelegtes Oberteam ohne Master, Abo und Paket voll funktioniert (Szenario 1).

### F · Offen (Entscheidung Dominique, nicht technisch)
- **AVV / Vertragsklausel:** Der Master liest Mandantendaten (kuratiert = lesend, Spec 61 F8). Braucht das eine Klausel bzw. einen Auftragsverarbeitungsvertrag?
- **Preisschutz:** Gilt eine Preisänderung ab Datum auch für Bestandskunden (AGB, Kündigungsfrist)?
- **Beträge** für Basis, Stamm-Paket und Abo.

## Messung 13.10.2026 (demo per SSH lesend + lokale Plattform + Code)

| # | Frage | Ergebnis | Folge |
|---|---|---|---|
| M1 | Hängen Mandanten per `parent_team_id` unter BHG.DIGITAL? | **Nein.** demo und lokale Plattform: 8 Teams, **alle ohne Eltern-Team**. `FOODALCHEMIST_MASTER_TEAM_ID` ist **nirgends gesetzt** (config = NULL). Keine Unter-Teams, `team_betriebe` leer. | Die Hierarchie-Logik ist gebaut, aber **ungenutzt**. Schritt 7 hat **keine Bestandsdaten, die brechen können** → Risiko deutlich kleiner als angenommen. Wir legen fest, ob Mandanten unter das Master-Team gehängt werden (Kette) oder daneben stehen (dann Abo per Freigabe-Tabelle) – das ist frei wählbar. |
| M2 | Gibt es heute einen globalen Stamm (`team_id NULL`)? | **Nein.** Auf demo liegt **alles in Team 6** („Demo“): 7.984 GPs, 264.516 Artikel, 221.592 Preise, 120 Lieferanten, 3.748 Rezepte. 0 Zeilen global. | Der Plattform-Stamm muss erst **entstehen**. Er wird **auf dem eigenen Server** als globaler Stamm aufgebaut, nicht per Migration auf demo (Dominique 13.10., siehe §10). Die Rezepte werden die Master-Bibliothek. |
| M3 | Rechnet ein Rezept mit den Preisen des betrachtenden Teams? | **Nein.** `PriceService::activePriceSubquery` filtert **nicht nach Team** (ein Preis je Artikel). Die Kosten stehen **einmal am Rezept** (`recipes.ek_total_eur`), die Neuberechnung (`RecipeRecomputeService::recomputePipeline(recipeId)`) kennt kein Team. | **Abo und Preise je Mandant brauchen eine Kostenschicht je Team:** Preissuche mit Team (Team → Vorfahren → global) **und** Kosten je Rezept × Team (z. B. Tabelle `recipe_team_costs`), neu gerechnet je abonnierendem Team. Das ist der größte Einzelbaustein → Schritt 3 wird etwa doppelt so groß. |
| M4 | Stamm-Lieferant-Matrix auf demo? | **Korrigiert 13.10. (Schritt 4a):** Die Tabelle heißt seit dem Englisch-Umbau `foodalchemist_preferred_suppliers` und **existiert auf demo**. Alle 373 Migrationen aus main stehen auf demo, der FA-Pin steht auf main (nur Spec-82-Doku fehlt). Spaltenvergleich GP, Lieferant, Artikel, Preis: identisch. | Kein Migrations-Vorlauf nötig. |

## Offen (vor Freigabe)

- [x] ~~Hängen die Mandanten unter BHG.DIGITAL?~~ → M1: nein, es gibt noch keine Mandanten-Hierarchie
- [x] ~~Rechnet ein Master-Rezept mit den Preisen des Mandanten?~~ → M3: nein, Kostenschicht je Team nötig
- [x] ~~Mandanten unter oder neben dem Master-Team?~~ → **daneben**, entschieden 13.10. (§8)
- [ ] **Necta-Schnittstelle:**
  - Zielbild: Der Mandant lädt seine Daten in Necta hoch, und sie kommen in sein Team.
  - Recherche vom 13.10.: necta.one (Internorga 17.03.2026) hat eine dokumentierte REST-API. **necta.supply** ist das erste Modul darauf, mit strukturiertem Lieferanten-Datenaustausch. Daneben laufen die FTP-Formate (EDIFACT, Hogast, BMECat, Delegate, HRPRICAT) mit Artikelstamm, Tagespreisen und LMIV.
  - **Ungeprüft:** ob es Konditionen bzw. Preise je Necta-Mandant gibt. Das muss mit Necta geklärt werden.
- [x] ~~Entscheidung 1 · Abo ohne Stamm-Paket~~ → **Variante C (Dominique 13.10.):** Ein Abo setzt ein gebuchtes Stamm-Paket voraus, das Paket ist Teil der FA-Gebühren und enthält keine Preise. Preise kommen ausschließlich vom Mandanten, kein Listenpreis-Fallback. Die Master-Bibliothek verwendet nur globale Stamm-GPs.
- [ ] **Necta-Artikeldaten:** Dürfen die Artikeldaten aus Necta (Artikelnummer, Bezeichnung, Allergene, Nährwerte, EAN) an Mandanten weitergegeben werden? Mit Necta klären.
- [x] ~~Entscheidung 2 · Allergen-Änderung im Abo~~ → gelöst durch §12 B (Fassungen; eingefrorene Ausgaben bleiben; Allergen-Änderung nur nach Bestätigung). Zusammen mit der Homogenitäts-Regel (§2) entsteht sie nur noch durch eine Änderung der Rezeptur.
- [x] ~~Entscheidung 4 · Endkunde in der Kaskade~~ → **entschieden (Dominique 13.10., zweite Fassung): Die Kunden-Ebene greift, sobald die Ausgabe eine CRM-Firma trägt.** Angebot immer, Foodbook und Speisekarte nur mit verknüpfter Firma, ohne Firma nichts (§7). Die bestehenden Foodbook-Übergaben (Spec 42 F3) bleiben.
- [x] ~~Sektion „Unter-Teams & Freigaben“ zusätzlich in `/verwaltung` schaltbar (§5)~~ → **erledigt:** die Sektion hängt am Bereich „unterteams“ (`FaBereiche`, Spec 82 §13 B2). Aus = Sektion unsichtbar und Direktlink gesperrt; Plattform-Admins sehen sie wie bei jedem Bereich immer. Das Dropdown „Unter-Team → Kalkulationsprofil“ bleibt in Einstellungen → Kalkulationsprofile.
- [x] ~~Entscheidung 3 · „Outlet“ im Code umbenennen?~~ → **entschieden (Dominique 13.10.): nur die Beschriftung.** Klassen, Tabellen, Routen und MCP-Toolnamen bleiben (§1/§5). Der Code hängt in 124 Dateien, auch an Speisekarten, Druckern, Etiketten und Präsentationen.
- [x] ~~Allergen-Homogenität im Detail~~ → Spuren zählen nicht mit, fehlende Daten = unbekannt, nicht gesperrt (Dominique 13.10., §2)
- [x] ~~Schreibrecht des Masters über die Mandantengrenze?~~ → nein, Modul „Mandanten“ nur lesend (§9)
- [x] ~~Wo wird das Teamprofil gepflegt?~~ → im jeweiligen Team, Master liest (§6)
- [x] ~~Eigene Artikel eines Mandanten → GP?~~ → Der Mandant verknüpft selbst (§4)
- [x] ~~Eigene Grundprodukte?~~ → ja, mit Dubletten-Hinweis gegen den Stamm (§4)
- [x] ~~Globale Lieferanten/Artikel: Stamm oder Mandant?~~ → Stamm-Paket, das Oberteam wählt über die Matrix (§2)
- [x] ~~Eigene Preise je Mandant?~~ → ja, entschieden (§3)
- [x] ~~Kuratierungs-Tabelle aus Spec 61 §13?~~ → nicht nötig, solange es nur einen Kurator gibt
