# LinkStack page: Glia (recreation of linktr.ee/glia_international)

Live at **https://link.glia.org** (also `/@glia_international`).
Source of truth copied from the Linktree page on 2026-11-24.

## Mapping

| Linktree | LinkStack |
|---|---|
| `pageTitle: Glia` | user `Glia` (`users.name`), handle `glia_international` |
| description | `users.littlelink_description` |
| profile picture (1080x1080 from ugc.linktr.ee) | `assets/img/2.jpeg` (user id 2) |
| 6 social icons (`socialLinksPosition: TOP`) | links with `button_id=94` ("icon") + FA brand name in `title` — rendered by `linkstack.elements.icons` |
| 4 classic links | `button_id` = `custom_website` (uses the destination's favicon) |
| theme: bg `#41e6db`, button `#042E38`, text `#FFF` | custom theme `themes/glia` |

Order: icon links keep `order` 0-5, content links 10-13 (icons render separately at the top).

## Theme

`/htdocs/themes/glia/` on the PVC:
- `config.php` — custom-code enabled, default brand buttons
- `skeleton-auto.css`, `share.button.css` — copies of the stock stylesheets
- `extra/custom-head.blade.php` — the brand overrides (colours, icon row, footer)

Custom-code injection requires `ALLOW_CUSTOM_CODE_IN_THEMES="true"` in `/htdocs/.env`.
Set `users.theme='glia'` for user 2 to activate it.

## Team access

Several people can edit this page: see `docs/ldap-and-teams.md`. Editors are
added in **Studio → Team** (an admin can open the Glia page from "All pages
(admin)"), then each editor clicks "Edit this page" and works under the owner
account until "Return to my account". They cannot change the account, the page
handle, or the team list.

## Editing

Everything except the CSS is editable in the dashboard (Admin > Users > Glia, and
Admin > Links). The CSS lives in the theme files above; edit those directly and run
`php83 /htdocs/artisan view:clear` in the pod.

Notes:
- User 2 has no password, so it cannot log in — it's a managed page, not an account.
  To let Glia staff edit it, give it a password in the dashboard.
- `HOME_URL="glia_international"` makes the org page the site root. Tarek's own page
  stayed at `/@tarek` and was not touched.
- The "Powered by LinkStack" badge is LinkStack's own footer credit (AGPL project).
