<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class CheckInstallation
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $isInstallRoute = $request->is('install') || $request->is('install/*') || $request->is('installer/*');

        // Hard-block the installer in production once the app is already installed.
        // Belt-and-braces in case rachidlaasri/laravel-installer's own guards
        // misbehave or are misconfigured. /update routes are NOT blocked here
        // because authenticated admins legitimately use them to run migrations.
        if ($isInstallRoute && $this->isInstalled() && ! app()->environment(['local', 'testing'])) {
            abort(404);
        }

        // Skip check for installer routes, API routes, and static assets
        if (
            $isInstallRoute ||
            $request->is('update/*') ||
            $request->is('css/*') ||
            $request->is('js/*') ||
            $request->is('images/*')
        ) {
            return $next($request);
        }

        // Check only on dashboard, login, register routes
        if (!$request->is('/*') && !$request->is('dashboard*') && !$request->is('login') && !$request->is('register')) {
            return $next($request);
        }

        // If not installed, redirect to /install
        if (!$this->isInstalled()) {
            return redirect('/install');
        }

        // Auto-redirect to /update on migration drift is intentionally disabled.
        // On Infratel shared hosting there is no PHP CLI, so the updater wizard
        // cannot run artisan migrate — it just 500s. Worse, a single orphaned
        // row in the migrations table (e.g. a previous developer's migration
        // file that's since been removed) is enough to lock every admin out of
        // every page. Migrations on this deployment are applied via phpMyAdmin
        // SQL by the dev team.

        return $next($request);
    }

    /**
     * Check if application is installed
     */
    private function isInstalled(): bool
    {
        return file_exists(storage_path('installed'));
    }

    /**
     * Check if migrations are needed. Mirrors the laravel-installer's own
     * `alreadyUpdated()` check (count of files vs count of rows) so the two
     * stages of the flow never disagree. Previously a substring match on
     * `migrate:status` output (and a more elaborate name-diff) gave false
     * positives — sending admins to /update, which then 404'd because the
     * installer correctly saw nothing to do.
     */
    private function needsMigration(): bool
    {
        try {
            $fileCount = count(glob(database_path('migrations/*.php')));
            $ranCount = DB::table('migrations')->count();

            return $fileCount !== $ranCount;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
