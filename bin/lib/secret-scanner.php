<?php
/**
 * Secret-pattern scanner (shared by bin/scan-secrets.php and the artifact
 * inspector).
 *
 * Detects live-credential shapes (API keys, OAuth tokens, JWTs, private
 * keys) in files. Exemptions are structured, never a bare word on the line:
 * a line is skipped only when it carries the strict "secrets:allow" comment
 * marker, and a match is skipped only when the matched VALUE itself is
 * recognizable as a fake (placeholder shapes, well-known dummy segments) —
 * see wp_connectors_allow_marker_pattern() and
 * wp_connectors_is_recognizably_fake_secret().
 *
 * Findings deliberately never include the matched text — only file, line,
 * and pattern — so the scanner itself can never leak a secret into logs.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

/**
 * Returns the secret patterns this repository guards against.
 *
 * Each entry: name => [ regex, description ].
 *
 * @return array<string, list<string>>
 */
function wp_connectors_secret_patterns()
{
    return array(
        'private-key' => array( '/-----BEGIN (?:RSA |EC |OPENSSH |DSA |PGP )?PRIVATE KEY-----/', 'Private key material' ),
        'github-token' => array( '/\bgh[pousr]_[A-Za-z0-9]{20,}\b/', 'GitHub token' ),
        'openai-anthropic-key' => array( '/\bsk-(?:ant-|proj-)?(?:api3?-)?[A-Za-z0-9_-]{20,}\b/', 'OpenAI/Anthropic API key' ),
        'xai-key' => array( '/\bxai-[A-Za-z0-9]{20,}\b/', 'xAI API key' ),
        'zai-key' => array( '/\b[a-f0-9]{32}\.[a-f0-9]{16}\b/', 'z.ai / bigmodel.cn API key' ),
        'aws-key' => array( '/\bAKIA[0-9A-Z]{16}\b/', 'AWS access key ID' ),
        'google-key' => array( '/\bAIza[0-9A-Za-z_-]{35}\b/', 'Google API key' ),
        'slack-token' => array( '/\bxox[baprs]-[A-Za-z0-9-]{10,}\b/', 'Slack token' ),
        'jwt' => array( '/\beyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{5,}\b/', 'JWT token' ),
        'bearer-token' => array( '/\bBearer\s+[A-Za-z0-9._+\/-]{24,}/i', 'HTTP Bearer token' ),
    );
}

/**
 * The strict line-exemption marker for deliberate fixture/example secrets.
 *
 * A line is exempt ONLY when the marker appears inside a comment on that
 * line: `$key = '…'; // secrets:allow` (also `#`, `/* … *\/`, and ` * `
 * docblock continuations). A bare word like "fixture" anywhere on the line
 * exempts nothing — a real credential sitting on a line that merely
 * mentions "fixture" must still be flagged.
 *
 * @return string PCRE pattern matching the marker inside a comment.
 */
function wp_connectors_allow_marker_pattern()
{
    return '/(?:^|\s)(?:\/\/|#|\/\*|\*)\s*secrets:allow\b/';
}

/**
 * Whether a matched secret VALUE is recognizable as a fake.
 *
 * Recognizable fakes are verifiable from the value itself: placeholder
 * shapes wrapped entirely in ${…} or <…>, well-known dummy segments
 * bounded by separators (sk-proj-TEST-…, YOUR_API_KEY, test-key-…,
 * wpct_fixture_…, not-a-real-…), and obvious sequential filler. Anything
 * else must be treated as live. This mirrors the line-level marker rule:
 * prose words around the value never exempt it, only the value's own shape
 * can.
 *
 * @param string $value The matched secret text (never reported verbatim).
 * @return bool True when the value is verifiably not a live credential.
 */
function wp_connectors_is_recognizably_fake_secret($value)
{
    if (preg_match('/^\$\{[^}]+\}$/', $value) || preg_match('/^<[^>]+>$/', $value)) {
        return true;
    }
    if (preg_match('/(?:^|[-_\s])(?:not-a-real|notareal|test-value|test|example|dummy|sample|fixture|placeholder|your|fake|redacted|wpct)(?:[-_\s]|$)/i', $value)) {
        return true;
    }

    return (bool) preg_match('/0123456789abcdef|abcdefgh/i', $value);
}

/**
 * Scans one file's contents for secret patterns.
 *
 * @param string $contents File contents.
 * @param string $label    File label for findings (path or zip entry).
 * @return list<string> Findings ("<label>:<line> <name> (<description>)").
 */
function wp_connectors_scan_string($contents, $label)
{
    $findings = array();
    $allowMarker = wp_connectors_allow_marker_pattern();
    $lines = explode("\n", $contents);
    foreach ($lines as $index => $line) {
        if (preg_match($allowMarker, $line) === 1) {
            continue;
        }
        foreach (wp_connectors_secret_patterns() as $name => $pattern) {
            if (preg_match_all($pattern[0], $line, $matches) === 0) {
                continue;
            }
            foreach ($matches[0] as $matched) {
                if (wp_connectors_is_recognizably_fake_secret($matched)) {
                    continue;
                }
                // Never include the matched text in the finding.
                $findings[] = sprintf('%s:%d %s (%s)', $label, $index + 1, $name, $pattern[1]);

                break;
            }
        }
    }

    return $findings;
}

/**
 * Recursively scans files under given roots.
 *
 * @param list<string> $roots Absolute paths (files or directories).
 * @return list<string> Findings.
 */
function wp_connectors_scan_paths(array $roots)
{
    $findings = array();
    $excluded = array( '.git', 'vendor', 'node_modules', 'dist', 'tools', '.phpunit.cache' );
    foreach ($roots as $root) {
        if (is_file($root)) {
            $findings = array_merge($findings, wp_connectors_scan_string((string) file_get_contents($root), $root));
            continue;
        }
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            $parts = explode(DIRECTORY_SEPARATOR, $file->getPathname());
            if (array_intersect($parts, $excluded) !== array()) {
                continue;
            }
            if (! $file->isFile() || $file->getSize() > 2 * 1024 * 1024) {
                continue;
            }
            $extension = strtolower($file->getExtension());
            if ($extension !== '' && ! in_array($extension, array( 'php', 'js', 'json', 'txt', 'md', 'xml', 'yml', 'yaml', 'neon', 'env', 'ini', 'dist', 'po', 'svg', 'sh', 'go', 'conf', 'config', 'properties', 'pem', 'key', 'toml' ), true)) {
                continue;
            }
            $findings = array_merge($findings, wp_connectors_scan_string((string) file_get_contents($file->getPathname()), $file->getPathname()));
        }
    }

    return $findings;
}
