# Releasing Lexa Search

Updates are delivered from the **public** GitHub repo
[`NguyenThong251/lexa-search`](https://github.com/NguyenThong251/lexa-search)
via [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker)
v5.7, vendored at `plugin-update-checker/`. A new GitHub Release makes the normal
*"There is a new version available… update now"* notice appear on every site that
has the plugin installed.

---

## Setup on each site

**Nothing.** The repo is public, so update checks work as soon as the plugin is
installed — no token, no configuration.

A GitHub token is only worth adding on a host that exhausts GitHub's
60-requests-per-hour unauthenticated limit (shared hosting behind a busy NAT).
If you need one, put it in `wp-config.php` — never in the plugin, where it would
be committed to git and copied into every backup:

```php
define('LEXA_GITHUB_TOKEN', '<token>');
```

A read-only, fine-grained token is enough; the classic `repo` scope would grant
read *and write* to every private repo on the account.

> **Do not commit internal planning to this repo.** It is public. `PLAN.md` is
> in `.gitignore` for that reason — it carries commercial positioning and the
> Pro roadmap, and it stays local only.

---

## Releasing a new version

```bash
# 1. Bump the version. The "Version:" header in lexa-search.php is the ONLY one
#    WordPress and PUC read — keep LEXA_VERSION in sync (the script enforces it).
#      * Version:           0.4.0
#      define('LEXA_VERSION', '0.4.0');

# 2. Pre-flight: version consistency, wiring, syntax, no leaked token
bash bin/release.sh check

# 3. Commit and push
git commit -am "Release 0.4.0" && git push

# 4. Cut the release — this is what makes the update appear
gh release create v0.4.0 --repo NguyenThong251/lexa-search \
    --title "v0.4.0" --generate-notes
```

That's it. No zip to build or upload: PUC downloads GitHub's auto-generated
source archive, and `.gitattributes` (`export-ignore`) keeps `tests/`, `bin/`
and the docs out of it. PUC renames the extracted folder back to `lexa-search`
automatically (`UpdateChecker::fixDirectoryName`).

To see it immediately on a site instead of waiting for the periodic check:
**Plugins → Lexa Search → "Check for updates"** (PUC adds that link).

### Hand-installable zip

For a site that can't reach GitHub, or a first install:

```bash
bash bin/release.sh zip     # -> dist/lexa-search-0.4.0.zip
```

---

## Ways this breaks silently

Every item here produces **no error and no notice** — the site simply looks like
it is up to date. Check them in this order when an expected update doesn't show.

| Cause | Why | Fix |
|---|---|---|
| `Version:` header not bumped | PUC reads the header from the tagged commit and **ignores the tag name**. `version_compare('0.3.0','0.3.0','>')` is false. | Bump the header, re-tag |
| Only `LEXA_VERSION` bumped | Neither WordPress nor PUC ever reads that constant | Bump the header too — `bin/release.sh check` catches this |
| Repo made private again, renamed, or a wrong/expired `LEXA_GITHUB_TOKEN` is set | GitHub answers **404, not 403**, for a repo it will not show you — so an auth problem is indistinguishable from "no such repo" | Confirm the repo is public: `curl -s -o /dev/null -w '%{http_code}\n' https://api.github.com/repos/NguyenThong251/lexa-search/releases/latest` must print 200 with no credentials. Then check `wp-content/debug.log` for `[lexa-search] update check failed` |
| GitHub rate limit reached (60/hour unauthenticated, shared per server IP) | API returns 403 | Set `LEXA_GITHUB_TOKEN` in `wp-config.php` |
| Plugin files nested in a subfolder in the repo | PUC fetches `lexa-search.php` from the **repo root** | Keep the plugin at the repo root |
| URL written as `…/lexa-search/tree/main` or `www.github.com/…` | PUC's service detection needs exactly two path segments on host `github.com`; otherwise it falls back to a JSON checker | Use `https://github.com/NguyenThong251/lexa-search/` |
| URL written with a `.git` suffix | Accepted as a repo name, so the API calls 404 | Drop the `.git` |
| `setBranch('main')` removed | PUC defaults to `master`, which doesn't exist here | Keep `setBranch('main')` |

Failures *are* logged — `lexa-search.php` hooks `puc_api_error` and writes to the
PHP error log. Enable `WP_DEBUG_LOG` to capture them.

## Notes

- On `main`, PUC tries the **latest Release** first, then the highest tag, then
  the branch head. Once a Release exists it always wins, so pushing to `main`
  without cutting a release never ships anything.
- Update checks run on `admin_init` as well as WP-Cron, so they work even though
  quocduy.com.vn sets `DISABLE_WP_CRON = true`.
- The token is only ever sent as an in-memory `Authorization` header. PUC does
  not persist it — nothing lands in the options table or a DB dump.
- Tags: use plain `vX.Y.Z`. PUC strips leading `v` characters with `ltrim`, so
  `vv1.0` would become `1.0`.
