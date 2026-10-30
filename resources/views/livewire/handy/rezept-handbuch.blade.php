{{-- #1003 Handy-Detail im Handbuch-Stil, für Rezept ($art 'rezept') und Gericht ($art 'gericht').
     Rendert NUR am Handy (Recipes\DetailPanel, Verkauf\DetailPanel: HandyLesemodus::anfrageVomHandy); das
     Desktop-Detail bleibt unverändert. Aufbau: Foto → Titel → drei Kernwerte → Allergene → Reiter
     (Zutaten · Zubereitung · Anrichten · Regeneration · Formen). Nur lesen. --}}
@php
    $menge = fn ($wert) => rtrim(rtrim(number_format((float) $wert, 2, ',', '.'), '0'), ',');
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $reiter = array_filter([
        'zutaten' => 'Zutaten',
        'zubereitung' => 'Zubereitung',
        'anrichten' => $karte['anrichten'] !== [] ? 'Anrichten' : null,
        'regeneration' => $karte['regeneration'] !== [] || $karte['behaelter'] !== [] ? 'Regeneration' : null,
        'formen' => $art === 'gericht' && $formen->isNotEmpty() ? 'Formen' : null,
    ]);
    $teile = array_filter([
        $karte['portion']['form'] ?? null,
        ($karte['portion']['gramm'] ?? null) ? $menge($karte['portion']['gramm']) . ' g' : null,
        $karte['portion']['geschirr'] ?? null,
    ]);
