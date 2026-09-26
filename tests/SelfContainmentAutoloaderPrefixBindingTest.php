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

    public function testEveryOperandChannelFlagsAndProseWordsDoNot(): void
    {
        /*
         * R40-2+R40-5 (driven both directions — round 39's operand
         * probe's own two gaps): (1) the probe judged only
         * require/include statements, so a vendor reference riding
         * ANY OTHER operand channel — eval+file_get_contents,
         * readfile, shell_exec, all php -l clean — turned invisible
         * where master flagged; the channel set widens to the
         * file/exec call family. (2) The keyword arm had no LEFT
         * boundary and ran over string-bearing code, so the WORDS
         * 'require'/'include' in benign prose strings and
         * '$include' variable names (the R37-6 class regrown at a
         * third pattern) FALSE-FLAGGED. The keyword arm carries the
         * label-class lookbehind and every candidate's keyword is
         * re-confirmed on the MASKED view (prose blanks there).
         */
        $canonical = "<?php\n\$prefix = 'Deicod\\\\WpConnectors\\\\Zai\\\\';\nspl_autoload_register(function (\$class) use (\$prefix) { \$path = __DIR__ . '/' . str_replace('\\\\', '/', substr(\$class, strlen(\$prefix))) . '.php'; require_once \$path; });\n";
        $flag_shapes = array(
            'require vendor' => "require_once __DIR__ . '/vendor/pkg/lib.php';",
            'eval+fgc vendor' => 'eval( file_get_contents( __DIR__ . "/vendor/pkg/lib.php" ) );',
            'readfile vendor' => 'readfile( __DIR__ . "/vendor/x.php" );',
            'shell_exec vendor' => 'shell_exec( "cat vendor/build.sh" );',
        );
        foreach ($flag_shapes as $name => $leg) {
            $violations = $this->autoloadWith($canonical . $leg . "\n");
            $this->assertStringContainsString('must not reference composer or vendor', implode("\n", $violations), "The {$name} operand channel flags — an operand path is never prose.");
            // 8 digest chars: the chained per-shape roots once crossed
            // NAME_MAX at full md5 width (glm42-4's three new rows pushed
            // the 11-shape chain past 255 bytes — driven 'File name too long').
            $this->base .= '-n' . substr(md5($name), 0, 8);
            @mkdir($this->base . '/zai/src', 0755, true);
        }

        $clean_shapes = array(
            'prose require words' => "\$why = 'self-contained: must not require composer or any vendor tree';",
            'dollar-include variable' => '$include = "vendor/nothing.php";',
            /*
             * R42-6 (driven false flag — the probe's boundary one class
             * short): the keyword arm's lookbehind spelled PCRE's ASCII
             * '\w' while a LEGAL label byte glued to the keyword still
             * started a match — a php -l-clean autoloader calling the
             * user helper 'äfile_get_contents(...)' over an
             * assets/vendor-notes.txt path false-flagged as its sole
             * violation (red at HEAD). Both edges ride the LABEL byte
             * class now: a call must neither start nor continue a name
             * over any byte a label admits.
             */
            'high-byte glued helper' => "\xC3\xA4file_get_contents(__DIR__ . \"/assets/vendor-notes.txt\");",
            'right-edge high byte' => "eval\xFC(__DIR__ . \"/assets/x.php\");",
            'ASCII glued control' => 'my_file_get_contents(__DIR__ . "/assets/vendor-notes.txt");',
            /*
             * R55-8 (driven): the gate's bare stripos consults — the
             * masked CODE bytes, the operand statement text, and the
             * resolved values — refused a WORKING autoloader whose
             * only 'vendor' bytes rode the variable name
             * $vendor_dir. Every consult matches the needle as a
             * whole word over the label-byte and '$' boundaries.
             */
            'vendor-named variable' => '$vendor_dir = __DIR__ . "/lib";',
            'vendor-named variable used' => '$vendor_dir = __DIR__ . "/lib"; require $vendor_dir . "/x.php";',
            'plain valid' => '',
        );
        foreach ($clean_shapes as $name => $leg) {
            $violations = $this->autoloadWith($canonical . $leg . "\n");
            $this->assertStringNotContainsString('must not reference composer or vendor', implode("\n", $violations), "The {$name} shape stays clean — prose words and variables never arm the operand probe (red at HEAD for the prose/variable shapes: the false flag).");
            // 8 digest chars: the chained per-shape roots once crossed
            // NAME_MAX at full md5 width (glm42-4's three new rows pushed
            // the 11-shape chain past 255 bytes — driven 'File name too long').
            $this->base .= '-n' . substr(md5($name), 0, 8);
            @mkdir($this->base . '/zai/src', 0755, true);
        }
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

    public function testEveryFileExecOperandChannelFlagsAcrossAllThreeGaps(): void
    {
        /*
         * R41-1/2/3 (security:medium, driven — round 40's probe's own
         * three gaps): (1) the channel family one short — exec/system/
         * passthru/popen/proc_open/fopen invisible; (2) the '/^\S+/'
         * keyword extraction grabbing the whole zero-whitespace
         * statement, the masked re-confirmation then failing; (3) the
         * extent over string-bearing code truncating at an in-string
         * ';'. The rewrite: the channel set widened, the keyword a
         * CAPTURE GROUP, the extent over the MASKED view with the
         * judgment reading the RAW slice at the same offsets — and the
         * tail grammar riding the ONE constant both seats share.
         */
        $canonical = "<?php\n\$prefix = 'Deicod\\\\WpConnectors\\\\Zai\\\\';\nspl_autoload_register(function (\$class) use (\$prefix) { \$path = __DIR__ . '/' . str_replace('\\\\', '/', substr(\$class, strlen(\$prefix))) . '.php'; require_once \$path; });\n";
        $flag_shapes = array(
            'exec vendor' => "exec( \$base_dir . '/vendor/run.php' );",
            'zero-ws eval+fgc' => 'eval(file_get_contents(__DIR__."/vendor/pkg/lib.php"));',
            'semi-in-string shell_exec' => 'shell_exec( "true; cat vendor/build.sh" );',
            'system vendor' => 'system( "cat vendor/build.sh" );',
            'proc_open vendor' => 'proc_open( "vendor/tool", $desc, $pipes );',
        );
        foreach ($flag_shapes as $name => $leg) {
            $violations = $this->autoloadWith($canonical . $leg . "\n");
            $this->assertStringContainsString('must not reference composer or vendor', implode("\n", $violations), "The {$name} operand channel flags (red at HEAD: 0 violations where master flags).");
            // 8 digest chars: the chained per-shape roots once crossed
            // NAME_MAX at full md5 width (glm42-4's three new rows pushed
            // the 11-shape chain past 255 bytes — driven 'File name too long').
            $this->base .= '-n' . substr(md5($name), 0, 8);
            @mkdir($this->base . '/zai/src', 0755, true);
        }
    }

    public function testAVariableMediatedVendorOperandStillReferencesVendor(): void
    {
        /*
         * R43-1 (security:medium, driven fail-open — the round's one
         * regression versus master, driven end-to-end through every
         * gate on the review's master worktree): the masked probe
         * blanks the vendor path riding a quoted literal (the prose
         * immunity) and the operand probe judged only the statement
         * text at hand, so '$lib = __DIR__ .
         * "/vendor/pkg/lib.php"; require $lib;' — php -l clean, the
         * vendor file present inside the plugin root — answered 0
         * violations where master's gate flagged. The statement's
         * variable operands resolve through the SAME-FILE assignment
         * machinery the escape walk rides, each resolved assignment
         * value judged by the operand probe's own standard — an
         * operand path is never prose, whatever its spelling.
         */
        $canonical = "<?php\n\$prefix = 'Deicod\\\\WpConnectors\\\\Zai\\\\';\nspl_autoload_register(function (\$class) use (\$prefix) { \$path = __DIR__ . '/' . str_replace('\\\\', '/', substr(\$class, strlen(\$prefix))) . '.php'; require_once \$path; });\n";

        $laundered = $this->autoloadWith($canonical . "\$lib = __DIR__ . '/../vendor/pkg/lib.php';\nrequire \$lib;\n");
        $this->assertStringContainsString('must not reference composer or vendor', implode("\n", $laundered), 'The vendor path reaching the channel through a VARIABLE still references vendor (red at HEAD: clean where master flags).');

        $this->base .= '-eval';
        @mkdir($this->base . '/zai/src', 0755, true);
        $eval = $this->autoloadWith($canonical . "\$lib = __DIR__ . '/vendor/pkg/lib.php';\neval( file_get_contents( \$lib ) );\n");
        $this->assertStringContainsString('must not reference composer or vendor', implode("\n", $eval), 'The eval channel launders the same way — the resolution serves every operand channel.');

        $this->base .= '-prose';
        @mkdir($this->base . '/zai/src', 0755, true);
        $prose = $this->autoloadWith($canonical . "\$note = 'vendor docs mention';\n");
        $this->assertStringNotContainsString('must not reference composer or vendor', implode("\n", $prose), 'A prose note on a variable no channel reads stays clean — the resolution fires only for channel operands.');
    }
    public function testQuoteShapedDataInANowdocBodyOrHtmlTailDoesNotBindThePrefix(): void
    {
        /*
         * R42-1 (security:medium, driven fail-open — the ledger's
         * R41-15 PLAUSIBLE seat upgraded): the equality arm once
         * walked the quote-pair GRAMMAR over the comment-stripped
         * view, where heredoc/nowdoc bodies and inline-HTML spans
         * keep RAW bytes — quote-shaped TEXT inside them paired as a
         * "literal" whose decoded value equalled the expected prefix,
         * a php -l-clean foreign-prefix autoloader passing the gate
         * green (driven both shapes at HEAD: 0 violations while the
         * plugin autoloads none of its own classes at runtime). The
         * equality arm walks the TOKEN STREAM's
         * T_CONSTANT_ENCAPSED_STRING tokens now — the tokenizer the
         * ONE owner of which bytes are a real quoted literal: a
         * nowdoc body lexes T_ENCAPSED_AND_WHITESPACE, inline HTML
         * lexes T_INLINE_HTML, a commented literal lexes T_COMMENT —
         * excluded by token KIND, never by a grammar the region can
         * feed quote bytes into. The canonical quoted literal still
         * binds (the benign half of R37-2's own pin).
         */
        $foreign = "<?php\nspl_autoload_register(function (\$c) {\n    \$p = 'Acme\\\\Plane\\\\' . \$c . '.php';\n    require __DIR__ . '/' . \$p;\n});\n";
        $nowdoc = $this->autoloadWith("<?php\n\$note = <<<'EOT'\nbind 'Deicod\\\\WpConnectors\\\\Zai\\\\' below\nEOT;\n" . $foreign);
        $this->assertNotEmpty($nowdoc, 'Quote-shaped prefix TEXT inside a nowdoc body binds nothing — the tokenizer excludes it by kind (red at HEAD: 0 violations).');

        $this->base .= '-html';
        @mkdir($this->base . '/zai/src', 0755, true);
        $html = $this->autoloadWith($foreign . "?>\nbind 'Deicod\\\\WpConnectors\\\\Zai\\\\' below\n");
        $this->assertNotEmpty($html, 'Quote-shaped prefix TEXT inside an inline-HTML tail binds nothing — T_INLINE_HTML never arms the equality walk (red at HEAD: 0 violations).');

        $this->base .= '-canonical';
        @mkdir($this->base . '/zai/src', 0755, true);
        $canonical = "<?php\n\$prefix = 'Deicod\\\\WpConnectors\\\\Zai\\\\';\nspl_autoload_register(function (\$class) use (\$prefix) {\n    \$path = __DIR__ . '/' . str_replace('\\\\', '/', substr(\$class, strlen(\$prefix))) . '.php';\n    require \$path;\n});\n";
        $this->assertSame(array(), $this->autoloadWith($canonical), 'The canonical quoted prefix literal still binds — a real T_CONSTANT_ENCAPSED_STRING whose decoded value equals the prefix.');
    }

    public function testAVariableToVariableChainCarriesTheVendorOperandToTheChannel(): void
    {
        /*
         * R44-1 (security:medium, driven fail-open versus master —
         * glm43-1's resolution one dataflow hop short): the judgment
         * read only the resolved assignment's OWN text, so a
         * variable-to-variable chain — the explicit '$lib = $paths;
         * require $lib;' or the foreach value binding the collector
         * mints — carried the vendor path to the channel with no
         * flag where master refused (driven end-to-end through every
         * gate, the review's master worktree drive). The resolution
         * is TRANSITIVE now: a worklist over each assignment value's
         * own variables, a seen-set closing cycles, every hop judged
         * by the same standard.
         */
        $canonical = "<?php\n\$prefix = 'Deicod\\\\WpConnectors\\\\Zai\\\\';\nspl_autoload_register(function (\$class) use (\$prefix) { \$path = __DIR__ . '/' . str_replace('\\\\', '/', substr(\$class, strlen(\$prefix))) . '.php'; require_once \$path; });\n";

        $foreach_binding = $this->autoloadWith($canonical . "\$paths = array(__DIR__ . '/../vendor-pkg/lib.php');\nforeach (\$paths as \$lib) {\n    require \$lib;\n}\n");
        $this->assertStringContainsString('must not reference composer or vendor', implode("\n", $foreach_binding), 'The foreach value binding carries the vendor path — the synthetic mint the collector spells resolves transitively (red at HEAD: clean where master flags).');

        $this->base .= '-chain';
        @mkdir($this->base . '/zai/src', 0755, true);
        $chain = $this->autoloadWith($canonical . "\$paths = __DIR__ . '/../vendor-pkg/lib.php';\n\$lib = \$paths;\nrequire \$lib;\n");
        $this->assertStringContainsString('must not reference composer or vendor', implode("\n", $chain), 'The explicit two-hop chain flags — the resolution recurses into the value own variables (red at HEAD: clean).');

        $this->base .= '-cycle';
        @mkdir($this->base . '/zai/src', 0755, true);
        $cycle = $this->autoloadWith($canonical . "\$a = \$b;\n\$b = \$a;\n\$lib = __DIR__ . '/safe.php';\nrequire \$lib;\n");
        $this->assertStringNotContainsString('must not reference composer or vendor', implode("\n", $cycle), 'A variable cycle over an anchored value stays clean — the seen-set closes the loop.');
    }


    public function testVariableCalleesFlagAndMemberCallSpellingsStayClean(): void
    {
        /*
         * R45-1 (security:medium, driven both edges — the fixed
         * keyword enumeration wrong at BOTH ends): the
         * variable-callee spelling ('$fn = 'file_get_contents';
         * $fn( __DIR__ . '/../vendor-pkg/lib.php' );') was INVISIBLE
         * where master flags, and the member-call spelling
         * ('$docs->include( ...vendor-notes... )', a legal include()
         * method) was refused for the bare vendor substring. The
         * probe derives from the TOKEN STREAM now: keyword tokens
         * and channel T_STRINGs (their previous significant token
         * refusing the member/static/nullsafe/const/function
         * name-usage contexts R44-4 spelled for the loop detector)
         * plus a variable-immediately-followed-by-'(' arm — every
         * candidate's statement extent judged by the same standard.
         */
        $canonical = "<?php\n\$prefix = 'Deicod\\\\WpConnectors\\\\Zai\\\\';\nspl_autoload_register(function (\$class) use (\$prefix) { \$path = __DIR__ . '/' . str_replace('\\\\', '/', substr(\$class, strlen(\$prefix))) . '.php'; require_once \$path; });\n";

        $var_callee = $this->autoloadWith($canonical . "\$fn = 'file_get_contents';\n\$fn( __DIR__ . '/../vendor-pkg/lib.php' );\n");
        $this->assertStringContainsString('must not reference composer or vendor', implode("\n", $var_callee), 'The variable callee is a channel whatever name it holds — the argument bytes ride the statement extent (red at HEAD: clean where master flags).');

        $this->base .= '-member';
        @mkdir($this->base . '/zai/src', 0755, true);
        $member = $this->autoloadWith($canonical . "\$docs = new DocHelper();\n\$docs->include( __DIR__ . '/../assets/vendor-notes.txt' );\nclass DocHelper { public function include(\$p) { return readfile(\$p); } }\n");
        $this->assertStringNotContainsString('must not reference composer or vendor', implode("\n", $member), 'A legal include() method is a member call, never a channel — the name-usage contexts refused by token (red at HEAD: the false refusal).');

        $this->base .= '-direct';
        @mkdir($this->base . '/zai/src', 0755, true);
        $direct = $this->autoloadWith($canonical . "file_get_contents(__DIR__ . '/vendor/pkg/lib.php');\n");
        $this->assertStringContainsString('must not reference composer or vendor', implode("\n", $direct), 'The direct spelling keeps its flag — the rewrite widens, never narrows.');
    }

    /**
     * Round-57 pin (t31-glm57-2 [R57-2] — the channel-function
     * family as ONE vocabulary): the composer screen's keyword
     * alternation (inside wp_connectors_self_containment_violations)
     * and the operand probe's membership map (inside
     * wp_connectors_autoloader_violations) spelled the file/exec
     * family twice with no structural tie while the seat's own
     * docblock claimed the tie in prose — the exact lineage that
     * already lagged once (the composer screen missed the widened
     * family across rounds 40-54 until R54-2 re-aligned it,
     * precisely because nothing tied the seats). Both seats compose
     * from WP_CONNECTORS_CHANNEL_FUNCTIONS now; eval rides the regex
     * beside the family (that seat judges code TEXT) while the token
     * walk judges it by its own T_EVAL id — two spellings of one
     * judgment, named at both seats.
     */
    public function testTheChannelFunctionFamilyRidesOneVocabularyAtBothSeats(): void
    {
        /*
         * Byte-identity with the former inline literal (the order is
         * load-bearing for composition): a reordered constant is
         * semantically inert to PCRE but this pin holds the composed
         * string to the exact spelling the standing channel pins
         * (R54-2's legs above) drove for four decades of rounds.
         */
        $this->assertSame(
            'file_get_contents|file|readfile|shell_exec|exec|system|passthru|popen|proc_open|fopen|file_put_contents|eval',
            implode('|', WP_CONNECTORS_CHANNEL_FUNCTIONS) . '|eval',
            'The composed alternation is byte-identical to the former inline literal — order included.'
        );

        /*
         * The two seats compose from the constant (the source pin: a
         * pasted second spelling replaces the consult spelling it
         * abandoned, and the count names the one declaration + two
         * consults the owner exists to hold).
         */
        $source = (string) file_get_contents(__DIR__ . '/../bin/lib/plugin-tools.php');
        $this->assertNotSame('', $source, 'The plugin-tools source must be readable for the composition pin.');
        $this->assertStringContainsString("implode('|', WP_CONNECTORS_CHANNEL_FUNCTIONS)", $source, 'The composer screen\'s keyword alternation composes from the one vocabulary.');
        $this->assertStringContainsString('array_fill_keys(WP_CONNECTORS_CHANNEL_FUNCTIONS', $source, 'The operand probe\'s membership map composes from the one vocabulary.');
        $this->assertSame(3, substr_count($source, 'WP_CONNECTORS_CHANNEL_FUNCTIONS'), 'One declaration, two consults — no third spelling of the family grows beside the owner.');
    }

    /**
     * Round-58 pin (t31-glm58-3/4/5 [R58-3+R58-5+R58-6] — the
     * per-file composer screen's three gaps, driven at the real CLI
     * by both the review and the driver): the needles were bare
     * stripos (identifier-interior 'composer' bytes in a connector's
     * own slug-mandated PSR-4 prefix — 'ComposerBridge' riding code
     * bytes and resolved operand text — minting the violation and
     * making a composer-named connector un-buildable while the
     * autoloader seat judged the identical prefix clean); the
     * 'vendor/autoload' needle judged contiguous text only (a path
     * composed across two literals — '__DIR__ . '/vendor' .
     * '/autoload.php'' — laundering at 0 violations although the
     * runtime path IS vendor/autoload.php); and the keyword conjunct
     * gated the channel operands on an unrelated include (a lone
     * shell_exec('composer install') clean while its byte-twin plus
     * one innocent require_once flagged).
     */
    public function testTheComposerScreenJudgesWordBoundariesConcatenatedLiteralsAndChannelOperands(): void
    {
        $dir = $this->base . '/plug/src';
        @mkdir($dir, 0755, true);
        /*
         * The staging asserts its own landing (the t31-ocr53-9
         * doctrine).
         */
        $files = array(
            'autoload.php' => "<?php\n\$prefix = 'Deicod\\\\WpConnectors\\\\ComposerBridge\\\\';\nspl_autoload_register(function (\$class) use (\$prefix) {\n    \$file = __DIR__ . '/' . str_replace('\\\\', '/', substr(\$class, strlen(\$prefix))) . '.php';\n    if (is_file(\$file)) { require \$file; }\n});\n",
            'split.php' => "<?php\n\$p = __DIR__ . '/vendor' . '/autoload.php';\nrequire \$p;\n",
            'channelonly.php' => "<?php\n\$out = shell_exec( 'composer install --no-dev' );\n",
            'contiguous.php' => "<?php\n\$p = __DIR__ . '/vendor/autoload.php';\nrequire \$p;\n",
            'prose.php' => "<?php\n\$note = 'self-contained: must not require composer or vendor at runtime';\necho \$note;\n",
        );
        foreach ($files as $name => $body) {
            $this->assertNotFalse(file_put_contents($dir . '/' . $name, $body), "staging: {$name} must write — a staging failure fails as staging, never as the verdict.");
        }

        $violations = wp_connectors_self_containment_violations($this->base . '/plug');
        $joined = implode("\n", $violations);

        // R58-5: the SPLIT literal flags through its concatenated
        // literal spine (red at HEAD: 0 violations — clean).
        $this->assertStringContainsString('plug: src/split.php references vendor/autoload (no Composer at runtime).', $joined, 'A Composer path composed across two literals still references vendor/autoload — the runtime path is one spelling.');
        // R58-6: the channel-carried reference flags without any
        // unrelated include in the file (red at HEAD: clean).
        $this->assertStringContainsString('plug: src/channelonly.php references Composer at runtime.', $joined, 'A lone channel invocation carrying composer flags — the channel span IS the runtime context the conjunct asks for.');
        // The standing contiguous control keeps its flag.
        $this->assertStringContainsString('plug: src/contiguous.php references vendor/autoload (no Composer at runtime).', $joined, 'The contiguous spelling keeps its flag — the fix widens, never narrows.');
        // R58-3: the identifier-interior prefix bytes are NOT a
        // reference (red at HEAD: src/autoload.php flagged twice).
        $this->assertStringNotContainsString('src/autoload.php', $joined, 'Identifier-interior composer bytes in the slug-mandated PSR-4 prefix are not a Composer reference — one boundary, both seats.');
        // The R51-8 prose-immunity control keeps its cleanliness.
        $this->assertStringNotContainsString('src/prose.php', $joined, 'The words inside a benign prose literal still mint nothing — the prose immunity stands.');
        $this->assertCount(3, $violations, 'Exactly the three deliberate probes flag — nothing else in the staged tree.');

        /*
         * t31-glm60-1 (R60-5, driven at HEAD by both the review and
         * the driver — the round-59 glue check verified only the
         * PREFIX): a non-literal operand between two literals
         * ('$parts[0] .' '/autoload.php') was skipped wholesale and
         * the literals composed a fabricated contiguous spelling
         * the runtime never spells — a working plugin falsely
         * refused as Composer-dependent where master was clean.
         * The glue must span EXACTLY the bytes between the literals.
         */
        $interleaved = wp_connectors_joined_literal_pieces("\$parts = array('/notes'); file_get_contents( __DIR__ . '/vendor' . \$parts[0] . '/autoload.php' );");
        $this->assertSame("/notes\n/vendor\n/autoload.php", $interleaved, 'An operand between literals BREAKS the spine — the pieces never compose across it (red at HEAD: the fabricated /vendor/autoload.php).');
        $this->assertSame('/vendor/autoload.php', wp_connectors_joined_literal_pieces("\$p = __DIR__ . '/ven' . 'dor/autoload.php';"), 'The all-literal chain still composes the runtime spelling.');
        /*
         * t31-glm62-4 (R62-6, driven): the exact-glue check broke on
         * a parenthesized operand — a legal php -l-clean chain whose
         * runtime value composes vendor/autoload laundered at both
         * gates. The R48-6 paren tolerance at the spine.
         */
        $this->assertSame('/vendor/autoload.php', wp_connectors_joined_literal_pieces("\$x = file_get_contents((__DIR__ . '/vendor') . '/autoload.php');"), 'A parenthesized operand chain composes the runtime spelling — the glue may wrap either side in parens (red at HEAD: the spine split, the reference laundered).');
    }

    /**
     * Round-59 pin (t31-glm59-4 [R59-3+R59-4, driven at HEAD by both
     * the review and the driver — two fail-opens in the round-58
     * commit's own rework): the composer needle's OPERAND consult
     * lost the loader class names (WP_CONNECTORS_COMPOSER_CLASS_
     * REFERENCES armed the masked seat only, so a
     * 'ComposerAutoloader.php' include operand certified clean where
     * master flagged), and the R58-5 literal spine landed at the
     * self-containment screen's join points only — the autoloader
     * gate's own consults still judged contiguous text, so a
     * vendor/autoload path composed across two literals certified
     * there while the sibling screen flagged the same bytes.
     */
    public function testTheLoaderClassOperandAndTheGateSpineBothFlag(): void
    {
        $base = $this->base . '-r59';
        @mkdir($base . '/plug/src', 0755, true);

        // R59-3: the loader-class operand flags through the screen.
        $this->assertNotFalse(file_put_contents($base . '/plug/src/loader.php', "<?php\nrequire_once __DIR__ . \"/inc/ComposerAutoloader.php\";\n"), 'staging: loader.php must write.');
        $screen = wp_connectors_self_containment_violations($base . '/plug');
        $this->assertStringContainsString('plug: src/loader.php references Composer at runtime.', implode("\n", $screen), 'A loader-class-named operand IS a Composer reference — the class-name arm serves the operand seat too (red at HEAD: clean).');

        // R59-4: the split literal flags through the AUTOLOADER gate.
        $gate_dir = $base . '/zai';
        @mkdir($gate_dir . '/src', 0755, true);
        $this->assertNotFalse(file_put_contents($gate_dir . '/src/autoload.php', "<?php\n\$prefix = 'Deicod\\\\WpConnectors\\\\Zai\\\\';\nspl_autoload_register(static function (\$class) use (\$prefix) {\n    \$file = __DIR__ . '/' . str_replace('\\\\', '/', substr(\$class, strlen(\$prefix))) . '.php';\n    if (is_file(\$file)) { require \$file; }\n});\n\$fallback = __DIR__ . '/ven' . 'dor/autoload.php';\nrequire \$fallback;\n"), 'staging: the split-fallback autoload must write.');
        $gate = wp_connectors_autoloader_violations($gate_dir);
        $this->assertStringContainsString('must not reference composer or vendor', implode("\n", $gate), 'A vendor/autoload path composed across two literals flags through the autoloader gate — the spine serves both gates (red at HEAD: clean).');
    }

    /**
     * Round-64 pin (t31-glm64-2 [R64-3+R64-4+R64-12+R64-13] — the
     * composer screen's four round-64 members, all driven A/B by
     * both the review and the driver): the 'file' channel twin, the
     * backtick execution operator, the member-glue refusal, and the
     * concatenated prefix literal.
     */
    public function testTheComposerScreensFourRound64Members(): void
    {
        $base = $this->base . '-r64';
        $d = $base . '/plug'; @mkdir($d . '/src', 0755, true);
        $autoloader = "<?php\n"
            . "\$prefix = 'Deicod\\\\\\\\WpConnectors\\\\\\\\Zai\\\\\\\\';\n"
            . "spl_autoload_register(static function (\$class) use (\$prefix) {\n"
            . "    \$file = __DIR__ . \"/\" . str_replace(\"\\\\\\\\\", \"/\", substr(\$class, strlen(\$prefix))) . \".php\";\n"
            . "    if (is_file(\$file)) { require \$file; }\n"
            . "});\n";
        $this->assertNotFalse(file_put_contents($d . '/src/autoload.php', $autoloader), 'staging: autoload must write.');
        $writes = array(
            'file.php' => "<?php\nreturn \$x = file( __DIR__ . '/../vendor/autoload.php' );\n",
            'tick.php' => "<?php\n\$out = `composer install --no-dev`;\n",
            'member.php' => "<?php\n\$package = new stdClass(); \$package->composer = 'John'; require __DIR__ . '/helper.php';\n",
            'control.php' => "<?php\n\$package = new stdClass(); \$package->composerName = 'John'; require __DIR__ . '/helper.php';\n",
        );
        foreach ($writes as $name => $body) {
            $this->assertNotFalse(file_put_contents($d . "/src/{$name}", $body), "staging: {$name} must write.");
        }
        $v = wp_connectors_self_containment_violations($d);
        $joined = implode("\n", $v);
        $this->assertStringContainsString('src/file.php references vendor/autoload', $joined, 'The file() twin is a channel (red at HEAD: invisible).');
        $this->assertStringContainsString('src/tick.php references Composer', $joined, 'The backtick operator is a channel — the raw-view collector (red at HEAD: invisible).');
        $this->assertStringNotContainsString('src/member.php', $joined, 'A benign member named composer mints nothing — the member-glue refusal (red at HEAD: false refusal).');
        $this->assertStringNotContainsString('src/control.php', $joined, 'The control stays clean.');

        // R64-13: the concatenated prefix binds.
        $g = $base . '/plug2'; @mkdir($g . '/src', 0755, true);
        $this->assertNotFalse(file_put_contents($g . '/src/autoload.php', "<?php\n"
            . "\$prefix = 'Deicod\\\\\\\\WpConnectors\\\\\\\\' . 'Plug2\\\\\\\\';\n"
            . "spl_autoload_register(static function (\$class) use (\$prefix) {\n"
            . "    \$file = __DIR__ . \"/\" . str_replace(\"\\\\\\\\\", \"/\", substr(\$class, strlen(\$prefix))) . \".php\";\n"
            . "    if (is_file(\$file)) { require \$file; }\n"
            . "});\n"), 'staging: the concatenated autoload must write.');;
        $this->assertSame(array(), wp_connectors_autoloader_violations($g), 'A working autoloader binding the prefix through TWO literals binds — the token-adjacency equality arm (red at HEAD: false refusal).');
    }

}