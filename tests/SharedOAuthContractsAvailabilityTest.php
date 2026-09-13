<?php
/**
 * Contract tests: shared availability vocabulary (Task 3.1).
 *
 * All five states constructible with neutral labels, both evaluation
 * contexts present, and the port's shape fixed.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

use Deicod\WpConnectors\Shared\Availability\AvailabilityContext;
use Deicod\WpConnectors\Shared\Availability\AvailabilityState;
use Deicod\WpConnectors\Shared\Availability\OAuthAvailabilityInterface;

final class SharedOAuthContractsAvailabilityTest extends WpConnectorsTestCase
{
    public function testAllFiveStatesAreConstructibleWithNeutralLabels(): void
    {
        $expected = array(
            'disconnected' => 'Disconnected',
            'reconnect_required' => 'Re-connect required',
            'temporarily_unavailable' => 'Temporarily unavailable',
            'connected' => 'Connected',
            'configuration_error' => 'Update required',
        );

        $actual = array();
        foreach (AvailabilityState::cases() as $state) {
            $actual[$state->value] = $state->label();
        }

        $this->assertSame($expected, $actual);
    }

    public function testLabelsAreDistinctNonEmptyNeutralStrings(): void
    {
        $labels = array();
        foreach (AvailabilityState::cases() as $state) {
            $label = $state->label();
            $this->assertNotSame('', $label);
            // Neutral vocabulary: no markup, no host-function output.
            $this->assertThat($label, $this->logicalNot($this->stringContains('<')));
            $labels[] = $label;
        }

        $this->assertCount(5, array_unique($labels));
    }

    public function testBothEvaluationContextsExist(): void
    {
        $this->assertSame(
            array('render', 'maintenance'),
            array_map(static function (AvailabilityContext $context): string {
                return $context->value;
            }, AvailabilityContext::cases())
        );
    }

    public function testAvailabilityPortShapeIsFixed(): void
    {
        $this->assertTrue(interface_exists(OAuthAvailabilityInterface::class));
        $method = new \ReflectionMethod(OAuthAvailabilityInterface::class, 'availability');

        $this->assertSame(1, $method->getNumberOfParameters());
        $this->assertSame(AvailabilityContext::class, (string) $method->getParameters()[0]->getType());
        $this->assertSame(AvailabilityState::class, (string) $method->getReturnType());
    }

    /**
     * The GET-render read-only contract rides the Render context value:
     * an implementation answering for Render must never perform HTTP,
     * rotation, or event creation. Enforcement and its deterministic
     * tests land with the availability/admin tasks; this pin holds the
     * vocabulary those tests will drive.
     */
    public function testRenderContextIsTheReadOnlyContractCarrier(): void
    {
        $this->assertTrue(enum_exists(AvailabilityContext::class));
        $this->assertNotNull(AvailabilityContext::tryFrom('render'));
        $this->assertNotNull(AvailabilityContext::tryFrom('maintenance'));
    }
}
