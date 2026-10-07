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
  <title>{{ isset($title) ? $title.' · ' : '' }}Food Alchemist</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500&display=swap">

  <script>
    // faMenu — gemeinsamer Aufklapp-Helfer für Menüs (Status-Chips, „Weitere Aktionen" …).
    // Das Menü steht FEST am Bildschirm (nicht im scrollenden Tabellenkasten → wird nicht abgeschnitten),
    // rechtsbündig am Auslöser, klappt nach oben, wenn unten kein Platz ist, und schließt beim Scrollen.
    // Nutzung: x-data="faMenu()" · Auslöser x-on:click="toggle($event)" · Menü class="hidden" x-bind:class="{ hidden: ! offen }" x-bind:style="pos"
    // (Menü ist von Haus aus versteckt → fehlt der Helfer einmal, bleibt es zu statt die Tabelle aufzublähen.)
    document.addEventListener('alpine:init', () => {
      Alpine.data('faMenu', () => ({
        offen: false,
        pos: '',
        toggle(e) {
          if (this.offen) { this.offen = false; return; }
          const r = e.currentTarget.getBoundingClientRect();
          const oben = r.bottom + 280 > window.innerHeight && r.top > 280;
          this.pos = 'position:fixed;z-index:1000;left:' + r.right + 'px;top:' + (oben ? r.top - 4 : r.bottom + 4) + 'px;transform:translate(-100%,' + (oben ? '-100%' : '0') + ')';
          this.offen = true;
        },
        init() {
          const zu = () => { this.offen = false; };
          window.addEventListener('scroll', zu, true);
          window.addEventListener('resize', zu);
        },
      }));
    });
  </script>
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

  @php
    $gruppen = config('foodalchemist.sidebar', []);
    $user = auth()->user();
    $initialen = $user ? collect(preg_split('/\s+/', trim($user->name ?? '')))->filter()->map(fn ($t) => mb_substr($t, 0, 1))->take(2)->implode('') : '';
  @endphp

  <div class="flex h-screen w-full">
    <nav aria-label="Hauptnavigation"
         class="hidden md:flex w-[232px] shrink-0 flex-col bg-[var(--fa-rail)] text-[var(--fa-rail-ink)]">
      <a href="{{ route('foodalchemist.dashboard') }}" wire:navigate
         class="flex items-center gap-2.5 px-5 h-14 shrink-0 border-b border-[var(--fa-rail-line)]">
        <img src="{{ asset('brand/fa-mark-on-dark.png') }}" alt="" width="28" height="28" class="w-7 h-7">
        <span class="text-[15px] font-semibold tracking-tight text-[#eef2f8]">Food<span class="text-[#a9c2ff]">.</span><span class="text-[#a9c2ff]">Alchemist</span></span>
      </a>

      <div class="flex-1 min-h-0 overflow-y-auto px-3 py-4 flex flex-col gap-5">
        @foreach($gruppen as $gruppe)
          <div class="flex flex-col gap-0.5">
            <div class="px-2.5 pb-1 text-[12px] font-medium text-[var(--fa-rail-muted)]">{{ str_replace('&', 'und', $gruppe['group'] ?? '') }}</div>
            @foreach($gruppe['items'] ?? [] as $item)
              @php($aktiv = request()->routeIs($item['route']) || request()->routeIs($item['route'].'.*') || request()->routeIs(\Illuminate\Support\Str::beforeLast($item['route'], '.index').'.*'))
              <a href="{{ route($item['route']) }}" wire:navigate class="fa-rail-link" @if($aktiv) aria-current="page" @endif>
                @svg($item['icon'] ?? 'heroicon-o-cube', 'w-[18px] h-[18px] shrink-0')
                <span class="truncate">{{ $item['label'] }}</span>
              </a>
            @endforeach
          </div>
        @endforeach
      </div>

      @if($user)
        <div class="shrink-0 flex items-center gap-2.5 px-5 py-3 border-t border-[var(--fa-rail-line)]">
          <span class="flex items-center justify-center w-7 h-7 rounded-full bg-[var(--fa-rail-active)] text-[var(--fa-rail-active-ink)] text-[11px] font-semibold">{{ $initialen }}</span>
          <div class="min-w-0">
            <div class="text-[13px] text-[#eef2f8] truncate">{{ $user->name }}</div>
            <div class="text-[12px] text-[var(--fa-rail-muted)] truncate">{{ $user->currentTeam?->name }}</div>
          </div>
        </div>
      @endif
    </nav>

    <main class="flex-1 min-w-0 h-screen flex flex-col overflow-hidden bg-[var(--fa-ground)]">
      <x-foodalchemist::saved-toast />
      {{-- Terminal (Chat · Agenda · Feature …) — vorerst aus platform-core; bei der Ablösung (Spur 2)
           wird es ein eigener Teil des Food.Alchemist. Steht im DOM vor dem Inhalt, wird per order-first
           aber unter ihm angezeigt (wie im Plattform-Layout). --}}
      {{-- Dunkler Rahmen (Dominique 2026-10-05): Navigation, Chat-Leiste und Detail-Panels dunkel, Arbeitsfläche hell.
           display:contents — kein eigener Kasten, der Terminal-Root bleibt Flex-Kind von <main>; die Tokens erbt er trotzdem. --}}
      @auth
        <div class="contents" data-fa-theme="dark">
          @livewire('core.terminal')
        </div>
      @endauth
      <div class="flex-1 min-h-0 overflow-y-auto order-first">
        {{ $slot }}
      </div>
    </main>
  </div>

  <livewire:notifications.notices.index />
  @livewireScripts
</body>
</html>
