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

    public function testBaseIsAbstractSoOnlySpecificTypesAreThrown(): void
    {
        $reflection = new \ReflectionClass(OAuthRuntimeException::class);
        $this->assertTrue($reflection->isAbstract());

        $this->expectException(\Error::class);
        new OAuthRuntimeException('never');
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

    public function testRateLimitAcceptsZeroButRejectsNegativeRetryAfter(): void
    {
        $zero = new OAuthRateLimitException('throttled', 0, null, 0);
        $this->assertSame(0, $zero->retry_after_seconds());

        $this->expectException(\InvalidArgumentException::class);
        new OAuthRateLimitException('throttled', 0, null, -1);
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
