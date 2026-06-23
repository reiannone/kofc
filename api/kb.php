<?php
/**
 * kb.php — retrieval over embedded KofC source documents, collection-aware and WEIGHTED.
 * MySQL + PHP cosine: zero new infra. Swap kofc_kb_context's scan for a vector index later.
 *
 * Selection (kofc_kb_context): score every chunk, drop sub-floor noise, multiply by the
 * collection weight, then choose by weighted score — guaranteeing per-collection minimums
 * (e.g. binding regulations) and capping each collection so none monopolizes the budget.
 * Output is grouped in AUTHORITY ORDER and labeled, so the model still knows which sources bind.
 */

define('KOFC_EMBED_MODEL', 'text-embedding-3-small');
// Fallback only — real caps/weights/floor live in collections.php (and any supervisor override).
define('KOFC_DEFAULT_MIX', json_encode(['regulations' => 3, 'policy' => 3, 'vetted' => 3, 'sales' => 2]));

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

/**
 * Supervisor-saved retrieval overrides (weights/floor/mix/min/k), or [] when unset.
 * Fails safe to file defaults if the kb_tuning table doesn't exist yet.
 */
function kofc_kb_tuning(): array
{
    try {
        $row = kofc_db()->query('SELECT config FROM kb_tuning WHERE id = 1')->fetchColumn();
        if ($row) { $c = json_decode($row, true); if (is_array($c)) return $c; }
    } catch (Throwable $e) {
        // table absent or db error -> defaults apply
    }
    return [];
}

/** The collections.php tuning defaults, for the supervisor UI's "reset" and as the floor of merges. */
function kofc_kb_defaults(): array
{
    $meta = require __DIR__ . '/collections.php';
    return [
        'weights' => $meta['weights'] ?? [],
        'floor'   => $meta['floor']   ?? 0.0,
        'mix'     => $meta['mix']     ?? [],
        'min'     => $meta['min']     ?? [],
        'k'       => $meta['k']       ?? 6,
    ];
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
 * Prompt-ready retrieval block: weighted, floored, budgeted, authority-ordered.
 * Returns '' safely when mocked, empty, or on any error.
 *
 * @param string     $query   the search text
 * @param int        $k       total passage budget; 0 = use config 'k'
 * @param array|null $tuning  optional override of weights|floor|mix|min|k (e.g. supervisor config)
 */
function kofc_kb_context(string $query, int $k = 0, ?array $tuning = null): string
{
    if (AI_MOCK) return '';
    $meta   = require __DIR__ . '/collections.php';
    $tuning = is_array($tuning) ? $tuning : [];
    $stored = kofc_kb_tuning();   // supervisor-saved overrides (empty until the table exists / is set)

    // Precedence: explicit $tuning arg  >  supervisor-saved row  >  collections.php defaults.
    $weights = $tuning['weights'] ?? $stored['weights'] ?? ($meta['weights'] ?? []);
    $floor   = (float)($tuning['floor'] ?? $stored['floor'] ?? ($meta['floor'] ?? 0.0));
    $caps    = $tuning['mix']     ?? $stored['mix']  ?? ($meta['mix']  ?? json_decode(KOFC_DEFAULT_MIX, true));
    $mins    = $tuning['min']     ?? $stored['min']  ?? ($meta['min']  ?? []);
    $budget  = $k > 0 ? $k : (int)($tuning['k'] ?? $stored['k'] ?? ($meta['k'] ?? 6));

    try {
        $qv = kofc_embed($query);
        if (!$qv) return '';
        $rows = kofc_db()->query('SELECT source, collection, chunk_text, embedding FROM kb_chunks')->fetchAll();
    } catch (Throwable $e) {
        error_log('kb_context: ' . $e->getMessage());
        return '';
    }
    if (!$rows) return '';

    // 1) Score every chunk, drop sub-floor noise, apply the collection weight.
    $pool = [];
    foreach ($rows as $r) {
        $emb = json_decode($r['embedding'], true);
        if (!is_array($emb)) continue;
        $col = (isset($r['collection']) && $r['collection'] !== '') ? $r['collection'] : 'policy';
        if (($caps[$col] ?? 0) <= 0) continue;             // collection not part of this query's mix
        $raw = kofc_cosine($qv, $emb);
        if ($raw < $floor) continue;                        // relevance floor
        $w   = isset($weights[$col]) ? (float)$weights[$col] : 1.0;
        $pool[] = [
            'source'     => $r['source'],
            'collection' => $col,
            'chunk_text' => $r['chunk_text'],
            'raw'        => $raw,
            'eff'        => $raw * $w,
        ];
    }
    if (!$pool) return '';
    usort($pool, fn($a, $b) => $b['eff'] <=> $a['eff']);

    // 2) Select: guarantee per-collection minimums first (e.g. binding regs), then fill the rest
    //    by weighted score — all bounded by per-collection caps and the total budget.
    $chosen = [];
    $used   = [];
    $taken  = [];
    $take = function (int $i, array $h) use (&$chosen, &$used, &$taken, $caps, $budget): bool {
        if (count($chosen) >= $budget) return false;
        $c = $h['collection'];
        if (($used[$c] ?? 0) >= ($caps[$c] ?? 0)) return false;
        $used[$c]   = ($used[$c] ?? 0) + 1;
        $taken[$i]  = true;
        $chosen[]   = $h;
        return true;
    };

    foreach ($mins as $col => $need) {
        $got = 0;
        foreach ($pool as $i => $h) {
            if ($got >= (int)$need || count($chosen) >= $budget) break;
            if ($h['collection'] !== $col || isset($taken[$i])) continue;
            if ($take($i, $h)) $got++;
        }
    }
    foreach ($pool as $i => $h) {
        if (count($chosen) >= $budget) break;
        if (isset($taken[$i])) continue;
        $take($i, $h);
    }
    if (!$chosen) return '';

    // 3) Present grouped by AUTHORITY ORDER, labeled, with the preamble.
    $bySel = [];
    foreach ($chosen as $h) $bySel[$h['collection']][] = $h;

    $out = $meta['preamble'] . "\n\n";
    $any = false;
    foreach ($meta['authority_order'] as $col) {
        if (empty($bySel[$col])) continue;
        usort($bySel[$col], fn($a, $b) => $b['eff'] <=> $a['eff']);
        $any = true;
        $out .= '[' . strtoupper($meta['labels'][$col]) . "]\n";
        foreach ($bySel[$col] as $h) {
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
