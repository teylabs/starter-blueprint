# Starter blueprint delivery increment

Opinionated versioned application contract and public Mod scaffold pack. A/B and C/D are local synthetic release fixtures, not published versions. Runtime tenancy/membership/settings stay in maintained packages and host bindings.

The scaffold id is now `tey/starter-foundation` (it was `teylabs/starter-foundation`; the vendor moved from `teylabs` to `tey`). The command is still `starter:foundation`.

## Features

The app is a set of features. Each feature has a manifest at `resources/features/<id>/feature.json` with its id, owned paths, generated files (with their stubs), requirements (other features and packages), conflicts and host-owned paths. `resources/features/blueprint.json` holds what every selection shares: the artifact name, version, groups, migration order and verification policy.

Two features exist: `foundation` (the policy file) and `defaults` (the defaults file, which requires `foundation`). The whole app is every feature. Select features with `Blueprint::features(['foundation'])`, `Blueprint::all()`, or `config('starter-blueprint.features')` for the scaffold.

A selection is checked before anything is built. It is refused when a feature is unknown or listed twice, a required feature is missing, two features conflict, a file sits outside its feature's owned paths or inside a host-owned path, two features own the same path, or two features disagree on a package constraint.

Each feature is content-addressed: its manifest bytes and its stub bytes are pinned in `Blueprint`. A selection's source reference covers the composed manifest, every resource byte and every feature's pinned identity. The order you list features in does not change it. The selection is recorded in the manifest and in the artifact metadata (`blueprint_features`).

Mod 0.8 keeps one installed record per checkout and refuses changes to an artifact's name, groups or bindings, and refuses removals. So 0.1 uses one growing artifact:

- A first adoption has no baseline. The original is an empty artifact with the same name, groups, bindings and checks as the target, and no metadata, as Mod builds its own. Existing paths are refused as unowned, even when the bytes match.
- Adding a feature is an upgrade from the old selection to a larger one on the same artifact.
- Removing a feature is refused with a clear message, before any other check.

Per-feature artifacts need an additive Mod API and are not part of 0.1. The manifest shape is ready for it.

A–D stay as release fixtures. They are separate regression evidence, not feature sets: their stub bytes carry their own versions (0.1.0 to 0.4.0). Their manifests use the `groups` key, and their pinned identities changed with it (see the changelog).

The `starter-foundation` scaffold uses public released Mod APIs. Preview with `starter:foundation --dry-run --json`; then generate explicitly with `--no-interaction`. A/B generate one policy file. C/D add a separately owned defaults file for the two-file upgrade proof. It does not establish a bootable/authenticated application, persisted ownership, upgrade/recovery, browser or product-MCP acceptance.

Generated paths and host-owned branding/domain/configuration/data are distinct in the blueprint. The source reference content-addresses the manifest plus all resource bytes; baseline resources remain available for comparison. Generic lifecycle machinery lives in Mod. No private Mod APIs, forced overwrites, semantic merging, package publication or automatic production updates.

The initial recipe fixes the member name and ungrouped placement. Alternate command names cannot widen ownership. Only the Laravel layout with all declared paths and classes is supported; incompatible layouts refuse before generation.

The original A/B resource bytes and source references are pinned and retained. New C/D fixtures represent logical consumer baseline/target versions 0.3.0/0.4.0; both policy and defaults resources change. Each member has a fixed class/path, and every member validates the entire placement set before generation starts. Defaults occupy their own explicit folder so released Mod reverse-mapping remains unambiguous. Ordinary generation preserves existing customised output; it is not the persistent upgrade protocol.

Run `php tests/ScaffoldCheck.php <autoload.php> C` (also A/B/D) against a public Laravel 13 / Mod 0.8 install. The optional `misplace-defaults` argument checks that a mismatched second member refuses before writing the first. Each run creates a distinct synthetic filesystem fixture, not a real application consumer or an installed ownership record.

18 October 2026: Added immutable two-file C/D proof resources and whole-recipe placement validation; initial A/B identities retained.

The lifecycle adapter composes only public Mod `Artifact`, `Checkout`, `FilePlan` and `Maintenance` APIs. It runs on released `tey/mod` ^0.8 as installed from Packagist. It needs no local Mod source, overlay or source verifier. It refuses to run on any other Mod version. Mod publishes the Maintenance API; this package adds the blueprint, its identity and the host contract.

