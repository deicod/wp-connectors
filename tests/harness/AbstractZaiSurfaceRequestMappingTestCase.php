<?php
/**
 * The protocol-independent request-mapping rejections, shared by both
 * zai surfaces (glm22-6).
 *
 * The thin config-option wrappers around the harness's
 * assertRejectedBeforeTransport() were byte-identical copies in both
 * request-mapping suites — every wrapper pins a rule that lives on the
 * SHARED guard layer (AdvertisedOptionGuard, AdvertisedUsageGuard,
 * RequestShapeGuard), so a rule change had to be re-encoded twice, and a
 * one-side edit left the suites pinning different expectations of the
 * same code while both stayed green. The drift was already real on
 * landing: maxTokens -5 pinned only on the zai side, 0 only on the
 * Anthropic side, of the one shared maxTokens-positive guard. One copy
 * executes per surface now — each concrete subclass inherits these
 * tests, and the unified maxTokens pin exercises BOTH values on BOTH
 * surfaces.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Files\DTO\File;

abstract class AbstractZaiSurfaceRequestMappingTestCase extends WpConnectorsTestCase
{
    /**
     * The wired surface model (provided by each concrete suite).
     *
     * @param ModelConfig|null $config Optional model configuration.
     * @return object The wired model.
     */
    abstract protected function model( ?ModelConfig $config = null );

    public function testImageInputIsRejectedBeforeTransport()
    {
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(
                new MessagePart(new File('https://fixture.test/pic.png', 'image/png')),
            )),
        );

        $e = null;
        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('An image part must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('text input', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testCandidateCountIsRejectedBeforeTransport()
    {
        $this->assertRejectedBeforeTransport(
            ModelConfig::fromArray(array('candidateCount' => 2)),
            'candidateCount'
        );
    }

    public function testSamplingPenaltiesAreRejectedBeforeTransport()
    {
        $this->assertRejectedBeforeTransport(
            ModelConfig::fromArray(array('presencePenalty' => 0.5)),
            'presence penalty'
        );
        $this->assertRejectedBeforeTransport(
            ModelConfig::fromArray(array('frequencyPenalty' => 0.5)),
            'frequency penalty'
        );
    }

    public function testTopKIsRejectedBeforeTransport()
    {
        $this->assertRejectedBeforeTransport(
            ModelConfig::fromArray(array('topK' => 40)),
            'top-k'
        );
    }

    public function testLogprobsAreRejectedBeforeTransport()
    {
        $this->assertRejectedBeforeTransport(
            ModelConfig::fromArray(array('logprobs' => true)),
            'logprobs'
        );
    }

    public function testImageOutputModalityIsRejectedBeforeTransport()
    {
        $config = ModelConfig::fromArray(array());
        $config->setOutputModalities(array(
            WordPress\AiClient\Messages\Enums\ModalityEnum::text(),
            WordPress\AiClient\Messages\Enums\ModalityEnum::image(),
        ));

        $this->assertRejectedBeforeTransport($config, 'text output modalities');
    }

    public function testUnsupportedOutputMimeTypeIsRejectedBeforeTransport()
    {
        $this->assertRejectedBeforeTransport(
            ModelConfig::fromArray(array('outputMimeType' => 'image/png')),
            'outputMimeType'
        );
    }

    public function testCustomOptionsAreRejectedBeforeTransport()
    {
        $config = ModelConfig::fromArray(array());
        $config->setCustomOption('thinking', array('type' => 'enabled'));

        $this->assertRejectedBeforeTransport($config, 'custom options');
    }

    public function testNonPositiveMaxTokensIsRejectedBeforeTransport()
    {
        /*
         * glm18-3 (cross-surface parity, the shared RequestShapeGuard
         * rule): the SDK's setMaxTokens() is a bare assignment, so
         * maxTokens 0 (or negative) rode "max_tokens" verbatim to the
         * endpoint's generic misattributed 400. Typed pre-transport
         * rejection now. glm22-6: the two suites' copies had drifted —
         * the zai side pinned 0 and -5, the Anthropic side only 0 — so
         * the unified pin exercises BOTH values on BOTH surfaces.
         */
        $this->assertRejectedBeforeTransport(
            ModelConfig::fromArray(array('maxTokens' => 0)),
            'maxTokens'
        );
        $this->assertRejectedBeforeTransport(
            ModelConfig::fromArray(array('maxTokens' => -5)),
            'maxTokens'
        );
    }
}
