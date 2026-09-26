<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Http\Requests\StoreStudentDocumentRequest;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\SystemLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Digital forms and files attached to a student record (§3.9.2).
 *
 * Uploading and deleting are record-keeping, so they are registrar staff only.
 * Listing and downloading are reads, which admins keep for oversight, matching
 * the rest of the student-record endpoints.
 */
class StudentDocumentController extends Controller
{
    use AuthorizesRole;

    /**
     * List the documents attached to a student record.
     */
    public function index(Request $request, int $id): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff', 'admin'])) {
            return $err;
        }

        $student = Student::find($id);
        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $documents = StudentDocument::with('uploader:id,name')
            ->where('student_id', $student->student_id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($documents);
    }

    /**
     * Attach a document to a student record.
     */
    public function store(StoreStudentDocumentRequest $request, int $id): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        $user = $request->user();
        if ($err = $this->requireRoles($user, ['staff'])) {
            return $err;
        }

        $student = Student::find($id);
        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $validated = $request->validated();
        $file = $request->file('document');

        $document = StudentDocument::create([
            'student_id' => $student->student_id,
            'uploaded_by' => $user->id,
            'document_type' => $validated['document_type'],
            'description' => $validated['description'] ?? null,
            'file_path' => $file->store("student-documents/{$student->student_id}", 'local'),
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);

        SystemLog::create([
            'action' => "Uploaded {$document->document_type} for {$student->student_number} ({$student->first_name} {$student->last_name}): {$document->original_name}",
            'user_id' => $user->id,
            'role' => $this->userRole($user) ?? 'staff',
        ]);

        return response()->json([
            'message' => 'Document uploaded successfully.',
            'document' => $document->load('uploader:id,name'),
        ], 201);
    }

    /**
     * Download a document attached to a student record.
     */
    public function download(Request $request, int $id, int $documentId)
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff', 'admin'])) {
            return $err;
        }

        $document = $this->findForStudent($id, $documentId);
        if (!$document) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        if (!Storage::disk('local')->exists($document->file_path)) {
            return response()->json(['message' => 'Document file does not exist on server.'], 404);
        }

        return Storage::disk('local')->download($document->file_path, $document->original_name);
    }

    /**
     * Remove a document from a student record, e.g. one uploaded in error.
     */
    public function destroy(Request $request, int $id, int $documentId): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        $user = $request->user();
        if ($err = $this->requireRoles($user, ['staff'])) {
            return $err;
        }

        $document = $this->findForStudent($id, $documentId);
        if (!$document) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        $student = $document->student;

        // Row first: if the file delete then fails we leave an orphaned file,
        // not a listed document whose download 404s.
        $document->delete();
        Storage::disk('local')->delete($document->file_path);

        SystemLog::create([
            'action' => "Deleted {$document->document_type} for {$student->student_number} ({$student->first_name} {$student->last_name}): {$document->original_name}",
            'user_id' => $user->id,
            'role' => $this->userRole($user) ?? 'staff',
        ]);

        return response()->json(['message' => 'Document deleted successfully.']);
    }

    /**
     * Scope the lookup to the student in the URL so a document ID cannot be
     * fetched through another student's record.
     */
    private function findForStudent(int $studentId, int $documentId): ?StudentDocument
    {
        return StudentDocument::where('student_id', $studentId)->find($documentId);
    }
}
