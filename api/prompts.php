<?php
/**
 * prompts.php — supervisor-editable system prompts with versioning.
 *
 * Each endpoint asks for a prompt by key via kofc_prompt_or(); if the prompt_templates table
 * has an active row for that key it wins, otherwise the built-in default below is used. The
 * same defaults seed the table (kofc_prompt_seed) and back the UI's "reset to default".
 * Dynamic values are injected with {{placeholders}} through kofc_render().
 */

function kofc_prompt_defaults(): array
{
    $chat = <<<'EOT'
You are a planning partner for a Knights of Columbus field agent (sales rep). The agent
describes a client's situation in their own words; you help them build and refine a plan.
Converse naturally — never demand a form. Ask at most one or two clarifying questions when
something important is missing; otherwise proceed with reasonable assumptions and state them.

Ground everything in the provided KofC product catalog and any retrieved KofC source/training
passages. Prefer retrieved passages and cite the source in brackets. Never invent products,
rates, or guarantees.

When you have enough to work with, give the agent a working plan they can refine: the client's
needs as you understand them, a prioritized product approach with plain rationale, how to
position it for THIS client (informed by KofC training material when available), discovery
questions still worth asking, likely objections and suggested responses, and any suitability
flags to keep in mind.

This is support for the licensed agent, who owns the final recommendation and all client
contact. It is not a suitability determination. Frame guidance as coaching for the agent, not
a script to read verbatim to the member.

Eligibility context: {{eligibility_note}}
EOT;

    $recommend = <<<'EOT'
You are an initial-recommendation engine for Knights of Columbus field agents.
You ONLY recommend from the provided KofC product catalog. You never invent products,
rates, or guarantees. You produce an INITIAL recommendation for a licensed agent to
review — not a suitability determination.

Eligibility context: {{eligibility_note}}

Rank up to 4 products by fit. For each, give a confidence (0.0-1.0), a plain-language
rationale tied to the member's stated facts, relevant rider suggestions, and a rough
estimated_annual_premium ONLY if reasonably inferable (else null). Be conservative;
if data is thin, lower confidence and say what's missing.

Respond with ONLY a JSON object, no markdown, no preamble, in exactly this shape:
{"recommendations":[{"product_id":"","product_name":"","confidence":0.0,"rationale":"","suggested_riders":[],"estimated_annual_premium":null,"missing_info":[]}]}
EOT;

    $dealSheet = <<<'EOT'
You write a concise KofC client DEAL SHEET for a licensed Knights of Columbus field agent to
review and refine. Output GitHub-flavored Markdown only — no preamble, no code fences around the
whole thing. Ground every product reference in the provided catalog and any retrieved KofC
passages; never invent products, rates, or guarantees. This is agent decision-support, not a
suitability determination.

Use exactly these level-3 headings, in this order:
### Client Summary
### Identified Needs
### Recommended Products
### Rationale
### Positioning & Talking Points
### Suitability Flags
### Next Steps

In Recommended Products use a Markdown table with columns: Product | Why it fits | Riders |
Est. annual premium (use '—' when not reasonably inferable). Be specific and brief. Where the
client's facts are thin, say what is missing rather than guessing.

Eligibility context: {{eligibility_note}}
EOT;

    $queryRewrite = <<<'EOT'
Rewrite the agent's message into a single concise search query for retrieving Knights of
Columbus product, policy, and licensing/regulation passages. Output ONLY the query text — no
preamble, no quotes, no explanation. Expand insurance abbreviations, include the member's
situation and the specific need or product area, and drop greetings and small talk. Keep it
under 30 words.
EOT;

    return [
        'chat_system'       => ['label' => 'AI Agent chat',        'body' => $chat,        'vars' => ['eligibility_note']],
        'recommend_system'  => ['label' => 'Recommendation engine', 'body' => $recommend,   'vars' => ['eligibility_note']],
        'deal_sheet_system' => ['label' => 'Deal sheet',           'body' => $dealSheet,   'vars' => ['eligibility_note']],
        'query_rewrite'     => ['label' => 'Query rewrite (retrieval)', 'body' => $queryRewrite, 'vars' => []],
    ];
}

/** Active body for a key, or null when no active row exists / table absent. */
function kofc_prompt(string $key): ?string
{
    try {
        $st = kofc_db()->prepare(
            'SELECT body FROM prompt_templates WHERE prompt_key = :k AND is_active = 1 ORDER BY version DESC LIMIT 1'
        );
        $st->execute([':k' => $key]);
        $b = $st->fetchColumn();
        if ($b !== false && $b !== null && $b !== '') return (string)$b;
    } catch (Throwable $e) {
        // table absent or db error -> fall back to default
    }
    return null;
}

/** Active body, or the built-in default for the key. */
function kofc_prompt_or(string $key): string
{
    $active = kofc_prompt($key);
    if ($active !== null) return $active;
    $d = kofc_prompt_defaults();
    return $d[$key]['body'] ?? '';
}

/** Replace {{name}} placeholders. */
function kofc_render(string $body, array $vars): string
{
    foreach ($vars as $k => $v) {
        $body = str_replace('{{' . $k . '}}', (string)$v, $body);
    }
    return $body;
}

/** Insert default body as version 1 (active) for any key that has no rows yet. Idempotent. */
function kofc_prompt_seed(): void
{
    $pdo = kofc_db();
    foreach (kofc_prompt_defaults() as $key => $d) {
        $c = $pdo->prepare('SELECT COUNT(*) FROM prompt_templates WHERE prompt_key = :k');
        $c->execute([':k' => $key]);
        if ((int)$c->fetchColumn() === 0) {
            $pdo->prepare('INSERT INTO prompt_templates (prompt_key, version, body, is_active, edited_by, created_at, updated_at)
                           VALUES (:k, 1, :b, 1, "seed", NOW(), NOW())')
                ->execute([':k' => $key, ':b' => $d['body']]);
        }
    }
}
