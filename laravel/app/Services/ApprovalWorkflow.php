<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalWorkflow
{
    public function assertIndependent(?int $requesterId, int $approverId, string $subject): void
    {
        if ($requesterId !== null && $requesterId === $approverId) {
            throw ValidationException::withMessages([
                'approval' => 'Người lập hoặc đề xuất '.$subject.' không được tự phê duyệt hồ sơ đó.',
            ]);
        }
    }

    public function recordDecision(
        string $subjectType,
        int $subjectId,
        string $decision,
        string $statusFrom,
        string $statusTo,
        int $actorId,
        ?string $reason,
        array $content
    ): void {
        $snapshot = json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = now();

        DB::table('approval_events')->insert([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'decision' => $decision,
            'status_from' => $statusFrom,
            'status_to' => $statusTo,
            'actor_id' => $actorId,
            'reason' => $reason,
            'content_hash' => hash('sha256', $snapshot),
            'content_snapshot' => $snapshot,
            'decided_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
