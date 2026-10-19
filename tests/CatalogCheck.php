<?php

// External catalogs, stub trees and the catalog host; no consumer acceptance.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Tey\Mod\ModServiceProvider;
use Tey\StarterBlueprint\Blueprint;
use Tey\StarterBlueprint\BlueprintServiceProvider;
use Tey\StarterBlueprint\Catalog;
use Tey\StarterBlueprint\CatalogHost;
use Tey\StarterBlueprint\Lifecycle;
use Tey\StarterBlueprint\LifecycleHost;

$loader = require $argv[1];
$loader->addPsr4('Tey\\StarterBlueprint\\', dirname(__DIR__).'/src', true);

const FIXTURE_PINS = [
    'core' => 'sha256:eb585a2b71c76ae11ff8c12a75a0a8d49b4aa9e7275a29dc256766a51a53eb19',
    'workspaces' => 'sha256:7268f0b08883c256e145d454e447a6dad62fca0c70930d5f32b86c5afc165189',
    'access' => 'sha256:fe438afd0f51ee5e5db4cb321043ba0e1981042dff012ca9b7751b19c335b368',
    'settings' => 'sha256:ddb121e2a751e26f86a54e77931343c258ab49dbab90ab5bb4ec6dde7e03d59d',
    'session-ui' => 'sha256:5f9f50d45fe8f7e13b7d601879fad2460c7450a2f4d8ba83e240af45136abaa3',
    'mcp' => 'sha256:4a9f3c2376be0ba1a7bbe4e757332af3cfb933d82dc5df8297d379aa580be782',
    'notes' => 'sha256:358299f2822501be5ff675d99feae6391e0a94d3b9cb3270a23647307a45d0eb',
];
const FIXTURE_BASE = 'sha256:3a2a9567b899aaa739cdf595a5dd622c91edded60b1a637698faa5579f96944a';

