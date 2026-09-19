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
        /*
         * The walk behind this census FOLLOWS SYMLINKS (OCR round 49,
         * t31-ocr49-11): without FOLLOW_SYMLINKS a
         * symlink-to-directory under shared/src/Exception is neither
         * isFile()-true (stat follows the link to a directory) nor
         * recursed — the linked subtree escaped the census while the
         * pin's failure message claimed "at any depth", and PSR-4
         * maps a linked subdirectory exactly like a real one: the
         * family grows through it whether the census sees it or not.
         * The no-symlinks doctrine (a link is never silently skipped)
         * reads the tree whole: the linked files join the walk, a
         * stray link fails the file-set pin naming the class, and the
         * message tells the truth. Construction-evident (the shipped
         * tree carries no links; driven by planting one).
         *
         * The follow owns its CYCLE shape too (OCR round 51,
         * t31-ocr51-1): FOLLOW_SYMLINKS keeps no visited set, so a
         * directory symlink closing a loop recursed until the
         * path-length or memory limit fataled the process — the
         * "driven by planting one" promise above was, on a loop, an
         * unkept fatal. The walk (collectExceptionTreeClasses below,
         * the extraction this round) carries a visited-realpath set
         * at every directory it enters; the loop leg is driven at
         * testASymlinkLoopUnderTheExceptionTreeAnswersTheLoudRefusalNotTheFatal.
         */
        $from_tree = $this->collectExceptionTreeClasses(dirname(__DIR__) . '/shared/src/Exception');
        $listed = array_merge($this->concreteTypes(), array(OAuthRuntimeException::class, OAuthTransientException::class));
        sort($listed);

        $this->assertSame($from_tree, $listed, 'concreteTypes() must cover every type anywhere under shared/src/Exception/ — a new file there, at any depth, grows the list or fails this pin.');
    }

    /**
     * The recursive Exception-tree walk behind the census pin above —
     * the r49-11 follow-symlinks shape (linked files join, linked
     * directories recurse) with the cycle guard that shape owed its
     * own docblock (OCR round 51, t31-ocr51-1), the guard owning its
     * two RE-VISIT shapes since OCR round 52 (t31-ocr52-3): a
     * re-visited REALPATH is a LOOP only when it is an ANCESTOR of
     * the current position (a cycle — the walk would re-enter its
     * own ancestry forever), and the walk refuses loudly naming both
     * spellings — the entry path and the ancestor it resolves back
     * into — never the opaque path-length/memory fatal the follow's
     * unguarded recursion answered. A re-visit of a NON-ancestor is
     * a DIAMOND (two sibling links at one real directory — a
     * terminating shape, the linked files merely reachable twice):
     * the walk skips the duplicate entry and continues, each real
     * file counted once (the r51 guard refused ANY re-visit with the
     * loop vocabulary — a diamond is not a loop). The sets carry the
     * roles explicitly: $seen owns every tree ever entered, the
     * $ancestors stack owns the current descent. The loud-refusal
     * vocabulary covers the environment arms beside the loop: a
     * directory that will not resolve or list refuses naming it, and
     * since OCR round 53 (t31-ocr53-4) a DANGLING entry — a dead
     * symlink, neither is_dir() nor is_file() (both stat through the
     * link) — refuses too, the no-symlinks doctrine's fourth arm
     * beside file/dir/loop (a silently shrunken census is the
     * coverage hole this pin exists to close).
     *
     * @return list<string> Sorted class names of every *.php file under the tree.
     */
    private function collectExceptionTreeClasses(string $dir): array
    {
        $classes = array();
        $seen = array();
        $ancestors = array();
        $walk = function (string $current) use (&$walk, &$classes, &$seen, &$ancestors, $dir): void {
            $real = realpath($current);
            if (false === $real) {
                throw new \RuntimeException('The Exception-tree walk cannot resolve ' . $current . ' — an environment problem (a broken checkout, an open_basedir wall), never a census verdict.');
            }
            if (isset($ancestors[$real])) {
                throw new \RuntimeException('Symlink loop under the Exception tree: ' . $current . ' resolves back into its own ancestry (' . $real . ') — the census refuses a looping tree loudly, never fatals walking it.');
            }
            if (isset($seen[$real])) {
                // A DIAMOND, not a loop: this real tree is already
                // walked through another path (t31-ocr52-3) — skip the
                // duplicate entry, the walk continues.
                return;
            }
            $seen[$real] = true;
            $ancestors[$real] = true;
            $entries = scandir($current);
            if (false === $entries) {
                throw new \RuntimeException('The Exception-tree walk cannot list ' . $current . ' — the census refuses loudly, never silently shrinks the file set.');
            }
            foreach ($entries as $entry) {
                if ('.' === $entry || '..' === $entry) {
                    continue;
                }
                $path = $current . '/' . $entry;
                if (is_dir($path)) {
                    $walk($path);
                } elseif (is_file($path)) {
                    if ('php' === strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                        $classes[] = 'Deicod\\WpConnectors\\Shared\\Exception\\' . str_replace('/', '\\', substr($path, strlen($dir) + 1, -4));
                    }
                } else {
                    /*
                     * The DANGLING arm (t31-ocr53-4 — the no-symlinks
                     * doctrine's fourth arm beside file/dir/loop): a
                     * dead symlink satisfies neither is_dir() nor
                     * is_file() (both stat THROUGH the link), fell
                     * through both branches, and was skipped silently
                     * — the census quietly shrinking over an entry it
                     * could not read, the exact shape the doctrine ("a
                     * link is never silently skipped", t31-ocr49-11)
                     * exists to close.
                     */
                    throw new \RuntimeException('The Exception-tree walk cannot read ' . $path . ' — a dangling symlink (or other neither-file-nor-directory entry) under the census root is never silently skipped: the census refuses loudly, never silently shrinks the file set.');
                }
            }
            unset($ancestors[$real]);
        };
        $walk($dir);
        sort($classes);

        return $classes;
    }

    /**
     * OCR-round-51 pin (t31-ocr51-1): the driven probe the r49-11
     * docblock names ("driven by planting one") fatals on a LOOP, it
     * does not drive anything — a directory symlink closing a cycle
     * under the walked tree recursed until the engine's own limits
     * killed the process, an opaque crash instead of the loud named
     * refusal the no-symlinks doctrine requires. Driven on a scratch
     * replica of the tree: the planted loop link answers the walk's
     * named refusal and the SUITE SURVIVES — that survival is the
     * pin. The loop-bearing scratch tree releases through the
     * harness's own no-follow rrmdir (the t31-ocr1-11 shape: a linked
     * child is unlinked as itself, never descended).
     */
    public function testASymlinkLoopUnderTheExceptionTreeAnswersTheLoudRefusalNotTheFatal(): void
    {
        if (! WpHarness::canSymlink()) {
            $this->markTestSkipped('This host cannot create symlinks — the planted-loop leg did not run (the cycle-guard seam it drives is unconstructible here).');
        }

        do {
            $scratch = sys_get_temp_dir() . '/wpct-oauth-census-loop-' . getmypid() . '-' . bin2hex(random_bytes(4));
        } while (is_dir($scratch));

        try {
            $this->assertTrue(mkdir($scratch . '/Sub', 0755, true), 'staging: the scratch Exception tree must create — a staging failure fails as staging, never as the walk verdict.');
            $this->assertNotFalse(file_put_contents($scratch . '/One.php', "<?php\n"), 'staging: the scratch type file must write — a staging failure fails as staging, never as the walk verdict.');
            $this->assertNotFalse(file_put_contents($scratch . '/Sub/Two.php', "<?php\n"), 'staging: the scratch nested type file must write — a staging failure fails as staging, never as the walk verdict.');
            // The plant: a directory link closing the loop — the link
            // points back at the very tree being walked, so the walk
            // re-enters its own root through the link's realpath.
            $this->assertTrue(symlink($scratch, $scratch . '/x'), 'staging: the planted loop link must take — a staging failure fails as staging, never as the walk verdict.');

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Symlink loop');
            $this->collectExceptionTreeClasses($scratch);
        } finally {
            WpHarness::releaseScratch($scratch);
        }
    }

    /**
     * OCR-round-52 pin (t31-ocr52-3): a re-visited realpath is not
     * always a LOOP — two sibling links pointing at the same real
     * directory re-enter an already-walked tree through a
     * NON-cyclic path (a DIAMOND: the walk terminates normally, the
     * linked files just appear twice), yet the r51 guard refused
     * ANY re-visit with the loop vocabulary (red at HEAD: the
     * diamond answered 'Symlink loop'). The guard distinguishes the
     * shapes now: a re-visit is a loop only when the revisited
     * realpath is an ANCESTOR of the current position (a cycle —
     * the walk would re-enter itself forever); a re-visit of a
     * non-ancestor is a diamond, and the walk SKIPS the duplicate
     * entry and continues — each real file counted once, the census
     * complete. The planted-loop pin above keeps the other arm: a
     * true cycle still answers the loud refusal.
     */
    public function testASymlinkDiamondUnderTheExceptionTreeCompletesTheCensusEachFileOnce(): void
    {
        if (! WpHarness::canSymlink()) {
            $this->markTestSkipped('This host cannot create symlinks — the planted-diamond leg did not run (the diamond-shape seam it drives is unconstructible here).');
        }

        do {
            $scratch = sys_get_temp_dir() . '/wpct-oauth-census-diamond-' . getmypid() . '-' . bin2hex(random_bytes(4));
        } while (is_dir($scratch));

        try {
            $this->assertTrue(mkdir($scratch . '/Sub', 0755, true), 'staging: the scratch Exception tree must create — a staging failure fails as staging, never as the walk verdict.');
            $this->assertNotFalse(file_put_contents($scratch . '/One.php', "<?php\n"), 'staging: the scratch type file must write — a staging failure fails as staging, never as the walk verdict.');
            $this->assertNotFalse(file_put_contents($scratch . '/Sub/Two.php', "<?php\n"), 'staging: the scratch nested type file must write — a staging failure fails as staging, never as the walk verdict.');
            // The plant: two SIBLING links at the same real directory —
            // a diamond, never a cycle. Neither link points into its
            // own ancestry; the walk just reaches Sub three times.
            $this->assertTrue(symlink($scratch . '/Sub', $scratch . '/a'), 'staging: the first diamond link must take — a staging failure fails as staging, never as the walk verdict.');
            $this->assertTrue(symlink($scratch . '/Sub', $scratch . '/b'), 'staging: the second diamond link must take — a staging failure fails as staging, never as the walk verdict.');

            $expected = array(
                'Deicod\\WpConnectors\\Shared\\Exception\\One',
                'Deicod\\WpConnectors\\Shared\\Exception\\Sub\\Two',
            );
            $this->assertSame($expected, $this->collectExceptionTreeClasses($scratch), 'A diamond completes the census with each real file counted ONCE — the duplicate entry skips, the walk continues; only a re-entry of an ANCESTOR (a cycle) refuses.');
        } finally {
            WpHarness::releaseScratch($scratch);
        }
    }

    /**
     * OCR-round-53 pin (t31-ocr53-4): the walk's own doctrine — "a
     * link is never silently skipped" (t31-ocr49-11) — was not
     * enforced for the DANGLING arm: a dead symlink satisfies neither
     * is_dir() nor is_file() (both stat THROUGH the link), fell
     * through both branches, and was skipped silently — the census
     * quietly shrinking over an entry it could not read, the exact
     * shape the loud-refusal vocabulary exists to close (RED AT HEAD,
     * driven: the walk completed over the planted dead link without
     * naming it). The dangling arm refuses loudly now — the
     * no-symlinks doctrine's fourth arm beside file/dir/loop — in
     * the walk's own refusal vocabulary; live trees unchanged.
     */
    public function testADanglingSymlinkUnderTheExceptionTreeAnswersTheLoudRefusalNotTheSilentSkip(): void
    {
        if (! WpHarness::canSymlink()) {
            $this->markTestSkipped('This host cannot create symlinks — the planted-dangling-link leg did not run (the dangling-arm seam it drives is unconstructible here).');
        }

        do {
            $scratch = sys_get_temp_dir() . '/wpct-oauth-census-dangling-' . getmypid() . '-' . bin2hex(random_bytes(4));
        } while (is_dir($scratch));

        try {
            $this->assertTrue(mkdir($scratch, 0755, true), 'staging: the scratch Exception tree must create — a staging failure fails as staging, never as the walk verdict.');
            $this->assertNotFalse(file_put_contents($scratch . '/One.php', "<?php\n"), 'staging: the scratch type file must write — a staging failure fails as staging, never as the walk verdict.');
            // The plant: a DEAD link — its target is never created, so
            // both stat probes answer false and only the dangling arm
            // can name it.
            $this->assertTrue(symlink($scratch . '/gone', $scratch . '/dead'), 'staging: the planted dangling link must take — a staging failure fails as staging, never as the walk verdict.');

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('dangling symlink');
            $this->collectExceptionTreeClasses($scratch);
        } finally {
            WpHarness::releaseScratch($scratch);
        }
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

        /*
         * OCR-round-46 pin (t31-ocr46-10): the accessor's adjudicated
         * invariant (t31-r1-15 — hands out the RAW provider number
         * by design, the cap applied by the consumer through
         * RefreshPolicy) was pinned only for sub-cap values, so a
         * refactor that starts clamping the raw accessor to the
         * policy cap would have ridden green. The oversized raw side
         * answers RAW — both the oversized literal and the extreme
         * spelling — beside the policy suite's own capped pins (one
         * boundary, both files; the policy side rides unchanged).
         */
        $this->assertSame(86400, (new OAuthRateLimitException('throttled', 0, null, 86400))->retry_after_seconds(), 'An oversized Retry-After answers the RAW provider number — the accessor never clamps, the consumer does.');
        $this->assertSame(PHP_INT_MAX, (new OAuthRateLimitException('throttled', 0, null, PHP_INT_MAX))->retry_after_seconds(), 'Even the extreme spelling answers raw — a clamp inside the exception would couple it to the policy and to per-provider config it cannot know (red only under the clamping refactor this pin guards).');

        // The policy side states the cap over the same oversized
        // spellings (its own suite pins the capped side; one truth,
        // both sides — the t31-ocr2-7 pairing).
        $this->assertSame(60, (new \Deicod\WpConnectors\Shared\Policy\RefreshPolicy(0, 1, 60))->capped_retry_after_seconds(PHP_INT_MAX));
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
     *
     * OCR-round-22 pin (t31-ocr22-5): the allow sets once granted
     * 'retry_after_seconds' to EVERY concrete type, contradicting the
     * docblock contract above — the addition is the RATE-LIMIT type's
     * alone, so a wrong grant (a storage or transport type growing the
     * spelling) passed every audit invisible. The sets spell the
     * docblock's actual contract now: the standard baseline for every
     * type, the parsed-seconds additions only where the type carries
     * them.
     */
    public function testExceptionTypesDeclareNoPayloadOrCredentialCarryingApi(): void
    {
        /*
         * The STANDARD BASELINE owns statics too (OCR round 49,
         * t31-ocr49-9): every collection in this fence once skipped
         * `isStatic()` — the baseline, the name diff, and the
         * declaring-class audit — so a `public static` helper on a
         * concrete type or the family base passed every pin wholly
         * exempt, the exact hole the r8-5 ledger record's own
         * disposition names ("a static payload carrier fails the
         * NAME diff only if its name is not an engine name") — a
         * disposition the code never enforced, both static skips
         * contradicting it. Statics judge by the SAME rule as
         * instance methods at every collection now (the r8-5
         * exemption superseded): an engine-declared static rides the
         * baseline like any engine method, and a FAMILY-declared
         * static must be one of the type's allowed additions —
         * nothing static is, so any family-declared public static is
         * the payload channel (driven: a planted static helper
         * passed every pin at HEAD, both audits answering it now).
         */
        $standard = array();
        foreach ((new \ReflectionClass(\Exception::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $standard_method) {
            $standard[] = $standard_method->getName();
        }

        foreach ($this->concreteTypes() as $type) {
            // The per-type allow set (t31-ocr22-5): the additions ride
            // ONLY the type whose docblock contract carries them.
            $allowed = $standard;
            $family_additions = array('__construct');
            if (OAuthRateLimitException::class === $type) {
                $allowed[] = 'retry_after_seconds';
                $family_additions[] = 'retry_after_seconds';
            }

            $api = array();
            foreach ((new \ReflectionClass($type))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ('__construct' !== $method->getName()) {
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
             * The DECLARING CLASS decides now: a public method is
             * either the engine's own (declared outside the family,
             * un-overridden standard behavior) or one of the type's
             * allowed additions (t31-ocr22-5: __construct for every
             * type, retry_after_seconds for the RATE-LIMIT type
             * alone). Anything else the family declares — a concrete
             * override, a base method beyond the allow set, a static
             * helper (t31-ocr49-9: the audit's own static exemption
             * retired, statics judged by this same rule) — is the
             * payload channel and fails.
             */
            foreach ((new \ReflectionClass($type))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
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
            /*
             * The per-type property allow set (t31-ocr22-5): the one
             * parsed int rides ONLY the rate-limit type — every other
             * type's family-declared property set is empty.
             */
            $family_properties = OAuthRateLimitException::class === $type ? array('retry_after_seconds') : array();
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
