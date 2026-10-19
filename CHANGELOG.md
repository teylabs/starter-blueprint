# Changelog

## Unreleased

### Added

- Per-feature manifests (`foundation`, `defaults`) with ownership, requirements, conflicts and host-owned paths, each content-addressed into the source reference.
- `Blueprint::features()`, `Blueprint::all()` and `Blueprint::validateFeatures()`.
- No-baseline adopt path and add-only upgrades for a growing feature selection; removal is refused.
- `--features` and `--original-features` options for `starter:maintain`.
- `tests/FeatureCheck.php`, and feature runs of the scaffold and lifecycle checks, in CI.

### Changed

- Renamed the blueprint manifest key `modules` to `groups`. Pinned identities changed:
  - A: `sha256:015b8df7…e3cd1` → `sha256:81ac85e0…2ea7`
  - B: `sha256:281b86bd…7c37` → `sha256:9dc857f7…32ba`
  - C: `sha256:35ae3835…ca27` → `sha256:d5ca339f…81cc`
  - D: `sha256:1cf152e0…4177` → `sha256:9fab4803…c643`
- Renamed the scaffold id from `teylabs/starter-foundation` to `tey/starter-foundation`.
- Kept `tey/mod` at ^0.7.2. The adapter still reads `Artifact::$modules`; that rename follows Mod 0.8.
