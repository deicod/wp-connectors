<?php
/**
 * Task 2.2 — zai_anthropic endpoint resolver tests.
 *
 * Table-driven verification of the exact SPEC §3.1 Anthropic-surface URLs,
 * /v1/messages and /v1/models appended exactly once (double-append guard),
 * the per-surface canonical baseUrl(), immutability, and request-time
 * option resolution.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint;
use Deicod\WpConnectors\Zai\Provider\ZaiAnthropicProvider;
use Deicod\WpConnectors\Zai\Settings\PlanRegionSettings;
use Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings;

final class ZaiAnthropicEndpointResolverTest extends WpConnectorsTestCase
{
    /**
     * Data provider: the four SPEC §3.1 Anthropic-surface combinations.
     *
     * @return array<string, list<string>>
     */
    public function provideEndpointMatrix()
    {
        return array(
            'coding+intl (default)' => array('coding', 'intl', 'https://api.z.ai/api/coding/anthropic'),
            'coding+cn' => array('coding', 'cn', 'https://open.bigmodel.cn/api/coding/anthropic'),
            'general+intl' => array('general', 'intl', 'https://api.z.ai/api/anthropic'),
            'general+cn' => array('general', 'cn', 'https://open.bigmodel.cn/api/anthropic'),
        );
    }

    /**
     * @dataProvider provideEndpointMatrix
     */
    public function testResolvesExactUrlsForEveryCombination($plan, $region, $baseUrl)
    {
        $endpoint = ZaiAnthropicEndpoint::for($plan, $region);

        $this->assertSame($plan, $endpoint->plan());
        $this->assertSame($region, $endpoint->region());
        $this->assertSame($baseUrl, $endpoint->base_url());
        $this->assertSame($baseUrl, $endpoint->api_url());
        $this->assertSame($baseUrl . '/v1/messages', $endpoint->messages_url());
        $this->assertSame($baseUrl . '/v1/models', $endpoint->models_url());
        $this->assertSame($baseUrl . '/v1/messages', $endpoint->api_url('v1/messages'));
        $this->assertSame($baseUrl . '/v1/messages', $endpoint->api_url('/v1/messages'));
        $this->assertSame('zai_anthropic|' . $plan . '|' . $region, $endpoint->cache_key());
    }

    public function testCurrentSettingsDefaultToCodingInternational()
    {
        $endpoint = ZaiAnthropicEndpoint::for_current_settings();

        $this->assertSame('coding', $endpoint->plan());
        $this->assertSame('intl', $endpoint->region());
        $this->assertSame('https://api.z.ai/api/coding/anthropic', $endpoint->base_url());
        $this->assertSame('https://api.z.ai/api/coding/anthropic/v1/messages', $endpoint->messages_url());
        $this->assertSame('https://api.z.ai/api/coding/anthropic/v1/models', $endpoint->models_url());
    }

    /**
     * @dataProvider provideEndpointMatrix
     */
    public function testCurrentSettingsHonorTheAnthropicOptionsOnly($plan, $region)
    {
        update_option(ZaiAnthropicPlanRegionSettings::OPTION_PLAN, $plan);
        update_option(ZaiAnthropicPlanRegionSettings::OPTION_REGION, $region);

        $endpoint = ZaiAnthropicEndpoint::for_current_settings();

        $this->assertSame($plan, $endpoint->plan());
        $this->assertSame($region, $endpoint->region());
        $this->assertSame(ZaiAnthropicEndpoint::MATRIX[$plan][$region], $endpoint->base_url());

        // The ZAI provider's selection never influences this resolver.
        update_option(PlanRegionSettings::OPTION_PLAN, 'general');
        update_option(PlanRegionSettings::OPTION_REGION, 'cn');
        $this->assertSame(
            ZaiAnthropicEndpoint::MATRIX[$plan][$region],
            ZaiAnthropicEndpoint::for_current_settings()->base_url(),
            'The zai (OpenAI-surface) settings must not retarget the Anthropic resolver.'
        );
    }

    public function testCorruptSettingsFallBackToTheDefaultEndpoint()
    {
        update_option(ZaiAnthropicPlanRegionSettings::OPTION_PLAN, array('garbage'));
        update_option(ZaiAnthropicPlanRegionSettings::OPTION_REGION, null);

        $endpoint = ZaiAnthropicEndpoint::for_current_settings();

        $this->assertSame('coding', $endpoint->plan());
        $this->assertSame('intl', $endpoint->region());
    }

    /*
     * The double-append guard (Task 2.2).
     */

    public function testABaseUrlAlreadyCarryingAnEndpointSuffixLosesItExactlyOnce()
    {
        $this->assertSame(
            'https://api.z.ai/api/coding/anthropic',
            ZaiAnthropicEndpoint::normalize_base_url('https://api.z.ai/api/coding/anthropic/v1/messages'),
            'A base with /v1/messages appended must be normalized before the suffix is re-added.'
        );
        $this->assertSame(
            'https://api.z.ai/api/coding/anthropic',
            ZaiAnthropicEndpoint::normalize_base_url('https://api.z.ai/api/coding/anthropic/v1/models')
        );
        $this->assertSame(
            'https://api.z.ai/api/coding/anthropic',
            ZaiAnthropicEndpoint::normalize_base_url('https://api.z.ai/api/coding/anthropic/')
        );
        $this->assertSame(
            'https://api.z.ai/api/coding/anthropic',
            ZaiAnthropicEndpoint::normalize_base_url('https://api.z.ai/api/coding/anthropic')
        );
    }

    public function testMessagesAndModelsUrlsNeverAccumulateSuffixesAcrossCalls()
    {
        $endpoint = ZaiAnthropicEndpoint::for('coding', 'intl');

        // The value object is immutable: repeated reads (and repeated
        // normalization of its base) can never stack the suffix.
        $first = $endpoint->messages_url();
        $this->assertSame($first, $endpoint->messages_url());
        $this->assertSame($first, ZaiAnthropicEndpoint::normalize_base_url($first) . '/v1/messages');

        $models = $endpoint->models_url();
        $this->assertSame($models, ZaiAnthropicEndpoint::normalize_base_url($models) . '/v1/models');
        $this->assertStringEndsWith('/v1/messages', $first);
        $this->assertSame(1, substr_count($first, '/v1/messages'), 'The suffix appears exactly once.');
    }

    /*
     * Request-time retargeting (Task 2.2's core requirement).
     */

    public function testOptionChangeRetargetsSubsequentResolutionWithoutRegistryRebuild()
    {
        $first = ZaiAnthropicEndpoint::for_current_settings();
        $this->assertSame('https://api.z.ai/api/coding/anthropic/v1/messages', $first->messages_url());

        // The registry/provider state does not change; only the option does.
        update_option(ZaiAnthropicPlanRegionSettings::OPTION_PLAN, 'general');
        update_option(ZaiAnthropicPlanRegionSettings::OPTION_REGION, 'cn');

        $second = ZaiAnthropicEndpoint::for_current_settings();
        $this->assertSame('https://open.bigmodel.cn/api/anthropic/v1/messages', $second->messages_url());
        $this->assertSame('https://open.bigmodel.cn/api/anthropic/v1/models', $second->models_url());
        $this->assertNotSame($first->base_url(), $second->base_url());
    }

    public function testProviderBaseUrlStaysCanonicalRegardlessOfSettings()
    {
        update_option(ZaiAnthropicPlanRegionSettings::OPTION_PLAN, 'general');
        update_option(ZaiAnthropicPlanRegionSettings::OPTION_REGION, 'cn');

        // AbstractApiProvider::url() builds on the fixed canonical base URL
        // — per-surface canonical, distinct from the zai provider's.
        $this->assertSame(
            'https://api.z.ai/api/anthropic/v1/messages',
            ZaiAnthropicProvider::url('v1/messages')
        );
        $this->assertSame(ZaiAnthropicEndpoint::CANONICAL_BASE_URL, ZaiAnthropicProvider::url());
        $this->assertNotSame(
            Deicod\WpConnectors\Zai\Provider\ZaiProvider::url(),
            ZaiAnthropicProvider::url(),
            'The two surfaces keep distinct canonical base URLs.'
        );
    }

    public function testEndpointIsImmutable()
    {
        $endpoint = ZaiAnthropicEndpoint::for('coding', 'intl');

        // The value object exposes no mutators; a changed setting produces a
        // NEW instance and leaves existing ones untouched.
        update_option(ZaiAnthropicPlanRegionSettings::OPTION_REGION, 'cn');

        $this->assertSame('intl', $endpoint->region());
        $this->assertSame('https://api.z.ai/api/coding/anthropic', $endpoint->base_url());
        $this->assertNotSame($endpoint, ZaiAnthropicEndpoint::for_current_settings());
    }

    public function testUnknownCombinationIsRejected()
    {
        $this->expectException(InvalidArgumentException::class);
        ZaiAnthropicEndpoint::for('hobby', 'intl');
    }

    public function testUnknownRegionIsRejected()
    {
        $this->expectException(InvalidArgumentException::class);
        ZaiAnthropicEndpoint::for('coding', 'eu');
    }

    public function testCacheKeysNeverCollideWithTheOpenAiSurface()
    {
        foreach (ZaiAnthropicEndpoint::MATRIX as $plan => $regions) {
            foreach (array_keys($regions) as $region) {
                $this->assertSame(
                    'zai_anthropic|' . $plan . '|' . $region,
                    ZaiAnthropicEndpoint::for($plan, $region)->cache_key()
                );
            }
        }

        $this->assertNotSame(
            Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint::for('coding', 'intl')->cache_key(),
            ZaiAnthropicEndpoint::for('coding', 'intl')->cache_key(),
            'Endpoint identities differ per surface, keeping bindings/caches disjoint.'
        );
    }
}
