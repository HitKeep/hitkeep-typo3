# HitKeep for TYPO3

A small Composer extension that loads the existing async HitKeep tracker on public HTML pages. Tested with TYPO3 **14.3.9**, PHP **8.4.24**, and SQLite. The dependency range is restricted to 14.3.x; other versions are not qualified. No database tables, credentials, backend reports, or tracker fork.

This is a source implementation candidate, available at [PascaleBeier/hitkeep-typo3](https://github.com/PascaleBeier/hitkeep-typo3). No Packagist or TER release has been published. `hitkeep/typo3` and extension key `hitkeep` remain proposed distribution identities, subject to publisher ownership checks before a package release.

## Install and configure

To install the source candidate in a TYPO3 Composer project, add the GitHub VCS repository and require its development branch:

```sh
composer config repositories.hitkeep vcs https://github.com/PascaleBeier/hitkeep-typo3.git
composer require hitkeep/typo3:dev-main
```

Keep the resulting Composer lock to pin the reviewed commit. This is a development version, not a stable package release.

Alternatively, install the reviewed package using a local Composer `package` repository that declares version `0.1.0`, type `typo3-cms-extension`, the package requirements/autoload/extension key from `composer.json`, and the ZIP as its `dist` URL. Require `hitkeep/typo3:0.1.0` after adding that repository. The reproducible `tools/verify-package.py` fixture performs this installation. This does not require Packagist or TER publication. An ordinary Packagist install remains a separate publication step.

Add the set to the site's existing `config/sites/<site>/config.yaml` dependencies:

```yaml
dependencies:
  - hitkeep/tracker
```

Keep all existing site-set dependencies. In the native Site Settings editor, enable HitKeep and enter the HTTPS base URL. Equivalent `config/sites/<site>/settings.yaml`:

```yaml
hitkeep.enabled: true
hitkeep.host: 'https://cloud.hitkeep.eu'
hitkeep.excludePaths:
  - /private
  - /account
```

Cloud EU uses `https://cloud.hitkeep.eu`; Cloud US uses `https://cloud.hitkeep.com`. For a self-hosted installation or an activated custom tracking domain, use that deployment's HTTPS base URL. A base such as `https://analytics.example/hitkeep` loads `https://analytics.example/hitkeep/hk.js` and preserves its ingest prefix. Do not append `hk.js` yourself. Only HTTPS URLs with a host and optional unreserved ASCII path segments are accepted; credentials, query strings, fragments, controls, percent escapes, dot segments, and markup fail closed. No connection check runs on the server.

Each site reads its own settings. Register every public website/language domain in the correct HitKeep team/site; the tracker uses the visitor's page URL to resolve the site. The extension does not send a CMS user ID or invent a `data-site-id` attribute.

Remove any old manual HitKeep snippet or tag-manager installation before enabling this extension. Clear TYPO3's **all caches**, then purge any reverse-proxy/CDN page cache. Changing the host, exclusions, enabled flag, or site-set dependency requires this purge. An already open browser page cannot be recalled by a server-side setting change.

The set attaches `page.987654321` as a `USER_INT`. Use the conventional `page` PAGE object, keep that slot available, and ensure your site's later TypoScript does not clear it. A legacy `sys_template` with **clear setup** can remove site-set setup: remove that reset or explicitly import `EXT:hitkeep/Configuration/Sets/HitKeep/setup.typoscript` after it. Custom PAGE names must explicitly include this object. Do not change it to `USER`: cached registration would persist tracker eligibility in the page cache.

## Privacy and consent

Tracking is disabled by default. Backend sessions, authenticated frontend users, frontend previews, non-HTML output, and excluded paths receive no tracker from this extension. Exclusions match a path itself and its children; `/private` does not match `/private-other`. `/` excludes everything. Public caches outside TYPO3 must not serve a guest response to staff or authenticated users; honor TYPO3's cache-control/session behavior. The extension cannot identify staff when their browser sends no authenticated session.

HitKeep owns DNT and `hk_ignore` visitor opt-out behavior. The extension does not override either, add visitor identities, or store analytics in TYPO3. Loading `hk.js` itself still contacts the selected host even when tracker-side DNT or opt-out suppresses ingestion. The tracker may use an opaque session tuple and explicit visitor preference storage; do not describe this as universally storage-free or consent-exempt.

Where consent is required before any tracker resource loads, leave `hitkeep.enabled: false` and let your existing CMP insert the official snippet only after consent. Do not enable both loaders. There is no universal CMP adapter or server-side consent-cookie interpretation here; cached HTML must never encode visitor consent. An unconfigured or disabled extension emits no script before consent.

## Content Security Policy and unavailable trackers

Allow the **exact tracker origin**, including its port when non-default, in `script-src` and `connect-src`. When `script-src-elem` is present, allow it there too. Account for the existing site's CSP rather than replacing it with this example:

```text
script-src 'self' https://analytics.example;
script-src-elem 'self' https://analytics.example;
connect-src 'self' https://analytics.example;
```

No inline script, unsafe-inline exception, or nonce is needed for this origin-allowlist setup. Nonce-only/strict-dynamic policies need separate integration proof using TYPO3's current `csp` option; this extension does not claim that proof or use deprecated `useNonce`. A blocked/offline tracker leaves page rendering intact. It does not guarantee delivery.

## Update, disable, remove, and roll back

Back up the site's Composer lock and site configuration. Update only the extension package with Composer, apply normal TYPO3 package/cache maintenance, and purge all TYPO3 and external page caches. Check one script and one pageview on a registered public site and no script in a staff/preview response.

To disable, set `hitkeep.enabled: false` and purge caches. To uninstall, disable first, remove `hitkeep/tracker` from each site's dependencies and any explicit import, remove the extension's settings keys, then `composer remove hitkeep/typo3` and purge caches again. Do not remove other site settings or dependencies. The extension has no content/database cleanup: pages, content, and analytics already stored in HitKeep remain intact. Manual snippets/CMP loaders must be removed separately if present.

For rollback, restore the prior reviewed package/Composer lock and site configuration together, then purge caches. The first version has no prior public release; local package removal/reinstallation is verified, not an upgrade from an invented historical release.

## Development and release gates

With the matching PHP extensions available:

```sh
composer install
composer validate --strict
composer lint
composer analyse
composer test
```

Functional tests use TYPO3's testing framework, SQLite, actual frontend subrequests, DI/callable enforcement, and a persistent page-cache backend. Optional browser fixture export uses `HITKEEP_BROWSER_EVIDENCE` (an existing writable directory) and `HITKEEP_BROWSER_HOST` (a local HTTPS tracker base); it runs only when both are explicitly supplied. It never contacts a configured host from PHP.

Build a local package with `composer archive --format=zip --dir=build --file=hitkeep-0.1.0`. The archive excludes tests, tooling, dependencies, local runtime state, and the development lock. Validate and install the ZIP in a clean fixture before releasing.

Packagist and TER are **separate publication gates**. Neither has been performed for this source candidate. Before Packagist: verify namespace and public repository ownership, public source/issues and private security contacts, clean matching tag/version/artifact, and the installed-artifact test matrix. Before TER: additionally verify the TYPO3.org identity and owned `hitkeep` key, current Tailor/TER metadata and upload artifact requirements, license/security policy compliance, and a clean TER-artifact installation. TER listing does not imply independent security certification. No release credentials belong in the archive.

The extension's README is the initial operator guide. A maintainer must add a focused TYPO3 integration guide to the separately owned HitKeep documentation website before a public package release; no main-application or documentation-site source is changed by this extension repository.

Report reproducible non-sensitive problems in [GitHub issues](https://github.com/PascaleBeier/hitkeep-typo3/issues). For vulnerabilities, use the private reporting route in [SECURITY.md](SECURITY.md).
