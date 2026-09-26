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


    public function testTheIdentifierGlueClassIsTheLabelOwnerNotTheLiteralLetters(): void
    {
        /*
         * R49-1 (driven fail-open — the glm48-6 rewrite's own
         * escaping accident, the R47-9 class one round later): the
         * rewrite embedded the constant NAME
         * WP_CONNECTORS_LABEL_BYTES inside the single-quoted pattern
         * string — single quotes do not interpolate, so the
         * identifier-glue lookbehind shrank to the literal letters
         * of the constant's own name, and every identifier-glued
         * call whose glue bytes fell outside those letters
         * laundered the gate ('mydefine(' php -l clean, executing
         * fatals, ZERO violations — build publishing and inspect
         * ACCEPTING a plugin that dies at load, the R40-3 class
         * wholesale). The round-40 pin stayed green only because
         * the '_' of its 'my_define' fixture is one of the
         * surviving letters — the pin now drives a letter-OUTSIDE
         * spelling beside it.
         */
        foreach (array('mydefine', 'tryDefine', 'a1define') as $glued) {
            $this->root .= '-glue-' . strtolower($glued);
            @mkdir($this->root, 0755, true);
            $refused = $this->drive($glued, "function {$glued}(\$n, \$v) {} {$glued}('%s', '1.2.3');");
            $this->assertStringContainsString('must define constant', implode("\n", $refused), "The '{$glued}' identifier-glued call binds nothing — the glue class is the LABEL owner's, never the constant name's literal letters (red at HEAD: laundered).");
        }
    }

    public function testANamespaceDecoyDefineBindsNothing(): void
    {
        /*
         * R48-2 (driven fail-open — the namespace decoy): an
         * unqualified define() call that runtime-resolves to
         * something other than the global define binds NO constant —
         * a same-namespace 'function define($n,$v){}' decoy (hoisted,
         * php -l clean, 'Undefined constant "E\MYPLUG_VERSION"' at
         * runtime, exit 255 driven) or an un-aliased 'use function
         * Foo\define;' import (the same fatal) — both driven at ZERO
         * violations across every gate arm while executing the plugin
         * fatals. The candidate consults the file's own namespace
         * ledger and a brace-safe flat view: the decoy function must
         * sit at the scope's own top level (a method named define
         * shadows nothing) and the SAME namespace scope as the call
         * (a foreign block's decoy never resolves here), hoisting
         * covered (the decoy may sit after the call). The benign
         * twins keep their bindings: a bare 'namespace E;
         * define(...)' rides the global fallback, an ALIASED import
         * binds only its alias, and the global self-import imports
         * the global itself.
         */
        $this->assertSame(array(), $this->drive('ns-nodecoy', "namespace E;\ndefine('%s', '1.2.3');"), 'A bare namespaced define rides the global fallback — the binding stands.');

        $this->root .= '-decoy';
        @mkdir($this->root, 0755, true);
        $decoy = $this->drive('decoy', "namespace E;\nfunction define(\$n, \$v) {}\ndefine('%s', '1.2.3');");
        $this->assertStringContainsString('must define constant', implode("\n", $decoy), 'A same-namespace decoy define() shadows the call — the constant is never bound (red at HEAD: green).');

        $this->root .= '-braced';
        @mkdir($this->root, 0755, true);
        $braced = $this->drive('braced', "namespace E {\n function define(\$n, \$v) {}\n define('%s', '1.2.3');\n}");
        $this->assertStringContainsString('must define constant', implode("\n", $braced), 'The braced-namespace decoy shadows the same way (the scope\'s top level is depth one inside the block).');

        $this->root .= '-method';
        @mkdir($this->root, 0755, true);
        $method = $this->drive('method', "namespace E;\nclass R { function define(\$n, \$v) {} }\ndefine('%s', '1.2.3');");
        $this->assertSame(array(), $method, 'A METHOD named define shadows nothing — the unqualified call still rides the fallback.');

        $base = sys_get_temp_dir() . '/wp-connectors-version-import-' . uniqid('', true);
        @mkdir($base . '/myplug', 0755, true);
        file_put_contents($base . '/myplug/myplug.php', "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\nuse function Foo\\define;\ndefine( 'MYPLUG_VERSION', '1.2.3' );\n");
        $import = wp_connectors_version_constant_violations($base . '/myplug', array('version' => '1.2.3'), array($base . '/myplug/myplug.php'));
        $this->assertStringContainsString('must define constant', implode("\n", $import), 'An un-aliased use-function import of a foreign define shadows the bare name (red at HEAD: green).');
        file_put_contents($base . '/myplug/myplug.php', "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\nuse function Foo\\define as d;\ndefine( 'MYPLUG_VERSION', '1.2.3' );\n");
        $aliased = wp_connectors_version_constant_violations($base . '/myplug', array('version' => '1.2.3'), array($base . '/myplug/myplug.php'));
        $this->assertSame(array(), $aliased, 'An ALIASED import binds only its alias — the bare define still binds the constant.');
        WpHarness::releaseScratch($base);
    }

    public function testEncodingParenAndHeredocValueSpellingsBind(): void
    {
        /*
         * R48-6 (driven false refusals — the value's legal spellings
         * one grammar over): the capture admitted only bare
         * un-parenthesized single/double-quoted literals, so an
         * executed constant equal to the header REFUSED at every
         * gate — the b/B-encoding prefix (a no-op spelling), one
         * parenthesizing around the concatenation, and a
         * heredoc/nowdoc value all minting the false 'must define
         * constant' refusal on php -l-clean working plugins. The
         * capture admits the prefix, the wrapping parens, and the
         * heredoc arm (the closer matched against its own label by
         * RELATIVE backreference — the one spelling shared by the
         * first piece and every concatenation arm); the pieces
         * decode IN ORDER (quoted pieces and heredoc blocks merged
         * by offset), the heredoc body through the ONE quote-style
         * owner's double-quote arm, the nowdoc body verbatim.
         */
        $base = sys_get_temp_dir() . '/wp-connectors-version-spell-' . uniqid('', true);
        foreach (array(
            'b-prefixed single' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\ndefine( 'MYPLUG_VERSION', b'1.2.3' );\n",
            'B-prefixed double' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\ndefine( 'MYPLUG_VERSION', B\"1.2.3\" );\n",
            'parenthesized concat' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\ndefine( 'MYPLUG_VERSION', ( '1.2' . '.3' ) );\n",
            'double parens' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\ndefine( 'MYPLUG_VERSION', ( ( '1.2' . '.3' ) ) );\n",
            'heredoc value' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\ndefine( 'MYPLUG_VERSION', <<<V\n1.2.3\nV\n );\n",
            'nowdoc value' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\ndefine( 'MYPLUG_VERSION', <<<'V'\n1.2.3\nV\n );\n",
            'double-quoted label' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\ndefine( 'MYPLUG_VERSION', <<<\"V\"\n1.2.3\nV\n );\n",
            'flexible closer indent' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\ndefine( 'MYPLUG_VERSION', <<<V\n    1.2.3\n    V\n );\n",
            'CR-only opener' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\ndefine( 'MYPLUG_VERSION', <<<V\r1.2.3\rV\n );\n",
            'quote then heredoc' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\ndefine( 'MYPLUG_VERSION', '1.2' . <<<V\n.3\nV\n );\n",
            'CRLF-terminated heredoc' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\r\ndefine( 'MYPLUG_VERSION', <<<V\r\n1.2.3\r\nV\r\n );\r\n",
            'CRLF flex-closer indent' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\r\ndefine( 'MYPLUG_VERSION', <<<V\r\n    1.2.3\r\n    V\r\n );\r\n",
            'double-quoted heredoc escapes' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\ndefine( 'MYPLUG_VERSION', <<<\"V\"\n\\x31.2.3\nV\n );\n",
            'parenthesized name' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\ndefine( ( 'MYPLUG_VERSION' ), '1.2.3' );\n",
            'concatenated name' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\ndefine( 'MYPLUG' . '_VERSION', '1.2.3' );\n",
            /*
             * R53-5 (driven): the b/B prefix rode the quoted arms but
             * not the heredoc arm — the R48-6 class one spelling over
             * (red at HEAD: the false must-define refusal on both).
             */
            'b-prefixed nowdoc' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\ndefine( 'MYPLUG_VERSION', b<<<'V'\n1.2.3\nV\n );\n",
            'B-prefixed heredoc' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\ndefine( 'MYPLUG_VERSION', B<<<\"V\"\n1.2.3\nV\n );\n",
        ) as $name => $source) {
            @mkdir($base . '/myplug', 0755, true);
            file_put_contents($base . '/myplug/myplug.php', $source);
            $this->assertSame(array(), wp_connectors_version_constant_violations($base . '/myplug', array('version' => '1.2.3'), array($base . '/myplug/myplug.php')), "The {$name} spelling binds — the executed constant equals the header (red at HEAD: the false must-define refusal).");
            unlink($base . '/myplug/myplug.php');
        }
        @mkdir($base . '/myplug', 0755, true);
        file_put_contents($base . '/myplug/myplug.php', "<?php\n/**\n * Plugin Name: My Plug\n * Version: 9.9\n */\ndefine( 'MYPLUG_VERSION', <<<V\n1.2.3\nV\n );\n");
        $mismatch = wp_connectors_version_constant_violations($base . '/myplug', array('version' => '9.9'), array($base . '/myplug/myplug.php'));
        $this->assertStringContainsString('does not match header Version', implode("\n", $mismatch), 'The heredoc mismatch twin keeps its own refusal with the DECODED body printed.');

        /*
         * t31-glm49-5 (R49-6, driven fail-open — the heredoc decode's
         * unanchored closer): a body line STARTING with the label
         * ('V9 body line') closed the decode at the first
         * line-start label while the collector's backtracking
         * spanned the full body — the truncated decode matching a
         * header the real value MISMATCHES, the version-mismatch
         * laundering the gate exists to catch. The closer anchors
         * against label-continuation bytes now; this fixture's
         * decoded value ('1.2.3' + newline + 'V9 body line')
         * mismatches the '1.2.3' header and says so.
         */
        file_put_contents($base . '/myplug/myplug.php', "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\ndefine( 'MYPLUG_VERSION', <<<V\n1.2.3\nV9 body line\nV\n );\n");
        $label_continuation = wp_connectors_version_constant_violations($base . '/myplug', array('version' => '1.2.3'), array($base . '/myplug/myplug.php'));
        $this->assertStringContainsString('V9 body line', implode("\n", $label_continuation), 'A body line starting with the label does not close the heredoc — the FULL decoded value mismatches its header (red at HEAD: binds clean on the truncated decode).');
        file_put_contents($base . '/myplug/myplug.php', "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\ndefine( 'MYPLUG' . '_OTHER', '1.2.3' );\n");
        $wrong_name = wp_connectors_version_constant_violations($base . '/myplug', array('version' => '1.2.3'), array($base . '/myplug/myplug.php'));
        $this->assertStringContainsString('must define constant', implode("\n", $wrong_name), 'A concatenated name spelling ANOTHER constant binds nothing for this one — the name decodes and compares.');
        WpHarness::releaseScratch($base);
    }

    public function testTheDecoyConsultsFiveFurtherGaps(): void
    {
        /*
         * R49-3+R49-4+R49-5+R49-10 (driven — the decoy consult's
         * five gaps, one restructure): the import arm never computed
         * the name an import BINDS (an alias of 'define' and the
         * PHP 7 group-use member both laundering); the declaration
         * arm missed the reference-returning 'function &define('
         * and every conditionally declared namespace-scope function;
         * the consult judged only unqualified calls (a qualified
         * '\Foo\define(...)' laundering while the global escape
         * '\define' beside a decoy was FALSELY refused); and the
         * import arm judged the stripped view alone ('use function'
         * text in string data reading as a real import). Everything
         * once-per-file derives once (the static content-keyed
         * cache), the declarations from the token stream with a
         * class-frame tracker, the import shadow over the flat view.
         */
        $b = chr(92);
        foreach (array(
            'aliased-to-define' => "use function Foo{$b}other as define;\ndefine('%s', '1.2.3');",
            'group-use member' => "use function Foo{$b}{ define };\ndefine('%s', '1.2.3');",
            'multi-member group' => "use function Foo{$b}{ other, define };\ndefine('%s', '1.2.3');",
        ) as $name => $pre) {
            $this->root .= '-d' . substr(md5($name), 0, 4);
            @mkdir($this->root, 0755, true);
            $v = $this->drive($name, $pre);
            $this->assertStringContainsString('must define constant', implode("\n", $v), "The {$name} import shadows the bare define — the bound name is 'define' (red at HEAD: laundered).");
        }
        foreach (array(
            'ref-return decoy' => "namespace E;\nfunction &define(\$n, \$v) { return null; }\ndefine('%s', '1.2.3');",
            'conditional decoy' => "namespace E;\nif (true) { function define(\$n, \$v) {} }\ndefine('%s', '1.2.3');",
        ) as $name => $body) {
            $this->root .= '-d' . substr(md5($name), 0, 4);
            @mkdir($this->root, 0755, true);
            $v = $this->drive($name, $body);
            $this->assertStringContainsString('must define constant', implode("\n", $v), "The {$name} declares the namespaced define — the unqualified call resolves to it (red at HEAD: laundered).");
        }

        $base = sys_get_temp_dir() . '/wp-connectors-version-r49-' . uniqid('', true);
        @mkdir($base . '/myplug', 0755, true);
        file_put_contents($base . '/myplug/myplug.php', "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\nnamespace Foo { function define(\$n, \$v) {} }\nnamespace E { " . '\Foo\define' . "( 'MYPLUG_VERSION', '1.2.3' ); }\n");
        $qualified = wp_connectors_version_constant_violations($base . '/myplug', array('version' => '1.2.3'), array($base . '/myplug/myplug.php'));
        $this->assertStringContainsString('must define constant', implode("\n", $qualified), 'A qualified foreign define call binds no constant at this file (red at HEAD: laundered).');
        file_put_contents($base . '/myplug/myplug.php', "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\nnamespace E;\nfunction define(\$n, \$v) {}\n" . '\define' . "( 'MYPLUG_VERSION', '1.2.3' );\n");
        $escape = wp_connectors_version_constant_violations($base . '/myplug', array('version' => '1.2.3'), array($base . '/myplug/myplug.php'));
        $this->assertSame(array(), $escape, 'The global escape beside a decoy binds the GLOBAL define at runtime — the false refusal dead (red at HEAD: refused).');
        file_put_contents($base . '/myplug/myplug.php', "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\n\$help = 'use function Foo\\define;';\ndefine( 'MYPLUG_VERSION', '1.2.3' );\n");
        $string_data = wp_connectors_version_constant_violations($base . '/myplug', array('version' => '1.2.3'), array($base . '/myplug/myplug.php'));
        $this->assertSame(array(), $string_data, "'use function' text living in string DATA is not an import — the plugin's real define binds (red at HEAD: the false refusal).");
        WpHarness::releaseScratch($base);
    }

    public function testTheNameCompareIsFullLengthAndCaseSensitive(): void
    {
        /*
         * R50-2 (driven fail-open — the round-49 name compare was a
         * case-insensitive PREFIX match): '$name_raw !== $constantName
         * && 0 !== substr_compare(..., true)' short-circuits to
         * BINDING whenever the decoded name case-insensitively shares
         * the constant's prefix — 'MYPLUG_VERSION2',
         * 'myplug_version_extra', and 'myplug_version' each satisfying
         * the must-define gate at zero violations while executing
         * fatals on the unbound constant (constants case-sensitive on
         * the floor). The compare is full-length equality.
         */
        foreach (array(
            'prefix-sharing' => "define( '%s2', '1.2.3' );",
            'case-variant suffix' => "define( '%s_extra', '1.2.3' );",
            'case-variant exact' => "define( strtolower('%s'), '1.2.3' );",
        ) as $name => $body) {
            $this->root .= '-n' . substr(md5($name), 0, 4);
            @mkdir($this->root, 0755, true);
            $v = $this->drive($name, $body);
            $this->assertStringContainsString('must define constant', implode("\n", $v), "The {$name} spelling names a DIFFERENT constant — the gate refuses (red at HEAD: binds clean).");
        }
    }

    public function testTheDecoyConsultsRound50Gaps(): void
    {
        /*
         * R50-3+R50-4+R50-5+R50-6+R50-7+R50-8+R50-12+R50-13 (all
         * driven at HEAD, php -l clean): the '::class' lookahead
         * looked the WRONG WAY (the tokenizer mints 'Foo::class'
         * with the '::' PRECEDING), every ordinary usage pushing a
         * phantom class frame and every later decoy skipped as a
         * method; a RELATIVE qualified callee ('Foo\define(')
         * armed no branch and bound while executing fatals; the
         * type-led mixed group use ('use Foo\{ function other as
         * define };') never computed a bound name; a function
         * declared inside a METHOD body is namespace-scoped once
         * the method executes but was dropped as a 'method'; two
         * braced blocks declaring the SAME namespace are one
         * runtime namespace but the offset-identity scope match
         * said otherwise; a comment between 'function' and the
         * name broke the declaration walk; the pair inside a use
         * statement minted a phantom declaration falsely refusing
         * the working self-import; and a 'use function' in braced
         * block A was file-global, falsely shadowing a bare define
         * in block B.
         */
        $b = chr(92);
        foreach (array(
            '::class-then-decoy' => "namespace E;\n\$n = Foo::class;\nfunction define(\$n, \$v) {}\ndefine('%s', '1.2.3');",
            'relative-qualified' => null,
            'function-in-method' => "namespace E;\nclass Boot { public function boot() { function define(\$n, \$v) {} } }\ndefine('%s', '1.2.3');",
            'same-name-blocks' => "namespace E { function define(\$n, \$v) {} }\nnamespace E { define('%s', '1.2.3'); }",
            'comment-in-decl' => "namespace E;\nfunction /* c */ define(\$n, \$v) {}\ndefine('%s', '1.2.3');",
        ) as $name => $body) {
            if (null === $body) {
                continue;
            }
            $this->root .= '-e' . substr(md5($name), 0, 4);
            @mkdir($this->root, 0755, true);
            $v = $this->drive($name, $body);
            $this->assertStringContainsString('must define constant', implode("\n", $v), "The {$name} shape launders no more — the decoy resolves (red at HEAD: binds clean).");
        }

        $base = sys_get_temp_dir() . '/wp-connectors-version-r50-' . uniqid('', true);
        @mkdir($base . '/myplug', 0755, true);
        foreach (array(
            'relative-qualified' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\nnamespace E;\nFoo{$b}define( 'MYPLUG_VERSION', '1.2.3' );\n",
            'type-led-group-use' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\nuse Foo{$b}{ function other as define };\ndefine( 'MYPLUG_VERSION', '1.2.3' );\n",
            'type-led-group-plain' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\nuse Foo{$b}{ function define };\ndefine( 'MYPLUG_VERSION', '1.2.3' );\n",
        ) as $name => $source) {
            file_put_contents($base . '/myplug/myplug.php', $source);
            $v = wp_connectors_version_constant_violations($base . '/myplug', array('version' => '1.2.3'), array($base . '/myplug/myplug.php'));
            $this->assertStringContainsString('must define constant', implode("\n", $v), "The {$name} shape launders no more (red at HEAD: binds clean).");
        }
        foreach (array(
            'use-fn-define-in-ns' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\nnamespace E;\nuse function define;\ndefine( 'MYPLUG_VERSION', '1.2.3' );\n",
            'group-alias-in-ns' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\nnamespace E;\nuse Foo{$b}{ function define as d2 };\ndefine( 'MYPLUG_VERSION', '1.2.3' );\n",
            'import-block-a-define-b' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\nnamespace A { use function Foo{$b}define; }\nnamespace B { define( 'MYPLUG_VERSION', '1.2.3' ); }\n",
            'const-kind-group' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\nuse Foo{$b}{ const define };\ndefine( 'MYPLUG_VERSION', '1.2.3' );\n",
            'class-kind-plain-use' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\nuse Foo{$b}define;\ndefine( 'MYPLUG_VERSION', '1.2.3' );\n",
        ) as $name => $source) {
            file_put_contents($base . '/myplug/myplug.php', $source);
            $this->assertSame(array(), wp_connectors_version_constant_violations($base . '/myplug', array('version' => '1.2.3'), array($base . '/myplug/myplug.php')), "The {$name} shape binds — the false refusal dead (red at HEAD: must-define).");
        }
        WpHarness::releaseScratch($base);
    }

    public function testTheDecoyConsultsRound51Gaps(): void
    {
        /*
         * R51-5+R51-6+R51-7 (all driven at HEAD, php -l clean): a
         * trait adaptation aliasing a method as 'define' ('use T {
         * m as define; }') group-parsed as an import binding the
         * name — the phantom shadow falsely refusing a working
         * plugin (the group-use brace rides a NAMESPACE SEPARATOR,
         * a trait adaptation's a class NAME); imports are
         * BLOCK-scoped, not name-scoped — a shadowing import in one
         * braced block applying to a call in ANOTHER braced block
         * declaring the SAME namespace (the R50-13 intent spelled
         * per-arm: the DECLARATION match stays name-based, functions
         * name-scoped across same-name blocks per R50-7); and the
         * consult's two hand-rolled trivia walks were
         * whitespace-only — a comment between '::' and 'class' or
         * between the declaration name and its '(' breaking them,
         * the walks riding the ONE owner now.
         */
        $b = chr(92);
        $base = sys_get_temp_dir() . '/wp-connectors-version-r51-' . uniqid('', true);
        @mkdir($base . '/myplug', 0755, true);
        foreach (array(
            'trait-adaptation-alias' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\ntrait T { public function m() { return 1; } }\nclass C { use T { m as define; } }\ndefine( 'MYPLUG_VERSION', '1.2.3' );\n",
            'same-name-blocks-import' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\nnamespace E { use function Foo{$b}define; }\nnamespace E { define( 'MYPLUG_VERSION', '1.2.3' ); }\n",
            'two-unbraced-regions' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\nnamespace E;\nuse function Foo{$b}define;\nnamespace E;\ndefine( 'MYPLUG_VERSION', '1.2.3' );\n",
        ) as $name => $source) {
            file_put_contents($base . '/myplug/myplug.php', $source);
            $this->assertSame(array(), wp_connectors_version_constant_violations($base . '/myplug', array('version' => '1.2.3'), array($base . '/myplug/myplug.php')), "The {$name} shape binds — the false refusal dead (red at HEAD: must-define).");
        }
        foreach (array(
            'comment-in-::class' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\nnamespace E;\n\$n = Foo:: /* c */ class;\nif (true) { function define(\$n, \$v) {} }\ndefine( 'MYPLUG_VERSION', '1.2.3' );\n",
            'comment-before-decl-paren' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\nnamespace E;\nfunction define /* c */ (\$n, \$v) {}\ndefine( 'MYPLUG_VERSION', '1.2.3' );\n",
        ) as $name => $source) {
            file_put_contents($base . '/myplug/myplug.php', $source);
            $v = wp_connectors_version_constant_violations($base . '/myplug', array('version' => '1.2.3'), array($base . '/myplug/myplug.php'));
            $this->assertStringContainsString('must define constant', implode("\n", $v), "The {$name} shape launders no more — the ONE-owner walk skips the comment (red at HEAD: binds clean).");
        }
        WpHarness::releaseScratch($base);
    }

    public function testTheDecoyConsultsRound52Gaps(): void
    {
        /*
         * R52-2+R52-4+R52-5 (all driven at HEAD, php -l clean): the
         * trait-adaptation fence read the byte IMMEDIATELY before
         * '{' — a group use spelling its trivia there ('use Foo\ {
         * function define };') was skipped as an adaptation and the
         * shadow never charged (fail-open); the declaration walk's
         * use-fence closed at the FIRST ';' — a trait adaptation's
         * inner ';' ended the region early, its '{' consumed
         * uncounted while the matching '}' decremented the walk's
         * brace depth, the class frame filtered out mid-body and a
         * later method named 'define' recorded as a namespace-scope
         * declaration (false refusal — the fence counts braces now,
         * the nested-declaration control beside it keeping its
         * decoy verdict through the honest depth); and the
         * null-to-null import scope over-applied to ANONYMOUS blocks
         * — 'namespace { use …; } namespace { define(…); }' both
         * resolving to the null global scope, block 1's import
         * shadowing block 2's call (each shadow records its REGION
         * END — the enclosing block's close or a following
         * namespace declaration start — the single-block control
         * keeping its refusal).
         */
        $b = chr(92);
        $base = sys_get_temp_dir() . '/wp-connectors-version-r52-' . uniqid('', true);
        @mkdir($base . '/myplug', 0755, true);
        foreach (array(
            'ws-group-use-launder' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\nuse Foo{$b} { function define };\ndefine( 'MYPLUG_VERSION', '1.2.3' );\n",
            'nested-decl-after-adaptation' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\nnamespace E;\ntrait T { public function m() { return 1; } }\nclass Boot {\n    use T { m as define; }\n    public function boot() { function define( \$n, \$v ) { return true; } }\n}\ndefine( 'MYPLUG_VERSION', '1.2.3' );\n",
            'same-anonymous-block' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\nnamespace { use function Foo{$b}define; define( 'MYPLUG_VERSION', '1.2.3' ); }\n",
        ) as $name => $source) {
            file_put_contents($base . '/myplug/myplug.php', $source);
            $v = wp_connectors_version_constant_violations($base . '/myplug', array('version' => '1.2.3'), array($base . '/myplug/myplug.php'));
            $this->assertStringContainsString('must define constant', implode("\n", $v), "The {$name} shape launders no more (red at HEAD: binds clean).");
        }
        foreach (array(
            'method-define-after-adaptation' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\nnamespace E;\ntrait T { public function m() { return 1; } }\nclass Registry {\n    use T { m as rename_me; }\n    public function define( \$n, \$v ) { return true; }\n}\ndefine( 'MYPLUG_VERSION', '1.2.3' );\n",
            'two-anonymous-blocks' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\nnamespace { use function Foo{$b}define; }\nnamespace { define( 'MYPLUG_VERSION', '1.2.3' ); }\n",
        ) as $name => $source) {
            file_put_contents($base . '/myplug/myplug.php', $source);
            $this->assertSame(array(), wp_connectors_version_constant_violations($base . '/myplug', array('version' => '1.2.3'), array($base . '/myplug/myplug.php')), "The {$name} shape binds — the false refusal dead (red at HEAD: must-define).");
        }
        WpHarness::releaseScratch($base);
    }

    public function testTheDecoyConsultsRound53Gaps(): void
    {
        /*
         * R53-2+R53-3+R53-7+R53-9 (all driven at HEAD, php -l clean
         * unless noted): the import-shadow regex tail swallowed a
         * CLOSE TAG ('use function Foo\define ?>' gluing into the
         * next block, no shadow minted, the broken plugin
         * green-lit); the declaration walk counted NON-CODE braces
         * (the '${x}' interpolation's plain '}' closer with no
         * counted opener — a method named define after it recorded
         * as a namespace declaration, falsely refusing; the
         * '?>}<?php' inline-HTML brace re-opening the R50-6
         * laundering direction, a nested decoy skipped as a
         * method); the round-52 region-end 'namespace keyword' arm
         * was DEAD AS SPELLED (stripos with a nonzero offset
         * answers the ABSOLUTE position — '0 ===' never held) and
         * its constructible shapes are engine fatals anyway (PHP
         * refuses mixing braced and unbraced declarations), deleted
         * rather than repaired into untested live code; and the
         * backward qualification walk stopped at the first
         * non-label byte, so the whitespace-interrupted qualified
         * callee 'Foo \define(' answered the GLOBAL escape for a
         * foreign name (both trivia spellings parse errors — the
         * pre-lint totality window, the walk bridging the
         * separator-adjacent trivia now).
         */
        $b = chr(92);
        $base = sys_get_temp_dir() . '/wp-connectors-version-r53-' . uniqid('', true);
        @mkdir($base . '/myplug', 0755, true);
        foreach (array(
            'close-tag-import' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\nuse function Foo{$b}define ?>\n<?php\ndefine( 'MYPLUG_VERSION', '1.2.3' );\n",
            'html-brace-launder' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\nnamespace E;\nclass Boot {\n    public function boot() {\n?>}<?php\n        function define( \$n, \$v ) { return true; }\n    }\n}\ndefine( 'MYPLUG_VERSION', '1.2.3' );\n",
        ) as $name => $source) {
            file_put_contents($base . '/myplug/myplug.php', $source);
            $v = wp_connectors_version_constant_violations($base . '/myplug', array('version' => '1.2.3'), array($base . '/myplug/myplug.php'));
            $this->assertStringContainsString('must define constant', implode("\n", $v), "The {$name} shape launders no more (red at HEAD: binds clean).");
        }
        foreach (array(
            /*
             * The interpolation FALSE-REFUSAL leg (R53-3's (a) half):
             * a method named 'define' after an interpolation-bearing
             * sibling method stays a METHOD — the '{$x}' closer never
             * corrupts the depth — and the working bare define binds
             * (red at HEAD: the corrupted walk recorded the method as
             * a namespace-scope declaration and refused).
             */
            'interpolation-then-method' => "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\nnamespace E;\nclass C {\n    function helper( \$x ) { \$s = \"a{\$x}b\"; }\n    function define( \$k, \$v ) { return true; }\n}\ndefine( 'MYPLUG_VERSION', '1.2.3' );\n",
        ) as $name => $source) {
            file_put_contents($base . '/myplug/myplug.php', $source);
            $this->assertSame(array(), wp_connectors_version_constant_violations($base . '/myplug', array('version' => '1.2.3'), array($base . '/myplug/myplug.php')), "The {$name} shape binds — the false refusal dead (red at HEAD: must-define).");
        }
        // The consult-level leg: the LEGAL keyword-operand global escape keeps binding — the safety
        // shape that refuted R53-9's walk-start trivia bridge (both interrupted-qualified spellings
        // are parse errors the lint gate owns; a bridge here refuses this working spelling).
        $keyword_operand = "<?php\nreturn {$b}define( 'X', '1' );\n";
        $this->assertFalse(wp_connectors_define_call_resolves_to_decoy($keyword_operand, strpos($keyword_operand, 'define(')), 'The keyword-operand global escape keeps its unqualified reading — the walk-start trivia bridge refuted on exactly this legal shape.');
        /*
         * R55-6 (driven): the RELATIVE spelling of the global escape
         * — 'namespace\define(…)' at global scope is php -l clean,
         * executing binds the constant, and the walk consumed the
         * operator prefix as a foreign qualifier, minting the false
         * must-define refusal on a working plugin. Inside a declared
         * namespace the same spelling resolves to THAT namespace's
         * define — foreign, the refusal standing.
         */
        $relative_global = "<?php\nnamespace{$b}define( 'MYPLUG_VERSION', '1.2.3' );\n";
        file_put_contents($base . '/myplug/myplug.php', "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\n" . $relative_global);
        $this->assertSame(array(), wp_connectors_version_constant_violations($base . '/myplug', array('version' => '1.2.3'), array($base . '/myplug/myplug.php')), 'The relative global escape binds — the operator prefix is not a foreign qualifier at global scope (red at HEAD: must-define).');
        $relative_namespaced = "<?php\nnamespace E;\nnamespace{$b}define( 'MYPLUG_VERSION', '1.2.3' );\n";
        file_put_contents($base . '/myplug/myplug.php', "<?php\n/**\n * Plugin Name: My Plug\n * Version: 1.2.3\n */\n" . $relative_namespaced);
        $this->assertStringContainsString('must define constant', implode("\n", wp_connectors_version_constant_violations($base . '/myplug', array('version' => '1.2.3'), array($base . '/myplug/myplug.php'))), 'Inside a declared namespace the relative spelling resolves to THAT namespace — foreign, the refusal standing.');
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