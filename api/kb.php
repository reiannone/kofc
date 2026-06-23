<?php
/**
 * kb.php — retrieval over embedded KofC source documents (RAG).
 * MySQL + PHP cosine: zero new infra, fits XAMPP and RDS. For a large corpus, swap the
 * brute-force scan for a vector index (pgvector / OpenSearch / Pinecone) behind the same
 * kofc_kb_search() interface — nothing else changes.
 */

define('KOFC_EMBED_MODEL', 'text-embedding-3-small');

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

function kofc_kb_search(string $query, int $k = 4): array
{
    $qv = kofc_embed($query);
    if (!$qv) return [];
    $rows = kofc_db()->query('SELECT source, chunk_text, embedding FROM kb_chunks')->fetchAll();
    $scored = [];
    foreach ($rows as $r) {
        $emb = json_decode($r['embedding'], true);
        if (!is_array($emb)) continue;
        $scored[] = [
            'source'     => $r['source'],
            'chunk_text' => $r['chunk_text'],
            'score'      => kofc_cosine($qv, $emb),
        ];
    }
    usort($scored, fn($x, $y) => $y['score'] <=> $x['score']);
    return array_slice($scored, 0, $k);
}

/**
 * Prompt-ready retrieval block. Returns '' safely when mocked, empty, or on any error,
 * so the endpoints never break if the KB isn't populated yet.
 */
function kofc_kb_context(string $query, int $k = 4): string
{
    if (AI_MOCK) return '';
    try {
        $hits = kofc_kb_search($query, $k);
    } catch (Throwable $e) {
        error_log('kb_search: ' . $e->getMessage());
        return '';
    }
    if (!$hits) return '';
    $out = "RETRIEVED KofC SOURCE PASSAGES (prefer these; cite the source in brackets):\n\n";
    foreach ($hits as $h) {
        $out .= '[' . $h['source'] . "]\n" . trim($h['chunk_text']) . "\n\n";
    }
    return rtrim($out);
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
