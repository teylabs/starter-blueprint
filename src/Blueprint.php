<?php

namespace Tey\StarterBlueprint;

use RuntimeException;

final readonly class Blueprint
{
    private const REFERENCES = [
        'A' => 'sha256:81ac85e037756f52fe8c3d129fb6f45c29b51fae47d8bf7927d2ae4f3ccd2ea7',
        'B' => 'sha256:9dc857f776eaa9dfc905ffc0828823f4864c8fe9b41fc0fbc2a7bca2728332ba',
        'C' => 'sha256:d5ca339fc57d0b14410662901fc038efbc3265b7d07c5d70c4ec49f4df8681cc',
        'D' => 'sha256:9fab4803fae4ac2d27eb59cd587be2d4924bc192fc76c3d63cdbbabc4ce6c643',
    ];

    /** Selectable features in canonical order, with the pinned identity of each feature's manifest and stubs. */
    private const FEATURES = [
        'foundation' => 'sha256:642ba1a90f59b5a7bdfdf72c3b143c2a74838b4010865c90f7e3f0797c3d64f6',
        'defaults' => 'sha256:fb252b8b908a95b26579418ea661725c747464b1fe6cdd5bba4de5fd2a982e43',
    ];

    private const BASE = 'sha256:2fd1f7e44e6309e4f74e60fc36e1135ddf43307ff328d941b4175382612dab8c';

    /** @param array<string, string> $directories stub name => directory that holds it */
    private function __construct(public array $manifest, private array $directories, private ?string $release, private array $features = []) {}

    public static function release(string $release): self
    {
        if (! in_array($release, ['A', 'B', 'C', 'D'], true)) {
            throw new RuntimeException('Unknown blueprint release.');
        }
        $directory = dirname(__DIR__).'/resources/releases/'.$release;
        $manifest = self::manifestAt($directory);
        foreach (['format', 'id', 'version', 'groups', 'requirements', 'generated', 'host_owned', 'migration_order', 'verification'] as $field) {
            if (! array_key_exists($field, $manifest)) {
                throw new RuntimeException('Missing blueprint field: '.$field);
            }
        }

        $directories = [];
        foreach ($manifest['generated'] as $entry) {
            $directories[$entry['stub']] = $directory;
        }
        $blueprint = new self($manifest, $directories, $release);
        $blueprint->sourceReference();

        return $blueprint;
    }

    /** @return list<string> */
    public static function featureIds(): array
    {
        return array_keys(self::FEATURES);
    }

    /** Every feature: the whole app. */
    public static function all(): self
    {
        return self::features(self::featureIds());
    }

    /** @param list<string> $ids */
    public static function features(array $ids): self
    {
        [$manifest, $directories, $features] = self::compose($ids);
        $blueprint = new self($manifest, $directories, null, $features);
        $blueprint->sourceReference();

        return $blueprint;
    }

    /** @return list<string> Selected feature ids in canonical order; empty for a release fixture. */
    public function selection(): array
    {
        return $this->manifest['features'] ?? [];
    }

    /** Features can be added to an installed artifact, never removed, and the artifact itself stays the same. */
    public static function assertGrowth(?self $original, self $target): void
    {
        if ($original === null) {
            return;
        }
        if (($original->release === null) !== ($target->release === null)) {
            throw new RuntimeException('A release fixture and a feature selection cannot be upgraded into each other.');
        }
        if ($original->release !== null) {
            return;
        }
        foreach (['id', 'groups'] as $field) {
            if ($original->manifest[$field] !== $target->manifest[$field]) {
                throw new RuntimeException('Blueprint '.$field.' cannot change between feature selections.');
            }
        }
        $removed = array_values(array_diff($original->selection(), $target->selection()));
        if ($removed !== []) {
            throw new RuntimeException('Feature removal is refused: '.implode(', ', $removed).'. Features can only be added.');
        }
    }

    /**
     * Checks a selection against a catalog of feature manifests keyed by id.
     *
     * @param  array<string, array>  $catalog
     * @param  list<string>  $selected
     */
    public static function validateFeatures(array $catalog, array $selected): void
    {
        if ($selected === [] || count($selected) !== count(array_unique($selected))) {
            throw new RuntimeException('Select each feature once, and at least one.');
        }
        foreach ($selected as $id) {
            if (! isset($catalog[$id])) {
                throw new RuntimeException('Unknown feature: '.$id);
            }
        }
        $paths = [];
        $stubs = [];
        $owned = [];
        $hostOwned = [];
        foreach ($selected as $id) {
            $feature = $catalog[$id];
            foreach (['format', 'id', 'owns', 'files', 'requires', 'conflicts', 'host_owned'] as $field) {
                if (! array_key_exists($field, $feature)) {
                    throw new RuntimeException('Missing feature field: '.$id.'.'.$field);
                }
            }
            if ($feature['id'] !== $id) {
                throw new RuntimeException('Feature id does not match its manifest: '.$id);
            }
            foreach ($feature['owns'] as $prefix) {
                foreach ($owned as $other => $others) {
                    foreach ($others as $existing) {
                        if (self::within($prefix, $existing) || self::within($existing, $prefix)) {
                            throw new RuntimeException('Features own overlapping paths: '.$other.' and '.$id);
                        }
                    }
                }
                $owned[$id][] = $prefix;
            }
            $hostOwned = array_merge($hostOwned, $feature['host_owned']);
        }
        foreach ($selected as $id) {
            $feature = $catalog[$id];
            foreach ($feature['files'] as $path => $entry) {
                if (! array_filter($feature['owns'], fn (string $prefix): bool => self::within($path, $prefix))) {
                    throw new RuntimeException('Path outside feature ownership: '.$id.' '.$path);
                }
                foreach ($hostOwned as $host) {
                    if (self::within($path, $host)) {
                        throw new RuntimeException('Path is host-owned: '.$path);
                    }
                }
                if (isset($paths[$path])) {
                    throw new RuntimeException('Duplicate path across features: '.$path.' in '.$paths[$path].' and '.$id);
                }
                $paths[$path] = $id;
                if (isset($stubs[$entry['stub']])) {
                    throw new RuntimeException('Duplicate stub across features: '.$entry['stub']);
                }
                $stubs[$entry['stub']] = $id;
            }
            foreach ($feature['requires']['features'] as $required) {
                if (! in_array($required, $selected, true)) {
                    throw new RuntimeException('Feature '.$id.' requires feature '.$required.'.');
                }
            }
            foreach ($feature['conflicts'] as $conflict) {
                if (in_array($conflict, $selected, true)) {
                    throw new RuntimeException('Feature '.$id.' conflicts with feature '.$conflict.'.');
                }
            }
        }
        $packages = [];
        foreach ($selected as $id) {
            foreach ($catalog[$id]['requires']['packages'] as $package => $constraint) {
                if (isset($packages[$package]) && $packages[$package] !== $constraint) {
                    throw new RuntimeException('Features disagree on the constraint for '.$package.'.');
                }
                $packages[$package] = $constraint;
            }
        }
    }

    private static function within(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, rtrim($prefix, '/').'/');
    }

    /** @return array{0: array, 1: array<string, string>, 2: array<string, string>} */
    private static function compose(array $ids): array
    {
        $catalog = [];
        foreach (self::FEATURES as $id => $pin) {
            $catalog[$id] = self::featureAt(dirname(__DIR__).'/resources/features/'.$id);
        }
        self::validateFeatures($catalog, $ids);
        $ids = array_values(array_intersect(self::featureIds(), $ids));
        $base = self::baseManifest();
        $manifest = ['format' => $base['format'], 'id' => $base['id'], 'version' => $base['version'], 'groups' => $base['groups'], 'requirements' => [], 'generated' => [], 'host_owned' => []];
        $directories = [];
        $hashes = [];
        foreach ($ids as $id) {
            $feature = $catalog[$id];
            $manifest['requirements'] += $feature['requires']['packages'];
            $manifest['generated'] += $feature['files'];
            $manifest['host_owned'] = array_values(array_unique(array_merge($manifest['host_owned'], $feature['host_owned'])));
            foreach ($feature['files'] as $entry) {
                $directories[$entry['stub']] = dirname(__DIR__).'/resources/features/'.$id;
            }
            $hashes[$id] = self::featureHash($id, $feature);
        }
        foreach (['migration_order', 'verification', 'auth', 'exceptions'] as $field) {
            $manifest[$field] = $base[$field];
        }
        $manifest['features'] = $ids;

        return [$manifest, $directories, $hashes];
    }

    private static function baseManifest(): array
    {
        $base = self::manifestAt(dirname(__DIR__).'/resources/features');
        if ('sha256:'.hash('sha256', file_get_contents(dirname(__DIR__).'/resources/features/blueprint.json')) !== self::BASE) {
            throw new RuntimeException('Immutable blueprint resource identity mismatch.');
        }

        return $base;
    }

    private static function featureAt(string $directory): array
    {
        $path = $directory.'/feature.json';
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException('Missing or linked feature manifest.');
        }

        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /** Content-addresses one feature: its manifest bytes and every stub it owns. */
    private static function featureHash(string $id, array $feature): string
    {
        $directory = dirname(__DIR__).'/resources/features/'.$id;
        $stubs = [];
        foreach ($feature['files'] as $entry) {
            $path = self::stubIn($directory, $entry['stub']);
            $stubs[$entry['stub']] = hash('sha256', file_get_contents($path));
        }
        ksort($stubs);
        $hash = 'sha256:'.hash('sha256', json_encode([hash_file('sha256', $directory.'/feature.json'), $stubs], JSON_THROW_ON_ERROR));
        if ($hash !== self::FEATURES[$id]) {
            throw new RuntimeException('Immutable blueprint resource identity mismatch.');
        }

        return $hash;
    }

    public function sourceReference(): string
    {
        return $this->reference($this->resources());
    }

    /** @return array{source_reference: string, manifest: array, files: array<string, string>} */
    public function snapshot(): array
    {
        $resources = $this->resources();
        $reference = $this->reference($resources);
        $files = [];
        foreach ($resources as $path => $bytes) {
            $namespace = 'App\\'.str_replace('/', '\\', substr(dirname($path), 4));
            $rendered = str_replace(['{{ namespace }}', '{{ class }}'], [$namespace, pathinfo($path, PATHINFO_FILENAME)], $bytes);
            if (preg_match('/\{\{.*?\}\}/s', $rendered)) {
                throw new RuntimeException('Unsupported blueprint placeholder.');
            }
            $files[$path] = $rendered;
        }

        return ['source_reference' => $reference, 'manifest' => $this->manifest, 'files' => $files];
    }

    private function resources(): array
    {
        $current = $this->release === null ? self::compose($this->selection())[0] : self::manifestAt(array_values($this->directories)[0]);
        if ($current !== $this->manifest) {
            throw new RuntimeException('Immutable blueprint manifest changed.');
        }
        $resources = [];
        foreach ($this->manifest['generated'] as $path => $entry) {
            $resources[$path] = $this->stub($entry['stub']);
        }

        return $resources;
    }

    private function reference(array $resources): string
    {
        $hashes = array_map(fn (string $bytes): string => hash('sha256', $bytes), $resources);
        ksort($hashes);
        if ($this->release === null) {
            // Every feature's pinned identity is part of the set's identity.
            $reference = 'sha256:'.hash('sha256', json_encode([$this->manifest, $hashes, self::compose($this->selection())[2]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return $reference;
        }
        $reference = 'sha256:'.hash('sha256', json_encode([$this->manifest, $hashes], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if ($reference !== self::REFERENCES[$this->release]) {
            throw new RuntimeException('Immutable blueprint resource identity mismatch.');
        }

        return $reference;
    }

    private static function manifestAt(string $directory): array
    {
        $path = $directory.'/blueprint.json';
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException('Missing or linked blueprint manifest.');
        }

        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public function stubPath(string $stub): string
    {
        if (! isset($this->directories[$stub])) {
            if (! preg_match('/\A[a-z0-9-]+\.php\.stub\z/', $stub)) {
                throw new RuntimeException('Invalid blueprint resource.');
            }
            throw new RuntimeException('Missing or linked blueprint resource.');
        }

        return self::stubIn($this->directories[$stub], $stub);
    }

    private static function stubIn(string $directory, string $stub): string
    {
        if (! preg_match('/\A[a-z0-9-]+\.php\.stub\z/', $stub)) {
            throw new RuntimeException('Invalid blueprint resource.');
        }
        $path = $directory.'/'.$stub;
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException('Missing or linked blueprint resource.');
        }

        return $path;
    }

    public function stub(string $stub): string
    {
        return file_get_contents($this->stubPath($stub));
    }
}
