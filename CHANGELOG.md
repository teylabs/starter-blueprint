# Changelog

## Unreleased

## v0.1.0

### Added

- Per-feature manifests, `foundation` and `defaults`, each declaring its owned files, requirements, conflicts and host-owned paths. Every feature is content-addressed into the blueprint's source reference, and the whole app is all features.
- First adoption with no existing baseline, and add-only upgrades as the feature selection grows. Removing a feature is refused with a clear message.
- `starter:maintain {plan|apply|resume}` with `--original`, `--target`, `--features`, `--original-features`, `--intent`, `--expected-plan` and `--exclusive-checkout`. Every refusal or incomplete run exits nonzero.
- The `tey/starter-foundation` scaffold for previewing and generating the files through Mod's public scaffolds.
- The `LifecycleHost` contract an app implements for its app ID, bindings, input files and operation check, plus executor provenance and input fingerprints bound into each run.
- An upgrade's original must equal the installed artifact exactly, metadata included, or it is refused before Mod runs.
- Pinned release fixtures A–D: A `sha256:21bd1704…45e2`, B `sha256:70af6427…726c`, C `sha256:85405850…a3ff`, D `sha256:a85ea6fe…7aaf`; feature `foundation` `sha256:8e2e55ee…4054`, `defaults` `sha256:fb252b8b…2e43`.
- Requires `tey/mod` ^0.8 and `tey/concierge` ^0.7.2. CI proves the lifecycle on PHP 8.4 and 8.5 against the released packages: first adoption, plan and apply, additive upgrade, refused removal, an unowned path, resume and a cold retry. Adopting into an app that already has files the blueprint would own is refused until Mod can claim existing files.
