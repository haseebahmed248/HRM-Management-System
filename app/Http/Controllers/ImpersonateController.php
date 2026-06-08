<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ImpersonateController extends Controller
{
    public function start(Request $request, $userId)
    {
        /** @var User|null $impersonator */
        $impersonator = auth()->user();

        abort_unless(
            $impersonator && $impersonator->canImpersonate(),
            403,
            __('You do not have permission to impersonate other users.')
        );

        $target = User::findOrFail($userId);

        abort_unless(
            $target->canBeImpersonated(),
            403,
            __('This user cannot be impersonated.')
        );

        abort_if(
            (int) $target->id === (int) $impersonator->id,
            422,
            __('You cannot impersonate yourself.')
        );

        Log::info('Impersonation started', [
            'impersonator_id' => $impersonator->id,
            'impersonator_email' => $impersonator->email,
            'target_id' => $target->id,
            'target_email' => $target->email,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'at' => now()->toIso8601String(),
        ]);

        // Delegate to the package: it uses quietLogin/quietLogout (no session
        // regenerate) so the impersonator session keys actually survive the
        // auth swap. Manually doing session()->put() + Auth::login() lost
        // them because Auth::login calls session->migrate(true).
        if (! $impersonator->impersonate($target)) {
            abort(500, __('Could not start impersonation.'));
        }

        return redirect('/dashboard')->with('success', __('Now impersonating :name', ['name' => $target->name]));
    }

    public function leave(Request $request)
    {
        /** @var User|null $current */
        $current = auth()->user();

        abort_unless(
            $current && $current->isImpersonated(),
            403,
            __('No active impersonation session to leave.')
        );

        Log::info('Impersonation ended', [
            'impersonator_id' => session('impersonated_by'),
            'was_impersonating' => $current->id,
            'ip' => $request->ip(),
            'at' => now()->toIso8601String(),
        ]);

        $current->leaveImpersonation();

        return redirect('/companies')->with('success', __('Returned to admin panel'));
    }
}
