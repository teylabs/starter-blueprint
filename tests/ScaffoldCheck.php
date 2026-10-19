<?php

// Point to a public dependency install; no package-private API or app boot needed.
$autoload = $argv[1] ?? null;
if (! is_string($autoload) || ! is_file($autoload)) {
    fwrite(STDERR, "Pass a Composer autoload.php with Laravel13 and Mod0.8.\n");
    exit(2);
}
$loader = require $autoload;
$loader->addPsr4('Tey\\StarterBlueprint\\', dirname(__DIR__).'/src');
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\Stub;
use Tey\Mod\ModServiceProvider;
use Tey\StarterBlueprint\Blueprint;
use Tey\StarterBlueprint\BlueprintServiceProvider;

final class BlueprintLayoutProbeProvider extends ServiceProvider
{
    public static bool $misplace = false;

    public function boot(): void
    {
        if (self::$misplace) {
            Mod::layout('laravel')->generates('starter-defaults', in: 'Unexpected', stub: Stub::file((config('starter-blueprint.features') ? Blueprint::features(config('starter-blueprint.features')) : Blueprint::release(config('starter-blueprint.release')))->stubPath('defaults.php.stub')));
        }
    }
}

function blueprintCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    } echo 'PASS '.$message."\n";
}
try {
    $release = $argv[2] ?? 'A';
    // `features:foundation,defaults` selects features instead of an A-D release fixture.
    $features = str_starts_with($release, 'features:') ? explode(',', substr($release, 9)) : null;
    $load = fn (): Blueprint => $features === null ? Blueprint::release($release) : Blueprint::features($features);
    $blueprint = $load();
    blueprintCheck($blueprint->sourceReference() === $load()->sourceReference(), 'same immutable blueprint produces same source reference');
    blueprintCheck($blueprint->sourceReference() !== Blueprint::release($release === 'B' ? 'A' : 'B')->sourceReference(), 'changed blueprint bytes have a different source reference');
    try {
        $blueprint->stub('../outside');
        throw new RuntimeException('Traversal accepted');
    } catch (RuntimeException $e) {
        blueprintCheck($e->getMessage() === 'Invalid blueprint resource.', 'resource traversal refused');
    }
    blueprintCheck(Blueprint::release('A')->sourceReference() === 'sha256:ba839706bc9905327fc6328b926892b01d52ffb5bff2257689d9897828642979' && Blueprint::release('B')->sourceReference() === 'sha256:41ce80592e59248cd9d5c4895ae6aa5b3c314ca8862e1c08228b052f8a487b4a', 'A and B source references match their pinned groups-key identities');
    $root = sys_get_temp_dir().'/starter-blueprint-scaffold-'.bin2hex(random_bytes(6));
    foreach (['bootstrap/cache', 'config', 'app', 'storage/framework', 'storage/logs'] as $dir) {
        mkdir($root.'/'.$dir, 0700, true);
    }
    file_put_contents($root.'/composer.json', json_encode(['name' => 'example/consumer', 'autoload' => ['psr-4' => ['App\\' => 'app/']]]));
    file_put_contents($root.'/config/app.php', "<?php return ['name'=>'Synthetic consumer','env'=>'testing','key'=>'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=','cipher'=>'AES-256-CBC'];");
    file_put_contents($root.'/config/starter-blueprint.php', '<?php return '.var_export($features === null ? ['release' => $release] : ['features' => $features], true).';');
    $app = Application::configure($root)->withExceptions(fn ($e) => null)->withProviders([ModServiceProvider::class, BlueprintServiceProvider::class, BlueprintLayoutProbeProvider::class])->create();
    BlueprintLayoutProbeProvider::$misplace = ($argv[3] ?? '') === 'misplace-defaults';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    if (BlueprintLayoutProbeProvider::$misplace) {
        $refused = false;
        try {
            $refused = $kernel->call('starter:foundation', ['--no-interaction' => true]) !== 0;
        } catch (RuntimeException $e) {
            $refused = $e->getMessage() === 'Blueprint requires the unmodified laravel layout.';
        }
        blueprintCheck($refused && ! is_file($root.'/app/Support/Starter/BlueprintPolicy.php') && ! is_file($root.'/app/Unexpected/BlueprintDefaults.php'), 'second member placement refuses before any first-member write');
        exit(0);
    }
    $exit = $kernel->call('starter:foundation', ['--dry-run' => true, '--json' => true, '--no-interaction' => true]);
    $output = $kernel->output();
    $plan = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    if ($exit !== 0 || $plan['would_write'] !== true) {
        fwrite(STDERR, $output);
    }
    blueprintCheck($exit === 0 && $plan['would_write'] === true, 'released Mod scaffold produces executable preview');
    blueprintCheck(! is_file($root.'/app/Support/Starter/BlueprintPolicy.php'), 'preview writes no generated file');
    $second = $kernel->call('starter:foundation', ['--dry-run' => true, '--json' => true, '--no-interaction' => true]);
    blueprintCheck($kernel->output() === $output, 'repeat preview is byte-identical');
    $kernel->call('starter:foundation', ['name' => 'CustomPolicy', '--dry-run' => true, '--json' => true, '--no-interaction' => true]);
    $custom = json_decode($kernel->output(), true, flags: JSON_THROW_ON_ERROR);
    blueprintCheck(array_column($custom['files'], 'path') === array_column($plan['files'], 'path'), 'alternate public name cannot escape declared policy ownership');
    config()->set('mod.layout', 'ddd');
    try {
        (new BlueprintServiceProvider($app))->boot();
        throw new RuntimeException('Unsupported layout accepted');
    } catch (RuntimeException $e) {
        blueprintCheck($e->getMessage() === 'Blueprint requires the unmodified laravel layout.', 'unsupported layout refuses before generation');
    } finally {
        config()->set('mod.layout', 'laravel');
    }
    $exit = $kernel->call('starter:foundation', ['--no-interaction' => true]);
    $path = $root.'/app/Support/Starter/BlueprintPolicy.php';
    blueprintCheck($exit === 0 && is_file($path), 'released Mod generates declared file at intended path');
    blueprintCheck(str_contains(file_get_contents($path), "BLUEPRINT_VERSION = '".$blueprint->manifest['version']."'"), 'generated policy records selected blueprint version');
    blueprintCheck(array_column($plan['files'], 'path') === array_keys($blueprint->manifest['generated']), 'preview paths equal the complete explicit manifest');
    foreach ($blueprint->manifest['generated'] as $relative => $entry) {
        $expected = $blueprint->snapshot()['files'][$relative];
        blueprintCheck(file_get_contents($root.'/'.$relative) === $expected, 'generated exact immutable bytes for '.basename($relative));
    }
    if (in_array($release, ['C', 'D'], true) || ($features !== null && count($features) === 2)) {
        blueprintCheck(count($plan['files']) === 2, 'two independently owned generated files delivered');
        if ($features === null) {
            $other = Blueprint::release($release === 'C' ? 'D' : 'C');
            foreach ($blueprint->manifest['generated'] as $relative => $entry) {
                blueprintCheck($blueprint->stub($entry['stub']) !== $other->stub($entry['stub']), 'both release resources change independently: '.basename($relative));
            }
        }
        file_put_contents($root.'/app/Support/Starter/Defaults/BlueprintDefaults.php', '<?php // host customised defaults');
        file_put_contents($root.'/app/HostDomain.php', '<?php // host owned');
        $kernel->call('starter:foundation', ['--no-interaction' => true]);
        blueprintCheck(file_get_contents($root.'/app/Support/Starter/Defaults/BlueprintDefaults.php') === '<?php // host customised defaults' && file_get_contents($root.'/app/HostDomain.php') === '<?php // host owned', 'ordinary generation preserves customised output and unknown host code');
    }
    $before = hash_file('sha256', $path);
    $exit = $kernel->call('starter:foundation', ['--no-interaction' => true]);
    blueprintCheck(hash_file('sha256', $path) === $before, 'ordinary regeneration preserves existing file');
    echo json_encode(['blueprint' => $blueprint->sourceReference(), 'root' => $root, 'plan' => $plan, 'generated_hash' => $before], JSON_PRETTY_PRINT)."\n";
} catch (Throwable $e) {
    fwrite(STDERR, (string) $e."\n");
    exit(1);
}
