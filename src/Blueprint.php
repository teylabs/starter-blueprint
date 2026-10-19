<?php

namespace Tey\StarterBlueprint;

use RuntimeException;

final readonly class Blueprint
{
    private const REFERENCES = [
        'A' => 'sha256:21bd170447418e3d697324817f9d9874473b79b949a7e509d877d022ec2e45e2',
        'B' => 'sha256:70af6427bc4a6de682e01d6e1ef0f11553a177fa34fa3d2c238649b2a5ac726c',
        'C' => 'sha256:854058507018a0826f7c5b98aa5e39e24b1cfdde7299d31b8421053fa8a9a3ff',
        'D' => 'sha256:a85ea6fe8922388470fbdfc096e6907b1e397f6c88c38c8dda5ae63fa8217aaf',
    ];

    /** @param array<string, string> $directories stub name => directory that holds it */
    private function __construct(public array $manifest, private array $directories, private ?string $release, private array $features = [], private ?Catalog $catalog = null) {}

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
    public static function featureIds(?Catalog $catalog = null): array
    {
        return ($catalog ?? Catalog::package())->featureIds();
    }

    /** Every feature: the whole app. */
    public static function all(?Catalog $catalog = null): self
    {
        return self::features(self::featureIds($catalog), $catalog);
    }

    /** @param list<string> $ids */
    public static function features(array $ids, ?Catalog $catalog = null): self
    {
        $catalog ??= Catalog::package();
        [$manifest, $directories, $features] = self::compose($ids, $catalog);
        $blueprint = new self($manifest, $directories, null, $features, $catalog);
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
    private static function compose(array $ids, Catalog $catalog): array
    {
        $manifests = [];
        foreach ($catalog->featureIds() as $id) {
            if (in_array($id, $ids, true)) {
                $manifests[$id] = self::featureAt($catalog, $id);
            }
        }
        self::validateFeatures($manifests, $ids);
        $ids = array_values(array_intersect($catalog->featureIds(), $ids));
        $base = self::baseManifest($catalog);
        $manifest = ['format' => $base['format'], 'id' => $base['id'], 'version' => $base['version'], 'groups' => $base['groups'], 'requirements' => [], 'generated' => [], 'host_owned' => []];
        $directories = [];
        $hashes = [];
        foreach ($ids as $id) {
            $feature = $manifests[$id];
            $manifest['requirements'] += $feature['requires']['packages'];
            $manifest['generated'] += $feature['files'];
            $manifest['host_owned'] = array_values(array_unique(array_merge($manifest['host_owned'], $feature['host_owned'])));
            foreach ($feature['files'] as $entry) {
                $directories[$entry['stub']] = $catalog->directory($id);
            }
            $hashes[$id] = self::featureHash($catalog, $id, $feature);
        }
        foreach (['migration_order', 'verification', 'auth', 'exceptions'] as $field) {
            $manifest[$field] = $base[$field];
        }
        $manifest['features'] = $ids;

        return [$manifest, $directories, $hashes];
    }

    private static function baseManifest(Catalog $catalog): array
    {
        $base = self::manifestAt($catalog->root);
        if ('sha256:'.hash('sha256', file_get_contents($catalog->root.'/blueprint.json')) !== $catalog->basePin) {
            throw new RuntimeException('Immutable blueprint resource identity mismatch.');
        }

        return $base;
    }

    /** Reads one feature manifest, with any `files_from` stub tree expanded into `files`. */
    private static function featureAt(Catalog $catalog, string $id): array
    {
        $directory = $catalog->directory($id);
        $path = $directory.'/feature.json';
        if (is_link($directory) || ! is_file($path) || is_link($path)) {
            throw new RuntimeException('Missing or linked feature manifest.');
        }
        $feature = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($feature) || ! array_key_exists('files_from', $feature)) {
            return $feature;
        }

        return ['files' => self::expand($directory, $feature)] + $feature;
    }

    /**
     * Expands `files_from` into the map an explicit `files` would give: explicit entries first, then the
     * tree's files sorted by relative path. A `.stub` suffix is dropped from the target name.
     *
     * @return array<string, array{stub: string, scaffold: string, ownership: string}>
     */
    private static function expand(string $directory, array $feature): array
    {
        $from = $feature['files_from'];
        if (! is_array($from) || array_diff(array_keys($from), ['stubs', 'target', 'scaffold']) !== [] || ! is_string($from['stubs'] ?? null) || ! is_string($from['target'] ?? null) || ! is_string($from['scaffold'] ?? 'starter-foundation')) {
            throw new RuntimeException('Invalid files_from declaration.');
        }
        $files = $feature['files'] ?? [];
        if (! is_array($files)) {
            throw new RuntimeException('Invalid feature files.');
        }
        $stubs = self::relative($from['stubs']);
        $target = self::relative($from['target']);
        $owns = $feature['owns'] ?? null;
        if (! is_array($owns) || ! array_filter($owns, fn ($prefix): bool => is_string($prefix) && self::within($target, $prefix))) {
            throw new RuntimeException('files_from target is outside feature ownership: '.$target);
        }
        $tree = $directory.'/'.$stubs;
        if (is_link($tree) || ! is_dir($tree) || ! str_starts_with((string) realpath($tree), (string) realpath($directory).'/')) {
            throw new RuntimeException('Missing, linked or escaping files_from stub tree.');
        }
        $found = [];
        $walk = function (string $folder, string $prefix) use (&$walk, &$found): void {
            foreach (scandir($folder, SCANDIR_SORT_NONE) as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $path = $folder.'/'.$name;
                if (is_link($path)) {
                    throw new RuntimeException('Linked stub refused: '.$prefix.$name);
                }
                if (str_starts_with($name, '.')) {
                    continue;
                }
                if (is_dir($path)) {
                    $walk($path, $prefix.$name.'/');
                } elseif (is_file($path)) {
                    $found[] = $prefix.$name;
                } else {
                    throw new RuntimeException('Only regular stub files are allowed: '.$prefix.$name);
                }
            }
        };
        $walk($tree, '');
        sort($found, SORT_STRING);
        foreach ($found as $relative) {
            self::relative($relative);
            $path = $target.'/'.(str_ends_with($relative, '.stub') && strlen($relative) > 5 ? substr($relative, 0, -5) : $relative);
            if (isset($files[$path])) {
                throw new RuntimeException('Duplicate target path in feature: '.$path);
            }
            $files[$path] = ['stub' => $stubs.'/'.$relative, 'scaffold' => $from['scaffold'] ?? 'starter-foundation', 'ownership' => 'generated'];
        }

        return $files;
    }

    /** A catalog-relative path: no empty, `.` or `..` segments, no absolute or backslash forms. */
    private static function relative(string $path): string
    {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*(\/[A-Za-z0-9][A-Za-z0-9._-]*)*\z/', $path)) {
            throw new RuntimeException('Invalid catalog path: '.$path);
        }

        return $path;
    }

    /** Content-addresses one feature: its manifest bytes and every stub it owns. */
    private static function featureHash(Catalog $catalog, string $id, array $feature): string
    {
        $directory = $catalog->directory($id);
        $stubs = [];
        foreach ($feature['files'] as $entry) {
            $path = self::stubIn($directory, $entry['stub']);
            $stubs[$entry['stub']] = hash('sha256', file_get_contents($path));
        }
        ksort($stubs);
        $hash = 'sha256:'.hash('sha256', json_encode([hash_file('sha256', $directory.'/feature.json'), $stubs], JSON_THROW_ON_ERROR));
        if ($hash !== $catalog->pins[$id]) {
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
        $current = $this->release === null ? self::compose($this->selection(), $this->catalog)[0] : self::manifestAt(array_values($this->directories)[0]);
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
            $reference = 'sha256:'.hash('sha256', json_encode([$this->manifest, $hashes, self::compose($this->selection(), $this->catalog)[2]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

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
        if (! preg_match('/\A\.?[A-Za-z0-9][A-Za-z0-9._-]*(\/\.?[A-Za-z0-9][A-Za-z0-9._-]*)*\z/', $stub)) {
            throw new RuntimeException('Invalid blueprint resource.');
        }
        $path = $directory.'/'.$stub;
        if ($stub !== basename($stub) && ! self::inside($path, $directory)) {
            throw new RuntimeException('Missing or linked blueprint resource.');
        }
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException('Missing or linked blueprint resource.');
        }

        return $path;
    }

    /** True when no part of the path below the directory is a link. */
    private static function inside(string $path, string $directory): bool
    {
        $current = $directory;
        foreach (explode('/', substr($path, strlen($directory) + 1)) as $segment) {
            $current .= '/'.$segment;
            if (is_link($current)) {
                return false;
            }
        }

        return true;
    }

    public function stub(string $stub): string
    {
        return file_get_contents($this->stubPath($stub));
    }
}
