<?php
/**
 * Secret-pattern scanner (shared by bin/scan-secrets.php and the artifact
 * inspector).
 *
 * Detects live-credential shapes (API keys, OAuth tokens, JWTs, private
 * keys) in files. Lines containing obvious fixture markers are allowed so
 * tests and documentation can discuss fake credentials freely.
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
 * Marker substrings that mark a line as containing fixture/example data.
 *
 * @return list<string>
 */
function wp_connectors_fixture_markers()
{
    return array(
        'fixture', 'example', 'dummy', 'sample', 'placeholder', 'not-a-real',
        'notareal', 'fake', 'test-value', 'your-', '<key>', 'xxx', 'redacted',
        'wpct_', 'abcdefgh', '0123456789abcdef',
    );
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
    $markers = wp_connectors_fixture_markers();
    $lines = explode("\n", $contents);
    foreach ($lines as $index => $line) {
        $lineLower = strtolower($line);
        $marked = false;
        foreach ($markers as $marker) {
            if (strpos($lineLower, $marker) !== false) {
                $marked = true;

                break;
            }
        }
        if ($marked) {
            continue;
        }
        foreach (wp_connectors_secret_patterns() as $name => $pattern) {
            if (preg_match($pattern[0], $line) === 1) {
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
            if ($extension !== '' && ! in_array($extension, array( 'php', 'js', 'json', 'txt', 'md', 'xml', 'yml', 'yaml', 'neon', 'env', 'ini', 'dist', 'po', 'svg', 'sh', 'go', 'conf', 'config', 'properties', 'pem', 'key' ), true)) {
                continue;
            }
            $findings = array_merge($findings, wp_connectors_scan_string((string) file_get_contents($file->getPathname()), $file->getPathname()));
        }
    }

    return $findings;
}
