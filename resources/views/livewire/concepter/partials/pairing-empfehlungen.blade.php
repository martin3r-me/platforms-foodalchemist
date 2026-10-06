{{-- Kompakte Pairing-Empfehlungen (read-only), rechts neben dem Geschmacks-Radar — nur recipe-Typ.
     Spec 60 · P10: aus derselben Rechnung wie Netz und „Passt das zusammen?".
       Gericht      Aromenprofil · passt dazu (Basisrezepte) · deckt offenen Bedarf (Basisrezepte)
       Basisrezept  Aromenprofil · harmoniert (★★★) · Kontrast-Lieferanten · verwandte Basisrezepte
     Erwartet $pairing (PairingService::panelRecipe). Tokens ($pill/$variantPill) aus dem einbindenden Partial (Ui::maps()). --}}
@php($pr = $pairing ?? [])
@php($istRecipe = ($pr['type'] ?? null) === 'recipe')
@php($profil = $istRecipe ? ($pr['profil'] ?? []) : [])
@php($passt = $istRecipe ? ($pr['passt_dazu'] ?? []) : [])
@php($deckt = $istRecipe ? ($pr['deckt_bedarf'] ?? []) : [])
@php($partner = $istRecipe ? ($pr['partner'] ?? []) : [])
@php($kontrast = $istRecipe ? ($pr['kontrast'] ?? []) : [])
@php($verwandte = $istRecipe ? ($pr['verwandte'] ?? []) : [])
@if(count($profil) || count($passt) || count($deckt) || count($partner) || count($kontrast) || count($verwandte))
    <div class="space-y-3" data-pairing-empfehlungen>
        @if(count($profil))
            <div>
                <h4 class="text-[11px] font-medium text-gray-600 mb-1.5">Aromenprofil</h4>
                <div class="flex flex-wrap gap-1">
                    @foreach($profil as $a)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $a['name'] }} <span class="opacity-60">{{ (int) $a['anteil'] }} %</span></span>@endforeach
                </div>
            </div>
        @endif
        @if(count($passt))
            <div>
                <h4 class="text-[11px] font-medium text-gray-600 mb-1.5">Passt dazu</h4>
                <div class="flex flex-wrap gap-1">
                    @foreach($passt as $b)<span class="{{ $pill }} {{ $variantPill['info'] }}" title="harmoniert mit {{ $b['mit'] }}">{{ $b['name'] }}</span>@endforeach
                </div>
            </div>
        @endif
        @if(count($deckt))
            <div>
                <h4 class="text-[11px] font-medium text-gray-600 mb-1.5">Deckt offenen Bedarf</h4>
                <div class="flex flex-wrap gap-1">
                    @foreach($deckt as $b)<span class="{{ $pill }}" style="background-color: rgba(6,182,212,0.14); color: #0891b2;" title="deckt {{ $b['achse'] }}">{{ $b['name'] }} <span class="opacity-60">{{ $b['achse'] }}</span></span>@endforeach
                </div>
            </div>
        @endif
        @if(count($partner))
            <div>
                <h4 class="text-[11px] font-medium text-gray-600 mb-1.5">Harmoniert (★★★)</h4>
                <div class="flex flex-wrap gap-1">
                    @foreach($partner as $n)<span class="{{ $pill }} {{ $variantPill['info'] }}">{{ $n }}</span>@endforeach
                </div>
            </div>
        @endif
        @if(count($kontrast))
            <div>
                <h4 class="text-[11px] font-medium text-gray-600 mb-1.5">Kontrast (deckt offenen Bedarf)</h4>
                <div class="flex flex-wrap gap-1">
                    @foreach($kontrast as $c)<span class="{{ $pill }}" style="background-color: rgba(6,182,212,0.14); color: #0891b2;">{{ $c['name'] }} <span class="opacity-60">{{ $c['achse'] }}</span></span>@endforeach
                </div>
            </div>
        @endif
        @if(count($verwandte))
            <div>
                <h4 class="text-[11px] font-medium text-gray-600 mb-1.5">Verwandte Basisrezepte</h4>
                <div class="flex flex-wrap gap-1">
                    @foreach($verwandte as $r)
                        <span class="{{ $pill }} {{ $variantPill['secondary'] }}" title="{{ $r['shared'] }} gemeinsame Kern-Anker{{ count($r['shared_slugs'] ?? []) ? ': ' . implode(', ', $r['shared_slugs']) : '' }}">{{ $r['name'] }} <span class="opacity-60">{{ $r['shared'] }}</span></span>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
@endif
