<?php
/**
 * deal-title.php — canonical deal title/header resolver.
 *
 * Agents type a title for each deal. When they leave it blank we fall back to a
 * stable, human-readable default so a deal never shows up nameless in any list.
 *
 * Default shape:  "<client name or agent id> — YYYY-MM-DD HH:MM"  (Eastern time)
 *
 * Include once and call wherever a deal title is written (deal-save.php).
 *   require_once __DIR__ . '/deal-title.php';
 *   $title = kofc_deal_title($in['title'] ?? '', $clientName, $agentId);
 */

if (!function_exists('kofc_deal_title')) {
    /**
     * @param string $title      raw title from the agent (may be empty)
     * @param string $clientName client name on the deal (may be empty)
     * @param string $agentId    owning agent id / username
     * @return string            non-empty title, max 200 chars
     */
    function kofc_deal_title(string $title, string $clientName = '', string $agentId = ''): string {
        $t = trim($title);
        if ($t !== '') {
            return mb_substr($t, 0, 200);
        }

        try {
            $when = (new DateTime('now', new DateTimeZone('America/New_York')))->format('Y-m-d H:i');
        } catch (Throwable $e) {
            $when = date('Y-m-d H:i'); // fallback to server time if tz lookup fails
        }

        $who = trim($clientName);
        if ($who === '') { $who = trim($agentId); }
        if ($who === '') { $who = 'Deal'; }

        return mb_substr($who . ' — ' . $when, 0, 200);
    }
}