function catalogCheck(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
    echo 'PASS '.$message."\n";
}
function catalogRefuses(Closure $operation, string $contains, string $message): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        catalogCheck(str_contains($error->getMessage(), $contains), $message.' ('.$error->getMessage().')');

        return;
    }
    throw new RuntimeException('Expected refusal: '.$message);
}
function fixtureCatalog(): Catalog
{
    return Catalog::at(__DIR__.'/fixtures/catalog', FIXTURE_PINS, FIXTURE_BASE);
}
/** A disposable copy of the fixture catalog, with the pins its callers re-derive after editing it. */
function copyCatalog(): string
{
    $copy = sys_get_temp_dir().'/blueprint-catalog-copy-'.bin2hex(random_bytes(8));
    $source = __DIR__.'/fixtures/catalog';
    mkdir($copy, 0700);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
        $path = $copy.substr($entry->getPathname(), strlen($source));
        $entry->isDir() ? mkdir($path, 0700) : file_put_contents($path, file_get_contents($entry->getPathname()));
    }

    return $copy;
}
/** Pins computed independently of Blueprint: manifest bytes plus every file under the feature except dotfiles. */
function pinsOf(string $root): array
{
    $pins = [];
    foreach (array_keys(FIXTURE_PINS) as $id) {
        $stubs = [];
        $directory = $root.'/'.$id;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = substr($file->getPathname(), strlen($directory) + 1);
            $listed = str_contains(file_get_contents($directory.'/feature.json'), '"'.$relative.'"');
            if ($relative !== 'feature.json' && ! is_dir($file->getPathname()) && (! str_contains('/'.$relative, '/.') || $listed)) {
                $stubs[$relative] = hash('sha256', file_get_contents($file->getPathname()));
            }
        }
        ksort($stubs);
        $pins[$id] = 'sha256:'.hash('sha256', json_encode([hash_file('sha256', $directory.'/feature.json'), $stubs]));
    }

    return $pins;
}
function repin(string $root): Catalog
{
    return Catalog::at($root, pinsOf($root), 'sha256:'.hash_file('sha256', $root.'/blueprint.json'));
}
function editManifest(string $root, string $id, Closure $edit): void
{
    $path = $root.'/'.$id.'/feature.json';
    file_put_contents($path, json_encode($edit(json_decode(file_get_contents($path), true)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}

try {
    // The package catalog keeps its v0.1 identities.
    $package = Catalog::package();
    catalogCheck($package->featureIds() === ['foundation', 'defaults'] && Blueprint::featureIds() === ['foundation', 'defaults'], 'package catalog lists foundation then defaults');
    catalogCheck(str_starts_with($package->pins['foundation'], 'sha256:8e2e55ee') && str_ends_with($package->pins['foundation'], '4054') && str_starts_with($package->pins['defaults'], 'sha256:fb252b8b') && str_ends_with($package->pins['defaults'], '2e43'), 'foundation and defaults pins are the v0.1.0 ones');
    catalogCheck(Blueprint::all()->sourceReference() === Blueprint::all($package)->sourceReference() && Blueprint::all()->sourceReference() === 'sha256:a7324f255993fe2b2207c471ab03f28bbcaac02ba0af9dd61fc38cf3e2475b9e', 'the default catalog is the package catalog, with the v0.1 identity');
    catalogCheck(Blueprint::features(['foundation'], $package)->sourceReference() === Blueprint::features(['foundation'])->sourceReference(), 'an explicit package catalog changes nothing');
    $releases = ['A' => 'sha256:21bd170447418e3d697324817f9d9874473b79b949a7e509d877d022ec2e45e2', 'B' => 'sha256:70af6427bc4a6de682e01d6e1ef0f11553a177fa34fa3d2c238649b2a5ac726c', 'C' => 'sha256:854058507018a0826f7c5b98aa5e39e24b1cfdde7299d31b8421053fa8a9a3ff', 'D' => 'sha256:a85ea6fe8922388470fbdfc096e6907b1e397f6c88c38c8dda5ae63fa8217aaf'];
    foreach ($releases as $release => $identity) {
        catalogCheck(Blueprint::release($release)->sourceReference() === $identity, 'release '.$release.' identity is unchanged');
    }

    // An external catalog builds with its own pins.
    $catalog = fixtureCatalog();
    $all = Blueprint::all($catalog);
    catalogCheck($all->selection() === array_keys(FIXTURE_PINS) && $all->manifest['id'] === 'fixture/catalog-app', 'the external catalog loads all seven features in its own order');
    catalogCheck($all->sourceReference() !== Blueprint::all()->sourceReference(), 'an external catalog has its own identity');
    catalogCheck(Blueprint::features(['notes', 'workspaces', 'core'], $catalog)->sourceReference() === Blueprint::features(['core', 'workspaces', 'notes'], $catalog)->sourceReference(), 'selection order does not change an external identity');
    catalogCheck($all->sourceReference() === Blueprint::all($catalog)->sourceReference(), 'the same external selection produces the same identity');
    catalogCheck(count($all->snapshot()['files']) === 10, 'the whole fixture app generates ten files');
    $files = Blueprint::features(['core', 'workspaces'], $catalog)->snapshot()['files'];
    catalogCheck(str_contains($files['app/Workspaces/Concerns/BelongsToWorkspace.php'], 'namespace App\\Workspaces\\Concerns;') && str_contains($files['app/Workspaces/Concerns/BelongsToWorkspace.php'], 'final class BelongsToWorkspace'), 'tree stubs render with their own namespace and class');
    catalogCheck(Blueprint::features(['core'], $catalog)->sourceReference() !== Blueprint::features(['core', 'workspaces'], $catalog)->sourceReference(), 'a larger external selection has a different identity');
    catalogCheck(Blueprint::features(['core'], $catalog)->stub('core-marker.php.stub') !== '', 'an external stub reads from the catalog');
    Blueprint::assertGrowth(Blueprint::features(['core'], $catalog), Blueprint::features(['core', 'workspaces'], $catalog));
    catalogRefuses(fn () => Blueprint::assertGrowth(Blueprint::features(['core', 'workspaces'], $catalog), Blueprint::features(['core'], $catalog)), 'Feature removal is refused: workspaces', 'external features can only be added');

    // Catalog values are validated.
    catalogRefuses(fn () => Catalog::at(__DIR__.'/fixtures/missing', FIXTURE_PINS, FIXTURE_BASE), 'catalog root', 'a missing root refuses');
    catalogRefuses(fn () => Catalog::at(__DIR__.'/fixtures/catalog', ['../core' => FIXTURE_PINS['core']], FIXTURE_BASE), 'Invalid catalog feature id', 'a path-like feature id refuses');
    catalogRefuses(fn () => Catalog::at(__DIR__.'/fixtures/catalog', ['core' => 'sha256:abc'], FIXTURE_BASE), 'Invalid catalog pin', 'a malformed pin refuses');
    catalogRefuses(fn () => Catalog::at(__DIR__.'/fixtures/catalog', FIXTURE_PINS, 'nope'), 'base pin', 'a malformed base pin refuses');
    catalogRefuses(fn () => Catalog::at(__DIR__.'/fixtures/catalog', [], FIXTURE_BASE), 'at least one', 'an empty catalog refuses');

    // Identity checks: a wrong pin, a tampered stub, manifest or base is refused.
    $wrong = FIXTURE_PINS;
    $wrong['workspaces'] = FIXTURE_PINS['core'];
    $bad = Catalog::at(__DIR__.'/fixtures/catalog', $wrong, FIXTURE_BASE);
    catalogRefuses(fn () => Blueprint::features(['core', 'workspaces'], $bad), 'identity mismatch', 'a wrong feature pin refuses');
    catalogCheck(Blueprint::features(['core'], $bad)->selection() === ['core'], 'a wrong pin on an unselected feature does not block the others');
    catalogRefuses(fn () => Blueprint::all(Catalog::at(__DIR__.'/fixtures/catalog', FIXTURE_PINS, FIXTURE_PINS['core'])), 'identity mismatch', 'a wrong base pin refuses');
    $copy = copyCatalog();
    $pinned = repin($copy);
    catalogCheck(Blueprint::all($pinned)->sourceReference() === $all->sourceReference(), 'an unmodified copy re-derives the same pins and identity');
    file_put_contents($copy.'/workspaces/stubs/Workspace.php.stub', file_get_contents($copy.'/workspaces/stubs/Workspace.php.stub').'// changed');
    catalogRefuses(fn () => Blueprint::all($pinned), 'identity mismatch', 'a tampered tree stub refuses');
    $repinned = repin($copy);
    $built = Blueprint::all($repinned);
    file_put_contents($copy.'/core/core-marker.php.stub', 'tampered after build');
    catalogRefuses(fn () => $built->sourceReference(), 'identity mismatch', 'a stub tampered after the blueprint was built refuses');
    catalogRefuses(fn () => $built->snapshot(), 'identity mismatch', 'a tampered stub never reaches a snapshot');
    $copy = copyCatalog();
    $pinned = repin($copy);
    file_put_contents($copy.'/mcp/mcp-entry.php.stub', 'tampered');
    catalogRefuses(fn () => Blueprint::features(['workspaces', 'core', 'mcp'], $pinned), 'identity mismatch', 'a tampered explicit stub refuses');
    $copy = copyCatalog();
    $pinned = repin($copy);
    editManifest($copy, 'access', fn (array $m): array => $m + ['note' => 'changed']);
    catalogRefuses(fn () => Blueprint::all($pinned), 'identity mismatch', 'a tampered feature manifest refuses');
    $copy = copyCatalog();
    $pinned = repin($copy);
    file_put_contents($copy.'/blueprint.json', str_replace('fixture/catalog-app', 'other/app', file_get_contents($copy.'/blueprint.json')));
    catalogRefuses(fn () => Blueprint::all($pinned), 'identity mismatch', 'a tampered base manifest refuses');
    $copy = copyCatalog();
    $pinned = repin($copy);
    file_put_contents($copy.'/workspaces/stubs/Extra.php.stub', '<?php // added');
    catalogRefuses(fn () => Blueprint::all($pinned), 'identity mismatch', 'a stub added to a tree refuses');
    $copy = copyCatalog();
    $pinned = repin($copy);
    unlink($copy.'/notes/stubs/Note.php.stub');
    catalogRefuses(fn () => Blueprint::all($pinned), 'identity mismatch', 'a stub removed from a tree refuses');
    $copy = copyCatalog();
    $pinned = repin($copy);
    unlink($copy.'/core/feature.json');
    catalogRefuses(fn () => Blueprint::features(['core'], $pinned), 'Missing or linked feature manifest', 'a missing feature manifest refuses');

    // Requires and conflicts over the seven-feature graph.
    $graph = ['workspaces' => ['core'], 'access' => ['workspaces'], 'settings' => ['workspaces', 'access'], 'session-ui' => ['workspaces'], 'mcp' => ['workspaces'], 'notes' => ['workspaces']];
    foreach ($graph as $id => $requires) {
        $manifest = json_decode(file_get_contents(__DIR__.'/fixtures/catalog/'.$id.'/feature.json'), true);
        catalogCheck($manifest['requires']['features'] === $requires, 'fixture '.$id.' requires '.implode(' and ', $requires));
    }
    catalogRefuses(fn () => Blueprint::features(['workspaces'], $catalog), 'requires feature core', 'workspaces without core refuses');
    catalogRefuses(fn () => Blueprint::features(['core', 'notes'], $catalog), 'Feature notes requires feature workspaces', 'notes without workspaces refuses');
    catalogRefuses(fn () => Blueprint::features(['core', 'workspaces', 'settings'], $catalog), 'Feature settings requires feature access', 'settings without access refuses');
    catalogRefuses(fn () => Blueprint::features(['core', 'access'], $catalog), 'Feature access requires feature workspaces', 'access without workspaces refuses');
    catalogRefuses(fn () => Blueprint::features(['core', 'nope'], $catalog), 'Unknown feature: nope', 'a feature the catalog does not pin refuses');
    catalogRefuses(fn () => Blueprint::features(['foundation'], $catalog), 'Unknown feature: foundation', 'a package feature is not in an external catalog');
    catalogRefuses(fn () => Blueprint::features(['core', 'core'], $catalog), 'once', 'a duplicate selection refuses');
    foreach ([['core', 'workspaces'], ['core', 'workspaces', 'access', 'settings'], ['core', 'workspaces', 'session-ui', 'mcp']] as $selection) {
        catalogCheck(Blueprint::features($selection, $catalog)->selection() === $selection, 'selection '.implode(',', $selection).' resolves');
    }
    $copy = copyCatalog();
    editManifest($copy, 'mcp', fn (array $m): array => ['conflicts' => ['notes']] + $m);
    $conflicting = repin($copy);
    catalogRefuses(fn () => Blueprint::features(['core', 'workspaces', 'mcp', 'notes'], $conflicting), 'conflicts with feature notes', 'a conflict refuses');
    catalogCheck(Blueprint::features(['core', 'workspaces', 'mcp'], $conflicting)->selection() === ['core', 'workspaces', 'mcp'], 'a conflict only matters when both are selected');
    $copy = copyCatalog();
    editManifest($copy, 'notes', function (array $m): array {
        $m['owns'][] = 'app/Mcp';

        return $m;
    });
    catalogRefuses(fn () => Blueprint::features(['core', 'workspaces', 'mcp', 'notes'], repin($copy)), 'overlapping paths', 'overlapping ownership across features refuses');

    // files_from: expansion.
    $workspaces = Blueprint::features(['core', 'workspaces'], $catalog)->manifest['generated'];
    catalogCheck(array_keys($workspaces) === ['app/Support/Core/CoreMarker.php', 'app/Workspaces/Concerns/BelongsToWorkspace.php', 'app/Workspaces/Workspace.php'], 'a tree expands sorted, drops .stub from targets and skips dotfiles');
    catalogCheck($workspaces['app/Workspaces/Workspace.php'] === ['stub' => 'stubs/Workspace.php.stub', 'scaffold' => 'starter-foundation', 'ownership' => 'generated'], 'expanded entries are generated and carry their stub path');
    $settings = Blueprint::features(['core', 'workspaces', 'access', 'settings'], $catalog)->manifest['generated'];
    catalogCheck(array_slice(array_keys($settings), -2) === ['app/Settings/SettingsPage.php', 'app/Settings/Preferences.php'], 'explicit files come first, then the expanded tree');
    $first = Blueprint::all($catalog);
    catalogCheck(json_encode($first->manifest['generated']) === json_encode(Blueprint::all($catalog)->manifest['generated']), 'expansion is deterministic');

    // Equivalence with an explicit files map, and identity over every expanded byte.
    $copy = copyCatalog();
    $explicit = [];
    foreach (['Workspace.php.stub' => 'Workspace.php', 'Concerns/BelongsToWorkspace.php.stub' => 'Concerns/BelongsToWorkspace.php'] as $stub => $target) {
        $explicit['app/Workspaces/'.$target] = ['stub' => 'stubs/'.$stub, 'scaffold' => 'starter-foundation', 'ownership' => 'generated'];
    }
    ksort($explicit, SORT_STRING);
    $treeManifest = Blueprint::features(['core', 'workspaces'], $catalog)->manifest;
    editManifest($copy, 'workspaces', function (array $m) use ($explicit): array {
        unset($m['files_from']);
        $m['files'] = $explicit;

        return $m;
    });
    $listed = Blueprint::features(['core', 'workspaces'], repin($copy));
    catalogCheck(array_slice($listed->manifest['generated'], 1) === array_slice($treeManifest['generated'], 1) && array_keys($listed->manifest['generated']) === ['app/Support/Core/CoreMarker.php', 'app/Workspaces/Concerns/BelongsToWorkspace.php', 'app/Workspaces/Workspace.php'], 'a stub tree expands to exactly the explicit files map');
    catalogCheck($listed->snapshot()['files'] === Blueprint::features(['core', 'workspaces'], $catalog)->snapshot()['files'], 'both give the same generated bytes');
    catalogCheck($listed->sourceReference() !== Blueprint::features(['core', 'workspaces'], $catalog)->sourceReference(), 'the manifest declaration itself is part of the identity');
    $bytes = [];
    foreach (['workspaces' => ['Workspace.php.stub', 'Concerns/BelongsToWorkspace.php.stub'], 'notes' => ['Note.php.stub', 'Policies/NotePolicy.php.stub'], 'settings' => ['Preferences.php.stub']] as $id => $stubs) {
        foreach ($stubs as $stub) {
            $copy = copyCatalog();
            $pinned = repin($copy);
            $path = $copy.'/'.$id.'/stubs/'.$stub;
            file_put_contents($path, file_get_contents($path)."\n");
            catalogRefuses(fn () => Blueprint::all($pinned), 'identity mismatch', 'identity covers the bytes of '.$id.'/stubs/'.$stub);
            $bytes[] = $stub;
        }
    }
    catalogCheck(count($bytes) === 5, 'every expanded stub was tampered with once');

    // files_from: refusals.
    $copy = copyCatalog();
    symlink($copy.'/core/core-marker.php.stub', $copy.'/workspaces/stubs/Linked.php.stub');
    catalogRefuses(fn () => Blueprint::features(['core', 'workspaces'], repin($copy)), 'Linked stub refused', 'a symlinked stub in a tree refuses');
    $copy = copyCatalog();
    mkdir($copy.'/outside');
    file_put_contents($copy.'/outside/Escape.php.stub', '<?php // outside');
    symlink($copy.'/outside', $copy.'/workspaces/stubs/Escape');
    catalogRefuses(fn () => Blueprint::features(['core', 'workspaces'], repin($copy)), 'Linked stub refused', 'a symlinked directory in a tree refuses');
    $copy = copyCatalog();
    rename($copy.'/workspaces/stubs', $copy.'/stubs-elsewhere');
    symlink($copy.'/stubs-elsewhere', $copy.'/workspaces/stubs');
    catalogRefuses(fn () => Blueprint::features(['core', 'workspaces'], repin($copy)), 'linked or escaping', 'a symlinked stub tree root refuses');
    foreach (['../core', '/etc', 'stubs/../../core', 'stubs//x', './stubs', 'stu bs'] as $stubs) {
        $copy = copyCatalog();
        editManifest($copy, 'workspaces', function (array $m) use ($stubs): array {
            $m['files_from']['stubs'] = $stubs;

            return $m;
        });
        catalogRefuses(fn () => Blueprint::features(['core', 'workspaces'], repin($copy)), 'Invalid catalog path', 'files_from stubs "'.$stubs.'" cannot leave the feature');
    }
    foreach (['../Elsewhere', '/app/Workspaces', 'app/Workspaces/../Access'] as $target) {
        $copy = copyCatalog();
        editManifest($copy, 'workspaces', function (array $m) use ($target): array {
            $m['files_from']['target'] = $target;

            return $m;
        });
        catalogRefuses(fn () => Blueprint::features(['core', 'workspaces'], repin($copy)), 'Invalid catalog path', 'files_from target "'.$target.'" cannot leave the catalog');
    }
    $copy = copyCatalog();
    editManifest($copy, 'workspaces', function (array $m): array {
        $m['files_from']['target'] = 'app/Access';

        return $m;
    });
    catalogRefuses(fn () => Blueprint::features(['core', 'workspaces'], repin($copy)), 'outside feature ownership', 'a target outside the feature owns refuses');
    $copy = copyCatalog();
    editManifest($copy, 'workspaces', function (array $m): array {
        $m['files_from']['target'] = 'app/Workspaces-other';

        return $m;
    });
    catalogRefuses(fn () => Blueprint::features(['core', 'workspaces'], repin($copy)), 'outside feature ownership', 'a sibling path that only shares a prefix refuses');
    $copy = copyCatalog();
    file_put_contents($copy.'/workspaces/stubs/Workspace.php', '<?php // same target as Workspace.php.stub');
    catalogRefuses(fn () => Blueprint::features(['core', 'workspaces'], repin($copy)), 'Duplicate target path', 'two stubs for one target refuse');
    $copy = copyCatalog();
    editManifest($copy, 'workspaces', function (array $m): array {
        $m['files'] = ['app/Workspaces/Workspace.php' => ['stub' => 'stubs/Other.php.stub', 'scaffold' => 'starter-foundation', 'ownership' => 'generated']];

        return $m;
    });
    file_put_contents($copy.'/workspaces/stubs/Other.php.stub', '<?php // listed twice');
    catalogRefuses(fn () => Blueprint::features(['core', 'workspaces'], repin($copy)), 'Duplicate target path', 'a tree target that an explicit file also names refuses');
    $copy = copyCatalog();
    editManifest($copy, 'workspaces', function (array $m): array {
        $m['files_from']['extra'] = true;

        return $m;
    });
    catalogRefuses(fn () => Blueprint::features(['core', 'workspaces'], repin($copy)), 'Invalid files_from', 'an unknown files_from key refuses');
    $copy = copyCatalog();
    editManifest($copy, 'workspaces', function (array $m): array {
        $m['files_from'] = 'stubs';

        return $m;
    });
    catalogRefuses(fn () => Blueprint::features(['core', 'workspaces'], repin($copy)), 'Invalid files_from', 'a malformed files_from refuses');
    $copy = copyCatalog();
    editManifest($copy, 'workspaces', function (array $m): array {
        $m['host_owned'][] = 'app/Workspaces/Concerns';

        return $m;
    });
    catalogRefuses(fn () => Blueprint::features(['core', 'workspaces'], repin($copy)), 'host-owned', 'an expanded path inside a host-owned path refuses');
    $copy = copyCatalog();
    file_put_contents($copy.'/workspaces/stubs/.dotfile', 'skipped');
    mkdir($copy.'/workspaces/stubs/.cache');
    file_put_contents($copy.'/workspaces/stubs/.cache/x.php.stub', 'skipped');
    catalogCheck(array_keys(Blueprint::features(['core', 'workspaces'], repin($copy))->manifest['generated']) === array_keys($workspaces), 'dotfiles and dot directories in a tree are skipped');
    $copy = copyCatalog();
    editManifest($copy, 'workspaces', function (array $m): array {
        $m['files']['app/Workspaces/.hidden'] = ['stub' => 'stubs/.hidden', 'scaffold' => 'starter-foundation', 'ownership' => 'generated'];

        return $m;
    });
    $listedDot = Blueprint::features(['core', 'workspaces'], repin($copy));
    catalogCheck(isset($listedDot->manifest['generated']['app/Workspaces/.hidden']) && $listedDot->snapshot()['files']['app/Workspaces/.hidden'] === "skipped\n", 'a dotfile listed in files is included');

    // CatalogHost drives starter:maintain.
    $adapter = new Lifecycle;
    $root = sys_get_temp_dir().'/blueprint-catalog-host-'.bin2hex(random_bytes(8));
    foreach (['app', 'bootstrap/cache', 'storage/logs', 'config'] as $dir) {
        mkdir($root.'/'.$dir, 0700, true);
    }
    $paths = [];
    foreach (['verification-policy', 'verification-code', 'dependency-lock', 'host-config', 'host-binding', 'host-operations', 'executor-provenance'] as $id) {
        $paths[$id] = $id.'.txt';
        file_put_contents($root.'/'.$paths[$id], 'synthetic fixture '.$id);
    }
    file_put_contents($root.'/executor-provenance.txt', json_encode($adapter->runtime()));
    file_put_contents($root.'/host-operations.txt', '<?php return function () use ($operation) { return $operation(); };');
    $root = realpath($root);
    $operation = fn () => true;
    $check = (require $root.'/host-operations.txt');
    $app = Application::configure($root)->withExceptions(fn ($e) => null)->withProviders([ModServiceProvider::class, BlueprintServiceProvider::class])->create();
    $plain = new class($adapter, $paths, $check) implements LifecycleHost
    {
        public function __construct(private Lifecycle $adapter, private array $paths, private Closure $check) {}

        public function lifecycle(): Lifecycle
        {
            return $this->adapter;
        }

        public function appId(): string
        {
            return 'catalog-host';
        }

        public function bindings(): array
        {
            return ['frontend' => 'SyntheticLayout'];
        }

        public function inputPaths(): array
        {
            return $this->paths;
        }

        public function operationCheck(): Closure
        {
            return $this->check;
        }
    };
    $owned = new class($adapter, $paths, $check, fixtureCatalog()) implements CatalogHost, LifecycleHost
    {
        public function __construct(private Lifecycle $adapter, private array $paths, private Closure $check, private Catalog $catalog) {}

        public function catalog(): Catalog
        {
            return $this->catalog;
        }

        public function lifecycle(): Lifecycle
        {
            return $this->adapter;
        }

        public function appId(): string
        {
            return 'catalog-host';
        }

        public function bindings(): array
        {
            return ['frontend' => 'SyntheticLayout'];
        }

        public function inputPaths(): array
        {
            return $this->paths;
        }

        public function operationCheck(): Closure
        {
            return $this->check;
        }
    };
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    $app->instance(LifecycleHost::class, $plain);
    catalogCheck($kernel->call('starter:maintain', ['action' => 'plan', '--features' => 'core,workspaces']) !== 0, 'a host without a catalog uses the package catalog, which does not have these features');
    catalogCheck($kernel->call('starter:maintain', ['action' => 'plan', '--features' => 'foundation,defaults']) === 0, 'a host without a catalog still plans the package features');
    $packagePlan = json_decode(trim($kernel->output()), true, flags: JSON_THROW_ON_ERROR);
    $app->instance(LifecycleHost::class, $owned);
    catalogCheck($kernel->call('starter:maintain', ['action' => 'plan', '--features' => 'core,workspaces,notes']) === 0, 'a catalog host plans features from its own catalog');
    $plan = json_decode(trim($kernel->output()), true, flags: JSON_THROW_ON_ERROR);
    $expected = $adapter->plan($root, 'catalog-host', null, Blueprint::features(['core', 'workspaces', 'notes'], fixtureCatalog()), ['frontend' => 'SyntheticLayout'], $paths);
    catalogCheck($plan['plan'] === $expected->identity() && $plan['plan'] !== $packagePlan['plan'], 'the command plan is the plan of the catalog host\'s blueprint');
    catalogCheck($kernel->call('starter:maintain', ['action' => 'plan', '--features' => 'foundation']) !== 0, 'a catalog host does not fall back to the package features');
    catalogCheck($kernel->call('starter:maintain', ['action' => 'plan', '--features' => 'notes']) !== 0 && str_contains($kernel->output(), 'requires feature'), 'a catalog host refuses a missing requirement');
    catalogCheck($kernel->call('starter:maintain', ['action' => 'apply', '--features' => 'core,workspaces,notes', '--intent' => 'catalog', '--exclusive-checkout' => true, '--expected-plan' => $plan['plan']]) === 0 && is_file($root.'/app/Workspaces/Concerns/BelongsToWorkspace.php') && is_file($root.'/app/Notes/Policies/NotePolicy.php'), 'a catalog host applies its tree features');
    catalogCheck(! is_file($root.'/app/Support/Starter/BlueprintPolicy.php'), 'no package feature is delivered by a catalog host');
    echo "Catalog checks only; no consumer acceptance.\n";
} catch (Throwable $error) {
    fwrite(STDERR, (string) $error."\n");
    exit(1);
}
