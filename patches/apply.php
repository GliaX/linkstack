<?php
/**
 * Idempotent patcher for LinkStack (LDAP login + page teams).
 * Run inside the linkstack pod:  php83 /tmp/patches/apply.php
 * Re-run after every LinkStack upgrade (the updater replaces app/ and resources/).
 */
$root = '/htdocs';
$backup = '/tmp/lnk-patch-backup';
@mkdir($backup, 0775, true);

$log = [];

function patch_file(string $path, string $marker, callable $apply, string $backupDir, string $label): string
{
    if (!file_exists($path)) {
        return "MISSING $label ($path)";
    }
    $src = file_get_contents($path);
    if (str_contains($src, $marker)) {
        return "skip  $label (already patched)";
    }
    $new = $apply($src);
    if ($new === null) {
        return "FAIL  $label (anchor not found)";
    }
    @copy($path, $backupDir . '/' . basename($path) . '.' . date('YmdHis'));
    file_put_contents($path, $new);
    return "patch $label";
}

// 1. new files
$files = [
    'LdapAuth.php'        => "$root/app/LdapAuth.php",
    'TeamController.php'  => "$root/app/Http/Controllers/TeamController.php",
    'TeamGuard.php'       => "$root/app/Http/Middleware/TeamGuard.php",
    'team.blade.php'      => "$root/resources/views/studio/team.blade.php",
];
foreach ($files as $srcName => $dest) {
    $src = __DIR__ . '/' . $srcName;
    if (!file_exists($src)) { $log[] = "MISSING source $srcName"; continue; }
    @mkdir(dirname($dest), 0775, true);
    copy($src, $dest);
    $log[] = "copy  " . basename($dest);
}

// 2. LoginRequest: fall back to LDAP when the local password check fails
$log[] = patch_file("$root/app/Http/Requests/Auth/LoginRequest.php", 'LdapAuth::login', function ($s) {
    $needle = "if (! Auth::attempt(['email' => \$this->email, 'password' => \$this->password, 'block' => 'no'] , \$this->filled('remember'))) {";
    $replacement = "if (! Auth::attempt(['email' => \$this->email, 'password' => \$this->password, 'block' => 'no'], \$this->filled('remember')) && ! \\App\\LdapAuth::login(\$this->email, \$this->password, \$this->filled('remember'))) {";
    if (str_contains($s, $needle)) {
        return str_replace($needle, $replacement, $s);
    }
    return preg_replace(
        "/if \(! Auth::attempt\(\[[^\]]*?'block' => 'no'\][^)]*\)\) \{/",
        $replacement,
        $s,
        1
    );
}, $backup, 'LoginRequest.php');

// 3. routes: team endpoints
$log[] = patch_file("$root/routes/web.php", 'teamIndex', function ($s) {
    $anchor = "Route::get('/dashboard', [AdminController::class, 'index'])->name('panelIndex');";
    if (!str_contains($s, $anchor)) { return null; }
    $insert = $anchor . "

/* page teams (local patch) */
Route::get('/studio/team', [\\App\\Http\\Controllers\\TeamController::class, 'index'])->name('teamIndex');
Route::post('/studio/team/add', [\\App\\Http\\Controllers\\TeamController::class, 'add'])->name('teamAdd');
Route::post('/studio/team/remove', [\\App\\Http\\Controllers\\TeamController::class, 'remove'])->name('teamRemove');
Route::post('/team/act-as/{id}', [\\App\\Http\\Controllers\\TeamController::class, 'actAs'])->name('teamActAs');
Route::post('/team/return', [\\App\\Http\\Controllers\\TeamController::class, 'returnToSelf'])->name('teamReturn');";
    return str_replace($anchor, $insert, $s);
}, $backup, 'routes/web.php');

