{{-- Eigenständige Hülle: Hauptnavigation (Sidebar). Aus dem Layout herausgelöst, damit sie ohne den Rest der Hülle (Terminal, Meldungen) renderbar und testbar ist.
     Nur Einträge, deren Bereich für Team und User freigeschaltet ist (Spec 77b); Master-only-Bereiche (Projekte, Spec 82 §9) nur im Master-Team — dieselbe
     Quelle und Regel wie die Plattform-Sidebar (livewire/sidebar.blade.php). --}}
  @php
    $user = auth()->user();
    // Spec 77b / Spec 82 §9: nur Einträge, deren Bereich für Team und User freigeschaltet ist; Master-only-Bereiche (Projekte) nur im Master-Team —
    // dieselbe Quelle und Regel wie die Plattform-Sidebar (livewire/sidebar.blade.php).
    $gruppen = \Platform\FoodAlchemist\Support\FaBereiche::sichtbareNavigation(config('foodalchemist.sidebar', []), $user);
    $initialen = $user ? collect(preg_split('/\s+/', trim($user->name ?? '')))->filter()->map(fn ($t) => mb_substr($t, 0, 1))->take(2)->implode('') : '';
  @endphp
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
