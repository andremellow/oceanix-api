<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

class StopUserImpersonation
{
    public function handle(): User
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $administratorId = session()->get('impersonator_user_id');
        abort_unless(is_numeric($administratorId), 404);

        $administrator = User::query()->findOrFail((int) $administratorId);
        abort_unless($administrator->isAdmin(), 403);

        Auth::login($administrator);
        session()->forget(['impersonator_user_id', 'impersonator_name']);
        session()->regenerate();

        return $administrator;
    }
}
