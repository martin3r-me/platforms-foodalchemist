{{-- Kompakte Pairing-Empfehlungen (read-only), rechts neben dem Geschmacks-Radar.
     Aus dem großen Pairing-Block hochgezogen (Kern-Anker / Passt dazu / Kontrast) — nur recipe-Typ.
     Erwartet $pairing (PairingService::panelRecipe). Tokens ($pill/$variantPill) aus dem einbindenden Partial (Ui::maps()). --}}
@php($pr = $pairing ?? [])
@php($istRecipe = ($pr['type'] ?? null) === 'recipe')
@php($anker = $istRecipe ? ($pr['anker'] ?? []) : [])
@php($vorschlaege = $istRecipe ? ($pr['vorschlaege'] ?? []) : [])
@php($signature = $istRecipe ? ($pr['signature'] ?? []) : [])
@php($nachbarn = $istRecipe ? ($pr['nachbarn'] ?? []) : [])
@php($kontrast = $istRecipe ? ($pr['kontrast'] ?? []) : [])
@if(count($anker) || count($vorschlaege) || count($signature) || count($nachbarn) || count($kontrast))
    <div class="space-y-3">
        @if(count($anker))
            <div>
                <h4 class="text-[11px] font-medium text-gray-600 mb-1.5">Kern-Anker</h4>
                <div class="flex flex-wrap gap-1">
                    @foreach($anker as $a)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ is_array($a) ? ($a['display_de'] ?: $a['slug']) : $a }}</span>@endforeach
                </div>
            </div>
        @endif
        @if(count($vorschlaege) || count($nachbarn))
            <div>
                <h4 class="text-[11px] font-medium text-gray-600 mb-1.5">Passt dazu</h4>
                <div class="flex flex-wrap gap-1">
                    @foreach($vorschlaege as $v)<span class="{{ $pill }} {{ $v['allrounder'] ? $variantPill['secondary'] : $variantPill['info'] }}" title="passt zu {{ $v['cover'] }}/{{ $v['dish_n'] }} Komponenten{{ $v['allrounder'] ? ' · Allrounder' : '' }}">{{ $v['slug'] }} <span class="opacity-60">{{ $v['cover'] }}/{{ $v['dish_n'] }}</span></span>@endforeach
                    @foreach($nachbarn as $n)<span class="{{ $pill }} {{ $variantPill['info'] }}">{{ $n }}</span>@endforeach
                </div>
            </div>
        @endif
        @if(count($signature))
            <div>
                <h4 class="text-[11px] font-medium text-gray-600 mb-1.5">Macht den Teller eigen</h4>
                <div class="flex flex-wrap gap-1">
                    @foreach($signature as $v)<span class="{{ $pill }} {{ $variantPill['info'] }}" title="passt zu {{ $v['cover'] }}/{{ $v['dish_n'] }} Komponenten, kein Allrounder">{{ $v['slug'] }} <span class="opacity-60">{{ $v['cover'] }}/{{ $v['dish_n'] }}</span></span>@endforeach
                </div>
            </div>
        @endif
        @if(count($kontrast))
            <div>
                <h4 class="text-[11px] font-medium text-gray-600 mb-1.5">Kontrast (Aroma-Gegenpol)</h4>
                <div class="flex flex-wrap gap-1">
                    @foreach($kontrast as $n)<span class="{{ $pill }}" style="background-color: rgba(6,182,212,0.14); color: #0891b2;">↔ {{ $n }}</span>@endforeach
                </div>
            </div>
        @endif
    </div>
@endif
