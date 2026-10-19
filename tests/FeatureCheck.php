<?php

// Feature manifests and selections; needs no Mod lifecycle API, so it runs against released Mod.
use Tey\StarterBlueprint\Blueprint;
use Tey\StarterBlueprint\Lifecycle;

$loader = require $argv[1];
$loader->addPsr4('Tey\\StarterBlueprint\\', dirname(__DIR__).'/src', true);

function featureCheck(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
    echo 'PASS '.$message."\n";
}
function featureRefuses(Closure $operation, string $contains, string $message): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        featureCheck(str_contains($error->getMessage(), $contains), $message.' ('.$error->getMessage().')');

        return;
    }
    throw new RuntimeException('Expected refusal: '.$message);
}
function feature(string $id, array $override = []): array
{
    return $override + [
        'format' => 1,
        'id' => $id,
        'owns' => ['app/Support/'.ucfirst($id)],
        'files' => ['app/Support/'.ucfirst($id).'/'.ucfirst($id).'.php' => ['stub' => $id.'.php.stub', 'scaffold' => 'starter-foundation', 'ownership' => 'generated']],
        'requires' => ['features' => [], 'packages' => []],
        'conflicts' => [],
        'host_owned' => ['app/Domain'],
    ];
}
try {
    // Selections of the shipped features.
    $foundation = Blueprint::features(['foundation']);
    $all = Blueprint::all();
    featureCheck($foundation->selection() === ['foundation'] && $all->selection() === ['foundation', 'defaults'], 'selection is recorded in the manifest in canonical order');
    featureCheck(Blueprint::features(['defaults', 'foundation'])->sourceReference() === $all->sourceReference(), 'selection order does not change the identity');
    featureCheck($foundation->sourceReference() !== $all->sourceReference(), 'a larger selection has a different identity');
    featureCheck($all->sourceReference() === Blueprint::all()->sourceReference(), 'the same selection produces the same identity');
    featureCheck(array_keys($all->manifest['generated']) === ['app/Support/Starter/BlueprintPolicy.php', 'app/Support/Starter/Defaults/BlueprintDefaults.php'], 'whole app is every feature');
    featureCheck(! array_key_exists('modules', $all->manifest) && $all->manifest['groups'] === ['workspace-foundation'], 'manifest uses the groups key');
    foreach (['A', 'B', 'C', 'D'] as $release) {
        $manifest = Blueprint::release($release)->manifest;
        featureCheck(! array_key_exists('modules', $manifest) && $manifest['groups'] === ['workspace-foundation'], 'release '.$release.' manifest uses the groups key');
    }
    featureRefuses(fn () => Blueprint::features(['defaults']), 'requires feature foundation', 'missing required feature refuses');
    featureRefuses(fn () => Blueprint::features(['foundation', 'nope']), 'Unknown feature: nope', 'unknown feature refuses');
    featureRefuses(fn () => Blueprint::features(['foundation', 'foundation']), 'once', 'duplicate selection refuses');
    featureRefuses(fn () => Blueprint::features([]), 'at least one', 'empty selection refuses');

    // Manifest validation on synthetic catalogs.
    $catalog = ['alpha' => feature('alpha'), 'beta' => feature('beta')];
    Blueprint::validateFeatures($catalog, ['alpha', 'beta']);
    featureCheck(true, 'valid synthetic catalog passes');
    featureRefuses(fn () => Blueprint::validateFeatures([...$catalog, 'beta' => feature('beta', ['requires' => ['features' => ['gamma'], 'packages' => []]])], ['alpha', 'beta']), 'requires feature gamma', 'missing requirement refuses');
    featureRefuses(fn () => Blueprint::validateFeatures([...$catalog, 'beta' => feature('beta', ['conflicts' => ['alpha']])], ['alpha', 'beta']), 'conflicts with feature alpha', 'conflict refuses');
    featureRefuses(fn () => Blueprint::validateFeatures([...$catalog, 'alpha' => feature('alpha', ['conflicts' => ['beta']])], ['alpha', 'beta']), 'conflicts with feature beta', 'conflict is checked from either side');
    featureRefuses(fn () => Blueprint::validateFeatures([...$catalog, 'beta' => feature('beta', ['files' => ['app/Elsewhere/Beta.php' => ['stub' => 'beta.php.stub', 'scaffold' => 'starter-foundation', 'ownership' => 'generated']]])], ['alpha', 'beta']), 'outside feature ownership', 'path outside ownership refuses');
    featureRefuses(fn () => Blueprint::validateFeatures([...$catalog, 'beta' => feature('beta', ['owns' => ['app/Support'], 'files' => ['app/Support/Alpha/Alpha.php' => ['stub' => 'other.php.stub', 'scaffold' => 'starter-foundation', 'ownership' => 'generated']]])], ['alpha', 'beta']), 'overlapping paths', 'overlapping ownership refuses');
    featureRefuses(fn () => Blueprint::validateFeatures([...$catalog, 'beta' => feature('beta', ['owns' => ['app/Support/Alpha/Alpha.php'], 'files' => ['app/Support/Alpha/Alpha.php' => ['stub' => 'other.php.stub', 'scaffold' => 'starter-foundation', 'ownership' => 'generated']]])], ['alpha', 'beta']), 'overlapping paths', 'a path owned by two features refuses');
    featureRefuses(fn () => Blueprint::validateFeatures([...$catalog, 'beta' => feature('beta', ['files' => ['app/Support/Beta/Beta.php' => ['stub' => 'alpha.php.stub', 'scaffold' => 'starter-foundation', 'ownership' => 'generated']]])], ['alpha', 'beta']), 'Duplicate stub', 'duplicate stub across features refuses');
    featureRefuses(fn () => Blueprint::validateFeatures([...$catalog, 'beta' => feature('beta', ['owns' => ['app/Domain/Beta'], 'files' => ['app/Domain/Beta/Beta.php' => ['stub' => 'beta.php.stub', 'scaffold' => 'starter-foundation', 'ownership' => 'generated']]])], ['alpha', 'beta']), 'host-owned', 'generated path inside a host-owned path refuses');
    featureRefuses(fn () => Blueprint::validateFeatures([...$catalog, 'alpha' => feature('alpha', ['requires' => ['features' => [], 'packages' => ['a/b' => '^1.0']]]), 'beta' => feature('beta', ['requires' => ['features' => [], 'packages' => ['a/b' => '^2.0']]])], ['alpha', 'beta']), 'disagree', 'conflicting package constraints refuse');
    featureRefuses(fn () => Blueprint::validateFeatures(['alpha' => ['id' => 'alpha']], ['alpha']), 'Missing feature field', 'incomplete manifest refuses');

    // Growth: add only.
    Blueprint::assertGrowth(null, $all);
    Blueprint::assertGrowth($foundation, $all);
    Blueprint::assertGrowth($all, $all);
    featureCheck(true, 'first adoption, additive upgrade and the same selection are allowed');
    $before = $foundation->snapshot()['files'];
    $after = $all->snapshot()['files'];
    featureCheck(array_intersect_key($after, $before) === $before && count($after) === 2, 'additive upgrade keeps existing bytes and adds the new feature file');
    featureCheck($foundation->manifest['id'] === $all->manifest['id'] && $foundation->manifest['groups'] === $all->manifest['groups'] && $foundation->manifest['version'] === $all->manifest['version'], 'both selections share artifact name, groups and version');
    featureRefuses(fn () => Blueprint::assertGrowth($all, $foundation), 'Feature removal is refused: defaults', 'removal is refused with a clear message');
    featureRefuses(fn () => Blueprint::assertGrowth(Blueprint::release('C'), $all), 'cannot be upgraded into each other', 'release fixture and feature selection stay apart');
    $adapter = new Lifecycle;
    featureRefuses(fn () => $adapter->plan(sys_get_temp_dir(), 'removal', $all, $foundation, [], []), 'Feature removal is refused', 'adapter plan refuses removal before any other check');
    featureRefuses(fn () => $adapter->resume(sys_get_temp_dir(), 'removal', $all, $foundation, [], [], 'removal', fn () => true, true), 'Feature removal is refused', 'adapter resume refuses removal before any other check');
    echo "Feature checks only; no lifecycle or consumer acceptance.\n";
} catch (Throwable $error) {
    fwrite(STDERR, (string) $error."\n");
    exit(1);
}
