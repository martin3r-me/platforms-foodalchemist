# 63 · Bestellversand per Mail

**Stand 2026-10-06 · Status: gebaut in fa-pass (uncommittet), Tests grün (7 neu + 93 Bestell-Tests)**

## Ziel

Bestellungen und Stornos gehen direkt aus dem Food Alchemist an den Lieferanten — mit Bestell-PDF, Protokoll
und Vorlage. Bisher: nur `mailto:` (eigenes Mailprogramm), Status „versendet“ von Hand.

## Verhalten

| Versandart (Einstellungen → Einkauf & Lieferanten → Bestellversand) | Absenden | Storno einer versendeten/bestätigten Bestellung |
|---|---|---|
| **Mailprogramm** (Standard, bisheriges Verhalten — demo/office unverändert) | Status „versendet“; mailto-Link im Menü | Storno-mailto im Menü |
| **Direkt per E-Mail** | Bestätigung → Mail mit PDF an `email_order` des Lieferanten, Antwort-an = Absender, BCC = Team-Kopie; Protokoll an der Bestellung | Storno-Mail automatisch |

- Fehlt beim Lieferanten eine gültige Bestell-E-Mail, wird Absenden verweigert (Status bleibt Entwurf).
- Versand in der Warteschlange (Job, 3 Versuche); Fehler im Protokoll sichtbar, Knopf „Erneut senden“.
- Greift zentral in `OrderService::setStatus` → gilt für Editor, Bestellrunden und MCP gleich.
- Mailer = der der Host-App (Laravel Mail; demo/office: Postmark). Kein eigener Mail-Dienst im Modul.

## Vorlagen

Betreff + Text für Bestellung und Storno, Platzhalter `{lieferant} {referenz} {positionen} {liefertermin} {summe}
{team} {besteller}`; leer = Standard-Wortlaut (identisch zum mailto-Weg). Knopf „Standardtext einsetzen“.
Signatur wird angehängt.

## Bausteine

- Migration `2026_10_06_100000_add_bestellversand_per_mail` (Team-Settings-Spalten + `foodalchemist_order_mails`)
- `Services/OrderMailService` (planen, senden, erneutSenden, inhalt/Vorlagen, Storno-Text ohne Status-Sperre)
- `Mail/BestellungMail`, `Jobs/BestellMailSendenJob`, `Models/FoodAlchemistOrderMail`
- UI: `Settings/Einkauf` (Abschnitt Bestellversand + Vorlagen), `Orders/Editor` (Protokoll, Beschriftungen)
- Tests: `tests/Feature/BestellversandMailTest.php`

## Offen

- MCP im Lockstep: `foodalchemist.order_mails.GET` (Protokoll lesen) — noch nicht gebaut.
- Rechte (Spec 61): „Absenden“ = Rolle *Freigeben*.
- Server: Mail-Dienst + SPF/DKIM der Absender-Domain (Termin Martin).
