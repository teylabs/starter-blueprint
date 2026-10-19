<?php

// Disposable adapter tests against the released tey/mod in vendor, not product/tenant/browser acceptance.
use Composer\InstalledVersions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Tey\Mod\Maintenance\Artifact;
use Tey\Mod\Maintenance\Maintenance;
use Tey\Mod\ModServiceProvider;
use Tey\StarterBlueprint\Blueprint;
use Tey\StarterBlueprint\BlueprintServiceProvider;
use Tey\StarterBlueprint\Lifecycle;
use Tey\StarterBlueprint\LifecycleHost;

$loader = require $argv[1];
$package = $argv[2] ?? dirname(__DIR__);
$loader->addPsr4('Tey\\StarterBlueprint\\', $package.'/src', true);
function check(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
    echo 'PASS '.$message."\n";
}
function refuses(Closure $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        check(true, $message.' ('.$error::class.')');

        return;
    }
    throw new RuntimeException('Expected refusal: '.$message);
}
function fixture(): array
{
    $root = sys_get_temp_dir().'/blueprint-adapter-'.bin2hex(random_bytes(8));
    foreach (['app/Support/Starter/Defaults', 'bootstrap/cache', 'storage/logs', 'config'] as $dir) {
        mkdir($root.'/'.$dir, 0700, true);
    }
    $paths = [];
    foreach (['verification-policy', 'verification-code', 'dependency-lock', 'host-config', 'host-binding', 'host-operations', 'executor-provenance'] as $id) {
        $paths[$id] = $id.'.txt';
        file_put_contents($root.'/'.$paths[$id], 'synthetic fixture '.$id);
    }
    file_put_contents($root.'/executor-provenance.txt', json_encode((new Lifecycle)->runtime()));
    file_put_contents($root.'/host-operations.txt', '<?php return function () use ($operation) { return $operation(); };');
    file_put_contents($root.'/app/Host.php', '<?php // independently owned domain');

    return [realpath($root), $paths];
}
function bound(string $root, Closure $operation): Closure
{
    return require $root.'/host-operations.txt';
}
function record(string $root): ?string
{
    return is_file($root.'/.mod-maintenance/installed.json') ? file_get_contents($root.'/.mod-maintenance/installed.json') : null;
}
try {
    $adapter = new Lifecycle;
    $modPath = realpath(InstalledVersions::getInstallPath('tey/mod'));
    check(str_starts_with(InstalledVersions::getPrettyVersion('tey/mod'), 'v0.8.') && str_contains($modPath, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'tey'.DIRECTORY_SEPARATOR.'mod'), 'lifecycle runs on the released tey/mod '.InstalledVersions::getPrettyVersion('tey/mod').' installed in vendor');
    check((new ReflectionClass(Maintenance::class))->getFileName() === $modPath.'/src/Maintenance/Maintenance.php', 'Maintenance API loads from the vendor package, not a local source');
    check($adapter->runtime() === ['installed_mod_version' => InstalledVersions::getPrettyVersion('tey/mod')], 'executor runtime is the installed released version');
    check(Lifecycle::compatibility(['php' => '^99', 'missing/peer' => '^1']) === ['php' => 'failed', 'missing/peer' => 'failed'], 'actual PHP and missing peer incompatibility');
    $probe = $argv[3] ?? null;
    if ($probe === 'resource-before') {
        $release = $argv[4];
        refuses(fn () => Blueprint::release($release), 'tampered immutable resource or manifest refuses before rendering');
        exit(0);
    }
    $c = Blueprint::release('C');
    $d = Blueprint::release('D');
    if (in_array($probe, ['resource-after', 'manifest-after'], true)) {
        [$root, $paths] = fixture();
        $plan = $adapter->plan($root, 'resource-drift', null, $c, [], $paths);
        $result = $adapter->apply($plan, null, $c, $paths, 'resource-drift', bound($root, function () use ($package, $probe) {
            $path = $package.'/resources/releases/C/'.($probe === 'resource-after' ? 'policy.php.stub' : 'blueprint.json');
            $bytes = file_get_contents($path);
            file_put_contents($path, $probe === 'resource-after' ? $bytes.'// changed' : str_replace('0.3.0', '0.3.1', $bytes));

            return true;
        }), true);
        check($result['status'] === 'verification_failed' && record($root) === null, 'actual package '.$probe.' host callback drift blocks record');
        exit(0);
    }
    check(! in_array('failed', Lifecycle::compatibility($c->manifest['requirements']), true), 'actual installed peer constraints satisfy selected blueprint');
    check($c->manifest['requirements']['tey/mod'] === '^0.8', 'blueprint requires released Mod 0.8');
    [$root, $paths] = fixture();
    $bindings = ['frontend' => 'SyntheticLayout'];
    $validExecutorInput = file_get_contents($root.'/'.$paths['executor-provenance']);
    $forged = ['installed_mod_version' => '0.0.1'];
    file_put_contents($root.'/'.$paths['executor-provenance'], json_encode($forged));
    refuses(fn () => $adapter->plan($root, 'adapter-one', null, $c, $bindings, $paths), 'executor provenance input requires exact typed verified runtime');
    file_put_contents($root.'/'.$paths['executor-provenance'], $validExecutorInput);

    $install = $adapter->plan($root, 'adapter-one', null, $c, $bindings, $paths);
    check($install->executable() && record($root) === null, 'absent ownership preview has no installed receipt');
    $missing = $paths;
    unset($missing['host-operations']);
    refuses(fn () => $adapter->plan($root, 'adapter-one', null, $c, $bindings, $missing), 'missing actual host operation implementation refuses');
    $calls = 0;
    $host = function () use (&$calls): bool {
        $calls++;

        return true;
    };
    refuses(fn () => $adapter->apply($install, null, $c, $paths, 'foreign-check', $host, true), 'unbound actual host check implementation refuses before effects');
    $result = $adapter->apply($install, null, $c, $paths, 'install', bound($root, $host), true);
    check($result['status'] === 'completed' && $calls === 1, 'synthetic host check plus package checks install C');
    $installed = json_decode(record($root), true, flags: JSON_THROW_ON_ERROR);
    check($installed['artifact']['metadata'] === $adapter->artifact($c, $bindings)->metadata, 'authoritative artifact records full source manifest provenance');
    check($installed['artifact']['metadata']['applied_migration_ids'] === [], 'no migrations executed or falsely recorded');
    $before = record($root);
    $again = $adapter->resume($root, 'adapter-one', null, $c, $bindings, $paths, 'install', bound($root, fn () => throw new RuntimeException('must not run')), true);
    check($again['no_op'] && record($root) === $before, 'exact committed cold retry skips callbacks and record writes');
    refuses(fn () => $adapter->resume($root, 'adapter-one', null, $d, $bindings, $paths, 'install', bound($root, $host), true), 'same intent changed source and metadata refuses');
    refuses(fn () => $adapter->resume($root, 'adapter-one', null, $c, ['frontend' => 'Different'], $paths, 'install', bound($root, $host), true), 'same intent changed selection refuses');
    $upgrade = $adapter->plan($root, 'adapter-one', $c, $d, $bindings, $paths);
    $failed = $adapter->apply($upgrade, $c, $d, $paths, 'upgrade', bound($root, fn () => false), true);
    check($failed['status'] === 'verification_failed' && record($root) === $before, 'false host check keeps installed C despite file delivery');
    $errored = $adapter->resume($root, 'adapter-one', $c, $d, $bindings, $paths, 'upgrade', bound($root, fn () => throw new RuntimeException('host error')), true);
    check($errored['status'] === 'verification_failed' && record($root) === $before, 'throwing host check cannot advance version');
    $result = $adapter->resume($root, 'adapter-one', $c, $d, $bindings, $paths, 'upgrade', bound($root, $host), true);
    check($result['status'] === 'completed' && file_get_contents($root.'/app/Host.php') === '<?php // independently owned domain', 'resume finishes D and preserves host code');
    $recordD = record($root);
    file_put_contents($root.'/'.$paths['host-binding'], 'changed binding code');
    refuses(fn () => $adapter->resume($root, 'adapter-one', $c, $d, $bindings, $paths, 'upgrade', bound($root, $host), true), 'completed retry changed bound implementation refuses');
    check(record($root) === $recordD, 'input refusal preserves installed record');
    [$root, $paths] = fixture();
    $plan = $adapter->plan($root, 'adapter-two', null, $c, $bindings, $paths);
    file_put_contents($root.'/'.$paths['dependency-lock'], 'stale lock');
    refuses(fn () => $adapter->apply($plan, null, $c, $paths, 'stale', bound($root, $host), true), 'stale prepared lock refuses before delivery');
    check(record($root) === null && ! is_file($root.'/app/Support/Starter/BlueprintPolicy.php'), 'stale plan preserves initial absence');
    $plan = $adapter->plan($root, 'adapter-two', null, $c, $bindings, $paths);
    $adapter->apply($plan, null, $c, $paths, 'install', bound($root, $host), true);
    $before = record($root);
    file_put_contents($root.'/app/Support/Starter/BlueprintPolicy.php', 'host customisation');
    $plan = $adapter->plan($root, 'adapter-two', $c, $d, $bindings, $paths);
    check(! $plan->executable(), 'customised generated file is explicit conflict');
    refuses(fn () => $adapter->apply($plan, $c, $d, $paths, 'conflict', bound($root, $host), true), 'conflicting upgrade refuses');
    check(record($root) === $before && file_get_contents($root.'/app/Support/Starter/BlueprintPolicy.php') === 'host customisation', 'conflict preserves host bytes and installed version');
    [$root, $paths] = fixture();
    file_put_contents($root.'/app/Support/Starter/BlueprintPolicy.php', $c->snapshot()['files']['app/Support/Starter/BlueprintPolicy.php']);
    $plan = $adapter->plan($root, 'unowned', null, $c, $bindings, $paths);
    refuses(fn () => $adapter->apply($plan, null, $c, $paths, 'unowned', bound($root, $host), true), 'identical but unowned generated file cannot be adopted');
    check(record($root) === null, 'unowned collision never records ownership');
    // Feature selections grow on one artifact: adopt without a baseline, add, never remove.
    $foundation = Blueprint::features(['foundation']);
    $everything = Blueprint::all();
    [$root, $paths] = fixture();
    $plan = $adapter->plan($root, 'features-one', null, $foundation, $bindings, $paths);
    check($plan->executable() && count($plan->target->files) === 1, 'first adoption of one feature plans from an empty original');
    $target = $plan->target;
    check($plan->baseline->metadata === [] && $plan->baseline->identity() === (new Artifact($target->name, $target->version, [], $target->groups, $target->bindings, $target->checks))->identity(), 'first-adoption original carries no metadata, exactly as Mod builds its own empty original');
    $adapter->apply($plan, null, $foundation, $paths, 'adopt-foundation', bound($root, $host), true);
    $installed = json_decode(record($root), true, flags: JSON_THROW_ON_ERROR);
    check($installed['artifact']['metadata']['blueprint_features'] === ['foundation'], 'installed artifact records the selected features');
    check(! is_file($root.'/app/Support/Starter/Defaults/BlueprintDefaults.php'), 'unselected feature file is not delivered');
    $grow = $adapter->plan($root, 'features-one', $foundation, $everything, $bindings, $paths);
    check($grow->executable(), 'adding a feature is an executable upgrade on the same artifact');
    $result = $adapter->apply($grow, $foundation, $everything, $paths, 'add-defaults', bound($root, $host), true);
    check($result['status'] === 'completed' && is_file($root.'/app/Support/Starter/Defaults/BlueprintDefaults.php') && file_get_contents($root.'/app/Host.php') === '<?php // independently owned domain', 'additive upgrade delivers the new feature and preserves host code');
    $installed = json_decode(record($root), true, flags: JSON_THROW_ON_ERROR);
    check($installed['artifact']['metadata']['blueprint_features'] === ['foundation', 'defaults'], 'installed artifact records the larger selection');
    $again = $adapter->resume($root, 'features-one', $foundation, $everything, $bindings, $paths, 'add-defaults', bound($root, fn () => throw new RuntimeException('must not run')), true);
    check($again['no_op'] && $again['status'] === 'completed', 'completed additive upgrade retries cold from the receipt without callbacks');
    $before = record($root);
    refuses(fn () => $adapter->plan($root, 'features-one', $foundation, $everything, ['frontend' => 'Different'], $paths), 'upgrade original with other bindings is not the installed artifact');
    try {
        $adapter->plan($root, 'features-one', $foundation, $everything, ['frontend' => 'Different'], $paths);
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'exactly the installed artifact'), 'the adapter names the mismatch itself');
    }
    refuses(fn () => $adapter->plan($root, 'features-one', $everything, $foundation, $bindings, $paths), 'removing a feature refuses');
    check(record($root) === $before, 'refused removal leaves the installed record untouched');
    [$root, $paths] = fixture();
    $plan = $adapter->plan($root, 'features-two', null, $everything, $bindings, $paths);
    $result = $adapter->apply($plan, null, $everything, $paths, 'adopt-all', bound($root, $host), true);
    check($result['status'] === 'completed' && is_file($root.'/app/Support/Starter/BlueprintPolicy.php') && is_file($root.'/app/Support/Starter/Defaults/BlueprintDefaults.php'), 'whole app adopts as all features at once');
    [$root, $paths] = fixture();
    file_put_contents($root.'/app/Support/Starter/BlueprintPolicy.php', $foundation->snapshot()['files']['app/Support/Starter/BlueprintPolicy.php']);
    $plan = $adapter->plan($root, 'features-unowned', null, $foundation, $bindings, $paths);
    refuses(fn () => $adapter->apply($plan, null, $foundation, $paths, 'unowned-feature', bound($root, $host), true), 'first adoption refuses an existing unowned path even with matching bytes');
    check(record($root) === null, 'unowned feature collision never records ownership');
    // Actual Laravel console adapter, with an explicit synthetic host binding.
    [$root, $paths] = fixture();
    $app = Application::configure($root)->withExceptions(fn ($e) => null)->withProviders([ModServiceProvider::class, BlueprintServiceProvider::class])->create();
    $hostBinding = new class($adapter, $paths, $root) implements LifecycleHost
    {
        public bool $passes = false;

        public function __construct(private Lifecycle $adapter, private array $paths, private string $root) {}

        public function lifecycle(): Lifecycle
        {
            return $this->adapter;
        }

        public function appId(): string
        {
            return 'cli-synthetic';
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
            return bound($this->root, fn () => $this->passes);
        }
    };
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    check($kernel->call('starter:maintain', ['action' => 'plan']) !== 0, 'CLI missing host binding exits nonzero');
    $app->instance(LifecycleHost::class, $hostBinding);
    check($kernel->call('starter:maintain', ['action' => 'plan']) === 0, 'CLI executable read-only plan exits zero');
    $preview = json_decode(trim($kernel->output()), true, flags: JSON_THROW_ON_ERROR);
    check($kernel->call('starter:maintain', ['action' => 'apply', '--intent' => 'cli', '--exclusive-checkout' => true, '--expected-plan' => 'wrong']) !== 0 && record($root) === null, 'CLI stale plan refusal exits nonzero before writes');
    check($kernel->call('starter:maintain', ['action' => 'apply', '--intent' => 'cli', '--exclusive-checkout' => true, '--expected-plan' => $preview['plan']]) !== 0 && record($root) === null, 'CLI verification failure exits nonzero');
    $hostBinding->passes = true;
    check($kernel->call('starter:maintain', ['action' => 'resume', '--intent' => 'cli', '--exclusive-checkout' => true]) === 0 && record($root) !== null, 'CLI exact resume completes with zero exit');
    $before = record($root);
    file_put_contents($root.'/.mod-maintenance/unresolved', 'preserve candidate');
    check($kernel->call('starter:maintain', ['action' => 'resume', '--intent' => 'cli', '--exclusive-checkout' => true]) !== 0 && json_decode(trim($kernel->output()), true)['status'] === 'orphaned', 'CLI unresolved orphan remains distinct and exits nonzero');
    check(record($root) === $before && file_get_contents($root.'/.mod-maintenance/unresolved') === 'preserve candidate', 'CLI orphan refusal preserves receipt and candidate');
    echo "Synthetic adapter checks only; no consumer/product/data/browser acceptance.\n";
} catch (Throwable $error) {
    fwrite(STDERR, (string) $error."\n");
    exit(1);
}
