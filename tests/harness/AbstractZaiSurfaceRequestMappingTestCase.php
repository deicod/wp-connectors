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
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;

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

    public function testAnEmptyPromptIsRejectedBeforeTransport()
    {
        /*
         * glm29-5 (cross-surface parity, the shared RequestShapeGuard
         * rule): the zai surface shipped the vendor parent's assembled
         * "messages": [] verbatim although the chat-completions schema
         * requires minItems 1 — the round trip answered 400 and the
         * caller got the generic misattributed rejection after the
         * wasted request, while this surface's twin rejected the
         * identical input typed pre-transport. One inherited pin runs
         * on BOTH surfaces (the glm22-6 shape).
         */
        try {
            $this->model()->generateTextResult(array());
            $this->fail('An empty prompt must be rejected before transport.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('requires at least one message', $e->getMessage());
        }

        $this->assertNoHttpRequests();
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

    public function testDeclarationValidationOrderIsSharedAcrossSurfaces()
    {
        /*
         * glm39-1: both surfaces' declaration walks are ONE shared
         * scaffold now (RequestShapeGuard::validate_function_declarations())
         * — this pin holds the ORDER the hand-maintained loop twins
         * carried and drifted by (each rule landed on one surface
         * before the twin: GLM12 #5, glm13-9, glm16-11): within one
         * declaration the identity rules fire BEFORE the schema rule,
         * and across declarations the WALK order decides — an earlier
         * declaration's defect rejects before a later one's, whatever
         * the rule kinds. One inherited expectation, executed per
         * surface (the glm22-6 shape): the identical verdict is the
         * lockstep.
         */
        $prompt = array(new Message(MessageRoleEnum::user(), array(new MessagePart('go'))));

        // Identity before schema, within ONE declaration: the empty
        // name must reject even though the same declaration also
        // carries a list-root schema.
        $identityFirst = ModelConfig::fromArray(array());
        $identityFirst->setFunctionDeclarations(array(
            new FunctionDeclaration('', 'Nameless, and a list-root schema besides', array('a', 'b')),
        ));

        try {
            $this->model($identityFirst)->generateTextResult($prompt);
            $this->fail('An empty declared tool name must reject before the schema rule judges the same declaration.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('non-empty name', $e->getMessage());
            $this->assertStringNotContainsString('JSON object', $e->getMessage(), 'The identity rule must fire first, not the schema rule.');
        }

        $this->assertNoHttpRequests();

        // Walk order across declarations: an EARLIER declaration's
        // schema defect rejects before a LATER declaration's duplicate
        // name — declaration order decides, not the rule kind.
        $declarationOrder = ModelConfig::fromArray(array());
        $declarationOrder->setFunctionDeclarations(array(
            new FunctionDeclaration('pick', 'Picks', array('a', 'b')),
            new FunctionDeclaration('pick', 'A different tool under the same name', null),
        ));

        try {
            $this->model($declarationOrder)->generateTextResult($prompt);
            $this->fail('The earlier declaration\'s schema defect must reject first.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('JSON object', $e->getMessage());
            $this->assertStringNotContainsString('unique names', $e->getMessage(), 'The earlier declaration rejects; the later duplicate is never reached.');
        }

        $this->assertNoHttpRequests();
    }
}
