<?php
/**
 * Task 2.7 support — live-probe CLI argument parsing (GLM8 #7).
 *
 * The probe performs live network requests, so these tests run it in a
 * subprocess with a cleared environment: with no key resolvable from
 * ZAI_LIVE_API_KEY / WP_CONNECTORS_TEST_ZAI_API_KEY / HOME, the probe
 * exits 2 at the key-lookup step — AFTER argument parsing — which makes
 * the exit diagnostic an exact observable of what the option parser
 * accepted. No network is ever reached.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

final class ZaiLiveProbeArgsTest extends WpConnectorsTestCase
{
    /**
     * Runs the probe once with a cleared environment and returns
     * [exit code, output].
     *
     * @param list<string> $arguments CLI arguments after the script path.
     * @return array{0: int, 1: string}
     */
    private function runProbe(array $arguments)
    {
        $repo = dirname(__DIR__, 2);

        $command = 'env -i HOME=/nonexistent-zai-probe-home '
            . escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($repo . '/bin/zai-live-probe.php');
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }
        $command .= ' 2>&1';

        exec($command, $outputLines, $exitCode);

        return array($exitCode, implode("\n", $outputLines));
    }

    /**
     * @dataProvider provideAcceptedInvocations
     */
    public function testTheConventionalSpaceSeparatedFormIsAccepted(array $arguments)
    {
        /*
         * GLM8 #7: getopt's optional-value '::' declarations captured
         * only the '--option=value' form — the conventional
         * space-separated form returned false for every option, cast to
         * '', and rejected a valid invocation as '--surface must be
         * openai or anthropic'. An accepted invocation now reaches the
         * key lookup and exits with the no-key diagnostic instead.
         */
        list($exitCode, $output) = $this->runProbe($arguments);

        $this->assertSame(2, $exitCode, "A keyless run must exit 2, got {$exitCode}: {$output}");
        $this->assertStringContainsString('no key found', $output, 'The invocation was accepted; the run stops at the key lookup.');
        $this->assertStringNotContainsString('--surface must', $output, 'The space-separated value form must not be rejected.');
        $this->assertStringNotContainsString('--plan must', $output);
        $this->assertStringNotContainsString('--region must', $output);
        $this->assertStringNotContainsString('requires a value', $output);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function provideAcceptedInvocations()
    {
        return array(
            'space-separated form' => array(array('--surface', 'anthropic', '--plan', 'general')),
            'equals-attached form' => array(array('--surface=anthropic', '--plan=general')),
            'mixed forms' => array(array('--surface=anthropic', '--plan', 'general', '--region=intl')),
            'no options at all' => array(array()),
        );
    }

    public function testABareOptionWithoutAValueFailsTruthfully()
    {
        // GLM8 #7: with required-value ':' declarations a bare trailing
        // '--option' silently drops out of the getopt() result (and a
        // bare option mid-invocation swallows the next token as its
        // value) — the raw argv scan names the real problem instead.
        list($exitCode, $output) = $this->runProbe(array('--surface'));

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('--surface requires a value', $output);
        $this->assertStringNotContainsString('must be openai or anthropic', $output, 'The diagnostic must not blame a value the user never passed.');

        list($exitCode, $output) = $this->runProbe(array('--surface', 'anthropic', '--plan'));
        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('--plan requires a value', $output);
    }

    public function testATrailingBareRepeatOptionIsRejectedWithTheOptionNamed()
    {
        /*
         * glm36-3 (round-36 finding 3): the missing-value check rode a
         * pre-scan whose array_search() saw only each option's FIRST
         * occurrence, the sequential scan consumed values blind, and
         * getopt() dropped the dangling flag — so '--plan coding --plan'
         * was silently ignored and the probe ran the full live,
         * billable round trip (empirically confirmed), while
         * '--plan a --plan' died blaming the VALUE through the
         * whitelist. The check rides the consumption site now — every
         * occurrence checks, and the diagnostic names the option.
         */
        list($exitCode, $output) = $this->runProbe(array('--plan', 'coding', '--plan'));

        $this->assertSame(2, $exitCode, "The dangling flag must be rejected, got {$exitCode}: {$output}");
        $this->assertStringContainsString('--plan requires a value', $output, 'The repeat occurrence is judged, not just the first.');
        $this->assertStringNotContainsString('no key found', $output, 'The rejection precedes the key lookup — no billable run.');

        // The '--plan a --plan' form previously reached the whitelist
        // and blamed the VALUE the user did pass for the flag they
        // left dangling.
        list($exitCode, $output) = $this->runProbe(array('--plan', 'a', '--plan'));
        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('--plan requires a value', $output, 'The dangling flag is the named problem, never the earlier value.');
        $this->assertStringNotContainsString('--plan must be', $output);

        list($exitCode, $output) = $this->runProbe(array('--surface', 'anthropic', '--surface'));
        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('--surface requires a value', $output);

        // An option-led VALUE is the missing-value shape for every
        // option (the pre-scan owned this; the consumption site owns it
        // now — same diagnostic, one site).
        list($exitCode, $output) = $this->runProbe(array('--surface', '--plan', 'coding'));
        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('--surface requires a value', $output);
    }

    public function testAnInvalidValueStillFailsWithTheValueDiagnostic()
    {
        // GLM8 #7 guard: only the FORM handling changed — a genuinely
        // invalid value keeps its whitelist diagnostic.
        list($exitCode, $output) = $this->runProbe(array('--surface', 'bogus'));

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('--surface must be openai or anthropic', $output);
    }

    public function testInvalidPlanAndRegionValuesKeepTheirWhitelistDiagnostics()
    {
        /*
         * glm15-10: the whitelists compose from the owner constants
         * now — the DIAGNOSTICS compose from the same lists, so an
         * invalid value keeps the value-naming diagnostic shape.
         */
        list($exitCode, $output) = $this->runProbe(array('--plan', 'bogus'));

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('--plan must be coding or general', $output);

        list($exitCode, $output) = $this->runProbe(array('--region', 'bogus'));

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('--region must be intl or cn', $output);
    }

    public function testAHomelessEnvironmentSkipsTheFileFallbackCleanly()
    {
        /*
         * glm15-3: under cron/systemd HOME is UNSET, getenv('HOME')
         * returns false, and the old bare concatenation probed the
         * filesystem ROOT ('/.config/z.ai/api_key') instead of skipping
         * the fallback — silently using whatever unrelated readable file
         * lives there as the live API key. With no HOME at all the key
         * lookup must fail cleanly at the no-key diagnostic.
         */
        $repo = dirname(__DIR__, 2);

        $command = 'env -i '
            . escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($repo . '/bin/zai-live-probe.php')
            . ' 2>&1';
        exec($command, $outputLines, $exitCode);
        $output = implode("\n", $outputLines);

        $this->assertSame(2, $exitCode, "A keyless, HOME-less run must exit 2, got {$exitCode}: {$output}");
        $this->assertStringContainsString('no key found', $output, 'With no HOME the file fallback is skipped, not concatenated onto false.');

        // Source pin: the boolean-concatenation shape may not return.
        $source = (string) file_get_contents($repo . '/bin/zai-live-probe.php');
        $this->assertSame(0, preg_match('/getenv\(\s*[\'"]HOME[\'"]\s*\)\s*\./', $source), 'getenv(\'HOME\') may never be concatenated unchecked (false concatenates to a root path).');
        $this->assertStringContainsString("\\is_string( \$home )", $source, 'The HOME fallback is guarded by a string check.');
    }

    public function testARegisterArgcArgvDisabledRunWalksTheUsagePathNotAFatal()
    {
        /*
         * glm23-3 (review round 23, finding 3): the argv pre-scan read
         * the global unguarded, so under register_argc_argv=0 (a valid
         * php.ini setting on the supported range — empirically unset in
         * docker php:8.3-cli and php:7.4-cli; PHP builds newer than the
         * composer platform ceiling always populate argv in the CLI
         * SAPI, where this pin asserts the same outcome vacuously) the
         * strict-types array_search() fataled with exit 255 before ANY
         * diagnostic, violating the file's own GLM7 #14 rule that even
         * Errors must surface as named FAILED steps. The guarded
         * pre-scan and the getopt() false normalization walk the usage
         * path: every option at its default, stopping at the key lookup
         * with its named diagnostic.
         */
        $repo = dirname(__DIR__, 2);

        $command = 'env -i HOME=/nonexistent-zai-probe-home '
            . escapeshellarg(PHP_BINARY) . ' -d register_argc_argv=0 '
            . escapeshellarg($repo . '/bin/zai-live-probe.php')
            . ' 2>&1';
        exec($command, $outputLines, $exitCode);
        $output = implode("\n", $outputLines);

        $this->assertSame(2, $exitCode, "The argc_argv=0 run must exit at the key lookup, got {$exitCode}: {$output}");
        $this->assertStringContainsString('no key found', $output, 'The unpopulated-argv run walks the usage path to the named diagnostic.');
        $this->assertStringNotContainsString('Fatal error', $output);
        $this->assertStringNotContainsString('TypeError', $output);
    }

    public function testThePerSurfaceFactsRideTheOwnerConstants()
    {
        /*
         * GLM10 #15: the probe hand-composed the plan/region option
         * names and selected ~8 per-surface facts through scattered
         * inline ternaries — an option rename would strand it writing
         * options nothing reads while still printing the chosen
         * plan/region as acceptance evidence, misleading evidence for
         * the exact billing-surface risk the plan/region whitelists
         * exist for. One fact table chosen after validation rides the
         * owner constants now; the source pin forbids hand-composed
         * plugin option names in the probe.
         */
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/zai-live-probe.php');

        $this->assertSame(0, preg_match('/[\'"]zai_connector_/', $source), 'No hand-composed plugin option names: every option rides an owner constant.');
        /*
         * glm24-1: the 'availability' fact is gone from the list — the
         * column was an unpinned hand pairing whose constants alias the
         * settings class the row already carries, so the probe reads
         * KEY_OPTION/STATE_OPTION through it and states no availability
         * class at all (pinned in ZaiSurfaceLockstepTest).
         */
        foreach (array('settings', 'endpoint', 'provider', 'provider_id', 'default_plan') as $fact) {
            $this->assertStringContainsString("['{$fact}']", $source, "The {$fact} fact rides the per-surface table.");
        }

        /*
         * GLM11 #5: two IDENTITY facts stayed hand-composed after the
         * GLM10 #15 fold — provider_id and default_plan were quoted
         * literals, so a PROVIDER_ID or DEFAULT_PLAN rename would have
         * registered Plugin::register() under a new id while the probe
         * still wired its authentication to the stale one (and
         * defaulted to the stale plan, printing it as evidence). The
         * table rows ride the owner constants now; the pins forbid
         * the quoted-literal shape outright, so the next hand-composed
         * identity fact fails the source scan, not a live run.
         */
        foreach (array(
            'ZaiProvider::PROVIDER_ID',
            'ZaiAnthropicProvider::PROVIDER_ID',
            'PlanRegionSettings::DEFAULT_PLAN',
            'ZaiAnthropicPlanRegionSettings::DEFAULT_PLAN',
        ) as $owner_constant) {
            $this->assertStringContainsString($owner_constant, $source, "The identity fact rides its owner constant ({$owner_constant}).");
        }
        foreach (array('provider_id', 'default_plan') as $fact) {
            $this->assertSame(0, preg_match("/['\"]{$fact}['\"]\s*=>\s*['\"]/", $source), "The {$fact} fact must ride a constant, not a quoted literal.");
        }

        /*
         * GLM12 #11: the discovery-source evidence line hardcoded
         * 'live /v1/models' for BOTH surfaces, but the openai surface's
         * models route is {base}/models (MODELS_ROUTE 'models') — the
         * acceptance evidence named a URL that surface never requested,
         * misdirecting anyone reconciling the probe output against
         * transport logs or the endpoint matrix. The line interpolates
         * the endpoint's models_url() (the MODELS_ROUTE owner) now; the
         * pins forbid the hardcoded route in the evidence channel.
         *
         * glm29-9: the evidence line lives in the round-trip runner
         * with the sequence itself; the pin re-targets the runner, and
         * the hardcoded-route ban covers BOTH files.
         */
        $runner = (string) file_get_contents(dirname(__DIR__, 2) . '/tests/harness/ZaiLiveRoundTrip.php');

        $this->assertStringContainsString("'live ' . \$endpoint->models_url()", $runner, 'The discovery evidence names the surface\'s own models URL.');
        $this->assertSame(0, preg_match('/live \/v1\/models/', $runner), 'No hardcoded models route may ride the evidence line.');
        $this->assertSame(0, preg_match('/live \/v1\/models/', $source), 'No hardcoded models route may ride the probe shell.');

        /*
         * glm15-4: the generation-route evidence rides the endpoint
         * layer's generation_url() owner — the instanceof ternary plus
         * the inline 'chat/completions' literal could print a URL the
         * plugin never requests after any vendor or plan route change,
         * and the literal existed nowhere else in src, so nothing
         * failed. The pins forbid both shapes in the probe.
         *
         * glm29-9: the evidence line lives in the round-trip runner
         * with the sequence itself; the owner-spelling pin re-targets
         * the runner and the literal bans cover BOTH files.
         */
        $this->assertStringContainsString("->generation_url()", $runner, 'The generation-route evidence rides the endpoint owner.');
        $this->assertSame(0, preg_match('/chat\/completions/', $source . $runner), 'No inline generation-route literal may ride the probe or runner.');
        $this->assertSame(0, preg_match('/instanceof ZaiAnthropicEndpoint \?/', $source . $runner), 'No instanceof route picking: the endpoint owns the route.');

        /*
         * glm15-10: the --plan/--region whitelists ride the declared
         * owner (AbstractPlanRegionSettings::PLANS/REGIONS) — this was
         * the third hand-copy of the lists (settings layer,
         * uninstall.php, here), so a valid new value was rejected with
         * a misleading diagnostic while the plugin served it. The pins
         * forbid the literal-list shape in the probe and uninstall.
         */
        $this->assertStringContainsString('AbstractPlanRegionSettings::PLANS', $source, 'The plan whitelist rides the owner constant.');
        $this->assertStringContainsString('AbstractPlanRegionSettings::REGIONS', $source, 'The region whitelist rides the owner constant.');
        $this->assertSame(0, preg_match("/array\(\s*'coding',\s*'general'\s*\)/", $source), 'No hand-copied plan list may ride the probe.');
        $this->assertSame(0, preg_match("/array\(\s*'intl',\s*'cn'\s*\)/", $source), 'No hand-copied region list may ride the probe.');

        $uninstall = (string) file_get_contents(dirname(__DIR__, 2) . '/connectors/zai/uninstall.php');
        $this->assertStringContainsString('AbstractPlanRegionSettings::PLANS', $uninstall, 'The uninstall discovery sweep rides the owner plan list.');
        $this->assertStringContainsString('AbstractPlanRegionSettings::REGIONS', $uninstall, 'The uninstall discovery sweep rides the owner region list.');
        $this->assertSame(0, preg_match("/array\(\s*'coding',\s*'general'\s*\)/", $uninstall), 'No hand-copied plan list may ride the uninstall sweep.');
        $this->assertSame(0, preg_match("/array\(\s*'intl',\s*'cn'\s*\)/", $uninstall), 'No hand-copied region list may ride the uninstall sweep.');
    }

    public function testHelpRequestsPrintUsageAndExitZeroWithoutReachingTheKeyLookup()
    {
        /*
         * glm31-3: --help used to be an unrecognized option getopt()
         * silently dropped — the probe fell to the defaults and, with a
         * key present, ran the full live, billable round trip while
         * answering the help request with PASS. Help answers before
         * anything else now, exit 0, and never reaches the key lookup.
         */
        foreach (array('--help', '-h') as $helpFlag) {
            list($exitCode, $output) = $this->runProbe(array($helpFlag));

            $this->assertSame(0, $exitCode, "[{$helpFlag}] Help must exit 0, got {$exitCode}: {$output}");
            $this->assertStringContainsString('Usage: php bin/zai-live-probe.php', $output);
            $this->assertStringContainsString('--surface <openai|anthropic>', $output, 'The usage derives its surface list from the built map.');
            $this->assertStringContainsString('--plan <coding|general>', $output, 'The usage derives its plan spellings from the owner constants.');
            $this->assertStringContainsString('--region <intl|cn>', $output, 'The usage derives its region spellings from the owner constants.');
            $this->assertStringNotContainsString('no key found', $output, 'A help request never reaches the key lookup.');
        }
    }

    public function testMisspelledAndUnknownOptionsAreRejectedBeforeAnyDefaultRun()
    {
        /*
         * glm31-3: getopt() drops unrecognized options, so --surfac=
         * anthropic (typo), -surface (single dash: undeclared short
         * options), --verbose (unknown), and a stray positional all
         * fell to the defaults — the silent-defaults class that runs
         * the billable round trip the operator did not ask for. The
         * raw-argv scan rejects each shape with its own diagnostic
         * before the key lookup.
         */
        $shapes = array(
            array('--surfac=anthropic', 'unrecognized option', '--surfac'),
            array('-surface', 'anthropic', 'unrecognized argument', '-surface'),
            array('--verbose', 'unrecognized option', '--verbose'),
            array('anthropic', 'unrecognized argument', 'anthropic'),
        );

        foreach ($shapes as $shape) {
            $label = implode(' ', array_slice($shape, 0, -1));
            $expected = $shape[count($shape) - 1];

            list($exitCode, $output) = $this->runProbe(array_slice($shape, 0, -1));

            $this->assertSame(2, $exitCode, "[{$label}] The shape must be rejected, got {$exitCode}: {$output}");
            $this->assertStringContainsString($expected, $output, "[{$label}] The diagnostic names the shape.");
            $this->assertStringContainsString('--help prints the usage', $output, "[{$label}] The diagnostic points at the help path.");
            $this->assertStringNotContainsString('no key found', $output, "[{$label}] The rejection precedes the key lookup.");
            $this->assertStringNotContainsString('must be openai or anthropic', $output, "[{$label}] The diagnostic must not blame a value for an unknown option's sake.");
        }
    }

    public function testAnEmptyEqualsAttachedValueIsRejectedAsAMissingValue()
    {
        /*
         * glm36-9 (verifier round): getopt() silently DROPS an empty
         * '='-attached value from its result ('--plan=' → the option
         * absent), so the probe fell to its DEFAULTS and, with a key
         * present, ran the full live, billable round trip on settings
         * the operator never chose — glm31-3's silent-defaults class,
         * empirically confirmed by both verifier lenses. The scan
         * judges the emptiness itself: same diagnostic as the bare
         * option, before the key lookup.
         */
        foreach (array('surface', 'plan', 'region') as $option) {
            list($exitCode, $output) = $this->runProbe(array("--{$option}="));

            $this->assertSame(2, $exitCode, "[--{$option}=] The empty attached value must be rejected, got {$exitCode}: {$output}");
            $this->assertStringContainsString("--{$option} requires a value", $output, "[--{$option}=] The diagnostic names the option.");
            $this->assertStringNotContainsString('no key found', $output, "[--{$option}=] The rejection precedes the key lookup.");
        }

        // A trailing empty repeat after a valid value is the same shape.
        list($exitCode, $output) = $this->runProbe(array('--plan=general', '--plan='));
        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('--plan requires a value', $output);
    }

    public function testAValueConsumedByAKnownOptionIsNeverJudgedItself()
    {
        /*
         * glm31-3 (scan-boundary pin): the sequential scan consumes the
         * token after a space-separated option as its VALUE — a plan
         * named '--region'-like or a value carrying a dash stays the
         * option's business (the whitelists judge it), never the
         * unknown-argument channel's.
         */
        list($exitCode, $output) = $this->runProbe(array('--plan', 'co-ding'));

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('--plan must be coding or general', $output, 'The value reaches the whitelist diagnostic, not the argument scan.');
    }

    public function testTheLongOptionVocabularyRidesOneOwner()
    {
        /*
         * glm37-9: the option names were hand-stated at ~7 sites; the
         * dangerous direction of a missed lockstep edit is the getopt()
         * SPEC — the raw scan accepts a token the spec never declared,
         * getopt() silently drops it, and the option falls to its
         * default (the glm36-9 silent-defaults class: a key-present run
         * billable on settings the operator never chose). The whitelist,
         * the spec, and the diagnostics compose from
         * zai_live_probe_long_options() now; this source pin holds the
         * composition and forbids the hand-stated list shapes, and every
         * owner name must appear in exactly one option read so a rename
         * cannot strand a read on a name getopt() no longer captures.
         *
         * glm38-10 SUPERSEDES the spec half (the GLM10 #4 lesson,
         * documented here): the scan COLLECTS the options it validates,
         * so the getopt() call and its spec are deleted — one parser,
         * nothing to keep in agreement. The spec ban below stays as a
         * guard against getopt() ever returning with a hand-stated spec;
         * the whitelist and diagnostic compositions are unchanged and
         * remain the pin's live information.
         */
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/zai-live-probe.php');

        $this->assertStringContainsString('function zai_live_probe_long_options()', $source, 'The CLI names its long-option vocabulary once (glm37-9).');
        $this->assertStringContainsString("return array( 'surface', 'plan', 'region' );", $source, "The owner states today's three names.");
        $this->assertStringContainsString('\in_array( $zai_probe_name, zai_live_probe_long_options(), true )', $source, 'The raw-argv whitelist rides the owner.');
        $this->assertStringContainsString('zai_live_probe_long_options()', $source, 'The getopt spec composition rides the owner.');

        // The owner itself states the names once — the bans target the
        // CONSUMER sites (the whitelist and the spec), where a hand-stated
        // copy is the silent-defaults shape. glm37-13 (verifier note): the
        // spec ban anchors on the getopt() call itself so the literal is
        // banned in ANY order ('array(' directly as getopt's second
        // argument — the composed spec passes 'array_map(' through).
        $this->assertSame(0, preg_match("/in_array\(\s*\\\$zai_probe_name,\s*array\(/", $source), 'No hand-stated whitelist: the scan composes from the owner.');
        $this->assertSame(0, preg_match("/getopt\(\s*''\s*,\s*array\(/", $source), 'No hand-stated getopt spec in any order: it composes from the owner.');

        foreach (array('surface', 'plan', 'region') as $option) {
            $this->assertStringContainsString("zai_live_probe_option( \$args, '{$option}',", $source, "The --{$option} read spells the owner's name.");
        }
    }

    public function testTheKeySourceProseRidesTheLookupConstants()
    {
        /*
         * glm37-10: the key-source names lived in code (the env ladder,
         * the key-file path) and were re-typed as literals in the usage
         * text and the no-key diagnostic — a renamed or added source
         * left --help telling the operator to set an env var the tool no
         * longer reads, on the exact failure where the guidance matters
         * most. The prose composes from the lookup's own constants now;
         * these pins hold the composition at both consumers.
         */
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/zai-live-probe.php');

        $this->assertStringContainsString(
            "const ZAI_PROBE_KEY_ENV_LADDER = array( 'ZAI_LIVE_API_KEY', 'WP_CONNECTORS_TEST_ZAI_API_KEY' );",
            $source,
            'The env ladder is stated once, as a constant.'
        );
        $this->assertStringContainsString("const ZAI_PROBE_KEY_FILE = '.config/z.ai/api_key';", $source, 'The key-file path is stated once, as a constant.');
        $this->assertStringContainsString("zai_live_probe_key_source_prose() . '.',", $source, 'The usage sentence composes from the owner constants.');
        $this->assertStringContainsString('zai_live_probe_key_source_prose() . ")', $source, 'The no-key diagnostic composes from the owner constants.');
    }
}
