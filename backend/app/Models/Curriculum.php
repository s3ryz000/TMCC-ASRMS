<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class Curriculum extends Model
{
    protected $table = 'curriculum';

    protected $fillable = [
        'program_id', 'subject_id', 'year_level', 'semester', 'unresolved_prerequisites', 'prerequisite_logic',
        'requires_all_other_subjects',
    ];

    /**
     * Cast unresolved_prerequisites from/to a PHP array automatically.
     * Null means no unresolved prerequisites exist for this curriculum row.
     *
     * requires_all_other_subjects (#82): a program-completion subject such as
     * PRACTICUM, enrolled only once every other subject of the program is
     * Passed or Credited.
     */
    protected $casts = [
        'unresolved_prerequisites' => 'array',
        'requires_all_other_subjects' => 'boolean',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
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
     * The subjects this entry requires. curriculum_prerequisites is the only
     * place prerequisites are stored (#17).
     *
     * @return Collection<int, Subject>
     */
    public function prerequisiteSubjects(): Collection
    {
        return $this->prerequisites->values();
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
