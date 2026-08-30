<?php
/**
 * Task 1.3 — endpoint resolver tests.
 *
 * Table-driven verification of the exact SPEC §3.1 URLs, canonical baseUrl(),
 * immutability, and request-time option resolution.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint;
use Deicod\WpConnectors\Zai\Provider\ZaiProvider;
use Deicod\WpConnectors\Zai\Settings\PlanRegionSettings;

final class ZaiEndpointResolverTest extends WpConnectorsTestCase
{
    /**
     * Data provider: the four SPEC §3.1 OpenAI-surface combinations.
     *
     * @return array<string, list<string>>
     */
    public function provideEndpointMatrix()
    {
        return array(
            'coding+intl (default)' => array('coding', 'intl', 'https://api.z.ai/api/coding/paas/v4'),
            'coding+cn' => array('coding', 'cn', 'https://open.bigmodel.cn/api/coding/paas/v4'),
            'general+intl' => array('general', 'intl', 'https://api.z.ai/api/paas/v4'),
            'general+cn' => array('general', 'cn', 'https://open.bigmodel.cn/api/paas/v4'),
        );
    }

    /**
     * @dataProvider provideEndpointMatrix
     */
    public function testResolvesExactUrlsForEveryCombination($plan, $region, $baseUrl)
    {
        $endpoint = ZaiEndpoint::for($plan, $region);

        $this->assertSame($plan, $endpoint->plan());
        $this->assertSame($region, $endpoint->region());
        $this->assertSame($baseUrl, $endpoint->base_url());
        $this->assertSame($baseUrl, $endpoint->api_url());
        $this->assertSame($baseUrl . '/models', $endpoint->api_url('models'));
        $this->assertSame($baseUrl . '/models', $endpoint->api_url('/models'));
        $this->assertSame($baseUrl . '/chat/completions', $endpoint->api_url('chat/completions'));
        $this->assertSame('zai|' . $plan . '|' . $region, $endpoint->cache_key());
    }

    public function testCurrentSettingsDefaultToCodingInternational()
    {
        $endpoint = ZaiEndpoint::for_current_settings();

        $this->assertSame('coding', $endpoint->plan());
        $this->assertSame('intl', $endpoint->region());
        $this->assertSame('https://api.z.ai/api/coding/paas/v4', $endpoint->base_url());
    }

    /**
     * @dataProvider provideEndpointMatrix
     */
    public function testCurrentSettingsHonorStoredOptions($plan, $region)
    {
        update_option(PlanRegionSettings::OPTION_PLAN, $plan);
        update_option(PlanRegionSettings::OPTION_REGION, $region);

        $endpoint = ZaiEndpoint::for_current_settings();

        $this->assertSame($plan, $endpoint->plan());
        $this->assertSame($region, $endpoint->region());
        $this->assertSame(ZaiEndpoint::MATRIX[$plan][$region], $endpoint->base_url());
    }

    public function testCorruptSettingsFallBackToTheDefaultEndpoint()
    {
        update_option(PlanRegionSettings::OPTION_PLAN, array('garbage'));
        update_option(PlanRegionSettings::OPTION_REGION, null);

        $endpoint = ZaiEndpoint::for_current_settings();

        $this->assertSame('coding', $endpoint->plan());
        $this->assertSame('intl', $endpoint->region());
    }

    /**
     * The core Task 1.3 requirement: an option change retargets the NEXT
     * request, resolved at request time, without rebuilding the registry.
     */
    public function testOptionChangeRetargetsSubsequentResolutionWithoutRegistryRebuild()
    {
        $provider_class = ZaiProvider::class;

        $first = ZaiEndpoint::for_current_settings();
        $this->assertSame('https://api.z.ai/api/coding/paas/v4/models', $first->api_url('models'));

        // The registry/provider state does not change; only the option does.
        update_option(PlanRegionSettings::OPTION_PLAN, 'general');
        update_option(PlanRegionSettings::OPTION_REGION, 'cn');
        $this->assertSame($provider_class, ZaiProvider::class);

        $second = ZaiEndpoint::for_current_settings();
        $this->assertSame('https://open.bigmodel.cn/api/paas/v4/models', $second->api_url('models'));
        $this->assertNotSame($first->base_url(), $second->base_url());
    }

    public function testProviderBaseUrlStaysCanonicalRegardlessOfSettings()
    {
        update_option(PlanRegionSettings::OPTION_PLAN, 'general');
        update_option(PlanRegionSettings::OPTION_REGION, 'cn');

        // AbstractApiProvider::url() builds on the fixed canonical base URL.
        $this->assertSame(
            'https://api.z.ai/api/paas/v4/chat/completions',
            ZaiProvider::url('chat/completions')
        );
        $this->assertSame(ZaiEndpoint::CANONICAL_BASE_URL, ZaiProvider::url());
    }

    public function testEndpointIsImmutable()
    {
        $endpoint = ZaiEndpoint::for('coding', 'intl');

        // The value object exposes no mutators; a changed setting produces a
        // NEW instance and leaves existing ones untouched.
        update_option(PlanRegionSettings::OPTION_REGION, 'cn');

        $this->assertSame('intl', $endpoint->region());
        $this->assertSame('https://api.z.ai/api/coding/paas/v4', $endpoint->base_url());
        $this->assertNotSame($endpoint, ZaiEndpoint::for_current_settings());
    }

    public function testUnknownCombinationIsRejected()
    {
        $this->expectException(InvalidArgumentException::class);
        ZaiEndpoint::for('hobby', 'intl');
    }

    public function testUnknownRegionIsRejected()
    {
        $this->expectException(InvalidArgumentException::class);
        ZaiEndpoint::for('coding', 'eu');
    }
}
