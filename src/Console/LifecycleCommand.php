<?php

namespace Tey\StarterBlueprint\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Tey\Mod\Maintenance\Maintenance;
use Tey\StarterBlueprint\Blueprint;
use Tey\StarterBlueprint\LifecycleHost;
use Throwable;

final class LifecycleCommand extends Command
{
    protected $signature = 'starter:maintain {action : plan, apply or resume} {--original=} {--target=C} {--features= : Comma-separated features for the target} {--original-features= : Comma-separated features already installed} {--intent=} {--expected-plan=} {--exclusive-checkout}';

    protected $description = 'Explicit file-only blueprint maintenance using a trusted host binding';

    public function handle(): int
    {
        try {
            if (! $this->laravel->bound(LifecycleHost::class)) {
                throw new RuntimeException('A trusted LifecycleHost binding is required.');
            }
            $action = $this->argument('action');
            if (! in_array($action, ['plan', 'apply', 'resume'], true)) {
                throw new RuntimeException('Unsupported maintenance action.');
            }
            $host = $this->laravel->make(LifecycleHost::class);
            $lifecycle = $host->lifecycle();
            $target = $this->option('features') === null ? Blueprint::release($this->option('target')) : Blueprint::features($this->list('features'));
            $original = match (true) {
                $this->option('original-features') !== null => Blueprint::features($this->list('original-features')),
                $this->option('original') !== null => Blueprint::release($this->option('original')),
                default => null,
            };
            $paths = $host->inputPaths();
            $root = $this->laravel->basePath();
            if ($action === 'resume') {
                $result = $lifecycle->resume($root, $host->appId(), $original, $target, $host->bindings(), $paths, (string) $this->option('intent'), $host->operationCheck(), (bool) $this->option('exclusive-checkout'));
            } else {
                $plan = $lifecycle->plan($root, $host->appId(), $original, $target, $host->bindings(), $paths);
                if ($action === 'plan') {
                    $result = Maintenance::diagnose($plan, $paths);
                    $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

                    return $result['current'] && $result['executable'] ? self::SUCCESS : self::FAILURE;
                }
                if ($this->option('expected-plan') !== $plan->identity()) {
                    throw new RuntimeException('A matching freshly reviewed expected plan is required.');
                }
                $result = $lifecycle->apply($plan, $original, $target, $paths, (string) $this->option('intent'), $host->operationCheck(), (bool) $this->option('exclusive-checkout'));
            }
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return $result['status'] === 'completed' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return list<string> */
    private function list(string $option): array
    {
        return array_map('trim', explode(',', (string) $this->option($option)));
    }
}
