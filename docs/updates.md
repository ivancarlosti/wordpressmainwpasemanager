# Updating

The plugin ships its own GitHub Releases updater
(`includes/class-mainwp-ase-manager-github-updater.php`). It is not distributed on wordpress.org, so
every update path — the Plugins screen, **Dashboard → Updates**, WP-CLI and the automatic background
updater — resolves through the GitHub releases API.

Unlike the rest of the plugin, the update code does **not** depend on the MainWP Dashboard plugin: the
updater, the row links, the `admin_post` handler and the admin notice are registered in
`MainWP_ASE_Manager::bootstrap()` *before* the `is_mainwp_active()` early return, so a deactivated
MainWP Dashboard never hides plugin updates.

## Surfaces

| Surface | Code | Behaviour |
|---|---|---|
| Plugins screen row | `MainWP_ASE_Manager::add_plugin_row_meta()` | **Check for updates** (forced API call) and **Release notes** (GitHub releases page) |
| `admin_post` handler | `MainWP_ASE_Manager::handle_check_updates()` | Checks the nonce and the `update_plugins` capability, forces the lookup, stores the result, redirects to `plugins.php` |
| Admin notice | `MainWP_ASE_Manager::show_update_check_notice()` on `admin_notices` | Renders "update available / up to date / could not reach GitHub" once, then deletes the transient |
| Extension page footer | `MainWP_ASE_Manager_Page::render_page()` | Installed version, cached update state, **Update now**, **Release notes** |
| Native update row | `check_update()` on `site_transient_update_plugins` | Feeds the WordPress upgrader, including cron |
| Details modal | `plugins_api_filter()` | Version and changelog from the GitHub release body |

## Sequence of a manual check

1. The **Check for updates** link points at `admin-post.php?action=mainwp_ase_manager_check_updates`,
   wrapped in `wp_nonce_url()` with the `mainwp_ase_manager_check_updates` action.
2. `MainWP_ASE_Manager::handle_check_updates()` calls
   `MainWP_ASE_Manager_Github_Updater::check_for_updates()`, which
   - deletes `CACHE_KEY` and `FAILED_CACHE_KEY` (`flush_cache()`), bypassing the 12 hour cache and the
     one hour failure back-off,
   - calls the GitHub API (`GITHUB_REPO` / `GITHUB_API_URL`),
   - re-saves the `update_plugins` transient so the `pre_set_site_transient_update_plugins` filter
     injects the new release and WordPress renders its native update row immediately,
   - returns `array( 'state' => 'update' | 'current' | 'error', 'version', 'installed', 'url' )`.
3. The result is stored in the `RESULT_CACHE_KEY . '_' . user_id` transient for five minutes and the
   request is redirected back to `plugins.php`.
4. `MainWP_ASE_Manager::show_update_check_notice()` renders the matching notice (with **Update now**
   and **Release notes** when an update exists) and deletes the transient.

## Release ZIP caveat

Every release carries several assets. Only `mainwp-ase-manager.zip` extracts into the plugin folder,
because its single root folder is `mainwp-ase-manager/`.

The archive produced by `.github/workflows/build.yml` (`wordpressmainwpasemanager-<version>.zip`)
keeps the repository layout, so it unpacks **seven top level entries** and WordPress rejects it with
*"The package contains more than one folder."* Both `build.yml` and `wordpress-deploy.yml` fire on
`release: published`, so the asset order in the API response is a race. `get_package_url()` therefore
prefers the exact `PLUGIN_SLUG . '.zip'` asset, falls back to the first `.zip` asset and finally to the
tag archive URL.

Verify a release before publishing:

```bash
unzip -Z1 mainwp-ase-manager.zip | cut -d/ -f1 | sort -u
# must print exactly one line: mainwp-ase-manager
```

## Failure handling

* `FAILED_CACHE_KEY` is set for one hour after a failed request, so a broken network does not hammer
  the GitHub API (an unauthenticated client is limited to 60 requests/hour).
* On failure the previous `CACHE_KEY` value is left untouched, so the last known good answer keeps
  driving the update row.
* Errors never surface on the Plugins screen itself; they are only reported by the manual check notice.

## Release notes

There is no `readme.txt`. Release notes are the GitHub release body, exposed in the plugin details
modal through `plugins_api_filter()` and linked from every "Release notes" link. The plugin is not
submitted to wordpress.org, so a `readme.txt` would only duplicate content that is already in
`README.md` and in the release body.
