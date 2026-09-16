<?php
/**
 * Contract tests: shared OAuth typed-error hierarchy (Task 3.1).
 *
 * Taxonomy pins: every concrete type is catchable as the base, transient
 * vs terminal vs configuration is distinguishable by type alone, the
 * rate-limit type exposes parsed Retry-After seconds, and the family
 * exposes no API that could carry raw provider payloads or credentials.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

use Deicod\WpConnectors\Shared\Exception\OAuthConfigurationException;
use Deicod\WpConnectors\Shared\Exception\OAuthMalformedResponseException;
use Deicod\WpConnectors\Shared\Exception\OAuthRateLimitException;
use Deicod\WpConnectors\Shared\Exception\OAuthRuntimeException;
use Deicod\WpConnectors\Shared\Exception\OAuthStorageException;
use Deicod\WpConnectors\Shared\Exception\OAuthTerminalAuthException;
use Deicod\WpConnectors\Shared\Exception\OAuthTransientException;
use Deicod\WpConnectors\Shared\Exception\OAuthTransportException;

final class SharedOAuthContractsErrorsTest extends WpConnectorsTestCase
{
    /**
     * @return list<string> Class names of every concrete exception type.
     */
    private function concreteTypes(): array
    {
        return array(
            OAuthTransportException::class,
            OAuthRateLimitException::class,
            OAuthTerminalAuthException::class,
            OAuthConfigurationException::class,
            OAuthStorageException::class,
            OAuthMalformedResponseException::class,
        );
    }

    public function testEveryConcreteTypeIsCatchableAsTheBase(): void
    {
        foreach ($this->concreteTypes() as $type) {
            $thrown = new $type('fixture message');
            $this->assertInstanceOf(OAuthRuntimeException::class, $thrown, $type . ' must extend the shared base.');
            $this->assertInstanceOf(\RuntimeException::class, $thrown, $type . ' must remain a RuntimeException for generic handlers.');
        }
    }

    /**
     * OCR-round-5 pin (t31-ocr5-5): concreteTypes() is a hardcoded
     * list — a seventh type added to shared/src/Exception/ would
     * silently escape every family pin (catchability, finality, the
     * payload API). The list is pinned against the DIRECTORY it
     * summarizes: the glob's class names must equal the concrete list
     * plus the two named family anchors (the abstract base, the
     * transient marker) — the family grows only by growing both
     * together, and a stray file in the directory fails the pin too.
     */
    public function testTheConcreteTypeListCoversTheWholeExceptionDirectory(): void
    {
        $from_tree = array();
        foreach (glob(dirname(__DIR__) . '/shared/src/Exception/*.php') ?: array() as $file) {
            $from_tree[] = 'Deicod\\WpConnectors\\Shared\\Exception\\' . basename($file, '.php');
        }
        $listed = array_merge($this->concreteTypes(), array(OAuthRuntimeException::class, OAuthTransientException::class));
        sort($from_tree);
        sort($listed);

        $this->assertSame($from_tree, $listed, 'concreteTypes() must cover every type in shared/src/Exception/ — a new file there grows the list or fails this pin.');
    }

    public function testBaseIsAbstractSoOnlySpecificTypesAreThrown(): void
    {
        $reflection = new \ReflectionClass(OAuthRuntimeException::class);
        $this->assertTrue($reflection->isAbstract());

        $this->expectException(\Error::class);
        new OAuthRuntimeException('never');
    }

    /**
     * OCR-round-2 pin (t31-ocr2-9): every concrete type is FINAL.
     * Finality is the fence that makes "is this retryable?" answerable
     * by type alone: a subclass of a non-final TERMINAL type that
     * implements OAuthTransientException would satisfy BOTH the
     * marker check (instanceof OAuthTransientException) and the
     * terminal catch arm (instanceof OAuthTerminalAuthException) —
     * two contradictory reactions to one thrown object, with no
     * type-level way to pick. The taxonomy tests above pin the
     * current six; this pin holds the fence itself (it fails the
     * moment a concrete type loses `final`, whatever its name).
     */
    public function testEveryConcreteExceptionTypeIsFinal(): void
    {
        foreach ($this->concreteTypes() as $type) {
            $reflection = new \ReflectionClass($type);
            $this->assertTrue(
                $reflection->isFinal(),
                $type . ' must be final — a subclass could implement the transient marker beside its terminal catch arm, and "is this retryable?" would stop being answerable by type alone.'
            );
        }

        // The fence's other post: the marker stays an INTERFACE — an
        // uninstantiable, un-extended type tag, not a class a
        // subclass could descend from.
        $marker = new \ReflectionClass(OAuthTransientException::class);
        $this->assertTrue($marker->isInterface());
        $this->assertSame(array(), $marker->getInterfaceNames(), 'The transient marker extends nothing — it is a bare tag.');
    }

    public function testTransientPairIsDistinguishableByTypeAlone(): void
    {
        $transient = array(
            new OAuthTransportException('t'),
            new OAuthRateLimitException('t'),
        );
        foreach ($transient as $exception) {
            $this->assertInstanceOf(OAuthTransientException::class, $exception);
        }
    }

    public function testTerminalClassesAreNotTransient(): void
    {
        $notTransient = array(
            new OAuthTerminalAuthException('t'),
            new OAuthConfigurationException('t'),
            new OAuthStorageException('t'),
            new OAuthMalformedResponseException('t'),
        );
        foreach ($notTransient as $exception) {
            $this->assertNotInstanceOf(OAuthTransientException::class, $exception);
        }
    }

    public function testEachTerminalClassIsSeparatelyCatchable(): void
    {
        // Terminal auth, configuration, storage, malformed: four distinct
        // catch arms, each reachable by type alone.
        $this->assertNotInstanceOf(OAuthConfigurationException::class, new OAuthTerminalAuthException('t'));
        $this->assertNotInstanceOf(OAuthTerminalAuthException::class, new OAuthConfigurationException('t'));
        $this->assertNotInstanceOf(OAuthStorageException::class, new OAuthMalformedResponseException('t'));
        $this->assertNotInstanceOf(OAuthMalformedResponseException::class, new OAuthStorageException('t'));
    }

    public function testRateLimitExposesParsedRetryAfterSeconds(): void
    {
        $withValue = new OAuthRateLimitException('throttled', 0, null, 37);
        $withoutValue = new OAuthRateLimitException('throttled');

        $this->assertSame(37, $withValue->retry_after_seconds());
        $this->assertNull($withoutValue->retry_after_seconds());
    }

    /**
     * OCR-round-2 pin (t31-ocr2-7): the input contract and the
     * constructor agreed on nothing for negative Retry-After — the
     * property docblock said both parser forms "land here as seconds"
     * (and the policy documents/tests negative clamping, implying
     * negatives flow from parsers) while the constructor REJECTED
     * them. One truth now, the parser reality: a negative delta is
     * meaningless but a parser can emit one (a header spelling the
     * past), so it clamps to zero — "retry immediately" — while null
     * stays "the provider sent none", the two facts distinct. Pinned
     * beside the policy's own clamp pin: one rule, both sides.
     */
    public function testRateLimitClampsNegativeRetryAfterToZero(): void
    {
        $zero = new OAuthRateLimitException('throttled', 0, null, 0);
        $this->assertSame(0, $zero->retry_after_seconds());

        $this->assertSame(0, (new OAuthRateLimitException('throttled', 0, null, -1))->retry_after_seconds(), 'A parser-emitted negative clamps to zero — retry immediately.');
        $this->assertSame(0, (new OAuthRateLimitException('throttled', 0, null, PHP_INT_MIN))->retry_after_seconds(), 'Even the extreme negative spelling clamps to the same zero.');

        // Null stays a DISTINCT fact: the provider sent none.
        $this->assertNull((new OAuthRateLimitException('throttled'))->retry_after_seconds());

        // The policy side states the same rule (pinned in the policy
        // suite too — one truth, both sides).
        $this->assertSame(0, (new \Deicod\WpConnectors\Shared\Policy\RefreshPolicy(0, 1, 60))->capped_retry_after_seconds(-5));
    }

    /**
     * The family must never grow an API that carries raw provider payloads
     * or credentials: the only additions over the standard exception API
     * are the rate-limit's parsed Retry-After seconds.
     */
    public function testExceptionTypesDeclareNoPayloadOrCredentialCarryingApi(): void
    {
        $allowed = array('retry_after_seconds');

        foreach ($this->concreteTypes() as $type) {
            $reflection = new \ReflectionClass($type);
            $own = array();
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() === $type
                    && !$method->isStatic()
                    && '__construct' !== $method->getName()) {
                    $own[] = $method->getName();
                }
            }
            $extra = array_values(array_diff($own, $allowed));
            $this->assertSame(
                array(),
                $extra,
                $type . ' declares unexpected own methods: ' . implode(', ', $extra)
            );
        }

        // The transient marker stays an empty marker.
        $this->assertSame(array(), (new \ReflectionClass(OAuthTransientException::class))->getMethods());
    }

    public function testRateLimitCarriesPreviousExceptionAndStandardExceptionBehavior(): void
    {
        $previous = new OAuthTransportException('connection refused');
        $exception = new OAuthRateLimitException('throttled', 429, $previous, 12);

        $this->assertSame(429, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
        $this->assertSame('throttled', $exception->getMessage());
    }
}
