<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Session key holding the candidate user-row ids after a login where the same
 * email/password is valid for more than one company. The company picker reads it.
 */
const LOGIN_COMPANY_CANDIDATES = 'login.company_candidates';
const LOGIN_REMEMBER           = 'login.remember';

class AuthenticatedSessionController extends Controller
{
    /**
     * Show the login page.
     */
    public function create(Request $request): Response
    {
        $demoBusinesses = [];

        if (config('app.is_demo')) {
            // Get the company user
            $companyUser = \App\Models\User::where('email', 'company@example.com')->first();
        }

        return Inertia::render('auth/login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => $request->session()->get('status'),
            'settings' => settings(),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        try {
            $request->ensureIsNotRateLimited();

            // A person may exist as several user rows (one per company) sharing the
            // same email. Collect every active row whose password matches so we can
            // let them choose which company to enter.
            $candidates = User::where('email', $request->email)->get()
                ->filter(fn ($u) => Hash::check($request->password, $u->password))
                ->values();

            if ($candidates->isEmpty()) {
                RateLimiter::hit($request->throttleKey());
                throw ValidationException::withMessages(['email' => __('auth.failed')]);
            }

            $active = $candidates->filter(fn ($u) => $u->status !== 'inactive')->values();
            if ($active->isEmpty()) {
                throw ValidationException::withMessages([
                    'email' => __('Your account is inactive. Please contact administrator.'),
                ]);
            }

            RateLimiter::clear($request->throttleKey());

            // More than one company → show the company picker (no login yet).
            if ($active->count() > 1) {
                $request->session()->put(LOGIN_COMPANY_CANDIDATES, $active->pluck('id')->all());
                $request->session()->put(LOGIN_REMEMBER, $request->boolean('remember'));
                return redirect()->route('login.company-select');
            }

            Auth::login($active->first(), $request->boolean('remember'));
            $request->session()->regenerate();

            return $this->completeLogin($request);

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Login error: ' . $e->getMessage());
            return back()->withErrors(['email' => $e->getMessage()]);
        }
    }

    /**
     * Show the company chooser after a multi-company login match.
     */
    public function showCompanySelect(Request $request): Response|RedirectResponse
    {
        $ids = $request->session()->get(LOGIN_COMPANY_CANDIDATES, []);
        if (empty($ids)) {
            return redirect()->route('login');
        }

        $companies = User::whereIn('id', $ids)->get()->map(function ($u) {
            $companyId = getCompanyId($u->id) ?? $u->id;
            $company   = User::find($companyId);
            return [
                'user_id'      => $u->id,
                'company_name' => $company?->name ?? __('Company'),
                'role'         => ucfirst($u->type),
            ];
        })->values();

        return Inertia::render('auth/company-select', [
            'companies' => $companies,
            'settings'  => settings(),
        ]);
    }

    /**
     * Complete login as the chosen company user row.
     */
    public function storeCompanySelect(Request $request): RedirectResponse
    {
        $ids = $request->session()->get(LOGIN_COMPANY_CANDIDATES, []);
        if (empty($ids)) {
            return redirect()->route('login');
        }

        $validated = $request->validate([
            'user_id' => 'required|integer',
        ]);

        // The chosen row MUST be one of the verified candidates from this session.
        if (!in_array((int) $validated['user_id'], array_map('intval', $ids), true)) {
            throw ValidationException::withMessages([
                'user_id' => __('Invalid company selection.'),
            ]);
        }

        $user = User::find($validated['user_id']);
        if (!$user || $user->status === 'inactive') {
            throw ValidationException::withMessages([
                'user_id' => __('This account is not available.'),
            ]);
        }

        $remember = (bool) $request->session()->pull(LOGIN_REMEMBER, false);
        $request->session()->forget(LOGIN_COMPANY_CANDIDATES);

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return $this->completeLogin($request);
    }

    /**
     * Post-login bookkeeping (email-verification gate + login history) shared by
     * the direct and company-picker login paths. Assumes the user is already
     * authenticated and the session regenerated.
     */
    private function completeLogin(Request $request): RedirectResponse
    {
        try {
            // Check if email verification is enabled and user is not verified
            $emailVerificationEnabled = getSetting('emailVerification', false);
            if ($emailVerificationEnabled && !$request->user()->hasVerifiedEmail()) {
                return redirect()->route('verification.notice');
            }

            $user = Auth::user();

            // Safely get IP address
            $ip = $request->ip() ?? '127.0.0.1';
            try {
                // Get location data with timeout and error handling
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 5,
                        'ignore_errors' => true
                    ]
                ]);
                $response = @file_get_contents('http://ip-api.com/php/' . $ip, false, $context);
                $query = $response ? @unserialize($response) : [];
                if (!is_array($query)) {
                    $query = [];
                }
            } catch (\Exception $e) {
                $query = [];
            }

            try {
                // Browser detection with error handling
                $userAgent = $request->header('User-Agent', '');
                if (!empty($userAgent)) {
                    $whichbrowser = new \WhichBrowser\Parser($userAgent);
                    // Skip if it's a bot
                    if (isset($whichbrowser->device->type) && $whichbrowser->device->type == 'bot') {
                        return redirect()->intended(route('dashboard', absolute: false));
                    }

                    $query['browser_name'] = $whichbrowser->browser->name ?? null;
                    $query['os_name'] = $whichbrowser->os->name ?? null;
                }
            } catch (\Exception $e) {
                // Continue without browser detection if it fails
            }

            // Get referrer safely
            $referrer = $request->header('Referer') ? parse_url($request->header('Referer')) : null;

            // Set additional details
            $query['browser_language'] = $request->header('Accept-Language') ? mb_substr($request->header('Accept-Language'), 0, 2) : null;
            $query['device_type'] = class_exists('Utility') ? getDeviceType($userAgent) : 'unknown';
            $query['referrer_host'] = !empty($referrer['host']) ? $referrer['host'] : null;
            $query['referrer_path'] = !empty($referrer['path']) ? $referrer['path'] : null;

            // Set timezone safely
            if (isset($query['timezone']) && !empty($query['timezone'])) {
                try {
                    date_default_timezone_set($query['timezone']);
                } catch (\Exception $e) {
                    // Continue with default timezone if setting fails
                }
            }

            // Save login details
            try {

                if (isSaaS()) {
                    if (Auth::user()->hasRole('superadmin')) {
                        $createdBy = Auth::user()->id;
                    } else if (Auth::user()->hasRole('company')) {
                        $createdBy = Auth::user()->created_by;
                    } else {
                        $createdBy = getCompanyId(Auth::user()->id);
                    }
                } else {
                    if (Auth::user()->hasRole('company')) {
                        $createdBy = Auth::user()->id;
                    } else {
                        $createdBy = getCompanyId(Auth::user()->id);
                    }
                }


                $loginDetail = new LoginHistory();
                $loginDetail->user_id = $user->id;
                $loginDetail->ip = $ip;
                $loginDetail->date = now();
                $loginDetail->Details = json_encode($query);
                $loginDetail->created_by = $createdBy;
                $loginDetail->save();

            } catch (\Exception $e) {
                Log::warning('Failed to save login details: ' . $e->getMessage());
            }

            return redirect()->intended(route('dashboard', absolute: false));

        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Login error: ' . $e->getMessage());
            return back()->withErrors(['email' => $e->getMessage()]);
        }
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
