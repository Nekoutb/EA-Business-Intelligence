<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Artisan;

/**
 * Deploy helper for shared hosting (Hostinger).
 *
 * The operator deploys by pulling via hPanel's Git section — but the
 * artisan cache clears still needed an SSH session, which Windows
 * PowerShell + shared hosting make painful. This page replaces that:
 * one admin-gated button runs every cache clear from the browser.
 *
 * Flow after merging to master:
 *   1. hPanel → Git → Deploy (or the GitHub auto-deploy webhook fires)
 *   2. /admin/maintenance → "Clear caches"  → new code + fresh data live
 *
 * Also shows WHICH commit is deployed (parsed from .git without exec(),
 * which shared hosts usually disable) so "did the pull actually run?"
 * is answerable at a glance.
 */
class MaintenanceController extends Controller
{
    public function index()
    {
        return view('admin.maintenance', [
            'version' => $this->deployedVersion(),
            'active'  => 'maintenance',
        ]);
    }

    public function clearCaches()
    {
        // view → route → config → cache. Route clear also deletes the
        // stubborn bootstrap/cache/routes-v7.php that has eaten several
        // post-deploy 404s on this host.
        $results = [];
        foreach (['view:clear', 'route:clear', 'config:clear', 'cache:clear'] as $cmd) {
            try {
                Artisan::call($cmd);
                $results[] = "{$cmd} ✓";
            } catch (\Throwable $e) {
                $results[] = "{$cmd} ✗ (" . ea_safe_error($e, 'admin_maintenance', 'failed') . ')';
            }
        }

        return redirect('/admin/maintenance')->with('flash', [
            'type' => str_contains(implode(' ', $results), '✗') ? 'error' : 'success',
            'msg'  => 'Caches cleared: ' . implode(' · ', $results),
        ]);
    }

    /**
     * Read the deployed commit straight from .git metadata — no exec(),
     * which shared hosts commonly disable. The repo root is the parent
     * of the Laravel app directory (repo layout: <root>/laravel-app).
     *
     * @return array{hash: string, branch: ?string, pulled_at: ?string}|null
     */
    private function deployedVersion(): ?array
    {
        try {
            $gitDir = dirname(base_path()) . DIRECTORY_SEPARATOR . '.git';
            $headFile = $gitDir . DIRECTORY_SEPARATOR . 'HEAD';
            if (!is_readable($headFile)) return null;

            $head = trim((string) file_get_contents($headFile));
            $branch = null;
            $hash = null;
            $refTime = null;

            if (str_starts_with($head, 'ref:')) {
                $ref = trim(substr($head, 4));                      // refs/heads/master
                $branch = basename($ref);
                $refFile = $gitDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $ref);
                if (is_readable($refFile)) {
                    $hash = trim((string) file_get_contents($refFile));
                    $refTime = @filemtime($refFile) ?: null;
                } else {
                    // Packed refs fallback
                    $packed = $gitDir . DIRECTORY_SEPARATOR . 'packed-refs';
                    if (is_readable($packed)) {
                        foreach (explode("\n", (string) file_get_contents($packed)) as $line) {
                            if (str_ends_with(trim($line), $ref)) {
                                $hash = substr(trim($line), 0, 40);
                                break;
                            }
                        }
                    }
                    $refTime = @filemtime($packed ?? $headFile) ?: null;
                }
            } else {
                $hash = $head;                                      // detached HEAD
                $refTime = @filemtime($headFile) ?: null;
            }

            if (!$hash) return null;
            return [
                'hash'      => substr($hash, 0, 10),
                'branch'    => $branch,
                'pulled_at' => $refTime ? date('d M Y H:i', $refTime) : null,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }
}
