<?php
/**
 * Autoloader prefix-binding fixtures (the R37-2 class: the prefix
 * probe's string-data laundering).
 *
 * The prefix probe once ran a raw strpos over comment-stripped but
 * STRING-BEARING source, so the expected prefix riding as a SUBSTRING
 * of any string literal satisfied the gate — a hostile zip's
 * src/autoload.php binding a FOREIGN prefix with $note = 'expected
 * Deicod\WpConnectors\Zai\ binding' passing green (driven: 0
 * violations where the byte-identical file with the literal's text
 * changed flags), a plugin that autoloads none of its classes
 * shipping through build, conventions, and inspect alike. The prefix
 * must ride CODE bytes (the masked view) OR stand as a quoted literal
 * whose DECODED VALUE EQUALS the prefix — containment in a longer
 * literal is prose, never a binding.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

require_once __DIR__ . '/../bin/check-conventions.php';

use PHPUnit\Framework\TestCase;

final class SelfContainmentAutoloaderPrefixBindingTest extends TestCase
{
    /**
     * @var string Per-test fixture base.
     */
    private $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/wp-connectors-prefix-' . uniqid('', true);
        @mkdir($this->base . '/zai/src', 0755, true);
    }

    protected function tearDown(): void
    {
        WpHarness::releaseScratch($this->base);
    }

    private function autoloadWith(string $body): array
    {
        file_put_contents($this->base . '/zai/src/autoload.php', $body);

        return wp_connectors_autoloader_violations($this->base . '/zai');
    }

    public function testAProseLiteralDoesNotBindThePrefix(): void
    {
        /*
         * R37-2 (security:medium, driven fail-open): the foreign
         * autoloader whose note literal merely MENTIONS the expected
         * prefix passes at HEAD — the substring satisfying the raw
         * strpos. Containment is prose; the literal-prose shape flags
         * exactly like the literal-changed control.
         */
        $prose = $this->autoloadWith("<?php\nspl_autoload_register(function (\$c) {\n    \$note = 'expected Deicod\\\\WpConnectors\\\\Zai\\\\ binding';\n});\n");
        $this->assertNotEmpty($prose, 'A prose literal mentioning the prefix binds nothing — the gate flags (red at HEAD: 0 violations).');

        $this->base .= '-control';
        @mkdir($this->base . '/zai/src', 0755, true);
        $control = $this->autoloadWith("<?php\nspl_autoload_register(function (\$c) {\n    \$note = 'note text';\n});\n");
        $this->assertSame('zai: src/autoload.php must bind PSR-4 prefix Deicod\\WpConnectors\\Zai\\ (derived from the plugin slug).', $control[0], 'The control keeps its byte-identical refusal.');
    }

    public function testTheCanonicalPrefixLiteralBinds(): void
    {
        /*
         * The benign half: the canonical single-quoted prefix literal
         * (equality — the real tree's own spelling, the escaped
         * backslashes decoded before the compare) binds; the real zai
         * autoload.php's own verdict is the build pins' charge, green
         * through the full check.
         */
        $canonical = $this->autoloadWith("<?php\n\$prefix = 'Deicod\\\\WpConnectors\\\\Zai\\\\';\nspl_autoload_register(function (\$class) use (\$prefix) {\n    \$path = __DIR__ . '/' . str_replace('\\\\', '/', substr(\$class, strlen(\$prefix))) . '.php';\n    require \$path;\n});\n");
        $this->assertSame(array(), $canonical, 'The canonical quoted prefix literal (decoded value EQUAL to the prefix) binds.');

        $this->base .= '-substring';
        @mkdir($this->base . '/zai/src', 0755, true);
        $substring = $this->autoloadWith("<?php\nspl_autoload_register(function (\$c) {\n    \$p = 'src/' . \$c . '-Deicod\\\\WpConnectors\\\\Zai\\\\.php';\n    require __DIR__ . '/' . \$p;\n});\n");
        $this->assertNotEmpty($substring, 'A literal whose decoded value merely CONTAINS the prefix as a substring is prose, never a binding — equality is the bar.');
    }
}
