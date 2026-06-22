<?php
/**
 * kb-ingest.php — CLI bulk ingest. Subfolders map to collections:
 *   kb_sources/regulations/*.txt -> collection "regulations"
 *   kb_sources/policy/*.txt      -> collection "policy"
 *   kb_sources/sales/*.txt       -> collection "sales"
 *   kb_sources/*.txt             -> collection "policy" (default)
 * Re-running a file replaces its prior chunks. Embeddings need a live OpenAI key.
 * Usage: php kb-ingest.php [sources_dir]
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require __DIR__ . '/config.php';
require __DIR__ . '/kb.php';

$meta  = require __DIR__ . '/collections.php';
$valid = array_keys($meta['collections']);
$base  = $argv[1] ?? (__DIR__ . '/../kb_sources');

// (collection, file) pairs: top-level files default to 'policy'; subfolders set the collection.
$jobs = [];
foreach (glob(rtrim($base, "/\\") . '/*.txt') as $f) { $jobs[] = ['policy', $f]; }
foreach ($valid as $col) {
    foreach (glob(rtrim($base, "/\\") . '/' . $col . '/*.txt') as $f) { $jobs[] = [$col, $f]; }
}
if (!$jobs) { fwrite(STDERR, "No .txt files found under {$base}\n"); exit(1); }

$total = 0;
foreach ($jobs as [$col, $file]) {
    $source = basename($file);
    $text = file_get_contents($file);
    if ($text === false || trim($text) === '') { echo "skip (empty): {$source}\n"; continue; }
    try {
        $n = kofc_kb_store($source, $col, $text);
    } catch (Throwable $e) {
        echo "  error on {$source}: " . $e->getMessage() . "\n";
        continue;
    }
    $total += $n;
    echo "ingested [{$col}] {$source}: {$n} chunks\n";
}
echo "Done. {$total} chunks embedded.\n";
