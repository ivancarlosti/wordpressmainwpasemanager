# Port the "Check for updates" feature and add a GitHub updater

- **Date:** 2026-11-07
- **Scope:** `wordpressmainwpasemanager` (ported from `wordpressiccsupercache`)

## Context

The plugin had **no updater at all**. It is distributed only through GitHub Releases, so installing a
new version meant downloading a ZIP by hand and uploading it over the existing copy — WordPress never
reported an available update, never offered **Update now**, and WP-CLI had nothing to update from.

The sibling plugins (`wordpressiccsupercache`, `wordpressiccllmfiles`, `wordpressiccopenidclient`) use a
GitHub Releases updater that injects the release into the `update_plugins` transient, plus a manual
"Check for updates" action (row link → `admin_post` handler → admin notice → native **Update now**).
`wordpressiccsupercache` also documented a packaging trap that applies here:

* `.github/workflows/build.yml` publishes `wordpressmainwpasemanager-<version>.zip`, built from the
  repository root, so it unpacks **seven top level entries**. WordPress refuses it with
  *"The package contains more than one folder."*
* `.github/workflows/wordpress-deploy.yml` publishes `mainwp-ase-manager.zip` with a single root
  folder — the only installable archive.
* Both workflows trigger on `release: published`, so the order of the assets in the GitHub API
  response is a race. Picking "the first `.zip` found" makes "Update now" fail intermittently.

Additional constraint specific to this repository: `MainWP_ASE_Manager::bootstrap()` returns early
when the MainWP Dashboard plugin is not active. Update handling must not be swallowed by that check,
otherwise the update row links and the updater disappear exactly when a user needs them.

## Goal

1. Add `includes/class-mainwp-ase-manager-github-updater.php` (`MainWP_ASE_Manager_Github_Updater`),
   picked up by the existing `MainWP_ASE_Manager_*` autoloader mapping, mirroring the other plugins:
   `check_update()`, `plugins_api_filter()`, 12 hour release cache, one hour failure back-off.
2. Make the ZIP asset selection deterministic: prefer `<slug>.zip`, then any other `.zip` asset, then
   the tag archive.
3. Expose a static API so the UI never needs an instance: `flush_cache()`, `check_for_updates()`,
   `get_cached_update()`, `check_url()`, `update_url()`.
4. Wire the user-facing surfaces:
   - **Check for updates** and **Release notes** in the Plugins screen row,
   - an `admin_post_mainwp_ase_manager_check_updates` handler,
   - an admin notice reporting *update available* / *up to date* / *could not reach GitHub*,
   - the same information in the ASE Manager page footer.
5. Register all of it **before** the `is_mainwp_active()` early return in `bootstrap()`.
6. Ship the preventive packaging guard and documentation (`tests/validate-plugin-layout.sh`,
   `docs/updates.md`, `docs/testing.md`).
7. Drop `readme.txt`: the plugin is not on wordpress.org and release notes live in the GitHub release
   body.

## Steps

1. **New updater class** — `includes/class-mainwp-ase-manager-github-updater.php`
   - constants `GITHUB_REPO`, `GITHUB_API_URL`, `CACHE_KEY`, `FAILED_CACHE_KEY`, `CACHE_DURATION`,
     `PLUGIN_SLUG`, `RESULT_CACHE_KEY`;
   - `check_update()` on `site_transient_update_plugins` and
     `pre_set_site_transient_update_plugins`, populating `response` / `no_update`;
   - `plugins_api_filter()` for the details modal changelog;
   - static `get_latest_release()`, `normalize_version()`, `request_recently_failed()`,
     `log_failed_request()`;
   - `get_package_url()` with the preferred/fallback/archive order;
   - static API `flush_cache()`, `check_for_updates()`, `get_cached_update()`, `check_url()`,
     `update_url()`.
2. **Bootstrap wiring** — `mainwp-ase-manager.php`
   - `define( 'MAINWP_ASE_MANAGER_BASENAME', plugin_basename( __FILE__ ) )` next to
     `MAINWP_ASE_MANAGER_FILE`;
   - in `bootstrap()`, before the `is_mainwp_active()` check: `init` hook for the updater,
     `plugin_row_meta`, `admin_post_mainwp_ase_manager_check_updates`, `admin_notices`;
   - new static methods `init_github_updater()`, `add_plugin_row_meta()`, `handle_check_updates()`,
     `show_update_check_notice()`.
3. **Page footer** — `includes/class-mainwp-ase-manager.php`, after `</form>` in `render_page()`.
4. **Packaging guard** — `tests/validate-plugin-layout.sh` (copied from `wordpressiccsupercache`) and a
   `.dockerignore` that keeps `docs`, `plans`, `docker` and `.github` out of build contexts.
5. **Documentation** — `README.md` "Updating" section, `docs/updates.md`, `docs/testing.md`, and the
   folder structure block in `README.md` (drop the `readme.txt` line).
6. **Remove `readme.txt`** and the reference to it in the `Requires PHP` header comment.
7. **Verify** — `php -l` on every touched file, `sh tests/validate-plugin-layout.sh`, a `/tmp` harness
   proving the asset preference, and the GitHub security checks.

## Risks

| Risk | Mitigation |
|---|---|
| The `is_mainwp_active()` early return hides the updater | Updater, row meta, handler and notice are registered before that check; the manual test in `docs/testing.md` deactivates MainWP Dashboard to prove it |
| The forced check hits the GitHub API on every click (60 requests/hour unauthenticated) | It runs only for users with `update_plugins`, on an explicit click, and still respects the one hour failure back-off |
| The autoloader must resolve the new class | `MainWP_ASE_Manager_Github_Updater` maps through the existing convention to `includes/class-mainwp-ase-manager-github-updater.php`; no `$class_map` entry is needed |
| `plugin_basename()` differs between a git clone (`wordpressmainwpasemanager/`) and a release install (`mainwp-ase-manager/`) | The basename is defined once in the main file instead of being hard-coded, so both layouts produce a working `update.php` URL |
| Deleting `readme.txt` breaks the release workflows | Both workflows treat it conditionally (`if [ -f readme.txt ]`), verified by reading `semantic-release.yml` and `wordpress-deploy.yml` |
| The `build.yml` ZIP keeps publishing an unusable archive | `get_package_url()` no longer depends on asset order; the caveat is documented in `docs/updates.md` |

