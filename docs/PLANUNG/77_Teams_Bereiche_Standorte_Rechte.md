# Spec 77 · Teams, Bereiche, Standorte und Rechte auf der Plattform

Stand 2026-10-08 · **abgestimmt mit Dominique** (F1–F5 entschieden) · Umsetzung in Stufen 77a–77d · gilt für die Plattform **food-alchemist.de** (demo ist kein Ziel)

> **Tracking:** Office Dev-Package 23, Features-Board (Issue #922, Folge-Paket Benutzerrechte).
> **Löst ab:** [61 · Rollen und Rechte](61_Rollen_und_Rechte.md) in §3–§6 (Rollenmodell, Datenmodell, Durchsetzung). §12 (Rezept-Freigabe über Ausgaben) und §13 (kuratiert/autark) gelten weiter und werden hier eingebaut.
> **Baut auf:** [75 · Wareneingang & Triple Match](75_Wareneingang_und_Triple_Match.md) (`FaRechte`, Rolle aus der Plattform-Rolle), Betriebs-Kalkulation (29.08., Outlets + Betriebs-Brille).

## Anlass

Dominique (08.10.): Auf der Plattform muss einmal geradegezogen werden, was ein **Team** freigeschaltet bekommt, was ein **User** darf, wie **Standorte** abgebildet werden und welche **Rezepturen** ein Standort sieht. Heute ist das verstreut, an vielen Stellen gar nicht geprüft und teils widersprüchlich dokumentiert.

## Ist-Stand (Bestandsaufnahme 08.10.)

**Team**
- Module (FA, Planner, CRM, Canvas, Notes, Academy, Organization, Integrations, Hatch) schaltet nur der Plattform-Admin in `/verwaltung` frei (`modulables`, Core `CheckModulePermission` auf allen FA-Routen). Ganz oder gar nicht.
- **FA-Bereiche** (Einkauf, Lager, Kalkulation …) lassen sich nicht je Team schalten — die Navigation (`config/foodalchemist.php`, 7 Gruppen, 28 Einträge) hat keine Rechte-Schlüssel.
- Einstellungen mit Freischalt-Charakter (KI-Notschalter, Sprachagent-Modus inkl. `nur_lesen`, Bestellversand, Trendradar) darf **jedes Mitglied** ändern.
- Keine Kontingente (Anzahl Betriebe, KI-Budget je Team).

**User**
- Core kennt owner/admin/member/viewer; **`/verwaltung` bietet viewer nicht an** → „nur lesen" ist auf der Plattform nicht einstellbar. Standard bei Einladung: member.
- Core erlaubt **Modul-Freigabe je User** — wirkt als *Team **oder** User* (`Module::hasAccess`): ein User kann ein Modul bekommen, das sein Team nicht hat.
- `FaRechte` (seit 75): owner/admin → FA-Admin, member → Kuratieren (+ Häkchen „darf Rechnungen freigeben" → Freigeben), viewer/kein Mitglied → Lesen; höchste Rolle über Team + Eltern.
- Drei Admin-Prüfungen mit unterschiedlicher Reichweite: `FaRechte` (Kette + Plattform-Admin), `MitBearbeitungssperre::istTeamAdmin` (nur aktuelles Team), Host `PlattformAdmin::rolleIn` (eine Elternebene). *Korrektur zu Spec 75:* sie kommen nur bei direkter Mitgliedschaft zum selben Ergebnis.

**Durchsetzung**
- `FaRechte` greift nur in Wareneingang, Rechnungen, Abgleich und Bestell-Freigabe. **Überall sonst darf jedes Mitglied — auch ein Betrachter — schreiben**, was die Team-Grenze zulässt: Bestellung senden/stornieren, Zahlungsstatus, Rezept-/GP-/Konzept-/Format-/Angebots-/Produktions-Status, alle Einstellungen, alle MCP-Schreib-Tools.
- **Sicherheitslücke:** `RecipeService::setStatus` prüft nur Sichtbarkeit, nicht Besitz → Status geerbter/Master-Rezepte über die Oberfläche änderbar (nur `RecipesStatusTool` prüft Besitz; `guardVkRecipe` prüft nichts).
- „Freigeben & senden" im Bestell-Editor prüft das Freigeben, das Senden nicht.

**Standorte**
- Betriebe (Outlets) gibt es je Team; die Betriebs-Brille ist eine **Rechenbrille** (Fixkosten, Marge, Stundensatz, VK) — Filter nur bei Signalen/Fixkosten, beim Anlegen nirgends automatisch gesetzt.
- **Ohne Betrieb:** Bestellungen, Lager, Lieferscheine, Rechnungen, Produktion, Angebote, Einkaufsjournal, Verkaufsdaten.
- Ein Unter-Team kann die Betriebe seines Oberteams **nicht** wählen (`ActiveOutletContext` nur eigene Betriebe).
- **Controlling rechnet nur im eigenen Team** (`WareneinsatzAbweichungService`, `PurchaseJournalService`: `team_id = eigenes Team`). Sichtbarkeit geht nur von unten nach oben (`TeamScope::applyVisible`).
- Eigene Einkaufspreise je Team gibt es (Team-Pin des Lead-Artikels, `gp_la_preferences`).

**Inhalte**
- Ein Unter-Team sieht **alles** vom Oberteam (alles oder nichts). Spec 61 §12 (Freigabe über Ausgaben) ist entschieden, nicht gebaut.

## Zielbild — vier Achsen, eine Grundregel

> **Grundregel:** Das **Team** bekommt, was gebucht ist. Der **User** bekommt eine Rolle und kann darin **nur eingeschränkt, nie über das Team hinaus erweitert** werden. „Aus" heißt: nicht in der Navigation, Seiten und MCP gesperrt — nicht nur ausgeblendet.

### A · Module und FA-Bereiche

| | Team (Plattform-Admin) | User (Team-Admin) |
|---|---|---|
| **Module** (FA, Planner, CRM …) | an/aus je Team (vorhandene Core-Freigabe) | einzelne Module **abschalten** |
| **FA-Bereiche** | an/aus je Team | einzelne Bereiche **abschalten** |

- **Bereichs-Katalog** (F4 entschieden): Übersicht (Dashboard, Signale) · Planung · Controlling · Stammdaten (Grundprodukte, Lieferanten, Geschirr, Favoriten) · Rezepte (Basisrezepte, Gerichte) · Concepter & Formate · **Foodbook** · **Speisekarte** · **Speiseplan** · **Angebote** · Produktion (Produktion, Tagesplan, Wandmonitor, Etiketten) · Einkauf (Bestellungen, Wareneingang, Bestellvorlagen) · Lager · Wissen (Wissen, Trendradar) · Einstellungen. Die Ausgaben sind einzeln schaltbar (ein Kunde braucht z. B. nur Speiseplan). Feinere Schnitte nur, wenn ein Kunde sie braucht.
- Jede Route, jedes MCP-Tool und jeder Navigationseintrag bekommt **einen** Bereich (eine Zuordnungstabelle im Code, nicht über Namensmuster).
- **Core-Modul je User:** Die heutige User-Freigabe darf nicht mehr erweitern. `/verwaltung` bietet je User nur noch „abschalten" an; ob Core eine User-Sperre trotz Team-Freigabe respektiert, ist **Prüfpunkt P1** — sonst wird die User-Einschränkung für ganze Module im FA-Navigations-/Routenfilter umgesetzt, ohne Core-Änderung.
- **Bestandsteams:** alle Bereiche an (niemand verliert etwas). Neue Teams: Bereiche nach Buchung.

### B · Rolle

| Plattform-Rolle (`team_user`) | FA-Stufe | darf |
|---|---|---|
| Inhaber / Admin | FA-Admin | alles im Team, inkl. Einstellungen und Freigabe-Häkchen |
| Mitglied | Kuratieren | anlegen, bearbeiten, Bestellungen **senden**, Lieferscheine buchen, Rechnungen erfassen |
| Mitglied + Häkchen „darf Rechnungen freigeben" | Freigeben | zusätzlich Rechnungen freigeben/bezahlt, Bestellungen freigeben/ablehnen |
| Betrachter | Lesen | alles sehen, drucken, exportieren; nichts schreiben |

- **Senden darf, wer Bestellungen machen darf** (= Kuratieren), Entscheidung Dominique 08.10.
- `/verwaltung` bekommt **Betrachter** als vierte Rolle und die Spalte **„darf Rechnungen freigeben"** (ruft `FaRechte::setzeFreigabe`) — eine Pflegestelle. Die FA-Seite „Zugriffsrechte" bleibt als Ansicht/Fallback.
- Rollen vererben nach unten (Oberteam-Rolle gilt in jedem Unter-Team mindestens), nie seitwärts. KI-Benutzer höchstens Kuratieren.
- **Ein Admin-Begriff:** `istTeamAdmin` (Bearbeitungssperre) liest künftig `FaRechte` (Kette + Plattform-Admin).

### C · Standorte — Unter-Team, Betrieb und zwei Brillen

**Jeder Standort ist ein Unter-Team** des Oberteams. Das Oberteam ist der Admin-Bereich.

| | Was sie wählt | Oberteam | Unter-Team |
|---|---|---|---|
| **Team-Brille** | welcher Standort: dessen **Daten** (Bestellungen, Lager, Wareneingang, Rechnungen, Controlling) und dessen Einkaufspreise; „alle" = Summe und Vergleich der Standorte | frei umschaltbar | fest auf sich selbst |
| **Betriebs-Brille** | welches **Kalkulationsprofil** (Fixkosten, Marge, Stundensatz) | frei umschaltbar | fest auf den **zugeordneten Betrieb**; ohne Zuordnung = Standard (erbt komplett) |

- **Betriebe liegen im Oberteam**; jedes Unter-Team bekommt **genau einen** davon zugeordnet (neue FA-Tabelle `foodalchemist_team_betriebe`: team_id → outlet_id des Oberteams). `ActiveOutletContext` liefert im Unter-Team diesen Betrieb fest, im Oberteam den gewählten.
- **Eigene Einkaufspreise = Eigenschaft des Unter-Teams** (Team-Pin, vorhanden), nicht des Betriebs. Ein Standort mit eigenen Preisen wird **nie** ein unverbundenes Haupt-Team — sonst sieht ihn das Controlling nicht.
- **Lesen nach unten (Kernbaustein):** Das Oberteam liest die Daten seiner Unter-Teams (Team-Brille). Schreiben bleibt im besitzenden Team. Geschwister sehen einander nicht. Betrifft `TeamScope` (neuer Lese-Scope „Team + gewählte/alle Unter-Teams") und die Listen/Auswertungen in Einkauf, Lager, Wareneingang, Rechnungen, Produktion, Controlling.
- **Controlling konsolidiert:** je Unter-Team mit dessen Einkaufspreisen und dessen Betriebsprofil gerechnet, im Oberteam summiert und nebeneinander gestellt.
- **Einschränkung eines Users auf Standorte** ergibt sich aus seiner Unter-Team-Mitgliedschaft — keine eigene User-Betrieb-Liste.

### D · Inhalte — Rezepturen freigeben

Unverändert Spec 61 §12, ergänzt um Sammlung und Schnellstart:
- **Schnellstart je Unter-Team:** Haken **„übernimmt alles vom Oberteam"** = sieht alles inkl. Neues (heutiges Verhalten). Bestandsteams: an.
- **Haken aus:** sieht nur, was freigegeben ist — über
  - **Sammlungen** (neue schlanke Ausgabe-Art: benannte Liste aus **Basisrezepten, Gerichten, Konzepten, Formaten**, ohne Layout/Veröffentlichen; Befüllen per Mehrfachauswahl „zu Sammlung hinzufügen"; mehrere je Team, ein Objekt darf in mehreren stehen; Empfehlung: Gerichte/Konzepte/Formate aufnehmen, Basisrezepte einzeln möglich),
  - und fertige Ausgaben (Foodbook, Speiseplan, Speisekarte).
- Die Freigabe zieht die Abhängigkeiten als **Hülle** mit (Format → Konzepte/Gerichte → Basisrezepte → Grundprodukte → Lieferantenartikel, inkl. Allergene, Kalkulation, Darreichungen), gespeichert und bei Änderung neu berechnet.
- Freigegebenes ist **lesend**; Anpassen = **eigene Kopie** im Unter-Team (Herkunft gemerkt, Hinweis bei Änderung des Originals). Unter-Teams legen weiter **eigene Rezepturen** an; das Oberteam sieht sie über die Team-Brille.
- Kein Schalter je Rezept, keine Kategorie-Freigabe. Kunden-IP-Regel bleibt.

## Durchsetzung — eine Stelle

`FaRechte` wird bereichsbewusst: `darf(user, team, bereich, stufe)` / `pruefe(...)` beantwortet Bereich (Team gebucht ∧ User nicht abgeschaltet) und Stufe (Rolle) in einem. Angeschlossen an:
1. **Navigation** — Einträge ohne Recht erscheinen nicht.
2. **Routen** — Middleware je Bereich (403 statt leerer Seite).
3. **MCP** — `FoodAlchemistTool` prüft vor jedem Tool Bereich + Stufe (Lese-Tools: Lesen; `read_only = false`: Kuratieren; Freigabe-Tools: Freigeben). Die KI kann nie mehr als ihr Benutzer.
4. **Services** — schreibende Methoden prüfen selbst (gleiche Regel für UI, MCP, Jobs). Ohne Benutzer (Queue, Kommando) keine Prüfung, wie in 75c.

## Stufen

- **77a · Lücken schließen + Rollen durchsetzen** (ohne neues Datenmodell)
  - Rezept-`setStatus`: Besitz + Kuratieren; `guardVkRecipe` reparieren.
  - Bestellungen: senden/stornieren/Runden senden ab Kuratieren; `updatePayment` „bezahlt" ab Freigeben; „Freigeben & senden" prüft beides.
  - Status-Setter (GP, Konzept, Format, Angebot, Ideen, Planung, Produktion, Lieferant) ab Kuratieren.
  - **Alle** Einstellungen ab FA-Admin (F3), Mitglieder lesend; `TeamSettingsPutTool` und Settings-MCP-Tools ebenso.
  - MCP-Grundprüfung in `FoodAlchemistTool` (Schreib-Tools ab Kuratieren).
  - `istTeamAdmin` → `FaRechte`.
  - Host `/verwaltung`: Rolle Betrachter + Spalte „darf Rechnungen freigeben".
- **77b · Bereiche, Kontingente, User-Einschränkung**: Bereichs-Katalog, Zuordnung Route/Tool/Navigation → Bereich, Team-Freischaltung (nur Plattform-Admin) und User-Abschaltung (Team-Admin), Kontingente (Standorte, User, KI-Budget), Pflege in `/verwaltung`, Prüfpunkte P1/P3.
- **77c · Standorte**: Unter-Team ↔ Betrieb, Team-Brille + Betriebs-Brille in einer Leiste, Lesen nach unten, Controlling konsolidiert.
- **77d · Inhalte**: Ausgabe-Freigaben, Sammlungen, Hülle, Schnellstart-Haken, eigene Kopie.

Reihenfolge-Vorschlag: 77a zuerst (Sicherheit, klein), dann 77c (Kernbaustein Standorte) und 77d, 77b zuletzt (Abrechnungs-/Paketfrage).

## Umsetzung 77a (2026-10-08)

- **Prüfstelle:** `FaRechte::pruefeAngemeldet(team, stufe, wofür)` prüft den angemeldeten Benutzer (Web; MCP setzt Core per `auth()->setUser`). Ohne Benutzer (Queue, Kommando) keine Prüfung.
- **Status-Setter ab Kuratieren:** `OrderService::setStatus` (damit auch Runde/ausgewählte senden und stornieren), Rezept (zusätzlich **nur eigene Rezepte** — geerbte/Master-Rezepte nicht), GP (im Besitzer-Team), Konzept, Format, Angebot, Skizze, Planung, Produktionsauftrag, Lieferant. `updatePayment` bezahlt/strittig ab **Freigeben**.
- **Einstellungen nur FA-Admin (F3):** Settings-Trait `MitEinstellungsSperre` weist jede Schreibaktion von Nicht-Admins ab (auch bei abgeschalteter Bearbeitungssperre) und zeigt „Nur lesen"; zusätzlich in `TeamSettingsService::update`, `OutletSettingsService`, Etikett-Vorlagen und Druckern (betrifft auch `TeamSettingsPutTool` und Controlling-Kennzahlen-Schwellen). **Operativ bleiben ab Kuratieren:** Lagerartikel/Mindestbestand, Bestellvorlagen, Stellplätze.
- **MCP-Grundprüfung:** jedes FA-Tool wird beim Registrieren in `FaRechteToolHuelle` gelegt — `read_only = false` braucht Kuratieren. Ausnahme `outlets.SET_ACTIVE` (nur Ansicht). KI-Vorschlags-Tools ohne Speichern (`presentation_designs.GENERATE_CSS`, `planung_leitplanken.EXTRACT`) bleiben für Betrachter gesperrt (verbrauchen KI-Budget).
- **Leitstellen-Agenten** (KI-Benutzer) melden sich per Bot-Token an und sind über die Team-Einstellungen Mitglied → Kuratieren → dürfen schreiben, nie freigeben. Ein KI-Benutzer **ohne** Team-Mitgliedschaft ist gesperrt (gewollt).
- **Ein Admin-Begriff:** `MitBearbeitungssperre::istTeamAdmin` liest `FaRechte`.
- **Test-Helfer:** `makeUser($team, $name, $rolle = 'owner')` legt die Mitgliedschaft mit an (null = kein Mitglied).
- **Betriebshinweis Wandmonitor:** Das Küchenkonto am Wandmonitor/Tagesplan braucht **mindestens Mitglied** (Kuratieren) — als Betrachter gehen Abhaken und Fertigmelden nicht.
- **Für 77b vorgemerkt:** Bestellrunde `generateDraftsFromSources`, `deleteRound`, Lagerartikel-Nachfüllung und die übrigen Schreibwege der Oberfläche (z. B. Bestell-Entwurf bearbeiten) ab Kuratieren — konsistent mit `sendRound`.

## Umsetzung 77b (2026-10-08)

- **Katalog** `Support\FaBereiche`: 15 Bereiche; Zuordnung Route (Gruppe) / MCP-Tool (Ressourcen-Präfix) / Livewire (Namespace) → Bereich, Ausnahmen ausdrücklich frei (öffentliche Präsentations-Links, Plattform-Assets, Sprachbefehl, Designsystem, `outlets.SET_ACTIVE/GET`, `ui`, `runs`, Rechte-Pflege). **Wächter-Test:** jedes registrierte FA-Tool und jede FA-Route muss zugeordnet sein.
- **Tabellen** (Migration `2026_10_09_100400`): `team_bereiche` (keine Zeile = an; Bestand verliert nichts), `user_bereich_sperren`, `team_kontingente` (am Haupt-Team).
- **`FaRechte`:** `bereichAktiv` (aus im Team oder einem Eltern-Team = aus), `darfBereich` (Plattform-Admin immer), `setzeTeamBereich`/`setzeKontingente` nur Plattform-Admin, `setzeUserSperre` FA-Admin (Inhaber/Admin nicht einschränkbar), `kontingentNutzung`, `pruefeKontingent`, `kiBudgetErschoepft` (€ aus `AiCostCalculator`, laufender Monat, Haupt-Team + Unter-Teams).
- **Durchsetzung:** Seiten per Middleware (403), Klicks per Livewire-`call`-Haken (Komponenten-Namespace), MCP in `FaRechteToolHuelle` (auch lesende Tools), Navigation gefiltert, KI-Gateway wirft `KiBudgetErschoepftException` (erbt vom Kill-Switch → bestehende Degradierung greift).
- **Pflege:** FA-Einstellungen → Zugriffsrechte (Bereiche lesend bzw. Plattform-Admin schaltet, Kontingente mit Nutzung, je Mitglied „einschränken"); Plattform `/verwaltung` Reiter Freigaben (Bereiche + Kontingente) und Kontingent-Prüfung beim Anlegen von Unter-Teams/Benutzern. MCP `team_bereiche.GET/PUT`.
- Tests: `Spec77bBereicheTest` (5).

## Umsetzung 77c (2026-10-08)

- **Tabellen** (Migration `2026_10_09_100500`): `team_betriebe` (Unter-Team → Betrieb des Oberteams, eine Zeile je Unter-Team; keine Zeile = Standard, erbt komplett), `team_brillen` (Team-Brille je Benutzer und Team, überlebt die Session).
- **`StandortService`** ist die eine Stelle: `unterTeamIds`/`unterTeams`, `zugeordneterBetrieb`, `betriebZuordnen` (FA-Admin des Oberteams, nur eigene Unter-Teams, nur aktive Betriebe), `brille`/`setzeBrille` (eigen | alle | team), `leseTeamIds`, Lese-Scopes `leseBereich` (Listen/Auswertungen), `leseBereichOderSichtbar` (Listen, die heute `visibleToTeam` lesen — bei „eigen" unverändert), `lesbarMitUnterTeams` (Detail lesend), `jeStandort` (Controlling nebeneinander). Ohne angemeldeten Benutzer (Queue, Signale, Kommandos) gilt immer „eigen".
- **Bewusst nicht über `visibleToTeam`:** daran hängen Referenz- und Schreibprüfungen von 130 Models. Lesen nach unten läuft nur über die eigenen Lese-Scopes; Schreiben prüft weiter den Besitz (`isOwnedBy`, `eigene()`, `offen()`) — Belege eines Standorts sind im Oberteam **nur lesend** (Oberfläche: „nur lesend", keine Aktionen).
- **Betriebs-Brille:** `ActiveOutletContext::current/set` liefert im Unter-Team den zugeordneten Betrieb fest; die Leiste zeigt ihn ohne Auswahl. `TeamSettingsService::outletRow` akzeptiert Betriebe der Team-Kette (Einstellungszeile im Besitzer-Team des Betriebs).
- **Team-Brille angewendet in:** Bestellungen (Liste + Detail), Wareneingang (erwartet, Lieferscheine, Detail), Rechnungen (Liste, Detail), Triple-Match, Lager (Bestand mit Preis des besitzenden Teams, Bewegungen, Inventuren; fremde Inventur nur lesend), Produktion (Liste, Browser, Detail), Einkaufsjournal (`spend`, `spendProLieferant`, `gpEinkauf`), Wareneinsatz-Abweichung (Umsatz, Abgänge, Bestandswert).
- **Standort-Spalte** bei Brille „alle": Bestellungen, Wareneingang, Rechnungen, Lager (Bestand, Bewegungen, Inventuren), Produktion-Browser.
- **Controlling konsolidiert:** Brille „alle" = Summe; Abweichungs-Panel zeigt zusätzlich „Je Standort" mit dessen eigenen Einkaufspreisen und Zielwerten.
- **Pflege:** Einstellungen → Betriebe, Abschnitt „Standorte" (Betrieb je Unter-Team). Leiste Team-Brille in Navbar und Sidebar. MCP `standorte.GET`, `standorte.PUT` (Admin), `standorte.SET_BRILLE` (jede Rolle, nur Ansicht).
- Tests: `Spec77cStandorteTest` (6).

## Umsetzung 77d (2026-10-08)

- **Tabellen** (Migration `2026_10_09_100600`): `team_inhalte` (Schnellstart-Haken je Unter-Team, keine Zeile = übernimmt alles → Bestand verliert nichts), `sammlungen` + `sammlung_objekte` (Rezepte = Gerichte und Basisrezepte, Konzepte, Formate), `ausgabe_freigaben` (Sammlung, Foodbook, Speiseplan, Speisekarte → Unter-Team), `freigabe_objekte` (gespeicherte Hülle je Empfänger). Spalten `kopie_von_id` + `kopie_stand_at` an Rezepten, Konzepten, Formaten.
- **Eine Regel an zwei Stellen:** `Support\InhaltsFreigabe` hängt in `BelongsToTeamHierarchy::scopeVisibleToTeam` und `TeamScope::applyVisible`. Für Inhalts-Typen (Rezept, Konzept, Format, Paket, Foodbook, Speiseplan, Speisekarte) sieht ein Unter-Team ohne Haken von seinen Vorfahren nur die Hülle; eigene Inhalte, globaler Bestand und Teams unterhalb der eingeschränkten Stufe bleiben sichtbar. Damit greift es auch in `TeamScope::referenz()` — nicht Freigegebenes lässt sich nicht referenzieren.
- **Bewusst nicht eingeschränkt:** Grundprodukte, Lieferantenartikel, Vokabular — Stammdaten bleiben voll geerbt, sonst könnte ein Standort keine eigenen Rezepturen bauen (Abweichung von Spec 61 §12 Nr. 2, dort standen GP/LA in der Hülle).
- **Hülle:** Format → Konzepte → Pakete/Gerichte (+ eingebettete Konzepte) → Basisrezepte (alle Ebenen); Foodbook über Kapitel und Blöcke, Speiseplan über Einträge, Speisekarte über Sektionen und Positionen. Neu gerechnet bei Freigabe/Haken/Sammlung sofort, bei Änderung eines Slots, Blocks, Eintrags, einer Position oder Zutat über `InhaltsFreigabeHuelleJob` (eindeutig in der Warteschlange, nur wenn es überhaupt Freigaben gibt).
- **Rechte:** Haken + Freigaben nur FA-Admin des Oberteams; freigegeben werden nur **eigene** Ausgaben an **eigene** Unter-Teams (Kunden-IP-Regel); Sammlungen pflegen ab Kuratieren.
- **Eigene Kopie:** `kopieAnlegen` (Rezept, Konzept, Format inkl. Gerüst) ab Kuratieren, merkt Original + Stand; `originalGeaendert` meldet Änderungen. Im Rezept-Editor: Hinweis „gehört … nur lesbar" mit Knopf „Eigene Kopie", an der Kopie Hinweis bei geändertem Original.
- **Pflege:** Einstellungen → Betrieb & Küche → **Inhalte für Standorte** (Haken je Standort, Freigaben, Sammlungen befüllen per Suche). MCP `standort_inhalte.GET/PUT`, `sammlungen.PUT` (auch mehrere IDs auf einmal), `inhalte.KOPIE`.
- **Befüllen:** Mehrfachauswahl im Rezept-Browser („Zu Sammlung hinzufügen"), Suche in den Einstellungen (Rezepte, Konzepte, Formate), MCP mit mehreren IDs.
- **Offen (Folgearbeit):** dieselbe Mehrfachauswahl in Concepter-/Format-Browser und „Eigene Kopie" im Konzept-/Format-Editor (heute per MCP).
- Tests: `Spec77dInhalteTest` (5).

## Bewusst nicht

- **Team-Preis je Artikel** (gleicher Artikel, standortabhängige Konditionen) — selten; Einzelfall: eigener Artikel im Unter-Team + Team-Pin. Ansatzpunkt, falls je nötig: zentrale Preisermittlung (`activePriceSubquery`).
- Rolle je Bereich, Rolle je Standort, Kategorie-Freigabe von Rezepten.
- Bereiche innerhalb fremder Module (Planner, CRM) — nur ganz an/aus.
- Änderungen an Core/Organization (nur lesen).

## Entscheidungen (Dominique 08.10.)

- **F1 Kontingente: ja, gleich mit (77b).** Je Team begrenzbar: Anzahl Standorte (Unter-Teams), Anzahl User, KI-Budget. Gepflegt vom Plattform-Admin neben der Freischaltung; Überschreiten = klare Meldung beim Anlegen bzw. beim KI-Aufruf (kein stilles Abschneiden). KI-Budget misst über das vorhandene KI-Aufruf-Protokoll (Prüfpunkt P3: Einheit € oder Aufrufe).
- **F2 Bereiche freischalten: nur Plattform-Admin** (= Abrechnung). Der Team-Admin schränkt innerhalb der Buchung nur ein (User, Unter-Teams).
- **F3 Einstellungen: alle nur FA-Admin** (Inhaber/Admin), auch fachliche (Einheiten, Warengruppen, Kalkulationswerte). Mitglieder sehen Einstellungen lesend. In 77a umgesetzt; `TeamSettingsPutTool` und alle Settings-Komponenten.
- **F5 Team-Brille „alle Standorte": Listen gemischt mit Standort-Spalte und Standort-Filter**; Auswertungen summieren und stellen die Standorte nebeneinander.

- **F4 Bereichs-Katalog:** wie in Achse A; „Ausgabe" aufgeteilt in Foodbook, Speisekarte, Speiseplan und Angebote, je eigener Schalter.

## Prüfpunkte

- **P1** Respektiert Core `Module::hasAccess` eine User-Sperre (enabled = false), wenn das Team das Modul hat? Wenn nein: Einschränkung im FA-Filter.
- **P2** Welche Livewire-Listen und Services lesen heute mit `team_id = eigenes Team`, welche mit `visibleToTeam` — Liste für den neuen Lese-Scope nach unten.
- **P3** KI-Budget: in welcher Einheit (€-Kosten aus dem KI-Aufruf-Protokoll oder Anzahl Aufrufe) und je Zeitraum (Monat)?
