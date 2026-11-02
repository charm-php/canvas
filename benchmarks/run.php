<?php
// php benchmarks/run.php graph|mixed [--jit] [--hash]
// 10 warm passes, then the mean of 10 measured frames.  --jit re-executes PHP
// with opcache and the tracing JIT on (the library file is then cached; the
// script named on the command line never is).  --hash prints an md5 of the
// first frame's pixels, to prove a change leaves the output untouched.
$names = ['graph' => 'Graph\\', 'mixed' => 'Mixed\\'];
$name = $argv[1] ?? '';
if (!isset($names[$name])) {
    fwrite(STDERR, "usage: php benchmarks/run.php graph|mixed [--jit] [--hash]\n");
    exit(2);
}
if (in_array('--jit', $argv, true) && !getenv('BENCH_JIT')) {
    putenv('BENCH_JIT=1');
    $cmd = escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=64M '
        . escapeshellarg(__FILE__) . ' ' . implode(' ', array_map('escapeshellarg', array_slice($argv, 1)));
    passthru($cmd, $code);
    exit($code);
}
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/' . $name . '-frame.php';
$ns = 'phasync\\Canvas\\Bench\\' . $names[$name];
if (in_array('--hash', $argv, true)) {
    echo md5(serialize(($ns . 'frame')(0)->toPixels())), "\n";
    exit(0);
}
$ms = ($ns . 'run')(10, 10);
printf("%s %s: %.1f ms/frame\n", $name, getenv('BENCH_JIT') ? 'jit' : 'interp', $ms);
