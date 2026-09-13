<?php
/**
 * Stamp-at-acceptance marker for replay-validated tool arguments
 * (code-review GLM12 #12).
 *
 * Every outbound request re-ran ToolArgsReplayGuard::is_replayable() —
 * two full json_encode passes plus a json_decode of each historical
 * tool call's arguments — on values that are IMMUTABLE after inbound
 * acceptance: both surfaces' parsers validate the arguments (the zai
 * surface's wire/walker rule, the Anthropic surface's aggregator or
 * model-level check) before they construct the FunctionCall, and the
 * SDK DTO has no setters, so a validated call's arguments can never
 * change under the stamp. The parsers therefore construct THIS subclass
 * at acceptance, and the outbound replay guards skip their oracle for
 * stamped calls — first-seen CALLER-built values (a tool loop feeding
 * back computed results through plain SDK instances) keep the full
 * oracle.
 *
 * The stamp also carries the precise GLM12 #8 verdict: an exact big
 * integer literal the inbound wire rule accepted would be REJECTED by
 * the outbound oracle's conservative walker — the stamp keeps one
 * verdict per value instead of the two surfaces of one conversation
 * disagreeing about the same arguments (the GLM3 #1 parse/replay
 * agreement).
 *
 * Pure marker: no behavior, no state — the TYPE is the stamp, sound
 * only because the parent DTO is immutable after construction.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

use WordPress\AiClient\Tools\DTO\FunctionCall;

/**
 * A FunctionCall whose arguments passed the replay guard at acceptance.
 *
 * @since 0.2.0
 */
final class ReplayValidatedFunctionCall extends FunctionCall {

}
