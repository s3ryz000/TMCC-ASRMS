<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class Student extends Model
{
    use HasFactory;

    protected $primaryKey = 'student_id';

    public $incrementing = true;

    protected $fillable = [
        'user_id',
        'program_id',
        'student_number',
        'first_name',
        'middle_name',
        'last_name',
        'date_of_birth',
        'email',
        'contact_number',
        'address',
        'place_of_birth',
        'sex',
        'guardian_name',
        'citizenship',
        'elementary_school',
        'elementary_year',
        'high_school',
        'high_school_year',
        'previous_school',
        'previous_course',
        'enrollment_date',
        'graduation_date',
        'GPA',
    ];

    protected $casts = [
        // Calendar dates, so JSON carries "2006-06-21" rather than a UTC
        // timestamp that a browser can read as the previous day (#77).
        'date_of_birth' => 'date:Y-m-d',
        'enrollment_date' => 'date:Y-m-d',
        'graduation_date' => 'date:Y-m-d',
        'elementary_year' => 'integer',
        'high_school_year' => 'integer',
        'GPA' => 'decimal:2',
    ];

    public function programMappings()
    {
        return $this->hasMany(ProgramMapping::class, 'student_id', 'student_id');
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'student_id', 'student_id');
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class, 'student_id', 'student_id');
    }

    public function recordRequests(): HasMany
    {
        return $this->hasMany(RecordRequest::class, 'student_id', 'student_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(StudentDocument::class, 'student_id', 'student_id');
    }

    public function archiveRecords(): HasOne
    {
        return $this->hasOne(ArchiveRecord::class, 'student_id', 'student_id');
    }
}
