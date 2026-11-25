# LinkStack: LDAP login + page teams

Two local features on top of upstream LinkStack 4.8.8 at https://link.glia.org.
Both are patches to the app on the PVC — **not** part of upstream, and **not**
survived by LinkStack's own updater. See "After an upgrade" below.

Source of truth for the deployment: `deploy/linkstack-cluster.yaml`.

## 1. LDAP login

Users sign in with their **LDAP email address** and LDAP password. A local
account is created on first login (`role=user`, `password=NULL`, handle derived
from `uid`), so the account then appears in the admin panel like any other.

- Directory: `ldaps://auth.glia.org:636`, base `dc=emlondon,dc=ca`
- Lookup is an **anonymous** search for `(|(mail=...)(uid=...))`, then a bind as
  that user's DN to prove the password. No service account password is stored.
- TLS is verified against the Let's Encrypt cert (`LDAP_TLS_VERIFY=true`).
- Local password login still works and is tried first (that is how the admin
  account signs in).
- Blocked accounts are refused even if LDAP accepts the password.

Environment (in `linkstack-cluster.yaml`):

| Variable | Value |
|---|---|
| `LDAP_ENABLED` | `true` |
| `LDAP_HOST` | `auth.glia.org` |
| `LDAP_PORT` | `636` |
| `LDAP_BASE_DN` | `dc=emlondon,dc=ca` |
| `LDAP_TLS_VERIFY` | `true` |

The stock image has no `php83-ldap`, so a derived image is used:
`ghcr.io/gliax/linkstack:v4.8.8-ldap` (built from `image/Dockerfile`; the `ghcr-pull`
pull secret is copied into the `linkstack` namespace).

## 2. Page teams

One page, several editors. The owner (or an admin) adds editors by email; an
editor opens **Studio → Team**, clicks "Edit this page", and gets the full studio
for that page until they click the banner's "Return to my account".

How it works: LinkStack's studio is hard-wired to `Auth::id()`, so a team edit
switches the session to the owner's account and keeps the editor's own id in
`team_acting_as` / `team_return_to`. `TeamGuard` then refuses anything that would
take over the owner's account:

- **Blocked while acting:** `/studio/profile` (email, password, delete account),
  `/studio/page` (page handle/URL, display name, avatar), team add/remove,
  `/admin/*`. GETs redirect to the dashboard; POSTs return 403.
- **Allowed while acting:** links (add/edit/delete/reorder), theme, share button.

Table (created by the patcher, survives upgrades since it lives in the DB):

```sql
page_teams(id, owner_id, member_id, created_at, updated_at, UNIQUE(owner_id, member_id))
```

### Seeding a team today

The Glia page owner has no login password (it is a managed page), so the team is
seeded by an admin:

1. Sign in as an admin → **Studio → Team**
2. Under "All pages (admin)" → **Manage this page** next to *Glia*
3. Add each editor by their LDAP email — it must match an account that has
   logged in at least once (LDAP provisions the account on first login)
4. **Return to my account**

Editors then see the page under "Pages you can edit".

## Files touched

| File | Change |
|---|---|
| `app/LdapAuth.php` | new — bind/lookup/auto-provision |
| `app/Http/Requests/Auth/LoginRequest.php` | one condition: local auth **or** LDAP |
| `app/Http/Controllers/TeamController.php` | new — team CRUD + act-as/return |
| `app/Http/Middleware/TeamGuard.php` | new — account-takeover guard |
| `app/Http/Kernel.php` | registers `TeamGuard` in the `web` group |
| `routes/web.php` | 5 team routes |
| `resources/views/studio/team.blade.php` | new — team screen |
| `resources/views/layouts/sidebar.blade.php` | Team nav item, acting banner, hides Settings while acting |

Backups of each patched file are written to `/tmp/lnk-patch-backup/` in the pod.

## After an upgrade

LinkStack's built-in updater replaces `app/` and `resources/`, so both features
vanish while the DB tables and env vars remain. Re-apply:

```bash
./scripts/reapply-patches.sh
```

`apply.php` is idempotent (each step has a marker) and safe to re-run. Check that
the new upstream version still contains the anchors it patches — `apply.php`
reports `FAIL ... (anchor not found)` instead of silently doing nothing.

If upstream bumps the app version, rebuild the LDAP image against the new tag and
update `linkstack-cluster.yaml`:

```bash
./scripts/rebuild-image.sh vX.Y.Z
```

## Verification recipe

- LDAP: sign in with an LDAP email/password → lands on `/dashboard`; wrong
  password stays on `/login`; a row appears in `users` with `password IS NULL`.
- Teams: as a non-member, `POST /team/act-as/<owner>` → 403. As a member, it
  lands on `/dashboard`; `GET /studio/profile` redirects away; `POST
  /studio/page` → 403; link edits succeed; `POST /team/return` restores identity.

## Limits worth knowing

- **No audit trail.** Editing happens *as* the owner, so the app cannot attribute
  a change to a person. LinkStack has no per-user change log to build on.
- **No per-member permissions.** Every editor of a page can do everything the
  owner can within the allowed surface above.
- **Admin bypasses teams.** Admins can open any page (that is what makes seeding
  possible) — an admin account is a superset of an editor.
- **One page at a time.** Switching pages ends the current editing session.
- **Any directory user can sign in.** There is no LDAP group/OU filter, so every
  user entry that an anonymous search can see may create a LinkStack account
  (a plain `role=user` page of their own). If access should be limited to, say,
  `ou=staff`, add a membership check in `LdapAuth::attempt()` — the filter is a
  single line.
- **Upstream gap.** LinkStack has no team/ACL concept (issue #146 was closed as a
  duplicate of roadmap discussion #49, which never adopted it), so this patch is
  the only way to get it today.
