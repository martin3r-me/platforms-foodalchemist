# Platform Food Alchemist

Dieses Modul dient als **Template und Startpunkt** für neue Module in der Platform.

## 📋 Übersicht

Dieses Template zeigt die **minimale Struktur** eines Platform-Moduls:
- ✅ Service Provider mit Modul-Registrierung
- ✅ Config-Datei mit Navigation und Sidebar
- ✅ Routes (Dashboard + Test-Seite)
- ✅ Livewire Components (Dashboard, Sidebar, Test)
- ✅ Views mit beiden Sidebars (links & rechts)
- ✅ Vollständige Dokumentation für LLMs

## 🚀 Schnellstart

### 1. Modul kopieren und umbenennen

```bash
# Kopiere das Template-Modul
cp -r platform/modules/foodalchemist platform/modules/dein-modul-name

# Gehe in das neue Modul
cd platform/modules/dein-modul-name
```

### 2. Dateien umbenennen und anpassen

**WICHTIG:** Ersetze in ALLEN Dateien:
- `FoodAlchemist` → `DeinModulName` (Namespace)
- `foodalchemist` → `dein-modul-name` (Verzeichnisname, Route-Prefix)
- `module_template` → `dein_modul_name` (Config-Key, Tabellennamen)

**Dateien die angepasst werden müssen:**
- `composer.json` - Name, Namespace, Provider
- `config/foodalchemist.php` → `config/dein-modul-name.php`
- `src/FoodAlchemistServiceProvider.php` → `src/DeinModulNameServiceProvider.php`
- Alle PHP-Dateien: Namespace ändern
- Alle Blade-Dateien: `foodalchemist::` → `dein-modul-name::`
- Routes: `foodalchemist` → `dein-modul-name`

### 3. Composer registrieren

Füge das Modul zur Hauptanwendung hinzu:

**In `composer.json` der Hauptanwendung:**
```json
{
  "require": {
    "martin3r/platform-dein-modul-name": "dev-main"
  },
  "repositories": [
    {
      "type": "path",
      "url": "../platform/modules/dein-modul-name"
    }
  ]
}
```

Dann:
```bash
composer update
```

### 4. Config publizieren (optional)

```bash
php artisan vendor:publish --tag=config --provider="Platform\DeinModulName\DeinModulNameServiceProvider"
```

## 📁 Struktur

```
foodalchemist/
├── composer.json              # Package-Definition
├── config/
│   └── foodalchemist.php    # Modul-Konfiguration
├── database/
│   └── migrations/            # Migrationen (optional)
├── resources/
│   └── views/
│       └── livewire/
│           ├── dashboard.blade.php    # Dashboard-View
│           ├── test.blade.php         # Test-Seite
│           └── sidebar.blade.php      # Sidebar-View
├── routes/
│   └── web.php                # Web-Routes
├── src/
│   ├── FoodAlchemistServiceProvider.php  # Service Provider
│   └── Livewire/
│       ├── Dashboard.php       # Dashboard Component
│       ├── Test.php           # Test Component
│       └── Sidebar.php        # Sidebar Component
└── README.md                   # Diese Datei
```

## 🔧 Wichtige Komponenten

### Service Provider

Der `FoodAlchemistServiceProvider` ist das Herzstück des Moduls:

1. **register()**: Config wird hier geladen (Laravel Best Practice)
2. **boot()**: 
   - Modul wird bei PlatformCore registriert
   - Routes werden geladen (nur wenn Modul aktiv)
   - Views und Livewire-Komponenten werden registriert

### Config-Datei

Die Config (`config/foodalchemist.php`) definiert:
- **routing**: Route-Modus (path/subdomain) und Prefix
- **navigation**: Hauptnavigation (Icon, Route, Order)
- **sidebar**: Sidebar-Struktur für das Modul

### Routes

- `/foodalchemist` → Dashboard
- `/foodalchemist/test` → Test-Seite

### Livewire Components

- **Dashboard**: Hauptübersicht
- **Test**: Test-Seite für Entwicklung
- **Sidebar**: Modul-spezifische Sidebar

## 📝 Anpassungen für dein Modul

### 1. Models hinzufügen

Erstelle Models in `src/Models/`:
```php
<?php
namespace Platform\DeinModulName\Models;

use Illuminate\Database\Eloquent\Model;
use Platform\ActivityLog\Traits\LogsActivity;

class DeinModulNameEntity extends Model
{
    use LogsActivity;
    
    protected $table = 'dein_modul_name_entities';
    // ...
}
```

### 2. Migrationen erstellen

```bash
php artisan make:migration create_dein_modul_name_entities_table
```

### 3. Livewire Components erweitern

Füge neue Components in `src/Livewire/` hinzu:
- Index-Views für Listen
- Create/Edit Modals
- Show-Views für Details

### 4. Routes erweitern

In `routes/web.php`:
```php
Route::get('/entities', Entity\Index::class)->name('dein-modul-name.entities.index');
```

## 🎯 Best Practices

1. **Immer Team-basiert**: Nutze `$user->currentTeam->id` für Team-Filterung
2. **Activity Logging**: Nutze `LogsActivity` Trait für Models
3. **UUIDs**: Verwende UUIDs für alle Models (UuidV7)
4. **Policies**: Erstelle Policies für Authorization
5. **Sidebars**: Beide Sidebars (links & rechts) in allen Views
6. **Modals**: Immer innerhalb von `<x-ui-page>` platzieren

## 🤖 Für LLMs

Dieses Template ist so strukturiert, dass LLMs es verstehen können:

- **Klare Namenskonventionen**: Alles folgt dem Muster `{modul-name}`
- **Ausführliche Kommentare**: Alle wichtigen Stellen sind dokumentiert
- **Konsistente Struktur**: Gleiche Struktur wie andere Module (HCM, Planner)
- **Beispiele**: Dashboard und Test-Seite zeigen alle Patterns

**Wichtige Patterns:**
- Service Provider Pattern (wie in HCM/Planner)
- Livewire Component Pattern
- Route Registration Pattern
- Sidebar Pattern (links & rechts)

## 📚 Weitere Ressourcen

- Siehe `platform/modules/hcm` für komplexere Beispiele
- Siehe `platform/modules/planner` für Modals und erweiterte Features
- Siehe `platform/core/src/PlatformCore.php` für Modul-Registrierung

## ✅ Checkliste für neues Modul

- [ ] Modul kopiert und umbenannt
- [ ] Alle Namespaces angepasst
- [ ] Composer.json angepasst
- [ ] Config-Datei angepasst
- [ ] Routes angepasst
- [ ] Service Provider angepasst
- [ ] Views angepasst
- [ ] In Hauptanwendung registriert
- [ ] `composer dump-autoload` ausgeführt
- [ ] Config publiziert (optional)
- [ ] Getestet

## 🐛 Troubleshooting

**Routen funktionieren nicht:**
- Config publiziert? → `php artisan vendor:publish --tag=config`
- Config-Cache geleert? → `php artisan config:clear`
- Route-Cache geleert? → `php artisan route:clear`

**Modul erscheint nicht in Navigation:**
- Modul in Datenbank registriert? → Prüfe `modules` Tabelle
- Config korrekt? → Prüfe `config/dein-modul-name.php`

**Livewire Components nicht gefunden:**
- Service Provider registriert? → Prüfe `composer.json`
- `composer dump-autoload` ausgeführt?
