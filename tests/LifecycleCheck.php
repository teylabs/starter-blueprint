<?php

// Disposable adapter tests, not product/tenant/browser acceptance.
use Composer\InstalledVersions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Tey\Mod\Maintenance\Maintenance;
use Tey\Mod\ModServiceProvider;
use Tey\StarterBlueprint\Blueprint;
use Tey\StarterBlueprint\BlueprintServiceProvider;
use Tey\StarterBlueprint\Lifecycle;
use Tey\StarterBlueprint\LifecycleHost;

$loader = require $argv[1];
$package = $argv[3] ?? dirname(__DIR__);
$loader->addPsr4('Tey\\StarterBlueprint\\', $package.'/src', true);
$mod = $argv[2] ?? 'released';
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
    foreach (['verification-policy', 'verification-code', 'dependency-lock', 'host-config', 'host-binding', 'host-operations', 'experimental-mod-source', 'executor-provenance'] as $id) {
        $paths[$id] = $id.'.txt';
        file_put_contents($root.'/'.$paths[$id], 'synthetic fixture '.$id);
    }
    file_put_contents($root.'/executor-provenance.txt', json_encode(['installed_mod_version' => InstalledVersions::getPrettyVersion('tey/mod'), 'experimental_source' => ['kind' => 'local-experimental', 'ref' => '7708da58d4bd36206e5e011e2746ae726aeba3ee']]));
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
    if ($mod === 'released') {
        try {
            (new Lifecycle)->runtime();
            throw new RuntimeException('Released lifecycle accepted.');
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), 'Released Mod 0.7.2'), 'released Mod 0.7.2 explicitly refuses maintenance');
        }
        check(Lifecycle::compatibility(['php' => '^99', 'missing/peer' => '^1']) === ['php' => 'failed', 'missing/peer' => 'failed'], 'actual PHP and missing peer incompatibility');
        [$root, $paths] = fixture();
        $app = Application::configure($root)->withExceptions(fn ($e) => null)->withProviders([ModServiceProvider::class, BlueprintServiceProvider::class])->create();
        $app->instance(LifecycleHost::class, new class implements LifecycleHost
        {
            public function lifecycle(): Lifecycle
            {
                return new Lifecycle;
            }

            public function appId(): string
            {
                return 'released-refusal';
            }

            public function bindings(): array
            {
                return [];
            }

            public function inputPaths(): array
            {
                return [];
            }

            public function operationCheck(): Closure
            {
                return fn () => throw new RuntimeException('must not run');
            }
        });
        $kernel = $app->make(Kernel::class);
        $kernel->bootstrap();
        check($kernel->call('starter:maintain', ['action' => 'plan']) !== 0 && str_contains($kernel->output(), 'Released Mod 0.7.2'), 'released CLI explicitly refuses with nonzero before generated effects');
        check(record($root) === null && ! is_file($root.'/app/Support/Starter/BlueprintPolicy.php'), 'released CLI preserves initial absence');
        check($kernel->call('starter:maintain', ['action' => 'plan', '--features' => 'defaults']) !== 0 && str_contains($kernel->output(), 'requires feature foundation'), 'CLI feature selection validates requirements');
        check($kernel->call('starter:maintain', ['action' => 'plan', '--features' => 'foundation', '--original-features' => 'foundation,defaults']) !== 0 && str_contains($kernel->output(), 'Feature removal is refused'), 'CLI refuses feature removal');

        exit(0);
    }
    $mod = realpath($mod) ?: throw new RuntimeException('Missing explicit experimental Mod root.');
    $loader->addPsr4('Tey\\Mod\\', $mod.'/src', true);
    $ref = trim(shell_exec('git -C '.escapeshellarg($mod).' rev-parse HEAD'));
    check($ref === '7708da58d4bd36206e5e011e2746ae726aeba3ee', 'reviewed experimental source pin');
    $tree = shell_exec('git -C '.escapeshellarg($mod).' ls-tree -r '.$ref.' src/Maintenance');
    $blobs = [];
    foreach (explode("\n", trim($tree)) as $line) {
        preg_match('/^100644 blob ([a-f0-9]{40})\t(.+)$/', $line, $match) || throw new RuntimeException('Unsupported source entry.');
        $blobs[$match[2]] = $match[1];
    }
    $verify = function () use ($blobs, $mod, $ref): string {
        if ((new ReflectionClass(Maintenance::class))->getFileName() !== $mod.'/src/Maintenance/Maintenance.php' || ! method_exists(Maintenance::class, 'resume')) {
            throw new RuntimeException('Loaded API does not match selected source.');
        }
        foreach ($blobs as $path => $blob) {
            $bytes = file_get_contents($mod.'/'.$path);
            if (sha1('blob '.strlen($bytes)."\0".$bytes) !== $blob) {
                throw new RuntimeException('Actual loaded source tree differs from pin.');
            }
        }

        return $ref;
    };
    $descriptor = ['kind' => 'local-experimental', 'ref' => $ref];
    $adapter = new Lifecycle($descriptor, $verify);
    check($adapter->runtime()['experimental_source'] === $descriptor, 'explicit overlay verifies actual loaded API and Git blob bytes');
    foreach ([fn () => false, fn () => true, fn () => str_repeat('0', 40), fn () => throw new RuntimeException('bad source')] as $badVerifier) {
        [$root, $paths] = fixture();
        refuses(fn () => (new Lifecycle($descriptor, $badVerifier))->plan($root, 'synthetic', null, Blueprint::release('C'), [], $paths), 'false/error/mismatched source refuses before file effects');
        check(record($root) === null && ! is_file($root.'/app/Support/Starter/BlueprintPolicy.php'), 'unverified source preserves initial absence');
    }
    refuses(fn () => new Lifecycle(['kind' => 'dev-main', 'ref' => $ref], $verify), 'unsupported overlay kind refuses');
    $probe = $argv[4] ?? null;
    if ($probe === 'resource-before') {
        $release = $argv[5];
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
    [$root, $paths] = fixture();
    $bindings = ['frontend' => 'SyntheticLayout'];
    $validExecutorInput = file_get_contents($root.'/'.$paths['executor-provenance']);
    $forged = $adapter->runtime();
    $forged['experimental_source']['ref'] = true;
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
    $otherSource = new Lifecycle(['kind' => 'local-experimental', 'ref' => str_repeat('1', 40)], fn () => str_repeat('1', 40));
    refuses(fn () => $otherSource->resume($root, 'adapter-one', null, $c, $bindings, $paths, 'install', bound($root, $host), true), 'same intent changed overlay provenance refuses even with a trusted verifier');
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
    $valid = true;
    $changing = new Lifecycle($descriptor, function () use (&$valid, $verify) {
        return $valid ? $verify() : false;
    });
    $plan = $changing->plan($root, 'adapter-drift', null, $c, $bindings, $paths);
    $result = $changing->apply($plan, null, $c, $paths, 'drift', bound($root, function () use (&$valid) {
        $valid = false;

        return true;
    }), true);
    check($result['status'] === 'verification_failed' && record($root) === null, 'overlay authority rechecked after host callback before record');
    [$root, $paths] = fixture();
    file_put_contents($root.'/app/Support/Starter/BlueprintPolicy.php', $c->snapshot()['files']['app/Support/Starter/BlueprintPolicy.php']);
    $plan = $adapter->plan($root, 'unowned', null, $c, $bindings, $paths);
    refuses(fn () => $adapter->apply($plan, null, $c, $paths, 'unowned', bound($root, $host), true), 'identical but unowned generated file cannot be adopted');
    check(record($root) === null, 'unowned collision never records ownership');
    // Model two trusted source attestations; no synthetic future source is claimed to exist.
    [$root, $paths] = fixture();
    $plan = $adapter->plan($root, 'executor-transition', null, $c, $bindings, $paths);
    $adapter->apply($plan, null, $c, $paths, 'old-executor-install', bound($root, $host), true);
    $historical = record($root);
    $nextRuntime = $adapter->runtime();
    $nextRuntime['experimental_source']['ref'] = str_repeat('2', 40);
    $nextExecutor = new Lifecycle($nextRuntime['experimental_source'], fn () => str_repeat('2', 40));
    file_put_contents($root.'/'.$paths['executor-provenance'], json_encode($nextRuntime));
    file_put_contents($root.'/'.$paths['experimental-mod-source'], 'synthetic trusted new attestation; not a real future release');
    refuses(fn () => $nextExecutor->resume($root, 'executor-transition', null, $c, $bindings, $paths, 'old-executor-install', bound($root, $host), true), 'same intent changed executor still refuses');
    check(record($root) === $historical, 'same-intent executor refusal preserves historical record');
    $plan = $nextExecutor->plan($root, 'executor-transition', $c, $d, $bindings, $paths);
    check($plan->executable(), 'new-intent runtime transition keeps immutable C baseline executable');
    $result = $nextExecutor->apply($plan, $c, $d, $paths, 'new-executor-upgrade', bound($root, $host), true);
    check($result['status'] === 'completed', 'new intent upgrades C to D across trusted executor transition');
    $installed = json_decode(record($root), true, flags: JSON_THROW_ON_ERROR);
    check($installed['artifact']['metadata'] === $adapter->artifact($d, $bindings)->metadata && isset($installed['commit']['inputs']['executor-provenance']), 'artifact provenance stays immutable while execution input is bound in authoritative commit');
    // Feature selections grow on one artifact: adopt without a baseline, add, never remove.
    $foundation = Blueprint::features(['foundation']);
    $everything = Blueprint::all();
    [$root, $paths] = fixture();
    $plan = $adapter->plan($root, 'features-one', null, $foundation, $bindings, $paths);
    check($plan->executable() && count($plan->target->files) === 1, 'first adoption of one feature plans from an empty original');
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
    $before = record($root);
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