Bind `LifecycleHost` explicitly to application-owned code for `starter:maintain`. It supplies the app ID, frontend bindings, actual named input files, a `Lifecycle`, and a real operation/tenant acceptance closure. There is no default success callback. Required input IDs are `verification-policy`, `verification-code`, `dependency-lock`, `host-config`, `host-binding`, `host-operations` and `executor-provenance`. Executor provenance is required for every executor: write the exact typed `runtime()` result as JSON before preview. It is checked against the Mod version actually installed and fingerprinted as an execution input, not treated as proof on its own. These must name the actual policy, registered implementation, lock, configuration and binding files; include additional relevant package/verifier implementation inputs. The operation closure must be declared in the file named by host-operations; reflection rejects an unbound implementation before delivery. Package and resource checks run before effects and again before recording, including after the host operation callback. Host code cannot replace package dependency/resource checks.

Preview with `starter:maintain plan --target=C`, or `starter:maintain plan --features=foundation,defaults` to select features. Review its plan identity before `starter:maintain apply --target=C --intent=<stable-intent> --expected-plan=<identity> --exclusive-checkout`. Upgrade with `--original=C --target=D`, or add features with `--original-features=foundation --features=foundation,defaults`; retry an interrupted exact intent with `starter:maintain resume` and the same original/target/intent. On an upgrade, the original you name must rebuild exactly the installed artifact, features and bindings included; otherwise the command refuses before Mod does. Every refusal or noncompletion exits nonzero, including conflicts, failed verification and unresolved orphan candidates. Completed cold retries use the authoritative receipt without rerunning host callbacks. No force, semantic merge, migration execution or orphan deletion exists.

The artifact's inert metadata contains the pinned blueprint source, complete manifest and truthful empty applied-migration IDs. Requirements, migration declarations, exceptions and host ownership are provenance, not execution evidence. The generic Mod installed record remains the sole version authority, committed last. Executor provenance is separate from immutable artifact provenance. It is exposed in results and bound through the checked executor-provenance input in the authoritative installed commit. A changed executor and input can run a new upgrade intent against the unchanged historical artifact; they cannot reuse an old intent. The input is host configuration, never a competing installed-version record. All paths require an exclusively owned checkout. Advisory locking cannot protect against arbitrary editors; preserve/refuse conflicts and unresolved recovery files.

Run `php tests/FeatureCheck.php <autoload.php>` for manifest validation and feature growth rules (no lifecycle API needed). Run `php tests/ScaffoldCheck.php <autoload.php> features:foundation,defaults` for a feature selection. Run `php tests/LifecycleCheck.php <autoload.php>` for the lifecycle against the Mod in vendor: first adoption, plan and apply, additive upgrade, refused removal, an unowned path, resume, and a cold retry. `php tests/ResourceCheck.php <autoload.php>` tampers only fresh disposable package copies. CI runs all of these on PHP 8.4 and 8.5. These callbacks and files are synthetic adapter evidence, not consumer operation/tenant/browser/database acceptance. `ScaffoldCheck.php` compares snapshots to actual public released scaffold output for every A/B/C/D release.

The tests install the blueprint's peer packages as dev dependencies so the compatibility check runs on real versions. `tey/concierge` 0.7.1 does not yet allow Mod 0.8, so `tests/fixtures/concierge` stands in for it with the same name and version and no code. A real host cannot satisfy this blueprint until Concierge releases for Mod 0.8

19 October 2026: Added a bounded public-only lifecycle adapter, immutable resource checks, explicit host/overlay preflight, authoritative provenance and CLI exit propagation. Two-consumer execution and published installation remain separate gates.

19 October 2026: Keep immutable blueprint identity independent of the current executor; bind verified executor provenance as an execution input and preserve same-intent staleness across new-executor upgrades.

19 October 2026: Added per-feature manifests, a no-baseline adopt path and an add-only feature upgrade on one growing artifact. The manifest key `modules` is now `groups`; Mod's own `Artifact::$modules` reads wait for Mod 0.8.

20 October 2026: Moved to released Mod 0.8. Lifecycle now runs against the vendor package, with no experimental overlay or source verifier. The first-adoption original carries no metadata, and an upgrade's original must match the installed artifact exactly.