@endphp
<div class="flex flex-col gap-4 pb-6 min-h-full bg-[var(--fa-ground)]" data-fa-handbuch="{{ $art }}" x-data="{ reiter: 'zutaten' }">
    @if($karte['foto_url'])
        <img src="{{ $karte['foto_url'] }}" alt="" class="w-full aspect-[4/3] object-cover" data-fa-handbuch-foto>
    @endif

    <header class="flex flex-col gap-2 px-4 {{ $karte['foto_url'] ? '' : 'pt-4' }}" data-fa-detail-kopf>
        <h2 class="text-[length:var(--fa-text-2xl)] font-semibold leading-snug text-[var(--fa-ink)] wrap-anywhere">{{ $rezept->name }}</h2>
        <div class="flex flex-wrap items-center gap-1.5">
            <x-fa::status :value="$rezept->status" />
            @if($art === 'rezept')
                <x-fa::badge tone="info">{{ $rezept->category?->label ?? 'Ohne Kategorie' }}</x-fa::badge>
            @elseif($teile)
                <span class="{{ $leise }}">{{ implode(' · ', $teile) }}</span>
            @endif
        </div>
        <p class="flex items-center gap-1.5 {{ $leise }}" data-fa-handy-hinweis>
            @svg('heroicon-m-computer-desktop', 'w-4 h-4 shrink-0') Am Computer bearbeiten
            <button type="button" class="ml-auto underline underline-offset-2" x-on:click="window.faAnsicht('desktop')" data-fa-desktop-ansicht>Desktop-Ansicht</button>
        </p>
    </header>

    {{-- Kernwerte: drei Zahlen, fehlende werden gezeigt, nicht geschätzt --}}
    <dl class="mx-4 grid grid-cols-3 rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-surface)] divide-x divide-[var(--fa-line)]" data-fa-kernwerte>
        @foreach($kennzahlen as $kz)
            <div class="flex flex-col gap-0.5 px-3 py-2.5 min-w-0">
                <dt class="{{ $leise }} truncate">{{ $kz['label'] }}</dt>
                <dd class="text-[length:var(--fa-text-lg)] font-semibold tabular-nums leading-tight {{ ($kz['fehlt'] ?? false) ? 'text-[var(--fa-warn)]' : 'text-[var(--fa-ink)]' }}">{{ $kz['value'] }}</dd>
            </div>
        @endforeach
    </dl>

    <div class="px-4" data-fa-handbuch-allergene>
        <x-fa::deklaration :rezept="$rezept" />
    </div>

    {{-- Reiter: je Bildschirm ein Thema --}}
    <div class="sticky top-0 z-10 bg-[var(--fa-ground)] border-b border-[var(--fa-line)]">
        <div class="flex overflow-x-auto px-2" role="tablist">
            @foreach($reiter as $schluessel => $titel)
                <button type="button" role="tab" class="shrink-0 min-h-11 px-3 border-b-2 text-[length:var(--fa-text-md)] font-medium"
                        x-bind:class="reiter === '{{ $schluessel }}' ? 'border-[var(--fa-accent)] text-[var(--fa-ink)]' : 'border-transparent text-[var(--fa-ink-3)]'"
                        x-bind:aria-selected="reiter === '{{ $schluessel }}'" x-on:click="reiter = '{{ $schluessel }}'" data-fa-reiter="{{ $schluessel }}">{{ $titel }}</button>
            @endforeach
        </div>
    </div>

    <section class="px-4" x-show="reiter === 'zutaten'" data-fa-reiter-inhalt="zutaten">
        @if($rezept->ingredients->isEmpty())
            <p class="{{ $leise }}">Keine Zutaten hinterlegt.</p>
        @else
            @if(($hoch ?? null) !== null)
                @include('foodalchemist::livewire.recipes.partials.hochrechner', ['hoch' => $hoch, 'handy' => true, 'uebernehmbar' => false])
            @endif
            <ul class="flex flex-col">
                @foreach($rezept->ingredients as $z)
                    <li wire:key="hz-{{ $z->id }}" class="flex items-baseline gap-3 py-2.5 border-b border-[var(--fa-line)] last:border-b-0 text-[length:var(--fa-text-md)] {{ $z->is_optional ? 'opacity-60' : '' }}">
                        <span class="min-w-0 flex-1 text-[var(--fa-ink)]">
                            @if($z->referencedRecipe !== null)
                                @if($art === 'rezept')
                                    <a href="#" role="button" wire:click.prevent="zeige({{ $z->referenced_recipe_id }})" class="text-[var(--fa-info)] underline underline-offset-2">{{ $z->referencedRecipe->name }}</a>
                                @else
                                    <a href="{{ route('foodalchemist.recipes.index', ['rezept' => $z->referenced_recipe_id]) }}" class="text-[var(--fa-info)] underline underline-offset-2">{{ $z->referencedRecipe->name }}</a>
                                @endif
                                <span class="{{ $leise }}">(Rezept)</span>
                            @else
                                {{ $z->gp?->name ?? $z->display_name ?? $z->raw_text }}
                            @endif
                            @if($z->is_optional)<span class="{{ $leise }}"> (optional)</span>@endif
                        </span>
                        @php $mengeText = $menge($z->quantity) . ($z->quantity_max !== null ? '–' . $menge($z->quantity_max) : '') . ' ' . $z->unit?->slug; @endphp
                        @if(($hoch ?? null) !== null && (float) $z->quantity > 0)
                            {{-- Menge antippen = „nach Zutat" hochrechnen; bei Faktor die neue Menge, darunter die Rezeptmenge --}}
                            <button type="button" wire:click="hochNachZutat({{ $z->id }})" class="shrink-0 min-h-11 -my-2 pl-2 text-right tabular-nums {{ ($hoch['zutat']?->id ?? null) === $z->id ? 'text-[var(--fa-accent)]' : 'text-[var(--fa-ink-2)]' }}" data-fa-hoch-menge="{{ $z->id }}">
                                @if(isset($hoch['mengen'][$z->id]))
                                    <span class="block font-semibold text-[var(--fa-accent)]" data-fa-hoch-neu>{{ $hoch['mengen'][$z->id] }}</span>
                                    <span class="block {{ $leise }}">statt {{ $mengeText }}</span>
                                @else
                                    <span class="underline decoration-dotted underline-offset-4">{{ $mengeText }}</span>
                                @endif
                            </button>
                        @else
                            <span class="shrink-0 text-right tabular-nums text-[var(--fa-ink-2)]">{{ $mengeText }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
            @php
                $ausbeuteKg = $rezept->yield_kg_manual ?? $rezept->yield_kg;
                $portionG = $karte['portion']['gramm'] ?? null;
                // Gerichte sind oft genau eine Portion: dann „je Portion“, sonst die Ansatz-Menge (nichts wird umgerechnet)
                $jePortion = $ausbeuteKg !== null && $portionG !== null && $portionG > 0 && abs($ausbeuteKg * 1000 - $portionG) <= 0.05 * $portionG;
            @endphp
            <p class="mt-2 {{ $leise }}" data-fa-mengen-basis="{{ $jePortion ? 'portion' : 'ansatz' }}">{{ $jePortion ? 'Mengen für eine Portion.' : 'Mengen für den ganzen Ansatz' . ($ausbeuteKg !== null ? ' (' . $menge($ausbeuteKg) . ' kg)' : '') . '.' }}</p>
        @endif
    </section>

    @foreach(['zubereitung' => 'Keine Zubereitung hinterlegt.', 'anrichten' => 'Kein Anrichten hinterlegt.'] as $abschnitt => $leer)
        @continue(! isset($reiter[$abschnitt]))
        <section class="px-4" x-show="reiter === '{{ $abschnitt }}'" x-cloak data-fa-reiter-inhalt="{{ $abschnitt }}">
            <ol class="flex flex-col gap-4">
                @forelse($karte[$abschnitt] as $s)
                    <li class="flex items-start gap-3" wire:key="hs-{{ $abschnitt }}-{{ $s['nr'] }}">
                        <span class="shrink-0 grid place-items-center w-7 h-7 rounded-full bg-[var(--fa-accent-soft)] text-[length:var(--fa-text-sm)] font-semibold tabular-nums text-[var(--fa-accent)]">{{ $s['nr'] }}</span>
                        <div class="min-w-0 flex-1 flex flex-col gap-2">
                            <div class="text-[length:var(--fa-text-md)] leading-relaxed text-[var(--fa-ink)]">{!! \Illuminate\Support\Str::inlineMarkdown($s['text']) !!}</div>
                            @foreach($s['fotos'] as $f)
                                @if(!empty($f['url']))
                                    <img src="{{ $f['url'] }}" alt="{{ $f['caption'] ?? '' }}" class="w-full rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)]" loading="lazy">
                                @endif
                            @endforeach
                        </div>
                    </li>
                @empty
                    <li class="{{ $leise }}">{{ $leer }}</li>
                @endforelse
            </ol>
        </section>
    @endforeach

    @isset($reiter['regeneration'])
        <section class="px-4 flex flex-col gap-2" x-show="reiter === 'regeneration'" x-cloak data-fa-reiter-inhalt="regeneration">
            @foreach($karte['regeneration'] as $r)
                <div class="rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-surface)] px-3 py-2.5">
                    <p class="font-medium text-[var(--fa-ink)]">{{ $r['komponente'] }}</p>
                    <p class="text-[length:var(--fa-text-md)] tabular-nums text-[var(--fa-ink-2)]">
                        @if($r['kalt'])
                            Kalt servieren
                        @else
                            {{ implode(' · ', array_filter([$r['geraet'], $r['temp_c'] !== null ? $r['temp_c'] . ' °C' : null, $r['dauer_min'] !== null ? $r['dauer_min'] . ' min' : null, $r['kern_c'] !== null ? 'Kern ' . $r['kern_c'] . ' °C' : null])) }}
                        @endif
                    </p>
                    @if(!empty($r['hinweis']) && ! ($r['kalt'] && mb_strtolower(trim($r['hinweis'])) === 'kalt servieren'))<p class="{{ $leise }}">{{ $r['hinweis'] }}</p>@endif
                </div>
            @endforeach
            @foreach($karte['behaelter'] as $bh)
                <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]"><span class="font-medium text-[var(--fa-ink)]">{{ $bh['zweck'] }}:</span> {{ $bh['name'] }}</p>
            @endforeach
        </section>
    @endisset

    @isset($reiter['formen'])
        <section class="px-4 flex flex-col gap-2" x-show="reiter === 'formen'" x-cloak data-fa-reiter-inhalt="formen">
            @foreach($formen as $d)
                <div wire:key="hf-{{ $d->id }}" class="flex items-center gap-3 rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-surface)] px-3 py-2.5">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-[var(--fa-ink)]">{{ $d->servingForm?->label ?? 'Ohne Form' }}</p>
                        <p class="{{ $leise }}">{{ implode(' · ', array_filter([$d->is_standard ? 'Standard' : null, $d->quantity_per_unit_g !== null ? $menge($d->quantity_per_unit_g) . ' g' : null, $d->unit_count !== null && (float) $d->unit_count !== 1.0 ? $menge($d->unit_count) . ' Stück' : null])) }}</p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="text-[length:var(--fa-text-lg)] font-semibold tabular-nums {{ $d->sales_gross === null ? 'text-[var(--fa-warn)]' : 'text-[var(--fa-ink)]' }}">{{ $d->sales_gross !== null ? number_format($d->sales_gross, 2, ',', '.') . ' €' : 'VK fehlt' }}</p>
                        @if($d->sales_net !== null)<p class="{{ $leise }} tabular-nums">netto {{ number_format($d->sales_net, 2, ',', '.') }} €</p>@endif
                    </div>
                </div>
            @endforeach
            @if($standort ?? false)<p class="{{ $leise }}">Grundpreise der Darreichungen. Der Preis für deinen Standort steht oben bei VK brutto.</p>@endif
        </section>
    @endisset
</div>
