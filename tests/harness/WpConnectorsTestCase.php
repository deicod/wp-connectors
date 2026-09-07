<?php
/**
 * Base test case for all wp-connectors tests.
 *
 * Resets the WordPress API harness state between tests and installs the
 * blocking SDK HTTP client, so no test can reach the network through either
 * transport (wp_remote_* or the SDK's PSR-18 layer) unless it explicitly
 * mocks a response.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\HttpTransporter;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

abstract class WpConnectorsTestCase extends TestCase
{
    /**
     * The model id the harness wires every recording fixture to (glm29-10).
     *
     * Derived from the catalog owner (the probe's glm19-9 idiom), never a
     * bare literal: the discovery-prime defaults and the model wiring must
     * name the SAME id for the vendor directory lookup to resolve, and a
     * catalog-refresh edit updating one spelling but not the other left
     * every mapping suite failing pre-transport resolution in ways that
     * took real debugging to trace.
     */
    const HARNESS_MODEL_ID = \Deicod\WpConnectors\Zai\Metadata\ZaiModelCatalog::CODING_MODELS[0];

    /**
     * Set to true in tests that deliberately exercise the blocked/unmocked
     * HTTP path; otherwise unmocked attempts fail the run (failOnWarning).
     *
     * @var bool
     */
    protected $allowUnmockedHttp = false;

    protected function setUp(): void
    {
        parent::setUp();

        WpHarness::reset();

        // The SDK registry is a process-wide singleton: point its transporter
        // at the harness client before any provider boots. Re-installing on
        // every test also re-propagates to previously registered providers.
        AiClient::defaultRegistry()->setHttpTransporter(
            new HttpTransporter(new SdkHttpClient())
        );
    }

    /**
     * Audits unmocked HTTP attempts. Runs in assertPostConditions (NOT
     * tearDown): PHPUnit 9.6 reads $this->warnings before tearDown hooks, so
     * addWarning() there is silently discarded.
     */
    protected function assertPostConditions(): void
    {
        parent::assertPostConditions();

        if ($this->allowUnmockedHttp) {
            return;
        }

        $leaks = array();
        foreach (WpHarness::$http_attempts as $attempt) {
            if (empty($attempt['mocked'])) {
                $leaks[] = $attempt;
            }
        }
        foreach (WpHarness::$sdk_http_attempts as $attempt) {
            if (empty($attempt['mocked'])) {
                $leaks[] = $attempt;
            }
        }

        if ($leaks !== array()) {
            $this->addWarning(
                'Test made unmocked HTTP attempts (wp_remote_* or SDK transport): ' . wp_json_encode($leaks)
            );
        }
    }

    /*
     * ---------------------------------------------------------------
     * Deterministic clock.
     * ---------------------------------------------------------------
     */

    /**
     * Freezes the clock at a fixed unix timestamp.
     *
     * @param int $timestamp Unix timestamp.
     * @return void
     */
    protected function freezeTime($timestamp)
    {
        WpHarness::freezeTime($timestamp);
    }

    /**
     * Advances the frozen clock by N seconds.
     *
     * @param int $seconds Seconds.
     * @return void
     */
    protected function advanceTime($seconds)
    {
        WpHarness::advanceTime($seconds);
    }

    /*
     * ---------------------------------------------------------------
     * Users, capabilities, nonces.
     * ---------------------------------------------------------------
     */

    /**
     * Acts as an administrator (manage_options granted).
     *
     * @param array $caps Extra capability names to grant.
     * @return void
     */
    protected function asAdministrator(array $caps = array())
    {
        wp_set_current_user(1, 'admin', array_merge(array( 'manage_options' => true ), array_fill_keys($caps, true)));
    }

    /**
     * Acts as a logged-out visitor.
     *
     * @return void
     */
    protected function asAnonymous()
    {
        wp_set_current_user(0);
    }

    /**
     * Creates a deterministic nonce and injects it into $_REQUEST.
     *
     * @param string $action Nonce action.
     * @return string The nonce value.
     */
    protected function withValidNonce($action)
    {
        $nonce = wp_create_nonce($action);
        $_REQUEST['_wpnonce'] = $_POST['_wpnonce'] = $nonce;

        return $nonce;
    }

    /*
     * ---------------------------------------------------------------
     * Plugin booting and the init ladder.
     * ---------------------------------------------------------------
     */

    /**
     * Loads a plugin main file exactly once per process (require-once).
     *
     * Because the harness resets the hook registry between tests, the plugin's
     * load-time add_action() calls vanish on reset. Passing the plugin's
     * idempotent boot callback (the convention: `boot()` in the plugin's root
     * namespace) re-installs its hooks after every reset; the harness dedupes
     * identical registrations, so double booting is harmless.
     *
     * @param string   $path          Absolute path to the plugin main file.
     * @param callable|string|null $boot Optional boot callback to (re)invoke.
     * @return void
     */
    protected function loadPlugin($path, $boot = null)
    {
        $path = (string) $path;
        if (! isset(WpHarness::$loaded_plugins[ $path ])) {
            WpHarness::$loaded_plugins[ $path ] = true;
            require_once $path;
        }
        if (null !== $boot) {
            $callback = is_string($boot) && ! is_callable($boot) ? trim($boot, '\\') : $boot;
            if (is_callable($callback)) {
                call_user_func($callback);
            }
        }
    }

    /**
     * Fires the init action (the harness does not auto-run any init hooks).
     *
     * @return void
     */
    protected function runInit()
    {
        do_action('init');
    }

    /*
     * ---------------------------------------------------------------
     * The zai plugin boot facts (glm29-13).
     * ---------------------------------------------------------------
     */

    /**
     * The zai plugin main file — the fact four suites copied as private
     * constants and seven more sites inlined (glm29-13: one spelling next
     * to the loadPlugin() owner).
     */
    const ZAI_PLUGIN_FILE = __DIR__ . '/../../connectors/zai/zai.php';

    /**
     * The zai plugin's boot callback (the loadPlugin() convention).
     */
    const ZAI_PLUGIN_BOOT = '\Deicod\WpConnectors\Zai\boot';

    /**
     * Loads the zai plugin (installs hooks) without firing init.
     *
     * @return void
     */
    protected function loadZaiPlugin()
    {
        $this->loadPlugin(self::ZAI_PLUGIN_FILE, self::ZAI_PLUGIN_BOOT);
    }

    /**
     * Loads the zai plugin and fires init once.
     *
     * @return void
     */
    protected function bootZaiPluginAndInit()
    {
        $this->loadZaiPlugin();
        $this->runInit();
    }

    /*
     * ---------------------------------------------------------------
     * HTTP mocking.
     * ---------------------------------------------------------------
     */

    /**
     * Mocks the next wp_remote_* call (via core's pre_http_request filter).
     *
     * The response array uses the WP shape; body content is fully controlled
     * by the caller. Return false from the filter callback to unmock.
     *
     * @param array|WP_Error $response Response for the next request(s).
     * @param callable|null  $matcher  Optional predicate (url, args) => bool.
     * @return void
     */
    protected function mockHttpResponse($response, $matcher = null)
    {
        add_filter('pre_http_request', function ($pre, $args, $url) use ($response, $matcher) {
            if (null !== $matcher && ! $matcher($url, $args)) {
                return $pre;
            }

            return $response;
        }, 10, 3);
    }

    /**
     * Queues the next SDK-transport (PSR-18) response.
     *
     * @param int    $status  HTTP status code.
     * @param array  $headers Response headers.
     * @param string $body    Response body.
     * @return void
     */
    protected function queueSdkResponse($status, array $headers = array(), $body = '')
    {
        WpHarness::$sdk_mock_queue[] = new Response((int) $status, $headers, (string) $body);
    }

    /**
     * Primes one surface's discovery transient for the CURRENT endpoint
     * (glm22-9: the two byte-identical surface twins parameterized — the
     * selectEndpoint() shape).
     *
     * The plugin transient is the sole discovery cache (the SDK layer is
     * bypassed), so every model lookup re-checks discovery. Tests that
     * only mock the generation transport call this first, so no
     * unexpected /models attempt disturbs their recorded requests.
     *
     * glm15-12: the transient id rides the endpoint layer's one owner
     * (discovery_cache_id()) — the hand-composed CACHE_PREFIX . md5()
     * mirror this harness carried was the composition copy the whole
     * repo had already migrated off; if the composition ever changes,
     * the primed transients must stop matching what the directories
     * read in the same edit, not ~31 call sites later.
     *
     * @param string       $endpoint_class The endpoint class (ZaiEndpoint::class
     *                                     or ZaiAnthropicEndpoint::class).
     * @param list<string> $ids            Model IDs to advertise (default the harness model id).
     * @return void
     */
    protected function primeZaiSurfaceDiscoveryTransient(string $endpoint_class, array $ids = array( self::HARNESS_MODEL_ID ))
    {
        $endpoint = $endpoint_class::for_current_settings();

        set_transient(
            $endpoint_class::discovery_cache_id( $endpoint->plan(), $endpoint->region() ),
            $ids,
            \Deicod\WpConnectors\Zai\Metadata\ZaiDiscoveryCache::DISCOVERY_TTL
        );
    }

    /**
     * Primes the z.ai discovery transient for the CURRENT endpoint
     * (glm22-9: one-line delegate to the parameterized helper).
     *
     * @param list<string> $ids Model IDs to advertise (default the harness model id).
     * @return void
     */
    protected function primeZaiDiscoveryTransient(array $ids = array( self::HARNESS_MODEL_ID ))
    {
        $this->primeZaiSurfaceDiscoveryTransient( \Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint::class, $ids );
    }

    /**
     * Primes the zai_anthropic discovery transient for the CURRENT
     * endpoint (glm22-9: one-line delegate to the parameterized helper).
     *
     * @param list<string> $ids Model IDs to advertise (default the harness model id).
     * @return void
     */
    protected function primeZaiAnthropicDiscoveryTransient(array $ids = array( self::HARNESS_MODEL_ID ))
    {
        $this->primeZaiSurfaceDiscoveryTransient( \Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint::class, $ids );
    }

    /**
     * A zai-surface generation model wired for one harness-recorded
     * request, parameterized by provider class (glm23-11).
     *
     * The one 4-statement wiring every zai suite needs: prime the
     * discovery transient (glm15-1: the vendor parent caches the
     * directory statically per class, so an unwired cached instance
     * throws pre-transport under randomized order — the old accidental
     * pass), resolve the model, bind the registry's harness
     * transporter, and authenticate with an ApiKeyRequestAuthentication.
     *
     * wiredZaiAnthropicModel() (glm20-11) and wiredZaiModel() (glm22-8)
     * each spelled this wiring as mirrored bodies differing only in the
     * provider class and the discovery-prime delegate — in this very
     * file, whose own docblocks document consolidating exactly this
     * wiring-drift class, and whose primeZaiSurfaceDiscoveryTransient()
     * had already parameterized the one genuinely divergent step. One
     * class-parameterized helper now (the glm22-9/12 shape): the
     * provider's surface row — and with it the endpoint the prime
     * targets — derives from the ZaiSurfaces registry by PROVIDER_ID
     * (the lockstep pin's identity: a provider's PROVIDER_ID is its
     * surface's settings CACHE_SCOPE), so a wiring change lands once
     * and a third surface needs no new helper.
     *
     * @param string           $provider_class The surface's SDK provider
     *                                        class (ZaiProvider or
     *                                        ZaiAnthropicProvider).
     * @param string|null      $key            Exact API key to authenticate
     *                                        with, or null for a fresh
     *                                        per-call fixture key
     *                                        (FakeSecrets::apiKey() is
     *                                        random per call — pass a
     *                                        captured value when a test
     *                                        binds flags/verdicts to the
     *                                        key).
     * @param ModelConfig|null $config         Optional model configuration.
     * @return object The wired model.
     */
    protected function wiredZaiSurfaceModel(string $provider_class, ?string $key = null, ?ModelConfig $config = null)
    {
        $surface = $this->zaiSurfaceRowForProvider($provider_class);

        if (null === $surface) {
            throw new \RuntimeException("No registered zai surface for provider '{$provider_class}'.");
        }

        $this->primeZaiSurfaceDiscoveryTransient($surface['endpoint']);

        $model = $provider_class::model(self::HARNESS_MODEL_ID, $config);
        $model->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $model->setRequestAuthentication(new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication(
            null === $key ? FakeSecrets::apiKey() : $key
        ));

        return $model;
    }

    /**
     * The ZaiSurfaces registry row for one SDK provider class, or null
     * when the provider's id matches no surface (glm23-11).
     *
     * The registry fixes the pairing direction (glm20-4): SDK-dependent
     * facts alias the SDK-free owners, never the reverse — so the row
     * is FOUND by identity (the provider's PROVIDER_ID === the row's
     * settings CACHE_SCOPE, the lockstep pin's rule), never restated
     * here.
     *
     * @param string $provider_class The SDK provider class.
     * @return array{settings: class-string, endpoint: class-string}|null
     */
    private function zaiSurfaceRowForProvider(string $provider_class)
    {
        $slug = $provider_class::PROVIDER_ID;

        foreach (\Deicod\WpConnectors\Zai\Support\ZaiSurfaces::SURFACES as $surface) {
            if ($surface['settings']::CACHE_SCOPE === $slug) {
                return $surface;
            }
        }

        return null;
    }

    /**
     * A zai_anthropic model wired for one harness-recorded request
     * (glm20-11; glm23-11: one-line delegate to the class-parameterized
     * wiredZaiSurfaceModel()).
     *
     * @param string|null      $key    Exact API key to authenticate with, or
     *                                 null for a fresh per-call fixture key
     *                                 (FakeSecrets::apiKey() is random per
     *                                 call — pass a captured value when a
     *                                 test binds flags/verdicts to the key).
     * @param ModelConfig|null $config Optional model configuration.
     * @return \Deicod\WpConnectors\Zai\Models\ZaiAnthropicTextGenerationModel
     */
    protected function wiredZaiAnthropicModel(?string $key = null, ?ModelConfig $config = null)
    {
        return $this->wiredZaiSurfaceModel(\Deicod\WpConnectors\Zai\Provider\ZaiAnthropicProvider::class, $key, $config);
    }

    /**
     * A zai (OpenAI-surface) model wired for one harness-recorded request
     * (glm22-8; glm23-11: one-line delegate to the class-parameterized
     * wiredZaiSurfaceModel()).
     *
     * @param string|null      $key    Exact API key to authenticate with, or
     *                                 null for a fresh per-call fixture key
     *                                 (FakeSecrets::apiKey() is random per
     *                                 call — pass a captured value when a
     *                                 test binds flags/verdicts to the key).
     * @param ModelConfig|null $config Optional model configuration.
     * @return \Deicod\WpConnectors\Zai\Models\ZaiTextGenerationModel
     */
    protected function wiredZaiModel(?string $key = null, ?ModelConfig $config = null)
    {
        return $this->wiredZaiSurfaceModel(\Deicod\WpConnectors\Zai\Provider\ZaiProvider::class, $key, $config);
    }

    /**
     * A wired instance of one zai SDK-provider class (glm22-12).
     *
     * The same 3-statement wiring (construct, bind the registry's
     * harness transporter, authenticate) the availability and model-
     * directory fixtures spell per suite — availability() in both
     * ProviderMetadataAndAvailability suites and directory() in both
     * ModelDirectory suites differed only in the constructed class, so
     * a wiring-step change had to land four places and a missed edit
     * silently left one suite probing with a differently-wired instance
     * while staying green. One class-parameterized helper now (the
     * selectEndpoint() shape); the per-suite fixtures are one-line
     * delegates.
     *
     * @param string      $provider_class The class to instantiate
     *                                    (ZaiProviderAvailability,
     *                                    ZaiAnthropicProviderAvailability,
     *                                    ZaiModelMetadataDirectory, or
     *                                    ZaiAnthropicModelMetadataDirectory).
     * @param string|null $key            Exact API key to authenticate with,
     *                                    or null for a fresh per-call
     *                                    fixture key.
     * @return object The wired instance.
     */
    protected function wiredZaiSdkInstance(string $provider_class, ?string $key = null)
    {
        $instance = new $provider_class();
        $instance->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $instance->setRequestAuthentication(new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication(
            null === $key ? FakeSecrets::apiKey() : $key
        ));

        return $instance;
    }

    /**
     * Reads a private SSE aggregator state field (glm19-11).
     *
     * The aggregators' observability getters (is_done()/event_count()/
     * malformed_count()) were a public API only tests called and are
     * deleted; the DONE/TERMINATED state stays internal (the frame
     * gates read it), so the pins that assert termination read the
     * field through reflection — no production surface widened.
     * (glm26-11 deleted the zai aggregator's EVENT-COUNT field; the
     * former event-accounting pins ride behavioral assertions now.)
     *
     * @param object $aggregator The aggregator instance.
     * @param string $field      The private field name ('done', 'terminated').
     * @return mixed The field value.
     */
    protected function aggregator_state($aggregator, string $field)
    {
        $property = new \ReflectionProperty($aggregator, $field);
        if (PHP_VERSION_ID < 80100) {
            // Required on PHP <= 8.0; a silent no-op since 8.1 (deprecated only since 8.5).
            $property->setAccessible(true);
        }

        return $property->getValue($aggregator);
    }

    /**
     * The constants a class declares ITSELF, inherited ones excluded
     * (glm25-11).
     *
     * The base-vs-child constant lockstep pins (identifier ownership on
     * the provider, availability, endpoint, and settings bases) each
     * hand-rolled this reflection walk inline — four unowned copies
     * meant a change to the declaring-class rule had to land in five
     * places, and a suite that missed the edit silently weakened
     * exactly the pin catching constant drift. One harness helper
     * (the aggregator_state() precedent) serves every walk.
     *
     * @param string $class Class name.
     * @return list<string> Declared constant names.
     */
    protected static function declared_constants(string $class): array
    {
        $names = array();
        foreach ((new \ReflectionClass($class))->getReflectionConstants() as $constant) {
            if ($constant->getDeclaringClass()->getName() === $class) {
                $names[] = $constant->getName();
            }
        }

        return $names;
    }

    /**
     * Recorded wp_remote_* attempts.
     *
     * @return list<array{method: string, url: string, args: array}>
     */
    protected function httpAttempts()
    {
        return WpHarness::$http_attempts;
    }

    /**
     * Recorded SDK-transport attempts.
     *
     * @return list<array{method: string, url: string, headers: array, body: ?string}>
     */
    protected function sdkHttpAttempts()
    {
        return WpHarness::$sdk_http_attempts;
    }

    /**
     * Asserts no wp_remote_* or SDK-transport attempt was made.
     *
     * @return void
     */
    protected function assertNoHttpRequests()
    {
        $this->assertSame(array(), WpHarness::$http_attempts, 'Unexpected wp_remote_* attempts.');
        $this->assertSame(array(), WpHarness::$sdk_http_attempts, 'Unexpected SDK transport attempts.');
    }

    /*
     * ---------------------------------------------------------------
     * Directory-suite helpers (glm15-19: the selectEndpoint()/idList()
     * twins lived privately in both directory suites, one settings class
     * apart, with docblocks already drifted from their assertions).
     * ---------------------------------------------------------------
     */

    /**
     * Writes one surface's plan/region options — the endpoint selection
     * the directories' discovery-cache ids key on.
     *
     * @param string $settings_class The surface's settings class (PlanRegionSettings::class / ZaiAnthropicPlanRegionSettings::class).
     * @param string $plan           One of the surface's plans.
     * @param string $region         One of the surface's regions.
     * @return void
     */
    protected function selectEndpoint(string $settings_class, string $plan, string $region)
    {
        update_option($settings_class::OPTION_PLAN, $plan);
        update_option($settings_class::OPTION_REGION, $region);
    }

    /**
     * The model IDs of a metadata list.
     *
     * @param list<ModelMetadata> $models
     * @return list<string>
     */
    protected function idList(array $models): array
    {
        return array_map(static function (ModelMetadata $m) {
            return $m->getId();
        }, $models);
    }

    /*
     * ---------------------------------------------------------------
     * WP core source loading (the core-path mapping suites).
     * ---------------------------------------------------------------
     */

    /**
     * Loads the real core prompt builder class file.
     *
     * glm15-18: this loader was a byte-identical copy in both surface
     * mapping suites; a fix to the core-source lookup path or skip
     * condition landing on one suite only made the zai and zai_anthropic
     * core-builder tests silently skip or run against different core
     * checkouts, diverging coverage of the same ErrorMapper/core-code
     * path.
     *
     * @return class-string<WP_AI_Client_Prompt_Builder>
     */
    protected function corePromptBuilderClass(): string
    {
        $home = (string) getenv('HOME');
        $wpRoot = (string) (getenv('WP_CONNECTORS_TEST_WP_ROOT') ?: ($home !== '' ? $home . '/wp-ai-research/wordpress' : ''));
        $file = $wpRoot . '/wp-includes/ai-client/class-wp-ai-client-prompt-builder.php';

        if ('' === $wpRoot || ! is_file($file)) {
            $this->markTestSkipped('WP core source not found (set WP_CONNECTORS_TEST_WP_ROOT to the WordPress checkout).');
        }

        require_once $file;

        return 'WP_AI_Client_Prompt_Builder';
    }

    /**
     * Boots one surface's provider into the registry, settles its
     * availability verdict, and returns the core prompt builder
     * (glm22-7; glm23-12: registry-derived, parameterized by provider
     * class).
     *
     * The 15-line boot sequence was a full copy in both surface mapping
     * suites, differing only in five surface substitutions — the
     * discipline the sequence embeds (the glm15-1 static-directory-cache
     * priming, the settle-before-generation assert, the skip-first core
     * lookup) had to be edited in both suites, and a missed edit left
     * one suite green while silently testing a differently-booted
     * provider. glm22-7 extracted the sequence but HARD-CODED the
     * surface set (a slug allowlist plus is_zai ternaries restating the
     * availability/provider/prime/body pairings ZaiSurfaces and
     * Plugin::PROVIDER_CLASSES own), so a third surface could not boot
     * through this path at all and a pairing typo inside the ternaries
     * silently booted the wrong surface (review round 23, finding 12).
     *
     * Every surface fact now derives from the ZaiSurfaces registry by
     * PROVIDER_ID (glm23-11's identity rule): the endpoint the prime
     * targets, the settings KEY_OPTION the key row stores (the
     * availability classes' SDK-free mirror), the slug the registry
     * wires — a third surface registered in ZaiSurfaces boots with no
     * harness edit, and the ONE protocol-specific fact (the discovery
     * models body — OpenAI-style versus Anthropic-style) is the
     * caller's parameter. glm23-10's one-key discipline and the
     * skip-first core lookup stand.
     *
     * @param string $provider_class The surface's SDK provider class
     *                               (ZaiProvider or ZaiAnthropicProvider).
     * @param string $models_body    The discovery /models response body the
     *                               settle consumes (the one
     *                               protocol-specific fact).
     * @return WP_AI_Client_Prompt_Builder The settled builder.
     */
    protected function bootedCorePromptBuilder(string $provider_class, string $models_body)
    {
        // Skip-first: the core lookup may mark the test skipped, before
        // any option or registry mutation.
        $class = $this->corePromptBuilderClass();

        $surface = $this->zaiSurfaceRowForProvider($provider_class);

        if (null === $surface) {
            throw new \RuntimeException("No registered zai surface for provider '{$provider_class}'.");
        }

        $this->primeZaiSurfaceDiscoveryTransient($surface['endpoint']);

        /*
         * glm23-10: ONE key for both halves — the stored row and the
         * wired registry credential must be the same key or any later
         * database-key path reads an unsettled binding. The row rides
         * the SETTINGS class's SDK-free KEY_OPTION (the availability
         * classes' mirror of the same constant, pinned equal by the
         * consistency tests).
         */
        $key = FakeSecrets::apiKey();
        update_option($surface['settings']::KEY_OPTION, $key);
        \Deicod\WpConnectors\Zai\Plugin::register(AiClient::defaultRegistry());
        AiClient::defaultRegistry()->setProviderRequestAuthentication(
            $provider_class::PROVIDER_ID,
            new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication($key)
        );

        $this->queueSdkResponse(200, array(), $models_body);

        $this->assertTrue($provider_class::availability()->isConfigured(), 'Availability must settle before generation.');

        return new $class(AiClient::defaultRegistry(), 'Hello');
    }

    /*
     * ---------------------------------------------------------------
     * Secret-handling assertions.
     * ---------------------------------------------------------------
     */

    /**
     * Asserts a stored option does not contain a plaintext secret.
     *
     * @param string $option   Option name.
     * @param string $secret   The plaintext secret that must not be present.
     * @param string $message  Failure message.
     * @return void
     */
    protected function assertOptionNotPlaintext($option, $secret, $message = '')
    {
        $this->assertNotSame(false, get_option($option, false), sprintf('Option "%s" is not set.', $option));
        $stored = wp_json_encode(get_option($option));
        $this->assertStringNotContainsString($secret, $stored, $message !== '' ? $message : sprintf('Option "%s" contains the plaintext secret.', $option));
    }

    /**
     * Asserts a string (log line, error message, snapshot) never contains a
     * secret or identifiable fragments of it.
     *
     * @param string $haystack The output under test.
     * @param string $secret   The secret that must be redacted.
     * @return void
     */
    protected function assertRedacted($haystack, $secret)
    {
        $this->assertStringNotContainsString($secret, $haystack);

        // Long secrets must not leak via partial echoes either.
        if (strlen($secret) > 12) {
            $this->assertStringNotContainsString(substr($secret, 0, 8), $haystack);
            $this->assertStringNotContainsString(substr($secret, -8), $haystack);
        }
    }

    /*
     * ---------------------------------------------------------------
     * Diagnostics.
     * ---------------------------------------------------------------
     */

    /**
     * Asserts no _doing_it_wrong() was recorded during the test.
     *
     * @return void
     */
    protected function assertNoDoingItWrong()
    {
        $this->assertSame(array(), WpHarness::$doing_it_wrong, 'Unexpected _doing_it_wrong recordings: ' . wp_json_encode(WpHarness::$doing_it_wrong));
    }

    /**
     * Asserts the value is a WP_Error (optionally with a specific code).
     *
     * @param mixed  $actual Value.
     * @param string $code   Optional expected error code.
     * @return void
     */
    public static function assertWPError($actual, $code = '')
    {
        self::assertInstanceOf(WP_Error::class, $actual);
        if ('' !== $code) {
            self::assertSame($code, $actual->get_error_code());
        }
    }

    /**
     * Asserts the value is not a WP_Error.
     *
     * @param mixed $actual Value.
     * @return void
     */
    public static function assertNotWPError($actual)
    {
        self::assertNotInstanceOf(WP_Error::class, $actual);
    }

    /*
     * ---------------------------------------------------------------
     * Request capture, snapshots, and pre-transport rejections
     * (GLM12 #14 — hoisted from the byte-identical twin copies the two
     * mapping suites carried, whose captureRequest had already forked
     * once on the try/catch).
     * ---------------------------------------------------------------
     */

    /**
     * Directory holding this suite's committed request snapshots.
     *
     * The default is the shared snapshots directory; a suite with its
     * own snapshot family overrides (the surface mapping suites use
     * fixtures/snapshots/zai and fixtures/snapshots/zai-anthropic).
     *
     * @return string Absolute path, no trailing slash.
     */
    protected function snapshotDirectory(): string
    {
        return __DIR__ . '/../fixtures/snapshots';
    }

    /**
     * Queues the one successful response captureRequest() drives its
     * generation with. A suite whose surface parses a specific protocol
     * overrides with that protocol's success body.
     *
     * @return void
     */
    protected function queueCaptureResponse()
    {
        $this->queueSdkResponse(200, array(), '');
    }

    /**
     * The wired model the pre-transport rejection helper drives. A suite
     * using assertRejectedBeforeTransport() overrides with its own
     * provider's model (discovery transient primed, harness transporter,
     * fixture key); the loud default catches a missing override instead
     * of silently testing nothing.
     *
     * @param ModelConfig|null $config Optional model configuration.
     * @return object The wired model.
     */
    protected function snapshotTestModel(?ModelConfig $config = null)
    {
        throw new RuntimeException(get_class($this) . ' must provide the rejection-helper model.');
    }

    /**
     * Runs one generation and returns [url, decodedBody, headers] of the
     * single recorded request.
     *
     * GLM12 #14: the twin copies are reconciled on the explicit
     * pre-transport-failure branch — an InvalidArgumentException from
     * the guarded mapping surfaces as a named failure, not an uncaught
     * error.
     *
     * @param list<Message> $prompt Prompt to send.
     * @param object        $model  A wired text-generation model.
     * @return array{0: string, 1: array, 2: array}
     */
    protected function captureRequest(array $prompt, $model): array
    {
        $this->queueCaptureResponse();

        try {
            $model->generateTextResult($prompt);
        } catch (InvalidArgumentException $e) {
            // The captured body is still available; surface it for debugging.
            $this->fail('Request failed pre-transport or in parsing: ' . $e->getMessage());
        }

        $attempts = $this->sdkHttpAttempts();
        $this->assertCount(1, $attempts);

        return array(
            $attempts[0]['url'],
            (array) json_decode((string) $attempts[0]['body'], true),
            $attempts[0]['headers'],
        );
    }

    /**
     * Asserts the captured request equals the committed snapshot.
     *
     * Snapshots are created on first run (the test skips that run and
     * must be re-run to verify); headers are excluded by construction
     * and the credential invariants are asserted regardless.
     *
     * @param string $name  Snapshot name.
     * @param string $url   Request URL.
     * @param array  $body  Decoded request body.
     * @return void
     */
    protected function assertMatchesSnapshot(string $name, string $url, array $body)
    {
        $path = $this->snapshotDirectory() . '/' . $name . '.json';
        $snapshot = array('url' => $url, 'body' => $body);

        if (!is_file($path)) {
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            $this->markTestSkipped("Snapshot {$name} created; re-run to verify.");
        }

        $this->assertSame(
            $snapshot,
            (array) json_decode((string) file_get_contents($path), true),
            "Captured request drifted from snapshot {$name}."
        );

        // Snapshots never contain credentials (headers are excluded by
        // construction; assert the invariant anyway).
        $this->assertStringNotContainsString('Bearer', (string) file_get_contents($path));
        $this->assertStringNotContainsString('Authorization', (string) file_get_contents($path));
    }

    /**
     * Asserts the configuration is rejected before any transport work,
     * with the given needle in its message.
     *
     * @param ModelConfig $config The configuration under test.
     * @param string      $needle Expected message fragment.
     * @return void
     */
    protected function assertRejectedBeforeTransport(ModelConfig $config, string $needle)
    {
        try {
            $this->snapshotTestModel($config)->generateTextResult(array(
                new Message(MessageRoleEnum::user(), array(new MessagePart('hi'))),
            ));
            $this->fail("Config containing '{$needle}' must be rejected.");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }
}
