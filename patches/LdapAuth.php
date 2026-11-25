<?php

namespace App;

use App\Models\User;

/**
 * LDAP authentication against auth.glia.org.
 *
 * Lookup uses an anonymous bind (the directory permits it for user entries), then
 * the user's own credentials are verified by binding as their DN. No service
 * account password is stored anywhere.
 */
class LdapAuth
{
    public static function enabled(): bool
    {
        return filter_var(env('LDAP_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function host(): string
    {
        return env('LDAP_HOST', 'auth.glia.org');
    }

    public static function port(): int
    {
        return (int) env('LDAP_PORT', 636);
    }

    public static function baseDn(): string
    {
        return env('LDAP_BASE_DN', 'dc=emlondon,dc=ca');
    }

    private static function connect()
    {
        if (!function_exists('ldap_connect')) {
            return null;
        }

        $uri = (self::port() === 636 ? 'ldaps://' : 'ldap://') . self::host() . ':' . self::port();
        $conn = ldap_connect($uri);
        if (!$conn) {
            return null;
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);

        if (!filter_var(env('LDAP_TLS_VERIFY', 'true'), FILTER_VALIDATE_BOOLEAN)) {
            ldap_set_option($conn, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
        } else {
            ldap_set_option($conn, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND);
            $ca = env('LDAP_CA_CERT', '/etc/ssl/certs/ca-certificates.crt');
            if (is_readable($ca)) {
                ldap_set_option($conn, LDAP_OPT_X_TLS_CACERTFILE, $ca);
            }
        }

        return $conn;
    }

    /**
     * Verify credentials. Returns the entry attributes on success, null otherwise.
     */
    public static function attempt(string $login, string $password): ?array
    {
        if (!self::enabled() || $login === '' || $password === '') {
            return null;
        }

        $conn = self::connect();
        if (!$conn) {
            return null;
        }

        // Anonymous lookup so the directory's user entries can be found by mail or uid.
        if (!@ldap_bind($conn)) {
            return null;
        }

        $login = ldap_escape($login, '', LDAP_ESCAPE_FILTER);
        $filter = "(|(mail=$login)(uid=$login))";
        $attrs = ['uid', 'mail', 'cn', 'displayName'];

        $search = @ldap_search($conn, self::baseDn(), $filter, $attrs, 0, 2);
        if (!$search) {
            return null;
        }

        $entries = @ldap_get_entries($conn, $search);
        if (!$entries || $entries['count'] < 1) {
            return null;
        }

        $dn = $entries[0]['dn'];

        // A second bind as the user proves the password. Anonymous state is discarded.
        if (!@ldap_bind($conn, $dn, $password)) {
            return null;
        }

        return [
            'dn' => $dn,
            'uid' => $entries[0]['uid'][0] ?? null,
            'mail' => $entries[0]['mail'][0] ?? null,
            'cn' => $entries[0]['displayName'][0] ?? ($entries[0]['cn'][0] ?? null),
        ];
    }

    /**
     * Find or create the local account backing an LDAP identity.
     */
    public static function provision(array $entry): ?User
    {
        if (empty($entry['mail'])) {
            return null;
        }

        $user = User::where('email', $entry['mail'])->first();
        if ($user) {
            return $user;
        }

        if (!empty($entry['uid'])) {
            $user = User::where('littlelink_name', self::handle($entry['uid']))->first();
            if ($user) {
                return $user;
            }
        }

        return User::create([
            'name' => $entry['cn'] ?: $entry['uid'],
            'email' => $entry['mail'],
            'email_verified_at' => now(),
            'password' => null,
            'littlelink_name' => self::handle($entry['uid'] ?: $entry['mail']),
            'littlelink_description' => '',
            'role' => 'user',
            'block' => 'no',
            'image' => json_encode(['checkmark' => true, 'disable-sharebtn' => false, 'links-new-tab' => true]),
        ]);
    }

    /**
     * Handle for the public page URL; must be unique.
     */
    public static function handle(string $raw): string
    {
        $base = preg_replace('/[^A-Za-z0-9._-]/', '', strtolower(explode('@', $raw)[0]));
        if ($base === '') {
            $base = 'user';
        }

        $candidate = $base;
        $i = 1;
        while (User::where('littlelink_name', $candidate)->exists()) {
            $candidate = $base . $i++;
            if ($i > 50) {
                $candidate = $base . bin2hex(random_bytes(3));
                break;
            }
        }

        return $candidate;
    }

    /**
     * Authenticate an LDAP identity and start the session for its local account.
     * Called from LoginRequest after the local password check fails.
     */
    public static function login(string $email, string $password, bool $remember = false): bool
    {
        $entry = self::attempt($email, $password);
        if (!$entry) {
            return false;
        }

        $user = self::provision($entry);
        if (!$user || $user->block === 'yes') {
            return false;
        }

        \Illuminate\Support\Facades\Auth::login($user, $remember);

        return true;
    }
}
