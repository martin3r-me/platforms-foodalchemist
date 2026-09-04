<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Kaskaden-Lauf (Planungs-Kaskade, P0): EIN „Go" auf einer Planung. Der geteilte
 * Motor ({@see \Platform\FoodAlchemist\Services\PlanningCascadeService}) legt ihn an und fächert
 * ihn in {@see FoodAlchemistCascadeRunStep} auf (Baum concept → gericht → rezept/gp).
 *
 * `scope` bestimmt die Einstiegs-Tiefe (rezept ⊂ gericht ⊂ concept ⊂ vollkaskade); der Motor läuft
 * von dort abwärts. **Invariante:** erzeugt NUR Drafts — die Freigabe an eine Live-Ausgabe ist das
 * zweite Gate (Sammel-Review, P2). Team-lokal (D1).
 */
class FoodAlchemistCascadeRun extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_cascade_runs';

    protected $guarded = ['id'];

    /** Einstiegs-Tiefe der Kaskade (Rezept ⊂ Gericht ⊂ Concept ⊂ Ausgabe-Frame). */
    public const SCOPES = ['rezept', 'gericht', 'concept', 'vollkaskade'];

    /** Lebenszyklus: running (Steps rechnen) → review (fertig, Sammel-Review offen) → done | failed. */
    public const STATUSES = ['running', 'review', 'done', 'failed'];

    protected $casts = [
        'uuid' => 'string',
        'params' => 'array',
        'staged' => 'boolean',
        'cohesion_warning' => 'array',
        'deckel_hinweise' => 'array',
    ];

    /**
     * Vermerkt, was ein Deckel diesem Lauf WEGGENOMMEN hat — der eine Schreibweg für alle sechs
     * Runaway-Deckel der Generierung.
     *
     * Warum am Model und nicht im Service: die Deckel sitzen in drei verschiedenen Diensten
     * ({@see \Platform\FoodAlchemist\Services\PlanningCascadeService} für Zellen/Positionen/Slots,
     * {@see \Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService} für Schritte/Tiefe,
     * {@see \Platform\FoodAlchemist\Services\IdeenService} für die Ideen-Klemme). Ein gemeinsamer
     * Weg hier verhindert, dass die Struktur über sechs Stellen driftet — und dass der siebte
     * Deckel wieder still wird, weil sein Autor die Form nicht kennt.
     *
     * ATOMAR, mit Absicht: die Kind-Jobs eines Laufs können parallel laufen (auf demo heute nur
     * ein Worker, aber darauf darf sich das Datenmodell nicht verlassen). Ein naives
     * read-modify-write würde bei zwei gleichzeitigen Deckeln einen Hinweis verlieren — und ein
     * verlorener Hinweis ist genau der Zustand, den diese Spalte beseitigen soll.
     *
     * Idempotent je Deckel-Schlüssel: ein zweiter Vermerk desselben Deckels ersetzt den ersten
     * (ein Lauf, der zweimal an derselben Grenze anschlägt, hat EINEN Befund, nicht zwei).
     */
    public function vermerkeDeckel(string $deckel, int $grenze, int $verlangt, int $offen, string $text): void
    {
        if ($offen < 1) {
            return;   // nichts weggefallen = kein Hinweis. Ein Nichts wird nicht als Befund verkauft.
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($deckel, $grenze, $verlangt, $offen, $text): void {
            /** @var self|null $frisch */
            $frisch = self::query()->whereKey($this->getKey())->lockForUpdate()->first();
            if ($frisch === null) {
                return;
            }

            $liste = is_array($frisch->deckel_hinweise) ? $frisch->deckel_hinweise : [];
            $liste = array_values(array_filter(
                $liste,
                static fn ($e): bool => is_array($e) && ($e['deckel'] ?? null) !== $deckel
            ));
            $liste[] = [
                'deckel' => $deckel,
                'grenze' => $grenze,
                'verlangt' => $verlangt,
                'offen' => $offen,
                'text' => $text,
            ];

            $frisch->forceFill(['deckel_hinweise' => $liste])->save();
        });

        $this->refresh();
    }

    /**
     * Die Deckel-Sätze dieses Laufs als EINE Zeile — oder null, wenn kein Deckel gegriffen hat.
     *
     * Lese-Gegenstück zu {@see vermerkeDeckel}: der Wortlaut entsteht dort, wo der Deckel greift
     * (die Zähl-Mechanik ist bei jedem anders), und wird hier nur zusammengefügt. Ein zweiter
     * Wortlaut in der Livewire-Komponente würde vom gespeicherten driften — dann sagt die
     * Sofort-Meldung etwas anderes als das Lauf-Detail Wochen später.
     *
     * Ein Lauf kann mehrere Deckel treffen (ein großer Speiseplan zugleich Wochen UND
     * Sub-Rezept-Schritte), darum verbunden statt „der erste gewinnt".
     */
    public function deckelMeldung(): ?string
    {
        $liste = is_array($this->deckel_hinweise) ? $this->deckel_hinweise : [];
        $texte = array_values(array_filter(array_map(
            static fn ($e): string => is_array($e) ? trim((string) ($e['text'] ?? '')) : '',
            $liste,
        ), static fn (string $t): bool => $t !== ''));

        return $texte === [] ? null : implode(' · ', $texte);
    }

    /** Steps dieses Laufs (concept/gericht/rezept/gp), Baum über parent_step_id. */
    public function steps(): HasMany
    {
        return $this->hasMany(FoodAlchemistCascadeRunStep::class, 'cascade_run_id');
    }

    /**
     * Ursprungs-Skizze (Divergenz-Board), aus der dieser Lauf gestartet wurde (Etappe 4, Teil 2a).
     * Loser Zeiger — der Lauf überlebt die Skizze (kein Cascade). Trägt die Status-Rückkopplung
     * auf die Skizzen-Karte (Teil 2b: läuft/prüfen/fertig).
     */
    public function originDishIdea(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistDishIdea::class, 'origin_dish_idea_id');
    }
}
