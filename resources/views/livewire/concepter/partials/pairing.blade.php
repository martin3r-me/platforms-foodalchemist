{{-- Wiederverwendbares Pairing-Panel der Editoren: erwartet $pairing (PairingService::panelRecipe / panelGp). Read-only.
     Spec 60 · P10: dieselbe Aussage wie die Detail-Spalte — „Passt das zusammen?" (Kombinationslogik) + Netz.
     Die Empfehlungen (Aromenprofil, passt dazu, Kontrast …) stehen rechts vom Geschmacks-Radar
     (partials/pairing-empfehlungen). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

@if(! $pairing)
    <p class="text-xs text-gray-500 py-4">Noch keine Pairing-Daten.</p>
@elseif(($pairing['type'] ?? null) === 'recipe')
    @php($k = $pairing['kombination'] ?? null)
    @if($k === null || (($k['bestandteile'] ?? []) === [] && ($pairing['profil'] ?? []) === []))
        <p class="text-xs text-gray-500 py-4">Noch keine Pairing-Daten — keine Zutat ist einem Aroma zugeordnet.</p>
    @else
        <div class="relative overflow-hidden {{ $card }}" data-editor-kombination>
            <div class="{{ $cardAccent }}"></div>
            <div class="px-5 py-4 space-y-3">
                <h3 class="font-medium tracking-tight text-gray-900">Passt das zusammen?</h3>
                <x-foodalchemist::kombination :daten="$k" />
            </div>
        </div>
        @if(($pairing['netz']['nodes'] ?? []) !== [])
            <div class="relative overflow-hidden {{ $card }} mt-3" data-editor-netz>
                <div class="{{ $cardAccent }}"></div>
                <div class="px-5 py-4 space-y-2">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="font-medium tracking-tight text-gray-900">Pairing-Netz</h3>
                        <x-fa::button size="sm" variant="ghost" icon-right="heroicon-m-arrow-up-right" href="#" x-on:click.prevent=""
                            wire:click="$dispatch('pairing-netz.oeffnen', { recipeId: {{ (int) ($pairing['netz']['meta']['recipe_id'] ?? 0) }} })"
                            title="Ganzes Netz mit verwandten Rezepten und Vorschlägen öffnen" data-editor-netz-oeffnen>Netz öffnen</x-fa::button>
                    </div>
                    <p class="text-[11px] text-gray-500">
                        {{ ($pairing['ist_gericht'] ?? false) ? 'Die Basisrezepte des Gerichts und was dazu passt — die Anker liegen im Hintergrund.' : 'Die Kern-Anker des Aromenprofils und ihre ★★★-Partner.' }}
                    </p>
                    {{-- Die Vorschau ist für die schmale Detail-Spalte gebaut — im breiten Editor begrenzen, sonst skaliert die Schrift mit. --}}
                    <div class="max-w-xl">
                        <x-foodalchemist::pairing-netz :recipe-id="$pairing['netz']['meta']['recipe_id'] ?? 0" :netz="$pairing['netz']" />
                    </div>
                </div>
            </div>
        @endif
    @endif
@elseif(($pairing['type'] ?? null) === 'gp')
    @if(count($pairing['anker']) === 0)
        <p class="text-xs text-gray-500 py-4">Noch keine Pairing-Daten (kein Aroma-Anker auf diesem GP).</p>
    @else
        <div class="relative overflow-hidden {{ $card }}">
            <div class="{{ $cardAccent }}"></div>
            <div class="px-5 py-4 space-y-2">
                <h3 class="font-medium tracking-tight text-gray-900">Aroma-Anker</h3>
                <div class="flex flex-wrap gap-1">
                    @foreach($pairing['anker'] as $a)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $a['display_de'] ?: $a['slug'] }}</span>@endforeach
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-3 mt-3 items-start">
        @if(count($pairing['aroma'] ?? []))
            <div class="relative overflow-hidden {{ $card }}" data-gp-harmonie>
                <div class="{{ $cardAccent }}"></div>
                <div class="px-5 py-4 space-y-2">
                    <h3 class="font-medium tracking-tight text-gray-900">Harmoniert (★★★)</h3>
                    <p class="text-[11px] text-gray-500">Echtes Food Pairing, gemessen (Foodpairing Inspire).</p>
                    <div class="flex flex-wrap gap-1">
                        @foreach($pairing['aroma'] as $n)<span class="{{ $pill }} {{ $variantPill['primary'] }}">{{ $n }}</span>@endforeach
                    </div>
                </div>
            </div>
        @endif

        @if(count($pairing['braucht'] ?? []) || count($pairing['kontrast'] ?? []))
            <div class="relative overflow-hidden {{ $card }}" data-gp-kontrast>
                <div class="{{ $cardAccent }}"></div>
                <div class="px-5 py-4 space-y-2">
                    <h3 class="font-medium tracking-tight text-gray-900">Braucht · Kontrast</h3>
                    @if(count($pairing['braucht'] ?? []))
                        <p class="text-[11px] text-gray-500">
                            @foreach($pairing['braucht'] as $b){{ $loop->first ? '' : ' · ' }}{{ $b['achse'] }}{{ $b['staerke'] === 'muss' ? ' (muss)' : '' }}@endforeach
                            <span class="opacity-70">— {{ $pairing['braucht'][0]['grundlage'] }}</span>
                        </p>
                    @endif
                    <div class="flex flex-wrap gap-1">
                        @foreach($pairing['kontrast'] ?? [] as $c)<span class="{{ $pill }}" style="background-color: rgba(6,182,212,0.14); color: #0891b2;" title="deckt {{ $c['achse'] }}">{{ $c['name'] }} <span class="opacity-60">{{ $c['achse'] }}</span></span>@endforeach
                    </div>
                </div>
            </div>
        @endif

        @if(count($pairing['konflikt'] ?? []))
            <div class="relative overflow-hidden {{ $card }}" data-gp-konflikt>
                <div class="{{ $cardAccent }}"></div>
                <div class="px-5 py-4 space-y-2">
                    <h3 class="font-medium tracking-tight text-gray-900">Stört sich mit</h3>
                    <p class="text-[11px] text-gray-500">Aus dem Anker-Wissen (Dossier).</p>
                    <div class="flex flex-wrap gap-1">
                        @foreach($pairing['konflikt'] as $n)<span class="{{ $pill }} {{ $variantPill['danger'] }}">{{ $n }}</span>@endforeach
                    </div>
                </div>
            </div>
        @endif
        </div>
    @endif
@endif
