<?php

namespace App\Observers;

use App\Models\User;
use App\Models\UserHistory;
use Illuminate\Support\Facades\Auth;

class UserObserver
{
    /**
     * Handle the User "created" event.
     */
    public function created(User $user): void
    {
        $this->recordHistory($user, 'created', null);
    }

    /**
     * Handle the User "updated" event.
     */
    public function updated(User $user): void
    {
        // Get changes excluding the 'updated_at' field
        $changes = $user->getChanges();
        unset($changes['updated_at']);

        if (!empty($changes)) {
            $this->recordHistory($user, 'updated', $changes);
        }
    }

    /**
     * Handle the User "deleted" event.
     */
    public function deleted(User $user): void
    {
        $this->recordHistory($user, 'deleted', null);
    }

    /**
     * Helper method to insert into user_histories
     */
    protected function recordHistory(User $user, string $action, ?array $changes): void
    {
        UserHistory::create([
            'actor_id' => Auth::id(), // This will be null if run from CLI (e.g. seeders)
            'target_user_id' => $user->id,
            'action' => $action,
            'changes' => $changes,
        ]);
    }
}
