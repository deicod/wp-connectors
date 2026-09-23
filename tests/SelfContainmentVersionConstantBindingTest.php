<?php
/**
 * Version-constant binding fixtures (the R37-3 class: the define
 * probe's raw-source laundering, with the R33-6 case axis riding).
 *
 * The define probe once ran unanchored over the RAW main-file source,
 * so a define spelling inside a comment or heredoc body both
 * satisfied the 'main file must define constant' arm and supplied the
 * header-matching value (driven: 0 violations over a plugin whose
 * bare constant reference fatals at runtime) — and the byte-exact
 * lowercase 'define(' refused a legal DEFINE/Define spelling (the
 * R33-6 inheritance, recorded awaiting a round that claims the seat).
 * The probe runs over the COMMENT-STRIPPED view, anchored at a
 * statement start, case-insensitive at the keyword — a binding is a
 * real call at line start, nothing else.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

require_once __DIR__ . '/../bin/check-conventions.php';

use PHPUnit\Framework\TestCase;

final class SelfContainmentVersionConstantBindingTest extends TestCase
{
    /**
     * @var string Per-test fixture root.
     */
    private $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wp-connectors-version-' . uniqid('', true);
        @mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        WpHarness::releaseScratch($this->root);
    }

    private function drive(string $name, string $body): array
    {
        $constant = strtoupper(strtr(basename($this->root), '-.', '__')) . '_VERSION';
        file_put_contents(
            $this->root . '/x.php',
            "<?php\n/**\n * Plugin Name: Test Plugin\n * Version: 1.2.3\n */\n" . sprintf($body, $constant) . "\n"
        );

        return wp_connectors_version_constant_violations($this->root, array('version' => '1.2.3'), array($this->root . '/x.php'));
    }

    public function testACommentedOrHeredocDefineDoesNotBind(): void
    {
        /*
         * R37-3 (security:medium, driven fail-open): the comment and
         * heredoc spellings satisfied the raw-source probe at HEAD —
         * inspection green on a plugin that fatals at runtime. A
         * binding is a real call in code: both shapes flag exactly
         * like the no-define control.
         */
        $comment = $this->drive('comment', "// define('%s', '1.2.3');");
        $this->assertStringContainsString('must define constant', implode("\n", $comment), 'A commented define binds nothing — the constant is absent at runtime (red at HEAD: 0 violations).');

        $this->root .= '-heredoc';
        @mkdir($this->root, 0755, true);
        $heredoc = $this->drive('heredoc', "\$note = <<<EOT\ndefine('%s', '1.2.3');\nEOT;");
        $this->assertStringContainsString('must define constant', implode("\n", $heredoc), 'A define inside heredoc DATA binds nothing — string data never defines.');
    }

    public function testRealAndCaseVariantDefinesBindAndMismatchesFlag(): void
    {
        /*
         * The benign half and the R33-6 inheritance: the real define,
         * its indented spelling, and the legal DEFINE case variant
         * (the recorded 'define(' false-refusal, closed at the seat
         * this round claims) all bind clean; a value mismatch keeps
         * its own refusal.
         */
        $this->assertSame(array(), $this->drive('real', "define('%s', '1.2.3');"), 'The real define binds clean.');

        $this->root .= '-indented';
        @mkdir($this->root, 0755, true);
        $this->assertSame(array(), $this->drive('indented', "    define('%s', '1.2.3');"), 'The indented define binds — the anchor admits line-leading whitespace.');

        $this->root .= '-upper';
        @mkdir($this->root, 0755, true);
        $this->assertSame(array(), $this->drive('upper', "DEFINE('%s', '1.2.3');"), 'The legal DEFINE spelling binds — PHP lexes function names case-insensitively (the R33-6 inheritance closed).');

        $this->root .= '-mismatch';
        @mkdir($this->root, 0755, true);
        $mismatch = $this->drive('mismatch', "define('%s', '9.9.9');");
        $this->assertStringContainsString('does not match header Version', implode("\n", $mismatch), 'The value-mismatch refusal keeps its own verdict.');
    }
}
