#!/usr/bin/env php
<?php
/**
 * Release build script for Data Importer plugin.
 *
 * Creates a versioned release branch with compiled assets and production
 * dependencies committed, all dev files removed, and a git tag applied.
 *
 * Usage:
 *   php build-release.php [--dry-run]
 *
 * Options:
 *   --dry-run   Print what would happen without making any changes.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('This script must be run from the command line.' . PHP_EOL);
}

define('PLUGIN_DIR', __DIR__);
define('PLUGIN_FILE', PLUGIN_DIR . '/data-importer.php');
define('DRY_RUN', in_array('--dry-run', $argv, true));

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function color(string $text, string $code): string
{
    return "\033[{$code}m{$text}\033[0m";
}

function info(string $msg): void  { echo color('[INFO] ', '34') . $msg . PHP_EOL; }
function ok(string $msg): void    { echo color('[OK]   ', '32') . $msg . PHP_EOL; }
function warn(string $msg): void  { echo color('[WARN] ', '33') . $msg . PHP_EOL; }

function abort(string $msg): never
{
    echo color('[ERR]  ', '31') . $msg . PHP_EOL;
    exit(1);
}

/**
 * Run a shell command, echo its output, and optionally abort on failure.
 *
 * @return array{output: string[], code: int}
 */
function run(string $command, bool $failOnError = true): array
{
    if (DRY_RUN) {
        echo color('[DRY]  ', '33') . $command . PHP_EOL;
        return ['output' => [], 'code' => 0];
    }

    info("$ $command");
    exec($command . ' 2>&1', $output, $code);

    if ($output) {
        echo implode(PHP_EOL, $output) . PHP_EOL;
    }

    if ($code !== 0 && $failOnError) {
        abort("Command failed (exit $code): $command");
    }

    return ['output' => $output, 'code' => $code];
}

/** Recursively delete a directory. */
function remove_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
    }

    rmdir($dir);
}

function git(string $subcommand, bool $failOnError = true): array
{
    return run('git -C ' . escapeshellarg(PLUGIN_DIR) . ' ' . $subcommand, $failOnError);
}

// ---------------------------------------------------------------------------
// Step 1 — Read plugin version from the file header
// ---------------------------------------------------------------------------

info('Reading plugin version...');

$pluginContent = file_get_contents(PLUGIN_FILE);

if (!preg_match('/^\s*\*\s*Version:\s*(.+)$/m', $pluginContent, $matches)) {
    abort('Could not read Version from ' . PLUGIN_FILE);
}

$version = trim($matches[1]);
ok("Version: $version");

// ---------------------------------------------------------------------------
// Step 2 — Verify git state
// ---------------------------------------------------------------------------

info('Checking git status...');

$statusResult = git('status --porcelain', false);

if (!DRY_RUN && !empty($statusResult['output'])) {
    abort(
        'Working directory has uncommitted changes. Commit or stash them before building a release.' . PHP_EOL .
        implode(PHP_EOL, $statusResult['output'])
    );
}

$branchResult  = git('rev-parse --abbrev-ref HEAD');
$currentBranch = DRY_RUN ? 'main' : trim(implode('', $branchResult['output']));

if (str_starts_with($currentBranch, 'release/')) {
    abort("Already on release branch '$currentBranch'. Switch to main/develop first.");
}

ok("Source branch: $currentBranch");

// ---------------------------------------------------------------------------
// Step 3 — Create or reset the release branch
// ---------------------------------------------------------------------------

$releaseBranch = "release/$version";
info("Preparing branch: $releaseBranch");

$listResult   = git('branch --list ' . escapeshellarg($releaseBranch), false);
$branchExists = !DRY_RUN && !empty($listResult['output']);

if ($branchExists) {
    warn("Branch '$releaseBranch' already exists — resetting it to '$currentBranch'.");
    git("checkout $releaseBranch");
    git("reset --hard " . escapeshellarg($currentBranch));
} else {
    git("checkout -b $releaseBranch");
}

