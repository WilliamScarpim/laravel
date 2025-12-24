<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserActivationToken;
use App\Notifications\AccountActivationNotification;
use Illuminate\Support\Str;

class AccountActivationService
{
    public function create(User $user): UserActivationToken
    {
        $token = Str::uuid()->toString();

        UserActivationToken::where('user_id', $user->id)->delete();

        return UserActivationToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDays(2),
        ]);
    }

    public function send(User $user): UserActivationToken
    {
        $token = $this->create($user);
        $user->notify(new AccountActivationNotification($token->token, $user->name));

        return $token;
    }
}
