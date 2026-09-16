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
     * summarizes: the tree's class names must equal the concrete list
     * plus the two named family anchors (the abstract base, the
     * transient marker) — the family grows only by growing both
     * together, and a stray file in the directory fails the pin too.
     *
     * OCR round 6 (t31-ocr6-13): the derivation is RECURSIVE now —
     * the round-5 glob was flat, so the same escape it killed
     * reopened one level down: a type in a future Exception/
     * SUBDIRECTORY (PSR-4 maps it; the one-type-per-file gate's file
     * set covers it) would miss the flat glob and slip every family
     * pin while the pin's own message claimed to cover the whole
     * directory. Still a pure FILE-SET pin composing with the
     * one-type-per-file gate (the r5 adjudication stands: no
     * tokenizing the Exception files) — the walk only names files,
     * the PSR-4 spelling comes from the path.
     */
    public function testTheConcreteTypeListCoversTheWholeExceptionDirectory(): void
    {
        $dir = dirname(__DIR__) . '/shared/src/Exception';
        $base = strlen($dir) + 1;
        $from_tree = array();
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile() || 'php' !== strtolower($file->getExtension())) {
                continue;
            }
            $from_tree[] = 'Deicod\\WpConnectors\\Shared\\Exception\\' . str_replace('/', '\\', substr($file->getPathname(), $base, -4));
        }
        $listed = array_merge($this->concreteTypes(), array(OAuthRuntimeException::class, OAuthTransientException::class));
        sort($from_tree);
        sort($listed);

        $this->assertSame($from_tree, $listed, 'concreteTypes() must cover every type anywhere under shared/src/Exception/ — a new file there, at any depth, grows the list or fails this pin.');
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
     *
     * OCR-round-5 widening (t31-ocr5-6): the audit covers INHERITED
     * public methods too — the declaring-class filter let a
     * base-class raw_payload() pass invisible (the docblock contract is
     * the FAMILY's API, not each type's own). The standard baseline is
     * derived from the engine's own \Exception reflection, so the allow
     * set never drifts with PHP versions.
     */
    public function testExceptionTypesDeclareNoPayloadOrCredentialCarryingApi(): void
    {
        $allowed = array('retry_after_seconds');
        foreach ((new \ReflectionClass(\Exception::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $standard) {
            if (! $standard->isStatic()) {
                $allowed[] = $standard->getName();
            }
        }

        foreach ($this->concreteTypes() as $type) {
            $api = array();
            foreach ((new \ReflectionClass($type))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (! $method->isStatic() && '__construct' !== $method->getName()) {
                    $api[] = $method->getName();
                }
            }
            $extra = array_values(array_diff($api, $allowed));
            $this->assertSame(
                array(),
                $extra,
                $type . ' exposes unexpected API (own or inherited): ' . implode(', ', $extra)
            );

            /*
             * OCR-round-8 pin (t31-ocr8-5): the name diff above is
             * NAME-based — a concrete type REDECLARING an allowed name
             * (a future __toString override appending the raw provider
             * body) wore an allowed spelling and passed invisible.
             * The DECLARING CLASS decides now: a non-static public
             * method is either the engine's own (declared outside the
             * family, un-overridden standard behavior) or one of the
             * family's allowed additions (__construct,
             * retry_after_seconds). Anything else the family declares
             * — a concrete override, a base method beyond the allow
             * set — is the payload channel and fails.
             */
            $family_additions = array('__construct', 'retry_after_seconds');
            foreach ((new \ReflectionClass($type))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic()) {
                    continue;
                }
                $declared_by = $method->getDeclaringClass()->getName();
                if (0 !== strpos($declared_by, 'Deicod\\WpConnectors\\')) {
                    continue;
                }
                $this->assertContains(
                    $method->getName(),
                    $family_additions,
                    $type . ' carries ' . $method->getName() . ' declared by ' . $declared_by . ' — the family contract is the base behavior; a family-declared override is the payload channel.'
                );
            }
        }

        // The transient marker stays an empty marker.
        $this->assertSame(array(), (new \ReflectionClass(OAuthTransientException::class))->getMethods());

        /*
         * OCR-round-8 pin (t31-ocr8-6): the audit above walks METHODS
         * only — a public PROPERTY carrying raw payload passed it
         * entirely. The family's API is methods-only; no concrete type
         * (nor anything it inherits) may expose a public property.
         */
        foreach ($this->concreteTypes() as $type) {
            $props = (new \ReflectionClass($type))->getProperties(\ReflectionProperty::IS_PUBLIC);
            $this->assertSame(
                array(),
                $props,
                $type . ' must expose no public properties — the family\'s API is methods-only; a public property is a payload channel the method audit never saw.'
            );

            /*
             * OCR-round-8 verifier-pass pin (t31-ocr8-14): the IS_PUBLIC
             * probe sees nothing of a PROTECTED or PRIVATE property —
             * and print_r()/var_export()/serialize() dump those RAW
             * (driven with a planted protected string), the exact
             * channel this audit fences; the methods-only pin even
             * BLOCKED the family's own mitigation (a __debugInfo()
             * override would fail the declaring-class check above).
             * The family's data carrier is ONE parsed int, declared
             * once; every property the FAMILY declares — any
             * visibility, inherited included — must be that one. A new
             * declared property of any visibility is a payload
             * candidate and fails here, consciously.
             */
            $family_properties = array('retry_after_seconds');
            $declared = array();
            foreach ((new \ReflectionClass($type))->getProperties() as $property) {
                if (0 === strpos($property->getDeclaringClass()->getName(), 'Deicod\\WpConnectors\\')) {
                    $declared[] = $property->getName();
                }
            }
            $this->assertSame(
                array(),
                array_values(array_diff($declared, $family_properties)),
                $type . ' declares unexpected properties (' . implode(', ', $declared) . ') — the family\'s carrier is the one parsed int; print_r()/var_export() dump non-public properties raw, so a new declared property of any visibility is a payload candidate.'
            );
        }
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
