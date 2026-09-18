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

    /**
     * OCR round 27 (t31-ocr27-5): the LABELS table and the cases stay
     * in sync in BOTH directions — a case added without its row once
     * surfaced only as the engine's own "Undefined array key" warning
     * plus a TypeError at label() time (loud, but naming neither the
     * enum nor the missing case nor the duty; the named-failure guard
     * in label() answers the value, the table, and the add-them-
     * together duty when a desync ever reaches it). The sync is
     * construction-evident here: a new case without a row fails THIS
     * test naming the case, and a row without a case fails naming the
     * orphan key.
     */
    public function testTheLabelTableAndTheCasesStayInSyncInBothDirections(): void
    {
        $caseValues = array();
        foreach (AvailabilityState::cases() as $state) {
            $caseValues[$state->value] = true;
            $this->assertArrayHasKey(
                $state->value,
                AvailabilityState::LABELS,
                sprintf('Every case carries its LABELS row — "%s" has none; add the case and the row together.', $state->value)
            );
        }

        foreach (array_keys(AvailabilityState::LABELS) as $rowKey) {
            $this->assertArrayHasKey(
                $rowKey,
                $caseValues,
                sprintf('Every LABELS row belongs to a case — "%s" is an orphan row; add the case and the row together.', $rowKey)
            );
        }
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
