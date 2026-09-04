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
    }
}
