<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Where a student's paper records are kept: archiving a student (#85) and
 * editing the location later (#97). New Student uses the same field rules.
 */
class ArchiveLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route's role:staff middleware decides who may write
    }

    /** The archive fields' rules, shared with StoreStudentRequest. */
    public static function fieldRules(): array
    {
        return [
            'record_type'     => ['required', 'string', 'max:100'],
            'cabinet_no'      => ['required', 'string', 'max:50'],
            'shelf_no'        => ['required', 'string', 'max:50'],
            'folder_code'     => ['required', 'string', 'max:50'],
            'document_status' => ['required', 'string', 'max:50'],
        ];
    }

    /** Field names as the forms label them, for the messages. */
    public static function fieldNames(): array
    {
        return [
            'record_type'     => 'record type',
            'cabinet_no'      => 'cabinet number',
            'shelf_no'        => 'shelf number',
            'folder_code'     => 'folder code',
            'document_status' => 'document status',
        ];
    }

    public function rules(): array
    {
        return self::fieldRules();
    }

    public function attributes(): array
    {
        return self::fieldNames();
    }
}
