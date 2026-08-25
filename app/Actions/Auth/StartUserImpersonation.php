<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

class StartUserImpersonation
{
    public function handle(User $administrator, User $target): void
    {
        abort_unless(app()->environment(['local', 'testing']), 404);
        abort_unless($administrator->isAdmin(), 403);
        abort_unless($administrator->company_id === $target->company_id, 404);
        abort_if(session()->has('impersonator_user_id'), 409);

        session()->put([
            'impersonator_user_id' => $administrator->id,
            'impersonator_name' => $administrator->name,
        ]);

        Auth::login($target);
        session()->regenerate();
    }
}
