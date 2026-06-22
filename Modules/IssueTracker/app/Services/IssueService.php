<?php

namespace Modules\IssueTracker\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Modules\IssueTracker\Models\Issue;
use Modules\IssueTracker\Models\IssueActivity;
use Modules\IssueTracker\Models\IssueAttachment;
use Modules\IssueTracker\Models\IssueComment;

class IssueService
{
    public function createIssue(array $data, array $files = []): Issue
    {
        $issue = Issue::create([
            'tracking_token' => Str::random(12),
            'title' => $data['title'],
            'description' => $data['description'],
            'platform' => $data['platform'],
            'category_id' => $data['category_id'] ?? null,
            'priority' => $data['priority'] ?? 'medium',
            'status' => 'open',
            'reporter_name' => $data['reporter_name'],
            'reporter_contact' => $data['reporter_contact'] ?? null,
        ]);

        $this->handleAttachments($issue, null, $files);

        IssueActivity::create([
            'issue_id' => $issue->id,
            'action' => 'created',
            'actor_name' => $issue->reporter_name,
            'metadata' => ['platform' => $issue->platform],
        ]);

        return $issue;
    }

    public function addComment(Issue $issue, array $data, array $files = []): IssueComment
    {
        $comment = IssueComment::create([
            'issue_id' => $issue->id,
            'body' => $data['body'],
            'author_type' => $data['author_type'],
            'author_name' => $data['author_name'],
            'is_internal' => $data['is_internal'] ?? false,
        ]);

        $this->handleAttachments($issue, $comment, $files);

        IssueActivity::create([
            'issue_id' => $issue->id,
            'action' => 'commented',
            'actor_name' => $data['author_name'],
            'metadata' => ['is_internal' => $comment->is_internal],
        ]);

        return $comment;
    }

    public function updateStatus(Issue $issue, string $status, string $actorName): void
    {
        $oldStatus = $issue->status;
        $issue->status = $status;

        if ($status === 'resolved') {
            $issue->resolved_at = now();
        }

        $issue->save();

        IssueActivity::create([
            'issue_id' => $issue->id,
            'action' => 'status_changed',
            'actor_name' => $actorName,
            'metadata' => ['from' => $oldStatus, 'to' => $status],
        ]);
    }

    public function assignTo(Issue $issue, string $assignee, string $actorName): void
    {
        $issue->update(['assigned_to' => $assignee]);

        IssueActivity::create([
            'issue_id' => $issue->id,
            'action' => 'assigned',
            'actor_name' => $actorName,
            'metadata' => ['assigned_to' => $assignee],
        ]);
    }

    protected function handleAttachments(Issue $issue, ?IssueComment $comment, array $files): void
    {
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store('issue-tracker/' . $issue->id, 'public');

            IssueAttachment::create([
                'issue_id' => $issue->id,
                'comment_id' => $comment?->id,
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
            ]);
        }
    }
}
