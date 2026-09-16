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
        return StoredGrant::in_state('fixture-provider', 3, GrantState::Connected, $this->tokenSet());
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
        $grant = StoredGrant::in_state('fixture-provider', 7, GrantState::Connected, $set);

        $this->assertSame('fixture-provider', $grant->provider_id());
        $this->assertSame(7, $grant->generation());
        $this->assertSame(GrantState::Connected, $grant->state());
        $this->assertSame($set, $grant->token_set());
    }

    public function testEmptyProviderIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StoredGrant::in_state('  ', 0, GrantState::ReconnectRequired, null);
    }

    /**
     * Fix-round pin (t31-r13-2): the stored grant's provider label is
     * the free-text string PendingAuthorization already guards — with
     * only the non-empty screen it constructed, and print_r() of the
     * grant forged a line BESIDE THE MASKED TOKEN SET (reproduced: a
     * '\n'-bearing provider id rendered its own line in the safe
     * debug form, the exact forged-log-line channel the r12-5 guard
     * closed on the pending flow's label). The constructor joins the
     * SAME guard — one callable at the vocabulary owner, no copy —
     * and every public spelling (in_state, the immutable transitions,
     * revoke()) funnels through it, so no produced grant carries the
     * channel; the exact legal labels stay green.
     */
    public function testControlBytesInTheProviderIdRefuseLoudly(): void
    {
        $hostile_ids = array(
            'CRLF-bearing label' => "zai\r\nAuthorization: Bearer x",
            'newline-bearing label' => "zai\ninjected: [GENERATION 3 TOKENS LIVE]",
            'NUL-bearing label' => "za\x00i",
            'C1 NEL spelling' => "zai\xC2\x85",
            'U+2028 spelling' => "zai\xE2\x80\xA8",
        );
        foreach ($hostile_ids as $label => $provider_id) {
            try {
                StoredGrant::in_state($provider_id, 3, GrantState::Connected, $this->tokenSet());
                $this->fail("A control-byte-bearing provider id ({$label}) must refuse.");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('must not contain control characters', $e->getMessage());
            }
        }

        // The exact legal labels stay green — the plain fixture
        // spelling included — and the safe debug form of a legal grant
        // carries no forged-line material.
        $grant = StoredGrant::in_state('fixture-provider', 3, GrantState::Connected, $this->tokenSet());
        $this->assertSame('fixture-provider', $grant->provider_id());
        $this->assertStringNotContainsString("\r", print_r($grant, true), 'The legal dump carries no carriage return — no forged line material.');
    }

    public function testNegativeGenerationIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StoredGrant::in_state('fixture-provider', -1, GrantState::ReconnectRequired, null);
    }

    public function testZeroGenerationIsAcceptable(): void
    {
        $grant = StoredGrant::in_state('fixture-provider', 0, GrantState::ReconnectRequired, null);

        $this->assertSame(0, $grant->generation());
    }

    public function testConnectedRequiresATokenSet(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('connected grant requires a token set');

        StoredGrant::in_state('fixture-provider', 0, GrantState::Connected, null);
    }

    /**
     * Fix-round pin (t31-r2-3): the tombstone invariant is structural —
     * no public spelling constructs a Revoked grant, because only
     * revoke() can guarantee the generation ADVANCES (the fence an
     * in-flight refresh CAS-commits against; the finding's shape — a
     * constructor-minted tombstone at an un-advanced generation —
     * let a late refresh commit over the revoke).
     */
    public function testARevokedTombstoneIsOnlyEverMintedByRevoke(): void
    {
        // The constructor is private: revoke() and the immutable
        // transitions are its only callers.
        $constructor = (new \ReflectionClass(StoredGrant::class))->getConstructor();
        $this->assertTrue($constructor->isPrivate(), 'The StoredGrant constructor must be private — a tombstone must not be publicly constructible.');

        // The named constructor — the public construction entry —
        // rejects the tombstone state with the class's typed exception,
        // with or without a token set.
        foreach (array(null, $this->tokenSet()) as $token_set) {
            try {
                StoredGrant::in_state('fixture-provider', 3, GrantState::Revoked, $token_set);
                $this->fail('A Revoked grant must not be constructible through the public entry point.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('revoke()', $e->getMessage());
            }
        }

        // revoke() remains the producer, and its tombstone is advanced.
        $tombstone = $this->connectedGrant()->revoke();
        $this->assertSame(GrantState::Revoked, $tombstone->state());
        $this->assertSame(4, $tombstone->generation());
    }

    /**
     * The adjudicated tolerance (t31-r2-3), pinned honestly: a
     * tombstone may transition BACK to a live state at the same
     * generation — fence-neutral (no in-flight writer's commit verdict
     * changes; the CAS reads the generation, which is preserved), and
     * terminality is the Task 3.3 coordinator's policy, not the VO's.
     */
    public function testATombstoneMayTransitionBackAtTheSameGenerationForNow(): void
    {
        $tombstone = StoredGrant::in_state('fixture-provider', 4, GrantState::ReconnectRequired, null)->revoke();

        $revived = $tombstone->with_state(GrantState::ReconnectRequired);

        $this->assertSame(GrantState::ReconnectRequired, $revived->state());
        $this->assertSame($tombstone->generation(), $revived->generation());
        $this->assertSame(GrantState::Revoked, $tombstone->state());
    }

    public function testDeadGrantMayRetainItsTokenSetAsDiagnosticState(): void
    {
        // Terminal authorization failure: dead, but safe diagnostic state
        // (the token set) may be retained by the coordination task.
        $dead = StoredGrant::in_state('fixture-provider', 2, GrantState::ReconnectRequired, $this->tokenSet());
        $deadWithoutTokens = StoredGrant::in_state('fixture-provider', 2, GrantState::ReconnectRequired, null);

        $this->assertNotNull($dead->token_set());
        $this->assertNull($deadWithoutTokens->token_set());
    }

    public function testConfigurationErrorGrantMayRetainItsTokenSet(): void
    {
        // Third terminal class: retries suppressed until configuration
        // changes; the token set is retained.
        $grant = StoredGrant::in_state('fixture-provider', 1, GrantState::ConfigurationError, $this->tokenSet());

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
        $tombstone = (StoredGrant::in_state('fixture-provider', 4, GrantState::ReconnectRequired, null))
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
        $grant = StoredGrant::in_state('fixture-provider', 0, GrantState::ReconnectRequired, null);

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
        $grant = StoredGrant::in_state('fixture-provider', 5, GrantState::ReconnectRequired, null);

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
        $grant = (StoredGrant::in_state('fixture-provider', 0, GrantState::ReconnectRequired, null))
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
        $loaded = $storage->load('fixture-provider');

        /*
         * Fix-round pin (t31-r9-5): the round trip is by VALUE, and
         * identity never survives the storage boundary — a decrypting
         * (real) adapter reconstructs the object graph from persisted
         * bytes on every load, so the old assertSame pins asked for
         * semantics no real implementation can honor (they passed only
         * against the fake's alias). The fake round-trips a copy now;
         * the pins below hold against every implementation.
         */
        $this->assertNotSame($first, $loaded, 'A loaded grant is a reconstructed instance, never the stored one.');
        $this->assertNotSame($storage->load('fixture-provider'), $storage->load('fixture-provider'), 'Every load reconstructs — two loads never share an instance either.');
        $this->assertSame($first->provider_id(), $loaded->provider_id());
        $this->assertSame($first->generation(), $loaded->generation());
        $this->assertSame($first->state(), $loaded->state());
        $this->assertSame($first->token_set()->to_array(), $loaded->token_set()->to_array(), 'The token set round-trips exactly (the storage serialization is the compare).');

        // Atomic replace on the observed generation: only the newest
        // grant is ever visible.
        $second = $first->with_token_set($this->tokenSet())->with_generation(4);
        $this->assertTrue($storage->save('fixture-provider', $second, 3));
        $replaced = $storage->load('fixture-provider');
        $this->assertNotSame($second, $replaced);
        $this->assertSame(4, $replaced->generation());
        $this->assertSame($second->token_set()->to_array(), $replaced->token_set()->to_array());
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

    /**
     * OCR-round-2 pin (t31-ocr2-3): the CAS fence's monotonicity leg.
     * The equality-only fence judged PERSISTED === EXPECTED and never
     * asked whether the GRANT ITSELF was stale — the finding's repro:
     * a stale Connected grant at generation 3 saved with expected:4
     * over a persisted Revoked tombstone at 4 PASSED (4 === 4), the
     * persisted fence regressed to 3, and the revoked tokens
     * resurrected. Every legitimate commit satisfies
     * grant.generation() >= expected, so the contract (and the
     * reference fake) REJECT a lower-generation grant loudly, nothing
     * committed — the same typed-caller-bug class the provider
     * identity rule rides, never a silent false that a caller could
     * mistake for a fence verdict it could retry.
     */
    public function testASaveWhoseGrantIsStalerThanItsExpectationIsRejected(): void
    {
        $storage = new InMemoryTokenStorage();
        $first = $this->connectedGrant(); // generation 3

        $this->assertTrue($storage->save('fixture-provider', $first, TokenStorageInterface::EXPECT_NO_GRANT));
        $tombstone = $first->revoke(); // generation 4
        $this->assertTrue($storage->save('fixture-provider', $tombstone, 3));

        // The repro: the stale grant (3) under an expectation (4) the
        // persisted tombstone satisfies — equality passes, the fence
        // would regress, so the save REFUSES.
        try {
            $storage->save('fixture-provider', $first, 4);
            $this->fail('A grant staler than its own expectation must be rejected, never committed over the newer fence.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('must be at least the expected generation', $e->getMessage());
        }

        // Nothing committed: the tombstone stands, no tokens resurrected,
        // and the rejected save is no commit.
        $persisted = $storage->load('fixture-provider');
        $this->assertSame(GrantState::Revoked, $persisted->state());
        $this->assertSame(4, $persisted->generation());
        $this->assertNull($persisted->token_set());
        $this->assertSame(2, $storage->saveCount('fixture-provider'));

        /*
         * The legitimate legs stay green: EQUAL (the refresh commit —
         * same generation, new token set) and HIGHER (the advanced
         * generation a rotation or revoke rides).
         */
        $storage = new InMemoryTokenStorage();
        $grant = $this->connectedGrant(); // generation 3
        $this->assertTrue($storage->save('fixture-provider', $grant, TokenStorageInterface::EXPECT_NO_GRANT));
        $this->assertTrue(
            $storage->save('fixture-provider', $grant->with_token_set($this->tokenSet()), 3),
            'An equal-generation commit is the legitimate refresh shape.'
        );
        $this->assertTrue(
            $storage->save('fixture-provider', $grant->with_generation(4), 3),
            'A higher-generation commit is the legitimate rotation shape.'
        );
        $this->assertSame(3, $storage->saveCount('fixture-provider'));
    }

    public function testStorageIsKeyedPerProvider(): void
    {
        $storage = new InMemoryTokenStorage();
        $grant = $this->connectedGrant();

        $storage->save('fixture-provider', $grant, TokenStorageInterface::EXPECT_NO_GRANT);

        $this->assertNull($storage->load('another-provider'));
        // By value, not identity (t31-r9-5: load reconstructs).
        $loaded = $storage->load('fixture-provider');
        $this->assertNotSame($grant, $loaded);
        $this->assertSame($grant->provider_id(), $loaded->provider_id());
        $this->assertSame($grant->generation(), $loaded->generation());
    }

    /**
     * OCR-round-1 pin (t31-ocr1-7): save()'s provider-identity rule —
     * the $provider_id parameter is the storage key and MUST equal the
     * grant's own provider_id(). The reference fake used to key blindly
     * by parameter, so save('provider-a', $grantForProviderB) silently
     * installed B's grant under A's slot — the exact shape the port's
     * contract (Task 3.2's adapters implement against it) now forbids:
     * implementations REJECT the mismatch, never silently persist. The
     * fake throws the typed caller-bug rejection and commits nothing
     * under either key.
     */
    public function testAMismatchedProviderSaveIsRejectedLoudly(): void
    {
        $storage = new InMemoryTokenStorage();
        $grant = $this->connectedGrant(); // labeled 'fixture-provider'

        try {
            $storage->save('another-provider', $grant, TokenStorageInterface::EXPECT_NO_GRANT);
            $this->fail('A save whose storage key disagrees with the grant\'s label must be rejected, never silently installed under the wrong slot.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('must agree', $e->getMessage());
        }

        $this->assertNull($storage->load('another-provider'), 'The mismatched save committed nothing under the wrong key.');
        $this->assertNull($storage->load($grant->provider_id()), 'The mismatched save committed nothing under the grant\'s own key either.');
        $this->assertSame(0, $storage->saveCount('another-provider'), 'The rejected save is no commit.');

        /*
         * Verifier-round leg (t31-ocr1-13): the caller-controlled key
         * rides the ONE control-byte guard before it rides any
         * message — a '\n'-bearing key rejects at the screen (the
         * r13-2 class: an unscreened key reached the mismatch
         * rejection's sprintf raw and would forge a line beside it).
         */
        try {
            $storage->save("forged\nprovider", $grant, TokenStorageInterface::EXPECT_NO_GRANT);
            $this->fail('A control-bearing storage key must reject at the screen, never reach the message.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString("\n", $e->getMessage(), 'The rejection message carries no forged line.');
        }
        // No forged-key load probe here: load() screens its own key now
        // (t31-ocr6-2, pinned in its own test below) — the
        // committed-nothing fact rides the clean-key loads and the
        // saveCount above.
    }

    /**
     * OCR-round-6 pin (t31-ocr6-2): load() and delete() accept the same
     * caller-controlled $provider_id save() does, and the port's
     * contract screens the key on ALL THREE methods — a Task-3.2
     * adapter embedding the key in an OAuthStorageException message on
     * a failed load or delete reopens the forged-log-line class the
     * save screen closed (t31-ocr1-13). The reference fake rides one
     * screen owner (screen_key()) at three call sites; the pin drives
     * each reader method with the same forged key the save pin uses.
     */
    public function testLoadAndDeleteScreenTheirKeysLikeSaveDoes(): void
    {
        $storage = new InMemoryTokenStorage();
        $storage->save('fixture-provider', $this->connectedGrant(), TokenStorageInterface::EXPECT_NO_GRANT);

        foreach (array('load' => $storage->load(...), 'delete' => $storage->delete(...)) as $method => $call) {
            try {
                $call("forged\nprovider");
                $this->fail(ucfirst($method) . '() must screen its key — the same caller-controlled string save() refuses, never a silent no-op.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('must not contain control characters', $e->getMessage(), "{$method}() refuses with the same screen shape as save().");
                $this->assertStringNotContainsString("\n", $e->getMessage(), 'The rejection message carries no forged line.');
            }
        }

        $this->assertNotNull($storage->load('fixture-provider'), 'The screened reader calls touched no persisted grant.');
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

    /**
     * Verifier-round pin (t31-r11-5): the SERIALIZATION channel rides
     * the redaction contract. The grant's secret material lives in its
     * token set; without __debugInfo() the engine dumped the raw
     * property tree — both tokens in full through the nested set
     * (reproduced pre-fix). The grant's dump renders the public facts
     * and reaches the tokens only through the set's own masked dump —
     * one vocabulary, no second masking decision to drift.
     */
    public function testTheSerializationChannelDumpsTheGrantWithMaskedTokens(): void
    {
        // One set, one grant: the fixture secrets are random per call, so
        // the assertions must hold the SAME instance the dump carried.
        $set = $this->tokenSet();
        $grant = StoredGrant::in_state('fixture-provider', 3, GrantState::Connected, $set);
        $dumped = print_r($grant, true);

        $this->assertStringContainsString('fixture-provider', $dumped, 'The public provider fact dumps as itself.');
        $this->assertStringNotContainsString($set->access_token(), $dumped, 'The dump must never carry the raw access token.');
        $this->assertStringNotContainsString($set->refresh_token(), $dumped, 'The dump must never carry the raw refresh token.');
        $this->assertStringContainsString((string) \Deicod\WpConnectors\Shared\Support\SecretMask::mask($set->access_token()), $dumped, 'The nested token set dumps in its masked form.');
    }

    /**
     * OCR-round-6 pin (t31-ocr6-3): the label's RENDERING leg rides the
     * safe-debug doctrine (r4-13/r8-6) at this VO's seam. A lone 0xE9
     * passes the constructor's control-byte screen by design (a config
     * label is opaque; the byte is not a control byte), but rendered
     * VERBATIM it made the dump and serialize forms invalid UTF-8 — the
     * json_encode()-false log-drop class. Both channels escape it now,
     * riding SecretMask::utf8_for_safe_render() — the ONE rendering
     * owner PendingAuthorization's label leg rides too.
     */
    public function testAnInvalidUtf8ProviderLabelRendersEscapedInTheGrantSafeForms(): void
    {
        $grant = StoredGrant::in_state("fixture\xE9provider", 3, GrantState::ReconnectRequired);

        foreach (array('dump' => print_r($grant, true), 'serialize' => serialize($grant)) as $channel => $rendered) {
            $this->assertStringNotContainsString("\xE9", $rendered, "The raw invalid byte never rides the {$channel} form.");
            $this->assertStringContainsString('fixture%E9provider', $rendered, "The label escapes exactly like the established safe-debug forms in the {$channel} channel.");
            $this->assertNotFalse(json_encode($rendered), "The {$channel} form always json_encodes.");
        }
        $this->assertSame("fixture\xE9provider", $grant->provider_id(), 'The stored bytes are unchanged — the escape is render-only.');

        $clean = StoredGrant::in_state('fixture-provider', 3, GrantState::ReconnectRequired);
        $this->assertStringContainsString('fixture-provider', print_r($clean, true), 'A normal label renders as itself.');
    }

    /**
     * OCR-round-2 pin (t31-ocr2-1): the grant's serialize() channel.
     * The grant defines no storage serialization of its own — the
     * envelope owns that (Task 3.2) — but serialize() still had the raw
     * property tree to dump: the nested token set's both tokens in
     * cleartext, for serialize() of the grant AND of any container
     * holding it. __serialize() rides the same masked view as the dump
     * (the token set delegating through its own hook), the
     * reconstruction channels refuse, and var_export() stays the one
     * NAMED-EXCLUDED channel (no engine hook exists — pinned exactly as
     * excluded, so a future engine hook tightens the contract instead
     * of silently understating it; the t31-ocr1-8 shape).
     */
    public function testTheSerializeChannelRendersTheGrantMaskedAndRefusesToRebuild(): void
    {
        $set = $this->tokenSet();
        $grant = StoredGrant::in_state('fixture-provider', 3, GrantState::Connected, $set);

        $payload = serialize($grant);
        $this->assertStringNotContainsString($set->access_token(), $payload, 'serialize() must never carry the raw access token.');
        $this->assertStringNotContainsString($set->refresh_token(), $payload, 'serialize() must never carry the raw refresh token.');
        $this->assertStringContainsString('fixture-provider', $payload, 'The grant\'s own public facts ride the payload as themselves.');
        $this->assertStringContainsString((string) \Deicod\WpConnectors\Shared\Support\SecretMask::mask($set->access_token()), $payload, 'The nested token set rides its OWN masked serialize form — the grant delegates, it does not re-decide the mask.');

        // A container holding the grant (the queue/cache shape) rides the
        // same hooks through the graph.
        $container = serialize(array('grants' => array($grant)));
        $this->assertStringNotContainsString($set->access_token(), $container, 'A container holding the grant must never carry the raw access token.');
        $this->assertStringNotContainsString($set->refresh_token(), $container, 'A container holding the grant must never carry the raw refresh token.');

        // The masked snapshot is not a round-trip payload: rebuilding refuses.
        try {
            unserialize($payload);
            $this->fail('A masked stored grant must never reconstruct from its own safe form.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not a round-trip payload', $e->getMessage());
        }

        // The eval channel refuses typed directly.
        try {
            StoredGrant::__set_state(array('provider_id' => 'fixture-provider'));
            $this->fail('__set_state() must refuse the raw export as a reconstruction source.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('never a payload', $e->getMessage());
        }

        // The one EXCLUDED channel, pinned exactly: var_export() dumps the
        // raw property tree through no engine hook (the nested set dumps
        // raw too), and evaluating the dump never reconstructs — by
        // whichever refusal fires on this engine (the nested set's own
        // __set_state() evaluates first).
        $export = var_export($grant, true);
        $this->assertStringContainsString($set->access_token(), $export, 'The documented exclusion is exact: var_export() dumps the raw tree through no hook — which is precisely why its reconstruction channel refuses.');
        try {
            eval('return ' . $export . ';');
            $this->fail('Evaluating a var_export of a stored grant must never reconstruct one.');
        } catch (\Throwable $reconstruction_refused) {
            $this->addToAssertionCount(1);
        }
    }
}
