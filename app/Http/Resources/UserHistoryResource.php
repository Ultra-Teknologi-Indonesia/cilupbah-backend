<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserHistoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $actorName = $this->actor ? $this->actor->name : 'Sistem';
        $targetName = $this->targetUser ? $this->targetUser->name : 'Pengguna (Telah Dihapus)';

        $message = match($this->action) {
            'created' => "{$actorName} membuat akun {$targetName}.",
            'updated' => "{$actorName} memperbarui informasi akun {$targetName}.",
            'deleted' => "{$actorName} menghapus akun {$targetName}.",
            'force_logged_out' => "{$actorName} memutus sesi (force logout) akun {$targetName} secara paksa.",
            default => "Aksi tidak dikenal.",
        };

        return [
            'id' => $this->id,
            'actor' => $this->actor ? [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'email' => $this->actor->email,
            ] : null,
            'target_user_id' => $this->target_user_id,
            'action' => $this->action,
            'message' => $message,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