ok("On branch: $releaseBranch");

// ---------------------------------------------------------------------------
// Step 4 — Composer (production dependencies only)
// ---------------------------------------------------------------------------

info('Installing Composer dependencies (production)...');

run(
    'composer install --no-dev --optimize-autoloader --no-interaction' .
    ' --working-dir=' . escapeshellarg(PLUGIN_DIR)
);

ok('Composer dependencies installed.');

// ---------------------------------------------------------------------------
// Step 5 — npm install + build
// ---------------------------------------------------------------------------

info('Installing npm dependencies...');
run('npm install --prefix ' . escapeshellarg(PLUGIN_DIR));
ok('npm dependencies installed.');

info('Building assets...');
run('npm run build --prefix ' . escapeshellarg(PLUGIN_DIR));
ok('Assets built.');

// ---------------------------------------------------------------------------
// Step 6 — Remove development files and directories
// ---------------------------------------------------------------------------

info('Removing development files...');

$dirsToRemove = [
    'node_modules',
    'tests',
    'src/js',
    'src/scss',
];

$filesToRemove = [
    '.gitignore',
    'vite.config.mjs',
    'composer.json',
    'composer.lock',
    'package.json',
    'package-lock.json',
    'phpunit.xml.dist',
    '.phpunit.result.cache',
    'TESTS.md',
    'CHANGES.md',
    'CHANGES-sv.md',
];

foreach ($dirsToRemove as $rel) {
    $path = PLUGIN_DIR . '/' . $rel;
    if (DRY_RUN) {
        echo color('[DRY]  ', '33') . "Remove dir: $rel" . PHP_EOL;
    } elseif (is_dir($path)) {
        remove_dir($path);
        ok("Removed dir: $rel");
    }
}

foreach ($filesToRemove as $rel) {
    $path = PLUGIN_DIR . '/' . $rel;
    if (DRY_RUN) {
        echo color('[DRY]  ', '33') . "Remove file: $rel" . PHP_EOL;
    } elseif (file_exists($path)) {
        unlink($path);
        ok("Removed file: $rel");
    }
}

// ---------------------------------------------------------------------------
// Step 7 — Stage everything except this build script and node_modules, then commit
// ---------------------------------------------------------------------------

info('Staging all files...');
git('add -A');
// Explicitly remove from the index anything that must never be in the release.
// Using --cached keeps the files on disk; false = don't abort if already absent.
git('rm --cached build-release.php 2>/dev/null', false);
git('rm -r --cached node_modules 2>/dev/null', false);

$commitMsg = "Release build for version $version";
info("Committing: $commitMsg");
git('commit -m ' . escapeshellarg($commitMsg));
ok('Release committed.');

// Remove the build script from the working tree after the commit
// so it does not pollute the release branch.
if (!DRY_RUN && file_exists(PLUGIN_DIR . '/build-release.php')) {
    unlink(PLUGIN_DIR . '/build-release.php');
    ok('Removed build-release.php from working tree.');
}

// ---------------------------------------------------------------------------
// Step 9 — Tag
// ---------------------------------------------------------------------------

$tag = "$version";
info("Tagging: $tag");

$tagList = git('tag --list ' . escapeshellarg($tag), false);
if (!DRY_RUN && !empty($tagList['output'])) {
    warn("Tag '$tag' already exists — deleting and recreating.");
    git("tag -d $tag");
}

git('tag -a ' . escapeshellarg($tag) . ' -m ' . escapeshellarg("Version $version"));
ok("Tagged: $tag");

// ---------------------------------------------------------------------------
// Done
// ---------------------------------------------------------------------------

echo PHP_EOL;
echo color("Release build complete!", '32') . PHP_EOL;
echo "  Branch : $releaseBranch" . PHP_EOL;
echo "  Tag    : $tag" . PHP_EOL;
echo PHP_EOL;
echo "To push:" . PHP_EOL;
echo "  git -C " . PLUGIN_DIR . " push origin $releaseBranch --tags" . PHP_EOL;
