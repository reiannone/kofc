<?php
/**
 * Shared OpenAI helpers.
 * kofc_ai_chat()     — full messages array (multi-turn conversations).
 * kofc_ai_complete() — convenience wrapper for single system+user calls.
 * $jsonMode requests a strict JSON object (the prompt must mention "JSON").
 */
function kofc_ai_chat(array $messages, bool $jsonMode = false): string
{
    $payload = [
        'model'      => AI_MODEL,
        'max_tokens' => AI_MAX_TOKENS,
        'messages'   => $messages,
    ];
    if ($jsonMode) {
        $payload['response_format'] = ['type' => 'json_object'];
    }

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 60,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $code >= 300) {
        error_log('OpenAI HTTP ' . $code . ' ' . $err . ' :: ' . substr((string)$resp, 0, 500));
        throw new RuntimeException('AI call failed (HTTP ' . $code . ')');
    }

    $data = json_decode($resp, true);
    return trim($data['choices'][0]['message']['content'] ?? '');
}

function kofc_ai_complete(string $system, string $userMsg, bool $jsonMode = false): string
{
    return kofc_ai_chat([
        ['role' => 'system', 'content' => $system],
        ['role' => 'user',   'content' => $userMsg],
    ], $jsonMode);
}
