<?php
/**
 * Super admin accounts of one BMS site, for the console (ticket T10).
 *
 *   php superadmin.php <laravel app dir> list
 *   php superadmin.php <laravel app dir> set-password <user id>   (new password on stdin)
 *
 * Run by bms_console.py as www-data, inside the site's own app, so the
 * password is hashed exactly as the app hashes it (the User model's
 * 'hashed' cast). Prints one JSON object. Never prints a password or hash.
 * Only accounts whose role is super_admin can be changed.
 */

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;

function out(array $data, int $code = 0): never
{
    echo json_encode($data, JSON_UNESCAPED_SLASHES), "\n";
    exit($code);
}

[$self, $base, $cmd] = array_pad($argv, 3, null);
if (!$base || !is_file("$base/artisan")) out(['error' => 'not a Laravel app: ' . $base], 2);

require "$base/vendor/autoload.php";
$app = require "$base/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if ($cmd === 'list') {
    $rows = User::query()
        ->where('role', User::ROLE_SUPER_ADMIN)
        ->orderBy('id')
        ->get();
    out(['accounts' => $rows->map(fn (User $u) => [
        'id'             => $u->id,
        'name'           => $u->name,
        'email'          => $u->email,
        'status'         => $u->status,
        // AdminAuthController: no tenant, role super_admin, status active
        'can_sign_in'    => $u->tenant_id === null && $u->status === User::STATUS_ACTIVE,
        'last_login_at'  => optional($u->last_login_at)->toDateTimeString(),
        'last_login_ip'  => $u->last_login_ip,
        'default_password' => Hash::check('changeme', (string) $u->getAuthPassword()),
        'updated_at'     => optional($u->updated_at)->toDateTimeString(),
    ])->values()]);
}

if ($cmd === 'set-password') {
    $id = $argv[3] ?? '';
    if (!ctype_digit((string) $id)) out(['error' => 'bad id'], 2);
    $password = rtrim((string) fgets(STDIN), "\r\n");
    if (strlen($password) < 12 || strlen($password) > 128) out(['error' => 'password must be 12 to 128 characters'], 2);
    $u = User::find((int) $id);
    if (!$u) out(['error' => 'no such account'], 3);
    if ($u->role !== User::ROLE_SUPER_ADMIN) out(['error' => 'refused: not a super admin'], 3);
    $u->password = $password;      // hashed by the model's 'hashed' cast
    $u->save();
    if (!Hash::check($password, (string) $u->fresh()->getAuthPassword())) out(['error' => 'saved, but the check failed'], 4);
    out(['ok' => true, 'email' => $u->email]);
}

out(['error' => 'usage: list | set-password <id>'], 2);
