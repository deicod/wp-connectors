<?php
/**
 * Contract tests: shared stored-grant VO and storage port (Task 3.1).
 *
 * Generation fencing primitives, state/token compatibility, immutable
 * transitions, and the storage interface contract exercised against the
 * in-memory fake.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

use Deicod\WpConnectors\Shared\Grant\GrantState;
use Deicod\WpConnectors\Shared\Grant\StoredGrant;
use Deicod\WpConnectors\Shared\Grant\TokenStorageInterface;
use Deicod\WpConnectors\Shared\Token\AccessTokenSet;

final class SharedOAuthContractsGrantTest extends WpConnectorsTestCase
{
    private function tokenSet(): AccessTokenSet
    {
        return new AccessTokenSet(
            FakeSecrets::accessToken(),
            FakeSecrets::refreshToken(),
            3600,
            new \DateTimeImmutable('2026-09-13T10:00:00+00:00')
        );
    }

    private function connectedGrant(): StoredGrant
    {
        return new StoredGrant('fixture-provider', 3, GrantState::Connected, $this->tokenSet());
    }

    /* ---------------------------------------------------------------
     * GrantState enum.
     * ---------------------------------------------------------------
     */

    public function testGrantStateCoversTheTerminalModel(): void
    {
        // Four persisted lifecycle states; transient failures are NOT a
        // state (a grant under cooldown stays Connected).
        $this->assertSame(
            array('connected', 'reconnect_required', 'configuration_error', 'revoked'),
            array_map(static function (GrantState $state): string {
                return $state->value;
            }, GrantState::cases())
        );
        $this->assertSame(GrantState::ReconnectRequired, GrantState::from('reconnect_required'));
        $this->assertNull(GrantState::tryFrom('unknown'));
    }

    /* ---------------------------------------------------------------
     * StoredGrant shape and validation.
     * ---------------------------------------------------------------
     */

    public function testValidGrantCarriesItsFacts(): void
    {
        $set = $this->tokenSet();
        $grant = new StoredGrant('fixture-provider', 7, GrantState::Connected, $set);

        $this->assertSame('fixture-provider', $grant->provider_id());
        $this->assertSame(7, $grant->generation());
        $this->assertSame(GrantState::Connected, $grant->state());
        $this->assertSame($set, $grant->token_set());
    }

    public function testEmptyProviderIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new StoredGrant('  ', 0, GrantState::ReconnectRequired, null);
    }

    public function testNegativeGenerationIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new StoredGrant('fixture-provider', -1, GrantState::ReconnectRequired, null);
    }

    public function testZeroGenerationIsAcceptable(): void
    {
        $grant = new StoredGrant('fixture-provider', 0, GrantState::ReconnectRequired, null);

        $this->assertSame(0, $grant->generation());
    }

    public function testConnectedRequiresATokenSet(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('connected grant requires a token set');

        new StoredGrant('fixture-provider', 0, GrantState::Connected, null);
    }

    public function testRevokedTombstoneForbidsTokens(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('revoked tombstone');

        new StoredGrant('fixture-provider', 0, GrantState::Revoked, $this->tokenSet());
    }

    public function testDeadGrantMayRetainItsTokenSetAsDiagnosticState(): void
    {
        // Terminal authorization failure: dead, but safe diagnostic state
        // (the token set) may be retained by the coordination task.
        $dead = new StoredGrant('fixture-provider', 2, GrantState::ReconnectRequired, $this->tokenSet());
        $deadWithoutTokens = new StoredGrant('fixture-provider', 2, GrantState::ReconnectRequired, null);

        $this->assertNotNull($dead->token_set());
        $this->assertNull($deadWithoutTokens->token_set());
    }

    public function testConfigurationErrorGrantMayRetainItsTokenSet(): void
    {
        // Third terminal class: retries suppressed until configuration
        // changes; the token set is retained.
        $grant = new StoredGrant('fixture-provider', 1, GrantState::ConfigurationError, $this->tokenSet());

        $this->assertSame(GrantState::ConfigurationError, $grant->state());
        $this->assertNotNull($grant->token_set());
    }

    /* ---------------------------------------------------------------
     * Immutable transitions.
     * ---------------------------------------------------------------
     */

    public function testWithTokenSetReplacesSetAndKeepsGenerationAndState(): void
    {
        $grant = $this->connectedGrant();
        $replacement = $this->tokenSet();

        $next = $grant->with_token_set($replacement);

        $this->assertNotSame($grant, $next);
        $this->assertSame($replacement, $next->token_set());
        $this->assertSame($grant->generation(), $next->generation());
        $this->assertSame($grant->state(), $next->state());
    }

    public function testWithTokenSetOnRevokedTombstoneIsRejected(): void
    {
        $tombstone = (new StoredGrant('fixture-provider', 4, GrantState::ReconnectRequired, null))
            ->revoke();

        $this->expectException(\InvalidArgumentException::class);

        $tombstone->with_token_set($this->tokenSet());
    }

    public function testWithStatePreservesTokensAndGeneration(): void
    {
        $grant = $this->connectedGrant();

        $dead = $grant->with_state(GrantState::ReconnectRequired);

        $this->assertSame(GrantState::ReconnectRequired, $dead->state());
        $this->assertSame($grant->token_set(), $dead->token_set());
        $this->assertSame($grant->generation(), $dead->generation());
        $this->assertSame(GrantState::Connected, $grant->state());
    }

    public function testWithStateToConnectedWithoutTokensIsRejected(): void
    {
        $grant = new StoredGrant('fixture-provider', 0, GrantState::ReconnectRequired, null);

        $this->expectException(\InvalidArgumentException::class);

        $grant->with_state(GrantState::Connected);
    }

    public function testWithStateToRevokedWithTokensIsRejectedUseRevokeInstead(): void
    {
        $grant = $this->connectedGrant();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('revoke()');

        $grant->with_state(GrantState::Revoked);
    }

    /**
     * Review-round pin: the transition must not mint an UN-advanced
     * tombstone even from a token-less grant — the advanced generation
     * is the fence a late refresh/exchange commits against, and only
     * revoke() produces one.
     */
    public function testWithStateToRevokedOnTokenlessGrantIsRejectedToo(): void
    {
        $grant = new StoredGrant('fixture-provider', 5, GrantState::ReconnectRequired, null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('revoke()');

        $grant->with_state(GrantState::Revoked);
    }

    public function testWithGenerationOnlyMovesForward(): void
    {
        $grant = $this->connectedGrant();
        $this->assertSame(3, $grant->generation());

        $advanced = $grant->with_generation(4);
        $this->assertSame(4, $advanced->generation());
        $this->assertSame(3, $grant->generation());

        try {
            $grant->with_generation(3);
            $this->fail('An equal generation must be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('forward', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $grant->with_generation(2);
    }

    public function testRevokeProducesTheFencingTombstone(): void
    {
        $grant = $this->connectedGrant();

        $tombstone = $grant->revoke();

        $this->assertSame(GrantState::Revoked, $tombstone->state());
        $this->assertNull($tombstone->token_set());
        // The generation advances — the fence a late refresh or exchange
        // commits against.
        $this->assertSame(4, $tombstone->generation());
        $this->assertSame(3, $grant->generation());
        $this->assertNotNull($grant->token_set());
    }

    /**
     * Review-round pin (t31-r1-11): revoke() did an unguarded
     * generation + 1 — at PHP_INT_MAX (constructible via
     * with_generation()) the int overflowed to float and the engine
     * threw a TypeError from the constructor instead of the documented
     * rejection. The tombstone is unrepresentable there, and the class
     * says so with its own typed rejection.
     */
    public function testRevokeAtMaxGenerationRejectsInsteadOfOverflowing(): void
    {
        $grant = (new StoredGrant('fixture-provider', 0, GrantState::ReconnectRequired, null))
            ->with_generation(PHP_INT_MAX - 1);

        // One below the boundary revokes normally.
        $this->assertSame(PHP_INT_MAX, $grant->revoke()->generation());

        $maxed = $grant->with_generation(PHP_INT_MAX);
        $this->assertSame(PHP_INT_MAX, $maxed->generation());

        try {
            $maxed->revoke();
            $this->fail('revoke() at PHP_INT_MAX must reject with the documented type, never overflow to a float TypeError.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('PHP_INT_MAX', $e->getMessage());
        }
    }

    public function testGrantVoIsImmutableWithNoSetters(): void
    {
        $reflection = new \ReflectionClass(StoredGrant::class);
        $this->assertTrue($reflection->isFinal());
        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly(), StoredGrant::class . '::' . $property->getName() . ' must be readonly.');
            $this->assertFalse($property->isStatic());
        }
    }

    /* ---------------------------------------------------------------
     * Storage interface contract (against the in-memory fake).
     * ---------------------------------------------------------------
     */

    public function testStoragePortShapeIsFixed(): void
    {
        $this->assertTrue(interface_exists(TokenStorageInterface::class));
        $reflection = new \ReflectionClass(TokenStorageInterface::class);

        $this->assertSame(
            array('load', 'save', 'delete'),
            array_map(static function (\ReflectionMethod $method): string {
                return $method->getName();
            }, $reflection->getMethods())
        );

        // Review-round pin (t31-r1-13): save() carries the generation
        // precondition and reports the fence verdict as a bool — the
        // widened shape 3.2's envelope and 3.3's coordination ride.
        $save = new \ReflectionMethod(TokenStorageInterface::class, 'save');
        $this->assertSame(3, $save->getNumberOfParameters());
        $this->assertSame('bool', (string) $save->getReturnType());
        $this->assertSame(-1, TokenStorageInterface::EXPECT_NO_GRANT);
    }

    public function testStorageRoundTripAndAtomicReplacement(): void
    {
        $storage = new InMemoryTokenStorage();
        $first = $this->connectedGrant();

        $this->assertNull($storage->load('fixture-provider'));

        $this->assertTrue($storage->save('fixture-provider', $first, TokenStorageInterface::EXPECT_NO_GRANT));
        $this->assertSame($first, $storage->load('fixture-provider'));

        // Atomic replace on the observed generation: only the newest
        // grant is ever visible.
        $second = $first->with_token_set($this->tokenSet())->with_generation(4);
        $this->assertTrue($storage->save('fixture-provider', $second, 3));
        $this->assertSame($second, $storage->load('fixture-provider'));
        $this->assertSame(2, $storage->saveCount('fixture-provider'));
    }

    /**
     * Review-round pin (t31-r1-13): the port's save is a
     * generation-checked commit — Task 3.3's "a fencing generation
     * checked before every refresh commit", fixed into the port BEFORE
     * the envelope and coordination tasks pin the three-method shape.
     * A late writer whose observed generation has been superseded
     * commits NOTHING (the finding's shape: a refresh returning after
     * a revoke must not silently reconnect the provider).
     */
    public function testStorageSaveIsFencedOnThePersistedGeneration(): void
    {
        $storage = new InMemoryTokenStorage();
        $first = $this->connectedGrant(); // generation 3

        $this->assertTrue($storage->save('fixture-provider', $first, TokenStorageInterface::EXPECT_NO_GRANT));

        // The revoke advances the persisted generation to 4.
        $tombstone = $first->revoke();
        $this->assertTrue($storage->save('fixture-provider', $tombstone, 3));

        // The late refresh (still holding generation-3 facts and a
        // fresh token set) is fenced off: nothing commits.
        $late = $first->with_token_set($this->tokenSet());
        $this->assertFalse($storage->save('fixture-provider', $late, 3));

        // The tombstone stands — no tokens resurrected, and the fenced
        // attempt does not count as a commit.
        $persisted = $storage->load('fixture-provider');
        $this->assertSame(GrantState::Revoked, $persisted->state());
        $this->assertNull($persisted->token_set());
        $this->assertSame(2, $storage->saveCount('fixture-provider'));
    }

    public function testAbsentExpectationIsFencedOffByAnyPersistedGrant(): void
    {
        $storage = new InMemoryTokenStorage();

        $this->assertTrue($storage->save('fixture-provider', $this->connectedGrant(), TokenStorageInterface::EXPECT_NO_GRANT));
        $this->assertFalse($storage->save('fixture-provider', $this->connectedGrant(), TokenStorageInterface::EXPECT_NO_GRANT));
        $this->assertSame(1, $storage->saveCount('fixture-provider'));
    }

    public function testStorageIsKeyedPerProvider(): void
    {
        $storage = new InMemoryTokenStorage();
        $grant = $this->connectedGrant();

        $storage->save('fixture-provider', $grant, TokenStorageInterface::EXPECT_NO_GRANT);

        $this->assertNull($storage->load('another-provider'));
        $this->assertSame($grant, $storage->load('fixture-provider'));
    }

    public function testStorageDeleteRemovesAndIsANoopWhenAbsent(): void
    {
        $storage = new InMemoryTokenStorage();

        $storage->delete('fixture-provider');
        $this->assertNull($storage->load('fixture-provider'));

        $storage->save('fixture-provider', $this->connectedGrant(), TokenStorageInterface::EXPECT_NO_GRANT);
        $storage->delete('fixture-provider');
        $this->assertNull($storage->load('fixture-provider'));
    }
}
