{{-- Spec 29: Menü-Vorschau (Kundensicht, read-only) — das LIVE-Ergebnis des Foodbooks.
     Quelle = dieselbe aufgelöste Wording-Kette wie Druck-Dokument/Präsentation
     ($menue = FoodbookService::dokumentDaten). Herausgelöst aus dem alten `vorschau`-Tab,
     damit die Listen-Seite das fertige Ergebnis dauerhaft zeigt (Ansehen ≠ Bearbeiten).
     Erwartet im Scope: $fb, $menue, $feedbackAgg, $card, $cardAccent. --}}
<div class="relative overflow-hidden {{ $card }} p-6 space-y-5" data-fb-menue-vorschau>
    <div class="{{ $cardAccent }}"></div>
    <div class="flex items-baseline justify-between border-b border-black/5 pb-3">
        <div>
            <h2 class="text-lg font-semibold tracking-tight text-gray-900">{{ $fb->label }}</h2>
            @if($menue['customer'] ?? null)<p class="text-xs text-gray-500">{{ $menue['customer'] }}@if(($menue['kontakt'] ?? null) && $menue['kontakt'] !== $menue['customer']) · {{ $menue['kontakt'] }}@endif</p>@endif
        </div>
        @if(($menue['gesamt']['vk_pro_person'] ?? 0) > 0)<span class="text-sm font-semibold text-emerald-600 tabular-nums">{{ number_format((float) $menue['gesamt']['vk_pro_person'], 2, ',', '.') }} €/P</span>@endif
    </div>
    @forelse($menue['kapitel'] ?? [] as $k)
        <section style="margin-left: {{ $k['depth'] * 16 }}px">
            <div class="flex items-baseline gap-2 border-b border-black/5 pb-1 mb-2">
                <h3 class="text-sm font-semibold text-violet-700">{{ $k['title'] }}</h3>
                @if($k['vk_pro_person'] > 0)<span class="ml-auto text-[11px] text-gray-500 tabular-nums">{{ number_format((float) $k['vk_pro_person'], 2, ',', '.') }} €/P</span>@endif
            </div>
            @forelse($k['bloecke'] as $b)
                @php($istKonzept = in_array($b['type'], ['concept_ref', 'recipe_ref'], true))
                <div class="py-0.5">
                    <p class="text-sm {{ $b['ist_header'] ? 'font-semibold text-gray-700 mt-2' : ($istKonzept ? 'font-semibold text-gray-900 mt-2' : 'text-gray-900') }}">{{ $b['label'] }}</p>
                    @if($b['untertitel'] ?? null)<p class="text-[11px] text-gray-500 italic">{{ $b['untertitel'] }}</p>@endif
                    @foreach($b['gerichte'] ?? [] as $g)
                        @if($g['type'] === 'paket' || $g['type'] === 'header')
                            <p class="text-xs font-semibold text-gray-600 ml-3 mt-1">{{ $g['text'] }}</p>
                        @else
                            @php($gfb = ($g['recipe_id'] ?? null) ? ($feedbackAgg[$g['recipe_id']] ?? null) : null)
                            <p class="text-xs text-gray-600 {{ $g['source'] === 'name' ? 'italic text-amber-600' : '' }}" style="margin-left:{{ 12 + $g['einrueckung'] * 12 }}px">{{ $g['text'] }}@if($g['source'] === 'name')<span class="ml-1 text-[10px]">· Wording fehlt</span>@endif@if($gfb && $gfb['count'] > 0)<span class="ml-1.5 text-[10px] {{ ($gfb['avg'] ?? 0) >= 4 ? 'text-emerald-600' : (($gfb['avg'] ?? 0) >= 3 ? 'text-amber-600' : 'text-red-500') }}" title="{{ $gfb['count'] }} Feedback-Einträge">★ {{ $gfb['avg'] !== null ? number_format((float) $gfb['avg'], 1, ',', '.') : '–' }}</span>@endif</p>
                        @endif
                    @endforeach
                </div>
            @empty
                <p class="text-xs text-gray-500">—</p>
            @endforelse
        </section>
    @empty
        <p class="text-xs text-gray-500 py-6 text-center">Noch keine Kapitel — im Editor anlegen und Concepts einfügen.</p>
    @endforelse
    <p class="text-[11px] text-gray-500 pt-2 border-t border-black/5">Gericht-Namen aus der Wording-Kette: Foodbook-Override → Konzept-Wording → VK-Standard → interner Name. Amber = kein Wording gepflegt.</p>
</div>{{-- /Menü-Vorschau-Karte --}}
