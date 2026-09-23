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

    public function testAVendorOrComposerIncludeOperandStillReferencesComposerOrVendor(): void
    {
        /*
         * R39-3 (security:medium, driven true-positive loss — round
         * 38's masked probe one leg too far): the masked view blanks
         * string contents, so a RUNTIME OPERAND riding in a quoted
         * literal — 'require_once __DIR__ .
         * "/vendor/pkg/lib.php";' — turned invisible where master
         * flagged it, the hostile plugin passing the gate green.
         * The prose immunity stands (the masked probe); the OPERAND
         * probe judges the raw text of every require/include
         * statement — an include path is never prose, whatever its
         * quoting. The prose-note twin stays clean beside it.
         */
        $canonical = "<?php\n\$prefix = 'Deicod\\\\WpConnectors\\\\Zai\\\\';\nspl_autoload_register(function (\$class) use (\$prefix) { \$path = __DIR__ . '/' . str_replace('\\\\', '/', substr(\$class, strlen(\$prefix))) . '.php'; require_once \$path; });\nrequire_once __DIR__ . '/vendor/pkg/lib.php';\n";
        $operand = $this->autoloadWith($canonical);
        $this->assertStringContainsString('must not reference composer or vendor', implode("\n", $operand), 'A vendor include path is a runtime operand, never prose — the reference flags (red at HEAD: 0 violations where master flags).');

        $this->base .= '-prose';
        @mkdir($this->base . '/zai/src', 0755, true);
        $prose = $this->autoloadWith("<?php\n\$prefix = 'Deicod\\\\WpConnectors\\\\Zai\\\\';\nspl_autoload_register(function (\$class) use (\$prefix) { \$path = __DIR__ . '/' . str_replace('\\\\', '/', substr(\$class, strlen(\$prefix))) . '.php'; require_once \$path; });\n\$note = 'no vendor or composer here';\n");
        $this->assertStringNotContainsString('must not reference composer or vendor', implode("\n", $prose), 'The prose-note twin stays clean — the masked probe\'s immunity intact.');
    }

    public function testACommentNamingThePrefixAndStringDataNamingTheRegisterProbeBindNothing(): void
    {
        /*
         * R38-1+R38-4 (security:medium, driven — round 37's sweep
         * stopped one view short at this seat): the prefix probe
         * composed the masker over RAW source, so a comment naming
         * the expected prefix satisfied the code-byte arm (driven: a
         * foreign autoloader plus the comment answering 0 violations,
         * master's comment-stripped probe having refused the same
         * bytes), and the register probes judged string data
         * case-sensitively — a '$note = "spl_autoload_register";'
         * satisfying both arms, a legal 'Spl_AutoLoad_Register(...)'
         * refused. Every probe rides the provider's STRIPPED+MASKED
         * view now, the register count case-insensitive: comments
         * and string contents blank, the keyword folding.
         */
        $comment = $this->autoloadWith("<?php
// expected prefix Deicod\\WpConnectors\\Zai\\ bound below
spl_autoload_register(function (\$c) {
    \$p = 'foreign/' . \$c . '.php';
    require __DIR__ . '/' . \$p;
});
");
        $this->assertNotEmpty($comment, 'A comment naming the prefix binds nothing — the provider view blanks comments (red at HEAD: 0 violations).');

        $this->base .= '-string';
        @mkdir($this->base . '/zai/src', 0755, true);
        $string = $this->autoloadWith("<?php
\$note = 'spl_autoload_register';
");
        $this->assertCount(3, $string, 'String data naming the register probe satisfies nothing — all three verdicts fire (red at HEAD: 1 violation, the string satisfying both register arms).');

        $this->base .= '-case';
        @mkdir($this->base . '/zai/src', 0755, true);
        $caseVariant = $this->autoloadWith("<?php
Spl_AutoLoad_Register(function (\$class) { \$prefix = 'Deicod\\\\WpConnectors\\\\Zai\\\\'; \$path = __DIR__ . '/' . str_replace('\\\\', '/', substr(\$class, strlen(\$prefix))) . '.php'; require \$path; });
");
        $this->assertSame(array(), $caseVariant, 'The legal Spl_AutoLoad_Register spelling registers — PHP lexes function names case-insensitively (red at HEAD: both register violations).');
    }
}
