# Angebots-Funnel-Anfang — Brief-Parser (R6.2)

> **ROADMAP-Bezug:** R6.2 (Track „Alleinstellung"), Größe L, hängt an R6.1 (Brief→Konzept, gebaut).
> **Idee:** Die Kunden-Anfrage (Mail/Formular) automatisch in ein strukturiertes Event-Brief übersetzen — der Einstiegs-Trichter, der direkt in die R6.1-Konzeptgenerierung mündet. FA liefert **zu**, die Angebots-Führung bleibt beim Event-Modul.

---

## 1. Der Flow

```
Kunden-Mail / Formular-Text
  → Brief-Parser (KI, strukturiert): Anlass · Gäste · Budget · Diät-Anforderungen · Termin
    — je Feld eine Konfidenz
  → unsichere Felder = Rückfrage-Liste (NICHT geraten)
  → ein Klick: Brief → R6.1 Konzept-Vorschlag
```

## 2. DoD

- [ ] Kunden-Anfrage (Mail-Text/Formular) → **strukturiertes Event-Brief** (Anlass, Gäste, Budget, Diät-Anforderungen, Termin) mit **Konfidenz je Feld**.
- [ ] Unsichere Felder als **Rückfrage-Liste**, nicht geraten (spiegelt die „keine Erfindungen"-Haltung auf der Intake-Seite).
- [ ] Brief mündet direkt in R6.1 (**ein Klick:** Brief → Konzept-Vorschlag).
- [ ] **Grenze eingehalten:** Angebots-FÜHRUNG bleibt Event-Modul — FA liefert Brief + Konzept zu (Zuarbeits-Schnittstelle dokumentiert).

## 3. Abhängigkeiten + Einordnung

- **Hängt an R6.1** (gebaut; Blindtest #492 offen) — der Brief-Parser füttert dessen `concept.brief_geruest`-Pfad. Sinnvoll scharf erst nach dem R6.1-Blindtest (sonst füttert man einen ungetesteten Generator).
- **Kein neues Grounding nötig** — nutzt AiGateway + Prompt-Registry; der Parser ist reine Struktur-Extraktion, die eigentliche Konzept-Logik bleibt in R6.1 deterministisch.
- **Verwandt mit `briefing_parser`-Skill (CJ)** — konzeptuelle Vorlage aus der Vault-Skill-Welt.

## 4. Bewusste Nicht-Ziele

- **Keine Angebots-Führung/CRM-Funnel in FA** — nur die Brief-Zuarbeit (Grenze zum Event-/Nachbar-Modul).
- **Kein Raten** — unsichere Felder werden gefragt, nicht gefüllt.
- Kein Auto-Versand/Preis-Commit — FA liefert den Konzept-Vorschlag als Draft, nichts geht raus.

*Verzahnt: R6.1 `ConceptGeneratorService` (Ziel des Briefs), [08_Planungs_und_Kreativ_Ebene.md](08_Planungs_und_Kreativ_Ebene.md) (der Brief kann auch die Foodbook-/Concept-Planungsebene speisen), N-Track (Event-Modul = Angebots-Führung). Erstellt 2026-07-18.*
