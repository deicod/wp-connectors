<?php
/**
 * Task 2.1 — second-provider registration tests.
 *
 * Covers both providers coexisting idempotently in one registry, distinct
 * card identities, and the rule that one registration can never silently
 * replace the other (a foreign registration holding a provider ID is
 * skipped, not overwritten — and never blocks the OTHER provider).
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\AbstractProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\ProviderRegistry;
use Deicod\WpConnectors\Zai\Plugin;
use Deicod\WpConnectors\Zai\Provider\ZaiAnthropicProvider;
use Deicod\WpConnectors\Zai\Provider\ZaiProvider;

final class ZaiAnthropicPluginScaffoldTest extends WpConnectorsTestCase
{
    private const PLUGIN_FILE = __DIR__ . '/../../connectors/zai/zai.php';

    private const BOOT = '\Deicod\WpConnectors\Zai\boot';

    /**
     * Loads the plugin (installs hooks) without firing init.
     *
     * @return void
     */
    private function bootPlugin()
    {
        $this->loadPlugin(self::PLUGIN_FILE, self::BOOT);
    }

    /**
     * Boots the plugin and fires init once.
     *
     * @return void
     */
    private function bootPluginAndInit()
    {
        $this->bootPlugin();
        $this->runInit();
    }

    public function testBothProvidersRegisterBeforeCoreConnectorDiscovery()
    {
        $this->bootPluginAndInit();

        $registeredAtPriority15 = array();
        add_action('init', static function () use (&$registeredAtPriority15) {
            $registeredAtPriority15 = array(
                'zai' => AiClient::defaultRegistry()->hasProvider('zai'),
                'zai_anthropic' => AiClient::defaultRegistry()->hasProvider('zai_anthropic'),
            );
        }, 15, 0);

        $this->runInit();

        $this->assertSame(array('zai' => true, 'zai_anthropic' => true), $registeredAtPriority15);
        $this->assertTrue(AiClient::defaultRegistry()->hasProvider(ZaiProvider::class));
        $this->assertTrue(AiClient::defaultRegistry()->hasProvider(ZaiAnthropicProvider::class));
    }

    public function testTheTwoProvidersPresentDistinctCards()
    {
        $this->bootPluginAndInit();

        $zai = ZaiProvider::metadata();
        $anthropic = ZaiAnthropicProvider::metadata();

        $this->assertSame('zai', $zai->getId());
        $this->assertSame('zai_anthropic', $anthropic->getId());
        $this->assertNotSame($zai->getId(), $anthropic->getId());

        $this->assertSame('z.ai', $zai->getName());
        $this->assertSame('z.ai (Anthropic API)', $anthropic->getName());
        $this->assertNotSame($zai->getName(), $anthropic->getName(), 'The Connectors cards must be distinguishable.');

        // Core derives one key option per provider ID (architecture record 0001).
        $this->assertSame('connectors_ai_zai_api_key', Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability::KEY_OPTION);
        $this->assertSame('connectors_ai_zai_anthropic_api_key', Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability::KEY_OPTION);
    }

    public function testDuplicateInitExecutionRegistersNeitherProviderTwice()
    {
        $this->bootPluginAndInit();

        $this->runInit();
        $this->runInit();

        $registry = AiClient::defaultRegistry();
        $this->assertTrue($registry->hasProvider('zai'));
        $this->assertTrue($registry->hasProvider('zai_anthropic'));

        // The exact registration set is proven on a fresh registry (the
        // default registry is process-wide and accumulates fixture
        // providers from other test files).
        $fresh = new ProviderRegistry();
        Plugin::register($fresh);
        Plugin::register($fresh);
        $this->assertSame(array('zai', 'zai_anthropic'), $fresh->getRegisteredProviderIds());
        $this->assertNoDoingItWrong();
    }

    public function testAForeignZaiAnthropicRegistrationIsNeverSilentlyReplaced()
    {
        $this->bootPluginAndInit();

        // A foreign provider class already holds the zai_anthropic ID: the
        // plugin must SKIP its own registration (never overwrite the foreign
        // one) and must still register the zai provider independently.
        $registry = new ProviderRegistry();
        $registry->registerProvider(ZaiAnthropicForeignIdFixture::class);
        $this->assertTrue($registry->hasProvider('zai_anthropic'));

        Plugin::register($registry);

        // zai registered; the foreign zai_anthropic registration survived.
        $this->assertTrue($registry->hasProvider('zai'));
        $this->assertSame(ZaiAnthropicForeignIdFixture::class, $registry->getProviderClassName('zai_anthropic'), 'A foreign provider-ID registration must never be silently replaced.');

        // A re-run changes nothing.
        Plugin::register($registry);
        $this->assertSame(ZaiAnthropicForeignIdFixture::class, $registry->getProviderClassName('zai_anthropic'));
    }

    public function testAZaiIdCollisionStillRegistersZaiAnthropicIndependently()
    {
        $this->bootPluginAndInit();

        // Mirror image: a foreign class holds the zai ID. The zai
        // registration is skipped, and zai_anthropic STILL registers — one
        // provider's failed registration never blocks the other.
        $registry = new ProviderRegistry();
        $registry->registerProvider(ZaiForeignIdFixture::class);

        Plugin::register($registry);

        $this->assertSame(ZaiForeignIdFixture::class, $registry->getProviderClassName('zai'));
        $this->assertSame(ZaiAnthropicProvider::class, $registry->getProviderClassName('zai_anthropic'));
    }
}

