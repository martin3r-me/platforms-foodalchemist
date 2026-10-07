# 64 · CRM-Anbindung: Kunden und Lieferanten

**Stand 2026-10-07 · Status: §1 gebaut (alle 4 Ausgaben + Kunden-DNA), §1b gebaut, §2–§4 Entwurf zur Freigabe**

> Bezug: [33 · Portfolio-Steuerung](33_Portfolio_Steuerung.md) P2 (Zuordnungsachsen), [63 · Bestellversand](63_Bestellversand_per_Mail.md),
> [61 · Rollen und Rechte](61_Rollen_und_Rechte.md). Kein Eingriff in CRM oder Core.

## Ist (gemessen 2026-10-06)

- **Kunden an Ausgaben** (Foodbook, Speisekarte, Speiseplan, Angebot): `crm_company_id` + `crm_contact_id`,
  Picker „Kunde (CRM)“ (`components/crm-kunde-picker`), MCP `*_CustomerLinkTool`. Freitext-Kunde seit 2026-08-04 entfernt.
- **Lieferanten:** keine CRM-Verbindung. Ansprechpartner in eigener Tabelle `foodalchemist_supplier_contacts`,
  nur anlegen (kein Bearbeiten/Löschen), bei globalen Lieferanten gar nicht.
- **CRM bietet:** Morph-Links `crm_company_links`/`crm_contact_links` (+ Traits), Core-Verträge
  `CrmCompanyOptionsProviderInterface` (root-team-gefiltert), `CrmCompanyContactsProviderInterface` u. a.

## §1 · Mandantensicherheit (GEBAUT)

**Befund:** Die Ausgabe-Services suchten über `CompanyLinkService::searchCompanies()` / `ContactLinkService::searchContacts()`
— **ohne Team-Filter**. Auf einer Plattform mit mehreren Kunden hätte ein Team die CRM-Firmen anderer Teams gesehen.
`verknuepfeKunde` prüfte die ID nicht (Livewire-Direktaufruf / MCP konnte fremde Firmen anhängen).

**Fix:** `Support/CrmKunden` — Suche und Prüfung auf das **Haupt-Team** (CRM ist root-scoped), nur aktive Firmen/Kontakte.
Eingebaut in Foodbook-, Speisekarte-, Angebot-Service (`sucheFirmen`, `sucheKontakte`, `verknuepfeKunde`).
Test `CrmKundenMandantTest` (3 grün) + Zuordnungs-/MCP-/Portfolio-Tests grün.

**Erledigt (2026-10-06, Zusammenführungs-Branch):** auch `SpeiseplanService` umgestellt — alle vier Ausgaben mandantensicher.

**Nachtrag 2026-10-07 · Kunden-DNA:** `Settings\KundeDna::firmaWaehlen` übernahm die Firmen-ID ungeprüft
(Livewire-Direktaufruf legte den `kunde_dna`-Canvas für eine fremde Firma an), den Namen sogar vom Client.
Jetzt `CrmKunden::firma()` (Haupt-Team), Name aus der DB. Tests in `SettingsKundeDnaTest`.

## §1b · Kunden-DNA aus dem CRM öffnen (GEBAUT 2026-10-07)

**Entscheidung Dominique (2026-10-07), Variante A:** Die Kunden-DNA bleibt im Food Alchemist — sie ist Ebene
`kunde_dna` der KI-Kaskade (`CanvasService::cascadeKontext`) mit festen Feldern. Kein Umzug ins generische
Canvas-Modul (frei editierbare Bausteine würden die Felder brechen, die die KI liest; demo hat das Modul nicht).

- **FA:** Einstellungen → Kunden-DNA nimmt `?firma=<crm_company_id>` und öffnet die Firma direkt
  (mandantensicher wie oben); Rücklink „Im CRM öffnen“, wenn die CRM-Route existiert.
- **Host (Plattform food-alchemist.de):** Knopf „Kunden-DNA“ (Status leer/gepflegt) auf der CRM-Firmenseite,
  per View-Override der Kopfzeile — **keine Änderung am CRM-Modul**.

## §2 · Lieferant ↔ CRM-Firma (Entwurf)

- Lieferanten können plattformweit sein (Hanos, Chefs Culinar), **Ansprechpartner sind je Betrieb verschieden**
  → Ansprechpartner gehören ins CRM des jeweiligen Teams.
- Neue Tabelle `foodalchemist_supplier_crm_links` (team_id, supplier_id, crm_company_id; unique team+supplier):
  jedes Team verknüpft „seinen“ Lieferanten mit seiner CRM-Firma. Picker im Lieferanten-Stammblatt (gleiche Mechanik wie §1).
- Bereich „Ansprechpartner“ zeigt die CRM-Kontakte dieser Firma (über `CrmCompanyContactsProviderInterface`);
  anlegen/bearbeiten im CRM, Link „Im CRM öffnen“.
- Migration: bestehende `foodalchemist_supplier_contacts` einmalig als CRM-Kontakte an die verknüpfte Firma überführen
  (je Team), alte Tabelle danach nur lesend, später entfernen.

## §3 · Empfänger der Bestell-Mail (Entscheidung Dominique 2026-10-06)

- **An:** immer die allgemeine Bestelladresse des Lieferanten (`email_order`).
- **Zusätzlich (CC), frei wählbar aus dem CRM:** z. B. der direkte Ansprechpartner beim Lieferanten (Kontakt der
  verknüpften CRM-Firma) und/oder eigene Kontakte (z. B. der Besteller selbst, Küchenchef).
- Umsetzung: an der Bestellung eine Liste `zusatz_empfaenger` (CRM-Kontakt-IDs, mandantengeprüft wie §1), vorbelegbar
  je Lieferant (Standard-Empfänger pro Team/Lieferant). Protokoll (Spec 63) speichert alle Empfänger.

## §4 · Kleine Lücken (Entwurf)

- Portfolio „Je Kunde“ bezieht **Angebote** nicht ein (PortfolioService ~218–258) → ergänzen.
- Reife-Adapter Foodbook prüft noch das alte Freitextfeld `customer` (immer leer) → auf `crm_company_id` umstellen.
- Formate (`origin=kunde`) und Kunden-Verkaufsnamen am Gericht (`recipe_customer_names`) führen den Kunden als Freitext
  → auf CRM-Firma umstellen (Freitext als Anzeige-Fallback).
- MCP im Lockstep: `foodalchemist.supplier_crm_links.GET/PUT`, Bestellungs-Zusatzempfänger in `orders.*`.
