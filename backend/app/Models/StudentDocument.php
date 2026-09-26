<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A digital form or file the registrar has attached to a student record
 * (§3.9.2). Files live on the private "local" disk, never the public root,
 * and are only served through StudentDocumentController::download.
 */
class StudentDocument extends Model
{
    protected $fillable = [
        'student_id',
        'uploaded_by',
        'document_type',
        'description',
        'file_path',
        'original_name',
        'mime',
        'size',
    ];

    /** The storage path is an internal detail; clients download by ID. */
    protected $hidden = [
        'file_path',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id', 'student_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by', 'id');
    }
}
