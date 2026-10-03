<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @deprecated Read-only history (#21). A student's program is
 * students.program_id and their year level and term come from their latest
 * enrollment; nothing writes program_mappings any more. The table and its
 * rows are kept until a later phase decides to drop them.
 */
class ProgramMapping extends Model
{
    protected $fillable = ['program_id', 'student_id','academic_year','semester','status','year_level'];


    public function program()
    {
        return $this->belongsTo(Program::class);
    }
    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id', 'student_id');
    }
}
