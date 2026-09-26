<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class Curriculum extends Model
{
    protected $table = 'curriculum';

    protected $fillable = ['program_id', 'subject_id', 'year_level', 'semester', 'prerequisite', 'unresolved_prerequisites', 'prerequisite_logic'];

    /**
     * Cast unresolved_prerequisites from/to a PHP array automatically.
     * Null means no unresolved prerequisites exist for this curriculum row.
     */
    protected $casts = [
        'unresolved_prerequisites' => 'array',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @deprecated Use prerequisites() instead to support multiple subjects.
     */
    public function prerequisite(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'prerequisite');
    }

    public function prerequisites()
    {
        return $this->belongsToMany(
            Subject::class,
            'curriculum_prerequisites',
            'curriculum_id',
            'prerequisite_subject_id'
        )->withTimestamps();
    }

    /**
     * The subjects this entry requires: the many-to-many prerequisites, or the
     * deprecated single `prerequisite` column for rows not yet migrated.
     *
     * @return Collection<int, Subject>
     */
    public function prerequisiteSubjects(): Collection
    {
        if ($this->prerequisites->isNotEmpty()) {
            return $this->prerequisites->values();
        }

        // The column and the relation share a name, so read each explicitly.
        $legacyId = $this->getAttributes()['prerequisite'] ?? null;
        if (! $legacyId) {
            return collect();
        }

        $legacy = $this->getRelationValue('prerequisite') ?? Subject::find($legacyId);

        return $legacy ? collect([$legacy]) : collect();
    }

    /**
     * Prerequisites still outstanding, given the subjects already passed.
     * AND needs every prerequisite; OR is satisfied by any one of them.
     *
     * @param int[] $passedSubjectIds
     * @return Collection<int, Subject>
     */
    public function missingPrerequisites(array $passedSubjectIds): Collection
    {
        $required = $this->prerequisiteSubjects();
        $passed = array_map('intval', $passedSubjectIds);
        $isPassed = fn (Subject $subject) => in_array((int) $subject->id, $passed, true);

        if (($this->prerequisite_logic ?? 'AND') === 'OR') {
            return $required->isEmpty() || $required->contains($isPassed) ? collect() : $required;
        }

        return $required->reject($isPassed)->values();
    }
}
