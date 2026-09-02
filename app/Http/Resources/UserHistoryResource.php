<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserHistoryResource extends JsonResource
{

    public function toArray(Request $request): array
    {
        $actorName = $this->actor?->name ?? $this->actor_user_name ?? 'Sistem';
        $targetName = $this->targetUser?->name ?? $this->target_user_name ?? 'Pengguna (Telah Dihapus)';

        $message = match($this->action) {
            'created' => "{$actorName} membuat akun {$targetName}.",
            'updated' => "{$actorName} memperbarui informasi akun {$targetName}.",
            'deleted' => "{$actorName} menghapus akun {$targetName}.",
            'force_logged_out' => "{$actorName} memutus sesi (force logout) akun {$targetName} secara paksa.",
            default => "Aksi tidak dikenal.",
        };

        return [
            'id' => $this->id,
            'actor' => ($this->actor || $this->actor_user_name) ? [
                'id' => $this->actor?->id ?? $this->actor_id_snapshot,
                'name' => $this->actor?->name ?? $this->actor_user_name,
                'email' => $this->actor?->email ?? $this->actor_user_email,
            ] : null,
            'target_user_id' => $this->target_user_id,
            'target_user_id_snapshot' => $this->target_user_id_snapshot,
            'target_user_name' => $this->targetUser?->name ?? $this->target_user_name,
            'target_user_email' => $this->targetUser?->email ?? $this->target_user_email,
            'action' => $this->action,
            'message' => $message,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