/**
 * Fixture provider base whose metadata ID the subclass picks.
 *
 * @package wp-connectors
 */
abstract class ZaiForeignIdFixtureBase extends AbstractProvider
{
    protected static function createProviderMetadata(): ProviderMetadata
    {
        return new ProviderMetadata(
            static::fixtureProviderId(),
            'Foreign fixture provider',
            ProviderTypeEnum::cloud(),
            'https://example.test/keys',
            RequestAuthenticationMethod::apiKey()
        );
    }

    protected static function createModel(ModelMetadata $modelMetadata, ProviderMetadata $providerMetadata): ModelInterface
    {
        throw new RuntimeException('Not used.');
    }

    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        return new ZaiForeignIdAvailabilityFixture();
    }

    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new ZaiForeignIdDirectoryFixture();
    }

    /**
     * The provider ID this fixture registers under.
     *
     * @return string
     */
    abstract protected static function fixtureProviderId(): string;
}

/**
 * Fixture holding the 'zai' provider ID.
 *
 * @package wp-connectors
 */
final class ZaiForeignIdFixture extends ZaiForeignIdFixtureBase
{
    protected static function fixtureProviderId(): string
    {
        return 'zai';
    }
}

/**
 * Fixture holding the 'zai_anthropic' provider ID.
 *
 * @package wp-connectors
 */
final class ZaiAnthropicForeignIdFixture extends ZaiForeignIdFixtureBase
{
    protected static function fixtureProviderId(): string
    {
        return 'zai_anthropic';
    }
}

/**
 * Availability fixture: never configured, no HTTP wiring.
 *
 * @package wp-connectors
 */
final class ZaiForeignIdAvailabilityFixture implements ProviderAvailabilityInterface
{
    public function isConfigured(): bool
    {
        return false;
    }
}

/**
 * Directory fixture: no models, no HTTP wiring.
 *
 * @package wp-connectors
 */
final class ZaiForeignIdDirectoryFixture implements ModelMetadataDirectoryInterface
{
    public function listModelMetadata(): array
    {
        return array();
    }

    public function hasModelMetadata(string $modelId): bool
    {
        return false;
    }

    public function getModelMetadata(string $modelId): ModelMetadata
    {
        throw new RuntimeException('Not used.');
    }
}
