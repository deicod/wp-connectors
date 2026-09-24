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
            "<?php\n/**\n * Plugin Name: Test Plugin\n * Version: 1.2.3\n */\n" . vsprintf($body, array_fill(0, substr_count($body, '%s'), $constant)) . "\n"
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

    public function testTheGuardedIdiomAndSameLineSpellingsBind(): void
    {
        /*
         * R39-4 (driven false refusals — round 37's anchor STILL
         * over-narrowed after round 38's widening): four more
         * php -l-clean spellings master accepted refused at all
         * three gates — the canonical WordPress GUARDED IDIOM
         * "if ( ! defined('X') ) define(...)" (check-conventions
         * exit 1 end-to-end on the canonical spelling, driven), a
         * define after another statement on the SAME LINE, the
         * case-insensitive open tag '<?PHP', and the composable
         * '<?php \define(...)'. The anchored shape was the problem:
         * the probe returns to an UNANCHORED find over the
         * comment-stripped view, the TWO-VIEW judge (the masked
         * re-confirmation) being the laundering guard that makes
         * anchoring unnecessary — a comment or heredoc define never
         * survives the second view.
         */
        $this->assertSame(array(), $this->drive('guarded-braced', "if ( ! defined( '%s' ) ) { define( '%s', '1.2.3' ); }"), 'The canonical WordPress guarded idiom binds (red at HEAD: the false refusal).');
        $this->root .= '-unbraced';
        @mkdir($this->root, 0755, true);
        $this->assertSame(array(), $this->drive('guarded-unbraced', "if ( ! defined( '%s' ) ) define( '%s', '1.2.3' );"), 'The unbraced guarded idiom binds.');
        $this->root .= '-sameline';
        @mkdir($this->root, 0755, true);
        $this->assertSame(array(), $this->drive('same-line', "\$ok = true; define('%s', '1.2.3');"), 'A define after another statement on the same line binds.');

        $base = sys_get_temp_dir() . '/wp-connectors-version-r39tag-' . uniqid('', true);
        @mkdir($base . '/myplug', 0755, true);
        $base = $base . '/myplug';
        foreach (array('upper-tag' => '<?PHP ', 'fq-tag' => '<?php \\') as $name => $tag) {
            file_put_contents(
                $base . '/myplug.php',
                $tag . "define( \"MYPLUG_VERSION\", \"1.2.3\" );\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\n"
            );
            $this->assertSame(array(), wp_connectors_version_constant_violations($base, array('version' => '1.2.3'), array($base . '/myplug.php')), sprintf('The %s spelling binds.', $name));
        }
        WpHarness::releaseScratch(dirname($base));
    }

    public function testTheOpenTagLineAndFullyQualifiedSpellingsBind(): void
    {
        /*
         * R38-6 (driven false refusals — round 37's anchor
         * over-narrowed): '^[ \t]*' refused two legal spellings
         * master's unanchored probe had accepted — the define as
         * the FIRST statement on the OPEN-TAG LINE ('<?php
         * define(...)', the minimal plugin's own spelling, driven:
         * 'must define constant' at HEAD with no violation at the
         * pre-round-37 baseline, check-conventions exiting 1 on a
         * well-formed plugin) and the fully-qualified global call
         * '\define(...)'. The anchor admits the open-tag prefix
         * and the leading separator beside whitespace.
         */
        $base = sys_get_temp_dir() . '/wp-connectors-version-tagline-' . uniqid('', true);
        @mkdir($base . '/myplug', 0755, true);
        $base = $base . '/myplug';
        file_put_contents(
            $base . '/myplug.php',
            "<?php define( \"MYPLUG_VERSION\", \"1.2.3\" );\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\n"
        );
        $tagLine = wp_connectors_version_constant_violations($base, array('version' => '1.2.3'), array($base . '/myplug.php'));
        $this->assertSame(array(), $tagLine, 'The define as the first statement on the open-tag line binds — the minimal plugin\'s own spelling (red at HEAD: the false must-define-constant refusal).');
        WpHarness::releaseScratch(dirname($base));

        $this->root .= '-fq';
        @mkdir($this->root, 0755, true);
        $fq = $this->drive('fq', "\\define('%s', '1.2.3');");
        $this->assertSame(array(), $fq, 'The fully-qualified \'\define(...)\' call binds — the anchor admits the leading separator (red at HEAD: the false refusal).');
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

    public function testTheThreeArgumentDefineSpellingBinds(): void
    {
        /*
         * R41-5 (driven false refusal — the value-argument tail one
         * byte short): the pattern required ')' IMMEDIATELY after
         * the second quoted literal, so the three-argument spelling
         * — define('X', '1.2.3', false), the documented
         * case-insensitivity switch, php -l clean and executing
         * diagnostic-free — answered 'must define constant' at HEAD
         * on a well-formed plugin. The tail tolerates the optional
         * third argument; the guarded idiom and the case variant
         * ride the widened tail unchanged, and the VALUE capture
         * still the second literal (the mismatch twin keeps its own
         * refusal beside them).
         */
        $threeArg = $this->drive('threearg', "define('%s', '1.2.3', false);");
        $this->assertSame(array(), $threeArg, 'The three-argument define binds — the case-insensitivity switch is not a missing constant (red at HEAD: the false must-define-constant refusal).');

        $this->root .= '-guarded';
        @mkdir($this->root, 0755, true);
        $guarded = $this->drive('guarded', "if ( ! defined('%s') ) define('%s', '1.2.3', false);");
        $this->assertSame(array(), $guarded, 'The guarded idiom carries the third argument clean — the R39-4 spelling rides the widened tail.');

        $this->root .= '-upper3';
        @mkdir($this->root, 0755, true);
        $upper = $this->drive('upper3', "DEFINE('%s', '1.2.3', FALSE);");
        $this->assertSame(array(), $upper, 'The case-insensitive keyword and FALSE argument bind — PHP folds both lexically.');

        $this->root .= '-mismatch3';
        @mkdir($this->root, 0755, true);
        $mismatch = $this->drive('mismatch3', "define('%s', '9.9.9', false);");
        $this->assertStringContainsString('does not match header Version', implode("\n", $mismatch), 'The three-argument mismatch twin keeps its own refusal — the value capture still the SECOND literal.');
    }

    public function testMemberStaticAndNullsafeDefineCallsBindNothing(): void
    {
        /*
         * R45-3 (driven — the member/static/nullsafe define
         * laundering): the probe's left boundary refused only label
         * bytes, so '$registry->define('MYPLUG_VERSION', ...)' (a
         * decoy class's method, php -l clean) satisfied the
         * must-define arm with no constant defined — the plugin
         * fataling at runtime on the bare constant reference. The
         * class refuses the ':' '>' '$' and namespace-separator glue
         * bytes (R44-4's loop-detector doctrine at this seat).
         */
        $member = $this->drive('member', "<?php\nclass Registry { public function define(\$n, \$v) { return true; } }\n\$registry = new Registry();\n\$registry->define( '%s', '1.2.3' );\n");
        $this->assertStringContainsString('must define constant', implode("\n", $member), 'A member-call define binds nothing — the method is not the construct (red at HEAD: clean).');

        $this->root .= '-static';
        @mkdir($this->root, 0755, true);
        $static = $this->drive('static', "<?php\nclass Registry { public static function define(\$n, \$v) { return true; } }\nRegistry::define( '%s', '1.2.3' );\n");
        $this->assertStringContainsString('must define constant', implode("\n", $static), 'A static define binds nothing (red at HEAD: clean).');

        $this->root .= '-nullsafe';
        @mkdir($this->root, 0755, true);
        $nullsafe = $this->drive('nullsafe', "<?php\nclass Registry { public function define(\$n, \$v) { return true; } }\n\$registry = new Registry();\n\$registry?->define( '%s', '1.2.3' );\n");
        $this->assertStringContainsString('must define constant', implode("\n", $nullsafe), 'A nullsafe define binds nothing (red at HEAD: clean).');

        $this->root .= '-plain';
        @mkdir($this->root, 0755, true);
        $plain = $this->drive('plain', "define('%s', '1.2.3');");
        $this->assertSame(array(), $plain, 'The plain spelling keeps its binding.');
    }


    public function testSpacedGlueLaunderingRefusesAndQuoteBearingValuesBind(): void
    {
        /*
         * R46-2 (driven — the define probe's third seat was left on
         * its spacing-blind lookbehind): one space around '->'/'::'
         * laundered the gate — '$r -> define('MYPLUG_VERSION', ...)'
         * answering 0 violations where the tight spelling refuses.
         * The candidate loop consults the spacing-proof helper (the
         * $allow_separator flag admitting the legal fully-qualified
         * spelling).
         *
         * R46-4 (driven, the sweep's own drive): the value capture
         * was quote-blind — a version literal carrying the other
         * quote kind ('1.2'3' inside double quotes) or an escaped
         * quote failed the pattern and minted the false
         * 'must define constant' refusal. The value rides the
         * per-quote alternation, decoded through the ONE
         * quote-style-aware owner.
         */
        $spaced = $this->drive('spaced', "<?php\nclass Registry { public function define(\$n, \$v) { return true; } }\n\$r = new Registry();\n\$r -> define( '%s', '1.2.3' );\n");
        $this->assertStringContainsString('must define constant', implode("\n", $spaced), 'One space around the arrow launders nothing — the spacing-proof helper owns this seat too (red at HEAD: clean).');

        $this->root .= '-static';
        @mkdir($this->root, 0755, true);
        $static = $this->drive('static', "<?php\nclass Registry { public static function define(\$n, \$v) { return true; } }\nRegistry :: define( '%s', '1.2.3' );\n");
        $this->assertStringContainsString('must define constant', implode("\n", $static), 'The spaced static spelling launders nothing either (red at HEAD: clean).');

        $this->root .= '-fq';
        @mkdir($this->root, 0755, true);
        $fq = $this->drive('fq2', "\\define('%s', '1.2.3');");
        $this->assertSame(array(), $fq, 'The fully-qualified spelling keeps its binding — the separator flag admitting the legal form.');
    }

    public function testQuoteBearingVersionValuesBind(): void
    {
        /*
         * R46-4's benign half (driven at HEAD before the fix: the
         * false 'must define constant' refusal): the other-quote
         * literal and the escaped-quote literal, both php -l clean
         * and executing fine, with matching headers.
         */
        $base = sys_get_temp_dir() . '/wp-connectors-version-quote-' . uniqid('', true);
        @mkdir($base . '/myplug', 0755, true);
        file_put_contents(
            $base . '/myplug/myplug.php',
            "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2'3\n */\ndefine( \"MYPLUG_VERSION\", \"1.2'3\" );\n"
        );
        $other = wp_connectors_version_constant_violations($base . '/myplug', array('version' => "1.2'3"), array($base . '/myplug/myplug.php'));
        $this->assertSame(array(), $other, 'A version literal carrying the other quote kind inside double quotes binds (red at HEAD: the false refusal).');
        WpHarness::releaseScratch($base);

        $base = sys_get_temp_dir() . '/wp-connectors-version-esc-' . uniqid('', true);
        @mkdir($base . '/myplug', 0755, true);
        file_put_contents(
            $base . '/myplug/myplug.php',
            "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2'3\n */\ndefine( 'MYPLUG_VERSION', '1.2\\'3' );\n"
        );
        $escaped = wp_connectors_version_constant_violations($base . '/myplug', array('version' => "1.2'3"), array($base . '/myplug/myplug.php'));
        $this->assertSame(array(), $escaped, 'The escaped-quote literal decodes through the quote-style owner and binds (red at HEAD: the false refusal).');
        WpHarness::releaseScratch($base);
    }


    public function testTightGlueAndCommentGlueDefineShapes(): void
    {
        /*
         * R47-4 [driven false refusals]: the define collector own
         * lookbehind pre-filtered the byte-pair helper - tight
         * 'case 1:define(...)', the elvis colon, and the arrow-tail
         * spelling minting false must-define refusals where their
         * spaced twins bind. R47-2 [driven fail-open]: the position
         * consult walked the MASKED view where comments ride
         * verbatim, a comment between the glue and the keyword
         * stopping the walk on the comment bytes - the member call
         * carrying an inline block laundering the gate. The
         * collector collects, the helper judges on the STRIPPED
         * view.
         */
        $this->assertSame(array(), $this->drive('tightcase', "switch(1){case 1:define('%s','1.2.3');}"), 'The tight case-label define binds (red at HEAD: the false refusal).');

        $this->root .= '-elvis';
        @mkdir($this->root, 0755, true);
        $this->assertSame(array(), $this->drive('tightelvis', "\$g = true;\n\$g ?:define('%s','1.2.3');"), 'The tight elvis define binds (red at HEAD: the false refusal).');

        $this->root .= '-arrow';
        @mkdir($this->root, 0755, true);
        $this->assertSame(array(), $this->drive('tightarrow', "\$m = array('k' =>define('%s','1.2.3'));"), 'The tight arrow-tail define binds (red at HEAD: the false refusal).');

        $this->root .= '-cglue';
        @mkdir($this->root, 0755, true);
        $comment_glue = $this->drive('cglue', "<?php\nclass Registry { public function define(\$n, \$v) { return true; } }\n\$r = new Registry();\n\$r->/*c*/define( '%s', '1.2.3' );\n");
        $this->assertStringContainsString('must define constant', implode("\n", $comment_glue), 'A comment between the glue and the keyword launders nothing - the consult walks the stripped view (red at HEAD: clean).');
    }

    public function testEscapedAndConcatenatedValuesBind(): void
    {
        /*
         * R47-3 (driven false refusal): the ordered alternation
         * legacy quote-blind arm sat FIRST, so an escape-bearing
         * value with no quote byte was captured RAW and the header
         * compare ran on escaped source bytes. R47-5 (driven): a
         * CONCATENATION of literals never matched at all - the
         * R38-6/R41-5 class one spelling over. The value rides the
         * OWNER grammar in one whole-expression capture, the pieces
         * decoding through the ONE quote-style owner and joining.
         */
        $base = sys_get_temp_dir() . '/wp-connectors-version-hex-' . uniqid('', true);
        @mkdir($base . '/myplug', 0755, true);
        file_put_contents($base . '/myplug/myplug.php', "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2\n */\ndefine( 'MYPLUG_VERSION', \"\\x31.\\x32\" );\n");
        $hex = wp_connectors_version_constant_violations($base . '/myplug', array('version' => '1.2'), array($base . '/myplug/myplug.php'));
        $this->assertSame(array(), $hex, 'The hex-escaped value decodes and binds (red at HEAD: the escaped-bytes mismatch refusal).');
        WpHarness::releaseScratch($base);

        $base = sys_get_temp_dir() . '/wp-connectors-version-cat-' . uniqid('', true);
        @mkdir($base . '/myplug', 0755, true);
        file_put_contents($base . '/myplug/myplug.php', "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\ndefine( 'MYPLUG_VERSION', '1.2' . '.3' );\n");
        $concat = wp_connectors_version_constant_violations($base . '/myplug', array('version' => '1.2.3'), array($base . '/myplug/myplug.php'));
        $this->assertSame(array(), $concat, 'The concatenated value binds - the pieces decode and join (red at HEAD: the false must-define refusal).');
        WpHarness::releaseScratch($base);

        $base = sys_get_temp_dir() . '/wp-connectors-version-catmm-' . uniqid('', true);
        @mkdir($base . '/myplug', 0755, true);
        file_put_contents($base . '/myplug/myplug.php', "<?php\n/**\n * Plugin Name: My Plug\n * Version: 9.9\n */\ndefine( 'MYPLUG_VERSION', '1.2' . '.3' );\n");
        $mismatch = wp_connectors_version_constant_violations($base . '/myplug', array('version' => '9.9'), array($base . '/myplug/myplug.php'));
        $this->assertStringContainsString('does not match header Version', implode("\n", $mismatch), 'The concatenated mismatch twin keeps its own refusal with the DECODED value.');
        WpHarness::releaseScratch($base);
    }
}