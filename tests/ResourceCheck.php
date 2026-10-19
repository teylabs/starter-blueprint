<?php

// Tamper only exclusive disposable copies, never immutable package resources.
function copyPackage(string $source, string $destination): void
{
    mkdir($destination, 0700);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
        if ($entry->isLink()) {
            throw new RuntimeException('Linked package fixture refused.');
        }
        $path = $destination.substr($entry->getPathname(), strlen($source));
        if ($entry->isDir()) {
            mkdir($path, 0700);
        } else {
            $stream = fopen($path, 'xb');
            fwrite($stream, file_get_contents($entry->getPathname()));
            fclose($stream);
        }
    }
}
try {
    $cases = [['resource-after', 'C', null], ['manifest-after', 'C', null]];
    foreach (['A', 'B', 'C', 'D'] as $release) {
        foreach (glob(dirname(__DIR__).'/resources/releases/'.$release.'/*') as $resource) {
            $cases[] = ['resource-before', $release, basename($resource)];
        }
    }
    foreach ($cases as [$mode, $release, $resource]) {
        $copy = sys_get_temp_dir().'/blueprint-resource-copy-'.bin2hex(random_bytes(8));
        copyPackage(dirname(__DIR__), $copy);
        if ($resource !== null) {
            $path = $copy.'/resources/releases/'.$release.'/'.$resource;
            $bytes = file_get_contents($path);
            file_put_contents($path, $resource === 'blueprint.json' ? str_replace('private-workspace', 'changed-workspace', $bytes) : $bytes.'// changed');
        }
        $process = proc_open([PHP_BINARY, __DIR__.'/LifecycleCheck.php', $argv[1], $copy, $mode, $release], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException($output.$error);
        }
        echo 'PASS disposable '.$release.' '.($resource ?? $mode).' tamper refused'."\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, (string) $error."\n");
    exit(1);
}
