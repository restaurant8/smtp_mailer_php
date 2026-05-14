<?php

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect_with_notice(string $path, string $notice): never
{
    header('Location: ' . $path . '?notice=' . rawurlencode($notice), true, 303);
    exit;
}

function normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function unsubscribe_token(string $email): string
{
    return hash_hmac('sha256', normalize_email($email), config()['app_secret']);
}

function unsubscribe_url(string $email): string
{
    $base = rtrim(config()['app_url'], '/');
    return $base . '/unsubscribe?email=' . rawurlencode(normalize_email($email)) . '&token=' . unsubscribe_token($email);
}

function render_template(string $body, array $vars, bool $escapeHtml = false): string
{
    return preg_replace_callback('/{{\s*([a-zA-Z0-9_]+)\s*}}/', function (array $matches) use ($vars, $escapeHtml) {
        $value = (string) ($vars[$matches[1]] ?? '');
        return $escapeHtml ? e($value) : $value;
    }, $body);
}

function parse_contacts(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }

    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $lines = array_values(array_filter($lines, fn ($line) => trim($line) !== ''));
    if ($lines === []) {
        return [];
    }

    $contacts = [];
    $first = strtolower($lines[0]);
    $hasHeader = str_contains($first, 'email');
    $isCsv = str_contains(implode("\n", array_slice($lines, 0, 3)), ',');

    if ($isCsv) {
        $headers = $hasHeader ? str_getcsv(array_shift($lines)) : ['email', 'name', 'company'];
        foreach ($lines as $line) {
            $values = str_getcsv($line);
            $row = array_combine(array_slice($headers, 0, count($values)), $values);
            if (!$row || empty($row['email'])) {
                continue;
            }
            $contacts[] = [
                'email' => normalize_email($row['email']),
                'name' => trim($row['name'] ?? ''),
                'company' => trim($row['company'] ?? ''),
                'vars_json' => json_encode($row, JSON_UNESCAPED_UNICODE),
            ];
        }
        return $contacts;
    }

    foreach ($lines as $line) {
        $contacts[] = [
            'email' => normalize_email($line),
            'name' => '',
            'company' => '',
            'vars_json' => json_encode(['email' => normalize_email($line)], JSON_UNESCAPED_UNICODE),
        ];
    }

    return $contacts;
}