// 4. Kernel: register TeamGuard in the web group
$log[] = patch_file("$root/app/Http/Kernel.php", 'TeamGuard', function ($s) {
    $needle = "\\Illuminate\\Routing\\Middleware\\SubstituteBindings::class,\n        ],\n\n        'api'";
    $replacement = "\\Illuminate\\Routing\\Middleware\\SubstituteBindings::class,\n            \\App\\Http\\Middleware\\TeamGuard::class,\n        ],\n\n        'api'";
    if (str_contains($s, $needle)) { return str_replace($needle, $replacement, $s); }
    return preg_replace(
        "/\\\\Illuminate\\\\Routing\\\\Middleware\\\\SubstituteBindings::class,(\s*\],\s*'api')/",
        "\\\\Illuminate\\\\Routing\\\\Middleware\\\\SubstituteBindings::class,\n            \\\\App\\\\Http\\\\Middleware\\\\TeamGuard::class,\1",
        $s, 1
    );
}, $backup, 'Kernel.php');

// 5. sidebar: nav entry + "return" banner
$log[] = patch_file("$root/resources/views/layouts/sidebar.blade.php", 'studio/team', function ($s) {
    $navAnchor = "                    @if(auth()->user()->role == 'admin')";
    if (!str_contains($s, $navAnchor)) { return null; }
    $nav = "                    <li class=\"nav-item\">
                        <a class=\"nav-link {{ Request::segment(2) == 'team' ? 'active' : ''}}\" href=\"{{ url('/studio/team') }}\">
                            <i class=\"icon\"><span class=\"bi bi-people\" style=\"font-size:17px;line-height:20px;\"></span></i>
                            <span class=\"item-name\">Team</span>
                        </a>
                    </li>
" . $navAnchor;

    return str_replace($navAnchor, $nav, $s);
}, $backup, 'sidebar.blade.php (nav)');

$log[] = patch_file("$root/resources/views/layouts/sidebar.blade.php", 'team-banner', function ($s) {
    if (!preg_match('/<body[^>]*>/', $s, $m)) { return null; }
    $banner = $m[0] . "
@if(session('team_acting_as')){{-- team-banner --}}
<div style=\"position:fixed;top:0;left:0;right:0;z-index:2050;background:#042E38;color:#fff;padding:9px 16px;display:flex;justify-content:space-between;align-items:center;font-size:14px;\">
    <span>Editing {{ optional(App\\Models\\User::find(session('team_acting_as')))->name }}'s page as a team member</span>
    <form action=\"{{ url('/team/return') }}\" method=\"POST\" style=\"margin:0;\">@csrf
        <button style=\"background:#41e6db;border:0;border-radius:6px;padding:4px 10px;font-weight:600;cursor:pointer;\">Return to my account</button>
    </form>
</div>
@endif";
    return str_replace($m[0], $banner, $s);
}, $backup, 'sidebar.blade.php (banner)');

$log[] = patch_file("$root/resources/views/layouts/sidebar.blade.php", 'team-hide-settings', function ($s) {
    $needle = "<li><a class=\"dropdown-item\" href=\"{{ url('/studio/profile') }}\"><i class=\"bi bi-gear-fill\"></i> {{__('messages.Settings')}}</a></li>";
    if (!str_contains($s, $needle)) { return null; }
    $wrapped = "@unless(session('team_acting_as')){{-- team-hide-settings --}}\n                    " . $needle . "\n                    @endunless";
    return str_replace($needle, $wrapped, $s);
}, $backup, 'sidebar.blade.php (settings link)');

// 6. table
try {
    $db = new PDO("sqlite:$root/database/database.sqlite");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE IF NOT EXISTS page_teams (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        owner_id INTEGER NOT NULL,
        member_id INTEGER NOT NULL,
        created_at DATETIME, updated_at DATETIME,
        UNIQUE(owner_id, member_id)
    )');
    $log[] = "table page_teams ready";
} catch (Throwable $e) {
    $log[] = "FAIL table: " . $e->getMessage();
}

echo implode("\n", $log), "\n";
