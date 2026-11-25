<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Page teams: a page owner can add other accounts as editors of their page.
 * Editing happens by switching the session to the owner's account (LinkStack's
 * studio is hard-wired to Auth::id()), with the original identity kept in the
 * session so it can be restored. TeamGuard blocks account-level changes while
 * acting, so an editor cannot take over the owner's login.
 */
class TeamController extends Controller
{
    /** Table is created on demand so upgrades/migrations cannot drop the feature. */
    private function ensureTable(): void
    {
        DB::statement('CREATE TABLE IF NOT EXISTS page_teams (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_id INTEGER NOT NULL,
            member_id INTEGER NOT NULL,
            created_at DATETIME,
            updated_at DATETIME,
            UNIQUE(owner_id, member_id)
        )');
    }

    public function index()
    {
        $this->ensureTable();

        $owner = Auth::user();
        $actingAs = session('team_acting_as') ? User::find(session('team_acting_as')) : null;

        $members = DB::table('page_teams')
            ->join('users', 'users.id', '=', 'page_teams.member_id')
            ->where('page_teams.owner_id', $owner->id)
            ->orderBy('users.name')
            ->select('users.id', 'users.name', 'users.email', 'users.littlelink_name')
            ->get();

        $editable = DB::table('page_teams')
            ->join('users', 'users.id', '=', 'page_teams.owner_id')
            ->where('page_teams.member_id', $owner->id)
            ->orderBy('users.name')
            ->select('users.id', 'users.name', 'users.email', 'users.littlelink_name')
            ->get();

        // Admins are the only accounts that can log in on a fresh install, so they
        // need a way to open any page and seed its team.
        $allPages = $owner->role === 'admin'
            ? User::where('id', '!=', $owner->id)->orderBy('name')->select('id', 'name', 'littlelink_name')->get()
            : collect();

        return view('studio.team', compact('owner', 'members', 'editable', 'actingAs', 'allPages'));
    }

    public function add(Request $request)
    {
        $this->ensureTable();
        $request->validate(['email' => 'required|email']);

        $owner = Auth::user();
        $member = User::where('email', $request->email)->first();

        if (!$member) {
            return redirect('/studio/team')->with('team_error', 'No account with that email. LDAP users get one on first login.');
        }
        if ($member->id === $owner->id) {
            return redirect('/studio/team')->with('team_error', 'That is your own account.');
        }

        DB::table('page_teams')->insertOrIgnore([
            'owner_id' => $owner->id,
            'member_id' => $member->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect('/studio/team')->with('team_status', $member->name . ' can now edit this page.');
    }

    public function remove(Request $request)
    {
        $this->ensureTable();
        $request->validate(['member_id' => 'required|integer']);

        DB::table('page_teams')
            ->where('owner_id', Auth::id())
            ->where('member_id', (int) $request->member_id)
            ->delete();

        return redirect('/studio/team')->with('team_status', 'Removed.');
    }

    public function actAs(Request $request, $id)
    {
        $this->ensureTable();

        $owner = User::findOrFail($id);
        $me = Auth::user();

        abort_if($me->id === (int) $owner->id, 403);

        $isMember = DB::table('page_teams')
            ->where('owner_id', $owner->id)
            ->where('member_id', $me->id)
            ->exists();

        abort_unless($isMember || $me->role === 'admin', 403);

        $returnTo = $me->id;
        Auth::loginUsingId($owner->id);
        $request->session()->put('team_acting_as', (int) $owner->id);
        $request->session()->put('team_return_to', (int) $returnTo);

        return redirect('/dashboard');
    }

    public function returnToSelf(Request $request)
    {
        $back = $request->session()->get('team_return_to');
        $user = $back ? User::find($back) : null;

        abort_unless($user, 403);

        Auth::loginUsingId($user->id);
        $request->session()->forget(['team_acting_as', 'team_return_to']);

        return redirect('/studio/team');
    }
}
