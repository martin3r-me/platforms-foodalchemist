{{-- Planungs-Leitstelle · Reiter „Import“ (Spec 85 C1 PR-1, reine Verschiebung aus planung/index.blade.php).
     Bestehende Rezeptur (Text, Web-Kopie, PDF, Foto, Scan) TREU übernehmen und mit Grundprodukten verknüpft anlegen.
     Der Wrapper mit x-show und wire:key bleibt im Parent; „Fortschritt ansehen“ schaltet dessen Alpine-Reiter um. --}}
@php
    $hinweisText = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
@endphp
<div class="flex flex-col gap-4">
    @if($importStep === 'eingabe')
        <x-foodalchemist::modal-section icon="heroicon-o-arrow-down-tray" title="Rezept importieren">
            <x-slot:actions>
                <x-fa::badge>Schritt 1 von 3</x-fa::badge>
            </x-slot:actions>
            <div class="flex flex-col gap-4">
                <p class="{{ $hinweisText }} max-w-2xl">
                    Bestehendes Rezept einfügen oder als PDF, Scan oder Foto hochladen (Rezeptkarte, Kochbuchseite,
                    Step-by-Step-Seite). Es wird wörtlich übernommen, nichts wird erfunden, und die Zutaten werden mit
                    den Grundprodukten verknüpft. Verschachtelte Rezepte (Gericht mit Sauce oder Püree) werden als
                    verknüpfte Unterrezepte angelegt. Aus Bildern gelesene Mengen bitte in der Vorschau prüfen.
                </p>
                <x-fa::field label="Anlegen als" hint="Nach dem Lesen wird ein Vorschlag gesetzt, deine Wahl hat Vorrang.">
                    <x-fa::choice name="importTyp" :live="false" id-prefix="import-eingabe" :options="['basisrezept' => 'Basisrezept', 'gericht' => 'Gericht für den Verkauf']" />
                </x-fa::field>
                <x-fa::field label="Rezepttext">
                    <x-fa::textarea wire:model="importText" rows="10"
                        placeholder="Rezepttext hier einfügen, mit Zutaten und Zubereitung. Abschnitte wie »Für die Sauce: …« werden als Komponenten erkannt." />
                </x-fa::field>
                <x-fa::field label="Oder PDF / Foto hochladen" hint="PDF (Text oder Scan), JPG, PNG, WebP — bis 20 MB. Ein Text im Feld oben ergänzt das Bild." error="importPdf">
                    <div class="flex flex-wrap items-center gap-3">
                        <input type="file" wire:model="importPdf" accept="application/pdf,image/jpeg,image/png,image/webp,image/gif"
                               class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)] file:mr-3 file:h-8 file:px-3 file:rounded-[var(--fa-radius-control)] file:border file:border-[var(--fa-line-strong)] file:bg-[var(--fa-surface)] file:text-[var(--fa-ink)] file:font-medium" />
                        <span wire:loading wire:target="importPdf" class="{{ $hinweisText }}">wird geladen …</span>
                    </div>
                </x-fa::field>
                @if($importMeldung)<x-fa::signal tone="crit">{{ $importMeldung }}</x-fa::signal>@endif
                <div class="flex justify-end">
                    <x-fa::button variant="primary" icon="heroicon-o-document-magnifying-glass" wire:click="importExtrahieren" wire:loading.attr="disabled" wire:target="importExtrahieren,importPdf">
                        <span wire:loading.remove wire:target="importExtrahieren">Lesen und gliedern</span>
                        <span wire:loading wire:target="importExtrahieren">Wird gelesen, dauert etwa 15 Sekunden …</span>
                    </x-fa::button>
                </div>
            </div>
        </x-foodalchemist::modal-section>
    @elseif($importStep === 'vorschau')
        {{-- Prüf-Karte + klebende Anlegen-Leiste: die Zutatenliste wächst mit der Quelle, die Knöpfe
             standen bisher dahinter und rutschten unter den Fold. Felder, Reihenfolge, Bindings und die
             data-import-*-Anker bleiben unverändert. --}}
        <x-foodalchemist::modal-section icon="heroicon-o-eye" title="Vorschau prüfen">
            <x-slot:actions>
                <x-fa::badge>Schritt 2 von 3</x-fa::badge>
                @if(in_array($importQuelle, ['bild', 'scan'], true))
                    <x-fa::badge tone="warn" data-import-quelle="{{ $importQuelle }}">aus {{ $importQuelle === 'scan' ? 'Scan' : 'Foto' }} gelesen — Mengen prüfen</x-fa::badge>
                @endif
                @if(count($importVorschau['zutaten'] ?? []) > 0)
                    <x-fa::badge tone="accent">{{ count($importVorschau['zutaten']) }} Zutaten</x-fa::badge>
                @endif
                @if(!empty($importVorschau['komponenten']))
                    <x-fa::badge tone="accent">{{ count($komponentenFlach) }} Komponenten</x-fa::badge>
                @endif
            </x-slot:actions>
            <div class="flex flex-col gap-4">
                <div class="flex flex-wrap items-end gap-3">
                    <x-fa::field label="Name" class="flex-1 min-w-[16rem]" hint="Pflicht, wenn die Quelle keinen Titel hat.">
                        <x-fa::input wire:model="importVorschau.name" placeholder="Name des Rezepts" data-import-name />
                    </x-fa::field>
                    <x-fa::field label="Anlegen als" class="w-44">
                        <x-fa::select wire:model="importTyp" data-import-typ>
                            <option value="basisrezept">Basisrezept</option>
                            <option value="gericht">Gericht</option>
                        </x-fa::select>
                    </x-fa::field>
                </div>
                {{-- Abgleich-Leitplanken nur für diesen Import (27.10.): womit die Zutaten gegen den GP-Bestand abgeglichen werden --}}
                <div class="flex flex-col gap-2 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] px-3 py-2" data-import-abgleich>
                    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" data-import-abgleich-text>
                        Abgleich: <span class="font-medium text-[var(--fa-ink)]">{{ $abgleichOptionen['bio'][$abgleichBio] ?? $abgleichBio }} · {{ $abgleichOptionen['frische'][$abgleichFrische] ?? $abgleichFrische }} · {{ $abgleichOptionen['convenience'][$abgleichConvenience] ?? 'Keine Vorgabe' }}</span>
                        <span class="text-[var(--fa-ink-3)]">({{ $abgleichAusSession ? 'aus der Planungs-Session' : 'Standard' }}, nur für diesen Import änderbar)</span>
                    </p>
                    <div class="flex flex-wrap items-end gap-3">
                        <x-fa::field label="Bio" for="import-abgleich-bio" class="w-44">
                            <x-fa::select id="import-abgleich-bio" size="sm" wire:model.live="abgleichBio" :options="$abgleichOptionen['bio']" data-import-abgleich-bio />
                        </x-fa::field>
                        <x-fa::field label="Frische" for="import-abgleich-frische" class="w-52">
                            <x-fa::select id="import-abgleich-frische" size="sm" wire:model.live="abgleichFrische" :options="$abgleichOptionen['frische']" data-import-abgleich-frische />
                        </x-fa::field>
                        <x-fa::field label="Eigenleistung" for="import-abgleich-convenience" class="w-48">
                            <x-fa::select id="import-abgleich-convenience" size="sm" wire:model.live="abgleichConvenience" :options="$abgleichOptionen['convenience']" data-import-abgleich-convenience />
                        </x-fa::field>
                    </div>
                </div>
                <div class="flex flex-col gap-1.5">
                    <div class="flex items-center gap-2 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">
                        <span class="w-20 shrink-0">Menge</span><span class="w-24 shrink-0">Einheit</span><span>Zutat</span>
                    </div>
                    @foreach(($importVorschau['zutaten'] ?? []) as $zi => $z)
                        {{-- #6 (Dominique 2026-08-28): Menge und Einheit fest schmal, Bezeichnung breit. --}}
                        <div class="flex items-center gap-2" wire:key="izut-{{ $zi }}">
                            <x-fa::input wire:model="importVorschau.zutaten.{{ $zi }}.quantity" numeric class="w-20 shrink-0" placeholder="Menge" aria-label="Menge" data-import-zutat-menge />
                            <x-fa::input wire:model="importVorschau.zutaten.{{ $zi }}.unit" class="w-24 shrink-0" placeholder="Einheit" aria-label="Einheit" data-import-zutat-einheit />
                            <x-fa::input wire:model="importVorschau.zutaten.{{ $zi }}.text" class="flex-1 min-w-0" placeholder="Zutat" aria-label="Zutat" data-import-zutat-text />
                        </div>
                    @endforeach
                </div>
                @if(!empty($importVorschau['komponenten']))
                    <div class="flex flex-col gap-1.5">
                        <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Erkannte Komponenten <span class="font-normal text-[var(--fa-ink-3)]">· werden als Unterrezepte angelegt</span></p>
                        <ul class="flex flex-col divide-y divide-[var(--fa-line)] rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]">
                            @foreach($komponentenFlach as $ki => $k)
                                <li class="flex items-center justify-between gap-2 py-1.5 pr-3 text-[length:var(--fa-text-md)] {{ [1 => 'pl-3', 2 => 'pl-7', 3 => 'pl-11'][$k['ebene']] ?? 'pl-11' }}" wire:key="ikomp-{{ $ki }}" data-import-komponente="{{ $k['ebene'] }}">
                                    <span class="font-medium text-[var(--fa-ink)]">@if($k['ebene'] > 1)<span class="text-[var(--fa-ink-3)]">↳ </span>@endif{{ $k['name'] !== '' ? $k['name'] : 'Komponente ohne Namen' }}</span>
                                    <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">{{ $k['zutaten'] }} Zutaten</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <x-fa::field label="Zubereitung">
                    <x-fa::textarea wire:model="importVorschau.preparation" rows="6" />
                </x-fa::field>
            </div>
        </x-foodalchemist::modal-section>

        <div class="sticky bottom-0 z-10 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-[var(--fa-radius-surface)] border border-[var(--fa-line-strong)] bg-[var(--fa-surface)] px-4 py-3 shadow-lg shadow-black/20">
            <div class="flex-1 min-w-[16rem] flex flex-col gap-1">
                <p class="flex flex-wrap items-center gap-2 text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)]">
                    @svg('heroicon-o-check-circle', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]') Rezept anlegen
                    @if(trim((string) ($importVorschau['name'] ?? '')) !== '')
                        <x-fa::badge tone="accent">{{ \Illuminate\Support\Str::limit(trim($importVorschau['name']), 32) }}</x-fa::badge>
                    @endif
                </p>
                <p class="{{ $hinweisText }}">Die Zutaten werden mit den Grundprodukten verknüpft. Beschreibung und Pairings folgen im Hintergrund.</p>
                @if($importMeldung)<x-fa::signal tone="crit">{{ $importMeldung }}</x-fa::signal>@endif
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-fa::button variant="ghost" wire:click="importReset">Import verwerfen</x-fa::button>
                <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="importAnlegen" wire:loading.attr="disabled" wire:target="importAnlegen">
                    <span wire:loading.remove wire:target="importAnlegen">Rezept anlegen</span>
                    <span wire:loading wire:target="importAnlegen">Wird verknüpft …</span>
                </x-fa::button>
            </div>
        </div>
    @elseif($importStep === 'fertig' && $importErgebnis)
        <x-foodalchemist::modal-section icon="heroicon-o-check-badge" title="Importiert">
            <x-slot:actions>
                <x-fa::badge>Schritt 3 von 3</x-fa::badge>
            </x-slot:actions>
            <div class="flex flex-col gap-3">
                <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">
                    „{{ $importErgebnis['name'] }}" ist als Entwurf angelegt
                    @if(!empty($importErgebnis['sub_recipes']))
                        , dazu {{ count($importErgebnis['sub_recipes']) }} {{ count($importErgebnis['sub_recipes']) === 1 ? 'Unterrezept' : 'Unterrezepte' }}
                    @endif
                </p>
                {{-- #6/Import: Verknüpfung mit Grundprodukten + Anreicherung laufen im Hintergrund (nicht mehr synchron per Knopf). --}}
                <x-fa::signal tone="info">Grundprodukte, Beschreibung und Pairings werden im Hintergrund ergänzt.</x-fa::signal>
                <div class="flex flex-wrap items-center gap-2">
                    <x-fa::button variant="primary" icon="heroicon-o-queue-list" x-on:click="tab='worker'" data-import-zum-worker>Fortschritt ansehen</x-fa::button>
                    <x-fa::button variant="ghost" icon="heroicon-o-arrow-down-tray" wire:click="importReset">Weiteres Rezept importieren</x-fa::button>
                </div>
            </div>
        </x-foodalchemist::modal-section>
    @endif
</div>
