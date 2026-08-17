<?php declare(strict_types=1);

$root = dirname(__DIR__);
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$version = $composer['version'] ?? null;

if (!is_string($version) || $version === '') {
    fwrite(STDERR, "Could not resolve plugin version from composer.json.\n");
    exit(1);
}

$archivePath = $argv[1] ?? $root . '/release/EbizChargeShopware-v' . $version . '-shopware67.zip';
if (!is_file($archivePath)) {
    fwrite(STDERR, "Release archive not found: {$archivePath}\n");
    exit(1);
}

$listing = [];
$listingExitCode = 0;
exec('unzip -Z1 ' . escapeshellarg($archivePath), $listing, $listingExitCode);
if ($listingExitCode !== 0) {
    fwrite(STDERR, "Could not read ZIP listing for {$archivePath}\n");
    exit(1);
}

if ($listing === []) {
    fwrite(STDERR, "Release archive is empty.\n");
    exit(1);
}

foreach ($listing as $entry) {
    if (!str_starts_with($entry, 'EbizChargeShopware/')) {
        fwrite(STDERR, "Release archive contains an unexpected top-level entry: {$entry}\n");
        exit(1);
    }

    if (str_contains($entry, '__MACOSX') || str_contains($entry, '.DS_Store')) {
        fwrite(STDERR, "Release archive contains forbidden macOS artifact: {$entry}\n");
        exit(1);
    }
}

$manifestPath = $root . '/src/Resources/public/administration/.vite/manifest.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$adminEntry = $manifest['main.js']['file'] ?? null;
if (!is_string($adminEntry) || $adminEntry === '') {
    fwrite(STDERR, "Could not resolve administration entry from Vite manifest.\n");
    exit(1);
}

$tmpDir = sys_get_temp_dir() . '/ebizcharge-release-smoke-' . bin2hex(random_bytes(4));
if (!mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
    fwrite(STDERR, "Could not create temp directory {$tmpDir}\n");
    exit(1);
}

register_shutdown_function(static function () use ($tmpDir): void {
    if (!is_dir($tmpDir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isDir()) {
            rmdir($file->getPathname());
            continue;
        }

        unlink($file->getPathname());
    }

    rmdir($tmpDir);
});

$unzipExitCode = 0;
exec('unzip -q ' . escapeshellarg($archivePath) . ' -d ' . escapeshellarg($tmpDir), $unusedOutput, $unzipExitCode);
if ($unzipExitCode !== 0) {
    fwrite(STDERR, "Could not unzip release archive.\n");
    exit(1);
}

$pluginRoot = $tmpDir . '/EbizChargeShopware';
if (!is_dir($pluginRoot)) {
    fwrite(STDERR, "Unpacked release archive is missing EbizChargeShopware/.\n");
    exit(1);
}

$requiredFiles = [
    $pluginRoot . '/composer.json',
    $pluginRoot . '/README.md',
    $pluginRoot . '/CHANGELOG.md',
    $pluginRoot . '/src/EbizChargeShopware.php',
    $pluginRoot . '/src/Resources/config/plugin.png',
    $pluginRoot . '/src/Resources/config/services.xml',
    $pluginRoot . '/src/Resources/config/services/core.xml',
    $pluginRoot . '/src/Resources/config/services/controllers.xml',
    $pluginRoot . '/src/Resources/config/services/commands.xml',
    $pluginRoot . '/src/Resources/public/administration/' . $adminEntry,
];

foreach ($requiredFiles as $requiredFile) {
    if (!is_file($requiredFile)) {
        fwrite(STDERR, "Release archive is missing required file {$requiredFile}\n");
        exit(1);
    }
}

$comparisonPairs = [
    'composer.json' => 'composer.json',
    'README.md' => 'README.md',
    'CHANGELOG.md' => 'CHANGELOG.md',
    'src/EbizChargeShopware.php' => 'src/EbizChargeShopware.php',
    'src/Resources/config/plugin.png' => 'src/Resources/config/plugin.png',
    'src/Resources/config/services.xml' => 'src/Resources/config/services.xml',
    'src/Resources/config/services/core.xml' => 'src/Resources/config/services/core.xml',
    'src/Resources/config/services/controllers.xml' => 'src/Resources/config/services/controllers.xml',
    'src/Resources/config/services/commands.xml' => 'src/Resources/config/services/commands.xml',
    'src/Resources/public/administration/' . $adminEntry => 'src/Resources/public/administration/' . $adminEntry,
];

foreach ($comparisonPairs as $sourceRelative => $archiveRelative) {
    $sourcePath = $root . '/' . $sourceRelative;
    $archiveFile = $pluginRoot . '/' . $archiveRelative;

    if (!is_file($sourcePath) || !is_file($archiveFile)) {
        fwrite(STDERR, "Runtime-critical file missing during source/archive comparison: {$sourceRelative}\n");
        exit(1);
    }

    if (sha1_file($sourcePath) !== sha1_file($archiveFile)) {
        fwrite(STDERR, "Runtime-critical file mismatch between source and archive: {$sourceRelative}\n");
        exit(1);
    }
}

$serviceGraphOutput = [];
$serviceGraphExitCode = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/service-graph-check.php') . ' ' . escapeshellarg($pluginRoot),
    $serviceGraphOutput,
    $serviceGraphExitCode
);

if ($serviceGraphExitCode !== 0) {
    fwrite(STDERR, implode(PHP_EOL, $serviceGraphOutput) . PHP_EOL);
    exit(1);
}

echo "Release smoke validation passed for {$archivePath}\n";
