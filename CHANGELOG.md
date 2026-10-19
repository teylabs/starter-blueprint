# Changelog

## Unreleased

### Added

- Lifecycle checks against the released Mod 0.8 in vendor, in CI: first adoption, plan and apply, additive upgrade, refused removal, an unowned path, resume and a cold retry, plus `tests/ResourceCheck.php`.
- A check that an upgrade's original is exactly the installed artifact, metadata included.
- Dev dependencies on the blueprint's peers, so the compatibility check runs on real released versions.
- Per-feature manifests (`foundation`, `defaults`) with ownership, requirements, conflicts and host-owned paths, each content-addressed into the source reference.
- `Blueprint::features()`, `Blueprint::all()` and `Blueprint::validateFeatures()`.
- No-baseline adopt path and add-only upgrades for a growing feature selection; removal is refused.
- `--features` and `--original-features` options for `starter:maintain`.
- `tests/FeatureCheck.php`, and feature runs of the scaffold and lifecycle checks, in CI.

### Changed

- Require `tey/concierge` ^0.7.2, the first release that allows Mod 0.8, and drop the test-only stand-in. Identities moved: A `sha256:ba839706…2979` → `sha256:21bd1704…45e2`, B `sha256:41ce8059…7b4a` → `sha256:70af6427…726c`, C `sha256:16adb156…460a` → `sha256:85405850…a3ff`, D `sha256:77a4c1bf…face` → `sha256:a85ea6fe…7aaf`, foundation `sha256:52dc40d2…2218` → `sha256:8e2e55ee…4054`.
- Renamed the blueprint manifest key `modules` to `groups`. Pinned identities changed:
  - A: `sha256:015b8df7…e3cd1` → `sha256:81ac85e0…2ea7`
  - B: `sha256:281b86bd…7c37` → `sha256:9dc857f7…32ba`
  - C: `sha256:35ae3835…ca27` → `sha256:d5ca339f…81cc`
  - D: `sha256:1cf152e0…4177` → `sha256:9fab4803…c643`
- Renamed the scaffold id from `teylabs/starter-foundation` to `tey/starter-foundation`.
- Moved `tey/mod` to ^0.8 and the adapter to `Artifact::$groups`. The blueprint requirement is now `tey/mod` ^0.8, so pinned identities changed again:
  - A: `sha256:81ac85e0…2ea7` → `sha256:ba839706…2979`
  - B: `sha256:9dc857f7…32ba` → `sha256:41ce8059…7b4a`
  - C: `sha256:d5ca339f…81cc` → `sha256:16adb156…460a`
  - D: `sha256:9fab4803…c643` → `sha256:77a4c1bf…face`
- `Lifecycle` no longer takes an experimental source or verifier. It runs on released Mod ^0.8 only.
- The first-adoption original carries no metadata, as Mod builds its own.

### Removed

- The "released Mod refuses lifecycle" behaviour, the local-experimental descriptor, the trusted source verifier and the `experimental-mod-source` input.
