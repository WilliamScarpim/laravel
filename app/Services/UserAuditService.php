<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserAuditLog;

class UserAuditService
{
    public function record(User $user, array $before, ?User $actor = null): UserAuditLog
    {
        $after = $user->only(array_keys($before));
        $changes = [];

        foreach ($before as $key => $value) {
            if (($after[$key] ?? null) !== $value) {
                $changes[] = [
                    'field' => $key,
                    'before' => $value,
                    'after' => $after[$key] ?? null,
                ];
            }
        }

        return UserAuditLog::create([
            'user_id' => $user->id,
            'actor_id' => $actor?->id,
            'changes' => $changes,
        ]);
    }
}
