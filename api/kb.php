<?php
/**
 * kb.php — retrieval over embedded KofC source documents, collection-aware.
 * MySQL + PHP cosine: zero new infra. Swap kofc_kb_context's scan for a vector index later.
 * Retrieval pulls a balanced mix across collections and labels them in AUTHORITY ORDER
 * (regulations > policy > sales), so the model knows which sources are binding.
 */

define('KOFC_EMBED_MODEL', 'text-embedding-3-small');
define('KOFC_DEFAULT_MIX', json_encode(['regulations' => 2, 'policy' => 2, 'vetted' => 2, 'sales' => 1]));

function kofc_embed(string $text): array
{
    $ch = curl_init('https://api.openai.com/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY,
        ],
        CURLOPT_POSTFIELDS     => json_encode(['model' => KOFC_EMBED_MODEL, 'input' => $text]),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code >= 300) {
        throw new RuntimeException('Embedding call failed (HTTP ' . $code . ')');
    }
    $data = json_decode($resp, true);
    return $data['data'][0]['embedding'] ?? [];
}

function kofc_cosine(array $a, array $b): float
{
    $n = min(count($a), count($b));
    $dot = 0.0; $na = 0.0; $nb = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $dot += $a[$i] * $b[$i];
        $na  += $a[$i] * $a[$i];
        $nb  += $b[$i] * $b[$i];
    }
    if ($na == 0.0 || $nb == 0.0) return 0.0;
    return $dot / (sqrt($na) * sqrt($nb));
}

/**
 * Prompt-ready retrieval block, balanced across collections and ordered by authority.
 * Returns '' safely when mocked, empty, or on any error.
 * $mix maps collection => how many passages to include.
 */
function kofc_kb_context(string $query, int $k = 4, ?array $mix = null): string
{
    if (AI_MOCK) return '';
    $mix = $mix ?? json_decode(KOFC_DEFAULT_MIX, true);

    try {
        $qv = kofc_embed($query);
        if (!$qv) return '';
        $rows = kofc_db()->query('SELECT source, collection, chunk_text, embedding FROM kb_chunks')->fetchAll();
    } catch (Throwable $e) {
        error_log('kb_context: ' . $e->getMessage());
        return '';
    }
    if (!$rows) return '';

    $byCol = [];
    foreach ($rows as $r) {
        $emb = json_decode($r['embedding'], true);
        if (!is_array($emb)) continue;
        $col = $r['collection'] !== '' ? $r['collection'] : 'policy';
        $byCol[$col][] = [
            'source'     => $r['source'],
            'chunk_text' => $r['chunk_text'],
            'score'      => kofc_cosine($qv, $emb),
        ];
    }

    $meta  = require __DIR__ . '/collections.php';
    $out   = $meta['preamble'] . "\n\n";
    $any   = false;

    foreach ($meta['authority_order'] as $col) {
        $want = $mix[$col] ?? 0;
        if ($want <= 0 || empty($byCol[$col])) continue;
        usort($byCol[$col], fn($a, $b) => $b['score'] <=> $a['score']);
        $top = array_slice($byCol[$col], 0, $want);
        if (!$top) continue;
        $any = true;
        $out .= '[' . strtoupper($meta['labels'][$col]) . "]\n";
        foreach ($top as $h) {
            $out .= '[' . $h['source'] . '] ' . trim($h['chunk_text']) . "\n\n";
        }
    }

    return $any ? rtrim($out) : '';
}

function kofc_chunk(string $text, int $max = 1000): array
{
    $text = str_replace("\r\n", "\n", $text);
    $paras = preg_split('/\n\s*\n/', $text);
    $chunks = [];
    $buf = '';
    foreach ($paras as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (strlen($buf) + strlen($p) + 2 <= $max) {
            $buf .= ($buf ? "\n\n" : '') . $p;
        } else {
            if ($buf !== '') { $chunks[] = $buf; $buf = ''; }
            if (strlen($p) > $max) {
                foreach (str_split($p, $max) as $piece) { $chunks[] = $piece; }
            } else {
                $buf = $p;
            }
        }
    }
    if ($buf !== '') $chunks[] = $buf;
    return $chunks;
}

/**
 * Shared: embed + chunk + store a document's text under a collection.
 * Replaces any prior chunks for the same source (idempotent). Returns chunk count.
 */
function kofc_kb_store(string $source, string $collection, string $text): int
{
    $pdo = kofc_db();
    $pdo->prepare('DELETE FROM kb_chunks WHERE source = :s')->execute([':s' => $source]);
    $chunks = kofc_chunk($text);
    $ins = $pdo->prepare(
        'INSERT INTO kb_chunks (source, collection, chunk_index, chunk_text, embedding, created_at)
         VALUES (:s, :c, :i, :t, :e, NOW())'
    );
    $n = 0;
    foreach ($chunks as $i => $chunk) {
        $emb = kofc_embed($chunk);
        if (!$emb) continue;
        $ins->execute([':s' => $source, ':c' => $collection, ':i' => $i, ':t' => $chunk, ':e' => json_encode($emb)]);
        $n++;
    }
    return $n;
}
