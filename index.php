<?php
/**
 * Meetup Events – Esperanto in Sydney
 *
 * Fetches the group's public iCal feed (no API key or account needed)
 * and renders upcoming events as plain HTML.
 *
 * Requirements: PHP 7.4+, php-curl extension (standard on most hosts).
 */

define('GROUP_URLNAME', 'esperanto-in-sydney');
define('ICAL_URL', 'https://www.meetup.com/' . GROUP_URLNAME . '/events/ical');

// ── Fetch the iCal feed ────────────────────────────────────────────────────────

function fetch_ical(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: Mozilla/5.0 (compatible; PHP iCal reader)',
            'Accept: text/calendar, */*',
        ],
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err)        throw new RuntimeException("cURL error: $err");
    if ($code !== 200) throw new RuntimeException("HTTP $code returned from Meetup");
    if (empty($body)) throw new RuntimeException("Empty response from Meetup");

    return $body;
}

// ── Parse iCal into an array of event arrays ──────────────────────────────────

function parse_ical(string $ical): array
{
    // Unfold long lines (RFC 5545 §3.1)
    $ical   = preg_replace("/\r\n[ \t]/", '', $ical);
    $lines  = preg_split('/\r?\n/', $ical);
    $events = [];
    $event  = null;

    foreach ($lines as $line) {
        $line = rtrim($line);
        if ($line === 'BEGIN:VEVENT') {
            $event = [];
        } elseif ($line === 'END:VEVENT' && $event !== null) {
            $events[] = $event;
            $event = null;
        } elseif ($event !== null && strpos($line, ':') !== false) {
            // Split on first ':'; property names may carry parameters (DTSTART;TZID=…)
            [$rawKey, $value] = explode(':', $line, 2);
            $key = strtoupper(explode(';', $rawKey)[0]); // strip params like TZID
            $event[$key] = unescape_ical($value);
        }
    }

    return $events;
}

function unescape_ical(string $value): string
{
    return str_replace(['\\n', '\\N', '\\,', '\\;'], ["\n", "\n", ',', ';'], $value);
}

// ── Parse an iCal DTSTART/DTEND value to a Unix timestamp ─────────────────────

function ical_to_timestamp(string $dt): int
{
    // Formats: 20250702T180000Z  or  20250702T180000  or  20250702
    $dt = preg_replace('/[^0-9TZ]/', '', $dt);

    if (strlen($dt) >= 15) {          // date + time
        $utc = str_ends_with($dt, 'Z');
        $ts  = mktime(
            (int) substr($dt, 9,  2),
            (int) substr($dt, 11, 2),
            (int) substr($dt, 13, 2),
            (int) substr($dt, 4,  2),
            (int) substr($dt, 6,  2),
            (int) substr($dt, 0,  4)
        );
        return $utc ? $ts : $ts; // both treated as local/server time for display
    }

    // Date-only (all-day)
    return mktime(0, 0, 0,
        (int) substr($dt, 4, 2),
        (int) substr($dt, 6, 2),
        (int) substr($dt, 0, 4)
    );
}

// ── HTML helpers ──────────────────────────────────────────────────────────────

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function format_dt(int $ts): string
{
    return date('D j M Y, g:i A', $ts);
}

// ── Main ──────────────────────────────────────────────────────────────────────

$events = [];
$error  = null;
$now    = time();

try {
    $ical   = fetch_ical(ICAL_URL);
    $parsed = parse_ical($ical);

    foreach ($parsed as $ev) {
        $start = isset($ev['DTSTART']) ? ical_to_timestamp($ev['DTSTART']) : 0;
        // Keep only upcoming events
        if ($start >= $now) {
            $events[] = $ev + ['_start_ts' => $start];
        }
    }

    // Sort ascending by start time
    usort($events, fn($a, $b) => $a['_start_ts'] <=> $b['_start_ts']);

} catch (RuntimeException $e) {
    $error = $e->getMessage();
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Upcoming Events – Esperanto in Sydney</title>
</head>
<body>

<h1>Upcoming events &ndash; Esperanto in Sydney</h1>
<p>Source: <a href="https://www.meetup.com/<?= e(GROUP_URLNAME) ?>/events/">meetup.com/<?= e(GROUP_URLNAME) ?></a></p>

<?php if ($error): ?>
    <p><strong>Error:</strong> <?= e($error) ?></p>

<?php elseif (empty($events)): ?>
    <p>No upcoming events found.</p>

<?php else: ?>
    <p><?= count($events) ?> upcoming event<?= count($events) !== 1 ? 's' : '' ?>.</p>

    <?php foreach ($events as $ev): ?>
    <article>
        <h2><?= e($ev['SUMMARY'] ?? '(Untitled event)') ?></h2>

        <p><strong>Date:</strong> <?= format_dt($ev['_start_ts']) ?>
        <?php if (!empty($ev['DTEND'])): ?>
            &ndash; <?= format_dt(ical_to_timestamp($ev['DTEND'])) ?>
        <?php endif; ?>
        </p>

        <?php if (!empty($ev['LOCATION'])): ?>
        <p><strong>Location:</strong> <?= e($ev['LOCATION']) ?></p>
        <?php endif; ?>

        <?php if (!empty($ev['DESCRIPTION'])): ?>
        <p><?= nl2br(e(trim($ev['DESCRIPTION']))) ?></p>
        <?php endif; ?>

        <?php if (!empty($ev['URL'])): ?>
        <p><a href="<?= e($ev['URL']) ?>">RSVP on Meetup &rarr;</a></p>
        <?php endif; ?>

        <hr>
    </article>
    <?php endforeach; ?>

<?php endif; ?>

</body>
</html>
