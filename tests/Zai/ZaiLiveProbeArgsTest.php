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
        foreach (array('settings', 'endpoint', 'provider', 'availability', 'provider_id', 'default_plan') as $fact) {
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
         */
        $this->assertStringContainsString("'live ' . \$endpoint->models_url()", $source, 'The discovery evidence names the surface\'s own models URL.');
        $this->assertSame(0, preg_match('/live \/v1\/models/', $source), 'No hardcoded models route may ride the evidence line.');

        /*
         * glm15-4: the generation-route evidence rides the endpoint
         * layer's generation_url() owner — the instanceof ternary plus
         * the inline 'chat/completions' literal could print a URL the
         * plugin never requests after any vendor or plan route change,
         * and the literal existed nowhere else in src, so nothing
         * failed. The pins forbid both shapes in the probe.
         */
        $this->assertStringContainsString("->generation_url()", $source, 'The generation-route evidence rides the endpoint owner.');
        $this->assertSame(0, preg_match('/chat\/completions/', $source), 'No inline generation-route literal may ride the probe.');
        $this->assertSame(0, preg_match('/instanceof ZaiAnthropicEndpoint \?/', $source), 'No instanceof route picking: the endpoint owns the route.');

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
}
