{{--
    Food Alchemist · eigenständige Hülle (Design „Labor und Tageslicht", 2026-10-05)

    Ersetzt platform::layouts.app: keine Plattform-Modulleiste, keine Fremd-Modals.
    Navigation kommt aus EINER Quelle: config('foodalchemist.sidebar').
    Login/Teams laufen vorerst weiter über platform-core (Stufe 1 der Herauslösung).
--}}
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <x-foodalchemist::handy.kopf />
  <title>{{ isset($title) ? $title.' · ' : '' }}Food Alchemist</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500&display=swap">

  <x-foodalchemist::shell.fa-menu-script />
  <x-ui-styles />
  @vite(['resources/css/app.css', 'resources/js/app.js'])
  @livewireStyles
  <script src="https://unpkg.com/@wotz/livewire-sortablejs@1.0.0/dist/livewire-sortable.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
</head>

<body class="bg-[var(--fa-ground)] text-[var(--fa-ink)] overflow-hidden">
  @auth
  @php
    $uiState = \Platform\Core\Models\UserUiPreference::where('user_id', auth()->id())->value('state') ?? new \stdClass();
  @endphp
  <script>
    window.__UI_PREFS__ = @json($uiState);
    window.__UI_PREFS_URL__ = @json(route('platform.ui-preferences.update'));
  </script>
  <script>
    // Seitenrahmen (x-ui-page-sidebar) lesen/schreiben ihre Breite über diesen Store.
    document.addEventListener('alpine:init', () => {
      const DEFAULTS = { page_sidebar: { open: true, width: 320 }, activity: { open: false, width: 320 } };
      Alpine.store('ui', {
        state: (window.__UI_PREFS__ && typeof window.__UI_PREFS__ === 'object') ? window.__UI_PREFS__ : {},
        module: 'foodalchemist',
        _t: null,
        g(s, f) { return this.state?.[s]?.[f] ?? DEFAULTS[s]?.[f]; },
        gSet(s, f, v) { (this.state[s] ??= {})[f] = v; this._sync(); },
        m(s, f) { return this.state?.modules?.foodalchemist?.[s]?.[f] ?? DEFAULTS[s]?.[f]; },
        mSet(s, f, v) { ((((this.state.modules ??= {}).foodalchemist ??= {})[s] ??= {}))[f] = v; this._sync(); },
        mToggle(s, f) { this.mSet(s, f, !this.m(s, f)); },
        _sync() {
          clearTimeout(this._t);
          this._t = setTimeout(() => {
            fetch(window.__UI_PREFS_URL__, {
              method: 'PUT', credentials: 'same-origin',
              headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'X-Requested-With': 'XMLHttpRequest' },
              body: JSON.stringify({ state: this.state }),
            }).catch(() => {});
          }, 300);
        },
      });
    });
  </script>
  @endauth


  <div class="flex h-screen w-full" data-fa-huelle>
    @include('foodalchemist::layouts.partials.standalone-nav')

    <main class="flex-1 min-w-0 h-screen flex flex-col overflow-hidden bg-[var(--fa-ground)]">
      <x-foodalchemist::saved-toast />
      {{-- Terminal (Chat · Agenda · Feature …) — vorerst aus platform-core; bei der Ablösung (Spur 2)
           wird es ein eigener Teil des Food.Alchemist. Steht im DOM vor dem Inhalt, wird per order-first
           aber unter ihm angezeigt (wie im Plattform-Layout). --}}
      {{-- Dunkler Rahmen (Dominique 2026-10-05): Navigation, Chat-Leiste und Detail-Panels dunkel, Arbeitsfläche hell.
           display:contents — kein eigener Kasten, der Terminal-Root bleibt Flex-Kind von <main>; die Tokens erbt er trotzdem. --}}
      @auth
        <div class="contents" data-fa-theme="dark" data-fa-nur-desktop>
          @livewire('core.terminal')
        </div>
      @endauth
      <div class="flex-1 min-h-0 overflow-y-auto order-first">
        {{ $slot }}
      </div>
    </main>
  </div>

  {{-- #1003 Untere Leiste der Handy-Oberfläche (am Computer unsichtbar) --}}
  @auth
    <x-foodalchemist::handy.nav />
  @endauth

  <livewire:notifications.notices.index />
  @livewireScripts
</body>
</html>
