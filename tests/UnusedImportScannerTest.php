<?php
/**
 * Unused-import scanner fixtures (glm17-12).
 *
 * wp_connectors_unused_import_violations() is wired into `composer
 * check` as a pass/fail gate (glm16-10) and has been reworked since —
 * glm16-17's one-copy removal (plus its incidental CRLF/EOF detection
 * delta), glm17-8's token-masked import finding, glm17-9's
 * case-insensitive mentions, and glm17-11's offset-capture removal —
 * with nothing in the suite pinning any of it. These fixtures pin the
 * documented contract: only an import whose short name appears
 * NOWHERE else in the real source (case-insensitively — comments and
 * docblocks count) is flagged; imports are LOCATED on the
 * token-masked view, so string/heredoc/comment text is never code;
 * and a directory named *.php is skipped, not scanned.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

require_once __DIR__ . '/../bin/check-conventions.php';

use PHPUnit\Framework\TestCase;

final class UnusedImportScannerTest extends TestCase
{
    /**
     * @var string Per-test fixture root.
     */
    private $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wp-connectors-unused-import-' . uniqid('', true);
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ((glob($this->root . '/*') ?: array()) as $entry) {
            if (is_link($entry)) {
                @unlink($entry);
            } elseif (is_dir($entry)) {
                @rmdir($entry);
            } else {
                @unlink($entry);
            }
        }
        @rmdir($this->root);
    }

    /**
     * @dataProvider importFixtureProvider
     */
    public function testScannerVerdict(string $source, int $expected): void
    {
        file_put_contents($this->root . '/fixture.php', $source);

        $this->assertSame(
            $expected,
            wp_connectors_unused_import_violations($this->root),
            'The scanner verdict must match the documented contract for this fixture.'
        );
    }

    public function importFixtureProvider(): array
    {
        return array(
            'unused import flags' => array(
                <<<'FIXTURE'
<?php
use Vendor\Package\Widget;
FIXTURE
                ,
                1,
            ),
            'code use does not flag' => array(
                <<<'FIXTURE'
<?php
use Vendor\Package\Widget;
$x = new Widget();
FIXTURE
                ,
                0,
            ),
            'mixed-case use does not flag (glm17-9)' => array(
                <<<'FIXTURE'
<?php
use Vendor\Package\Widget;
$x = new widget();
FIXTURE
                ,
                0,
            ),
            'docblock-only mention does not flag (conservative contract)' => array(
                <<<'FIXTURE'
<?php
use Vendor\Package\Widget;
/**
 * @param Widget $w
 */
function f( $w ) {}
FIXTURE
                ,
                0,
            ),
            'prose comment mention does not flag' => array(
                <<<'FIXTURE'
<?php
use Vendor\Package\Widget;
// The Widget handles this.
FIXTURE
                ,
                0,
            ),
            'comment carrying the exact statement text does not flag (glm16-17)' => array(
                <<<'FIXTURE'
<?php
// see use Vendor\Package\Widget;
use Vendor\Package\Widget;
FIXTURE
                ,
                0,
            ),
            'alias used via alias does not flag' => array(
                <<<'FIXTURE'
<?php
use Vendor\Package\Widget as W;
$x = new W();
FIXTURE
                ,
                0,
            ),
            'unused alias flags' => array(
                <<<'FIXTURE'
<?php
use Vendor\Package\Widget as UnusedAlias;
FIXTURE
                ,
                1,
            ),
            'function import used does not flag' => array(
                <<<'FIXTURE'
<?php
use function Vendor\helper;
helper();
FIXTURE
                ,
                0,
            ),
            'unused function import flags' => array(
                <<<'FIXTURE'
<?php
use function Vendor\helper;
FIXTURE
                ,
                1,
            ),
            'heredoc column-0 use line is data, not an import (glm17-8)' => array(
                <<<'FIXTURE'
<?php
$scaffold = <<<'EOT'
use WP_Fix\PhantomImport;
EOT;
FIXTURE
                ,
                0,
            ),
            'block comment column-0 use line is data, not an import (glm17-8)' => array(
                <<<'FIXTURE'
<?php
/*
use WP_Fix\CommentImport;
*/
FIXTURE
                ,
                0,
            ),
            'dead import after a line comment flags (glm17-14 anchor)' => array(
                <<<'FIXTURE'
<?php
// TODO remove after M5
use Vendor\Package\LegacyClient;
FIXTURE
                ,
                1,
            ),
            'closure use is not an import' => array(
                <<<'FIXTURE'
<?php
$f = function () use ( $x ) { return $x; };
FIXTURE
                ,
                0,
            ),
            'CRLF file with a dead import flags (glm16-17 delta)' => array(
                str_replace("\n", "\r\n", "<?php\nuse Vendor\\Package\\DeadOne;\n"),
                1,
            ),
            'EOF without a trailing newline flags (glm16-17 delta)' => array(
                "<?php\nuse Vendor\\Package\\DeadTwo;",
                1,
            ),
        );
    }

    public function testADirectoryNamedPhpIsSkipped(): void
    {
        /*
         * glm17-10 / glm17-16: the ONLY directory shape the recursive
         * iterator yields as a leaf is a SYMLINK to a directory (plain
         * directories are descended, never emitted — the glm17-12 form
         * of this test was vacuous, verifier-confirmed) — and one named
         * *.php passes the extension gate, so the isDir() skip is what
         * keeps it out of the file read on every PHP version.
         */
        mkdir($this->root . '/target');
        $linked = symlink($this->root . '/target', $this->root . '/looks-like-a-file.php');
        if (false === $linked) {
            $this->markTestSkipped('This host cannot create symlinks.');
        }
        file_put_contents($this->root . '/real.php', "<?php\nuse Vendor\\Package\\Used;\n\$x = new Used();\n");

        $this->assertSame(0, wp_connectors_unused_import_violations($this->root));
    }

    public function testADanglingSymlinkNamedPhpFailsLoudly(): void
    {
        /*
         * glm17-16: glm17-10's loud unreadable-file branch pinned by the
         * one unreadable shape that fails on EVERY host (chmod-000 stays
         * readable under root): reading a dangling symlink returns
         * false, so the branch must count exactly one violation — the
         * cast-revert mutation (back to silent '' compliance) turns
         * this red.
         */
        $linked = symlink($this->root . '/no-such-target', $this->root . '/dangling.php');
        if (false === $linked) {
            $this->markTestSkipped('This host cannot create symlinks.');
        }

        $this->assertSame(1, wp_connectors_unused_import_violations($this->root));
    }

    public function testStrippedCommentsKeepTheirLineTerminator(): void
    {
        /*
         * glm17-14: on PHP < 8.0 the tokenizer includes the trailing
         * newline inside T_COMMENT; blanking the whole token joined the
         * next line onto the comment's line in the stripped view,
         * un-anchoring every ^-anchored scan (the scanner's /^use/m
         * silently missed real dead imports on the composer-pinned 7.4
         * floor — glm17 verifier round, empirically confirmed in
         * docker php:7.4-cli). The contract below holds identically on
         * 7.4 (terminator preserved out of the comment token) and 8.0+
         * (the newline is separate whitespace copied verbatim), and
         * fails on 7.4 against the old all-spaces strip.
         */
        $source   = "<?php\n// drop\nuse Vendor\\Package\\StillAnchored;\n";
        $stripped = wp_connectors_strip_comments($source);

        $this->assertSame(strlen($source), strlen($stripped), 'The stripped view stays length-preserving.');
        $this->assertNotFalse(
            strpos($stripped, "\nuse Vendor\\Package\\StillAnchored;"),
            'The comment line keeps its terminator, so the following use statement keeps its ^ anchor on every supported PHP version.'
        );
    }
}
