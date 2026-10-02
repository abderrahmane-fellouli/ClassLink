<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** F-DEV-02, F-DEV-03 — rendu. RG-16 : `is_late` calculé par le serveur. */
class SubmissionResource extends \Illuminate\Http\Resources\Json\JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $canSeeStudentEmail = $viewer && $this->student
            && \Illuminate\Support\Facades\Gate::forUser($viewer)->allows('viewEmail', $this->student);

        return [
            'id' => $this->id,
            'assignment_id' => $this->assignment_id,
            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'download_endpoint' => $this->file_path
            ? url("/api/submissions/{$this->id}/download")
            : null,
            'is_late' => (bool) $this->is_late,
            'grade' => $this->grade,
            'feedback' => $this->feedback,
            'submitted_at' => $this->created_at?->toIso8601String(),

            'student' => $this->whenLoaded('student', fn () => array_filter([
                'id' => $this->student->id,
                'display_name' => $this->student->display_name,
                'email' => $canSeeStudentEmail ? $this->student->email : null,
            ], static fn ($v) => $v !== null)),
        ];
    }
}
