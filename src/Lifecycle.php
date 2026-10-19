<?php

namespace Tey\StarterBlueprint;

use Closure;
use Composer\InstalledVersions;
use Composer\Semver\Semver;
use InvalidArgumentException;
use ReflectionFunction;
use RuntimeException;
use Tey\Mod\Maintenance\Artifact;
use Tey\Mod\Maintenance\Checkout;
use Tey\Mod\Maintenance\FilePlan;
use Tey\Mod\Maintenance\Maintenance;

/** Opinionated adapter; all lifecycle effects belong to Mod's public API. */
final readonly class Lifecycle
{
    private const MOD = '^0.8';

    /** Checks actual installed packages and PHP, never a caller-supplied version inventory. */
    public static function compatibility(array $requirements): array
    {
        $outcomes = [];
        foreach ($requirements as $package => $constraint) {
            if (! is_string($package) || ! is_string($constraint)) {
                throw new InvalidArgumentException('Named package constraints are required.');
            }
            $version = $package === 'php' ? PHP_VERSION : (InstalledVersions::isInstalled($package) ? InstalledVersions::getPrettyVersion($package) : null);
            $outcomes[$package] = $version !== null && Semver::satisfies($version, $constraint) ? 'passed' : 'failed';
        }

        return $outcomes;
    }

    /** The Mod release this process runs on; lifecycle needs the released Maintenance API. */
    public function runtime(): array
    {
        $version = InstalledVersions::isInstalled('tey/mod') ? InstalledVersions::getPrettyVersion('tey/mod') : null;
        if ($version === null || ! Semver::satisfies($version, self::MOD) || ! class_exists(Maintenance::class)) {
            throw new RuntimeException('Lifecycle requires released tey/mod '.self::MOD.'.');
        }

        return ['installed_mod_version' => $version];
    }

    public function artifact(Blueprint $blueprint, array $bindings): Artifact
    {
        $this->runtime();
        $snapshot = $blueprint->snapshot();
        $manifest = $snapshot['manifest'];

        $metadata = ['blueprint_source' => $snapshot['source_reference'], 'blueprint_manifest' => $manifest, 'applied_migration_ids' => []];
        if (isset($manifest['features'])) {
            $metadata['blueprint_features'] = $manifest['features'];
        }

        return new Artifact($manifest['id'], $manifest['version'], $snapshot['files'], $manifest['groups'], $bindings, $manifest['verification'], metadata: $metadata);
    }

    /** The original for a first adoption: no files, and the same name, groups, bindings and checks as the target. */
    private function empty(Artifact $b): Artifact
    {
        return new Artifact($b->name, $b->version, [], $b->groups, $b->bindings, $b->checks);
    }

    public function plan(string $root, string $appId, ?Blueprint $original, Blueprint $target, array $bindings, array $inputPaths): FilePlan
    {
        Blueprint::assertGrowth($original, $target);
        $runtime = $this->runtime();
        $compatibility = $this->compatible($target);
        $b = $this->artifact($target, $bindings);
        $a = $original === null ? $this->empty($b) : $this->artifact($original, $bindings);
        $this->installed($root, $original === null ? null : $a, [$a]);
        $plan = Checkout::inspect($root, $appId, $a, $b, $inputPaths, $compatibility);
        $this->inputs($root, $inputPaths, $runtime);

        return $plan;
    }

    public function apply(FilePlan $plan, ?Blueprint $original, Blueprint $target, array $inputPaths, string $intent, Closure $hostOperationCheck, bool $exclusiveCheckout): array
    {
        Blueprint::assertGrowth($original, $target);
        $runtime = $this->runtime();
        $compatibility = $this->compatible($target);
        $b = $this->artifact($target, $plan->target->bindings);
        $a = $original === null ? $this->empty($b) : $this->artifact($original, $plan->baseline->bindings);
        if ($plan->target->identity() !== $b->identity() || $plan->baseline->identity() !== $a->identity() || $plan->compatibility !== $compatibility) {
            throw new RuntimeException('Plan does not match the immutable blueprint and current compatibility.');
        }
        $this->installed($plan->root, $original === null ? null : $a, [$a]);
        Checkout::inspect($plan->root, $plan->appId, $a, $b, $inputPaths, $compatibility);
        $this->inputs($plan->root, $inputPaths, $runtime);
        $this->hostCheck($plan->root, $inputPaths, $hostOperationCheck);
        $result = Maintenance::apply($plan, $inputPaths, $intent, $this->checks($original, $target, $hostOperationCheck), $exclusiveCheckout);

        return $result + ['runtime' => $runtime];
    }

    public function resume(string $root, string $appId, ?Blueprint $original, Blueprint $target, array $bindings, array $inputPaths, string $intent, Closure $hostOperationCheck, bool $exclusiveCheckout): array
    {
        Blueprint::assertGrowth($original, $target);
        $runtime = $this->runtime();
        $compatibility = $this->compatible($target);
        $b = $this->artifact($target, $bindings);
        $a = $original === null ? $this->empty($b) : $this->artifact($original, $bindings);
        $this->installed($root, $original === null ? null : $a, [$a, $b]);
        Checkout::inspect($root, $appId, $a, $b, $inputPaths, $compatibility);
        $this->inputs($root, $inputPaths, $runtime);
        $this->hostCheck($root, $inputPaths, $hostOperationCheck);
        $result = Maintenance::resume($root, $appId, $a, $b, $inputPaths, $compatibility, $intent, $this->checks($original, $target, $hostOperationCheck), $exclusiveCheckout);

        return $result + ['runtime' => $runtime];
    }

    /**
     * An upgrade's original must be the installed artifact exactly, metadata included; Mod refuses it otherwise.
     * Resume may also meet the target when the intent already completed.
     *
     * @param  list<Artifact>  $accepted
     */
    private function installed(string $root, ?Artifact $original, array $accepted): void
    {
        if ($original === null) {
            return;
        }
        $path = $root.'/.mod-maintenance/installed.json';
        if (! is_file($path)) {
            return;
        }
        $identity = json_decode(file_get_contents($path), true, 32)['artifact']['identity'] ?? null;
        foreach ($accepted as $artifact) {
            if ($identity === $artifact->identity()) {
                return;
            }
        }
        throw new RuntimeException('The original given must be exactly the installed artifact, features and bindings included.');
    }

    private function compatible(Blueprint $target): array
    {
        $outcomes = self::compatibility($target->manifest['requirements']);
        foreach ($outcomes as $package => $outcome) {
            if ($outcome !== 'passed') {
                throw new RuntimeException('Incompatible blueprint dependency: '.$package);
            }
        }

        return $outcomes;
    }

    private function inputs(string $root, array $inputPaths, array $runtime): void
    {
        $required = ['verification-policy', 'verification-code', 'dependency-lock', 'host-config', 'host-binding', 'host-operations', 'executor-provenance'];
        foreach ($required as $id) {
            if (! isset($inputPaths[$id]) || ! is_file($root.'/'.$inputPaths[$id])) {
                throw new RuntimeException('Missing required host verification input: '.$id);
            }
        }
        $path = $root.'/'.$inputPaths['executor-provenance'];
        if (filesize($path) > 4096 || json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR) !== $runtime) {
            throw new RuntimeException('Executor provenance input must match the currently verified runtime.');
        }
    }

    private function hostCheck(string $root, array $inputPaths, Closure $hostOperationCheck): void
    {
        $implementation = (new ReflectionFunction($hostOperationCheck))->getFileName();
        if ($implementation === false || realpath($implementation) !== realpath($root.'/'.$inputPaths['host-operations'])) {
            throw new RuntimeException('Host operation check must be implemented in the bound host-operations input.');
        }
    }

    private function checks(?Blueprint $original, Blueprint $target, Closure $hostOperationCheck): array
    {
        if ($target->manifest['verification'] !== ['dependency compatibility', 'generated resource integrity', 'host operation and tenant acceptance']) {
            throw new RuntimeException('Unsupported blueprint verification policy.');
        }

        return [
            'dependency compatibility' => function () use ($target): bool {
                $this->runtime();
                $this->compatible($target);

                return true;
            },
            'generated resource integrity' => function () use ($original, $target): bool {
                $original?->sourceReference();
                $target->sourceReference();

                return true;
            },
            'host operation and tenant acceptance' => function () use ($hostOperationCheck, $original, $target): bool {
                $result = $hostOperationCheck();
                // Host code may change source outside the observed checkout inputs.
                $this->runtime();
                $this->compatible($target);
                $original?->sourceReference();
                $target->sourceReference();

                return $result === true;
            },
        ];
    }
}
