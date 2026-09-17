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
            /*
             * glm20-3: group-use declarations were invisible to the gate
             * — the single-class pattern stops at the '{', so a dead
             * import inside a group never flagged. Every import inside
             * the braces is judged by the same mention contract as the
             * single form.
             */
            'group-use with an unused member flags (glm20-3)' => array(
                <<<'FIXTURE'
<?php
use Vendor\Package\{Used, Dead};
$x = new Used();
FIXTURE
                ,
                1,
            ),
            'group-use fully used does not flag (glm20-3)' => array(
                <<<'FIXTURE'
<?php
use Vendor\Package\{Alpha, Beta as B};
$x = new Alpha();
$y = new B();
FIXTURE
                ,
                0,
            ),
            'group-use alias member unused flags (glm20-3)' => array(
                <<<'FIXTURE'
<?php
use Vendor\Package\{Alpha as A, Beta};
$x = new Beta();
FIXTURE
                ,
                1,
            ),
            'nested group-use unused member flags (glm20-3)' => array(
                <<<'FIXTURE'
<?php
use Vendor\{Pkg\{Deep}, Other};
$x = new Deep();
FIXTURE
                ,
                1,
            ),
            'function group-use unused member flags (glm20-3)' => array(
                <<<'FIXTURE'
<?php
use function Vendor\{helper, dead_helper};
helper();
FIXTURE
                ,
                1,
            ),
            /*
             * glm27-2 (Codex R21 finding 2): the MIXED group-use syntax
             * carries per-member kinds — use Vendor\Pkg\{function
             * helper, const FLAG, Widget}; — and the typed members
             * failed both member regexes, silently skipping them as
             * though invalid: an unused typed member reported NO
             * violation. The kind prefix is stripped before the parse
             * now; it never affects the short name.
             */
            'mixed group-use with typed members all used does not flag (glm27-2)' => array(
                <<<'FIXTURE'
<?php
use Vendor\Pkg\{function helper, const FLAG, Widget};
helper();
$x = FLAG;
$y = new Widget();
FIXTURE
                ,
                0,
            ),
            'mixed group-use with one typed member unused flags (glm27-2)' => array(
                <<<'FIXTURE'
<?php
use Vendor\Pkg\{function helper, const FLAG, Widget};
helper();
$y = new Widget();
FIXTURE
                ,
                1,
            ),
            'mixed group-use alias on a typed member resolves (glm27-2)' => array(
                <<<'FIXTURE'
<?php
use Vendor\Pkg\{function helper as h, const FLAG as F};
h();
$x = F;
FIXTURE
                ,
                0,
            ),
            'mixed group-use unused aliased typed member flags (glm27-2)' => array(
                <<<'FIXTURE'
<?php
use Vendor\Pkg\{function helper as h, const FLAG as F};
h();
FIXTURE
                ,
                1,
            ),
            'group-use member mention in comment does not flag (glm20-3)' => array(
                <<<'FIXTURE'
<?php
use Vendor\Package\{Widget};
// The Widget handles this.
FIXTURE
                ,
                0,
            ),
            /*
             * The members are split on the MASKED view: a comma inside a
             * blanked comment is not a member separator. Splitting the
             * RAW bytes instead corrupts 'Dead /* ...' into a non-name
             * shape that is skipped — the dead import fails OPEN, the
             * exact silent false negative glm20-3 closes.
             */
            'comment comma cannot hide a group member (glm20-3)' => array(
                <<<'FIXTURE'
<?php
use Vendor\Package\{Dead /* a, b */, Alpha};
$x = new Alpha();
FIXTURE
                ,
                1,
            ),
            'unterminated group use stays neutral (lint owns it)' => array(
                <<<'FIXTURE'
<?php
use Vendor\{Broken;
FIXTURE
                ,
                0,
            ),
            /*
             * glm28-1: a trailing comment between the qualified name and
             * the terminator is legal PHP, and the qualified name is
             * derived from the REAL statement bytes — the comment used
             * to ride into the short name ('Request' plus the note
             * bytes), a string that appears nowhere else, so a
             * genuinely used import flagged. The import's own bytes
             * cannot contain a comment opener, so the first opener ends
             * them.
             */
            'trailing comment on a used import does not flag (glm28-1)' => array(
                <<<'FIXTURE'
<?php
use Vendor\Pkg\Request /* note */;
function f(): Request {
	return new Request();
}
FIXTURE
                ,
                0,
            ),
            'trailing comment on an unused import still flags (glm28-1)' => array(
                <<<'FIXTURE'
<?php
use Vendor\Pkg\DeadThing /* note */;
FIXTURE
                ,
                1,
            ),
            'trailing comment after an alias keeps the alias short name (glm28-1)' => array(
                <<<'FIXTURE'
<?php
use Vendor\Pkg\Widget as W /* note */;
$x = new W();
FIXTURE
                ,
                0,
            ),
            'trailing line-comment on an unused import still flags (glm28-1)' => array(
                "<?php\nuse Vendor\\Pkg\\DeadLine # note\n;\n",
                1,
            ),
            /*
             * glm28-22 (the security verifier's regression repro): a
             * comment between the keyword and the name is legal PHP
             * too, and the first real-bytes comment cut emptied the
             * derived name — the pre-round scanner flagged these dead
             * imports, the cut silently skipped them. The name derives
             * from the MASKED match text now (comments blank to space
             * runs the keyword-prefix regex spans), so every comment
             * position derives the clean name.
             */
            'comment between keyword and name, unused, still flags (glm28-22)' => array(
                <<<'FIXTURE'
<?php
use /* note */ Vendor\Pkg\DeadThing;
FIXTURE
                ,
                1,
            ),
            'comment between keyword and name, used, does not flag (glm28-22)' => array(
                <<<'FIXTURE'
<?php
use /* note */ Vendor\Pkg\Widget;
$x = new Widget();
FIXTURE
                ,
                0,
            ),
            'hash comment between keyword and name, unused, still flags (glm28-22)' => array(
                "<?php\nuse # hash\n Vendor\\Pkg\\DeadThree;\n",
                1,
            ),
        );
    }

    /**
     * glm28-1: the violation message must print the CLEAN qualified
     * name. The short-name derivation used to carry the trailing
     * comment bytes into the flag text, and while the count fixtures
     * above pin the verdict, the message shape needs the real STDERR
     * channel — captured through a child process because fwrite to
     * STDERR bypasses output buffering.
     */
    public function testTheTrailingCommentFlagMessagePrintsTheCleanQualifiedName(): void
    {
        /*
         * The exec-capability guard (t31-ocr18-2, the t31-ocr16-12
         * doctrine over this child-process consumer): the STDERR
         * channel is captured through a spawned engine, and on a
         * disable_functions host the first spawn was an
         * undefined-function \Error instead of the visible skip.
         */
        if (! function_exists('exec') || ! function_exists('escapeshellarg')) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the STDERR-channel message leg cannot run (the clean-name verdict rides a child process).');
        }

        file_put_contents($this->root . '/fixture.php', "<?php\nuse Vendor\\Pkg\\DeadThing /* note */;\n");

        $script = 'require ' . var_export(realpath(__DIR__ . '/../bin/check-conventions.php'), true) . ';'
            . ' wp_connectors_unused_import_violations(' . var_export($this->root, true) . ');';
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1', $output, $exit);
        $message = implode("\n", $output);

        $this->assertSame(0, $exit, 'The scanner helper must not exit non-zero; the CLI gate owns the exit code.');
        $this->assertStringContainsString(
            "unused import 'Vendor\\Pkg\\DeadThing'",
            $message,
            'The flag message must print the clean qualified name.'
        );
        $this->assertStringNotContainsString(
            'note',
            $message,
            'The flag message must not carry the trailing comment bytes.'
        );
    }

    /**
     * Fix-round pin (t31-r9-7): the conventions gate's unused-import
     * scan covered only connectors/, so a dead import in shared/src
     * passed every gate and then shipped into EVERY embedding plugin —
     * the phantom-dependency drift the gate exists to kill, one tree
     * further out. Pinned through the CLI itself against a scratch
     * repo (the gate computes its roots from its own location, so the
     * wiring — not just the scanner helper — is what's under test):
     * a planted dead `use RuntimeException;` in the scratch shared
     * tree fails the run naming the file, and the same repo without
     * it stays green.
     */
    public function testTheConventionsGateScansTheSharedSourceTree(): void
    {
        /*
         * The exec-capability guard (t31-ocr18-2, the t31-ocr16-12
         * doctrine over this child-process consumer): both gate runs
         * (clean control and planted dead import) spawn the scratch
         * repo's check-conventions.php through a child process, and
         * on a disable_functions host the first spawn was an
         * undefined-function \Error instead of the visible skip.
         */
        if (! function_exists('exec') || ! function_exists('escapeshellarg')) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the scratch-repo gate legs cannot run (both verdicts ride child processes).');
        }

        $repo = sys_get_temp_dir() . '/wp-connectors-conventions-shared-' . uniqid('', true);
        mkdir($repo . '/bin/lib', 0755, true);
        mkdir($repo . '/shared/src/Clock', 0755, true);
        mkdir($repo . '/connectors', 0755, true);
        copy(dirname(__DIR__) . '/bin/check-conventions.php', $repo . '/bin/check-conventions.php');
        copy(dirname(__DIR__) . '/bin/lib/plugin-tools.php', $repo . '/bin/lib/plugin-tools.php');
        // The gate's repo-level checks need a CHANGELOG at the root.
        file_put_contents($repo . '/CHANGELOG.md', "# scratch\n");
        // A valid plugin so ONLY the unused-import verdict can fail the run.
        // The scratch-tree helpers are the harness's ONE pair (t31-ocr1-9).
        WpHarness::copyTree(__DIR__ . '/fixtures/plugins/example-connector', $repo . '/connectors/example-connector');

        try {
            // Control: the clean shared tree is invisible to the gate.
            file_put_contents(
                $repo . '/shared/src/Clock/ClockInterface.php',
                "<?php\nnamespace Shared\\Clock;\ninterface ClockInterface {}\n"
            );
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo . '/bin/check-conventions.php') . ' 2>&1', $cleanOutput, $cleanExit);
            $this->assertSame(0, $cleanExit, "The clean scratch repo must pass the gate: " . implode("\n", $cleanOutput));

            // The planted dead import in shared/src fails the run.
            file_put_contents(
                $repo . '/shared/src/Clock/DeadImport.php',
                "<?php\nnamespace Shared\\Clock;\nuse RuntimeException;\ninterface DeadImport {}\n"
            );
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo . '/bin/check-conventions.php') . ' 2>&1', $plantedOutput, $plantedExit);
            $message = implode("\n", $plantedOutput);

            $this->assertSame(1, $plantedExit, 'A dead import in shared/src must fail the conventions gate.');
            $this->assertStringContainsString("unused import 'RuntimeException'", $message, 'The failure must be the unused-import vocabulary.');
            $this->assertStringContainsString('Clock/DeadImport.php', $message, 'The failure must name the shared source file.');
            /*
             * t31-ocr5-2: the summary attributes every count to its own
             * tree. The pooled line read "1 plugin dir(s) checked, 1
             * violation(s)" on this exact fixture — a shared/src-only
             * red run whose plugin dirs (never scanned for the
             * violation) carried the count.
             */
            $this->assertStringContainsString(
                '1 plugin dir(s) checked, 0 plugin-tree violation(s), 1 shared/src violation(s), 0 repo violation(s)',
                $message,
                'A shared/src-only red run must not attribute its violations to the plugin dirs.'
            );
        } finally {
            WpHarness::rrmdir($repo);
        }
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
         * silently missed real dead imports on the former
         * composer-pinned 7.4 floor — glm17 verifier round, empirically
         * confirmed in docker php:7.4-cli). The contract below holds on
         * the 8.0+ tokenizer the 8.2 floor guarantees (terminator
         * preserved out of the comment token; the newline is separate
         * whitespace copied verbatim) and failed on 7.4 against the old
         * all-spaces strip — the version-conditional history stays
         * because the byte-preservation invariant it explains is
         * version-independent.
         */
        $source   = "<?php\n// drop\nuse Vendor\\Package\\StillAnchored;\n";
        $stripped = wp_connectors_strip_comments($source);

        $this->assertSame(strlen($source), strlen($stripped), 'The stripped view stays length-preserving.');
        $this->assertNotFalse(
            strpos($stripped, "\nuse Vendor\\Package\\StillAnchored;"),
            'The comment line keeps its terminator, so the following use statement keeps its ^ anchor on every supported PHP version.'
        );
    }

    public function testTheSharedViewProviderServesBothChecksPerContent(): void
    {
        /*
         * glm25-8: the conventions gate tokenizes every connectors/*.php
         * file once, not once per check — the self-containment driver and
         * this scanner both read their (source, code, masked) views from
         * wp_connectors_file_code_views(). The provider's memo is keyed
         * by CONTENT (path + md5), so a rewritten fixture re-tokenizes:
         * no mtime granularity, and no order dependence for suites that
         * rewrite within one process (the glm15-6 memoization boundary).
         */
        $path = $this->root . '/provider.php';
        $source = "<?php\n// comment\n\$x = 'string';\n";
        file_put_contents($path, $source);

        $views = wp_connectors_file_code_views($path);
        $this->assertIsArray($views, 'A readable file yields the view triple.');
        $this->assertSame($source, $views['source'], 'The raw source rides along for statement slicing.');
        $this->assertSame(strlen($source), strlen($views['code']), 'The comment-stripped view is length-preserving.');
        $this->assertSame(strlen($source), strlen($views['masked']), 'The string-masked view is length-preserving.');
        $this->assertStringNotContainsString('comment', $views['code'], 'Comments are blanked in the code view.');
        $this->assertStringNotContainsString('string', $views['masked'], 'String contents are blanked in the masked view.');
        $this->assertStringContainsString("\$x", $views['masked'], 'Real code keeps its bytes in the masked view.');

        // Same content, same path: the served entry is the memoized one.
        $this->assertSame($views, wp_connectors_file_code_views($path), 'Same content reuses the memoized triple.');

        // A REWRITE re-tokenizes — the md5 half of the key.
        file_put_contents($path, "<?php\nuse Vendor\Package\Widget;\n");
        $rewritten = wp_connectors_file_code_views($path);
        $this->assertSame("<?php\nuse Vendor\Package\Widget;\n", $rewritten['source'], 'A rewritten file never serves the stale view.');

        // An unreadable file is null; the callers own that return.
        $dangling = $this->root . '/dangling.php';
        symlink('/nonexistent/target/for/wp-connectors-tests', $dangling);
        $this->assertNull(wp_connectors_file_code_views($dangling), 'The unreadable return is null, owned by the callers.');

        /*
         * Source pin: both consumers route through the one provider and
         * neither computes its own strip+mask pair anymore.
         */
        $gate = (string) file_get_contents(dirname(__DIR__) . '/bin/check-conventions.php');
        $this->assertStringContainsString('$views = wp_connectors_file_code_views(', $gate, 'The unused-import scan reads the shared views.');
        $this->assertSame(0, preg_match_all('/=\s*wp_connectors_mask_string_contents\(/', $gate), 'The scan computes no mask of its own.');

        $tools = (string) file_get_contents(dirname(__DIR__) . '/bin/lib/plugin-tools.php');
        $this->assertStringContainsString('$views = wp_connectors_file_code_views($path);', $tools, 'The self-containment driver reads the shared views.');
    }
}
