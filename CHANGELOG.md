# Changelog

All notable changes to this repository are documented here, per plugin and per
tooling area. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning per plugin follows its own header `Version` (no monorepo version).

## [Unreleased]

### Fixed (shared — M3 Task 3.1, claude-glm round 48)

Thirty-fourth claude-glm pass (/code-review max over the FULL branch
diff, ledger-read first; the convergence round ordered — still not
zero): three driven fail-opens in the gates (the write-visibility
machinery's ASCII-'\b' laundering via high-byte variable names, the
namespace-decoy define, the scanner's aggregate-entropy gap), the
memory-limit grammar handed to the engine's own parser, the mask's
embedded-credential tail, the define value's remaining legal
spellings, the un-handleable compile warning at the tokenize seats,
and two documentation corrections. Nine fix commits t31-glm48-1..9
plus the docs close, the full offline check green after every
commit. Five wp-stubs core divergences, the set-cookie2 catalog
rows, and the define variable-callee leg recorded as inheritance.
Suite 1972 → 1976 tests, 48539 → 48565 assertions, 3 skipped
unchanged.

- **The write-visibility machinery's variable boundaries ride the
  LABEL byte class (t31-glm48-1, driven fail-open; bin/lib/
  plugin-tools.php)** — the by-ref closure-capture arm's ASCII
  '\b' failed on high-byte-final names, '$fé' laundering the
  deferred-execution shape at zero violations where the '$fr' twin
  flags. The closure arm, the destructuring/by-ref refusals and
  their plain-path twins, the signature consult, and the foreach
  header's lookahead swept in one stroke.

- **A decoy define binds nothing (t31-glm48-2, driven fail-open;
  bin/lib/plugin-tools.php)** — a same-namespace 'function
  define(){}' or an un-aliased 'use function Foo\define;' shadows
  the bare call at runtime (the plugin fatals on the constant) while
  every gate stayed green. The seat consults the namespace ledger:
  the import and declaration arms, the benign fallback/alias/
  self-import/method shapes pinned green.

- **The fake-secret tail's entropy budget is aggregate
  (t31-glm48-3, driven through the real CLI; bin/lib/
  secret-scanner.php)** — 35 chunked bytes in seven 5-byte segments
  shipped as fake where the same bytes contiguous flag; the filler
  anchor tightened to the head's with the pure sequential runs
  staying filler.

- **The memory-limit grammar is the engine's own
  ini_parse_quantity() (t31-glm48-4; bin/lib/secret-scanner.php)**
  — '128Mb' parses to 128 bytes by the engine and 128 mebibytes by
  the hand grammar, the census overstating the enforced limit
  ~1,000,000x on such hosts. The saturation arms unchanged; the
  '128mb' unit pin superseded to the engine's verdict.

- **The visible tail judges the embedded credential
  (t31-glm48-5, driven; shared/src/Support/SecretMask.php)** — a
  nine-character user code riding in a Location query rendered four
  of its characters through every safe debug form; a query-shaped
  value judges its final parameter now, long trailing tokens
  keeping the correlation tail.

- **The define value's legal spellings bind (t31-glm48-6, driven
  false refusals; bin/lib/plugin-tools.php)** — the b/B-encoding
  prefix, one parenthesizing, and heredoc/nowdoc values all minted
  the false must-define refusal on working plugins; one shared
  heredoc arm (relative backreference), the pieces decoding in
  order. The variable-callee leg recorded as inheritance.

- **The engine's compile warning never escapes a tokenize seat
  (t31-glm48-7, driven; bin/lib/plugin-tools.php, bin/lib/
  secret-scanner.php)** — token_get_all() over php -l-clean
  octal-overflow escapes printed raw Warning lines misattributed
  to the tool's own file, failing strict-output consumers; output
  captured at both driven seats and every further hostile-byte
  seat swept.

- **Two documentation corrections (t31-glm48-8/9)** — the stranded
  is_php_source docblock relocated (glm45-6's insertion; the
  R47-14 class unswept at this seat), and five narration blocks
  describing superseded code corrected in place (the r26-8 class
  over rounds 44-47's own records).

### Fixed (shared — M3 Task 3.1, claude-glm round 47)

Thirty-third claude-glm pass (/code-review max over the FULL branch
diff, ledger-read first): still not zero — 15 findings, the five
headline defects all in the last two rounds' own fixes (the
collector-must-collect class both seats over, the helper's view
mis-named, the define value's capture grammar). DRIVER ADJUDICATION
under the scope rule: nine fix classes accepted (the loop collector's
class reduction, the define seat's collector/view/value one-owner
close, the statement-position helper's isset table, the IPv6
serializer's 32-bit floor fix, three round-46-landing corrections,
the harness query-separator pin, the fleet hoist the round-46
records armed); the latent add_filter dedupe recorded as
inheritance. Nine fix commits t31-glm47-1..9 plus the docs close,
the full offline check green after every commit. Suite 1970 → 1972
tests, 48532 → 48539 assertions, 3 skipped unchanged.

- **The loop collector collects, the byte-pair helper judges
  (t31-glm47-1, driven fail-open; bin/lib/plugin-tools.php)** —
  the loop detector's regex lookbehind still refused the tight
  ':' '>' bytes before the glm46-1 helper ever consulted, so
  'case 1:while(...)' laundering shapes answered zero violations
  where the spaced twins flag. The class reduces to label+'$',
  the helper owning every pair judgment.

- **The define probe's collector, view, and value grammar made
  one-owner-true (t31-glm47-2, driven; bin/lib/plugin-tools.php,
  tests/SelfContainmentVersionConstantBindingTest.php)** — the
  collector's own lookbehind stopped pre-answering the helper
  (tight case/elvis/arrow defines bind), the position consult
  walks the comment-STRIPPED view (a '$r->/*c*/define(...)' call
  launders nothing), and the whole value expression rides the ONE
  quoted-literal grammar composed with the join — the pieces
  decoded through the one quote-style owner and concatenated
  (hex-escaped and concatenated values bind; the mismatch twin
  prints the decoded value).

- **The statement-position helper's label-run walk rides a
  256-entry isset table (t31-glm47-3, measured; bin/lib/
  plugin-tools.php)** — the table derived once per process from
  the one label owner itself; 811ms → 201ms whole-call over the
  landing bench, verdict-identical byte-for-byte over all 256
  bytes.

- **The IPv6 serializer's dotted-tail hextets no longer ride a
  32-bit float (t31-glm47-4; shared/src/Http/Url.php)** —
  inet_pton() + unpack('n2') replace the unsigned ip2long()
  coercion feeding intdiv(), which TypeErrors on a float under
  strict_types on the 32-bit floor the composer constraint
  admits.

- **Three round-46-landing corrections (t31-glm47-5/6/7;
  shared/src/Http/Url.php)** — the dead duplicate $hex build
  deleted, the stranded r12-8 docblock relocated to its function,
  and the bracket computations gated on the bracket-bearing shape
  (plain hostnames skip the whole block; the garbage inner-literal
  slice gone).

- **The harness add_query_arg() pins its query separator
  (t31-glm47-8, driven; tests/harness/wp-stubs.php)** — the bare
  http_build_query() consulted arg_separator.output; four pinned
  assertions went red under the hostile ini. The separator pins
  explicitly, the stub's output stable on every host.

- **The pooled php -l fleet rides its ONE owner (t31-glm47-9;
  bin/lib/plugin-tools.php, bin/lint-php.php,
  bin/inspect-artifact.php)** — wp_connectors_pooled_php_lint_
  verdicts() owns the whole fleet (pooled shape, both staging
  fallbacks, the inner-shell scratch escape, the last-exit
  verdict anchor, the cleanup), the ~140-line twin deleted (net
  -75 lines), each gate keeping its own message vocabulary — and
  each gate's serial and pooled arms now one rendering apiece.

### Fixed (shared — M3 Task 3.1, claude-glm round 46)

Thirty-second claude-glm pass (/code-review max over the FULL branch
diff, ledger-read first): still not zero — 14 findings under the
cap, the four headline defects all in the last rounds' own fixes.
DRIVER ADJUDICATION under the scope rule: five fix classes accepted
(the byte-pair glue judgment, the define seat's spacing and quote
gaps, the inner-shell scratch escape at both fleet seats, the IPv6
canonicality screen, the dead seat guard); the measured hostile-tree
quadratic, the pooled/serial message divergence, two latent harness
divergences, the unpinned prescreen anchors, and the fleet hoist
(third drift) recorded as inheritance. Five fix commits
t31-glm46-1..5 plus the docs close, the full offline check green
after every commit. Suite 1969 → 1970 tests, 48521 → 48532
assertions, 3 skipped unchanged.

- **The glue judgment rides the byte pair (t31-glm46-1,
  security:medium, driven fail-open at every gate;
  bin/lib/plugin-tools.php, tests/SelfContainmentLoopWritesTest.php)**
  — glm45-6's helper refused ':' and '>' unconditionally, but those
  are also the case-label, alternative-syntax, ternary-colon, and
  '=>' positions: includes and loops there were invisible where
  master flags. '>' glues only after '-', ':' only after ':', the
  $allow_separator flag admitting the legal '\define'.

- **The define seat's two gaps closed (t31-glm46-2, driven;
  bin/lib/plugin-tools.php,
  tests/SelfContainmentVersionConstantBindingTest.php)** — the
  spacing-proof helper consulted at the third keyword seat
  ('$r -> define(...)' laundering no more), and the value capture
  riding the per-quote alternation decoded through the one
  quote-style owner ('Version: 1.2'3' no longer falsely refused).

- **The pooled-lint scratch rides the inner-shell escape
  (t31-glm46-3, driven over the real repo; bin/lint-php.php,
  bin/inspect-artifact.php)** — a '$' in TMPDIR made the inner
  'sh -c' expand it inside the redirect targets, all 184 files
  spurious FAILs with the serial fallback never firing; the scratch
  escapes the four bytes sh re-parses inside double quotes, at both
  fleet seats.

- **The IPv6 canonicality screen (t31-glm46-4, driven;
  shared/src/Http/Url.php, tests/SharedOAuthContractsHttpTest.php)**
  — '[0:0:0:0:0:0:0:1]' and '[::ffff:1.2.3.4]' constructed while
  every WHATWG consumer serializes '::1' / '::ffff:102:304': two
  spellings naming one host over the browser-facing channel. The
  WHATWG serializer spelled as one helper; the literal must equal
  its own canonical serialization (uppercase hex legal per the
  pinned fold).

- **The leftover empty-short seat guard deleted (t31-glm46-5;
  bin/check-conventions.php)** — glm45-9's 'every arm at one owner'
  claim made true, the stale copy having read as load-bearing.

### Fixed (shared — M3 Task 3.1, claude-glm round 45)

Thirty-first claude-glm pass (/code-review max over the FULL branch
diff, ledger-read first; the driver's order pre-designating the two
round-44 measured claims, both landed before the review): still not
zero — 8 CONFIRMED correctness findings (5 defects in the last
rounds' own fixes), 7 cleanups recorded. DRIVER ADJUDICATION under
the scope rule: eight fix classes accepted; the three-owner removal
drift, the nine-site walk-abort invariant, two measured eager-view
arms (~11ms/~7.6ms), and four smaller items recorded as
inheritance. Nine fix commits t31-glm45-1..9 plus the docs close,
the full offline check green after every commit. Suite 1965 → 1967
tests, 48495 → 48511 assertions, 3 skipped unchanged.

- **The scanner's native prescreen (t31-glm45-1, the pre-measured
  R44-7; bin/lib/secret-scanner.php)** — a strict-superset strpos
  chain skips the ten-pattern battery on ~78% of repo lines
  (1,154,550 → 256,560 PCRE invocations; the battery seat 367ms →
  222ms, the scan 0.99–1.07s → 0.81–0.83s), the abort pin
  surviving by construction.

- **The mention check walks once per file (t31-glm45-2, the
  pre-measured R44-8; bin/check-conventions.php)** — one
  maximal-label-run pass bucketed by the folded token replaces the
  per-import full-source rescans; byte-identical by the 14-shape +
  8000-fuzz differential (the first cut's head-class walk having
  caught two impossible-in-production edge families before commit).

- **The operand probe derives from the token stream
  (t31-glm45-3, security:medium, driven both edges;
  bin/lib/plugin-tools.php)** — the variable-callee
  `$fn( ...vendor... );` shape was invisible where master flags,
  and the member-call `$docs->include(...)` shape was falsely
  refused. Constructs, channel T_STRINGs (their previous
  significant token refusing the name-usage contexts), and a
  variable-followed-by-'(' arm — the prose immunity now structural.

- **The fake-secret word exempts only over placeholder bytes on
  BOTH sides (t31-glm45-4, driven end-to-end;
  bin/lib/secret-scanner.php)** — `xoxb-eu1-test-9f3k...` shipped
  as fake where the same entropy ahead of the word flags; the tail
  after the word must be placeholder material too, the pinned
  filler fixtures keeping their exemption through the fall-through.

- **Member, static, and nullsafe define calls bind nothing
  (t31-glm45-5, driven; bin/lib/plugin-tools.php)** — a decoy
  class's `$registry->define('MYPLUG_VERSION', ...)` satisfied the
  must-define gate with no constant defined (the plugin fataling at
  runtime); the left class refuses the name-usage glue bytes.

- **The spacing-proof position filter (t31-glm45-6, driven;
  bin/lib/plugin-tools.php)** — the include owner matched
  semi-reserved keywords as method/constant names (the R44-4
  census unswept there), and glm44-4's fixed-length guards walked
  past by two spaces, a comment, or operator spacing. ONE shared
  helper walks the view's bytes backward at both seats.

- **glm44-6's fallback made effective and the sibling seat swept
  (t31-glm45-7 + t31-glm45-8; bin/lint-php.php,
  bin/inspect-artifact.php)** — the lint seat's verdict loop rides
  inside the write-success arm (the serial call alone had landed),
  and the inspector's write leg gains the else its comment always
  promised (a REAL tmpfs ENOSPC having rejected all 61 extracted
  files of the valid dist zip).

- **The mention helper guards the empty short (t31-glm45-9,
  driven; bin/check-conventions.php)** — glm45-2's own edge: a
  backslash-ending group member minted a spurious 'unused import'
  FAIL; the single arm's guard rides the helper, every arm at one
  owner.

### Fixed (shared — M3 Task 3.1, claude-glm round 44)

Thirtieth claude-glm pass (/code-review max over the FULL branch
diff, ledger-read first): still not zero — 15 findings under the
cap (6 correctness, 9 cleanup; the locale-fold family refuted by
the review's own verifiers — `strtolower` is locale-independent
since PHP 8.2, the repo's floor), the correctness yield dominated
by the round-43 commits' own defects. DRIVER ADJUDICATION under
the scope rule: six fix classes accepted (the second dataflow
hop, the session-id glued twin and JSESSIONID, the reconciled
double read, the semi-reserved phantom spans, the ASCII label
straggler, the staging write-failure fallback); the scanner
prescreen (measured 4.1x) and the mention-scan single-alternation
(~35% of its seat) recorded as the next round's pre-measured
cheap claims, seven cleanups beside them. Six fix commits
t31-glm44-1..6 plus the docs close, the full offline check green
after every commit. Suite 1962 → 1965 tests, 48476 → 48495
assertions, 3 skipped unchanged.

- **The variable-operand resolution is transitive
  (t31-glm44-1, security:medium, driven fail-open versus master;
  bin/lib/plugin-tools.php,
  tests/SelfContainmentAutoloaderPrefixBindingTest.php)** —
  glm43-1 judged only the resolved assignment's own text, so
  `$paths = array(__DIR__."/../vendor-pkg/lib.php"); foreach
  ($paths as $lib) { require $lib; }` carried the vendor path to
  the channel with no flag (inspect ACCEPTING where master
  refuses). A worklist over each assignment value's own
  variables, a seen-set closing cycles, every hop judged by the
  same standard.

- **The session-id member's glued twin and JSESSIONID join the
  suffix class (t31-glm44-2, driven;
  shared/src/Support/SecretMask.php,
  tests/SharedOAuthContractsHttpTest.php)** — 'X-SessionId' and
  'SessionId' rendered verbatim through all five render channels
  (JSESSIONID and ASP.NET_SessionId are canonical glued
  spellings of the same credential); 'sessionid' joins as the
  flattened twin, JSESSIONID as its own member, the spanning
  boundary intact.

- **The autoloader gate's two reads reconcile
  (t31-glm44-3, race-driven; bin/lib/plugin-tools.php)** — the
  guarded read and the provider's read assembled verdicts over
  bytes no single file contained (340/100000 zero-violation
  verdicts for the hostile file under a rename cycler); a content
  mismatch answers the loud mid-swap refusal, never a mixed-file
  verdict.

- **Semi-reserved keyword constants and methods mint no phantom
  loop spans (t31-glm44-4, driven false flags;
  bin/lib/plugin-tools.php, tests/SelfContainmentLoopWritesTest.php)**
  — 'const DO = 1;' (the declaration the braceless-do guard
  cannot see past) and 'function do($t)' armed phantom spans to
  EOF that false-flagged benign plugins; every arm refuses the
  const/function-declaration contexts and the name-usage glue
  bytes, the real loops unchanged.

- **A high-byte plugin directory builds
  (t31-glm44-5, driven end-to-end; bin/build.php,
  tests/BuildArtifactsTest.php)** — assertNamespaceSegment was
  the lone ASCII straggler at a label-legality verdict; 'grün'
  passed every pre-config gate and failed the build solely here
  with the wrong-reason message. The judgment rides the one
  label-byte owner.

- **The lint staging's write failure falls back to the serial arm
  (t31-glm44-6, shim-driven; bin/lint-php.php)** — a files.nul
  write failure (ENOSPC on tmpfs) ran neither the fleet nor the
  promised fallback, every lintable file a spurious
  misattributed FAIL; the write failure falls back too.

### Fixed (shared — M3 Task 3.1, claude-glm round 43)

Twenty-ninth claude-glm pass (/code-review max over the FULL
branch diff, ledger-read first; R42-8 pre-designated the round's
cheap claim): still not zero — 15 findings, the dominant disease
the branch's own label-byte-census and comment-blanked-twin
doctrines unswept at sibling seats, beside one fail-open
regression versus master. DRIVER ADJUDICATION under the scope
rule: ten fix classes accepted (the variable-mediated vendor
operand, the label-byte census at three families, the
blanked-twin sweep and separator spacing, the fake-secret
dictionary word, the fractional memory limit, glm42-2's own
printable leftovers, the provider's TOCTOU null, the session-id
suffix, the third hand-rolled tearDown, and the driver-ordered
line_split strcspn twin); the eight-seat use-head grammar hoist
recorded as inheritance (cleanup, its terminator difference
deliberate). Ten fix commits t31-glm43-1..10 plus the docs close,
the full offline check green after every commit. Suite
1959 → 1962 tests, 48437 → 48476 assertions, 3 skipped unchanged.

- **The variable-mediated vendor operand still references vendor
  (t31-glm43-1, security:medium, driven fail-open versus master;
  bin/lib/plugin-tools.php,
  tests/SelfContainmentAutoloaderPrefixBindingTest.php)** — the
  masked probe blanks the vendor path riding a quoted literal and
  the operand probe judged only the statement text at hand, so
  `$lib = __DIR__ . '/../vendor/pkg/lib.php'; require $lib;`
  passed every gate where master flagged (the review's master
  worktree drive). The channel statement's variable operands
  resolve through the same-file assignment machinery the escape
  walk rides, each resolved value judged by the operand probe's
  own standard — an operand path is never prose, whatever its
  spelling.

- **The label-byte census swept to three sibling families
  (t31-glm43-2, driven false flags; bin/lib/plugin-tools.php,
  tests/SelfContainmentLoopWritesTest.php)** — the include scan's
  ASCII `\b` keyword arm minted four phantom include statements
  over two `$require`/`$include` variable assignments (the
  R37-6/R37-7 recorded inheritance claimed), the loop detector's
  `\b` arms read `grüwhile(` as a loop header arming a phantom
  span, and the variable-shape regexes at four seats refused a
  legal high-byte variable its ASCII twin resolves. Every arm
  rides the label byte class with the `$` guard.

- **The blanked-twin sweep completed and both group seats admit
  the separator spacing (t31-glm43-3, driven false refusals;
  bin/build.php, tests/BuildArtifactsTest.php)** — the
  namespace-declaration pass rides `replaceOverCommentBlanked()`
  (a php -l-clean comment inside the declaration refused
  anonymously), and `use Deicod\WpConnectors \{Shared\Clock};`
  (whitespace before the separator-brace) refused anonymously at
  both seats; all eight shapes rewrite clean.

- **The fake-secret dictionary word exempts only over a
  placeholder head (t31-glm43-4, driven under-refusal;
  bin/lib/secret-scanner.php, tests/SecureFixturesTest.php)** —
  a live credential carrying `-test-` or `_test_` mid-body was
  exempted wholesale and shipped undetected where the `-tesx-`
  twin flagged; the word exempts only when the value's head
  before it is placeholder material itself (empty, vendor
  markers, other dictionary words, filler) — never the
  high-entropy bytes of a live body.

- **A fractional memory_limit parses to the engine's clamp
  (t31-glm43-5, driven fatal-without-verdict;
  bin/lib/secret-scanner.php, tests/SecureFixturesTest.php)** —
  `128.5M` (which PHP 8.5 clamps to `128M` and enforces) fell out
  of the integer-only grammar, answered PHP_INT_MAX, and
  disabled the token-memory census: a dense payload fatalling at
  exit 255 with no verdict where the integer control answered the
  loud refusal. The grammar admits the fractional tail and floors.

- **glm42-2's own printable leftovers (t31-glm43-6, both the
  r26-8 class over round 42's records; bin/check-conventions.php)**
  — the connectors walk-abort print was never swept (the round-42
  scripted replacement silently no-oped, unasserted, while the
  commit claimed both), and the four sprintf FAIL arms' terminator
  sat inside `wp_connectors_printable()`, flattening `\n` to a
  space and merging consecutive violations onto one stderr line.

- **The provider's null read answers the loud unreadable refusal
  (t31-glm43-7, race-driven; bin/lib/plugin-tools.php)** — a TOCTOU
  null degraded to `''` and the gate answered 'must register a
  PSR-4 autoloader' over bytes it never saw (101 such verdicts
  over 4000 race-driven calls); the misattributed content
  verdicts are unreachable over unread bytes now.

- **The session identifier joins the sensitive-header suffix
  class (t31-glm43-8, the PLAUSIBLE upgraded and driven;
  shared/src/Support/SecretMask.php,
  tests/SharedOAuthContractsHttpTest.php)** — `X-Session-Id`
  rendered its value verbatim through every masked render surface
  while the catalog's own cookie row masks the same credential
  channel; `session-id` joins the suffixes, the boundary unchanged
  (`x-session-idle`, `x-session-count`, `x-request-id` verbatim).

- **The census miss: a third hand-rolled tearDown rides
  releaseScratch (t31-glm43-9; tests/SelfContainmentInterpolationTest.php)**
  — glm42-6's 'the family's LAST two' claim missed this flat-walk
  spelling (nested directories and locked entries stranded); the
  owner rides every exit.

- **The line_split strcspn twin landed, byte-identical
  (t31-glm43-10, the driver-ordered R42-8;
  bin/lib/secret-scanner.php)** — one native `strcspn` scan per
  line replaces the per-byte PHP walk (round 42's measurement:
  5.08x broad / 5.60x scan-realistic), the same terminator
  semantics by construction, zero mismatches re-driven against
  the inline reference over 26 edge shapes + 3000 fuzz + the
  6.4 MB repo corpus; the repo scan 1.26–1.36s → 0.99–1.07s.

### Fixed (shared — M3 Task 3.1, claude-glm round 42)

Twenty-eighth claude-glm pass (/code-review max over the FULL
branch diff, ledger-read first): still not zero — 15 findings,
three of them contradicting round 41's own commit records (the
r26-8 class, corrected in-round). DRIVER ADJUDICATION under the
scope rule: R42-1 (the autoloader equality arm — the ledger's
R41-15 PLAUSIBLE upgraded to a driven fail-open), R42-2+3 (the
printable seam's last two gates), R42-4+5+7 (the group-use seats'
comment-blindness — the ledger's deferred seat-matching question
re-driven as a real defect — plus the orphaned grammar docblock),
R42-6 (the operand probe's ASCII lookbehind one short of the label
class), R42-14 (the terminator alternation hoisted to its own
constant), and R42-15 (the family's last two hand-rolled
tearDowns) accepted; R42-8 (the line_split strcspn twin, measured
5.08x/5.60x with a byte-identical differential — the next round's
cheap claim), R42-9 (the scanner's double-lex), the two
ledger-covered re-flags (R42-10, R42-11), and two cleanups
recorded as inheritance. Six fix commits t31-glm42-1..6 plus the
docs close, the full offline check green after every commit. Suite
1953 → 1959 tests, 48408 → 48437 assertions, 3 skipped unchanged.

- **The autoloader equality arm walks the token stream
  (t31-glm42-1, security:medium, driven fail-open;
  bin/lib/plugin-tools.php,
  tests/SelfContainmentAutoloaderPrefixBindingTest.php)** — the
  quote-pair grammar over the comment-stripped view kept
  heredoc/nowdoc bodies and inline-HTML spans raw, so quote-shaped
  text inside them paired as a "literal" whose decoded value
  equalled the expected prefix: a php -l-clean foreign-prefix
  autoloader plus a nowdoc body (or a `?>` HTML tail) passed the
  gate green while class_exists() is false at runtime. The arm
  walks T_CONSTANT_ENCAPSED_STRING tokens now — the tokenizer the
  one owner of which bytes are a real quoted literal, every
  laundering region excluded by token kind.

- **The printable seam's last two gates swept (t31-glm42-2,
  driven; bin/check-conventions.php, bin/scan-secrets.php,
  bin/build.php, tests/SelfContainmentVerdictForgeryTest.php)** —
  the conventions gate's FAIL prints interpolated the shared
  builders' violation bytes raw (a newline inside a quoted include
  operand forging a standalone green-summary line above the real
  red one), and the scanner CLI's finding prints carried walked
  paths (a newline-named directory forging the green `0
  finding(s)` line above a live-credential finding). All prints
  render through `wp_connectors_printable()` — the forged text
  flattens into the diagnostic line, the verdict bytes untouched.

- **The group-use seats run over the comment-blanked twin
  (t31-glm42-3, driven false refusals over php -l-clean input;
  bin/build.php, tests/BuildArtifactsTest.php)** — the seat
  patterns and the body comma split were comment-blind over raw
  bytes, so a `;`/`}` inside a comment killed the seat match (the
  postcondition refusing legal input while the conventions gate
  answered 0 on the identical bytes) and a `,` inside a comment
  split a member mid-comment into the grammar's own refusal. Both
  seats ride `replaceOverCommentBlanked()`; the orphaned grammar
  docblock re-attached (getDocComment() was false at HEAD); the
  widened ownership forced three pin supersessions (the ocr35-1
  pattern).

- **The operand probe's keyword boundary rides the label byte
  class (t31-glm42-4, driven false flag; bin/lib/plugin-tools.php,
  tests/SelfContainmentAutoloaderPrefixBindingTest.php)** — the
  ASCII `(?<![\$\w])` lookbehind (and the right-side `\b`)
  false-flagged a php -l-clean `äfile_get_contents(...)` user
  helper as its sole violation; both edges ride
  WP_CONNECTORS_LABEL_BYTES now (the laundering direction refuted
  — the ASCII class is a strict subset).

- **The terminator alternation hoisted to its own constant
  (t31-glm42-5, verdict-identical by construction;
  bin/lib/plugin-tools.php)** — the `;|?>|EOF` alternation was
  still hand-spelled at both assignment seats while the tail
  grammar's docblock claimed the hoist closed the drift;
  WP_CONNECTORS_STATEMENT_TERMINATOR owns it, the tail grammar
  composes it, both seats ride it.

- **The family's last two hand-rolled tearDowns ride releaseScratch
  (t31-glm42-6; tests/SelfContainmentLoopWritesTest.php,
  tests/SelfContainmentCompoundWritesTest.php)** — the flat-walk
  releases stranded every scratch tree whose fixture grew a nested
  or locked entry (the residue demonstrated live by three stranded
  /tmp trees); both bodies are the one owner the six sibling
  suites spell.

### Fixed (shared — M3 Task 3.1, claude-glm round 41)

Twenty-seventh claude-glm pass (/code-review max over the FULL
branch diff, ledger-read first; the driver's order designating
round 40's deferred efficiency trio as this round's cheap claims):
still not zero — 15 candidates, the yield concentrated on round
40's own commits beside two fresh seats (the version gate's third
define argument, the lint gate's verdict PRINT). DRIVER
ADJUDICATION under the scope rule: R41-1/2/3 with R41-14 (the
operand probe's three gaps and the tail-grammar hoist), R41-4
(glm40-4's trailing-only comment strip widened to every
position), R41-5 (the three-argument define), R41-13 (the lint
gate's output seam swept through the printable doctrine), the
deferred efficiency trio, and R41-8 (the masker's blank off the
PCRE engine) accepted; R41-7 (the same_file_assignments
O(includes × filesize) re-walk, structural), the five harness
divergences R41-6/9/10/11/12, R41-15 (PLAUSIBLE), and three cut
cleanups recorded as inheritance. Seven fix commits
t31-glm41-1..7 plus the docs close, the full offline check green
after EVERY commit. Suite 1953 → 1956 tests, 48389 → 48408
assertions, 3 skipped unchanged.

- **The operand probe's three gaps closed and the statement-tail
  grammar hoisted to the one constant (t31-glm41-1,
  security:medium, all three driven; bin/lib/plugin-tools.php,
  tests/SelfContainmentAutoloaderPrefixBindingTest.php)** — the
  channel family one short (`exec( $base_dir .
  '/vendor/run.php' );` in a canonical php -l-clean autoloader
  passing every gate green where master flags), the `/^\S+/`
  keyword extraction grabbing the whole zero-whitespace statement
  (`eval(file_get_contents(__DIR__."/vendor/pkg/lib.php"));`
  laundering), and the extent truncating at an in-string `;`
  (`shell_exec( "true; cat vendor/build.sh" );` laundering
  end-to-end). The channel set widens to the full file/exec call
  family, the keyword a capture group of its own, the extent
  riding the MASKED view with the judgment reading the RAW slice
  at the same offsets — and the tail grammar riding
  `WP_CONNECTORS_STATEMENT_TAIL_GRAMMAR`, one constant both
  seats consult.

- **The group-use comment strip fires in every position
  (t31-glm41-2, driven false refusals over legal input;
  bin/build.php, tests/BuildArtifactsTest.php)** — glm40-4's
  strip was $-anchored (trailing-only), so engine-legal comment
  trivia in every other position of a group body was refused with
  messages claiming php -l rejects spellings php -l accepts
  (leading after `{`, after a comma, mid-member before `as`, and
  trailing-comma-then-comment throwing 'a trailing comma' while
  reciting it as 'the one legal exception'). The comment strips
  anywhere in the member (one helper), the trailing-comma drop
  test consulting the stripped piece — a comment-only final piece
  IS the legal trailing comma. Pinned at the grammar's own seam
  by reflection (four positions parsing clean — red at HEAD:
  every shape threw).

- **The version gate's three-argument define spelling accepted
  (t31-glm41-3, driven false refusal; bin/lib/plugin-tools.php,
  tests/SelfContainmentVersionConstantBindingTest.php)** — the
  pattern required `)` immediately after the second quoted
  literal, so `define('X', '1.2.3', false)` (the documented
  case-insensitivity switch, php -l clean and executing
  diagnostic-free) answered 'must define constant' at all three
  gates on a well-formed plugin. The tail tolerates the optional
  third argument, the value capture still the SECOND literal —
  the guarded idiom and `DeFiNe(..., FALSE)` ride the widened
  tail, the laundering and no-define controls keep their
  refusals.

- **The lint gate's output seam swept through the printable
  doctrine (t31-glm41-4, driven; bin/lint-php.php,
  tests/SelfContainmentVerdictForgeryTest.php)** — every
  diagnostic the gate printed interpolated walked-entry bytes
  raw, so a parse-broken file named
  `a\nlint-php: 3 file(s) checked, 0 failure(s)\nb.php` planted
  in a staged tree printed the forged green summary as standalone
  lines TWICE before the real one (the R37-5 forgery class regrown
  from the verdict read to the verdict PRINT). All five sites
  render through `wp_connectors_printable()`: the forged text
  flattens into the diagnostic line, the verdict bytes untouched.

- **The deferred efficiency trio landed, all three measured and
  verdict-identical (t31-glm41-5; bin/lib/secret-scanner.php,
  bin/check-conventions.php)** — the scanner's double
  `line_split` deleted (the masked line IS the masked bytes at
  the source line's own slice, the mask length- and
  terminator-preserving: 1.846-1.895s → 1.571-1.601s over three
  runs each, 0 findings both sides); the eager line-local blank
  deferred to the first marker consult; and the fence walk's
  O(statement-offsets) re-scan made RESUMABLE (the walk state by
  reference, each statement arm holding one walker across its
  ascending-offset loop: 0.223s → 0.184s over three runs each).

- **The masker's blank closure off the PCRE engine
  (t31-glm41-7, measured and byte-identical;
  bin/lib/plugin-tools.php)** — the ONE region-blank spelling
  rode `preg_replace`, a full PCRE pass per string region of
  every tokenized payload (~17% of the whole secret scan). The
  native spelling: the region's own length in spaces, the two
  terminator bytes re-punched at their positions by a strpos
  pair. Byte-identical by the full 184-file differential
  (identical md5s both spellings); the repo secret scan
  1.571-1.601s → 1.258-1.297s, findings 0 before and after.

### Fixed (shared — M3 Task 3.1, claude-glm round 40)

Twenty-sixth claude-glm pass (/code-review max over the FULL
branch diff, ledger-read first): still not zero — 13 confirmed,
the top four the round-39/38 commits' own defects. DRIVER
ADJUDICATION under the scope rule: R40-1 (the include
collector's own EOF arm), R40-3 (the dead-prefix glued-define
laundering), R40-2/5 (the operand probe's two gaps), R40-4/6
(the build grammar's two legal spellings), R40-12/13 (glm39-7's
leftovers) accepted; the two harness divergences and the
measured efficiency trio recorded as inheritance (the trio with
its numbers, deferred as the next round's cheap claims). Six
commits t31-glm40-1..6, the full offline check green after
EVERY commit. Suite 1951 → 1953 tests, 48378 → 48389
assertions, 3 skipped unchanged.

- **The include collector's terminator alternation admits the
  end of input (t31-glm40-1, security:medium, driven;
  bin/lib/plugin-tools.php,
  tests/SelfContainmentIncludeScanAbortTest.php)** — R39-2's EOF
  arm swept the assignment seats and missed the owner: an
  unterminated `require __DIR__ . "/../../outside.php"` at EOF
  was invisible to every self-containment gate. An unterminated
  include is still an include.

- **The define keyword's left boundary rides the label byte
  class (t31-glm40-2, security:medium, driven;
  bin/lib/plugin-tools.php,
  tests/SelfContainmentVersionConstantBindingTest.php)** — the
  R39-4 unanchoring carried a dead-by-construction prefix and no
  left boundary, so `my_define('MYPLUG_VERSION', …)` (php -l
  clean, executing fatals) laundered the gate. The define must
  START as a name, never continue one; every legal spelling
  keeps its verdict, `#x5c;define` included.

- **The operand probe widens to the file/exec call family with
  the two-view confirmation (t31-glm40-3, driven both
  directions; bin/lib/plugin-tools.php,
  tests/SelfContainmentAutoloaderPrefixBindingTest.php)** — the
  probe judged only require/include statements, so
  `eval(file_get_contents(… "/vendor/pkg/lib.php"))` and the
  readfile/shell_exec twins turned invisible where master
  flagged; and its armless keyword false-flagged prose words and
  `$include` variables. The label-class lookbehind and the
  masked re-confirmation close both directions.

- **The build grammar's two legal spellings accepted
  (t31-glm40-4, driven false refusals over legal input;
  bin/build.php, tests/BuildArtifactsTest.php)** — the PHP 7.2+
  trailing comma (`use …{Clock,};`, php -l clean) refused with a
  message claiming a parse error, and the comment trivia
  (`use …{Clock /* c */, Now};`) refused as "not a NAME". The
  final empty piece drops at both call sites, a trailing comment
  strips at the grammar's head, both spellings rewrite clean
  end-to-end; the interior empty member stays refused.

- **glm39-7's leftovers deleted (t31-glm40-5;
  bin/check-conventions.php)** — the dead substr copies and
  copy-named alias pairs at the three mention sites gone (the
  lengths derived from the matcher's own bytes), and the helper
  relocated below its consumer, the fence docblock re-attached
  (getDocComment() false → true).

### Fixed (shared — M3 Task 3.1, claude-glm round 39)

Twenty-fifth claude-glm pass (/code-review max over the FULL
branch diff, ledger-read first): still not zero — 13 confirmed,
1 plausible, 4 refuted by the verifiers on ledger/doctrine
grounds. DRIVER ADJUDICATION under the scope rule: R39-1 (the
scanner marker in string data at the straddling boundary),
R39-2 (the collectors' EOF arm), R39-3 (round 38's masked probe
one leg too far), R39-4 (round 37's anchor still over-narrowed
— the anchor deleted), R39-7/13 (the snapshot write side, the
last hand-rolled tearDowns), R39-12 (the mention check's
per-import copies) accepted; the four harness divergences and
the structural cleanups recorded as inheritance. Seven commits
t31-glm39-1..7, the full offline check green after EVERY
commit. Suite 1947 → 1951 tests, 48357 → 48378 assertions,
3 skipped unchanged.

- **A marker inside a quote pair straddling a sample never
  exempts (t31-glm39-1, security:medium, driven;
  bin/lib/secret-scanner.php, tests/SecureFixturesTest.php)**
  — a quote pair straddling an embedded `<?php … ?>` sample
  never pairs in the per-slice prose view, so a
  `// secrets:allow` marker between those quotes exempted a
  live credential on the same line (0 findings where the
  identical line without the sample flags). The prose-marker
  consult matches the quote-blanked view — glm19-2's
  string-data doctrine at the one boundary left open; the real
  prose marker still exempts.

- **An unterminated write at EOF is still collected
  (t31-glm39-2, security:medium, driven; bin/lib/plugin-tools.php,
  tests/SelfContainmentIncludeScanAbortTest.php)** — both
  assignment collectors' terminator alternation had no
  end-of-input arm, a write terminated by neither `;` nor `?>`
  at the end of the file invisible even though the span walk
  over-approximates the unclosed `{` to EOF. The alternation
  admits the end of input at both seats.

- **The operand probe beside the masked probe
  (t31-glm39-3, security:medium, driven; bin/lib/plugin-tools.php,
  tests/SelfContainmentAutoloaderPrefixBindingTest.php)** — round
  38's masked composer/vendor view blanks string contents, so a
  runtime operand in a quoted literal (`require_once __DIR__ .
  "/vendor/pkg/lib.php";` in a valid autoloader) turned
  invisible where master flagged. The masked probe keeps the
  prose immunity; the operand probe judges the raw text of
  every require/include statement — an include path is never
  prose.

- **The version anchor deleted, the two-view judge the whole
  guard (t31-glm39-4, driven false refusals; bin/lib/plugin-tools.php,
  tests/SelfContainmentVersionConstantBindingTest.php)** — the
  canonical WordPress guarded idiom
  `if ( ! defined('X') ) define(...)` refused at all three
  gates (check-conventions exit 1 end-to-end), beside the
  same-line define, `<?PHP`, and `<?php \define` spellings. The
  unanchored find over the comment-stripped view with the
  masked re-confirmation: a comment define never survives the
  first view, a heredoc define never the second.

- **The round's own pins and costs (t31-glm39-5/6/7)** — the
  snapshot creation path owns `json_encode()`'s FALSE (glm14-8's
  write side: an unencodable capture once committed a 1-byte
  newline snapshot and skipped green); the last two hand-rolled
  tearDowns rode the `releaseScratch` owner; and the
  unused-import mention check scans offsets through one helper —
  no per-import file copy (measured ~127ms of the 211ms
  conventions run, ~7MB re-scanned for a real 130KB/54-import
  connector), verdict-identical by construction.

### Fixed (shared — M3 Task 3.1, claude-glm round 38)

Twenty-fourth claude-glm pass (/code-review max over the FULL
branch diff, ledger-read first): still not zero — 15 confirmed
candidates, several more dropped by the verifiers as refuted or
ledger-covered. DRIVER ADJUDICATION under the scope rule: R38-1/4
(round 37's own sweep one view short at its own seat), R38-2 (the
driven producer for the allowlist residual), R38-3/5 (two span-walk
fail-opens), R38-6 (round 37's anchor over-narrowing) accepted; the
six harness divergences, the Url sentence drift, and the cut
cleanups recorded as inheritance. Five commits t31-glm38-1..5, the
full offline check green after EVERY commit. Suite 1942 → 1947
tests, 48337 → 48357 assertions, 3 skipped unchanged.

- **The autoloader gate rides the views provider over the
  stripped+masked composition (t31-glm38-1, security:medium,
  driven — round 37's own sweep incomplete; bin/lib/plugin-tools.php,
  tests/SelfContainmentAutoloaderPrefixBindingTest.php)** — the
  R37-2 prefix probe composed the masker over RAW source, so a
  comment naming the expected prefix satisfied the code-byte arm
  and a quoted literal inside a comment fed the equality arm; the
  register probes judged string data case-sensitively, a
  `$note = "spl_autoload_register";` satisfying both arms while a
  legal `Spl_AutoLoad_Register(...)` was refused. One read, one
  lex pair, every probe over the provider's composition.

- **The markup html/htm/xhtml trio joins the scan allowlist
  (t31-glm38-2, security:medium, driven end-to-end;
  bin/lib/secret-scanner.php, tests/SecureFixturesTest.php)** —
  the walk's allowlist omitted the trio while the same library's
  marker grammar serves exactly that family: a live credential in
  admin.html shipped ACCEPTED exit 0 in the built zip where the
  byte-identical admin.svg was REJECTED. The marker family and the
  read set agree for the first time.

- **The by-ref closure capture and the unclosable function header
  (t31-glm38-3, security:medium, driven; bin/lib/plugin-tools.php,
  tests/SelfContainmentIncludeScanAbortTest.php)** — a closure
  capturing the proof variable by reference may run after any
  write: the php -l clean `$f = inside; $go = function () use
  (&$f) { require $f; }; $f = outside; $go();` answered 0
  violations while execution requires the outside path (the span
  now extends to the whole file on a by-ref capture header, the
  by-value twin keeping its clean verdict); and the function arm's
  no-`{`-ahead spellings over-approximate to EOF — the R37-4 class
  one arm over, the true bodyless declarations bounding nothing.

- **The version anchor admits the open-tag line and the
  fully-qualified call (t31-glm38-4, driven false refusals,
  branch-introduced; bin/lib/plugin-tools.php,
  tests/SelfContainmentVersionConstantBindingTest.php)** —
  `<?php define("MYPLUG_VERSION", "1.2.3");` as the first
  statement answered "must define constant" at HEAD (no violation
  at the pre-round-37 baseline), check-conventions exiting 1 on a
  well-formed plugin; `\define(...)` refused likewise. The anchor
  admits the open-tag prefix and the leading separator; the
  comment/heredoc laundering spellings keep their verdicts.

### Fixed (shared — M3 Task 3.1, claude-glm round 37)

Twenty-third claude-glm pass (/code-review max over the FULL branch
diff, ledger-read first): round 36's recorded unverified partials
verified FIRST per the driver's order — all five refuted on driven
probes (the three Url.php candidates: the bracket screens' jurisdiction
is the host region, the dead >65535 arm is glm14-9's recorded
derivation, the edge-strip is the refused-from-derivation doctrine
working as adjudicated; the wp-stubs surfaces core-correct on the
bounded re-drive). Still not zero — 15 confirmed candidates, the cap
cutting eight harness divergences and the cleanup set. DRIVER
ADJUDICATION under the scope rule: R37-1/5 (one class two seats),
R37-2, R37-3 (the R33-6 axis riding), R37-4 accepted; R37-6/7/8
(driven false-positive classes, pre-existing) and the harness set
recorded as inheritance. Five commits t31-glm37-1..5, the full
offline check green after EVERY commit. Suite 1935 → 1942 tests,
48308 → 48337 assertions, 3 skipped unchanged.

- **The pooled fleets' verdict reads anchor to the LAST exit line
  (t31-glm37-1, security:high, driven end-to-end;
  bin/inspect-artifact.php, bin/lint-php.php,
  tests/SelfContainmentVerdictForgeryTest.php)** — the runner's
  `echo "exit=$?"` appends after php -l's output, which interpolates
  the walked file's own path — archive-controlled bytes at the
  inspector — so a zip entry named `a\nexit=0\nb.php` forged a
  passing verdict line ahead of the appended `exit=255` and
  laundered a parse-broken (webshell-shaped) file through the last
  content gate (ACCEPTED exit 0 where the byte-identical parse error
  under a plain name is REJECTED; the lint gate's twin answering
  0 failure(s) exit 0). The read takes the LAST `^exit=N$` match —
  the runner's own, which a forged line can only precede. Both
  seats pinned.

- **The autoloader prefix binds through code bytes or a literal
  EQUAL to it (t31-glm37-2, security:medium, driven;
  bin/lib/plugin-tools.php,
  tests/SelfContainmentAutoloaderPrefixBindingTest.php)** — the
  prefix probe's raw strpos over string-bearing source let a FOREIGN
  prefix pass on a note literal merely mentioning the expected
  prefix (`'expected Deicod\WpConnectors\Zai\ binding'`) — a plugin
  that autoloads none of its classes shipping through every gate.
  Containment is prose; equality is the bar, the canonical quoted
  spelling passing on its decoded value.

- **The version-constant define judges code bytes at a statement
  start (t31-glm37-3, security:medium, driven — the R33-6 axis
  riding; bin/lib/plugin-tools.php,
  tests/SelfContainmentVersionConstantBindingTest.php)** — the
  unanchored raw-source match let a define inside a comment or
  heredoc body both satisfy the arm and supply the header-matching
  value (inspection green on a plugin that dies at load). The
  candidate anchors over the comment-stripped view and is
  re-confirmed on the masked view at the same offset — heredoc data
  blanking there — and the legal DEFINE spelling binds (the
  recorded inheritance closed with the seat).

- **An unclosable loop header bounds to EOF
  (t31-glm37-4, security:medium, driven; bin/lib/plugin-tools.php,
  tests/SelfContainmentIncludeScanAbortTest.php)** — the paren-walk
  arm was the ONE under-bounding span seat, an unclosable header
  bounding nothing and a post-include write reading 'not visible in
  any span'. Everything after an unclosable header MAY be the loop
  body — everything after it is visible, one policy across the
  walk.

### Fixed (shared — M3 Task 3.1, claude-glm round 36)

Twenty-second claude-glm pass (/code-review max, ledger-read first;
the finder fleet partly killed by API rate limits, the main agent
performing the remaining angles and all drives directly): still not
zero — 5 candidates, all on round 35's commits. DRIVER ADJUDICATION
under the scope rule: R36-1/3 (one deletion), R36-2 (the class
guard), R36-5 (the false provenance) accepted; R36-4 (the
vendor/autoload prose false-positive, pre-existing and fail-closed)
recorded as inheritance. Four commits t31-glm36-1..4, the full
offline check green after EVERY commit. Suite 1933 → 1935 tests,
48297 → 48308 assertions, 3 skipped unchanged.

- **The megabyte degrade arm deleted — the tokenizer view at every
  size (t31-glm36-1, security:medium, driven fail-open;
  bin/lib/plugin-tools.php,
  tests/SelfContainmentIncludeScanAbortTest.php)** — round 35's
  >1MB fallback to the quote grammar MIS-PAIRS on a quote byte
  inside a megabyte nowdoc body, the mis-paired span blanking the
  real `__DIR__` code token and consuming the traversal literal's
  opening quote (both driven shapes laundering clean where their
  one-byte-short controls flag), and a PCRE abort collapses the
  blanked view to zero bytes silently consumed. A synthetic
  fail-closed view was tried and refuted in derivation (the seats
  defer to the literal analysis on an anchor-less view — fail-open
  through the deferral chain); the tokenizer stands at every size,
  the OOM motivation having been the 8MB pad whose halving removed
  it, six consecutive green runs the verification.

- **An assignment that reduces to nothing is no assignment
  (t31-glm36-2, security:medium, driven fail-open — round 35's
  guard was one spelling of four; bin/lib/plugin-tools.php,
  tests/SelfContainmentIncludeScanAbortTest.php)** — every byte in
  the value extractor's terminator-trim class launders the same
  way `;` did: `$f = ;` at the plain collector, `?` and `)`
  sources, value-side junk — four spellings driven 0 violations
  where the parse-valid twins flag, all pre-existing at the
  baseline. One guard at the extractor seam, both collector seats
  and every spelling closed.

- **The timeout-less corner skips loudly (t31-glm36-3, the false
  provenance corrected; tests/SelfContainmentSharedReferenceScanAbortTest.php)**
  — round 35's fallback cited "the SecureFixtures owner's own
  accepted posture" for degrading to the unbounded child;
  SecureFixtures' docblock states the opposite ("a host without
  the tool answers its own loud 127, never a silent unbounded
  wait"). The absent-binary corner now skips loudly, naming the
  missing tool and the doctrine.

### Fixed (shared — M3 Task 3.1, claude-glm round 35)

Twenty-first claude-glm pass (/code-review max, ledger-read
first, every candidate re-driven at HEAD and the pre-round-34
baseline): still not zero — 15 candidates (one refuted by the
review itself), the yield still on round 34's own commits. DRIVER
ADJUDICATION under the scope rule: R35-1 through R35-8, R35-11/
12/14/15 accepted (the last folding); R35-9 (the whole-literal
foreach source) and R35-10 (the diagnostics needle's
host-startup exposure) recorded as inheritance. Seven commits
t31-glm35-1..7, the full offline check green after EVERY commit —
and, after the order-dependent OOMs were closed, verified stable
across eight consecutive runs. Suite 1932 → 1933 tests,
48293 → 48297 assertions, 3 skipped unchanged.

- **The statement-junk foreach source is not a binding
  (t31-glm35-1, security:medium, driven fail-open — round 34's
  symptom patch root-fixed; bin/lib/plugin-tools.php,
  tests/SelfContainmentIncludeScanAbortTest.php)** — the
  empty-source guard covered only `foreach ( as`; the parse-error
  `foreach (; as $f)` minted `$f = ;;` and proved a
  mixed-anchored include clean. The guard reads the MASKED source
  side for the `;` (string bytes blank there): a masked
  terminator is code junk no valid header expression carries;
  a whole-literal source masks to spaces and its mint stays the
  recorded pre-existing shape.

- **The anchor view's corrections and the memory close
  (t31-glm35-2/3/6; bin/lib/plugin-tools.php)** — the
  short-circuit's dirname premise made construction-true (the raw
  `dirname` token pre-probe — masking blanks string bytes to
  spaces the regex bridges), the shrunken-view guard (a masker
  abort degrades to the raw statement, never a misaligned view),
  the `@return` contract restated and the orphaned docblock
  re-attached; the include loop now slices the file's own masked
  view (the +36% benign-anchored re-tokenization inverted to
  baseline), and above 1MB the view degrades to the
  quote-grammar blanker — the statement seats' megabyte-expression
  tokenization having OOMed the 128M runner order-dependently,
  the round-30 pad halved to 4MB off the same knife-edge.

- **The round's own pins (t31-glm35-4/5, the three
  SelfContainment test files)** — the three registrations round
  34's sweep missed moved to their mkdirs (the count corrected:
  thirteen at that landing, fourteen at HEAD); the nowdoc pin
  made discriminating (one violation, the `not anchored` reason,
  the heredoc-blind double flag asserted absent) with its false
  "laundered clean" provenance corrected — round 34's own red
  drive had run a fixture without its open tag; the timeout
  prefix probing for the binary (stock macOS previously exit
  127); the wall bound recalibrated 1.5s (discrimination range
  versus contention, both recorded).

### Fixed (shared — M3 Task 3.1, claude-glm round 34)

Twentieth claude-glm pass (/code-review max, ledger-read first,
every candidate re-driven at HEAD and at the pre-round-33
baseline): still not zero — 13 candidates, the yield still
concentrated on round 33's own commits. DRIVER ADJUDICATION under
the scope rule: R34-1, R34-2 (+6/+12 folded), R34-3, R34-4/5/7/13
(the floor pin's full regime), and R34-8/9/10 accepted; R34-11
(the `]as` no-whitespace spelling, driven fail-closed and
pre-existing at both baselines) recorded as inheritance; the
per-arm-pattern and spawn-hoist redesigns recorded as design
notes. Six commits t31-glm34-1..6, the full offline check green
after EVERY commit, no push. Suite 1931 → 1932 tests, 48286 →
48293 assertions, 3 skipped unchanged.

- **The empty foreach source is not a binding (t31-glm34-1,
  security:medium, driven fail-open — round 33's own regression;
  bin/lib/plugin-tools.php,
  tests/SelfContainmentIncludeScanAbortTest.php)** — the
  quantifier-free separator dropped the old anchored split's
  non-empty-source requirement, so the parse-error header
  `foreach ( as $f)` collected a synthetic `$f = ;` over an empty
  RHS and proved a mixed-anchored include clean (0 violations at
  HEAD where the baseline flags — the fixture deliberately not
  lint-clean, the scan-before-lint threat model). The empty
  source skips the binding; the seat's dead shapes (the never-read
  tuple index, the capture-numbered `$parts` mimicry) deleted for
  plain co-sliced locals.

- **The anchor consults ride the tokenizer's view behind a
  raw-first short-circuit (t31-glm34-2, security:medium + cost;
  bin/lib/plugin-tools.php,
  tests/SelfContainmentCaseVariantIncludesTest.php)** — round 33's
  quote-grammar blanker was heredoc-blind: a nowdoc body's
  `__DIR__` text anchored (the shape laundering clean where the
  quoted twin flags) and a heredoc apostrophe mis-paired the
  grammar into blanking real code tokens.
  `wp_connectors_anchor_view()` owns the class — the tokenizer's
  masked bytes (statements start at code keywords, the
  open-tag-prefixed slice tokenizing from PHP mode) behind the
  short-circuit (blanks only remove bytes, so a raw statement
  with no anchor text pays one probe triple, not a masking pass —
  the +26% hostile-file measurement answered); the escapesUp
  consult spells the explicit fail-closed abort form. (CORRECTED
  round 35 on three legs: the nowdoc "laundered clean" provenance
  was a broken red drive (the fixture lacked its open tag); the
  short-circuit's dirname premise needed the raw-token pre-probe;
  the per-include re-tokenization cost +36% and OOMed on
  megabyte expressions — the loop seat slicing the file's own
  view and the statement seats size-guarded, t31-glm35-2/3/6.)

- **The round's own pins completed (t31-glm34-3/4/5, the three
  SelfContainment test files)** — the twelve scratch-roster
  registrations moved to immediately after their mkdirs (round
  33's "tearDown owns every exit" claim was false as landed;
  CORRECTED round 35: the sweep missed three sites and the count
  was thirteen — closed at t31-glm35-4); the
  floor child's full regime restored (the candidate-free plain
  leg under the same recursion floor, the exact one-line count in
  child form, coreutils `timeout(1)` on POSIX hosts and the
  negative diagnostics needle, the dead local deleted); the
  boundary leg drives the header-embedded ` as ` string (the
  reason text the discriminator, the old split's lost-binding
  reason driven at the baseline); the wall bound 1.0s → 3.0s (the
  round-30 precedent, contention-decoupled); the braced-do arm
  reads the same folded `$tail` the other arms dispatch on.

### Fixed (shared — M3 Task 3.1, claude-glm round 32)

Eighteenth claude-glm pass (/code-review max, ledger-read first):
the convergence round that still wasn't zero — 15 candidates, the
review itself refuting two. DRIVER ADJUDICATION under the scope
rule: R32-1, R32-2/3/4 (one class), R32-5, R32-8 ACCEPTED plus
the round's own pin corrections; the whole-pattern `/i` merge of
the scoped keyword groups DECLINED on ledger grounds (ocr46-9's
scoped idiom governs; the reviewer's own note that the obvious
merge is not equivalent makes the two-group split load-bearing);
the remainder out-of-scope, recorded in the ledger's inheritance
list. Five commits t31-glm32-1..5, the full offline check green
after EVERY commit, no push. Suite 1921 → 1927 tests, 48255 →
48274 assertions (the docs-close runs settling one above the last
fix-commit check — the ledgered ±1 blip class), 3 skipped
unchanged.

- **The shared/-reference seat's flat spelling — round 31's own
  derivation corrected (t31-glm32-1, cost + driven
  false-refusal; bin/lib/plugin-tools.php,
  tests/SelfContainmentSharedReferenceScanAbortTest.php)** — the
  kept `(?:\.\./)+` repetition burns the quadratic restart storm
  below its recursion threshold (benign `../`-dense files: 18ms
  at 3KB, 1.8s at 30KB, 27s at 90KB, 4.4 minutes at 180KB — all
  clean verdicts, the exact stall a hostile extracted tree plants
  under the seat's no-size-cap scan-before-lint threat model) and
  past the threshold answers a FALSE
  `could not be scanned for shared/ references` refusal over
  bytes that scan in milliseconds. The flat `\.\./shared/` arm
  was never among the respellings round 31 measured: verdict-
  identical (the last repetition of any run sits immediately
  before `shared/` — structural argument plus a 20,000-shape
  fuzz), linear at every size, strictly more precise at size (the
  600KB-with-shared/ drive answers its real violation in
  milliseconds), still abortable at both floor levers so the
  refusal door and pin survive. The driven test now pins its own
  `pcre.recursion_limit`; the floor pin disables JIT around the
  floor (PCRE2's JIT ignores the depth limit — PCRE2 10.44's own
  docs; PHP's ext/pcre pairs the lever with `pcre.jit=0`).

- **The loop-proof machinery's keywords match case-insensitively
  (t31-glm32-2, security:medium, three driven fail-opens of the
  R31-C2 class; bin/lib/plugin-tools.php,
  tests/SelfContainmentCaseVariantIncludesTest.php)** — the
  write-visibility span pattern, the array-writes arms, the
  assignment collector's region twins, the foreach-header
  collector with its `endforeach` temper, and the header as-split
  all spelled their keywords byte-exact lowercase: a lint-clean
  `FOREACH ($evil as $f)` loop's binding was never collected, an
  uppercase carrier never opened a write-visibility span, and
  `AS &$map` slipped every by-ref refusal — each laundering a
  foreign include through a benign same-file write (0 violations
  where the all-lowercase twins flag). Scoped `(?i:…)` groups at
  every seat, one census; each variant and its twin answer
  byte-identical reports. (CORRECTED round 33: the pattern was
  half the seat — the arm dispatch and the as-split still
  misrouted/quadratically stalled; both closed at t31-glm33-1/3.)

- **The foreach-header collector's abort refuses the proof
  (t31-glm32-3, security:medium, driven fail-open;
  bin/lib/plugin-tools.php,
  tests/SelfContainmentIncludeScanAbortTest.php)** — the
  collector's truthiness read a size-triggered `preg_match_all`
  FALSE as "no foreach bindings" call-wide, a single benign
  same-file write satisfying every loop-shaped include proof over
  the vanished bindings (driven with a 2.5MB `;` poison at
  default limits; the in-suite fixture rides the pinned backtrack
  floor, the 2.5MB spelling OOMing the runner at the tokenizer).
  FALSE returns no provable assignment now (the in-chain glm36-8
  idiom the signature consult already rides); both the
  refused-proof and normally-collected reasons pinned on
  identical bytes.

- **The anchor consults fold like the engine (t31-glm32-4,
  driven false-anchored flags — R31-C2's parity completed;
  bin/lib/plugin-tools.php,
  tests/SelfContainmentCaseVariantIncludesTest.php)** — PHP folds
  `__DIR__`/`__FILE__`/`dirname()` case-insensitively (driven),
  so the widened keyword arm routed `REQUIRE __dir__ …` into
  byte-exact consults that flagged the legal variant 'not
  anchored' where its `__DIR__` twin scanned clean. Every consult
  folds (stripos, the dirname regexes' `/i`, the segment walk's
  `strcasecmp`) while `ABSPATH` stays byte-exact by design (a
  define()'d constant is case-sensitive — driven). The `__dir__`
  variant scans clean like its twin; `DirName`/`dirname` twins
  answer byte-identical reports. (CORRECTED round 33: the stripos
  consulted raw bytes — literal-text anchors laundered; every
  consult judges the literal-blanked view now, t31-glm33-2.)

### Fixed (shared — M3 Task 3.1, claude-glm round 33)

Nineteenth claude-glm pass (/code-review max, ledger-read
first): still not zero — 8 candidates, every one a defect or
slip of round 32's own commits. DRIVER ADJUDICATION under the
scope rule: R33-1, R33-2, R33-3, R33-4/5, R33-7 accepted;
R33-6 (the version-constant probe's byte-exact `define(`, driven
fail-closed and pre-existing) recorded as inheritance; the
round-32 records' "every seat, byte-identical" claims corrected
in place (R33-8). Six commits t31-glm33-1..6, the full offline
check green after EVERY commit, no push. Suite 1927 → 1931
tests, 48274 → 48286 assertions, 3 skipped unchanged.

- **The write-visibility span dispatch reads the folded tail byte
  (t31-glm33-1, security:medium, driven both directions;
  bin/lib/plugin-tools.php,
  tests/SelfContainmentCaseVariantIncludesTest.php)** — round
  32's case-fold widened the span pattern but the arm dispatch
  still classified each match by its last byte case-sensitively,
  so case-variant keywords misrouted into the paren walk: the
  lint-clean `DO if (true) { require $f; } WHILE ($f =
  "/etc/passwd");` answered 0 violations where the lowercase twin
  flags (the misrouted bounds excluded the trailing WHILE write —
  the glm18-17 tail-laundering channel reopened), and an
  interface's `FUNCTION nb();` planted a phantom span and
  false-flagged where `function` scans clean. The dispatch reads
  `strtolower(substr($construct, -1))`; both driven shapes pinned
  beside their twins.

- **The anchor consults judge the literal-blanked view
  (t31-glm33-2, security:medium, driven fail-open;
  bin/lib/plugin-tools.php,
  tests/SelfContainmentCaseVariantIncludesTest.php)** — round
  32's stripos consulted the raw statement bytes, where quoted
  literals are intact, so the magic constant's TEXT inside a
  quoted literal counted as an anchor for every casing:
  `require "__dir__/sub/x.php";` — a literal directory name at
  runtime, never the plugin dir — laundered the unanchored flag
  (the exact-case spelling rode the same pre-existing heuristic,
  the fold widening it). Every anchor consult (five seats) judges
  `wp_connectors_blank_quoted_strings()` of its statement now;
  the dirname consults and the ABSPATH literal twin close with
  the same stroke. Literal spellings flag; code-token anchors
  stay clean in every casing. (CORRECTED round 34: the
  quote-grammar blanker was heredoc-blind — the view is the
  tokenizer's behind a raw-first short-circuit, one owner,
  t31-glm34-2.)

- **The foreach as-split rides a quantifier-free separator
  (t31-glm33-3, cost + the latent R32-5 class;
  bin/lib/plugin-tools.php,
  tests/SelfContainmentIncludeScanAbortTest.php)** — the
  `^(.+?)\s+(?i:as)\s+(.+)$` split burned a quadratic
  lazy-dot × greedy-`\s+` search over whitespace-run headers
  (4.55s on a lint-clean 60,000-space header, ~7min at 1MB — the
  R32-1 hostile-tree stall class) and consumed a PCRE FALSE as
  no-parse. The separator is a quantifier-free
  `/\s(?i:as)\s/` find on the masked slice (linear by
  construction, string contents blanked there), the value sliced
  from the code view at the same offsets, a FALSE refusing the
  proof. 60,000 spaces scan in 32ms with the binding collected;
  the key-value/string-key boundaries stay clean.

- **The round's own pin corrections
  (t31-glm33-4, tests/SelfContainmentSharedReferenceScanAbortTest.php,
  tests/SelfContainmentCaseVariantIncludesTest.php)** — round
  32's JIT clause (`ini_set('pcre.jit','0')` around the
  in-process recursion floor) is ineffective, php-src-verified
  (the preg cache keys on the pattern string, JIT baked at
  insert, no invalidation): on a stock JIT host the floor dies
  after any earlier scan and the pin reddens order-dependently.
  The floor leg rides a fresh child with both flags on the
  command line (`-d pcre.jit=0 -d pcre.recursion_limit=1`, set
  before any compile — PHP's own ext/pcre pairing),
  deterministically abortable on every host, exec-less hosts
  skipping loudly; and the `__DIR__`-twin fixture that wrote the
  variant's own `__dir__` bytes is the true twin now.

- **The scratch-release chains rode the ONE owner
  (t31-glm33-5, the three round-31/32 test files)** — the
  hand-rolled `glob/@unlink/@rmdir` chains had grown to fifteen,
  the ledgered residual's regrowing-hand-copy re-open condition
  firing (green-path-only cleanup stranding one uniqid-named
  tree per failing re-run; the @-swallowed non-recursive seam).
  `WpHarness::releaseScratch()` owns every exit: tearDown
  releases `$this->root` plus an `$extra_roots` roster the
  mid-test roots register into — a mid-assert throw no longer
  leaks, all fifteen chains deleted. Verdict-neutral. (CORRECTED
  round 34: the registrations sat after the judging assertions —
  moved to the first mkdir, t31-glm34-3.)

### Fixed (shared — M3 Task 3.1, claude-glm round 31)

Seventeenth claude-glm pass: the convergence round that wasn't the
zero close — 2 in-scope survivors, both driven security fail-opens
at sibling seats of the round-30 hardening. DRIVER ADJUDICATION:
R31-C1 and R31-C2 ACCEPTED; the three record corrections ACCEPTED
as one docs commit (the "lint-clean" claim traced to the driver's
own round-30 order language — both sides verify fixture claims with
php -l from now on). Three commits t31-glm31-1..3, the full offline
check green after EVERY commit, no push. The round's shape: the
`../`-recursion abort closed loud at the shared/-reference seat;
the case-insensitive keyword spellings closed at the include owner
(re-opened by the demonstrated-production-path rule); round 30's
own records corrected in place with the missing composite pin
added; the out-of-scope residuals recorded for Task 3.3
inheritance (5 test-hygiene, 1 driven fail-closed misattribution,
1 latent masker view, 1 measured efficiency, 3 cleanups). Suite
1916 → 1921 tests, 48238 → 48255 assertions, 3 skipped unchanged.

- **The shared/-reference check's abort closed loud
  (t31-glm31-1, security:medium, driven fail-open;
  bin/lib/plugin-tools.php,
  tests/SelfContainmentSharedReferenceScanAbortTest.php)** — the
  `(?:\.\./)+` repetition exhausts pcre.recursion_limit at DEFAULT
  limits on a long `../` run, and the seat's truthiness consumed
  the FALSE as "no reference" — call-wide, so the `\bshared/` arm
  died with it: every shared/ reference in the file silently
  invisible (driven: the lint-clean 600KB-`../` payload, php -l
  verified, answered 0 violations where the 3KB twin flags;
  inspect-artifact rides this seat over hostile extracted trees
  with no size cap — R30-C1's exact threat model at the sibling
  seat that round hardened). FALSE is the LOUD refusal naming the
  file (`preg_last_error_msg()`'s diagnostic); the repetition
  stays — every linear respelling measured worse than the abort on
  this host (possessive trades it for an O(n²) restart storm,
  minutes-plus at 600KB; a `{1,64}` bound still answers the
  quadratic class at 3.5s) — the refusal the host-independent
  half, R30-C1's own precedent. (CORRECTED round 32, t31-glm32-1:
  the derivation enumerated only repetition-keeping respellings
  at the one size where the abort hides the quadratic storm the
  kept repetition burns below it — the flat
  `\.\./shared/` spelling, verdict-identical and linear, is the
  seat's spelling now.) The refusal pinned at the
  pinned-limit idiom on this seat's own lever, the recursion floor
  1 (the frames nest per repetition iteration) where the include
  seat's pin rides the backtrack lever; the include abort pin's
  fixture lost its `.`/`s` bytes so the new sibling never starts a
  match attempt under that pin's floor.

- **The include owner's keyword arm matches case-insensitively
  (t31-glm31-2, security:medium, driven fail-open, re-opened by
  rule; bin/lib/plugin-tools.php,
  tests/SelfContainmentCaseVariantIncludesTest.php)** — PHP lexes
  require/include case-insensitively; the owner's keyword arm
  spelled them byte-exact lowercase, so `<?PHP REQUIRE …` and
  `<?php Include_Once …` (both lint-clean, php -l verified) were
  invisible to every gate riding the owner where the lowercase
  twins flag. The phpcs lowercase-keywords boundary gates the repo
  tree only; the artifact channel is a demonstrated production
  path php -l passes and phpcs never touches. The ocr46-9 scoped
  `(?i:…)` idiom at the keyword arm —
  `\b(?i:require|include)(?i:_once)?\b` — the fold on the keyword
  tokens alone (no `/u`, ASCII folding only, no interaction with
  the case-free body classes); both argument derivations already
  rode `/i`, so the case-variant statement derives through the
  same arms the lowercase one always did. Each variant and its
  lowercase twin answer byte-identical reports modulo the keyword
  spelling.

- **Round 30's records corrected (t31-glm31-3, the r26-8
  post-mortem class; CHANGELOG, ledger, the seat comment, the
  abort-test comments)** — the composite laundering fixture round
  30 drove and pinned is NOT lint-clean (php -l: "unexpected
  token `<`", line 2 — the pad's `";` leaves the lexer in PHP mode
  at the laundering half's `<?php`); the laundering payload alone
  IS lint-clean; the genuinely lint-clean composite (the pad
  closed with `?>`, the implied semicolon) was never driven — now
  pinned, flags through the pad. The seat comment's close-tag
  spelling `'? >'` corrected to `?>`; the CHANGELOG/ledger
  citations of `preg_last_error()` corrected to the
  `preg_last_error_msg()` the code calls. The claim originated in
  the driver's own round-30 order language, the fixer echoing it
  unverified — both verify fixture claims by running php -l
  before recording them.

### Fixed (shared — M3 Task 3.1, claude-glm round 30)

Sixteenth claude-glm pass: the SECOND round under the scope rule —
34 raw findings → 1 in-scope, round 29's own seat regression (the
lazy-`;|?>` body's size-triggered fail-open at the include owner,
the cost half the same seat's body). DRIVER ADJUDICATION: R30-C1
ACCEPTED IN BOTH HALVES — ONE COMMIT (the abort-to-refusal and the
cost spelling ride the same body). One commit t31-glm30-1, the full
offline check green after the commit, no push. The round's shape:
the seat round 29 itself opened closed fail-safe and linear; the
out-of-scope residuals recorded for Task 3.3 inheritance (1
harness-parity, 2 driven fail-closed false-positives, 2 measured
efficiency, 7 cleanups, 2 ledger-covered re-flags dropped). Suite
1913 → 1916 tests, 48228 → 48238 assertions, 3 skipped unchanged.

- **The include owner's lazy body closed fail-safe and linear
  (t31-glm30-1, security:medium, driven fail-open + cost;
  bin/lib/plugin-tools.php, tests/SelfContainmentIncludeScanAbortTest.php)**
  — glm29-2's lazy `[^;]*?(?:;|\?>)` burned a per-byte step across
  terminator-free spans, and past pcre.backtrack_limit on a ~490KB
  span preg_match_all() returned FALSE, which the seat's truthiness
  consumed as "no includes": every include in the file silently
  invisible (driven end-to-end: the laundering payload — lint-clean
  alone, php -l verified — flags alone, 0 violations when preceded
  by one benign ~700KB `require $x . "AAA…";` statement, the
  composite itself NOT lint-clean (php -l refuses it at line 2 —
  the pad's `";` leaves the lexer in PHP mode at the laundering
  half's `<?php`; corrected round 31, the genuinely lint-clean
  composite pinned there) — the masker blanks the literal
  to same-length spaces; inspect-artifact rides this seat over
  hostile extracted trees with no size cap, and its php -l rejection
  runs after the scan). Both halves one commit: FALSE is the LOUD
  refusal naming the file (preg_last_error_msg()'s diagnostic, the
  glm36-8 abort-is-a-refusal doctrine at the one seat that round
  never swept), and the body is the possessive unrolled loop
  `[^;?]*+(?:\?(?!>)[^;?]*+)*+(?:;|\?>)` — `[^;?]*+` runs to the
  next `;` or `?`, each `\?(?!>)` iteration eats one `?` that is
  not a close tag (a ternary/null-coalescing `?` is statement
  body; a `?`-then-`>` pair is PHP's own close-tag lexing), so the
  match still ends at whichever terminator comes FIRST (the lazy
  and possessive match sets driven byte-identical over the round-29
  shapes, the ternary and `??` spellings included) while the engine
  never backtracks: linear, no limit left to exhaust on the
  490KB/700KB drives (measured at pattern level 18.5ms → 1.25ms at
  490KB over terminator-free bytes). The refusal pinned at the
  pinned-limit idiom (a live include answers exactly the one
  refusal at floor limit 1, candidate-free payloads keep their
  clean verdict there, the control flags at the restored limit);
  the 8MB pad scanned-never-aborted with the wall bound a generous
  class guard; all round-29 terminator pins green unchanged.

### Fixed (shared — M3 Task 3.1, claude-glm round 29)

Fifteenth claude-glm pass: the FIRST round under the scope rule
(shared/ production code, driven fail-opens, security high/medium)
— 37 raw findings → 3 in-scope, every one a driven security
fail-open, plus the reviewer's self-noted vacuous-pin leg. DRIVER
ADJUDICATION: R29-1/2/3 ACCEPTED — R29-2/R29-3 one class at two
seats, TWO commits (the seats' fixes differ: the terminator
alternation at the include owner, the collection change at the
write seats); the vacuous-pin touch-up the fourth commit. Four
commits t31-glm29-1..4, the full offline check green after EVERY
commit, no push. The round's shape: the CR-line laundering closed
at the scanner's line split; the close-tag terminator class swept
to the include owner and both collector seats; the vacuous pin
made failable; the out-of-scope residuals recorded for Task 3.3
inheritance (5 harness-parity latents, the test-hygiene set, 12
cleanups, 2 ledger-covered re-flags dropped). Suite 1905 → 1913
tests, 48201 → 48228 assertions, 3 skipped unchanged (every delta
measured from output).

- **The scanner's line split owns the tokenizer's exact three
  terminators (t31-glm29-1, security:high; bin/lib/secret-scanner.php,
  bin/lib/plugin-tools.php, tests/SecureFixturesTest.php)** —
  explode("\n") collapsed a CR-only payload to ONE line, so the
  line-local `secrets:allow` marker exempted a live secret on a
  DIFFERENT CR-line (the artifact ACCEPTED at exit 0 where the LF
  twin rejected). One hand-walk owner (wp_connectors_line_split(),
  \r\n|\r|\n — never PCRE's broader \R, never a preg_split abort
  surface) answers each line's true byte start; the marker's
  line-locality rides the same split at both arms, the masker's
  blank preserving \r beside \n so the masked view stays
  index-aligned (glm17-1's line-preservation completed at the CR
  boundary). Driven: the CR twin flags exactly like its LF twin,
  CRLF byte-identical, a marked CR-line still exempts.
- **The include owner's terminator is the ocr62-1 alternation ';|?>'
  (t31-glm29-2, security:medium; bin/lib/plugin-tools.php,
  tests/SelfContainmentCloseTagTerminatorsTest.php)** — a
  close-tag-terminated include (PHP implies the semicolon) was
  INVISIBLE to every gate riding the owner, and a later ';' glued
  the greedy match across the close tag into unrelated code. The
  match is lazy and ends at whichever terminator comes FIRST; the
  implied-semicolon tail joins the ';' in the two argument
  derivations the statement feeds, so benign close-tag spellings
  stay byte-identical with their ';' twins. Driven: the php -l
  clean `require dirname(__DIR__, 2) . "/outside.php" ?>` flags
  exactly like its ';' twin (red at HEAD: 0 violations).
- **The same ';|?>' class at both collector seats, the twins moving
  together (t31-glm29-3, security:medium; bin/lib/plugin-tools.php,
  tests/SelfContainmentCloseTagTerminatorsTest.php)** — a
  close-tag-terminated WRITE with no later ';' was never collected:
  the driven lint-clean loop shape answered 0 violations while its
  second iteration requires the outside path, and the map twin left
  the map-literal proof standing on the collected literal while the
  runtime value was the request parameter. Both seats' bodies end
  at whichever terminator comes FIRST (the glm18-7/8
  write-visibility contract restored at the close-tag boundary);
  the ';' spellings byte-identical.
- **The vacuous nonce-guard pin made failable (t31-glm29-4,
  test-hygiene; tests/Zai/ZaiSettingsTest.php)** —
  assertArrayNotHasKey over an array_column VALUES list probed
  integer keys, never the codes; the assertNotContains idiom asserts
  what it means (the nonce failure surface never reaches the
  response). Structural only; no behavior change.

### Fixed (shared — M3 Task 3.1, claude-glm round 28)

Fourteenth claude-glm pass: 15 findings, all verified. DRIVER
ADJUDICATION: ALL 15 ACCEPTED — #2 riding the glm15 refutation's own
re-open rule (premise falsified against the pinned pluggable.php, the
live Zai consumer the driven spelling), #7 closing the glm14 arity
deferral with its first driven evidence, #8 closing the ocr5-1
residual by its own re-open rule (the standalone build is the
producer). Fifteen numbered commits t31-glm28-1..15 — with #13's
route-through REFUTED IN VERIFICATION by the full-check drive (a
deterministic-seed OOM in the unused-import views battery over the
token memo's retention; green at HEAD, re-driven both ways — the
refutation landed as t31-glm28-13r beside the original commit, the
seat keeping its bare tokenize with the standalone justification
recorded) — the full offline check green after every commit (four
first-check runs carrying the recorded seed-dependent census-refusal
blip, every re-run green at the settled counts, round 27's own
protocol), no push. The round's shape: the scanner's PCRE-abort
straggler closed fail-safe; the check_admin_referer die-contract with
the glm15 refutation corrected; the glm14 arity deferral and the
ocr5-1 build residual closed by their own rules; the URL message
quartet completed (the non-digit-led port region and the parse-false
bracket tail); four core-parity stubs (wp_nonce_url, add_query_arg's
false-value idiom + remove_query_arg, esc_url's allowed protocols,
sanitize_email's gates); two pooled walks (~4.9 s of every check
retired at the engine's spawn floor); the clock guard; and the
quote-style unescape owner. Suite 1894 → 1905 tests, 48116 → 48192
assertions, 3 skipped unchanged (every delta measured from output).

- **The repo-scan test rides the production-shaped fresh child
  (glm28-16, post-round, test-hygiene:medium;
  tests/SecureFixturesTest.php, docs/review/REFUTATION_LEDGER.md)**
  — the post-round check reddened over the repo's own two biggest
  sources answering the secret-scan token-memory bound inside the
  memory-squeezed phpunit process; the diagnosis (measured): a
  LEGITIMATE trip of glm17-2's fail-safe census (estimates 72.1/70.0
  MB against in-suite headrooms below the 55.9/58.0 MB break-evens)
  and not a glm28-1 regression — the real costs are 13.4/11.6 MB and
  the composer @scan-secrets gate scans both green in its fresh
  process every check. No bound change can admit them in-suite
  without reopening the adversarial fatal window (any lower factor
  passes a hostile mid-size dense payload into a fatal); the test
  scans the repo through the spawned fresh child instead, a
  squeezed-child leg pinning the census's loud refusal unchanged.
  The order-dependent blip class is closed for the suite — four
  consecutive random-order runs green.

- **A PCRE abort over the scanner's pattern walk refuses loudly
  (t31-glm28-1, security:low; bin/lib/secret-scanner.php,
  tests/SecureFixturesTest.php)** — `preg_match_all() === 0` read a
  FALSE return (match limit exhausted) as the zero-findings arm, the
  abort falling into the match loop over an EMPTY `$matches`; the
  abort converts to the walk's own loud refusal, never a clean
  verdict over bytes the walk could not test. Derivation recorded:
  the ten flat patterns auto-possessify on this PCRE2 engine (the
  round's 200 KB 'A'-pad premise probed against every pattern — no
  craftable abort at any realistic limit), so the drive rides the
  pinned-limit idiom at the floor where any match attempt aborts.
- **check_admin_referer rides core's die-contract
  (t31-glm28-2, bug:medium; tests/harness/wp-stubs.php,
  tests/FoundationHarnessTest.php, tests/Zai/ZaiSettingsTest.php,
  docs/review/REFUTATION_LEDGER.md)** — the glm15 refutation's
  premise ('dies only when the nonce is absent') falsified against
  the pin: core dies on ANY failed verification through wp_nonce_ays
  (); the stub recorded doing_it_wrong and CONTINUED, and the live
  Zai leg asserted post-conditions that only ran because the die
  never fired. The seat spells core's shape now (a new wp_nonce_ays
  stub carrying core's generic arm, the one die vocabulary's
  emulation boundary, the referer escape dropped with the boundary
  recorded); the glm15 entry carries the in-place CORRECTED pointer.
- **wp_nonce_url delegates to the fragment-correct add_query_arg
  (t31-glm28-3, bug:low; tests/harness/wp-stubs.php,
  tests/FoundationHarnessTest.php)** — the hand-glued separator put
  the nonce INSIDE the fragment on a fragment-bearing URL; core's
  own shape rides the delegation plus the '&amp;' input un-escape
  and esc_html() wrap, byte-for-byte the pin.
- **add_query_arg unsets false-valued params; remove_query_arg ships
  (t31-glm28-4, bug:low; tests/harness/wp-stubs.php,
  tests/FoundationHarnessTest.php)** — a FALSE param answered
  'key=0' through http_build_query() where core unsets (the remove
  channel's own idiom), and remove_query_arg did not exist while the
  architecture gate's allowed vocabulary names it; the merge unsets
  false-strict and the sibling ships in core's shape (the array form
  and the REQUEST_URI default included).
- **esc_url preserves wp_allowed_protocols members
  (t31-glm28-5, bug:low; tests/harness/wp-stubs.php,
  tests/FoundationHarnessTest.php)** — the http(s)-only probe
  answered '' over mailto:/tel:/ftp: (a connector's support link
  green-testing an empty href); the scheme rides a documented
  allowed set (the minimal honest one for the stub's consumers,
  core's wider list the recorded divergence), a disallowed scheme
  still stripping.
- **sanitize_email rides core's gates (t31-glm28-6, bug:low;
  tests/harness/wp-stubs.php, tests/FoundationHarnessTest.php)** —
  the bare FILTER_SANITIZE_EMAIL passthrough let
  'bogus@@example..com' through where core answers ''; the six gates
  spell core verbatim (the doubled-period run REMOVED whole, never
  collapsed — driven), each riding core's own 'sanitize_email'
  filter arm with its context string.
- **apply_filters passes the arity verbatim
  (t31-glm28-7, bug:low; tests/harness/wp-stubs.php,
  tests/FoundationHarnessTest.php, docs/review/REFUTATION_LEDGER.md)**
  — the max(1, ...) clamp passed one arg to a 0-arity callback (an
  ArgumentCountError in production for a registration core serves
  with zero); the glm14 deferral closed with its first driven
  evidence, the slice riding WP_Hook::apply_filters's own call
  shape.
- **The builder refuses statement-before-namespace
  (t31-glm28-8, bug:medium; bin/build.php,
  tests/BuildArtifactsTest.php, docs/review/REFUTATION_LEDGER.md)**
  — the ocr5-1 residual's own re-open rule fired: the standalone
  build IS the producer, the driven shape shipping fatal bytes at
  exit 0 while the built zip failed php -l. Statement-seen tracking
  rides the rewriter's own token walk (the engine's rule derived
  against php -l over every shape: a statement or inline HTML
  before the first declaration fatals — the BOM included; both
  declare forms legal; a second declaration after code legal), the
  refusal naming the file and line, the @lint roots the second net.
- **A non-digit-led port region on a failed parse wears the port
  sentence (t31-glm28-9, bug:low; shared/src/Http/Url.php,
  tests/SharedOAuthContractsHttpTest.php)** — ':-80/' wore the
  generic scheme/host sentence while its ':+80/' and ': 80/' twins
  answered the digits sentence through the raw screen, one malformed
  class two verdicts; the digit-led requirement drops, every
  digit-led arm keeping its precedence verbatim.
- **The parse-false glued bracket tail wears the bracket sentence
  (t31-glm28-10, bug:low; shared/src/Http/Url.php,
  tests/SharedOAuthContractsHttpTest.php)** — '[::1]8080/' wore the
  generic sentence while '[::1]x/' answered the glued-bracket
  sentence, inverting the success path's bracket-first precedence;
  the raw screen's own glued-tail check rides the entry screen over
  the split owner's strrpos anchor ('a]:b]:70000' keeps its range
  verdict), the sentence hoisted to the one const both screens
  share.
- **The lint walk rides the pooled fleet (t31-glm28-11,
  efficiency:medium; bin/lint-php.php)** — the serial one-engine-
  per-file loop spent 6.60 s of every check over the 177 sources;
  one xargs -0 -n2 -P8 fleet lints them with per-index verdict
  files (the trailing echo absorbing php -l's exit-255 refusals,
  glm21-15's idiom), a missing verdict the gate's own loud failure.
  Measured 6.60 s → 3.72 s — the honest 1.8x on this 4-core host,
  the ceiling the engine's own spawn cost (P8/P16 identical, the
  glm21-15 finding reproduced).
- **The inspector's syntax walk rides the pooled fleet
  (t31-glm28-12, efficiency:medium; bin/inspect-artifact.php)** —
  2.35 s of a 2.50 s inspection was spawn cost over the extracted
  tree; the walk collects and one fleet lints (the verdict sentence
  byte-identical, the fence's partial-results behavior preserved).
  Measured 2.41 s → 1.41 s over the real zai zip; the
  third-consumer hoist threshold named at both pooled seats.
- **The family detector's token route REFUTED IN VERIFICATION
  (t31-glm28-13 + t31-glm28-13r, efficiency; bin/lib/plugin-tools.php,
  docs/review/REFUTATION_LEDGER.md)** — the routing onto the ONE
  token provider was driven green at the seam and REFUTED by the
  full check: the detector's postcondition consumers run it over
  every build's REWRITTEN bytes (unique content per scratch tree),
  filling the provider's 4 MB bounded-FIFO memo with single-use
  streams until the suite — knife-edge at the 128M CLI limit beside
  the views memo's 24 MB — OOMed inside the unused-import retention
  battery at a deterministic seed (red with the routing, green at
  HEAD, both re-driven). The seat keeps its bare tokenize with the
  standalone justification documented in place; re-open only with a
  memory-budget change.
- **advanceTime rejects negative advances (t31-glm28-14, bug:low;
  tests/harness/WpHarness.php, tests/FoundationHarnessTest.php)** —
  the seat silently rewound the frozen clock where the sibling
  DeterministicClock::advanceBy() refuses rewinds by doctrine; the
  refusal is unconditional (frozen or live — the sign error is the
  defect wherever the clock stands).
- **quoted_literals computes runtime values through the
  quote-style-aware owner (t31-glm28-15, bug:low;
  bin/lib/plugin-tools.php, tests/SelfContainmentEscapedQuoteTest.php)**
  — the blind callback decoded `\"`/`\'` alike, returning values PHP
  never computes (a single-quoted `\"` decoded where the backslash
  IS the value) while the correct owner sat unused; the pair rides
  the owner (the quote byte keeping the interpolation predicate's
  contract), the double-quoted arm computing the full escape table —
  the 57,649-spelling sweep finding zero fail-open flips, and the
  hex-spelled dot pair now computing the traversal it spells.

### Fixed (shared — M3 Task 3.1, claude-glm round 27)

Thirteenth claude-glm pass: 15 findings at cap; 12 stand, #9
records beside the standing deferral #12 (the remedy is the post-M3
one-row redesign, not per-shape patches), #13/#15 stay deferred.
DRIVER ADJUDICATION: #1-#8, #10, #11, #12, #14 ACCEPTED. Twelve
numbered commits t31-glm27-1..12, the full offline check green
after every commit (two first-check runs carrying the recorded
seed-dependent census-refusal blip — re-runs green at the settled
counts, and the round's own 16 MB token-memo first cut having made
the class easier to hit, the bound corrected to 4 MB within the
round), no push. The round's shape: round 26's own timeout_*
exclusion over-refused — the parse learns the two namespaces with
the premise correction recorded; the harness core-parity six
(iteration resync, delete gating, map_deep, parse_str, ajax die,
plugins_url); the encode-guard; the double-tokenization; two
hoists; and the derive-first twin. Suite 1886 → 1894 tests,
48070 → 48116 assertions, 3 skipped unchanged (every delta measured
from output).

- **Transients named `timeout_*` delete again — the two namespaces
  of the one row (t31-glm27-1, bug:low; tests/harness/wp-stubs.php,
  tests/FoundationHarnessTest.php)** — glm26-3's
  '_transient_timeout_' exclusion routed the whole family to the
  missing-row false, so a transient literally named 'timeout_x' was
  PERMANENTLY UNDELETABLE (core's namespace-blind delete answers
  true over its live value row); the parse widens to the whole
  '_transient_' family with a timeout twin beside it, the row
  existing when any reading names a live half, the delete killing
  every half it names (the timeout reading disarming the window
  ALONE). The round-26 premise ('core answers false') held only for
  ABSENT rows — the correction recorded in the ledger.
- **The hook iteration re-syncs with the live registration array
  (t31-glm27-2, bug:low; tests/harness/wp-stubs.php,
  tests/FoundationHarnessTest.php)** — the COW-snapshot foreach
  inverted core's resort semantics BOTH ways: an
  unhook-before-it-runs still delivered, a mid-run add at a pending
  priority was invisible. One iteration owner re-derives the
  priority list at every bucket boundary, the bucket snapshotted at
  its own start (core's own foreach shape), do_action() and
  apply_filters() both riding it.
- **delete_option's success pair rides core's affected-rows gate
  (t31-glm27-3, bug:low; tests/harness/wp-stubs.php,
  tests/FoundationHarnessTest.php)** — the pair fired
  unconditionally after the pre-hook, so a mid-action deleting
  observer got true plus the DOUBLE family where core's delete
  affects nothing; the store re-consult (the missing-row consult
  and the gate hoisted to one row-exists owner) answers false with
  the pair suppressed.
- **wp_unslash/wp_slash ride core's map_deep leaf semantics
  (t31-glm27-4, bug:low; tests/harness/wp-stubs.php,
  tests/FoundationHarnessTest.php)** — the (string) coercion turned
  int members into strings and OBJECT members into strict_types
  fatals; one map_deep owner walks arrays and objects with the
  strings-only callback (wp_unslash), wp_slash keeping core's own
  three-arm shape (objects verbatim).
- **wp_parse_args parses the string form
  (t31-glm27-5, bug:low; tests/harness/wp-stubs.php,
  tests/FoundationHarnessTest.php)** — a string $args was discarded
  into array(), mis-parsing get_sites('fields=ids&number=2') against
  its own advertised array|string surface; the seat spells core's
  three-branch head (wp_parse_str's query-string shape, the filter,
  the merge guard).
- **check_ajax_referer rides core's $die contract
  (t31-glm27-6, bug:low; tests/harness/wp-stubs.php,
  tests/FoundationHarnessTest.php)** — the delegation to
  check_admin_referer dropped $die, continuing execution on a nonce
  failure where core's ajax twin DIES; the un-delegated seat spells
  core's own shape (the three-way nonce lookup, the verdict action,
  the $die gate) with the harness's wp_die RuntimeException as the
  recorded stop-execution emulation.
- **plugins_url derives the plugin's own folder
  (t31-glm27-7, bug:low; tests/harness/wp-stubs.php,
  tests/FoundationHarnessTest.php)** — the $plugin argument was
  ignored, addressing the plugins ROOT whatever file named the
  plugin; the seat prefixes dirname(plugin_basename($plugin)) with
  core's '.' skip, non-empty-string path guard, and 'plugins_url'
  filter.
- **The plaintext-scan assertion guards its encode
  (t31-glm27-8, bug:low; tests/harness/WpConnectorsTestCase.php,
  tests/FoundationHarnessTest.php)** — wp_json_encode()'s false fed
  a natively string-typed assert under strict_types (a TypeError
  instead of a verdict), and the HTTP-leaks warning's unguarded
  concat silently dropped the leak detail; both seats assert the
  encode not-false with a failure NAMING it.
- **The embed leg reads and tokenizes each shared source once per
  build (t31-glm27-9, efficiency:low; bin/build.php,
  bin/lib/plugin-tools.php)** — the collector's PSR-4 fence and the
  embed loop paid the read and the tokenize twice; the fence's bytes
  flow out to readSharedSource() and one token-stream provider
  (content-keyed, bounded 4 MB FIFO) serves the fence walk and the
  rewrite's own walk. Measured 3273 → 2994 ms over 89 embed passes,
  build verdicts byte-identical.
- **The copyTree ancestor-walk stop set rides one owner
  (t31-glm27-10, cleanup; tests/harness/WpHarness.php)** — the stop
  condition was spelled twice with twin sentinels (the set already
  evolved twice at these seats); walkToExistingComponent() serves
  both walks, each keeping its own sentinel vocabulary.
- **The containment fold's standalone table justified and pinned
  (t31-glm27-11, cleanup; tests/harness/WpHarness.php,
  tests/HarnessCopyTreeTest.php)** — derive-first REFUTES the
  route-through: WpHarness.php loads bare in the php -r children
  that drive copyTree(), where the tooling owner is undefined; the
  standalone justification is documented in place and the table's
  agreement with wp_connectors_ascii_lower() asserted over the full
  byte range (a single-site mutation flips the pin).
- **The Url authority split rides one owner
  (t31-glm27-12, cleanup; shared/src/Http/Url.php)** — the
  failed-parse screen and the success path had regrown the probe
  arithmetic twin glm15-7 recorded deleted (the re-open rule
  fired); split_authority_host_port() derives the first authority's
  host[:port] region once (last-'@' userinfo strip, first ':' after
  any ']'), both screens riding it, verdicts byte-identical.

### Fixed (shared — M3 Task 3.1, claude-glm round 26)

Twelfth claude-glm pass: 4 correctness + 3 test-hygiene + 1
documentation + 2 cleanup; 1 ledger-covered standing (#8 fixed in
code now, the ledger granting it). DRIVER ADJUDICATION: ALL
ACCEPTED, both cleanup hoists included (the threshold crossed, the
off-by-one already bit). Twelve numbered commits t31-glm26-1..12,
the full offline check green after every commit (one run carrying
the recorded seed-dependent census-refusal blip, re-runs green at
the settled counts), no push. The round's shape: the delete seat's
own re-audit (truthiness, mid-save capture, the timeout alias, the
hook family); the glm25 legs' own hygiene; the citation correction
at every site with the driver-owned post-mortem; and the two
hoists. Suite 1882 → 1886 tests, 48049 → 48070 assertions, 3
skipped unchanged (every delta measured from output).

- **Falsy transient names delete cleanly
  (t31-glm26-1, bug:low; tests/harness/wp-stubs.php,
  tests/FoundationHarnessTest.php)** — delete_option()'s transient
  half gated on truthiness at both seats, so '0'- and ''-named
  transients answered the missing-row false over a live row and
  kept serving post-delete; both seats ride identity
  (`false !==`), the parse's own sentinel.
- **A mid-save deleting observer cannot kill the save
  (t31-glm26-2, bug:low; tests/harness/wp-stubs.php,
  tests/FoundationHarnessTest.php)** — the expires_at keep-guard
  re-read the row after the update/add_option hook family fired,
  and a deleting observer left it absent: the undefined-key warning
  killed the save under the warning-to-exception regime. The
  standing expiry is captured before the family (core's own arming
  order) and the guard reads the capture.
- **The timeout family's spelling is no transient value row
  (t31-glm26-3, bug:low; tests/harness/wp-stubs.php,
  tests/FoundationHarnessTest.php)** — the prefix parse resolved
  '_transient_timeout_<name>' onto the transient named
  'timeout_<name>', so a delete over the timeout spelling answered
  true and killed the aliased transient where core answers false
  over the absent timeout row; the parse excludes the family, the
  no-such-row claim honest now.
- **delete_option() rides core's own hook family
  (t31-glm26-4, bug:low; tests/harness/wp-stubs.php,
  tests/FoundationHarnessTest.php)** — core fires the generic
  'delete_option' action before the delete (the row still present),
  the keyed and closing actions after a successful delete alone,
  and a missing row fires none; the seat modeled zero hook seats,
  newly load-bearing since glm25-6 routes every transient deletion
  through it.
- **The swallowed fail-inside-try surfaces the right message
  (t31-glm26-5, test-hygiene; tests/ToolchainSmokeTest.php)** —
  glm25-4's fail() inside the try was swallowed by its own catch
  (AssertionFailedError IS a RuntimeException subclass); collect
  inside, assert outside, the never-returned verdict its own
  assertion now.
- **The '/' denial leg gains its opendir pre-flight
  (t31-glm26-6, test-hygiene; tests/HarnessDenialProbeTest.php)**
  — a policy-confined host denies opendir('/') by non-mode means
  and the leg would red over the host's own policy; the capability
  is probed, the skip named, the mode-denial verdicts unchanged.
- **The vacuous closing fileperms pin dropped
  (t31-glm26-7, test-hygiene; tests/HarnessDenialProbeTest.php)**
  — the chmod pre-flight had proved chmod refused, so the closing
  perms assertion could never fail; a pin that cannot redden pins
  nothing.
- **The delete_transient core citation corrected everywhere
  (t31-glm26-8, documentation)** — the function spans
  option.php:1380-1418 in the pinned 7.1.1 (pre-hook :1391,
  deleted_transient :1414), never 1408-1438; the driver-owned range
  corrected at every echoing site (wp-stubs, FoundationHarnessTest,
  CHANGELOG ×2, ledger ×3), the post-mortem recorded in the
  round-26 ledger section.
- **The '_transient_' convention rides one owner pair
  (t31-glm26-9, cleanup; tests/harness/wp-stubs.php)** — the
  forward spelling (three concats) and the reverse parse each
  hand-copied, this round's substr off-by-one the demonstrated
  hazard; wp_connectors_transient_option_name() and its parse twin
  serve all seats, the timeout exclusion riding the owner.
- **The wpdb enumeration census rides one query owner
  (t31-glm26-10, cleanup; tests/FoundationHarnessTest.php)** — the
  LIKE query hand-copied at five sites (the round-25 diff adding a
  closure and an inline twin in one file); censusTransientRows()
  spells it once, deliberately the literal spelling so the oracle
  never inherits the stub's own helper.
- **The copy-on-write comment states the one-row doctrine
  (t31-glm26-11, ledger-granted; tests/harness/wp-stubs.php)** —
  the sharing's basis is the one-row doctrine itself (a nested
  mutation landing in both stores is one row's meaning), never
  copy-on-write; the round-25 narration correction landed in code.

### Fixed (shared — M3 Task 3.1, claude-glm round 25)

Eleventh claude-glm pass: 1 confirmed regression + 4 small accepts +
2 candidates (driver pre-verified BOTH against the local pinned
7.1.1, option.php:1380-1418) + 5 ledger refutations standing + 1
deferral (#12 ledgered as a post-M3 design option). DRIVER
ADJUDICATION: #1 accepted (the reviewer's fix shape approved); #9,
#6-partial, #11-partial, #10/#14 accepted; candidates #4/#5
accepted — delete_transient fires delete_transient_<name> BEFORE,
deleted_transient AFTER success only, returns false over a missing
row; the mirror closes both directions. Eight numbered commits
t31-glm25-1..8, the full offline check green after every commit
(two runs carrying the recorded seed-dependent census-refusal
blip, every re-run green at the settled counts), no push. The
round's shape: glm24-4's own keep-guard regression closed at the
stored-false arming seat; the mid-method skip's folded verdicts
restored; two documented contracts made live under the
warning-to-exception regime; and the delete pair riding core's own
shape with the mirror's ownership symmetry completed. Suite
1875 → 1882 tests, 47998 → 48049 assertions, 3 skipped unchanged
(every delta measured from output).

- **TTL (re)arming survives the stored-false row's missing read
  (t31-glm25-1, bug:low; tests/harness/wp-stubs.php,
  tests/FoundationHarnessTest.php)** — glm24-4's keep-guard keyed on
  the transient store's own row alone, but a stored-false row reads
  missing to the add-vs-update predicate, so the arming block never
  ran over it and the write was its ONLY expiry seat: keeping the
  standing expires_at disarmed every TTL-bearing re-save
  (set('k', false) then set('k', false, 100) never expired;
  set('f', false, 100) then set('f', false, 300) died at the stale
  first window). The standing timeout is kept ONLY over a
  zero-expiration save; an expiration-bearing save takes the head's
  own derivation whichever row shape carries it.
- **The raw-splice driver sits in its own top-gated method
  (t31-glm25-2, test-hygiene:medium; tests/SecureFixturesTest.php)**
  — the isPosixHost() skip sat one leg deep, so on a non-POSIX host
  it folded the escaped-flag leg's already-run verdicts (its own
  gate is the spawn capability alone) and the unquoted-cut twin
  never ran; the driver carries both gates at the top now, each
  method's verdicts independent, POSIX behavior unchanged.
- **A failed lock chmod answers the denial probe honestly
  (t31-glm25-3, bug:low; tests/harness/WpHarness.php,
  tests/HarnessDenialProbeTest.php)** — under the suite's
  warning-to-exception regime an unsuppressed chmod() that fails
  threw before the probe logic ran, so the documented contract (the
  skip fires) never lived; both the lock and the paired restore
  ride the @-suppressed spelling, the refusal driven over a
  chmod-refused stat-able target.
- **A refused declared-root mkdir answers the named staging
  exception (t31-glm25-4, bug:low; tests/harness/WpHarness.php,
  tests/ToolchainSmokeTest.php)** — mkdir's E_WARNING escaped first
  as a converted exception (itself a RuntimeException subclass,
  never the harness's own named class), leaving the documented
  staging RuntimeException unreachable; the mkdir rides the
  @-suppressed spelling and the refusal is driven over a read-only
  base.
- **The probe's pre-state mask widens to 07777, the dead 0755
  fallback dropped (t31-glm25-5, tests/harness/WpHarness.php)** —
  the docblock promises the probed shape's OWN pre-state, and the
  setgid/setsticky family above 0777 is part of it on the hosts
  that carry them; the fallback's false arm was dead besides
  (fileperms' own warning fires first under the regime). Verdicts
  unchanged on this host; a structural pin holds the spelling.
- **delete_transient() rides core's own hook shape
  (t31-glm25-6, bug:low; tests/harness/wp-stubs.php,
  tests/FoundationHarnessTest.php)** — core (option.php:1380-1418,
  pinned 7.1.1) fires delete_transient_<name> BEFORE the delete
  (unconditionally), deleted_transient AFTER a SUCCESSFUL delete
  alone (`if ($result)`), and returns the delete's own false over a
  missing row; the seat had modeled zero hook seats and answered
  true unconditionally.
- **delete_option() owns the transient-store row too
  (t31-glm25-7, bug:low; tests/harness/wp-stubs.php,
  tests/FoundationHarnessTest.php)** — core is one row, and the
  uninstall path's LIKE-enumeration deletes ride the shape: an
  option-keyed delete left get_transient() still serving post-delete
  (glm24-3 had closed only the other direction). The missing-row
  predicate consults both stores, and delete_transient() folds onto
  core's own delegation in the same stroke — one deletion spelling,
  the hook choreography above it exactly as option.php spells it.

### Fixed (shared — M3 Task 3.1, claude-glm round 24)

Tenth claude-glm pass: 6 correctness findings (5 driven CONFIRMED,
1 portability PLAUSIBLE) + 9 cleanup, the cleanup folded to six
commits (two pairs combined). DRIVER ADJUDICATION: correctness
#1-#6 all accepted; cleanup accepted except the isset/!empty pair
(refuted on ledger grounds — pin-derived shapes govern, untouched
and recorded). Twelve numbered commits t31-glm24-1..12, the full
offline check green after every commit (one run carrying the
recorded seed-dependent census-refusal blip, every re-run green
at the settled counts), no push. The round's shape: the transient
seat's own re-audit (seed-row TTL arming, the mirror's delete
ownership, the stored-false disarm, the completion actions'
raw-value contract); the marker grammar's markup-family
enumeration completed (php/phtml/htm/xhtml); the raw-splice
driver's portability gate; and the choreography folds — the
mutation driver onto its spawn owner, the staged lint-roots loop,
the lock/probe/restore probe choreography, the timeout arming's
single derivation, the seeded-row save's doubled spellings, and
the trailroot patch's structural anchor. Suite 1871 → 1875
tests, 47978 → 47998 assertions, 3 skipped unchanged (every delta
measured from output).

- **The marker grammar's markup family carries php/phtml/htm/xhtml
  (t31-glm24-1, bug:low; bin/lib/secret-scanner.php)** — the family
  array named html/svg/xml/md alone, so a .php template's glued
  `<!-- secrets:allow -->` flagged while the identical .html bytes
  exempted, and .htm flagged while .html exempted; a .php template
  IS a payload whose comment grammar includes HTML comments, the
  family the payload's grammar — never a hand-list one spelling
  short. Non-markup extensions keep refusing the glued form.
- **set_transient() arms the timeout row over a seeded-only row
  (t31-glm24-2, bug:low; tests/harness/wp-stubs.php)** — core
  writes the timeout row BEFORE the value row's unchanged refusal
  whichever row carried the old value; the refresh once guarded
  on the transient store's own entry, so a seed re-saved with its
  own value and a TTL answered false but armed nothing, the read
  falling to false immediately over the standing seed.
- **delete_transient() owns both stores (t31-glm24-3, bug:low;
  tests/harness/wp-stubs.php)** — glm23-3's option-row mirror was
  a second copy the seat's own delete never owned: post-delete
  get_option() answered the value, the wpdb uninstall enumeration
  still presented the row, and a re-save fired the UPDATE family
  where core fires the ADD. The mirror (and its autoload half)
  dies with the transient.
- **A zero-expiration re-save never disarms a TTL-armed
  stored-false row (t31-glm24-4, bug:low; tests/harness/
  wp-stubs.php)** — the write's expires_at keep-guard keyed on
  $existing (false for a stored-false value), so the re-save reset
  the window to false and the row never died, violating glm23-1's
  own invariant; the guard keys on the transient store's own row.
- **The completion actions observe the pre-head value
  (t31-glm24-5, bug:low; tests/harness/wp-stubs.php)** — core's
  clone and sanitize live inside the delegated by-value twins and
  never propagate back, so set_transient_<name>/set_transient hand
  the observer the pre_set-filtered RAW value (the caller's own
  object instance included); the seat's frame once reassigned
  $value at both heads, the actions observing the sanitized clone.
- **The raw-splice mutation driver gates on isPosixHost()
  (t31-glm24-6, bug:low portability, PLAUSIBLE;
  tests/SecureFixturesTest.php)** — the driver's single-quoted
  splice is a POSIX-shell premise and its anchored needle pins
  this build's INI mangle (the t31-ocr29-7 class glm23-12 closed
  in the same file); the leg gates with a loud named skip and the
  docblock names both premises. POSIX behavior unchanged.
- **The raw-splice driver rides the spawn owner
  (t31-glm24-7, test-hygiene:medium; tests/SecureFixturesTest.php)**
  — the driver hand-copied the owner's whole plumbing (the
  glm23-15 class one round later); the mutation is ONE raw-flags
  parameter on the owner now, the command byte-identical by
  construction, a later owner edit unable to strand a stale child.
- **The staged declared-roots choreography rides ONE owner
  (t31-glm24-8, test-hygiene:medium; tests/harness/WpHarness.php)**
  — glm23-13 hoisted the root data but not the loop; the five
  staged legs' hand-copied loops (is_dir skip, 0755 mkdir,
  already-diverging messages) fold into
  WpHarness::stageLintRoots(), a failed mkdir throwing the
  staging message itself.
- **The lock/probe/restore choreography rides the probe owner's
  sibling (t31-glm24-9, test-hygiene:medium; tests/harness/
  WpHarness.php)** — glm23-14 stopped one layer short: nine sites
  hand-copied the chmod-0000 lock, the verdict, and the chmod-back
  (the restore mode already diverging, 0777 vs 0755 — drift, never
  deliberate). WpHarness::lockForDenialProbe() captures the
  directory's own pre-state and restores it on the non-denied path
  (the divergence closed by construction); a denied lock stays
  locked (the leg's subject), the skip message staying at the site.
- **The timeout arming reuses the head's own $expires_at
  (t31-glm24-10, test-hygiene:low; tests/harness/wp-stubs.php)**
  — the >0/<0 arms were spelled twice (two now() reads that could
  straddle a tick on an unfrozen clock); one arm, the zero save
  the derivation's own false.
- **The seeded-row save's doubled spellings folded
  (t31-glm24-11, test-hygiene:low; tests/harness/wp-stubs.php,
  bin/lib/secret-scanner.php)** — one stored copy serves both
  stores (the agreeing-stores doctrine's own shape, copy-on-write
  safe), one array_key_exists where two spelled the same key, and
  the marker pattern's two arms compose from one shared
  line-comment opener fragment (byte-identical patterns, verified).
- **The trailroot patch anchored structurally, the pin reusing the
  loop's read (t31-glm24-12, test-hygiene:low;
  tests/ToolchainSmokeTest.php, tests/BuildSeamPropertyTest.php)**
  — the mutation target is the owner's own derivation
  (`$base . '/connectors'`), never the 12-char substring any
  future literal could collide with; the structural pin takes the
  entry-loop's lint-php.php member instead of re-reading ~13 KB
  fifteen lines later.

### Fixed (shared — M3 Task 3.1, claude-glm round 23)

Ninth claude-glm pass: 15 findings (13 CONFIRMED, 2 PLAUSIBLE
test-hygiene), all fifteen accepted by driver adjudication. Fifteen
numbered commits t31-glm23-1..15, the full offline check green after
every commit, no push. The round's shape: the transient seat's
core-parity surface completed (the two-row TTL mechanics with the live
ZaiDiscoveryCache caller, the pre_set/set_transient filter family, the
seeded-row visibility, the settings-updated pass-back merge, and the
head-trio owner beside the option twins); the scanner marker
boundary's extension split (the markup glue kept, the non-markup
laundering refused, the doctrine recorded) with the markup class
unified one member further; the zero-port class's last split closed at
its own loose pin; and the test-hygiene cluster — the splice-pin's
real red with round 22's exit-2 claim falsified, the probe's
try/finally, two portable-spelling legs, and three hoists over the
recorded threshold. Suite 1865 → 1871 tests, 47917 → 47978
assertions, 3 skipped unchanged (every delta measured from output).

- **set_transient() refreshes the timeout row over an unchanged
  re-save — core's two-row TTL mechanics (t31-glm23-1, bug:low;
  tests/harness/wp-stubs.php)** — core's update branch refreshes the
  '_transient_timeout_<name>' row ahead of the value row's own
  update_option() (option.php:1562-1571), so an unchanged re-save
  never refreshing expires_at left an expired-but-unread row
  permanently dead and degraded the live ZaiDiscoveryCache re-seed to
  cache misses after the first 12h window; the refresh rides ahead of
  the compare (the unchanged false and zero hooks untouched), a
  zero-expiration re-save leaving the standing timeout alone.
- **set_transient() answers core's pre_set/set_transient filter family
  (t31-glm23-2, bug:low; tests/harness/wp-stubs.php)** — the seat
  dropped the whole family: pre_set_transient_<name> at the head (the
  rewritten value flowing to storage and compare) with
  expiration_of_transient_<name> beside it, and the completion
  actions set_transient_<name>/set_transient firing over a completed
  save alone (core's `if ( $result )` — the unchanged re-save fires
  neither), all at the pin's own arities.
- **A seeded _transient_ option row is visible to set_transient()
  (t31-glm23-3, bug:low; tests/harness/wp-stubs.php)** — core's
  add-vs-update predicate is get_option-shaped over the option row, so
  a seed answers the UPDATE family with its own value as the old and
  keeps its home current through the save, where the harness fired the
  ADD family over the standing seed and left the stores divergent.
- **get_settings_errors() merges the settings-updated pass-back
  transient (t31-glm23-4, bug:low; tests/harness/wp-stubs.php)** —
  core merges the rows options.php parked in the 'settings_errors'
  transient over a settings-updated request (template.php:1928-1931)
  and consumes it exactly once; the harness answered in-process rows
  alone over the very request shape the pass-back exists for, the
  $hide_on_update head still answering first.
- **The transient head trio rides ONE owner with the option twins
  (t31-glm23-5, test-hygiene:medium; tests/harness/wp-stubs.php)** —
  the hand-copied clone-at-head (three seats) and glm17-8 two-arm
  compare (two seats) close into wp_connectors_option_head_clone() and
  wp_connectors_value_unchanged(); verdict-neutral, one mutation
  flipping every seat's pins at once.
- **The glue-broadened <!-- marker boundary is the markup family's
  alone — the non-markup laundering closed (t31-glm23-6, bug:high,
  security; bin/lib/secret-scanner.php)** —
  `api_key="<live-key>"<!-- secrets:allow -->` answered ZERO findings
  in a .env (and the quote-glued shape in a .json) while the unmarked
  control flagged; the grammar is extension-aware — the markup family
  (.html/.svg/.xml/.md) keeps the glued class, every non-markup
  extension the line-comment boundary for the '<!--' form, the spaced
  spelling exempting everywhere it did.
- **The markup marker arm's left boundary admits direct text adjacency
  (t31-glm23-7, bug:low; bin/lib/secret-scanner.php)** — the premise a
  comment follows its element with no separator holds for text content
  too; the class is unified for the family (text-glued markers
  exempt), the line-comment spellings keeping the whitespace guard in
  both families.
- **The zero-valued port class answers ONE range sentence
  (t31-glm23-8, bug:low; shared/src/Http/Url.php)** — ':0/' through
  ':00000/' answered the raw screen's short sentence while the
  parse-false spellings (':000000/' and up) answered the entry
  screen's long one, masked by glm22-2's substring pin; the < 1 half
  answers the ONE class sentence (a shared const), the over-range
  class keeping the long sentence and the in-range zeros their live
  remediation, the test pinning the full sentence.
- **The raw-splice mutation driver pins the child-side evidence —
  round 22's exit-2 claim corrected (t31-glm23-9, test-hygiene:medium;
  tests/SecureFixturesTest.php)** — at the raw splice of the
  INI-quoted flag the shell passes the token whole, php RUNS (exit 0)
  with its own INI diagnostic printed and the value arriving mangled,
  so only the anchored needle went red; the mutation driver rides the
  test, pinning the falsified premise and the flag-token line the
  needle actually distinguishes.
- **The scan-perm capability probe stages inside its own try/finally
  (t31-glm23-10, test-hygiene:medium; tests/SecureFixturesTest.php)**
  — a false staging answer once leaked the /wpct-scan-perm-<uniqid>
  tree per failed run, possibly mode 0000; releaseScratch rides the
  finally with the restore kept ahead of it.
- **The GPC lint leg binds the resolved temp prefix and asserts the
  relative root fragment (t31-glm23-11, test-hygiene:medium;
  tests/BuildSeamPropertyTest.php)** — a symlinked temp host's child
  FAIL line names the resolved prefix, so the unresolved bind red over
  a path difference that is not the subject.
- **The scan-fence finding needle composes the separator
  (t31-glm23-12, test-hygiene:medium; tests/SecureFixturesTest.php)**
  — the label is the iterator's host-joined pathname; the composed
  spelling matches on both separator vocabularies.
- **The lint root set rides ONE owner (t31-glm23-13,
  test-hygiene:medium; bin/lint-php.php, bin/lib/plugin-tools.php,
  tests)** — wp_connectors_lint_roots() serves the walk and all five
  staged legs (the trailing-separator patch retargeting the owner's
  line with its exactly-once pin), a structural pin owning the
  consult; a fifth root or a rename changes one site.
- **WpHarness::canDenyDirectoryOpen() owns the opendir capability
  probe (t31-glm23-14, test-hygiene:medium; tests/harness and seven
  test files)** — the finding's five inline spellings swept with every
  same-shape sibling (twelve sites total), each keeping its own
  staging, restore, and skip message; runningAsRootRunner()'s
  comment-maintained cross-reference is structural now.
- **The GPC lint leg's green run and driven re-run share ONE exec
  command (t31-glm23-15, test-hygiene:medium;
  tests/BuildSeamPropertyTest.php)** — the verbatim re-spelling once
  let a later edit leave the re-run spawning a stale child, a false
  verdict on the leg whose purpose is proving the refusal.

### Fixed (shared — M3 Task 3.1, claude-glm round 22)

Eighth claude-glm pass: 15 findings, #1-#9 accepted by driver
adjudication — #1 closing the standing scan_paths residual as its
own round commit — plus the GPC-leg roots-validity piece from #13
as its own commit (a staged leg blessing a walk that silently skips
3 of its 4 declared roots is a false-green, not cleanup); the rest
of #10-#15 deferred to the cleanup sweep. Ten numbered commits
t31-glm22-1..10, the full offline check green after every commit,
no push. The round's shape: the scan_paths walk residual closed at
last by its own convention; the next round's re-audit of the
round-21 entries (the zero-port one-arm miss, the glued-markup
boundary, the transient delegation's three missing twins, the
INI-semicolon doc-truth, the GPC leg's false-green roots — five
round-21 ledger lines corrected in place); and the settings seat's
two remaining members. Suite 1860 → 1865 tests, 47838 → 47917
assertions, 3 skipped unchanged (every delta measured from output).

- **The scan_paths walk fences its boundary aborts — the standing
  residual closed (t31-glm22-1, bug:medium; bin/lib/secret-scanner.php,
  tests/SecureFixturesTest.php)** — a chmod-000 root or entry aborted
  the bare iterator walk with an uncaught UnexpectedValueException and
  the whole scan died at exit 255 with no verdict; the walk rides the
  sibling fence idiom (construction inside the try, the boundary abort
  a named refusal in the glm14-2 vocabulary with the SPL message
  parenthetically, the readable trees' findings kept, per-entry
  RuntimeExceptions untouched), driven both boundary shapes.
- **The entry screen's port range check is two-sided — the zero-valued
  class unified under the range verdict (t31-glm22-2, bug:low;
  shared/src/Http/Url.php)** — ':00000/' answered range through the
  raw screen while ':000000/' (parse-false) answered the leading-zeros
  sentence whose remediation is dead (stripping zeros lands on ':0',
  itself refused); the < 1 side joins the > 65535, glm21-5's one-arm
  miss corrected.
- **The allow-marker's comment-form arm carries its own markup boundary
  class (t31-glm22-3, bug:low; bin/lib/secret-scanner.php)** — compact
  markup ('</text><!-- secrets:allow -->') was not a marker while the
  spaced spelling was, a legitimately marked .svg false-finding; the
  markup edge (tag- and quote-closers beside start/whitespace) joins
  the comment-form arm, the ///# line-comment spellings keeping the
  whitespace guard.
- **set_transient() answers the twins' unchanged short-circuit
  (t31-glm22-4, bug:medium; tests/harness/wp-stubs.php)** — an
  identical re-save fired the full update family and answered true
  where core's delegation answers false with zero hooks; the glm17-8
  two-arm compare rides the update branch, the serialized-equality
  arm deciding over the detached row.
- **set_transient() clones an object value at the hook seat
  (t31-glm22-5, bug:medium; tests/harness/wp-stubs.php)** — a
  mutating add_option observer reached the caller's object through
  the transient seat; the glm18-8/glm19-4 clone rides the
  delegation's head, a hook-seat mutation landing in the stored row
  (core's pre-INSERT vantage) and never the caller's value.
- **set_transient() sanitizes at the head
  (t31-glm22-6, bug:medium; tests/harness/wp-stubs.php)** — the
  transient row's own filter (sanitize_option__transient_<name>)
  never fired; one run per save whichever family persists it, the
  sanitized value comparing, storing, and riding every hook.
- **settings_errors() honors its $sanitize/$hide_on_update arguments
  (t31-glm22-7, bug:low; tests/harness/wp-stubs.php)** — both were
  accepted and silently dropped, the argument-dropping class glm21-8
  closed for $setting one member over; $hide_on_update at core's own
  head, $sanitize delegated to the twin's second parameter (the
  callback's own settings errors surfacing by default).
- **get_settings_errors() answers core's dense-append shape
  (t31-glm22-8, bug:low; tests/harness/wp-stubs.php)** — the filtered
  rows were keyed by their store indices (a sparse 0/2 list for two
  errors on one setting) where core appends densely; the append
  replaces the key preservation, record order intact.
- **The escaping proof rides the real INI boundary, the doc-truth
  corrected (t31-glm22-9, test-hygiene:medium;
  tests/SecureFixturesTest.php)** — glm21-11's 'pre-space run'
  premise was false: the engine keeps an unquoted value's
  PRE-SEMICOLON run (INI comment semantics), so the pin held only
  because its poison carried a ';'; the poison now rides the engine's
  own quoted-value spelling with the whole value as the anchored
  full-line needle, the unquoted cut pinned beside it.
- **The lint walk names a declared root that names nothing — the GPC
  leg's false-green roots (t31-glm22-10, test-hygiene:medium;
  bin/lint-php.php, tests/BuildSeamPropertyTest.php,
  tests/ToolchainSmokeTest.php)** — the GPC staged leg blessed a walk
  silently skipping 3 of its 4 declared roots; the walk answers a
  counted refusal and the red exit for a missing declared root, the
  leg stages every root it declares with the summary pinned whole,
  and the four ToolchainSmokeTest lint legs stage their undeclared
  roots as empty trees.

### Fixed (shared — M3 Task 3.1, claude-glm round 21)

Seventh claude-glm pass: 15 findings (14 CONFIRMED, 1 PLAUSIBLE
accepted as internal consistency), all 15 accepted by driver
adjudication — #1 riding the ocr57-2 re-open condition (the driven
falsification of its recorded premise). Fifteen numbered commits
t31-glm21-1..15, the full offline check green after every commit, no
push. The round's shape: the glued credential vocabulary closed over
the .NET twin whose skip's premise was false; the scanner's
over-refusal direction (the cap behind the allowlist, the missing
root loud, the HTML-comment marker); the leading-zero port class
unified under one verdict sentence; the harness quartet (transient
detachment + hooks, the REQUEST_URI leak, the settings filter, the
non-finite recurring head); the owner-level diagnostics needle and
escaped INI flags at the spawn seam; the cron docblock truth; and
three measured efficiency classes with the fused-pattern redesign
deferred beside glm20's #5. Suite 1854 → 1860 tests, 47601 → 47838
assertions, 3 skipped unchanged (every delta measured from output).

- **The .NET glued header name joins the credential suffix class
  (t31-glm21-1, security:medium; shared/src/Support/SecretMask.php,
  tests/SharedOAuthContractsHttpTest.php)** —
  '__RequestVerificationToken' carries no internal delimiters (its
  underscores are leading, shed at the fold's bound-segment strip),
  so the judged name 'requestverificationtoken' matched nothing and
  the anti-forgery credential rendered verbatim while 'X-Api-Key'
  masked — falsifying ocr57-2's recorded 'already covered'
  verification; the member rides the class (the eleventh flattened
  twin), the boundary neighbors stay verbatim, and the r57-2 record
  is corrected in place.
- **The walk's 2 MB loud-cap fires only for extensions the scan
  would actually read (t31-glm21-2, security:low;
  bin/lib/secret-scanner.php)** — the cap once judged before the
  extension allowlist, so a legitimate 3 MB assets/big.png in a
  shipped artifact rejected the whole inspection over bytes the
  screen never reads; the allowlist judges first (driven: the png
  absent from the report at HEAD-red), a >2 MB .php still refuses
  loud.
- **A scan root that names nothing answers the loud refusal, never a
  clean exit 0 (t31-glm21-3, security:low; bin/lib/secret-scanner.php)**
  — a typo'd CLI target or dangling-symlink root answered 'secrets: 0
  finding(s)' exit 0, certifying clean a tree the scan never saw (the
  ocr20-4 narrative shape, never adjudicated); the refusal is a
  finding line in the glm14-2 vocabulary, both consumers deriving
  their refusal with zero changes.
- **The allow-marker grammar knows the HTML comment enclosure
  (t31-glm21-4, security:low; bin/lib/secret-scanner.php)** —
  `<!-- secrets:allow -->` was rejected while a markdown heading
  (not a comment at all) rode the `#` arm green; the enclosure joins
  the marker's own vocabulary across every markup extension, and the
  md-heading spelling's status is re-derived and pinned deliberately
  in the docblock.
- **The leading-zero port class answers one verdict sentence across
  the parse-false split (t31-glm21-5, bug:low; shared/src/Http/Url.php)**
  — parse_url() answers false outright for 6+-digit ports even
  in-range, so ':065535/' fell through the entry screen to the
  scheme/host message while ':0443/' answered the leading-zeros
  sentence; the sentence is hoisted to its own const and the entry
  screen's digit/zero arm names it (range precedence preserved,
  build-independent).
- **set_transient stores the detached copy and fires the option hook
  family (t31-glm21-6, bug:medium; tests/harness/wp-stubs.php)** — the
  stub stored the caller's live reference and fired zero hooks; the
  stored value rides wp_connectors_option_stored_copy() (glm19-5's
  doctrine — mutation after the save never leaks) and the
  add/update option family fires over the '_transient_<name>' row
  exactly as core's own delegation spells (the timeout-row half not
  modeled, the recorded simplification).
- **WpHarness::reset() clears the request-URI superglobal member
  (t31-glm21-7, test-hygiene:low; tests/harness/WpHarness.php)** —
  reset() restored GET/POST/REQUEST only, so a test's
  $_SERVER['REQUEST_URI'] assignment survived into later tests'
  add_query_arg() resolutions under --order-by=random; the member
  clears with the restore (unset, the pristine CLI state).
- **settings_errors() honors its $setting filter through the twin
  (t31-glm21-8, bug:low; tests/harness/wp-stubs.php)** — the stub
  returned the whole array over every slug (2 rows where core answers
  1) while get_settings_errors() owned the filter 15 lines below; the
  seat delegates, the bare spelling keeping the historical behavior.
- **The is_finite guard rides the recurring head too
  (t31-glm21-9, bug:low; tests/harness/wp-stubs.php)** — glm19-7's
  refusal landed single-head only, so wp_schedule_event() queued
  INF/NAN/'1e999' at the (int) cast 0 (driven red: the engine's own
  INF-cast error); both heads answer one guard, the honest basis the
  harness's own asymmetry doctrine (never queue what cannot fire).
- **glm20-3's diagnostics needle rides the spawn OWNER
  (t31-glm21-10, test-hygiene:medium; tests/SecureFixturesTest.php)** —
  the negative needle lived at one of the six spawn consumers, a
  child-side Warning/Notice/Deprecation passing unasserted at the
  other clean-exit legs; every clean-exit leg asserts now (the
  timeout-bound leg excepted), driven both ways at a non-whale leg.
- **The spawn owner escapes its -d INI flags
  (t31-glm21-11, test-hygiene:low; tests/SecureFixturesTest.php)** —
  the repo's single variable-interpolated unescaped value at an exec
  seam (literals today); each flag rides escapeshellarg, a
  metacharacter-bearing value reaching the child as one token (driven
  red at HEAD: the shell's own syntax error, exit 2, no child).
- **The cron storage docblock matches both producers' row shape
  (t31-glm21-12, test-hygiene:low; tests/harness/WpHarness.php)** —
  the annotation promised the deleted 'id' member and omitted the
  live 'interval' member; the docblock is the only contract
  (phpstan-excluded), now reading
  array{timestamp: int, args: array, interval?: int}.
- **The scanner's pattern table is built once per payload, the marker
  probes gated behind the first candidate (t31-glm21-13,
  efficiency:medium; bin/lib/secret-scanner.php)** — the ten-entry
  array was rebuilt for every line of every file and the two marker
  preg_match probes ran before any candidate; verdicts byte-identical
  over the repo tree plus a synthetic battery (diff clean), the CLI
  repo scan measuring 1.36-1.37 s at HEAD against 1.23-1.26 s with
  both hoists. The fused-pattern redesign is deferred beside glm20's
  #5 as a post-M3 design option.
- **The GPC lint leg stages its subject into a scratch tree
  (t31-glm21-14, efficiency:medium; tests/BuildSeamPropertyTest.php)** —
  the guard proof spawned the full serial php -l walk over the real
  repository (6.4 s, ~18% of the suite) for a tree-independent
  subject; the staged twin (the ToolchainSmokeTest pattern) answers
  the same assertions at 0.13 s, the ~48x cut.
- **The alias-oracle leg stages its 81 probes and lints them in one
  batched pass (t31-glm21-15, efficiency:medium;
  tests/BuildArtifactsTest.php)** — 81 serial php -l children (~3.0 s)
  became one xargs -n1 -P8 fleet with per-word verdict files (the
  per-word attribution intact, all 81 verdicts unchanged); measured
  4.4 s serial against 2.4 s batched on this 4-core host — the
  ceiling is the engine's own startup cost at 81 spawns, and the
  runner's trailing echo is load-bearing twice over (php -l refuses
  at exit 255, the status bare xargs aborts on).


### Fixed (shared — M3 Task 3.1, claude-glm round 20)

Sixth claude-glm pass over the round-19 fixes. The review: 8 findings,
all clustered on the glm19-11b post-round fix and the scanner seam.
Driver adjudication: #2, #3, #4, #6, #7, #8 accepted; #1 accepted as a
hybrid — the scanner-side whole-call census undercharge ledgered as a
NAMED residual and the real caller seam capped; #5 (per-region
tokenization) deferred as a post-M3 design option. Seven numbered
commits t31-glm20-1..7, the full offline check green after every
commit, no push. The round's shape: the spawn boundary's pins restored
(anchored verdict needles, the warnings regime, the dead survival);
the spawn-owner hoist with the timeout bound; and the loud file-root
cap. Suite 1853 → 1854 tests, 47589 → 47601 assertions, 3 skipped
unchanged (every delta measured from output).

- **A directly-named over-bound single file answers the loud 2 MB
  refusal (t31-glm20-1, security:medium; bin/lib/secret-scanner.php,
  tests/SecureFixturesTest.php)** — the scan_paths file-root arm had
  no size cap (glm14-3's "a caller naming one file owns that choice"
  clause, falsified and corrected in place), so a >2 MB named file
  kept the fatal-without-verdict window the census's uncharged
  whole-call terms leave open; the benign half driven at HEAD: a
  2.85 MB prose-heavy named payload scanned every byte to a clean
  zero-finding verdict, no refusal. The named target answers the same
  loud refusal the walk ships, naming the file; the whole-call census
  undercharge itself is the round's named residual and the
  per-region-tokenization redesign is deferred as post-M3.
- **The whale leg's verdict needles are anchored full-line matches
  (t31-glm20-2, test-hygiene:medium; tests/SecureFixturesTest.php)**
  — the spawn move left substring pins at the child boundary, so a
  drifted `count=230001` report false-passed `count=23000` (and a
  drifted first-line number its needle); each needle answers exactly
  one whole line of the report now, the drifted-report mutant pinning
  the needle's own contract (zero matches where the substring pin
  answered one).
- **The warnings-to-failures regime is restored at the spawn boundary
  (t31-glm20-3, test-hygiene:medium; tests/SecureFixturesTest.php)**
  — a whale-scale-only Warning/Notice/Deprecation regression printed
  into the spawned child's report unasserted beside green verdict
  lines; no engine diagnostic line may appear in the child's report,
  the needle's catch driven over a real spawned child's warning
  output beside the injected-line mutant.
- **The dead parent-side `$key` is deleted from the whale leg
  (t31-glm20-4, test-hygiene:low; tests/SecureFixturesTest.php)** — a
  survival from the in-process shape (the leg built the payload with
  it); spawned, the child builds its own and the parent's copy was
  assigned and never read.
- **One spawn owner serves every spawned-engine leg (t31-glm20-5,
  test-hygiene:low; tests/SecureFixturesTest.php)** — the fourth
  inline copy of the realpath+assertNotFalse pair and exec/report
  stanza crossed the repo's own recorded hoist threshold, the copies
  already drifting (`";\n"` concat against the sprintf bind, the -d
  flags scattered per site); the owner standardizes the sprintf bind
  (the whale's literal `%.3f` re-spelled `%%.3f`), carries the
  path-asserted-before-embed doctrine, and takes the INI flags per
  call — all four legs riding it, verdicts byte-identical.
- **The spawn is bounded (t31-glm20-6, test-hygiene:medium; tests/
  SecureFixturesTest.php)** — exec() waits on the child forever, so a
  never-terminating scanner regression crossing into a spawned leg
  hung phpunit whole (the wall-clock pins guard the slow child, never
  the one that never answers; the HEAD shape driven under a shell
  kill); the owner rides coreutils timeout(1) on POSIX hosts, the 30
  s default swept across all four legs, and the driven leg proves the
  mechanism at its own tight bound — a sleeping child answers
  timeout(1)'s exit 124, its unfinished verdict line absent.
- **Round 20 in the refutation ledger (t31-glm20-7, docs; CHANGELOG.md,
  docs/review/REFUTATION_LEDGER.md)** — the round's paragraph records
  the adjudication (the #1 hybrid, #5 deferred as a post-M3 design
  option), the two verifier refutations (the environment-relative
  guard claim, the absolute timing bound claim), and the new named
  residual; the glm19-11b paragraph carries its in-place correction
  ("first/middle/last exact" first vouched substring needles) and the
  whole-call census undercharge residual.

### Fixed (shared — M3 Task 3.1, claude-glm round 19)

Fifth claude-glm pass over the round-18 fixes. The review: 10
CONFIRMED correctness + 1 measured efficiency; items 12-14 the
cleanup class. Driver adjudication: findings 1-11 accepted; 12-14
(the open-tag classifier consolidation, the cron find-row helper, the
dead short-echo probe arm) DEFERRED to the cleanup sweep per
precedent — ELEVEN numbered commits t31-glm19-1..11, the full
offline check green after every commit, no push. The round's shape:
three driven regressions of round 18's own commits closed (the
in-string close splitting the region walk, the mixed-line marker
crossing, the alternative-syntax /s glue); the core-parity cluster
resolved against the pinned WP 7.1.1 — glm18-7's own fractional-
acceptance premise falsified by core's key truncation and corrected
in the ledger; the fatal-band pin's three gaps; and the compositor's
quadratic class made linear with a measured equivalence pin. Suite
1843 → 1853 tests, 47538 → 47586 assertions, 3 skipped unchanged
(every delta measured from output).

- **An in-string close tag no longer splits the sample region
  (t31-glm19-1, security:medium; bin/lib/secret-scanner.php, tests/
  SecureFixturesTest.php)** — the region walk closed each region at
  the first byte-level `?>`, so a close spelled inside a quoted
  interior split it and the code after the in-string close fell to
  the line-local arm, its marker honoring there (the glm18-1
  laundering class reopened; driven: 0 findings at HEAD, 1 at base).
  The close rides the tokenizer now — the engine's lexer the one
  owner of where PHP mode ends — and the census judges before the
  tokenizing walk through a byte-level INI-independent open
  pre-screen (verdict-identical routing, the loud refusal never
  traded for a mid-walk fatal).
- **The line-skip never crosses a region boundary (t31-glm19-2,
  security:medium; bin/lib/secret-scanner.php, tests/
  SecureFixturesTest.php)** — the composed code view fed the whole
  mixed line to the marker judge, so a prose marker outside the
  region exempted a key inside it (driven: `<?php $k = "<key>"; ?>
  // secrets:allow` answered 0 at HEAD, 1 at base). The exemption is
  per-arm now: each match is exempt only by the marker of its own
  arm (the region's code view for region bytes, the line-local arm's
  own marker for prose bytes), never crossed in either direction.
- **The foreach header capture is alternation-aware
  (t31-glm19-3, bug:low; bin/lib/plugin-tools.php, tests/
  SelfContainmentLoopWritesTest.php)** — glm18-3's `/s` let the lazy
  capture glue across an `endforeach` boundary onto a later
  foreach's `) {`, consuming the real header and phantom-flagging
  the include over it (driven: 1 violation at HEAD, 0 at base). The
  capture is tempered by the endforeach token and an
  alternative-syntax header matches its own `:` close, its binding
  collecting like any brace-syntax twin.
- **add_option() clones at the true head, core's both-heads shape
  (t31-glm19-4, bug:low; tests/harness/wp-stubs.php, tests/
  FoundationHarnessTest.php)** — the stub's direct-add path stored
  the caller's live reference, so a mutate-in-place re-save through
  the other entry point answered false with zero hooks where core's
  add-time detached copy completes (driven — glm18-8's exact class
  through the other entry point; core clones at option.php:1108-1110
  beside :882-884, pinned 7.1.1).
- **The stored row is serialized-equal (t31-glm19-5, bug:low;
  tests/harness/wp-stubs.php, tests/FoundationHarnessTest.php)** —
  the head clone is shallow and core's row is serialized bytes at
  the database layer, so no nested reference survives the write; the
  harness's shallow clone kept nested objects shared and a nested
  mutation answered false with zero hooks where core's serialized
  row completes (driven). The serialization detachment rides the
  write (unserialize over serialize) at both INSERT sites, the hooks
  still observing the caller-shaped value at core's pre-INSERT
  vantage.
- **The fractional schedule lands at the int-truncated key
  (t31-glm19-6, bug:low; tests/harness/wp-stubs.php, tests/
  FoundationHarnessTest.php)** — glm18-7's premise that core "keys
  the fractional timestamp downstream" is falsified against the
  pinned 7.1.1: the `$crons[$event->timestamp]` key truncates 0.5
  onto key 0, next-scheduled's falsy-key guard answers false, and
  the stub's raw-0.5 row stranded cancellation permanently (driven
  at the round-18 pin). The row rides the int-truncated key now
  (core's own key shape), next_scheduled answers the falsy-key
  false, and the stranded-cancellation shape dies; the glm18-7 pin
  and ledger line carry their in-place CORRECTED pointers.
- **Non-finite timestamps refuse at the schedule guard
  (t31-glm19-7, bug:low; tests/harness/wp-stubs.php, tests/
  FoundationHarnessTest.php)** — INF and NAN pass the raw-value
  guard (is_numeric true, neither `<= 0`) where no honest schedule
  exists: the raw INF queued as a never-firing zombie while core's
  key truncation fires it every pass, and the int-truncated storage
  cast drew the engine's own "float INF is not representable"
  complaint (driven). The guard refuses non-finite values, covering
  the over-width numeric-string spellings too.
- **The fatal-band guard rides before the ini_set (t31-glm19-8,
  test-hygiene:medium; tests/SecureFixturesTest.php)** — a runner
  with live usage past the 128M pin itself gets a refused lowering
  (false plus an E_WARNING PHPUnit converts), so the pin line
  answered a spurious red, never the named skip (driven with
  ballast staged past the pin). The guard now judges first, its band
  covering the refusal shape whole; the @-suppression road refused.
- **The fatal-band floor derives from the staging peak
  (t31-glm19-9, test-hygiene:medium; tests/SecureFixturesTest.php)**
  — the floor was the pin minus the staging's resting size, but the
  dense concat holds two ~1.91 MiB copies transiently: a
  (125.8, 126.0) MiB window passed the guard and the very staging
  killed the engine at exit 255 (driven: the exhaustion inside the
  leg's own allocation, no verdict). The floor derives from the
  fixture's own arithmetic — the same expression that builds the
  entry, charged twice, plus a drift margin.
- **The ballast stager answers the landed reading (t31-glm19-10,
  test-hygiene:low; tests/SecureFixturesTest.php)** — the ballast
  loops assumed ~64 KiB growth per append but the real-usage
  reading advances in allocator chunks (~2 MiB jumps), so a ceiling
  assert could answer a red where the shape was the allocator's
  own. One stager owns the top-up (stageBallastPastFloor()), every
  ceiling judgment rides the landed reading — a jump past the
  ceiling is the named skip — and the unit pin holds the stager's
  contract, the suite's doctrine for the userland-undrivable shape.
- **The pair-bounded compositor stays linear (t31-glm19-11,
  efficiency:medium; bin/lib/secret-scanner.php, tests/
  SecureFixturesTest.php)** — the compositor re-walked all regions
  from index 0 for every line (O(lines × regions); a 23,000-pair
  .md answered in ~13.9 s, measured twice independently). The walk
  rides a by-ref region cursor now — O(lines + regions), 0.92 s
  measured on this host against 25.3 s for the reverted re-walk —
  verdicts byte-identical, the driven leg pinning 23,000 findings
  over the pairs payload beside the bounded wall clock.

### Fixed (shared — M3 Task 3.1, claude-glm round 18)

Fourth claude-glm pass over the round-17 fixes. The review: 15
findings (1-4 driven regressions of round 17's own commits, 3-5+7-10
tripping recorded re-open rules, 11 self-doctrine, 12-15 the cleanup
class). Driver adjudication: findings 1-11 accepted; 12-15 (the keyed
re-location collapse to wp_unschedule_event, the strpos fast path,
the redundant re-blank loop + stale docblock, the @var id) DEFERRED
to the cleanup sweep per precedent — ELEVEN numbered commits
t31-glm18-1..11, the full offline check green after every commit, no
push. The round's shape: glm17-3's routing traded false-negatives for
a false-positive class and an over-refusal, and all four driven legs
closed; glm17-1's "no consumer reads a newline as a code byte" claim
falsified and corrected; the core-parity cluster (keyed reschedule
write, the schedule_event ts guard, the raw-value timestamp judge,
the clone-at-head, the hook-before-write) resolved against the pinned
WP 7.1.1; the fatal-band pin; and the pair-bounded routing residual
closed. Suite 1832 → 1843 tests, 47475 → 47538 assertions, 3 skipped
unchanged (every delta measured from output).

- **Unclosed text-family samples mask their string-data interiors
  (t31-glm18-1, security:medium; bin/lib/secret-scanner.php, tests/
  SecureFixturesTest.php)** — glm17-3's ledger claim that unclosed
  samples "keep the line-local arm exactly as the pre-diff behavior
  read them" was false for string-DATA markers: a marker inside an
  unclosed sample's multi-line string interior laundered the live key
  beside it (driven: 0 findings at HEAD, 1 at base). The tail rides
  the masked view from its open tag's line onward; the prose above
  keeps the line-local arm; the tokenized tail rides the token-memory
  census (the >1.3 MB unclosed .md leg's clean verdict, purchased by
  never tokenizing the tail, corrected to the honest loud refusal —
  glm17-2's own recorded premise). The glm17-3 ledger line carries
  its in-place CORRECTED pointer.
- **A directly-named file scans by CONTENT shape, not extension
  (t31-glm18-2, security:medium; bin/lib/secret-scanner.php, tests/
  SecureFixturesTest.php)** — the file-root arm fed a php-headed
  'config.inc' into the extension-aware gate, whose arms never saw a
  closed short-echo script: its string interiors laundered through
  the line-local arm (driven: clean, exit 0, where the pre-ride base
  flagged the key). Explicitly named = operator intent: a php-headed
  named target rides the CODE arm whatever its extension spells
  (wp_connectors_head_opens_php(), INI-independent open spellings);
  the walk never sets the flag and keeps its extension screen.
- **The foreach header collector rides /s (t31-glm18-3, bug:low;
  bin/lib/plugin-tools.php, tests/SelfContainmentLoopWritesTest.php)**
  — the header regex had no /s while the as-split one line below
  always did, so a multi-line string region inside a foreach header
  (whose interior newline glm17-1's line-preserving mask now keeps)
  killed the match, the VALUE binding went uncollected, and the
  include over it phantom-flagged (driven: 0 violations at base, 1 at
  HEAD on identical input) — glm17-1's "no consumer reads a newline
  as a code byte" claim falsified by the one consumer whose regex
  never said so. The glm17-1 ledger line carries its in-place
  CORRECTED pointer.
- **The span census rides the host's ACTUAL open-tag lexing
  (t31-glm18-4, security:medium; bin/lib/secret-scanner.php, tests/
  SecureFixturesTest.php)** — the census charged every '<?'…'?>' byte
  pair the dense ~98x factor whether or not the engine opens it:
  23k '<?xml-stylesheet …?>' processing instructions in a 1.84 MB .php
  document lex as ONE inline-HTML run under the production-default
  short_open_tag=0 (measured cost ~1x) while the census answered the
  loud refusal — glm16-2's own re-open rule, tripped (driven in a
  spawned engine). wp_connectors_engine_opener_lexing() probes the
  host's own answer (two tiny token_get_all() calls, cached); a
  non-opener's bytes never enter the total. The legs pin both
  directions under pinned INI.
- **The walk's reschedule write is KEYED (t31-glm18-5, bug:medium;
  tests/harness/WpHarness.php, tests/FoundationHarnessTest.php)** —
  the re-arm raw-appended where core's $crons[ts][hook][md5(args)]
  REPLACES (cron.php:323): two due hourly members one period apart
  both re-arm onto the SAME grid timestamp, the append left both rows
  standing, and the pair double-fired on every later pass forever
  (driven: 2/2; core 1 row, 1 fire). The write rides the member's own
  core key, the same predicate the removal owns.
- **wp_schedule_event() refuses non-positive timestamps
  (t31-glm18-6, bug:low; tests/harness/wp-stubs.php, tests/
  FoundationHarnessTest.php)** — glm17-14 pinned core's guard at the
  single entry point only; the recurring entry queued ts=0/-1 as a
  due-now row that fired and re-armed forever (driven). Core's own
  head guard (cron.php:252-263), before anything is keyed, at both
  schedule entry points now.
- **The single's head guard judges the RAW value (t31-glm18-7,
  bug:low; tests/harness/wp-stubs.php, tests/FoundationHarnessTest.php)**
  — the standing (int) coercion rode ahead of the guard, inverting
  core both ways: true and '60abc' coerced positive and queued where
  core's is_numeric judge refuses; 0.5 coerced to 0 and refused where
  core schedules and keys the fractional timestamp downstream
  (driven: every leg inverted). The guard is core's own spelling, a
  '+= 0' normalization behind it, the queued row numeric.
- **update_option() clones an object value at the head
  (t31-glm18-8, bug:low; tests/harness/wp-stubs.php, tests/
  FoundationHarnessTest.php)** — the harness stores live references,
  so a caller mutating the object they saved and re-saving it hit the
  identity arm with the SAME reference on both sides: false, zero
  hooks, where core completes with the full family (driven; core
  clones at option.php:882-884 and its row is serialized bytes). The
  glm17-8 pin's stored-INSTANCE assertion — the reference-storage
  artifact the clone falsifies — carries its in-place CORRECTED
  pointer.
- **add_option() fires the GENERIC hook BEFORE the write
  (t31-glm18-9, bug:low; tests/harness/wp-stubs.php, tests/
  FoundationHarnessTest.php)** — the stub wrote first, so an observer
  at 'add_option' read the NEW value through get_option() where core
  reads the OLD one (option.php:1140's do_action precedes :1142's
  INSERT; driven). The generic action rides ahead of the write; the
  specific and closing hooks stay post-write.
- **The dense-bound pin verifies its ceiling BEFORE the staging
  allocations (t31-glm18-10, test-hygiene:medium; tests/
  SecureFixturesTest.php)** — glm17-12's pin left a fatal band: with
  live usage in the ~(126, 128) MiB window the ini_set still succeeds
  and the leg's own ~1.9 MB staging kills phpunit at exit 255 before
  any verdict (mechanism driven on a 512M host). The guard measures
  REAL usage (the engine's own limit accounting) and skips loudly
  past the band's floor, never lowering into the window; a
  ballast-staged leg drives the band itself, the skip caught and
  pinned green.
- **A complete-pair MENTION routes only the sample region
  (t31-glm18-11, security:medium; bin/lib/secret-scanner.php, tests/
  SecureFixturesTest.php)** — prose mentioning a complete
  '<?php … ?>' pair routed the WHOLE text-family file onto the masked
  view, blanking the mention's surrounding prose and a marked
  fixture's marker with it (identical at base, pre-existing,
  unrecorded — the residual closed). The routing is PAIR-BOUNDED:
  wp_connectors_php_sample_regions() answers every matched pair plus
  the unclosed tail, wp_connectors_sample_region_line_view() composes
  each line (region bytes masked, outside bytes line-local,
  glm18-1's hybrid the mixed-line special case of the one
  compositor), and the census rides the full host-aware walk. The
  glued-opener spelling stays line-local under every INI — the
  recorded lexer-refused-opener corner's OFF-host half closes.

### Fixed (shared — M3 Task 3.1, claude-glm round 17)

Third claude-glm pass over the round-16 fixes. The review: 15
findings (11 CONFIRMED, 2 PLAUSIBLE, the cleanup class). Driver
adjudication: all 14 non-cleanup findings accepted — FOURTEEN
numbered commits t31-glm17-1..14, the full offline check green
after every commit; F15 (the third hand-synced copy of the
success-path algorithm) deferred to the cleanup sweep with the
round-16 cut list. Three findings falsify round 16's own recorded
premises with driven evidence (the eleventh through thirteenth
driven refutations of record), and the core-parity cluster was
re-adjudicated against the LOCAL pinned WP 7.1.1 (option.php:1142/
:923/:1113, cron.php:135-145/:48-60, wp-cron.php's fire loop) —
CORE WINS all six. Suite 1821 → 1832 tests, 47417 → 47475
assertions, 3 skipped unchanged (every delta measured from output).

- **The mask ride is LINE-PRESERVING (t31-glm17-1, security:high;
  bin/lib/plugin-tools.php, bin/lib/secret-scanner.php, tests/
  SecureFixturesTest.php)** — glm16-1's mask blanked interior
  newlines into spaces, so the masked view had FEWER lines than the
  source and every line past the first multi-line region shifted
  into an earlier line's view: a code marker lines BELOW a live key
  exempted it (driven). One region-blank spelling keeps the line
  terminator; view lines map 1:1 onto source lines.
- **The token-memory gate bounds the SUM of the spans
  (t31-glm17-2, security:medium; bin/lib/secret-scanner.php,
  tests/SecureFixturesTest.php)** — token_get_all() materializes
  the whole stream, so 24 dense ~100 KB spans passed the
  largest-span gate and fataled at 128M with no verdict (driven in
  a spawned engine). The over-refusal half of the finding (the
  >1.3 MB unclosed-sample .md) closes at glm17-3's routing, where
  its driven legs land.
- **The pre-gate is extension- and shape-aware (t31-glm17-3,
  security:medium; bin/lib/secret-scanner.php, tests/
  SecureFixturesTest.php)** — a text-family payload carrying an
  '<?xml' declaration or short-echo sample rode the masked view and
  its prose markers blanked (driven: a marked .md answered 1
  finding). Text-family payloads reach the tokenizer only for a
  '<?php' open with a matching close; the benign prose shapes keep
  the line-local arm, the matched-close sample stays masked.
- **The stored-false add_option COMPLETES (t31-glm17-4, bug:medium;
  tests/harness/wp-stubs.php, tests/FoundationHarnessTest.php)** —
  glm16-4's duplicate-key-collision premise is falsified against
  the pinned 7.1.1: the INSERT rides ON DUPLICATE KEY UPDATE
  (option.php:1142) and the guard returns early only for a row
  that reads non-false. Hooks fire, the row writes, true; the
  silent no-op stands for the non-false duplicate alone.
- **The singles dedupe window is core's TWO-SIDED band
  (t31-glm17-5, bug:medium; tests/harness/wp-stubs.php, tests/
  FoundationHarnessTest.php)** — glm16-7's one-sided floor answered
  the wrong shape both directions (driven): min = 0 within ten
  minutes of now (every past identical single counts), else ts−10
  min; max = now+10 min for a past new ts, else ts+10 min
  (cron.php:135-145).
- **The duplicate predicate is recurrence-blind; a single never
  overwrites a recurring row (t31-glm17-6, bug:medium;
  tests/harness/wp-stubs.php, tests/FoundationHarnessTest.php)** —
  glm16-6's keyed replace on the single arm inverted core: the
  duplicate check answers FALSE before any keyed write, and under
  the band the replace loop was unreachable by construction —
  deleted with the recurrence term.
- **The recurring reschedule rides the captured copy
  UNCONDITIONALLY (t31-glm17-7, bug:medium; tests/harness/
  WpHarness.php, tests/FoundationHarnessTest.php)** — the re-arm
  once gated on the live re-location died on a mid-walk
  cancellation; core's wp-cron.php reschedules before it even
  attempts the unschedule — cancellation stops the fire-target
  row, never the recurrence (driven).
- **The unchanged-value compare carries maybe_serialize equality
  (t31-glm17-8, bug:medium; tests/harness/wp-stubs.php, tests/
  FoundationHarnessTest.php)** — option.php:923's second arm:
  equal-valued non-identical arrays/objects are UNCHANGED (no
  write, no hooks), the identity-vs-value class glm16-12
  eradicated from the cron key reopened 350 lines above.
- **add_option() sanitizes at the TRUE head, before the guard
  (t31-glm17-9, bug:low; tests/harness/wp-stubs.php, tests/
  FoundationHarnessTest.php)** — option.php:1113 precedes :1121:
  the no-op add still counts the add-head run; the both-heads
  runs=2 arithmetic unchanged.
- **The walk's removal rides the CORE KEY (t31-glm17-10,
  bug:medium; tests/harness/WpHarness.php, tests/harness/
  wp-stubs.php, tests/FoundationHarnessTest.php)** — the by-id
  re-location read 'absent' for an unschedule-then-re-add of the
  identical-key event and the twin fired twice (driven); the
  removal rides (timestamp, args digest) exactly as wp-cron.php
  unschedules, and the synthetic id field is deleted with the
  spelling.
- **The empty-host arm outranks the backslash probe
  (t31-glm17-11, bug:low; shared/src/Http/Url.php, tests/
  SharedOAuthContractsHttpTest.php)** — 'https://user@:70000\x'
  answered the backslash sentence, contradicting glm16-10's own
  'absent authority is the primary defect' order; the entry
  derives the host region first and every probe arms inside the
  non-empty-host arm.
- **The dense-entry bound test pins its own memory ceiling
  (t31-glm17-12, test-hygiene:low; tests/SecureFixturesTest.php)**
  — the asserted refusal is environment-relative (red under
  memory_limit=-1 or ≥~190M, driven under both); the test pins
  128M and restores the runner's limit in a finally.
- **The memory-limit parser is WIDTH-AWARE (t31-glm17-13, bug:low;
  bin/lib/secret-scanner.php, tests/SecureFixturesTest.php)** —
  the integer multiply overflowed the host's width ('4G' on 32-bit
  answering a wrapped count, every scan refusing); the scale rides
  float arithmetic saturated at PHP_INT_MAX, the unit pin driving
  the parser directly.
- **wp_schedule_single_event() refuses ts ≤ 0 (t31-glm17-14,
  bug:low; tests/harness/wp-stubs.php, tests/
  FoundationHarnessTest.php)** — cron.php:48-60's own head guard:
  a non-positive timestamp answers FALSE, never a queued due-now
  entry (driven).

### Fixed (shared — M3 Task 3.1, claude-glm round 16)

Second claude-glm pass over the round-15 fixes. The review: 15
findings, every survivor driven; 3 reviewer hypotheses refuted in
verification. Driver adjudication: all 15 accepted — THIRTEEN numbered
commits t31-glm16-1..13 (the scanner family's three findings = one
root-fix commit), the full offline check green after every commit; the
refutations (including the reviewer's ledger-split note — pre-existing,
verified at 3ebdf63, no action) go to the ledger only. Six findings
indict round 15's own fixes. Suite 1809 → 1821 tests, 47350 → 47417
assertions, 3 skipped unchanged (every delta measured from output).
The round's shape: glm15-1's own fix failed open four ways — the
census deleted, the nesting-aware mask ridden as the single owner, and
the mask ride owning its memory bound; the core-parity cluster
(5/8/10) resolved FOR core — the round-15 runs=1 spec was wrong; cron
storage recurrence-blind replace plus core's dedupe window; the
snapshot fires unconditionally; the defaulted method before the
filter; two Url verdict-class adjudications; the serialized-digest
args key; staging-inside-try.

- **The heredoc census is deleted; the marker judge rides the ONE
  token-masked view (t31-glm16-1, security:high; bin/lib/
  secret-scanner.php, bin/lib/plugin-tools.php, tests/
  SecureFixturesTest.php)** — glm15-1's census failed open four ways
  (all driven): a NESTED heredoc clobbered its single-boolean state
  machine (only the inner body marked; live keys on outer body lines
  answered zero findings through the CLI gate); every other
  token-visible data region laundered (multi-line quoted interiors,
  __halt_compiler() tails, close-tag-bounded inline HTML,
  lexer-refused openers); the EOF branch was off by one
  (byte-identical contents ± one newline flipped the verdict). The
  masker gains the one region class it lacked (T_INLINE_HTML blanks,
  every consumer), scan_string() builds one length-preserving view per
  PHP-bearing payload, and payloads with no '<?' keep the pinned
  non-PHP tolerance (markers in .txt/.md fixtures stay honored).
- **The mask ride owns its memory bound (t31-glm16-2, security:medium;
  bin/lib/secret-scanner.php, tests/SecureFixturesTest.php)** — the
  ride tokenizes every PHP-bearing payload, and token_get_all()
  materializes the whole stream (~98× the source on dense input
  measured): a ~1.9 MB entry under the walk's own 2 MB cap fataled at
  128M with no verdict. The cost estimate rides the LARGEST PHP-mode
  span (prose between tags is one T_INLINE_HTML token — a markdown
  ledger tokenizes at its samples' cost, not its megabytes); an
  over-bound span answers the loud refusal in the glm14-2 vocabulary,
  never a silent fatal. Named ceiling: a '?>' woven inside strings
  splits a span the lexer keeps whole (the pre-round fatal class, no
  honest producer ships it).
- **update_option() sanitizes at the head, then compares
  (t31-glm16-3, bug:high; tests/harness/wp-stubs.php, tests/
  FoundationHarnessTest.php)** — the raw-compare-first never consulted
  the sanitizer over a raw-equal save, and a false-returning callback
  completed an ADD core refuses (the sanitized false equals the
  missing-row false: one refusal, no hooks, no write). Core's own
  order; the glm23-8 unchanged-value contract keeps its outcome.
- **add_option() over an existing row is core's duplicate-key silence
  (t31-glm16-4, bug:medium; tests/harness/wp-stubs.php, tests/
  FoundationHarnessTest.php)** — the stored-false ADD completed
  observably (hooks fired, value wrote, autoload flipped) where
  core's INSERT collides and answers a silent no-op false. One
  exists-check for both stored shapes; the glm15-14 test rewritten to
  the closed contract.
- **A first save runs the sanitizer at BOTH heads
  (t31-glm16-5, bug:medium; tests/harness/wp-stubs.php, tests/
  FoundationHarnessTest.php)** — the round-15 runs=1 spec was wrong:
  core sanitizes at update_option()'s head AND the add_option() it
  delegates to (first save = 2 runs, structural through the
  delegation, never a forced double call; subsequent saves 1). The
  seam docblock and the glm15-3 pin carry the corrected spec.
- **The keyed cron write is recurrence-blind (t31-glm16-6, bug:medium;
  tests/harness/wp-stubs.php, tests/FoundationHarnessTest.php)** — a
  single scheduled over an identical-key RECURRING entry appended and
  double-fired in one tick where core's key replaces. The single's
  keyed write replaces in place (the interval dies with the
  overwritten row, the id staying for the by-id fire walk).
- **The singles dedupe answers FALSE; the window is time()-anchored,
  floored, inclusive (t31-glm16-7, bug:medium; tests/harness/
  wp-stubs.php, tests/FoundationHarnessTest.php)** — the skip answered
  true where core answers false, and the symmetric abs() window deduped
  pairings core stacks: an identical single dedupes when the EXISTING
  entry sits at or after now() − 10 minutes (the harness's
  deterministic clock standing in for time()); past singles pile as
  bursts. 9:59 old dedupes, 10:01 old stacks.
- **A snapshot member fires unconditionally (t31-glm16-8, bug:medium;
  tests/harness/WpHarness.php, tests/FoundationHarnessTest.php)** — a
  handler's unschedule-then-reschedule of a not-yet-fired snapshot
  member suppressed its fire; core's wp_cron() walks its captured copy
  and never consults the live registry for permission. The by-id
  re-location survives as registry bookkeeping (remove, re-arm); the
  fire rides the snapshot's own args.
- **The defaulted method lands BEFORE pre_http_request
  (t31-glm16-9, bug:low; tests/harness/wp-stubs.php, tests/
  FoundationHarnessTest.php)** — the round-15 GET fix computed the
  default for the recorder only, handing mocks a method-less array;
  core settles 'GET' in $args before the short-circuit hook. The
  default now lands at the head — recorder, filter, and every
  downstream consumer see one shape.
- **The empty host wins the verdict over any port tail
  (t31-glm16-10, bug:low; shared/src/Http/Url.php, tests/
  SharedOAuthContractsHttpTest.php)** — 'https://:70000' answered the
  out-of-range PORT sentence over a host of zero bytes (glm14-9's
  pre-existing entry design, adjudicated this round: the authority
  being absent is the primary defect). The port screen arms only
  after a non-empty authority; empty-host shapes wear the host
  sentence.
- **The backslash screen outranks the port probe on failed parses
  (t31-glm16-11, bug:low; shared/src/Http/Url.php, tests/
  SharedOAuthContractsHttpTest.php)** — a backslash-bearing spelling
  with a glued port tail answered the DIGITS sentence (driven),
  splitting the backslash class across two sentences. One const owns
  the sentence; the entry screen's probe rides first, before the
  empty-host arm and the port probes.
- **The cron args identity rides the serialized digest
  (t31-glm16-12, bug:medium; tests/harness/wp-stubs.php, tests/
  FoundationHarnessTest.php)** — every args comparison rode PHP's
  identity (===) where core's key is md5(serialize($args)): two
  equal-valued object args stacked twins that double-fired. One
  helper owns the key shape; all four sites ride it.
- **The glm15-10 gate test stages inside its guarded try
  (t31-glm16-13, test-hygiene:low; tests/BuildArtifactsTest.php)** —
  the battery's mkdir, staging writes, and healthy control preceded
  the try, so a control failure stranded the scratch tree under
  dist/ (the ocr55-7 doctrine the file's own census claims closed).
  Everything rides the guarded try now; the finally owns every exit.

### Fixed (shared — M3 Task 3.1, claude-glm round 15)

First claude-glm review round after glm14 (the escalated external
review's second pass). The review: 15 findings, 14 CONFIRMED, 2
refuted — SIX of the findings indict glm14's own fixes, three of them
falsifying recorded premises with driven evidence. Driver adjudication:
all 15 accepted — fifteen numbered commits t31-glm15-1..15 (one per
finding, the full offline check green after every commit); the two
refutations (the tr_TR fold mechanism dead on PHP 8+ — confirming
r68-2/r69-2 as doctrine-only; the check_admin_referer die-contract
already documented+pinned) go to the ledger only. Suite 1794 → 1809
tests, 47275 → 47350 assertions, 3 skipped unchanged (every delta
measured from output). The round's shape: six glm14 indictments —
three premises falsified driven (sanitize-in-update_option,
grid-align reschedule, glued-port parse_url gap), two fix-extensions
(grammar's fourth copy consolidated, the unregister sibling), one
masking regression corrected; two new live fail-opens (heredoc marker
laundering, empty-literal pairing); entry-screen anchoring; three
wp-stubs parity classes; two latent divergences closed preemptively;
and the asserted-staging census closed.

- **The secrets:allow marker must sit in code, never in heredoc data
  (t31-glm15-1, security:medium; bin/lib/secret-scanner.php, tests/
  SecureFixturesTest.php)** — the exemption honored markers inside
  heredoc/nowdoc DATA (the line-local blanker owns quoted literals
  only), so a body line with a live key plus a lookalike marker
  laundered the finding (driven red at HEAD: zero findings, a shipped
  zip ACCEPTED). Heredoc bodies are string data through the token
  census now; an unterminated heredoc marks through EOF (fail-closed).
- **The house quote grammar is ONE owner, the empty-literal pairing
  dead (t31-glm15-2, security:high; bin/lib/plugin-tools.php, bin/lib/
  secret-scanner.php, tests/SelfContainmentEscapedQuoteTest.php)** —
  the grammar existed as FOUR inline copies with THREE variants, and
  the '+'-quantifier copy glm14-1 landed fail-opens: an empty literal
  paired with the next literal's opening quote, so
  `require __DIR__ . "" . "/sub/../../outside.php";` answered zero
  violations through every gate (driven). One shared grammar, '*'
  quantifier (empty literals match themselves), all four call sites
  riding it.
- **sanitize_option() at the head of update_option()/add_option()
  (t31-glm15-3, bug:medium; tests/harness/wp-stubs.php, tests/
  FoundationHarnessTest.php)** — glm14-10's premise ("core's own
  update_option does not sanitize") is FALSE (WP 7.1.1 option.php:886/
  :1113, driven), and its regression masked the gap by calling
  sanitize_option() manually first. The stub owns the head-of sanitize
  (the sanitized value compares, stores, and rides every hook; the
  callback runs exactly once per save on both families); the
  regression drops the manual call and counts runs.
- **runDueEvents() fires the entry snapshot
  (t31-glm15-4, bug:high; tests/harness/WpHarness.php, tests/
  FoundationHarnessTest.php)** — the while(true) rescan re-found every
  mid-run insert, so a handler re-scheduling an already-due event hung
  the suite forever (driven: timeout 10, exit 124) and mid-run
  schedules fired in the same call core's wp_cron() defers. The due
  set is captured on entry, stably ordered (glm14-7's tie rule
  intact), each member re-located by id in the live registry; the pass
  is bounded by the snapshot's size by construction.
- **The recurring reschedule grid-aligns
  (t31-glm15-5, bug:medium; tests/harness/WpHarness.php, tests/
  FoundationHarnessTest.php)** — glm14-7's now+interval spelling
  DRIFTS one fire's lateness into every later due; core grid-aligns:
  now + (interval − ((now − ts) % interval)). With the round's own
  pinned numbers core answers 1700003600 where the harness answered
  1700004600 — and the pin asserted the drift number mislabeled as
  core semantics. The pin corrects to the driven number; on-time
  fires are byte-identical under both spellings.
- **The glued port answers the port sentence at the entry
  (t31-glm15-6, bug:low; shared/src/Http/Url.php, tests/
  SharedOAuthContractsHttpTest.php)** — parse_url() fails EVERY glued
  port of five digits or more (in-range ':65534x' included) while
  short glue parses truncated: one malformed class, two sentences
  (driven: the five-digit glue wore the scheme/host sentence,
  falsifying glm14-9's glue clause). One shared compile-time sentence
  for both screens; userinfo ':digits@' shapes keep the generic
  refusal.
- **The entry probes are anchored at the first authority
  (t31-glm15-7, bug:low; shared/src/Http/Url.php, tests/
  SharedOAuthContractsHttpTest.php)** — the entry regex restarted at
  every '://' and scanned into the query: a query-carried ':70000'
  answered the PORT sentence over an empty-host failure (driven). The
  probes ride the derived authority (userinfo after the last '@',
  port colon after any ']') — the query never feeds the verdict; the
  success path reuses the same derivation, the twin deleted.
- **unregister_setting() removes the sanitize hook
  (t31-glm15-8, bug:low; tests/harness/wp-stubs.php, tests/
  FoundationHarnessTest.php)** — the registry row went away but the
  callback stayed ON the filter core's registration wires, so
  register(A), unregister, register(B) answered A still riding
  (driven: 'vAB' where core answers 'vB'). The recorded callback is
  removed from its own filter (glm14-10's sibling).
- **Cron schedules apply the cron_schedules filter; unknown
  recurrences refuse; weekly joins the defaults
  (t31-glm15-9, bug:medium; tests/harness/wp-stubs.php, tests/
  FoundationHarnessTest.php)** — three driven divergences: the filter
  was never applied (a plugin's custom schedule invisible), an
  unknown recurrence was accepted with interval 0 (fires once, never
  reschedules; core returns false), and 'weekly'/WEEK_IN_SECONDS were
  absent. Core's own shape: filter first, defaults merged over it,
  unknown recurrences refused with no queue entry.
- **The autoloader and version-constant readers own their read
  failures (t31-glm15-10, bug:medium; bin/lib/plugin-tools.php,
  tests/BuildArtifactsTest.php)** — the pair still hand-rolled the
  (string) file_get_contents laundering read glm14-2 swept: a
  chmod-0000 src/autoload.php answered three misattributed verdicts
  through all three gates. One loud FAIL naming the unreadable file
  each (the glm14-2 sibling vocabulary).
- **add_query_arg() keeps the fragment at the tail
  (t31-glm15-11, bug:low, latent; tests/harness/wp-stubs.php, tests/
  FoundationHarnessTest.php)** — a '#fragment' was swallowed into the
  last param's value (re-encoded '%23frag') or a new param appended
  inside the fragment. Zero callers today, but OAuth redirect URLs
  are where fragments live: closed preemptively at core's shape.
- **wp_remote_request() defaults the method to GET
  (t31-glm15-12, bug:low, latent; tests/harness/wp-stubs.php, tests/
  FoundationHarnessTest.php)** — the stub defaulted POST where core's
  WP_Http::request defaults GET, recording and mocking every
  default call as a POST. The raw entry point's default changes; the
  get/post/head wrappers set their methods explicitly and are
  untouched.
- **Identical cron entries replace; singles carry the 10-minute
  dedupe window (t31-glm15-13, bug:medium; tests/harness/
  wp-stubs.php, tests/FoundationHarnessTest.php)** — identical
  reschedules APPENDED and double-fired in one tick where core's
  keyed array REPLACES (driven); singles deduped only the exact
  timestamp. The recurring schedule replaces in place on the
  identical key; singles dedupe within 10 minutes of a pending
  identical single, distinct beyond it.
- **The update_option() delegation predicate is get_option-shaped
  (t31-glm15-14, bug:medium; tests/harness/wp-stubs.php, tests/
  FoundationHarnessTest.php)** — an option STORED AS FALSE routed to
  the UPDATE hook family in the harness, the ADD family in core
  (driven): core's get_option() reads stored-false the same false a
  missing row answers. The delegation rides false === $old and
  add_option() proceeds for stored-false (core's own predicate; the
  duplicate-key insert failure core then hits is named at the seam as
  the one divergence — the hook family and eventual value match).
- **The corrupt-seed plant asserts its own write
  (t31-glm15-15, test-hygiene:low; tests/BuildSeamPropertyTest.php)**
  — the file's one unasserted staging write after the t31-ocr55-5
  census: a failed write passed both pins over a fixture that never
  existed. assertNotFalse through the file's asserted-staging
  vocabulary, the failure riding the seed channel as the ROW's FAIL.

### Fixed (shared — M3 Task 3.1, claude-glm round 14)

First claude-glm `/code-review max` round after the OCR phase
converged (OCR round 70: zero findings, 61/61 files — the r37 close
criterion met). The review: 25 candidates, 3-state verification, 15
findings survived (12 CONFIRMED, 3 PLAUSIBLE), 10 refuted with quoted
evidence (adjudications stand). Driver adjudication: findings 1-10
accepted — ten numbered commits t31-glm14-1..10 (one per finding, the
full offline check green after every commit); findings 11-15 (cleanup:
twin use-import grammars, seven-fold always-throw duplication, the
//u UTF-8 twin, the per-caller validated-spelling contract, the
apply_filters 0-arity clamp) deferred to the post-convergence cleanup
sweep. Suite 1781 → 1794 tests, 47131 → 47275 assertions, 3 skipped
unchanged (every delta measured from output). The round's shape: four
driven security-gate bypasses closed at their one-owner seams, the
build CLI's silent mode-broadening refused, the hostile-tree OOM
answered with a verdict, two harness contracts made true of their own
docblocks, the port screen's dead half made honest, and the
settings-save sanitize step made emulable. The sixth driven refutation
of the loop recorded (the glm29 escaped-quote line, vector corrected);
the r6 extension-class adjudication reopened and completed.

- **The quoted-literal extraction escape-aware and decoded
  (t31-glm14-1, security:high; bin/lib/plugin-tools.php, tests/
  SelfContainmentEscapedQuoteTest.php)** — the match stopped at a
  backslash-escaped closing quote, so an outside-resolving traversal
  behind it was invisible to every self-containment gate (driven red
  at HEAD: php -l clean, zero violations). The matcher rides the house
  quote grammar and decodes to the runtime value — decoding required:
  the raw escaped bytes still compose inside. Falsifies the glm29
  escaped-quote claim (corrected, vector distinction named).
- **A failed read fails the secret scan loudly
  (t31-glm14-2, security:medium; bin/lib/secret-scanner.php, tests/
  SecureFixturesTest.php)** — both file_get_contents casts laundered a
  false read into an empty scan (a chmod-000 file with a live token:
  "0 finding(s)" exit 0). The failure is a finding line now; the CLI
  exit code and the inspector's verdict both refuse (driven, both
  arms, non-root).
- **The over-size skip is loud (t31-glm14-3, security:medium; bin/lib/
  secret-scanner.php)** — files over 2 MB were silently exempt from
  credential detection; a zip shipping a >2 MB entry passed the
  inspector's credential screen ACCEPTED. The cap stays (a stated
  memory bound) and the skip answers a finding line (driven: the
  oversized twin surfaces beside the under-limit one).
- **The is-a-source class owns the engine's template extensions
  (t31-glm14-4, security:medium; bin/lib/plugin-tools.php, bin/lib/
  secret-scanner.php, bin/inspect-artifact.php)** — a '.phtml' entry
  with a parse error plus a live token passed inspection while the
  identical bytes as '.php' were rejected (driven on a copy of the
  real dist zip). The r6 producer-gated adjudication reopened and
  completed: '.php'/'.phtml' at the ONE owner closes every channel at
  once; '.php5'/'.php7'/'.inc' stay out pending a driven producer.
- **The build CLI validates its option map from argv
  (t31-glm14-5, bug:medium; bin/build.php)** — a typo'd '--slugg=zai'
  exited 0 having rebuilt EVERY connector; the space-separated value
  form died in a misleading refusal. Unknown options and value-less
  bindings refuse with the actual input named, nothing built on a
  refused invocation (driven; getopt's parsed map can never name a
  dropped option — the walk reads argv itself).
- **The shared view provider's retention is bounded
  (t31-glm14-6, bug:medium; bin/lib/plugin-tools.php, tests/
  UnusedImportScannerTest.php)** — the memo never evicted, and the
  inspector rides it over hostile extracted trees: ~40 MB of .php
  entries retained ~120 MB, dying at exit 255 with no verdict
  (measured). FIFO-bounded at 24 MB — above the whole repo tree, so
  the single-tokenize purpose survives; over-bound walks re-tokenize
  on re-consult (driven: red at HEAD died in the exhaustion fatal
  itself).
- **Due events fire in timestamp order; overdue recurring fires once
  (t31-glm14-7, bug:medium; tests/harness/WpHarness.php, tests/
  FoundationHarnessTest.php)** — the walk fired in registration order
  against the docblock's own promise, and a day-overdue hourly event
  replayed 25× where core fires once and reschedules from now (both
  driven red at HEAD). Equal timestamps keep registration order.
- **The snapshot compare owns its read and its decode
  (t31-glm14-8, bug:low; tests/harness/WpConnectorsTestCase.php,
  tests/FoundationHarnessTest.php)** — a corrupt or unreadable
  snapshot misreported as "Captured request drifted" over the silent
  null/false decode (driven red at HEAD). Unreadable and corrupt
  answer as themselves, naming the file and the json error; the 3×
  re-read folded into one.
- **The out-of-range port answers the port sentence
  (t31-glm14-9, bug:low; shared/src/Http/Url.php, tests/
  SharedOAuthContractsHttpTest.php)** — parse_url() fails outright on
  ports beyond 65535, so the port died at the entry screen wearing
  the scheme/host message and the range screen's upper arm sat dead
  (driven: ':70000'). The entry names the out-of-range port; the port
  block keeps the reachable '< 1' arm; the invalid-URL battery asserts
  the message on every row, never the class alone.
- **The registered sanitize callback is wired; the save-path primitive
  exists (t31-glm14-10, bug:low, latent; tests/harness/wp-stubs.php,
  tests/FoundationHarnessTest.php)** — register_setting() recorded the
  callback but never wired it, and no sanitize_option() existed, so
  the settings-save pipeline was unemulatable (a save emulation stored
  raw input). The callback rides the sanitize_option_{name} filter
  exactly as core registers it; a null answer refuses the save
  (driven; red at HEAD: the undefined-function call itself).

### Fixed (shared — M3 Task 3.1, OCR round 69)

Sixty-ninth OCR round (61/61 fully complete): 3 findings, driver
accepts all — three numbered commits t31-ocr69-1..3 (one per finding,
no refutations this round), plus this docs record, the full offline
check green after every commit. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8→11→7→15→10→9→4→18→10→6→6→18→4→16→3→5→1→2→10→3→6→3→5→4→5→5→3→3.
Round 69 answered NEW findings, so the OCR phase continues per plan.
Suite 1779 → 1781 tests, 47124 → 47131 assertions, 3 skipped
unchanged (deltas +1/+5, +0/+0, +1/+2, every one measured from
output). The round's shape: the drive-anchor clamp — the '..'-collapse
never pops past the anchor into the vacuous empty spelling; the r68-2
claim made true at the import-position fence; the HeaderMap rejection
message names the formatting classes.

- **The '..'-collapse clamping at the drive anchor
  (t31-ocr69-1, bug:medium; tests/harness/WpHarness.php, tests/
  HarnessCopyTreeTest.php)** — a relative target whose '..' count
  exceeded the cwd's depth beneath the drive root popped the 'C:'
  anchor itself and composed '' — the vacuous spelling that passes
  every downstream guard while the landing resolves at the drive
  root. A pop that would consume the anchor stops there now (driven:
  the collapse composes the drive root, never '', through the
  reflection seam; the POSIX '/' root clamp verified unchanged).
- **The open-tag probe's keyword fold riding the ONE ASCII owner
  (t31-ocr69-2, bug:medium — claim-vs-code drift; bin/
  check-conventions.php)** — the r68-2 refutation's sweep claimed
  this seam for the locale-fold census while the compare still
  consulted strtolower(); the fold rides wp_connectors_ascii_lower()
  now (no observable behavior change — p/h are locale-invariant
  bytes, the r68-2 refutation's own leg), the claim true of the seam.
- **The control-byte rejection naming the formatting classes
  (t31-ocr69-3, documentation:low; shared/src/Http/HeaderMap.php,
  tests/SharedOAuthContractsHttpTest.php)** — the message named only
  'control characters or line breaks' while the pattern also bans
  the bidi/zero-width classes, so a value dying on a ZWJ or a soft
  hyphen gave the operator no hint of the cause. The sentence names
  the classes now (driven: both probes reject with the formatting
  class in the sentence; the pinned prefix rides byte-stable, every
  existing control-byte leg unchanged).

### Fixed (shared — M3 Task 3.1, OCR round 68)

Sixty-eighth OCR round (61/61 fully complete): 3 findings, driver
accepts all — two numbered commits t31-ocr68-1 and t31-ocr68-3 (one
per accepted finding) and ONE REFUTED AT HEAD with no commit
(t31-ocr68-2, the number preserved per the r42 shape), plus this docs
record, the full offline check green after every commit. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8→11→7→15→10→9→4→18→10→6→6→18→4→16→3→5→1→2→10→3→6→3→5→4→5→5→3.
Round 68 answered NEW findings, so the OCR phase continues per plan.
Suite 1779 → 1779 tests, 47120 → 47124 assertions, 3 skipped
unchanged (deltas +0/+3, +0/+1, every one measured from output). The
round's shape: three doctrine stragglers — the \b label-class
straggler at the dangling-alias probe (fixed), the strtolower locale
fold at the open-tag probe (REFUTED: the folded 'php' vocabulary
carries no dotted-I byte for any tr_* fold to touch), and the
root-relative Windows-absolute spelling the platform gate missed.

- **The dangling-alias probe riding PCRE's ASCII \b
  (t31-ocr68-1, bug:medium; bin/build.php, tests/
  BuildArtifactsTest.php)** — in byte mode every high byte is a
  non-word byte, so a legal label byte before an 'as' tail
  ('{Shared\Grüas}', php -l-legal input) matched /\bas\s*$/i and the
  build falsely refused it as a dangling alias (driven red at HEAD).
  The boundary rides the label-class lookbehind now (the t31-ocr60-1
  doctrine's straggler); every non-label byte before the tail still
  refuses, and the high-byte spelling constructs and rewrites.
- **The Windows-absolute gate missing the root-relative spelling
  (t31-ocr68-3, bug:low; tests/harness/WpHarness.php, tests/
  HarnessCopyTreeTest.php)** — a single leading backslash
  ('\Temp\dst') is absolute on the host it names (resolved against
  the current drive's root) and matched neither the drive-letter
  shape nor the UNC double-backslash, so the non-POSIX walk judged
  '<cwd>/Temp/dst' while the landing resolved at the drive root. The
  gate owns the leading-backslash class of any count now, the
  refusal naming all three spellings; the POSIX host keeps the
  spelling relative (driven: it lands under the cwd, never a false
  platform refusal).

### Fixed (shared — M3 Task 3.1, OCR round 67)

Sixty-seventh OCR round (61/61 fully complete): 5 findings, driver
accepts all — five numbered commits t31-ocr67-1..5 (one per finding,
no refutations this round), plus this docs record, the full offline
check green after every commit. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8→11→7→15→10→9→4→18→10→6→6→18→4→16→3→5→1→2→10→3→6→3→5→4→5→5.
Round 67 answered NEW findings, so the OCR phase continues per plan.
Suite 1777 → 1779 tests, 47101 → 47120 assertions, 3 skipped
unchanged (deltas +1/+4, +0/+10, +0/+0, +0/+0, +1/+5, every one
measured from output). The round's shape: the PCRE-D modifier sweep
('$' also matches before a final newline — the 'slug\n' tree landed
and was judged through \n-bearing relatives), the duplicate/traversal
census agreement on the junk-dot class (the wrong-premise case-fold
line beside its traversal rejection), the recursive-mkdir premise
made true, the extractTo-throw close discipline, and the comma-arm
display parity.

- **The slug grammar screen without its D modifier
  (t31-ocr67-1, bug:medium; bin/inspect-artifact.php, tests/
  BuildArtifactsTest.php)** — PCRE's '$' also asserts immediately
  before a final newline, so a zip whose entries sat under a
  top-level directory spelled 'slug\n' passed the screen and every
  downstream fence, extractTo() landed a REAL 'slug\n' directory,
  and the run judged the tree through \n-bearing relatives (driven:
  header verdicts over the landed tree, never the slug refusal).
  The screen rides '/^[A-Za-z0-9_.-]+$/D' now — '$' asserts only at
  the very end — and the round's census of the file's grammar
  anchors found exactly ONE '$' rider (this screen; the others are
  \A/\z-spelled, immune by construction). Pinned with the
  clean-slug twin proceeding past the screen.
- **The duplicate-fold exclusion missing the traversal-junk class
  (t31-ocr67-2, bug:low; bin/inspect-artifact.php, tests/
  BuildArtifactsTest.php)** — the fold's verbatim branch tested
  trim($segment, '.'), so any edge-junk byte blocked the exclusion:
  '.. ', "..\t", '. .' fell to the rtrim (which strips junk AND
  dots) down to '', the filter dropped them, and 'p/.. /x.php'
  folded onto 'p/x.php' — a case-fold-duplicate line whose premise
  is factually wrong (the spelling ESCAPES the tree on a
  normalizing host) beside its real traversal rejection (driven).
  The exclusion rides the traversal predicate's own spelling now
  (junk stripped anywhere, then dots-only ≥2): one class, both
  censuses agree. Pinned with the junk-dot limbs and the
  trailing-junk CONTENT control still folding.
- **The recursive-mkdir premise (t31-ocr67-3, documentation:low;
  bin/inspect-artifact.php)** — the t31-ocr10-2 census comment
  claimed "a pre-existing name of any kind fails" mkdir(), but the
  recursive flag returns TRUE over a pre-existing directory; only
  the CSPRNG suffix kept the shape unreachable. The flag is
  dropped — the parent exists by construction, and the stated
  premise becomes true.
- **The extractTo-throw path never closing its ZipArchive
  (t31-ocr67-4, maintainability:low; bin/inspect-artifact.php)** —
  the t31-ocr39-1 catch returned before $zip->close(), the one
  path on the resource that freed at scope teardown while every
  sibling closes explicitly. The catch closes before returning.
- **The comma arm stripping every leading separator
  (t31-ocr67-5, style:low; bin/check-conventions.php, tests/
  UnusedImportScannerTest.php)** — the comma-list handler unrolls
  through the group unroller under an empty prefix (one artifact
  separator prepended per member), but the FAIL print rode ltrim
  over ALL separators: a fully-qualified member ('use \A\B, \C\D;')
  printed as 'C\D', losing the marker the single arm prints for
  the same import spelled alone. The display strips exactly the
  one artifact separator — the FAIL vocabulary agrees across all
  three arms. Pinned through the real STDERR channel with the
  single-arm control beside it.

### Fixed (shared — M3 Task 3.1, OCR round 66)

Sixty-sixth OCR round (61/61 fully complete): 5 findings, driver
accepts all — three numbered commits t31-ocr66-1, -3, -5, and TWO
findings REFUTED on execution evidence with no commit (the
whitespace-free keyword-follower sweep — the round-65 refutation
re-driven PER SEAM at the finding's own demand — and the
close-tag-in-comment HTML arm, whose tokenizing premise this
engine's lexer refutes; see the ledger round), plus this docs
record, the full offline check green after every commit. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8→11→7→15→10→9→4→18→10→6→6→18→4→16→3→5→1→2→10→3→6→3→5→4→5.
Round 66 answered NEW findings, so the OCR phase continues per
plan. Suite 1776 → 1777 tests, 47075 → 47101 assertions, 3 skipped
unchanged (deltas +1/+9, +0/+3, +0/+14, measured from output).
The round's shape: the embed leg owning the stream-separator class
at its own collection seam (one class, three owners now), the
kind-exemption probe owning the fully-qualified separator tail,
'password' joining the credential suffix tier — and two findings
that did not survive their own mandated verification steps (every
engine-legal whitespace-free import spelling already flags at HEAD
by the keyword arm's fallthrough; the close tag after a one-line
comment is a DISTINCT token that survives both masked views).

- **The embed leg composing stream-separator entry names
  (t31-ocr66-1, bug:medium; bin/build.php, tests/
  BuildArtifactsTest.php)** — the t31-ocr63-3 screen answers only
  the plugin tree's collectFiles() walk, while the EMBED leg
  composes its entry names from the shared tree's own relatives
  (a collector carrying the symlink, near-source, extension-casing,
  and PSR-4 fences — never the separator), so a POSIX-legal
  'shared/src/Policy:Draft.php' published as
  '<slug>/src/Shared/Policy:Draft.php' at exit 0 while the
  inspector refused the same entry: the one-verdict drift one
  collection seam over (driven end-to-end at HEAD). The embed loop
  refuses the same per-segment ':' class at ITS seam now, before
  any destination is composed — one class, three owners
  (collectFiles, embed, inspector), one verdict; pinned with the
  colon-free twin building and inspecting green.
- **The kind-exemption probe missing the fully-qualified tail
  (t31-ocr66-3, bug:low; bin/build.php, tests/
  BuildArtifactsTest.php)** — group 1 of the shared-namespace use
  pattern admits the optional leading backslash of a
  fully-qualified import INSIDE the capture, so 'use function
  \…\Shared\true;' captures '…function \' and the r47-2 probe's
  kind-at-the-very-END requirement failed over the trailing
  separator: the special-class refusal fired for engine-legal
  fully-qualified kind-led spellings (php -l clean, driven), a
  false refusal the whitespace twin never hit. The probe rides
  '(?i:function|const)\s*\\\\?\z' now — the kind, optional
  whitespace, optional separator — and the kind-less
  fully-qualified twin keeps the refusal (the class import binds
  the special leaf), pinned unchanged.
- **'password' missing from the credential suffix tier
  (t31-ocr66-5, security:low; shared/src/Support/SecretMask.php,
  tests/SharedOAuthContractsHttpTest.php)** — the tier named
  'token'/'secret'/'authorization' (r24), 'key' (r52), and
  'authentication' (r55) while omitting the plainest credential
  word: 'X-Password' and the 'X-Api-Password'/'X-User-Password'
  family folded to a final segment matching neither catalog nor
  suffix and rendered VERBATIM through every safe debug form
  (driven at HEAD). 'password' joins the single-word tier, one
  member speaking every delimiter spelling per the r50-1 fold; the
  boundary unchanged ('x-password-policy', 'x-passport' stay
  verbatim), pinned with both debug channels and the neighbors.



Sixty-fifth OCR round (61/61 fully complete): 4 findings, driver
accepts all — three numbered commits t31-ocr65-2, -3, -4, and ONE
finding REFUTED on execution evidence with no commit (the
whitespace-free keyword-follower class — see the ledger round),
plus this docs record, the full offline check green after every
commit. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8→11→7→15→10→9→4→18→10→6→6→18→4→16→3→5→1→2→10→3→6→3→5→4.
Round 65 answered NEW findings, so the OCR phase continues per
plan. Suite 1775 → 1776 tests, 47062 → 47075 assertions, 3 skipped
unchanged (deltas +0/+1, +0/+1, +1/+11, measured from output).
The round's shape: the probe-before-fire twins retiring the '/'
release vehicle (destructive-capacity legs never fire at the
filesystem root — a chmod-0000 scratch tree fires the same
deterministic refusal), the bootstrap guard learning the PARTIAL
checkout (per-interface-file gates), and the whitespace-free
keyword-follower finding refuted on engine grammar (the glued
'use\Foo\Bar;' is a constant fetch, one name token, never a T_USE —
and every glued-keyword spelling that IS an import was already
matched by the keyword arm's own fallthrough).

- **The verdict-replacement leg planting its refusal at '/'
  (t31-ocr65-2, test:medium; tests/HarnessCopyTreeTest.php)** —
  the t31-ocr33-7 pin's safety rested entirely on the rrmdir root
  check: a regressed guard turns the LEG itself into the CHILD_FIRST
  destructive walk over the filesystem root on a root CI container,
  and the sibling is_writable('/') gating does not close it. ANY
  rrmdir refusal serves the leg's assertion, so the vehicle is a
  chmod-0000 scratch tree staged and released instead — the fence's
  'Failed to open directory' refusal fires deterministically on an
  unprivileged host (driven); on a uid-0 host the leg stays green
  with the catch arm unpinned, the stated price of never firing at
  the root. Assertion identical — the fix is blast-radius, green
  before and after.
- **The php-cgi child's guard leg planting its refusal at '/'
  (t31-ocr65-3, test:medium; tests/HarnessCopyTreeTest.php)** —
  the child-side twin of the same retirement: the child ran as the
  test user (uid 0 on root CI), a regressed root refusal turning it
  into the destructive walk. The child releases a chmod-0000
  scratch tree staged PARENT-side and passed through the
  environment beside the harness path (the parent's finally the
  only owner that can reclaim the stranded tree); the parent's
  opendir probe answers constructibility for the child, a uid-0
  host skipping visibly. Four assertions unchanged in shape, the
  refusal-naming one now naming the scratch tree; driven green
  with the diagnostic surfacing through php://stderr in the cgi
  SAPI.
- **The bootstrap's Shared-fixture requires gating only the whole
  directory (t31-ocr65-4, test:low; tests/bootstrap.php,
  tests/FoundationHarnessTest.php)** — the guard modeled only
  fully-absent and fully-present shared/src, but a PARTIAL checkout
  (sparse checkout, mid-rebase worktree: directory present, one
  contract file missing) executed the requires with the autoloader
  unable to resolve the fixtures' implements clauses at CLASS LOAD
  — a whole-suite bootstrap fatal. Each require gates on the
  specific interface FILE its fixture implements now: a partial
  checkout degrades to the documented per-test missing-fixture
  shape for exactly the missing pieces, never all-or-nothing.
  Driven end-to-end and pinned: a scratch checkout whose shared/src
  is copied minus Clock/ClockInterface.php runs the real bootstrap
  in a child — red at HEAD with the predicted 'Interface
  ClockInterface not found' fatal (exit 255), green after (exit 0,
  the Clock fixture absent beside the Token fixture loading through
  its present interface, 11 assertions).

### Fixed (shared — M3 Task 3.1, OCR round 64)

Sixty-fourth OCR round (61/61 fully complete): 5 findings, driver
accepts all — three numbered commits t31-ocr64-1, -2, -4 (the two
finally-chmod sites one commit), and ONE finding REFUTED on
execution evidence with no commit (the µs-fraction serializable
edge — see the ledger round), plus this docs record, the full
offline check green after every commit. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8→11→7→15→10→9→4→18→10→6→6→18→4→16→3→5→1→2→10→3→6→3→5.
Round 64 answered NEW findings, so the OCR phase continues per
plan. Suite 1769 → 1775 tests, 47056 → 47062 assertions, 3 skipped
unchanged (deltas +3/+3, +3/+3, +0/+0, measured from output).
The round's shape: the open-tag follower class ('<?phpecho' is
HTML), the three r63-1 lookbehinds riding the full shared anchor
class, and the finally-chmod twins — with the µs-fraction edge
refuted on arithmetic (truncation makes the guard stricter, never
looser).

- **The open-tag arm flipping to PHP mode on any '<?php' byte pair
  (t31-ocr64-1, bug:medium; bin/check-conventions.php)** — the
  engine lexes T_OPEN_TAG only when '<?php' is followed by
  whitespace or end of input; '<?phpecho'/'<?phpinfo()' are INLINE
  HTML under the production-default short_open_tag=Off, so an HTML
  region carrying a glued spelling then 'use …' text flipped the
  fence walk at a tag the engine never opened and raised a phantom
  over markup. The follower is the engine's own class — exactly
  [ \t\r\n] or end of input (probed: even '\x0B'/'\f' leave a glued
  spelling HTML) — and it is read from the RAW SOURCE, not the
  masked view: the view blanks a comment to spaces, and
  '<?php//note' is HTML to the engine while the blanked view would
  answer a space; tag detection stays on the view, where a
  string-embedded '<?php' is masked away entirely. '<?=' stays an
  opener unconditionally and every other '<?' spelling stays a
  non-opener (the r63-2 INI-independence kept, pinned under both
  INI settings). Driven: the glued, past-glue, and comment-glued
  rows red at HEAD (1, 2, 1), all correct now.
- **The three statement lookbehinds guarding label bytes only
  (t31-ocr64-2, bug:low; bin/check-conventions.php)** — the shared
  statement-start anchor the r63-1 census comments claim to ride
  (build.php's own spelling) includes the namespace separator, so
  'use Foo\use Bar;' matched at the SECOND use and raised a phantom
  for Bar, the exact drift class build.php's t31-ocr49-3 closed for
  the rewriter. All three statement patterns (plain, group,
  comma-list) ride the FULL class now — label bytes plus separator —
  the premise the census comment already stated, made true. Driven:
  the plain, comma-list, and group glued rows red at HEAD (1, 2,
  1), all 0 now; legal imports unchanged.
- **The permission-probe legs restoring with a bare chmod() in
  finally (t31-ocr64-4, bug:low; tests/UnusedImportScannerTest.php)**
  — a failed restore (NFS/quota/AV lock, vanished tree) converts to
  a Warning exception under the suite's convertWarningsToExceptions
  and REPLACES the in-flight verdict, the t31-ocr42-8 class the
  sibling batteries suppress at the identical seam. Both finally
  restores ride @chmod (the file's own doctrine at this seam), with
  a census comment; a file-wide sweep found no other bare
  chmod-in-finally straggler. Construction-evident; suite green.

### Fixed (shared — M3 Task 3.1, OCR round 63)

Sixty-third OCR round (61/61 fully complete): 3 findings, driver
accepts all — THREE numbered commits t31-ocr63-1..3 (one per
finding), plus this docs record, the full offline check green after
every commit. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8→11→7→15→10→9→4→18→10→6→6→18→4→16→3→5→1→2→10→3→6→3.
Round 63 answered NEW findings, so the OCR phase continues per
plan. Suite 1751 → 1769 tests, 47034 → 47056 assertions, 3 skipped
unchanged (deltas +10/+10, +7/+7, +1/+5, measured from output).
The round's shape: every finding a round-62 follow-on — the
mid-line anchor straggler, the inline-HTML brace arm, and the
builder-side stream-separator arm closing the one-verdict drift.

- **The unused-import gate's line anchor over three legal mid-line
  spellings (t31-ocr63-1, bug:medium; bin/check-conventions.php)**
  — the r62 anchors ([ \t]* under /m) still saw only
  statement-INITIAL lines, so the one-line braced namespace block
  ('namespace X { use A\B; }'), the second statement on a
  two-statement line, and the use riding the open tag's line
  ('<?php use A\B;') were invisible while bin/build.php's rewriter
  (statement-start lookbehind, no line anchor) handled them fine —
  the gate drift the round-62 census claimed closed. The
  statement-start boundary replaces the line anchor ('(?<![label
  byte])' over the ONE label class — mid-line after '{', ';', '}',
  or the open tag all satisfy it; a 'use' inside a longer label
  never does), riding all three statement patterns and the two
  keyword-prefix strips; the TRAIT FENCE carries the whole judgment
  (a one-line 'class C { use SomeTrait; }' is matched AND fenced,
  never anchor-blind). Driven: the dead mid-line spellings flag
  (red at HEAD: 0), the used twins and the one-line trait/comma/
  adaptation rows stay 0.
- **The trait-fence walk counting inline-HTML braces as code
  (t31-ocr63-2, bug:low; bin/check-conventions.php)** —
  T_INLINE_HTML keeps its bytes on the masked view, so a php
  -l-clean file whose '?>' HTML carries a template placeholder or
  inline JS/CSS braces armed an 'other' frame and every use after
  the HTML was judged a trait clause and skipped (dead imports
  escaping); a stray HTML '}' popped a frame the code still owed
  (verified verdict-neutral on every lint-clean shape — a close tag
  inside a class body is a parse error); and the '?' branch treated
  any '<?' as an open tag, so a leading '<?xml … ?>' HTML head rode
  the same arm. The walk owns the inline-HTML arm: the engine
  starts in HTML mode, braces and ';' in HTML never touch the frame
  stack, only the INI-independent spellings ('<?php', '<?=') open
  PHP mode, and a match landing in HTML is never an import (the
  region judgment the boundary anchor left for the fence). Driven:
  the unbalanced-brace, xml-led-brace, and in-HTML-use rows red at
  HEAD (0, 0, 1), all correct now; balanced, clean-xml, stray-'}'
  and used controls green before and after.
- **The builder collecting what the r62 stream fence refuses
  (t31-ocr63-3, bug:medium; bin/build.php, bin/inspect-artifact.php)**
  — ':' is legal in a POSIX filename, so 'assets/icon:2x.png'
  staged, published at exit 0, and the inspector's r62 stream fence
  refused the same bytes: build green, inspect refused, no CI run
  satisfiable. The builder owns the class at COLLECTION — a
  ':'-bearing segment answers the build's own loud refusal after
  the exclusion filter, before any staging or zip write (no
  drive-letter exemption needed on the relative side: the entry a
  'C:'-named node would compose is slug-prefixed, so the
  inspector's absolute-path head exemption never applies to it) —
  one class, two owners, one verdict, the inspector fence kept as
  defense in depth; the census rides both sites. Driven: the
  colon-bearing tree fails the BUILD loudly at collection (red at
  HEAD: the build returned a zip path), the colon-free twin builds
  and inspects green.

### Fixed (shared — M3 Task 3.1, OCR round 62)

Sixty-second OCR round (61/61 fully complete): 6 findings, driver
accepts all — FOUR numbered commits t31-ocr62-1, -2, -4, -5 (the
NBSP-duplicate finding REFUTED byte-exact at execution, no commit;
the inverted-narrative pair's premise REFUTED by its own probe, its
true residual — the 8.3/8.2.0 version anchor — the -5 commit),
plus this docs record, the full offline check green after every
commit. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8→11→7→15→10→9→4→18→10→6→6→18→4→16→3→5→1→2→10→3→6.
Round 62 answered NEW findings (four fixed, two refuted on
evidence), so the OCR phase continues per plan. Suite 1742 → 1751
tests, 47016 → 47034 assertions, 3 skipped unchanged (deltas
+8/+8, +1/+10, +0/+0, +0/+0, measured from output). The round's
shape: the braced-namespace-indent and comma-list spellings join
the mention gate (the trait fence keeping the widened anchor on
imports); the NTFS stream-separator class at every fold/lens seam;
the child-ownership finally; the round-43 getLastErrors direction
vindicated, its version anchor corrected to 8.2.0.

- **The unused-import gate's column-0 blindness over three legal
  spellings (t31-ocr62-1, bug:medium; bin/check-conventions.php)**
  — the statement patterns were ^-anchored under /m and the plain
  tail required ';' exactly, so the import INDENTED inside a braced
  namespace block, the COMMA-SEPARATED list, and the CLOSE-TAG
  terminator were invisible (every one a spelling bin/build.php's
  own classifier names as legal input): a dead import in any of the
  three rode unflagged while its ASCII twin was caught. The anchors
  own the leading-whitespace class (both patterns and both
  keyword-prefix strips), the terminator alternation rides the
  plain tail and the group's trailing check, and the comma arm
  unrolls through the same group unroller under an empty prefix —
  with the TRAIT FENCE the widening itself forced
  (wp_connectors_use_statement_in_import_position(): build.php's
  brace-kind doctrine over the masked view, mode tags as run
  boundaries), or every legitimately-used trait in the tree would
  flag. Driven: the three spellings flag dead and stay green used
  (red at HEAD: invisible); indented trait uses stay green by
  judgment, never by anchor blindness.
- **The pre-extraction fences' missing NTFS stream separator
  (t31-ocr62-2, security:medium; bin/inspect-artifact.php)** —
  ':' is neither edge junk nor slug grammar (beyond the top-level
  screen), so 'shell.php:$DATA' and 'x.php:hidden' passed the raw
  lens, the junk fold, the duplicate fold, and the dots-only
  traversal fold: on a Windows/NTFS target the bytes land in an
  alternate data stream of the colon-free file — for ':$DATA' the
  MAIN stream of shell.php — plugin-reachable, judged by nobody
  under its own spelling. One arm at the entry name, upstream of
  all four seams: ':' in any non-drive-letter position of a
  relative entry name refuses loudly (the drive-letter spelling is
  absolute-path grammar — the r39-2 fence's vocabulary — and its
  verdict unchanged, the census naming both). Driven: the
  spellings refuse naming the stream separator (red at HEAD:
  pass); 'C:/repo'-shaped absolute refusals and colon-free trees
  unchanged; the three printable-seam pins whose forged ENTRY-name
  payloads incidentally spelled 'inspect:' now spell 'inspect;'
  (the fence working — their newline/ANSI subject unchanged).
- **The live-direction sleeping child outside any finally
  (t31-ocr62-4, test:low; tests/HarnessCopyTreeTest.php)** — the
  crash-sim reap pin's sleep(30) child was spawned bare, so a
  failed assertion between spawn and killChildIfAlive() orphaned
  it for up to its full nap — the never-left-looping contract the
  assertion's own message names the finally keeping. The leg rides
  the file's try/finally idiom (the freeze-loop leg's frame):
  spawn inside try, the kill behind the $livePid > 0 guard.
  Construction-evident; verdicts and bounded waits unchanged.
- **The getLastErrors narratives' version anchor
  (t31-ocr62-5, documentation:low ×2; shared/src/Token/
  AccessTokenSet.php, tests/SharedOAuthContractsTokenSetAndClockTest.php)**
  — the round's finding claimed the comments' history INVERTED
  (pre-8.3 false, 8.3+ always-array); the probe it prescribes
  refutes the premise — this 8.5.10 runner answers bool(false) on
  a clean createFromFormat() parse, and the manual's changelog
  dates the rewrite at 8.2.0 in the direction the comments tell.
  Round 43's direction stands vindicated; the true residual (the
  8.3 anchor) is corrected in both sites with the probe and the
  changelog entry recorded as evidence. The guard stays
  shape-agnostic; no runtime branch. Doc-only, assertions
  byte-unchanged.

### Fixed (shared — M3 Task 3.1, OCR round 61)

Sixty-first OCR round (61/61 fully complete): 3 findings, driver
accepts all — THREE numbered commits t31-ocr61-1..3 (one per
finding), plus this docs record, the full offline check green
after every commit. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8→11→7→15→10→9→4→18→10→6→6→18→4→16→3→5→1→2→10→3.
Round 61 answered NEW findings, so the OCR phase continues per
plan. Suite 1741 → 1742 tests, 46960 → 47016 assertions, 3 skipped
unchanged (deltas +1/+4, +0/+14, +0/+38, measured from output).
The round's shape: the r57-4 raw/validated divergence class swept
to DeviceAuthorizationSession; the 'signature' suffix arm
(Stripe/GitHub HMAC headers); the swallowed-sentinel doctrine
applied file-wide.

- **The stored verification_uri kept the caller's raw edge bytes
  (t31-ocr61-1, security:medium; shared/src/Flow/
  DeviceAuthorizationSession.php)** — the constructor validated
  through Url::parse_validated() while storing the raw spelling,
  but the parse's §4.1 step-1 edge strip judges the edge-STRIPPED
  spelling: an accepted "https://device.example/verify\n"
  constructed with verification_uri() (the RAW channel handed to
  the authorization redirect) returning edge-control-bearing bytes
  no screen ever judged — the exact raw/validated divergence
  HttpRequest refused at t31-ocr57-4. The strip rides the one
  owner Url owns, before storing; the docblocks state the
  stored-form contract and the census names the field the one
  URI-bearing shape this VO carries. Driven: the edge-spelled URI
  constructs and verification_uri() answers the stripped spelling
  (red at HEAD: the raw edge bytes); clean spellings byte-exact.
- **The suffix class's missing 'signature' arm
  (t31-ocr61-2, security:medium; shared/src/Support/SecretMask.php)**
  — Stripe's 'Stripe-Signature', GitHub's 'X-Hub-Signature' and its
  SHA-256 variant, the generic 'X-Signature', and Google's
  'X-Goog-Signature' fold to a final token matching neither catalog
  nor suffix, so the credential-derived HMAC material rendered
  verbatim through every safe debug form. 'signature' joins (every
  delimiter spelling per the r50-1 fold), the '-256' variant rides
  its own two-token entry (its judged final segment is '256', a
  token no credential name spells), the HTTP-signatures 'Digest'
  twin considered-and-skipped in the census (an integrity digest
  of the body it rides with is secret-free, not credential-derived
  — the bar every member meets). Driven: the vendor spellings
  answer masked (red at HEAD: verbatim); the non-credential
  'x-signature-count' neighbors stay verbatim by the boundary.
- **The failure sentinels swallowed by their own catch
  (t31-ocr61-3, maintainability:low; tests/
  SharedOAuthArchitectureTest.php)** — the $this->fail() sentinel
  sat inside the try whose catch (AssertionFailedError) swallows
  it, the file's own glm29-16 doctrine skipped at the offender
  loop and its siblings: no false green today (every sentinel
  message verified non-contained in the asserted fragments), but a
  wrongly-passing gate degraded the sentinel's clear refusal into
  the catch's shape. The recorded-message idiom (catch records,
  assertNotNull carries the sentinel text outside) now owns all
  thirteen end-to-end legs — the reader pins, the namespace legs,
  static-multiline, planted-clock, .PHP casing, both vtab legs,
  wp-reach, provider-name, ref_array, class+trait. Construction-
  evident: a deliberately-broken gate (once, by hand) answers the
  sentinel's message, never a swallowed pass.

### Fixed (shared — M3 Task 3.1, OCR round 60)

Sixtieth OCR round (61/61 fully complete): 10 findings, driver
accepts all — SEVEN numbered commits t31-ocr60-1..7 (one per
finding; same-class = one commit; the 10 counts the gradings:
2+1+3+1+1+1+1), plus this docs record, the full offline check green
after every commit. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8→11→7→15→10→9→4→18→10→6→6→18→4→16→3→5→1→2→10.
Round 60 answered NEW findings, so the OCR phase continues per
plan. Suite 1734 → 1741 tests, 46931 → 46960 assertions, 3 skipped
unchanged (deltas +2/+2, +3/+3, +0/+6, +0/+2, +1/+5, +1/+10,
+0/+1, measured from output). The round's shape: the r59 high-byte
widening's follow-on surface — eight of ten findings are ONE class
(the \b boundaries both directions, the member-name shape guard,
the r49 anchor one byte short at all three seams, the member-leaf
boundary, the text lens) — plus the control-screen/edge-strip
order and the whitespace-tolerance pin.

- **The mention boundary's ASCII \b over high-byte short names
  (t31-ocr60-1, bug:medium ×2; bin/check-conventions.php)** — \b is
  PCRE's ASCII word boundary and a high byte is a non-word byte in
  byte mode, so after r59 a short name carrying one broke BOTH
  directions: 'new Grüß()' found no trailing boundary between the
  0x9F and '(' (legal code flagged 'unused import') while
  \bGrüß\b matched inside the lookalike 'Grüßx' (a dead import
  passing). Both mention seams ride label-class lookarounds now —
  the question \b asked, asked over the bytes a PHP label admits.
  Driven: the un-aliased used high-byte import stays green and its
  lookalike-only twin flags (red at HEAD: 1 and 0).
- **The member-NAME shape guard's ASCII \w (t31-ocr60-2,
  bug:medium; bin/check-conventions.php, bin/lib/plugin-tools.php)**
  — the group-use unroller's un-aliased arm refused an un-aliased
  high-byte member ('use Vendor\Pkg\{Grüß};') after the widened
  group opening had matched it, silently continue'd: invisible to
  the unused-import gate, the exact silent-false-negative class the
  round claims retired while the aliased twin flagged. The guard
  rides the one label byte class now, the census enumerating this
  member too. Driven: the member flags, its used twin does not
  (red at HEAD: 0 and 0 through the hole).
- **The r49 statement-start anchor, one byte class short at all
  three seams (t31-ocr60-3, bug:low + bug:medium ×2;
  bin/build.php)** — 'Grüßuse …' lexes as ONE T_STRING (no `use`
  token — invisible to every token-aware pass, exactly the anchor's
  premise) while the one-byte lookbehind read the preceding 0x9F as
  a boundary, so the pattern matched at the keyword glued inside
  the name and spliced the rewritten target beside leftover label
  bytes — the mid-name-splice shape the anchor was minted to kill.
  The one anchor derives its byte class from the LABEL_BYTES owner
  plus the separator at the declaration, plain-use, and group-use
  seams ($statement_start, one spelling). Driven: the glued
  spellings answer the refusal with the spelling riding verbatim —
  the plain and declaration legs naming the UNSPLICED family
  reference (red at HEAD: the message named the target-spliced
  artifact the rewrite had manufactured).
- **The member-leaf rewrite boundary's ASCII lookahead
  (t31-ocr60-4, bug:low; bin/build.php)** — a member segment
  'Sharedü' (a DIFFERENT segment) passed (?![A-Za-z0-9_]) at its
  high byte, the leaf matched mid-segment, and the rewrite spliced
  '<Suffix>\Shared' into it — a mid-name splice the postcondition
  refused one verdict late with a misleading survivor diagnostic.
  The boundary rides the label byte class now; the member rides
  verbatim and the refusal names the high-byte sibling precisely.
  Driven: 'Sharedü' answers the named refusal (red at HEAD: the
  anonymous survivor named the spliced '…OpenAiOauth\Sharedü');
  'SharedStorage' unchanged.
- **The text lens's ASCII leaf/stem boundaries (t31-ocr60-5,
  bug:low; bin/lib/plugin-tools.php)** — the family pattern's leaf
  arm matched '…\WpConnectors\Sharedü' MID-SEGMENT and reported the
  finding under the truncated own-namespace name while the name
  walk judges whole segments; the sibling pattern's EXCLUSION
  boundary swallowed the same spelling. All the text lens's
  boundaries derive from the LABEL_BYTES owner now (the exclusion
  failing at a label byte exactly as the leaf arm does), the r59
  census claim that the boundary lookarounds 'stay the word-byte
  census on purpose' rewritten to the round-60 truth. Driven: the
  docblock names the whole-segment sibling 'Deicod\WpConnectors\
  Sharedü' (red at HEAD: only the truncated finding), the
  SharedStorage text mention unchanged.
- **The control-screen/edge-strip order (t31-ocr60-6, bug:medium;
  shared/src/Http/Url.php)** — the entry control-byte screen ran
  BEFORE the §4.1 step-1 edge strip while it bans every C0 byte
  except tab at ANY position, so an edge \r, \x0B, \x0C, or NUL
  refused before the strip could remove it: the strip degraded to
  trim(" \t"), contradicting the adjacent docblock's own promises.
  Verified against the Standard first (§4.1 step 1 strips
  C0-control-or-space at the edges — all of it), the strip runs
  before the screen: edge C0 strips, interior still refuses.
  Driven: the edge "\r https://… \x0B" spelling parses to the
  stripped URL (red at HEAD: refused); interior control bytes
  still refuse.
- **The whitespace-tolerance pin's one-space half measure
  (t31-ocr60-7, test:low; tests/FoundationHarnessTest.php)** — the
  collapse kept ONE space per run, so the zero-space needle failed
  against 'defined( 'ZipArchive::RDONLY' )', this repo's own
  prevailing style: a mechanical formatting pass would redden the
  pin over a behaviorally-neutral reformat, the exact brittleness
  t31-ocr29-10 set out to retire. The pin rides the \s*-class
  pattern the repo's layout-tolerant pins already own against the
  raw source. Construction-evident: the respaced spelling matches
  the same pattern the real source does (red at HEAD).

### Fixed (shared — M3 Task 3.1, OCR round 59)

Fifty-ninth OCR round (61/61 fully complete; main run partial 55/61,
the fill-in r64b over the 7 compression-lost bin/ files CLEAN — 0
findings): 2 findings. Driver accepts both. 2 numbered commits
t31-ocr59-1..2, plus this docs record, the full offline check green
after every commit. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8→11→7→15→10→9→4→18→10→6→6→18→4→16→3→5→1→2.
Round 59 answered NEW findings, so the OCR phase continues per
plan. Suite 1731 → 1734 tests, 46916 → 46931 assertions, 3 skipped
unchanged (deltas +1/+7 and +2/+8, measured from output).

- **The staging-time sidecar prune outside the by-construction window
  (t31-ocr59-1, bug:low; bin/build.php)** — manifestLinesWithout()
  unlinked stale entries' .sha256 sidecars at STAGING time, but
  stageManifest() runs BEFORE the pre-flight loop and the
  landArtifact() renames, so a run that refused at pre-flight (or
  died at a landing rename) had already deleted sidecars while the
  on-disk manifest still listed those entries — a destructive,
  un-rolled-back write outside the 'every failure before the first
  rename leaves the prior artifact set byte-untouched BY
  CONSTRUCTION' window the publication seam's own docblock promises
  (the deleted files already-orphaned descriptors; the damage to the
  invariant, not to live artifacts). The staging merge records the
  fenced sidecar set now and the caller replays the unlink only
  after the LAST landing rename — inside the same merge lock, the
  ocr35-2 guards re-judged at the moment of the unlink. Driven: a
  pre-flight-refusing run leaves the stale sidecar on disk and the
  manifest byte-identical (red at HEAD: sidecar gone); a succeeding
  run still reclaims it.
- **The ASCII-only label stragglers against the high-byte member
  grammar (t31-ocr59-2, bug:low; bin/build.php, bin/lib/
  plugin-tools.php, bin/check-conventions.php)** — the sub-segment
  tail and alias classes (and the namespace-declaration tail's twin,
  the alias-id extraction, and the member alias shape) were
  ASCII-only while groupUseMemberGrammar() validated member names
  with the full PHP label byte set — and the engine accepts the high
  bytes in every position (php -l-verified first), so a legal 'use
  …\Shared\Grüß as Grün;' failed the pattern, rode verbatim, and
  refused at the postcondition with the anonymous-survivor message
  while the identical member spelling validated through the group-use
  seam; the unused-import scanner's \w classes left the same spelling
  invisible to that gate. ONE label byte class at every grammar seam
  now — WP_CONNECTORS_LABEL_HEAD_BYTES / WP_CONNECTORS_LABEL_BYTES
  in bin/lib/plugin-tools.php, the census comment there listing every
  aligned seam (and the boundary lookarounds staying the r46/r49
  word-byte census on purpose). Driven: the high-byte spellings
  construct and re-emit through the rewrite (red at HEAD: refused at
  the ASCII-only seams), the reserved vocabulary keeps refusing, a
  legal 'as Grüself' is judged by its full bytes, and the scanner's
  unused high-byte import flags (red at HEAD: invisible).

### Fixed (shared — M3 Task 3.1, OCR round 58)

Fifty-eighth OCR round (61/61 fully complete): 1 finding — TIES THE
ALL-TIME LOW (round 37 also had 1). Driver accepts it. 1 numbered
commit t31-ocr58-1, plus this docs record, the full offline check
green after it. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8→11→7→15→10→9→4→18→10→6→6→18→4→16→3→5→1.
Round 58 answered a NEW finding, so the OCR phase continues per
plan. Suite unchanged at 1731 tests, 46916 assertions, 3 skipped
(delta +0/+0 over the round).

- **The scratch-root maker's untyped signature (t31-ocr58-1,
  maintainability:low; tests/SecureFixturesTest.php)** — the r51-3
  scratch-root maker carried no native type declarations while every
  sibling maker in these files does (makeScratchRepo(string
  $state_id): array the named example) and its own docblock already
  declared @param string / @return string — the contract lived in
  prose the signature did not enforce. The maker spells it natively
  now — scanScratchRoot(string $stem): string — docblock and
  signature agreeing; a mismatch now fails at the signature instead
  of passing silently. Regression: suite green (callers pass literal
  strings), counts unchanged.

### Fixed (shared — M3 Task 3.1, OCR round 57)

Fifty-seventh OCR round (61/61 fully complete): 5 findings. Driver
accepts all. 5 numbered commits t31-ocr57-1..5 (one per finding),
plus this docs record, the full offline check green after every
commit. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8→11→7→15→10→9→4→18→10→6→6→18→4→16→3→5.
Round 57 answered NEW findings, so the OCR phase continues per
plan. Suite 1730 → 1731 tests, 46880 → 46916 assertions, 3 skipped
unchanged.

- **The dot-segment gap (t31-ocr57-1, bug:medium;
  shared/src/Http/Url.php)** — the WHATWG-differential screen family
  had no path-state dot-segment member: '/a/./b' and '/a/../b'
  stored verbatim while every WHATWG consumer resolves them ('..'
  clamping at the root, a trailing dot segment appending '/'), the
  r46-5 census claim falsified by its own uncovered member. The
  Standard's exact algorithm verified at the source first (both %2e
  spellings included; the opaque-path carve-out unreachable here —
  every admitted scheme is special). Dot segments refuse with the
  family's own vocabulary, never resolve silently. Driven: nine
  spellings through both VOs, the dots-inside-segments and no-path
  controls green.
- **The flattened 'csrftoken' credential twin (t31-ocr57-2,
  security:medium; shared/src/Support/SecretMask.php)** —
  'X-CSRFToken', Django's canonical CSRF header spelling, folded to
  a judged name matching no screen while the hyphenated twin masked
  via 'token' — the exact ocr50-1 covered-hyphenated/
  verbatim-flattened inconsistency. One member speaks every
  delimiter spelling; the sweep found no second vendor-canonical
  member ('antiforgerytoken' considered-and-skipped as spelled, the
  census names it). Driven: six spellings masked (red at HEAD),
  both render channels, the neighbors verbatim.
- **The symlink diagnostic's own uncaught fatal (t31-ocr57-3,
  bug:low; bin/lint-php.php)** — the no-symlinks refusal row read
  its target through a bare getLinkTarget(), whose RuntimeException
  (link gone between yield and readlink, NFS ESTALE) escaped the
  walk's UnexpectedValueException-only fence as an uncaught fatal —
  the exact class the r30-3 fence converts into a counted refusal —
  and whose false return degraded the target to ''. The read owns
  its shapes: both degrade to the counted refusal naming the
  unreadable target. Construction-evident (the race window owned
  end-to-end by the spawned child — the census states it); the
  readable-link battery stays the green control.
- **The stored-vs-validated spelling divergence (t31-ocr57-4,
  maintainability:low; shared/src/Http/HttpRequest.php,
  shared/src/Http/Url.php)** — HttpRequest stored the caller's
  bytes while parse_validated() judges the r56-2 edge-stripped
  spelling, so an accepted edge-spaced URL carried url() bytes no
  screen judged with redacted_url() derived from a different
  spelling — the raw/derived divergence the agreement doctrine
  refuses. The stored spelling is the validated spelling: the §4.1
  step-1 strip rides its ONE owner (Url::
  strip_edge_control_or_space()) and HttpRequest normalizes before
  storing, the docblock stating the stored-form contract. Driven:
  url() returns the stripped spelling (red at HEAD), agreement
  holds, clean URLs store byte-exact.
- **The bootstrap guard's modeled-but-fatal shape (t31-ocr57-5,
  maintainability:low; tests/bootstrap.php)** — the Shared
  autoloader's is_dir() guard models a checkout without shared/src,
  but the two harness fixtures implementing that namespace's
  interfaces (DeterministicClock, InMemoryTokenStorage) loaded
  unconditionally — in exactly the modeled scenario the require
  fatals ('Interface not found') instead of degrading. The two
  requires ride the guard (the absent-checkout shape stays
  bootable, PHPUnit reporting the missing fixtures per-test); the
  rest stay unconditional by census. Driven out-of-band: the
  modeled scratch checkout fatals at the pre-fix line 79 and boots
  clean after.

### Fixed (shared — M3 Task 3.1, OCR round 56)

Fifty-sixth OCR round (61/61 fully complete): 3 findings — the NEW
LOOP MINIMUM (previous: 4, twice). Driver accepts all. 3 numbered
commits t31-ocr56-1..3 (one per finding), plus this docs record, the
full offline check green after every commit. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8→11→7→15→10→9→4→18→10→6→6→18→4→16→3.
Round 56 answered NEW findings, so the OCR phase continues per
plan. Suite 1729 → 1730 tests, 46868 → 46880 assertions, 3 skipped
unchanged.

- **The third strcasecmp→ASCII-owner straggler (t31-ocr56-1,
  maintainability:medium; bin/build.php)** — the degenerate-
  composition refusal compared the suffix against the family leaf
  with strcasecmp() — a locale-consulting fold feeding a
  build-refusing verdict, the exact class the same file's own
  LICENSE/embed-collision doctrine prescribes against. The
  comparison folds both sides through wp_connectors_ascii_lower
  (the r11-6/ocr10-4/t31-ocr13-1 rule); the census comment records
  the sweep — no other strcasecmp() call remains in the file. The
  locale-divergence leg is not cheaply constructible on this host
  (no tr_* locale generated — the t31-r2-14 fold-table hardening
  precedent); the existing degenerate pin stays green.
- **The strip-set's missing §4.1 step 1 (t31-ocr56-2, bug:low;
  shared/src/Http/Url.php)** — the r44-1/45-2 WHATWG screen
  implements Standard step 2 (tab/newline refusal) but not step 1:
  leading/trailing C0-control-or-space strips from the WHOLE input
  before it — 'https://device.example/verify ' is '/verify' in
  every browser while this parse kept the space verbatim, the tab
  byte's own divergence class. The edge strips where the interior
  refuses (an edge byte names nothing; the interior space stays
  legal, interior C0/newline still refuse at the entry screen); an
  edge tab strips at step 1 and never reaches the refusal. The
  strip set derives the class exactly (U+0000–U+001F + U+0020,
  trim()'s enumerated charlist — byte-wise, locale-free, no PCRE
  abort to guard); direction verified first (the parser did not
  strip today). Driven: five edge spellings answer the browser's
  parse (path '/verify', redacted form derived from the stripped
  parse; red at HEAD), the interior boundary legs beside them.
- **The productionSource census member that never rode (t31-ocr56-3,
  test:low; tests/SharedOAuthContractsHttpTest.php)** — the lone
  structural-pin read still using the silent `(string)
  file_get_contents()` cast — the exact shape productionSource()
  (t31-ocr45-8) exists to replace, whose docblock claims one census
  over all four sites. The read rides the owner now (an unreadable
  source answers a named environment verdict, never a late
  needle-mismatch over the empty string) and the census claim comes
  true at five sites.

### Fixed (shared — M3 Task 3.1, OCR round 55)

Fifty-fifth OCR round (61/61 fully complete): 16 findings, driver
accepts all. The r54 "closed file-wide" census claims were over-broad
— this round proves ~14 more staging-leak sites the named sweeps
walked past, plus a NEW environment-topology class (zombie reaping).
9 numbered commits t31-ocr55-1..9 (one per finding; same-class = one
commit), plus this docs record, the full offline check green after
every commit. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8→11→7→15→10→9→4→18→10→6→6→18→4→16.
Round 55 answered NEW findings, so the OCR phase continues per
plan. Suite 1728 → 1729 tests, 46820 → 46868 assertions, 3 skipped
unchanged.

- **'authentication' joins the suffix class (t31-ocr55-1,
  security:medium)** — the 'authorization' tier's own sibling: the
  final token of 'X-Authentication'/'Proxy-Authentication'/
  'Client-Authentication' matched neither catalog (only the '-info'
  exchange spellings) nor suffix screens, so the credential rendered
  verbatim through every safe debug form. One member speaks every
  delimiter per the r50-1 fold; driven — seven spellings answer
  masked (red at HEAD: verbatim), the boundary twin stays verbatim,
  and the census names the neighbor scan (no non-credential
  '-authentication' header is known to exist).
- **The representative fence reads realpath()'s false as false
  (t31-ocr55-2, bug:low)** — a failed realpath of the temp root cast
  to '' made the fence prefix read as '/', every absolute base
  passed, and the probe planted OUTSIDE scratch (the r40-5 residue
  shape opened through the fence's own vocabulary) with the measured
  answer riding into the per-volume cache. Driven through the
  redirected-TMPDIR child over the ocr39-7 cache-unset seam:
  answer:false with cache:unmeasured — verified RED AT HEAD
  stash-verified ('cache:measured', the escaped plant's own answer).
- **The printable map rides a static (t31-ocr55-3, performance:low)**
  — wp_connectors_printable() rebuilt its 43-entry substitution map
  per invocation, per entry in the archive walk and per violation
  line; the file's own hoisting idiom (t31-ocr52-6) owns it now,
  byte-identical by construction.
- **The zombie-reaping topology joins the skip family (t31-ocr55-4,
  test:medium ×2, ONE commit)** — both reap pins hard-fail on
  runners whose PID 1 does not reap adopted orphans (container
  entrypoint, pod without a reaping init): a zombie answers ALIVE
  under both liveness spellings, so childIsAlive() never flips and
  the pins fail through topology. A per-process canary probe (spawn,
  bounded-wait its reaping) gates both legs, the skip loud and
  construction-evident — on this reaping host the canary reaps in
  milliseconds and both pins stay green and RUNNING.
- **The states() table's last silent-return plants assert their
  landing (t31-ocr55-5, test:medium)** — the chmod pair, the
  whitespace/upper-.PHP/near-source/build.json writes, both
  collision pairs, all three landing-blocker pairs, the empty-tree
  plants, the manifest lock, and the traversal write: every plant
  asserts through the table's own asserted-staging vocabulary, the
  assertion riding the apply-throw channel as the ROW's FAIL (never
  'the silent third', never a vacuous pass). ONE census comment
  closes the table.
- **The forced-archive legs' staged plants assert their landing
  (t31-ocr55-6, test:medium ×2, ONE commit)** — the $staged writes,
  the mid-leg unlink (the vanished-source premise itself), and the
  (b) chmod lock: a chmod false left the source readable and the leg
  reds at the assertNotNull as a PHANTOM finalization defect. The
  sibling locked legs' own shape ('staging: the lock must take').
- **The staging-before-try sweep, for real this time (t31-ocr55-7,
  test:low ×5 comments, ONE commit)** — the r54 "closed file-wide"
  census claims were over-broad; this sweep LISTS what it closes:
  SecureFixtures' known-secret battery (which rode in NO try at all),
  the ancestor sub-test's plants, the sweep battery's $stale/$foreign
  plants; HarnessCopyTree's trailing-slash, precondition,
  path-case, root-anchored, symlink-shapes, two-link-cycle,
  recursion-fence (its skip's release rides the owning finally, its
  finally-chmod gains the @ that owns the half-built exits), both
  redirected-TMPDIR sims, relative-target, and release-guard sites;
  FoundationHarness' corrupt-archive write. The finally owns every
  exit from the first mkdir on; the census claims ONLY the sites it
  lists.
- **classifyClean()'s last two launderers (t31-ocr55-8, test:low ×2,
  ONE commit)** — the extraction mkdir's failure surfaced as 'the
  independent extraction returned failure' (a soundness
  misattribution) and hash_file()'s false as 'the sidecar does not
  describe the shipped zip' (the laundering class readMemberOrMarker
  exists to close): both answer the row's own FAIL naming their
  channel.
- **The dead $spawnExit binding drops at all five spawn sites
  (t31-ocr55-9, maintainability:low)** — bound by reference, never
  read; the pid assert downstream already owns the staging verdict
  (t31-ocr27-9). Chosen shape: DROP (exec()'s &$result_code is
  optional), applied at every site, one census comment.

### Fixed (shared — M3 Task 3.1, OCR round 54)

Fifty-fourth OCR round (61/61 fully complete): 4 findings, driver
accepts all — all test:low, all completion twins of the two classes
round 53's sweeps started (the sweeps reached the batteries they
named and missed the siblings); ties the loop minimum (4). 2
numbered commits t31-ocr54-1..2 (one per class), plus this docs
record, the full offline check green after every commit. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8→11→7→15→10→9→4→18→10→6→6→18→4.
Round 54 answered NEW findings, so the OCR phase continues per
plan. Suite 1728 tests unchanged, 46817 → 46820 assertions, 3
skipped unchanged.

- **The unasserted-staging sweep completion (t31-ocr54-1,
  test:low ×2)** — the two sites round 53's staging-assert sweeps
  walked past: the known-secret battery kept its bare staging
  writes while r53-6 asserted every other scan site in its file (a
  failed mkdir/write surfaced as a missing 'zai-key'/'github-token'
  verdict — scanner-shaped red over a staging failure, the
  misattribution class; the r53-6 census had adjudicated this site
  outside its sweep as never-vacuous, and the completion brings it
  under the idiom as the misattribution fix), and
  'zip-staging-path-blocked' is the only CLEAN row planting
  filesystem state, its mkdir unasserted while its two sibling rows
  got asserts at r53-7 — the row's whole point is that the pid-only
  blocker spelling is INERT, so a silently failed mkdir yielded an
  identical green verdict over a plant that never landed (a vacuous
  pass, coverage gone). Both assert their own landing, each
  assertion naming its path; the zip row's closure drops 'static'
  so $this binds (the r53-7 shape), an assertion failure riding the
  table's apply-throw channel (t31-ocr30-6) as the row's own FAIL,
  never a phantom CLEAN. Construction-evident regressions; happy
  paths unchanged, every verdict byte-identical when the plants
  land.
- **The staging-inside-try sweep completion (t31-ocr54-2,
  test:low ×2)** — the four sites the sibling fixes left staging
  before the try that releases it: the three scan batteries r53-6
  asserted (prune-fold, artifact-scan, fresh-process) staged every
  fixture BEFORE the try/finally owning releaseScratch() (a failed
  staging assert threw with the tree half-planted and no finally in
  scope — each stem is used once per run, no later battery's pid
  sweep reclaims it), and $outside's staging in the
  self-containment boundary battery sat before its try (the
  half-built 'wpct-scanroot-outside-*' tree, a uniqid stem no pid
  sweep vocabulary ever names, leaked in system temp when the
  r52-5 write assert failed) — the exact t31-ocr16-14/t31-ocr18-3
  class the changeset's siblings already closed. All four stage
  inside the try now (the ocr30-4/ocr53-10 mid-landing shape): the
  finally owns every exit from the first mkdir on, the staging
  failure still failing as staging through the assert's own named
  verdict while the release reclaims whatever landed.
  Construction-evident; happy paths byte-identical (same stage
  calls, same verdicts, same assert count).

### Fixed (shared — M3 Task 3.1, OCR round 53)

Fifty-third OCR-tool round (main run partial 53/61 + fill-in r58b over
the 8 compression-lost files): 18 findings, driver accepts all; 10
numbered commits t31-ocr53-1..10, one per finding (same-class = one
commit). Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8→11→7→15→10→9→4→18→10→6→6→18.
The round's shape: the third driven refutation — the first against
our own fix (r52-1's 0o/0b arms were ECMAScript spellings, not
URL-Standard prefixes: over-refusal reverted to the Standard's
two-prefix shape); the two PCRE fail-open screens; the freeze-loop
stale-liveness kill; the dangling-symlink arm; saveCount's screen;
the staging sweeps through BuildSeam/UnusedImport/SecureFixtures;
the laundering reads; the pre-try creation leak. Round 53 answered
NEW findings, so the OCR phase continues per plan. Fixed as
t31-ocr53-1..10 — one commit per finding, plus this docs record, the
full offline check green after every commit. Suite 1725 → 1728
tests, 46771 → 46817 assertions, 3 skipped unchanged.

- **The r52-1 radix completion REFUTED by the Standard's own text
  (t31-ocr53-1, bug:medium + test:medium)** — the URL Standard's
  IPv4 number parser recognizes exactly two prefix spellings ('0x'
  radix 16, the legacy single leading '0' radix 8); '0o'/'0b' are
  ECMAScript numeric-literal spellings, so '0b1'/'0o7' last labels
  stay opaque domains for every WHATWG consumer and the r52-1
  refusal was over-refusal of a legal domain — the ledger's third
  driven refutation, the first against our own round's fix. Driven:
  the r52-1 legs flip to construction legs (red at the r52 shape),
  a fresh hex-arm refusal leg proves no overcorrection, the
  Standard-true spellings stay driven by the r49 hostile loop.
- **Both WHATWG-differential PCRE screens read abort-as-reject
  (t31-ocr53-2, bug:low ×2)** — the non-ASCII byte-class probe and
  the ends-in-a-number predicate were the file's two remaining
  `1 ===` ban forms: a PCRE abort made `1 === false` false, so a
  non-ASCII host constructed verbatim and an IPv4-ambiguous spelling
  constructed as an opaque domain — fail-open on exactly the
  differential screens. The byte-class probe re-spells `0 !==`; the
  predicate answers the refusing arm on an abort. Driven for the
  predicate by the pinned-backtrack-limit idiom (red at HEAD,
  stash-verified); the class-scan twin construction-evident (no
  backtracking to exhaust, stated in the census comment).
- **The freeze loop signals only a child it still owns
  (t31-ocr53-3, bug:medium)** — the loop signalled $childPid on the
  heartbeat's stale liveness evidence (proof the child reached its
  loop ONCE, each later kill riding ~30ms-old evidence), so a child
  that died mid-loop (the reflected invoke runs uncaught) was
  still STOPped/killed/CONTinued and, once the reaped pid recycled,
  an unrelated process was signalled — the recycled-pid class the
  file's own killChildIfAlive closed, applied inconsistently on the
  loop side. Every loop signal rides the current probe now, a dead
  child answering the named death verdict. Driven both directions:
  a died-mid-loop child never signalled (red at HEAD by
  construction); a live looping child freezes and thaws, observed
  over its own marker log (every filesize behind clearstatcache).
- **The census walk's dangling-symlink arm refuses loudly
  (t31-ocr53-4, test:low)** — a dead symlink satisfies neither
  is_dir() nor is_file() (both stat through the link), fell through
  both branches, and was skipped silently, contradicting the walk's
  own 'a link is never silently skipped' doctrine — the fourth arm
  beside file/dir/loop. Driven: a planted dead link answers the
  named refusal (red at HEAD, arm-disabled-verified: silent skip).
- **saveCount() rides the one screen owner (t31-ocr53-5,
  maintainability:low)** — the fake's observable took the same
  caller-controlled storage key as load()/save()/delete() while
  skipping screen_key(): a control-bearing key silently answered 0.
  The guard runs at the fourth call site. Driven: the forged-key
  loop gains the saveCount() arm (red at HEAD: 0); legal keys
  unchanged.
- **The SecureFixtures staging sweep (t31-ocr53-6, test:low ×4)** —
  four batteries planted fixtures on bare mkdir()/writes, each with
  its own misattribution shape: empty-report prune/artifact verdicts
  (a missing tree trivially contains no 'VENDOR' fragment), the
  ancestor leg's phantom, the fresh-process leg's misleading
  child-exit-code failure — the sweep test's own asserted-staging
  rule missed by the batteries that predate it. Every staging write
  asserts its landing, naming its path. Construction-evident.
- **The adversarial table's two symlink plants assert their landing
  (t31-ocr53-7, test:medium ×2)** — a silently failed plant made the
  CLEAN row's 'extra' control trivially true (vacuous pass, coverage
  gone) and the LOUD row fail as 'the silent third' — a phantom
  build defect over a staging failure; canSymlink() proved the
  capability, not the call. Both plants assert (the closures drop
  'static' so $this binds), an assertion failure riding the table's
  own apply-throw channel as the row's FAIL. Construction-evident.
- **The CLEAN leg's consistency reads ride the marker owner
  (t31-ocr53-8, test:low ×2)** — the sidecar/manifest reads spelled
  bare (string) casts, laundering a failed read into '' and failing
  the row under the wrong owner ('does not describe the shipped
  zip'), the exact ocr50-8 class readMemberOrMarker() fixed one
  screen up. Both reads ride the marker; absent/unreadable answer
  the row's own staging FAIL. Construction-evident.
- **The scanner suite's two bare staging sites ride the asserted
  idiom (t31-ocr53-9, test:low ×2)** — the teardown-release leg
  went vacuously green over a tree that never landed (tearDown
  releasing an already-clean root), and the unopenable-root leg
  green over opendir() false on a NONEXISTENT root (counted=0
  through the finally) — both legs' subjects defeated. Both sites
  ride the mid-walk leg's own spelled-out doctrine. Construction-
  evident.
- **The nested same-name leg's creation rides inside the release's
  try (t31-ocr53-10, test:low)** — the scratch trees were
  created/staged BEFORE the try that owns them in the finally, so a
  failed stage threw with the trees planted and releaseScratch()
  never ran: both leaked into the shared temp root, the exact class
  the file's own t31-ocr15-8 doctrine fixed ('every exit path from
  creation on removes them'). The finally owns every exit from
  creation on now. Construction-evident; the file's other
  staging-before-try batteries noted for a later round's census.

### Fixed (shared — M3 Task 3.1, OCR round 52)

Fifty-second OCR-tool round (main 61/61, fully complete): 6 findings,
driver accepts all; 6 numbered commits t31-ocr52-1..6, one per
finding. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8→11→7→15→10→9→4→18→10→6→6.
The round's shape: the radix-arm completion the r49-4 family owed
(0o/0b join 0x); the generic-key consistency gap in the masking
boundary; diamond-vs-loop in the cycle guard; the adjacent-pid
foreign-tree plant; the vacuous-pass staging channel; the traversal
fold's invariant hoist. Round 52 answered NEW findings, so the OCR
phase continues per plan. Fixed as t31-ocr52-1..6 — one commit per
finding, plus this docs record, the full offline check green after
every commit. Suite 1724 → 1725 tests, 46732 → 46771 assertions,
3 skipped unchanged.

- **The IPv4 number predicate spells the Standard's whole radix
  table (t31-ocr52-1, security:high)** — the r49-4 predicate spelled
  the 0x/0X hex arm alone while the URL Standard's IPv4 number
  parser it cites accepts three prefix spellings (0x radix 16, 0o
  radix 8, 0b radix 2, one empty-after-prefix rule), so a last label
  '0b1'/'0o7' named IPv4 for every WHATWG consumer while this parse
  kept the host an opaque domain — the r49-4 differential one radix
  over. Driven: the bare and last-label spellings refuse through
  both consumers (red at HEAD: constructed); non-radix digits keep
  the domain reading.
- **The generic 'key' token joins the masking suffix class
  (t31-ocr52-2, security:medium)** — the class carried
  'api-key'/'subscription-key' while missing the generic token both
  end in, so 'X-Secret-Key' and 'X-Access-Key' rendered verbatim
  while 'X-Client-Secret' masked. 'key' joins the generic tier
  'token'/'secret'/'auth' occupy (the r24 over-masking-errs-safe
  doctrine), subsuming the hyphenated compounds per the ocr33-3
  doctrine while their flattened twins stay beside the round's own
  'secretkey'/'accesskey'; the any-credential-token shape rejected
  in the census (it leaves 'X-Access-Key' verbatim). Driven: the
  family masks in name and render channels (red at HEAD); the
  spanning-bytes neighbors stay outside.
- **The Exception census's cycle guard distinguishes a LOOP from a
  DIAMOND (t31-ocr52-3, bug:low)** — the r51 guard refused ANY
  re-visited realpath, but two sibling links at one real directory
  re-enter an already-walked tree through a non-cyclic path: a
  terminating shape the guard reported as 'Symlink loop'. A re-visit
  is a loop only when the revisited realpath is an ANCESTOR of the
  current position (the ancestors stack); a non-ancestor re-visit is
  a diamond — the duplicate entry skips, the walk continues, each
  real file counted once. Driven: a planted diamond completes the
  census (red at HEAD: the refusal); the planted true loop still
  refuses loudly.
- **The foreign-tree plant rides a pid above the kernel's ceiling
  (t31-ocr52-4, test:low)** — the r51-3 sweep battery planted its
  foreign tree under getmypid() + 1, an ADJACENT pid — exactly two
  runners one orchestrator spawns — so the plant could sit on a real
  runner's live scratch vocabulary. The pid is one above
  PID_MAX_LIMIT (2^22 on every 64-bit Linux build, claimable by no
  live process); construction-evident, the refusal pins unchanged.
- **The scan-root boundary battery's staging writes own their
  returns (t31-ocr52-5, test:low)** — the boundary refuses a MISSING
  scan root with the same RuntimeException class and vocabulary as
  an outside root, so an unchecked staging write failure surfaced as
  the expected refusal: a reachable vacuous pass. Per-site success
  asserts (the ocr27-9 doctrine) fail the leg as staging, naming its
  path; construction-evident, the happy path unchanged.
- **The edge-junk byte class and traversal-fold char set hoisted out
  of the per-segment rebuild paths (t31-ocr52-6, performance:low)**
  — wp_connectors_path_edge_junk() rebuilt its constant class per
  call while its consumers judge per segment, and the traversal fold
  re-derived its char set per entry and per segment. The owner
  computes the class once per process (one cache point, every caller
  routed through it); the fold derives its array once per archive.
  Byte-identical by construction; pure perf, the existing fences the
  oracle.

### Fixed (shared — M3 Task 3.1, OCR round 51)

Fifty-first OCR-tool round (main 61/61, fully complete): 6 comments =
5 findings (two are twins on the same line), driver accepts all; 3
numbered commits t31-ocr51-1..3 (the line-91 twins one commit over
both gradings; the three r42-6 sites one commit per the same-class
doctrine). Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8→11→7→15→10→9→4→18→10→6.
The round's shape: the symlink-cycle guard the r49-11 fix owed its
own docblock; the ungated separator-vocabulary battery; the r42-6
reclaim doctrine swept to the random-suffixed sites
SecureFixturesTest grew since. Round 51 answered NEW findings, so the
OCR phase continues per plan. Fixed as t31-ocr51-1..3 — one commit
per finding class, plus this docs record, the full offline check
green after every commit. Suite 1722 → 1724 tests, 46717 → 46732
assertions, 3 skipped unchanged.

- **The Exception census's follow owns its cycle shape
  (t31-ocr51-1, bug:medium)** — the r49-11 FOLLOW_SYMLINKS fix kept
  no visited set, so a directory symlink closing a loop recursed with
  the pathname growing until the engine's own limits answered: a
  path-length or memory fatal on the finding's host shape, a silently
  truncated walk returning duplicated phantom classes on this one
  (demonstrated at HEAD: 82 entries over a two-file tree). The walk
  carries a visited-realpath set at every directory it enters — a
  re-visited realpath is a loop, refused loudly naming both spellings
  — and its environment arms refuse naming their path, never silently
  shrinking the census. Driven: a planted loop link answers the named
  refusal and the suite survives; ordinary trees byte-unchanged.
- **The symlink-refusal battery rides the platform gate its
  separator vocabulary owed (t31-ocr51-2, test:medium)** — the
  round-50 battery asserts '/'-joined fragments in refusal messages
  built from raw host-joined pathnames, ungated where its two
  siblings gate the same divergence (t31-ocr29-7): on a Win32 host it
  would fail as an environment defect, never the walk's own judgment.
  The isPosixHost() skip rides after the capability gates, one census
  comment naming the third '/'-fragment consumer. Construction-
  evident; POSIX hosts unchanged.
- **The r42-6 reclaim doctrine swept to the random-suffixed scan
  sites (t31-ocr51-3, bug:medium ×2 + maintainability:low, one
  class)** — four SecureFixturesTest sites derived pid-prefixed
  random-suffixed scratch roots, then reclaimed a pre-existing tree
  at the freshly derived name: per the t31-ocr42-6 census such a name
  is a foreign tree by construction (the recycled-pid collision
  shape), and the arm's only reachable effect was deleting another
  run's live scratch (demonstrated at HEAD over a planted occupant).
  One maker owns the derivation now — a pid-scoped stale sweep
  reclaiming this process's own debris, the suffix rolling until the
  name is free, the foreign tree untouched. Driven: a planted stale
  tree is swept, a planted foreign tree stands, the site proceeds
  under a fresh suffix.

### Fixed (shared — M3 Task 3.1, OCR round 50)

Fiftieth OCR-tool round (main 61/61, fully complete): 10 findings,
driver accepts all; 9 numbered commits t31-ocr50-1..9 (the unchecked
read pairs one commit over both their sites). Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8→11→7→15→10→9→4→18→10.
The round's shape: the hyphen-tail credential twin (X-ApiKey — the
r49-5 fix judged exact catalog matches only); the landing pre-flight
symlink follow; the recycled-pid kill; the IDN differential; the bidi
neutralizer; the digits-only fast arm; the lint coverage regression.
Round 50 answered NEW findings, so the OCR phase continues per plan.
Fixed as t31-ocr50-1..9 — one commit per finding, plus this docs
record, the full offline check green after every commit. Suite
1718 → 1722 tests, 46668 → 46717 assertions, 3 skipped unchanged.

- **The flattened credential family judges the hyphen-tail
  (t31-ocr50-1, security:medium)** — the r49-5 flattened spellings
  rode the catalog, consulted by exact match only, so a name whose
  final hyphen-token is one of them escaped both screens: 'X-ApiKey'
  folded to judged 'x-apikey' and rendered its secret verbatim. The
  seven flattened spellings ride the suffix class now — one predicate
  speaking every delimiter's segment tail plus the bare token — and
  the catalog entries are the dead weight the ocr33-3 subsumption
  doctrine refuses. Driven: 'X-ApiKey', 'x.accesstoken' answer masked
  (red at HEAD: verbatim); 'X-Request-Id' and the spanning bytes
  ('x-apikeychain') unchanged.
- **The landing pre-flight owns the link shape (t31-ocr50-2,
  security:medium)** — file_exists()/is_file() FOLLOW symlinks, so a
  link at a landing target pointing at a regular file passed the
  non-file gate and the publication rename silently replaced the link
  entry, this the one sibling seam that followed links instead of
  refusing them. is_link() judges the entry itself: a link at any
  landing target answers the loud refusal naming the path and its
  target, the prior set whole. Driven: a planted symlink target
  answers the refusal (red at HEAD: the build exited 0, the link
  silently replaced); regular landing targets unchanged.
- **The crash-sim finally's kill rides the child's own liveness
  evidence (t31-ocr50-3, bug:medium)** — the kill -9 fired on every
  exit path behind `$childPid > 0` alone, and the child that fataled
  at setup is reaped by init within milliseconds: the recycled pid
  took the signal meant for it. The kill rides the r47-8 signal-0
  probe's parent-side spelling now (EPERM = exists, ESRCH = gone): a
  dead-or-reaped child is never signalled, a live child still dies.
  Driven with real children in both directions (red at HEAD: the
  signal issued unconditionally over the dead child's positive pid).
- **The non-ASCII host answers the WHATWG-differential refusal
  (t31-ocr50-4, bug:low)** — a browser resolves 'https://bücher.example/'
  at 'xn--bcher-kva.example' (domain-to-ASCII, URL Standard §6.4)
  while this parse and every redacted form kept the raw UTF-8 bytes:
  two hosts named by one URL over the browser-facing channel. The
  host region refuses — never punycode-converts; write the xn--
  spelling, where both readings agree. The r12-8 locale-pressure
  pin's multibyte-host half is superseded (the fold invariant riding
  its ASCII legs and the guard probe; the multibyte URL now pinning
  the refusal byte-identical under pressure). Driven: three IDN
  spellings refuse through both owners (red at HEAD: constructed);
  the punycode spelling and pure-ASCII hosts unchanged.
- **The printable seam owns the bidi/format class (t31-ocr50-5,
  security:low)** — the neutralizer answered C0 + DEL only, so the
  Unicode bidi controls rode verbatim in inspector diagnostics
  interpolating archive-controlled entry names: a crafted name
  carrying U+202E could visually reorder its own diagnostic line.
  U+202A-202E, U+200E/U+200F, and U+2066-2069 join the substitution
  vocabulary as explicit byte sequences — an ASCII-only diagnostic
  stays byte-identical. Driven at the seam and end to end through a
  U+202E-bearing entry name (red at HEAD: the controls verbatim).
- **The ends-in-a-number predicate rides the digit-only fast arm
  (t31-ocr50-6, documentation:low)** — the URL Standard's step 4
  (last part non-empty, only ASCII digits) runs before the IPv4 radix
  parse, so '09' IS a number to every WHATWG consumer while the r49
  predicate's radix arm alone read it as octal-invalid and the host
  parsed as an opaque hostname — the accepting direction of the
  differential the r49-4 screen closes. The ledger read first (no
  digit-only adjudication; "the predicate is the Standard's own"):
  the code moves — the regex gains the digit-only alternation, the
  docblock naming the actual shape. Driven: 'https://09/' and
  'https://host.007/' answer the refusal (red at HEAD: constructed).
- **The lint gate refuses a symlinked source loudly (t31-ocr50-7,
  maintainability:low)** — the walk silently skipped symlinked *.php
  entries under a false "sibling collectors' parity" claim (the
  collectors THROW on links), a coverage regression: a linked source
  escaped php -l unseen. The link answers the walk's FAIL vocabulary
  now — a counted walk refusal naming the path and its target, the
  exit red, the tree still walked — with the exclusion judgment
  first, so a link under a third-party tree refuses nothing. The
  t31-ocr10-7 pin's silent-skip expectation is superseded. Driven:
  the linked source answers the refusal (red at HEAD: exit 0, the
  link unseen); regular trees lint unchanged.
- **The unchecked read pairs ride the marker owner (t31-ocr50-8,
  test:low ×2, one commit)** — the forced-failure test's
  byte-untouched snapshot and compare degraded a failed read to
  '' === '' (a vacuous pass over the contract the leg pins), and
  runState's seed manifest read degraded a staging failure into the
  CLEAN row's 'rebuilt manifest diverged' verdict. Both sites ride
  readMemberOrMarker(), an unreadable member answering its own marker
  like-for-like and an absent-or-unreadable seed manifest answering
  the row's own FAIL naming the staging channel. Construction-evident;
  happy paths unchanged.
- **The abort leg's derivation answers before the lock takes
  (t31-ocr50-9, test:low)** — the chmod-0000 on locked/ landed before
  the try/finally that restores it, two assertion sites between them:
  either failure stranded the tree at mode 0000 with no restore. The
  mode-independent yield derivation and the gate-path pin ride above
  the chmod now (the derivation's skip restore dead and deleted);
  only the probe's own restore-on-skip sits between lock and try, the
  finally owning every mode-0000 spelling alone. Construction-evident;
  failing legs still fail.

### Fixed (shared — M3 Task 3.1, OCR round 49)

Forty-ninth OCR-tool round (main 61/61, fully complete): 18 findings,
driver accepts all; 15 numbered commits t31-ocr49-1..15 (the left-anchor
generation lands as THREE findings/THREE commits under one census, the
seed channel as one commit over both its sites, the staging gaps and the
HarnessCopyTree pairs per their own census instructions). Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8→11→7→15→10→9→4→18.
The round's shape: the left-anchor generation — the ELEVENTH use-grammar
family, the namespace separator passing every word-byte lookbehind (the
engine demotes `Foo\namespace`/`Foo\use` to one qualified-name token, so
the keyword planted behind a separator matched every byte pattern at its
second occurrence); the IPv4-ambiguous host class (010.1.1.1 / 0x-labels
/ bare trailing-number hosts name two hosts over one URL on the
browser-facing channel); the undelimited credential tokens; the
seed-channel conversion the r45-6 sweep missed; and the statics exemption
in the payload fence. Round 49 answered NEW findings, so the OCR phase
continues per plan. Fixed as t31-ocr49-1..15 — one commit per finding,
plus this docs record, the full offline check green after every commit.
Suite 1716 → 1718 tests, 46468 → 46668 assertions
(+0/+2/+4/+2/+1/+16/+13/+3/+0/+0/+1/+8/+0/+0/+151/+0/+0/+0/+0/+0/+0),
3 skipped unchanged.

- **The namespace-declaration rewrite owns its left anchor
  (t31-ocr49-1, bug:high)** — the declaration pattern carried NO left
  boundary, and the engine lexes `use Foo\namespace Deicod\…` with
  `Foo\namespace` as ONE qualified-name token, so the pattern matched at
  the SECOND `namespace` and spliced the rewritten target mid-name —
  parse-error output at exit 0. The anchor `(?<![A-Za-z0-9_\\\\])`
  (word byte or separator refused) rides the head of the pattern, ONE
  census comment at the seam naming the statement-start class for every
  keyword-bearing pattern the rewriter spells; the mid-name span rides
  verbatim into the postcondition's family-reference verdict now.
  Driven: the mid-name spelling answers the refusal (red at HEAD:
  returned, spliced bytes shipping).
- **The use-keyword lookbehind owns the separator byte
  (t31-ocr49-2, bug:medium)** — `\` is not `[A-Za-z0-9_]`, so
  `use Foo\use Deicod\…\Clock;` matched at the second `use`, the
  callback splicing the rewritten target over the matched span with the
  leftover `use Foo\` bytes shipping parse-error output (the plain and
  family-prefix-brace spellings alike, one pattern). The lookbehind
  joins the r49-1 statement-start class; every legal `use` spelling
  rewrites byte-identically. Driven: both spellings answer the refusal
  (red at HEAD: returned).
- **The group-use prefix pattern owns the same anchor
  (t31-ocr49-3, bug:medium)** — the vendor-prefix group's lookbehind
  guarded word bytes only, so `use Foo\use Deicod\…\{Shared\Clock};`
  matched at the second `use` and the members rewrote inside a span no
  legal statement could occupy, refusing only at the postcondition one
  verdict late. The anchor refuses the match before any member bytes
  are judged. Driven through the splice's own observable: a planted
  repeated-kind member inside the mid-name group answered the MEMBER
  GRAMMAR's verdict at HEAD (the grammar judging mid-name bytes); it
  answers the postcondition's verdict now.
- **The IPv4-ambiguous host class rides the WHATWG-differential refusal
  (t31-ocr49-4, security:medium)** — the URL Standard parses any
  special-scheme host whose last label "ends in a number" as an IPv4
  address ('https://010.1.1.1/' is 8.1.1.1, the bare
  'https://2130706433/' is 127.0.0.1) while this parse kept the
  spelling as an opaque hostname: two hosts named by one URL over the
  browser-facing channel. The class refuses, never coerced — the
  predicate is the Standard's own (last label parses as an IPv4 number:
  decimal, 0x-hex, leading-zero octal), and the canonical dotted quad
  alone passes, both readings agreeing byte for byte. Driven: all six
  spellings answer the refusal through both the URL owner and the
  request VO (red at HEAD: constructed); canonical quads, interior
  digit labels, and trailing-dot domains unchanged.
- **The undelimited credential spellings join the catalog
  (t31-ocr49-5, security:low)** — a header named exactly 'apikey' or
  'accesstoken' (one token, no delimiter) folds to a judged name that
  equals no catalog entry and ends in no suffix segment: both screens
  answered false and the secret rendered verbatim through every safe
  debug form. The credential family's single-token forms join the
  catalog (the flattened spellings of the suffix class's hyphenated
  members and of the vendor-documented names the class's own record
  cites); the r24 boundary holds for glues with no recognized name
  behind them ('apitoken') and spanning bytes ('x-api-keychain') — the
  r24 pin's 'clientsecret' example row superseded. Driven: all eight
  spellings mask in name and render channels (red at HEAD: verbatim);
  'host'/'accept' stay verbatim.
- **The fold exclusion owns the dots-ONLY class
  (t31-ocr49-6, bug:low)** — the r48-3 close owned the exact '..'
  spelling only, so 'p/.../a.php' collapsed out of the fold key the
  same way and a zip carrying it beside 'p/a.php' answered a spurious
  case-fold duplicate line beside its traversal rejection. ANY segment
  consisting solely of dots stays outside the fold (the traversal
  screen's own parent-token predicate), a lone '.' still collapsing
  with the empty segment. Driven: the dots-run zip answers only the
  traversal refusal (red at HEAD: the spurious line).
- **The sweep charter tells the truth about the `.part` spelling
  (t31-ocr49-7, documentation:low)** — the claim read as if the pattern
  owned any `.<rand>.part` file, but the temp pattern is anchored to
  the zip temp's own prefix. Probed mid-close on this engine: libzip
  names the part temp AFTER THE ZIP'S OWN NAME, so the pattern's dotted
  tail does own the real spelling — and the sweep battery already pins
  it (the finding's "no test pins the claim" half read false against
  the tree). Doc-only: the charter names the exact owned spelling and
  the boundary the anchor draws.
- **The seed channel rides the row conversion
  (t31-ocr49-8, test:medium ×2)** — the seed-build catch spoke
  RuntimeException alone and the seed's zipEntryNames census rode raw
  assertions, so a ValueError-shaped throw — or a census assertion over
  a seed artifact that did not open — aborted the whole row table as a
  test ERROR, the one verdict channel the r45-6 sweep missed. Both
  invocations convert to the row's FAIL verdict naming the channel and
  class; the plant rides the row's optional 'seed_call' arm (absent on
  every enumerated row, the real seed build answers). Driven: a planted
  ValueError and a corrupt seed artifact each answer their row's
  verdict (red at HEAD: both aborted the table).
- **The payload-channel fence owns statics
  (t31-ocr49-9, test:medium)** — every collection in the exceptions
  family's API audit skipped `isStatic()`, so a `public static` helper
  on a concrete type passed every pin wholly exempt — the exact hole
  the ledger's own r8-5 disposition names while claiming the design.
  Statics judge by the same rule at every collection (the baseline
  keeping engine statics, the name diff and declaring-class audit
  answering family-declared ones). Driven both directions: a planted
  static helper passed every pin at HEAD; both audits answer it now.
- **The three staging gaps ride their siblings' doctrines
  (t31-ocr49-10, test:low ×3)** — makeScratchRepo's creation phase
  carries per-site success asserts (two mkdirs, three writes, each
  naming its own path), the four GPC/forged/lint/scan spawn legs
  consume the guarded realpath resolutions the five-script loop already
  asserts (the scan target's fixtures twin included), and the scanner's
  yield-order bracket leg owns all five IO returns before its guarded
  region. Construction-evident; happy paths unchanged.
- **The Exception census follows symlinked directories
  (t31-ocr49-11, test:low)** — without FOLLOW_SYMLINKS a
  symlink-to-directory under shared/src/Exception is neither
  isFile()-true nor recursed, escaping the census while the pin's
  message claimed "at any depth" — and PSR-4 maps a linked
  subdirectory like a real one, the family growing through it whether
  the census sees it or not. The walk reads the tree whole (a stray
  link fails the file-set pin naming the class). Driven by planting
  one: the linked type FAILS the census (green at HEAD — the escape).
- **The repo-junk catch writes the degraded notice it promises
  (t31-ocr49-12, bug:low)** — the finally's sweep catch was empty
  while its own census comment and the r42-8 record promise a
  stderrNotice(): an environmental failure in the repo-junk sweep
  disappeared with no diagnostic. The catch writes the notice through
  the suite's ONE STDERR writer (the ocr38-5 idiom), the in-flight
  verdict still outranking the diagnostic. Construction-evident; clean
  runs unchanged.
- **The multi-tail staging write rides stage()
  (t31-ocr49-13, test:low)** — the file's one staging write that
  bypassed the asserted stage() owner surfaced a failed write as a
  misleading downstream assertFileExists failure; the write rides the
  one owner whose failure names its path. Construction-evident.
- **The interval floor's deliberate lower bound named
  (t31-ocr49-14, documentation:low)** — RFC 8628 §3.2 defines
  `interval` with NO lower bound (only the absent field defaults to 5),
  so a provider may legally answer 0 and a future flow parser's
  payload would meet an opaque refusal. The ledger holds no
  interval-floor adjudication, so the docs align to what the code does:
  the floor is the VO's own deliberate decision (a 0-second floor is a
  busy-loop against the token endpoint), the parser's duty named at
  the guard. Doc-only.
- **The label sync guard distinguishes presence from null
  (t31-ocr49-15, maintainability:low)** — isset() cannot distinguish a
  LABELS row present with null from a missing row, so a
  `'case' => null` slip answered the MISSING-row guidance, mis-naming
  the defect. array_key_exists owns presence, a separate null check
  answers the null-shaped defect with its own named remedy — the
  runtime read riding a mixed-contract owner (phpstan constant-folds
  the declared table; no suppression). Driven both directions through
  a planted null row: the wrong guidance at HEAD, the null-shaped
  guidance now; ordinary labels unchanged.

### Fixed (shared — M3 Task 3.1, OCR round 48)

Forty-eighth OCR-tool round (main run partial — 52/61, nine large files
died to context compression; fill-in r52a covered the five bin/ files
completely): 4 findings, all accepted and fixed. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8→11→7→15→10→9→4.
The round's shape: the repeated-kind generation — the tenth
use-grammar family — beside the pid-false seam and the fold-key
comment drift. Round 48 answered NEW findings, so the OCR phase
continues per plan. Fixed as t31-ocr48-1..4 — one commit per finding,
plus this docs record, the full offline check green after every
commit. Suite 1716 → 1716 tests, 46458 → 46468 assertions
(+4/+3/+3/+0), 3 skipped unchanged.

- **The kind arm owns its once-per-member premise
  (t31-ocr48-1, bug:medium)** — the comma-list member grammar accepted
  a kind keyword whenever the member was not yet named, and a kind
  keyword never names anything: every kind-pair spelling
  (`function function Foo` and the three mixed/repeated twins)
  re-emitted verbatim beside the rewritten family — parse-error bytes
  php -l refuses, shipped at exit 0. The arm refuses the second kind
  with the engine's own verdict; single-kind members, the leaf
  exemption, and the alias dissolver ride unchanged. Driven: all four
  kind-pair spellings answer the refusal (red at HEAD: re-emitted).
- **The sweep patterns admit the empty-pid spelling
  (t31-ocr48-2, bug:low)** — getmypid() answers int|false, and a
  false runtime interpolates an empty pid component into every staging
  spelling (stage tree, zip temp, manifest temp) the `(\d+)`-anchored
  sweep patterns never matched: a crashed false-pid run's leftovers
  were permanently unsweepable dist residue. The pid component rides
  digit-optionally in all three patterns; the empty component parses
  to pid 0 — never alive, never a working run's own — so the dead-pid
  gate reclaims it exactly like any dead pid. Legacy pid-less foreign
  spellings stay unattributable. Driven: empty-pid stage, zip-temp,
  and manifest spellings all answer the sweep (red at HEAD: no
  match); normal pids unchanged.
- **The fold key keeps '..' outside the fold
  (t31-ocr48-3, maintainability:low)** — the inspector's fold-key
  derivation collapsed a dots-only '..' segment to '' and dropped it,
  folding 'p/../a.php' onto 'p/a.php' and answering a spurious
  case-fold duplicate line beside the traversal rejection,
  contradicting the key's own census comment. The '..' segment rides
  the key verbatim — excluded from the fold's collapsing vocabulary,
  exactly the comment's contract — and the traversal refusal alone
  answers. Driven: a zip with both spellings answers only the
  traversal refusal (red at HEAD: spurious line); genuine case-fold
  duplicates still answer theirs.
- **The freeze-kill docblock tells the miss arm's actual contract
  (t31-ocr48-4, documentation:low)** — the crash-sim pin's docblock
  promised "an unopened window after every attempt is a loud
  staging-shaped failure, never a vacuous green" while the else
  branch passes on the miss by design (per the r40-5 record's own
  both-arms-green fact; a loud miss would fail every plant-less
  host). Doc-only: the miss rides green BY DESIGN at the residual
  probability, the number stays, the loud-failure clause goes.

### Fixed (shared — M3 Task 3.1, OCR round 47)

Forty-seventh OCR-tool round (main 61/61, fully complete): 9 findings,
all accepted and fixed (the ninth folded into the eighth's commit per
its own census instruction). Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8→11→7→15→10→9.
The round's shape: the reserved-segment generation — the ninth
use-grammar family, the reserved-keyword census applied to SEGMENT
positions (member names AND sub-segment tails, the oracle's
vocabulary existing while its application stopped at aliases), the
multi-tail '/..' adjudication, the orphaned-children termination, and
the vacuous-assertion pair. Round 47 answered NEW findings, so the
OCR phase continues per plan. Fixed as t31-ocr47-1..8 (47-9 folded
into 47-8) — one commit per finding, plus this docs record, the full
offline check green after every commit. Suite 1716 → 1716 tests,
46431 → 46458 assertions (+11/+11/+0/+3/+1/+0/+1/+0), 3 skipped
unchanged.

- **The member NAME rides the reserved-segment census
  (t31-ocr47-1, bug:medium)** — the member-name check validated label
  SHAPE only, and every keyword of the language is legal label bytes:
  a bare `list` member beside a rewritten sibling re-emitted verbatim
  and the zip shipped a parse error at exit 0. Two driven halves: a
  bare-keyword member (single segment — the ONE identifier walk
  extracted from the alias oracle) and a special-class LEAF (the
  fifteen-spelling SPECIAL_CLASS_NAMES census, the alias soft list
  folded in plus static; an un-aliased class import binding one
  fatals at compile). The dissolvers stay legal: an alias, a
  function/const kind, and a keyword glued into a multi-segment
  member all lint clean and keep rewriting. Driven: six vendor-prefix
  rows and two family-prefix rows answer the grammar's own refusal
  (red at HEAD: the ship shapes returned normally).
- **The sub-segment tail and the relative-list member ride the same
  census (t31-ocr47-2, bug:medium)** — the plain use pattern's tail
  group and the relative-use walk's member judgment carried the same
  gap: `use …\Shared\true;` re-emitted its tail verbatim and the zip
  shipped a compile FATAL ("Cannot use … as true because 'true' is a
  special class name"), at exit 0, judged by nobody; the relative
  seam shipped the same fatal bytes twice over (a list member and its
  own run's leaf). Driven honestly: the finding's `list` example
  lints clean at the tail (the 8.0+ lexer glues a hard keyword into
  the name token) — the refused class is the special-class leaf
  alone. The tail and member checks ride the un-aliased class-kind
  binding only; the namespace-declaration seam is adjudicated clean
  by the same oracle (a declaration owns no reserved vocabulary).
  Driven: three tail rows and two relative rows answer their seam's
  refusal; two dissolver batteries pin the legal controls rewriting.
- **hash_file() and filesize() join the owned-return @ idiom
  (t31-ocr47-3, bug:low ×2, one commit)** — both checked calls spelled
  the call bare, emitting a raw E_WARNING before the seam's own named
  refusal while both neighbors already carried the suppression.
  Construction-evident; clean paths unchanged.
- **The multi-tail '/..' strip adjudicated no-pop for every tail
  count (t31-ocr47-4, bug:low)** — the probe's strip loop was
  adjudicated for the single tail only; the multi-tail landing
  ('/a/b/../..' probes '/a/b', never the semantic '/') is now
  derived, ledgered, and pinned: the landing is the PROBE spelling,
  never a resolver — a popped component would hide a link's own
  spelling from the probe chain (the ocr9-1 blast radius, multi-tail
  edition), while every component of a spelling IS traversed by the
  engine's own resolution. Pinned both directions: a multi-tail
  spelling through a link still names the link (red under the popping
  correction), and a real multi-tail source still copies the tree it
  semantically names.
- **The lint leg's scratch finally owns the tempnam base
  (t31-ocr47-5, test:low)** — tempnam() mints the extension-less base
  before the '.php' twin is written, and the finally unlinked only
  the twin: one empty /tmp file leaked per spawnable run. Both
  spellings removed now, the residue pinned (red at HEAD: the base
  survived).
- **The staged-spelling pin speaks the comparison's own vocabulary
  (t31-ocr47-6, test:low)** — the PSR-4 casing leg asserted the bare
  substring 'tools', which the refusal's embedded swept path carries
  incidentally: the leg passed whenever the file was named at all.
  The pin asserts the verdict's casing-specific phrase, which no
  incidental path substring can carry — red under exactly the
  regression it exists to catch.
- **The CR-only leg owns its must-trip premise (t31-ocr47-7,
  test:low)** — the loop skipped declaration references inline and
  asserted nothing else, so a collector regression reporting only the
  declaration left ZERO assertions and the pin silently stopped
  biting. The non-declaration references are collected and their
  presence asserted first (the ocr33-2 trip-guard idiom); the
  declaration-only regression answers a named failure.
- **The crash-sim child owns its own termination (+ the 47-9 census,
  folded; t31-ocr47-8, other:medium)** — the crash-sim child ran
  `while (true)` with no in-child termination, its only lifecycle
  owner the test's finally kill: a CI cancel/timeout, OOM fatal, or
  Ctrl-C orphaned a busy-looping child beside a dead test. The child
  embeds the owning test process's pid and probes it every iteration
  — the signal-0 probe LEADS (driven counter-proof: the /proc is_dir
  branch rides PHP's stat cache and kept answering alive over twenty
  million iterations past the owner's death; the first cut of this
  fix would have shipped it), the /proc fallback clears the cache per
  call. A dead owner answers the loud orphan exit within one
  iteration of the reaping (driven twice in /tmp: exit 71, ~5ms); the
  leg runs green and pgrep answers clean. The 47-9 census: this is
  the suite's only backgrounded unbounded child — every other spawn
  runs foreground over a finite script, and the harness's two
  while (true) walks are bounded fixpoint loops.

### Fixed (shared — M3 Task 3.1, OCR round 46)

Forty-sixth OCR-tool round (main 61/61, fully complete): 10 findings,
all accepted and fixed. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8→11→7→15→10.
The round's shape: the digit-initial generation — the eighth
use-grammar family, three owners in one census (the pattern classes,
the oracle's single-token blindness, the shape check), the same
un-anchored [A-Za-z0-9_]+ class everywhere — plus the masking
edge-delimiter twin (the empty final segment), the backslash-in-path
screen, and the scanner's case-variant sibling of the r35-1 census.
Round 46 answered NEW findings, so the OCR phase continues per plan.
Fixed as t31-ocr46-1..10 — one commit per finding, plus this docs
record, the full offline check green after every commit. Suite
1710 → 1716 tests, 46399 → 46431 assertions, 3 skipped unchanged.

- **Every label position in the use and namespace patterns anchors
  its first byte class (t31-ocr46-1, bug:medium)** — the identifier
  classes in the use-statement pattern and the namespace-declaration
  twin spelled the UN-ANCHORED byte class [A-Za-z0-9_]+, so '0Foo'/
  '0foo' matched the sub-segment tail, the alias group, and the
  declaration twin as though they were names, and the callbacks
  re-emitted them beside rewritten output: parse-error bytes in the
  zip at exit 0 with every gate green. Every label position anchors
  at [A-Za-z_] now — the illegal spelling fails the pattern, rides
  verbatim, and refuses at the postcondition's family-reference
  verdict. Driven: the three spellings answer the refusal naming the
  file (red at HEAD: re-emitted); legal labels unchanged.
- **The engine-oracle hard half walks the token sequence
  (t31-ocr46-2, bug:medium)** — the oracle returned true only when a
  single non-T_STRING token's text equaled the FULL alias, so a
  spelling that lexes as MORE THAN ONE token ('0foo' is T_LNUMBER +
  T_STRING) matched no single token and fell through as legal. The
  walk collects the alias position's tokens and requires EXACTLY ONE
  identifier-shaped token — an own-token keyword fails the name, a
  multi-token spelling fails the count, 'as' itself lexes T_AS and
  refuses by the same count (probed). The word-bytes routing gate
  above the walk stays un-anchored DELIBERATELY (it feeds the walk
  its own class). Driven: the digit-initial member-alias rows answer
  the refusal (red at HEAD: re-emitted); single-token spellings
  unchanged.
- **The member alias shape speaks the label grammar at every seam
  (t31-ocr46-3, bug:medium)** — the group-use member grammar's alias
  check spelled the same un-anchored class, so '{Shared\Clock as
  0foo}' passed the grammar at BOTH re-emit seams and shipped the
  bytes at exit 0. The shape speaks the label grammar and judges
  BEFORE the reserved-vocab oracle consults, so a digit-initial tail
  answers the shape's own vocabulary, never the reserved-word
  sentence it is not; the census comment names the routing gate's
  deliberate looseness. Driven: both group spellings answer the
  shape arm's refusal (red at HEAD: re-emitted); legal aliases
  unchanged.
- **The credential boundary segments before emptiness is judged
  (t31-ocr46-4, security:medium)** — 'Authorization.' is a legal RFC
  7230 token, and its fold 'authorization-' carries an EMPTY final
  segment: the exact match failed and str_ends_with failed over the
  empty-segment shape, so the credential rendered verbatim through
  every safe debug form (the same r12-4 leak class the ocr43-1/
  ocr44-2 closures claimed closed). The judged name sheds its bound
  separators once at the fold — an empty BOUND segment never
  disqualifies a credential-bearing name over either edge; an empty
  MID segment changes nothing, a separators-alone name judges the
  empty string. Driven: the edge spellings answer masked, the
  boundary controls stay plain (red at HEAD: the trailing-edge rows
  unmasked); plain spellings byte-unchanged.
- **The backslash screen owns the whole input (t31-ocr46-5,
  security:medium)** — the r28-6 screen probed only $authority, but
  the URL Standard's path state treats U+005C as a segment SEPARATOR
  for special schemes: a browser consuming 'https://host/device\page'
  requests '/device/page' while this parse kept the byte verbatim in
  the path — url(), redacted_url(), and every derived surface naming
  a different path than the browser consumes. The screen probes the
  whole URL (every scheme this constructor admits is special, and
  RFC 3986 admits no raw backslash in path, query, or fragment
  either). Driven: the path/query/fragment spellings answer the
  refusal (red at HEAD: constructed); clean paths unchanged.
- **The extra-throw pin rides its row's own gate premise
  (t31-ocr46-6, bug:medium)** — the round-45 pin drives runState()
  with an 'expect' => 'CLEAN' row but asserted its FAIL verdict
  unconditionally; on a disable_functions host the row-level gate
  answers SKIP first and the assertion failed as an
  environment-looking defect (the ocr42-5 class over the ROW
  channel). The SKIP verdict is the gate answering, named by its own
  why; the FAIL assertions run only where the row can.
  Construction-evident; spawning host unchanged.
- **The release-guard SAPI sim's guard speaks the declaring triple
  (t31-ocr46-7, bug:low)** — the guard named only the spawn pair
  while the leg's first statement past the probe is a putenv (the
  child's harness path rides the ENVIRONMENT), so a putenv-disabled
  host fataled before the child could ever spawn. The guard speaks
  canSpawnChildren('putenv') — the redirected-TMPDIR siblings' own
  idiom. Construction-evident; ordinary hosts unchanged.
- **The clean-direction fixture's compilability is a checked premise
  (t31-ocr46-8, test:low)** — the fixture's
  'self(): namespace\FormsFixture' was charged as a parse error the
  engine never sees (the gate tokenizes, never parses). DRIVEN at
  HEAD: the premise fails on this engine — the spelling lints CLEAN
  on 8.5.10; the relative operator has been part of the TYPE grammar
  since 8.0 and the floor is >= 8.2 ('expression-only' is a PHP 7
  truth). The spelling stays (legal on the floor, load-bearing per
  t31-r8-2); a php -l leg now pins the fixture's compilability, so
  the clean contract can never again ride a byte shape the engine
  would refuse. Construction-green at HEAD by the driven truth; gate
  verdicts unchanged.
- **The scanner's keyword patterns ride the scoped case-insensitive
  census (t31-ocr46-9, bug:low)** — the unused-import scanner spelled
  use/function/const/as byte-exact lowercase while the engine — and
  the rewriter's own r35-1 census — treats every keyword
  case-insensitively, so a legal 'Use …' / 'USE FUNCTION …' / '… AS
  C;' in connectors/ went unscanned: a dead case-variant import was
  invisible to the gate. The scoped (?i:…) groups sweep every
  keyword-bearing pattern in the file — the statement pair, both
  keyword-prefix strips, and the group unroller's member parses.
  Driven: five case-variant rows answer the verdict, the used control
  stays clean (red at HEAD: unscanned); lowercase spellings
  unchanged.
- **The raw accessor's oversized side pinned (t31-ocr46-10,
  test:low)** — the accessor's adjudicated invariant (t31-r1-15:
  hands out the RAW provider number by design, the cap is the
  consumer's) was pinned only for sub-cap values, so a refactor that
  starts clamping the raw accessor would have ridden green. The pin
  answers RAW on the oversized literal and PHP_INT_MAX, beside the
  policy suite's own capped pins (one boundary, both files).
  Construction-green at HEAD — red only under the clamping refactor
  it guards.

### Fixed (shared — M3 Task 3.1, OCR round 45)

Forty-fifth OCR-tool round (main 61/61, fully complete): 15 findings,
all accepted and fixed. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8→11→7→15.
The round's shape: the WHATWG host-parser generation proper —
percent-decode of special-scheme hosts (two hosts, one URL), the
whole-input tab strip, RFC 6874 zone ids named at their own screen —
beside the tchar census collapsed to its single owner, the
verdict-channel conversion swept to both siblings, the glob-false
pair, and the symlink-cycle bound. Round 45 answered NEW findings,
so the OCR phase continues per plan. Fixed as t31-ocr45-1..12 — one
commit per finding, plus this docs record, the full offline check
green after every commit. Suite 1704 → 1710 tests, 46357 → 46399
assertions, 3 skipped unchanged.

- **The URL host refuses percent-encoded spellings (t31-ocr45-1,
  security:medium)** — the URL Standard's host parser PERCENT-DECODES
  a special-scheme host before domain-to-ASCII (§6.4), so a browser
  loading 'https://id%70.example/' contacts idp.example while this
  parse and every redacted form named the encoded spelling verbatim:
  two hosts named by one URL over the same browser-facing channel
  (the device-flow verification URI) the backslash and strip-set
  screens closed for their own bytes — the r28-6 refusal class one
  generation over. A host carrying %XX answers the refusal at the
  host region (non-bracket; the bracket literal's percent spelling is
  the zone id's own screen), never decoded. Driven: four spellings
  refuse at both consumers (red at HEAD: constructed); percent-encoded
  paths, queries, and userinfo stay legal.
- **The strip-set screen owns the whole input (t31-ocr45-2,
  bug:low)** — the URL Standard removes tabs and newlines from the
  ENTIRE input before parsing, but the r44-1 probe judged only the
  extracted authority: a tab in the path or query rode validation
  green while every WHATWG consumer saw the stripped spelling and the
  engine's parse_url() rewrote the query's tab to a third
  ('code=ab_cd') — three URLs named by one input. The probe rides the
  entry now; the r44-1 authority spellings stay refused as a subset.
  Driven: path/query/fragment tabs refuse at both consumers (red at
  HEAD: the path shape constructed).
- **The bracket content leg names the RFC 6874 zone-id class
  (t31-ocr45-3, bug:low)** — 'https://[fe80::1%25eth0]/' is a
  spelling the WHATWG parser accepts, but FILTER_VALIDATE_IP (the one
  IP-literal validator the screen charters) rejects the %25-spelled
  literal and the PHP-side transport cannot resolve it; the literal
  died on the generic malformed-host sentence that names no zone id.
  The spelling keeps its refusal (acceptance would hand-roll a second
  IP grammar beside the engine's validator) under its OWN name — a
  percent probe rides first in the content leg. Driven: four
  spellings answer the named verdict (red at HEAD: the generic
  sentence); plain IPv6 unchanged.
- **The tchar delimiter census rides its one owner (t31-ocr45-4,
  maintainability:medium)** — the masking boundary's strtr census was
  a hand-spelled copy of the grammar's own class with nothing
  structural tying the spellings (the single-owner drift
  ocr8-8/ocr40-3 exist to prevent, claimed in prose only). HeaderMap
  gains NAME_TOKEN_DELIMITER_CLASS; NAME_TOKEN_PATTERN COMPOSES from
  it (byte-identical, verified) and the classifier consumes the same
  constant — the two spellings cannot drift. Construction-evident;
  the masking battery green byte-identically.
- **Both VO constructor annotations speak the true header key domain
  (t31-ocr45-5, documentation:low ×2)** — array<int|string, mixed>,
  mirroring HeaderMap's own domain (an all-digit name arrives as an
  engine-coerced int key and is restored, t31-r2-4). Doc-only.
- **Both verdict channels convert every Throwable (t31-ocr45-6,
  bug:medium + maintainability:low)** — the run-under-test catch
  spoke RuntimeException alone, the extra-channel catch
  AssertionFailedError alone: any other Throwable the adversarial
  state surfaced escaped runState() as a test ERROR and aborted the
  whole row table. The ocr30-6 conversion doctrine swept to both
  siblings. Driven: a planted ValueError inside a CLEAN row answers
  that row's FAIL verdict naming the class (red at HEAD: the throw
  escaped as a test ERROR).
- **The collector's iterator construction rides inside the guarded
  region (t31-ocr45-7, maintainability:low)** — an unopenable scan
  root threw from the RecursiveDirectoryIterator constructor BEFORE
  the try, the finally never ran, and the by-ref $counted was never
  assigned, falsifying the docblock's own "@param-out … always"
  contract. Driven in-process over a chmod-0000 root: the refusal
  AND $counted === 0 (red at HEAD: the count stayed null).
- **The four production-source pin reads own their failure
  (t31-ocr45-8, maintainability:low ×4)** — the structural pins read
  through silent `(string) file_get_contents()` casts, a failed read
  degrading to '' and the fragment assertions failing late with
  misleading messages. One productionSource() helper refuses loudly
  naming the class and file; all four sites ride it.
  Construction-evident.
- **Both glob sites own the glob contract (t31-ocr45-9, bug:low ×2)**
  — the scratch lens read glob()'s false as the window HELD (a
  diagnostic failure fabricating the window-open verdict), and the
  temp sweep kept the false into its foreach, a TypeError replacing
  the in-flight verdict (the ocr42-8 class). The lens answers its own
  loud failure; the sweep degrades to the empty array — diagnostics
  degrade, verdicts never. Construction-evident.
- **The deferred-read arm's dead handle discards inside its own
  silencer window (t31-ocr45-10, bug:low)** — a failed close() leaves
  the archive object dead-but-live until the bare reassignment or
  method-scope teardown, both outside any error handler; the
  destructor's engine vocabulary could leak into the next test's
  output. unset($zip) in its own silencer/finally window (the
  ocr42-7 idiom, this arm). Construction-evident.
- **The shared-prefix strip speaks the prefix boundary
  (t31-ocr45-11, maintainability:low)** — the completeness walk's
  every-occurrence str_replace spelled the exact defect shape
  ocr6-5 killed in the old inline copy loop, unreachable only by the
  random root suffix. The iterator roots the walk at the shared
  tree, so substr past the prefix IS the relative.
  Construction-evident.
- **The resolution loop owns a cycle bound (t31-ocr45-12, bug:low)**
  — the ocr17-9 termination argument leaned on every anchor link
  resolving; a chain returning to itself resolves forever, and the
  loop's boundedness was the screens' accident. Driven at HEAD: the
  two-link cycle does NOT hang on this engine (stat ELOOPs, the
  DANGLING arm refusing first under a sentence that mis-names a
  chain resolving forever) — the resolves-to-nothing arm now HOPS
  the chain with a seen-set naming the cycle, and the loop gains the
  seen-set fence (legal fixed points break before the fence).
  Driven: the cycle answers the SYMLINK CYCLE refusal (red at HEAD:
  the dangling vocabulary); dot-chains and plain resolutions
  unchanged.

### Fixed (shared — M3 Task 3.1, OCR round 44)

Forty-fourth OCR-tool round (main 61/61, fully complete): 7 findings,
all accepted and fixed. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8→11→7.
The round's shape: the tab-in-host WHATWG twin (the r28-6 refusal
class striking at the authority itself), the tchar class completed at
the masking boundary (the '.' slice), the constructor annotation
speaking the true key domain, and the test-hygiene trio (a scratch-tree
leak that defeated its own tearDown, a readdir-order load-bearing
assertion, a killed child's stranded probe, and a cgi child's argv
premise). Round 44 answered NEW findings, so the OCR phase continues
per plan. Fixed as t31-ocr44-1..7 — one commit per finding, plus this
docs record, the full offline check green after every commit. Suite
1702 → 1704 tests, 46340 → 46357 assertions, 3 skipped unchanged.

- **The URL authority refuses the WHATWG strip set (t31-ocr44-1,
  security:medium)** — the URL Standard removes ALL ASCII tabs and
  newlines from the input BEFORE parsing, so a tab in the host
  mutates the host a browser contacts: 'https://id<TAB>p.example'
  sends a WHATWG consumer to idp.example while this parse kept the
  tab in the authority and parse_url() rewrote it to a third spelling
  ('id_p.example') — one URL naming three hosts over the same
  browser-facing channel (the device-flow verification URI) the
  t31-ocr28-6 backslash screen closed. WHATWG-differential bytes are
  REFUSED at the authority, never naively stripped; the round-1
  host-charset adjudication's tab half ("forges nothing") is
  superseded — the tab forges — while its space half stands (a
  space-bearing host fails WHATWG validation rather than contacting
  another host). Driven: three tab spellings refuse at both
  consumers (red at HEAD: the strip shape constructed); the space
  control stays constructible; the three sites that pinned the old
  tab verdict re-staged.
- **The masking boundary owns the whole tchar delimiter census
  (t31-ocr44-2, security:low)** — the ocr43-1 rationale ("the
  delimiter is a CLASS") closed only the underscore twin, but '.' is
  equally a legal tchar and so is every other non-alphanumeric byte
  in NAME_TOKEN_PATTERN's own class: 'x.api.key' matched neither
  catalog nor class and rendered its secret verbatim (non-blocking,
  no current consumer — pure class completion). The fold normalizes
  all fourteen non-alphanumeric tchars (beside the hyphen's identity)
  to the hyphen once; the boundary holds over every separator
  spelling ('x.api.keychain' stays outside). Driven: the dot
  spellings answer masked (red at HEAD: 'x.api.key' unmasked).
- **The HeaderMap constructor annotation speaks the actual key
  domain (t31-ocr44-3, documentation:low)** — canonical digit-string
  keys arrive as PHP ints, are deliberately restored and accepted
  (pinned since t31-r2-4), yet `array<string, mixed>` made every
  such call site a static-analysis false positive. The annotation
  reads array<int|string, mixed> now, the same restoration narrative
  the docblock already tells. Doc-only.
- **The unused-import teardown release owns the whole tree
  (t31-ocr44-4, test:medium)** — tearDown() was a raw @rmdir chain
  one level deep: the abort leg's locked/Hidden.php (mode restored,
  file still inside) made both rmdirs fail silently and the whole
  uniqid-named tree survived every run — thirteen stranded trees
  cleared by hand from the temp root during the drive. The release
  rides WpHarness::releaseScratch (the t31-ocr34-4 idiom): recursive,
  guarded, never a verdict replacer. Driven: a new pin stages the
  non-empty shape and calls tearDown() directly (red at HEAD: the
  tree survived); the full check now leaves zero residue.
- **The abort leg derives its expectation from the observed yield
  order (t31-ocr44-5, test:medium)** — the ocr43-8 assertion
  required dead.php to be walked before the locked tree descended,
  premised on this build's readdir order (entries inverted from
  creation); on a creation-order filesystem the assertion failed as
  an environment-looking defect. Two dead sources BRACKET the locked
  tree now and the expectation derives from the OBSERVED stream
  (scandir SCANDIR_SORT_NONE — the raw readdir order the iterator
  walks; the default alphabetical sort is a third order neither
  consumer rides, driven), exact per observed sequence on every
  filesystem; an order yielding the locked tree first skips naming
  the premise. Green on this host; the leg no longer depends on
  readdir order.
- **The temp sweep reclaims the killed child's stranded probe
  (t31-ocr44-6, test:low)** — the ocr43-10 scoping left the sweep
  globbing only this process's pid spelling, but the SIGKILLed child
  strands its own whenever the kill lands in its plant window —
  residue in the shared temp root no run reclaims. The sweep answers
  both spellings (the test's OWN dead child — the ocr43-10
  foreign-process boundary intact); the window detection gains the
  scratch lens (the fixed seam's plant beside the regressed seam's
  fixtures lens). The pin is driven deterministically over the staged
  stranded spelling — this runner's tmpfs /tmp beside the ext4 repo
  makes the real strand unconstructible (the r38-2 no-plant arm,
  probed) — guarded by a verdict-return flag so no assertion fires
  while a verdict is in flight (the ocr42-8 doctrine). Red at HEAD:
  the staged probe survived the own-pid-only glob.
- **The cgi child reads its harness path from the environment
  (t31-ocr44-7, test:low)** — `require $argv[1];` gated the
  non-CLI-SAPI leg on register_argc_argv, an ini setting the CGI
  SAPI does not force: with it off, $argv was undefined, the child
  fataled at the require, and the leg failed through the exit-code
  assertion as an environment-looking defect. The path rides
  putenv/getenv now (read in every SAPI), the variable unset in the
  finally; construction-evident, the CLI host riding unchanged.

### Fixed (shared — M3 Task 3.1, OCR round 43)

Forty-third OCR-tool round (main 61/61, fully complete): 11 findings,
all accepted and fixed. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8→11.
The round's shape: the underscore twin (the masking suffix class
spoke hyphens only while the header grammar admits underscore tchars),
the r42-2 twin sweep that never happened (the zip/sidecar staging
temps stayed pid-predictable), the seventh use-grammar generation
(the group-member NAME's own shape), the inverted getLastErrors
narrative, and the slug-equals-family-leaf degenerate. Round 43
answered NEW findings, so the OCR phase continues per plan. Fixed as
t31-ocr43-1..10 — one commit per finding (the two documentation:low
halves of the inverted narrative sharing one commit), plus this docs
record, the full offline check green after every commit. Suite
1699 → 1702 tests, 46310 → 46340 assertions, 3 skipped unchanged.

- **The credential boundary speaks the underscore tchar (t31-ocr43-1,
  security:medium)** — the suffix class keyed exclusively on hyphen
  boundaries, but '_' is a legal RFC 7230 tchar HeaderMap's
  NAME_TOKEN_PATTERN admits beside '-': 'x_api_key' (and
  'subscription_key', 'auth_token', 'client_secret' — underscore
  spellings of the very tokens the class names) matched neither
  catalog nor class and rendered their full secret verbatim through
  every safe debug form. The classifier normalizes '_' to '-' once at
  the fold; hyphen-only names judge byte-identically, the boundary
  pin holding over either separator ('x_api_keychain' stays outside).
  Driven: the five underscore spellings answer masked (red at HEAD).
- **The zip and sidecar staging temps own unpredictable names
  (t31-ocr43-2, security:medium)** — the r42-2 twin sweep that never
  landed then: $stage gained its random suffix precisely because the
  pid is predictable, while the zip temp and its sidecar twin stayed
  pid-only in the same method (ZipArchive::open() writing through
  whatever stands at the predicted path). Both carry the random
  suffix now, one census comment naming the whole staging family; the
  sweep's tail pattern rides the suffix tail-optionally, so stale
  temps of both spellings still reclaim. Driven: a random-suffixed
  dead-pid zip orphan is swept (red at HEAD); the two planted-blocker
  legs invert to the guessable-spelling contract (junk at the
  once-predictable spelling is inert, the build landing whole beside
  it).
- **The group-member NAME rides the grammar (t31-ocr43-3,
  bug:medium)** — the seventh use-grammar generation:
  groupUseMemberGrammar() validated everything around the member name
  (emptiness, the alias, the kind, the leading separator) while "a
  member that is not a name at all" passed every check and was
  re-emitted verbatim by BOTH re-emit seams — compile-error bytes in
  the zip at exit 0. The name rides the engine's own label grammar
  (php -l-derived, high bytes admitted), one owner both seams already
  rode. Driven: digit-initial, mid-name space/hyphen, and
  digit-initial sub-segment rows answer the grammar's own refusal at
  both seams (red at HEAD: re-emitted); legal members unchanged.
- **The build removal walk owns its IO returns (t31-ocr43-4,
  bug:medium)** — WpConnectorsBuild::rrmdir()'s per-entry (and final)
  removal calls ran bare, so a refused removal answered with a raw
  E_WARNING interpolating staging paths against the docblock's own
  silent-degrade promise — under the runner's warning conversion,
  another vocabulary inside the finally. The calls ride the @ +
  owned-return idiom at this owner's own contract (never the harness
  twin's loud throw — a rethrow would REPLACE the primary verdict,
  the t31-ocr23-1 class): the refused entry stays for the sweep's
  next run, the partial removal stands. Driven: a stranded 0555
  subtree answers the named degrade (red at HEAD: the raw warning),
  the readable siblings removed.
- **The NAMESPACE keyword joins the scoped census (t31-ocr43-5,
  maintainability:low)** — the r35-1 census's one reopened seam: the
  declaration pattern spelled its keyword lowercase-only, so
  `NAMESPACE Deicod\WpConnectors\Shared;` (legal PHP) skipped the
  rewrite and refused only as an anonymous postcondition failure. The
  keyword rides the scoped (?i:…) group, its casing verbatim in the
  output. Driven: a NAMESPACE-spelled declaration answers the rewrite
  verdict (red at HEAD: skipped); lowercase spellings unchanged.
- **The slug-equals-family-leaf degenerate refuses at the config seam
  (t31-ocr43-6, bug:low)** — a connector slug of 'shared' derives
  namespace_suffix 'Shared' (the family's own leaf), passes every
  gate, and composes …\Shared\Shared: the rewrite re-matches its own
  output, a double rewrite shipping a namespace nothing loads at
  exit 0. The vocabulary-doctrine premise is explicit now — no
  component may compose into the family's own spelling — the
  colliding suffix (case-insensitively) refused loudly. Driven: slug
  'shared' answers the config refusal (red at HEAD: the degenerate
  build SUCCEEDED); ordinary slugs unchanged.
- **The lint summary names the count that carries the verdict
  (t31-ocr43-7, maintainability:low)** — the exit verdict folds the
  walk refusals in while the stdout summary named only checked and
  failure counts: a refusal-only red run printed "0 failure(s)" while
  exiting 1, the line and the verdict disagreeing about where the red
  came from. The refusal count rides the same summary line (both
  numbers when both nonzero). Driven over the locked-walk leg: the
  refusal-only shape prints its count (red at HEAD).
- **The aborted scan answers its partial count (t31-ocr43-8,
  other:low)** — the unused-import collector printed every FAIL as
  found but returned its count only at the end, so the gate's
  abort conversion discarded every violation counted before the
  refusal. An optional by-ref count syncs in a finally around the
  walk (the abort flying through untouched), both gate callers
  folding the partial in beside the abort's own FAIL. Driven through
  the child shape: the count at the abort equals the FAIL lines
  already printed (red at HEAD: the FAILs printed while the count
  stayed 0).
- **The getLastErrors narratives tell the engine's actual direction
  (t31-ocr43-9, documentation:low)** — the documented engine shapes
  were INVERTED: pre-8.3 DateTimeImmutable::getLastErrors() returns
  an ARRAY on a clean parse (zero counts, keys present); the 8.3
  change made it return FALSE when clean (the runner's 8.5 handing
  false confirms the direction). The guard's logic was always right;
  both narratives now tell the true direction, the invented
  "undefined-key warning pair" mechanism dropped with the shape it
  premised. Doc-only: guard and pins byte-unchanged.
- **The freeze-kill pin's temp sweep is pid-scoped (t31-ocr43-10,
  test:low)** — the finally globbed 'wpct-pathcase-*' over the shared
  temp root, matching every process's in-flight case probe: under
  parallel CI runners one runner's finally could unlink another LIVE
  runner's probe mid-measurement. The sweep matches only this
  process's pid-prefixed spelling (the probe name carries its own
  pid); a foreign-pid probe file survives it by construction, the
  crash sim's dead-child residue belonging in scratch or nowhere per
  the ocr40-5 doctrine.

### Fixed (shared — M3 Task 3.1, OCR round 42)

Forty-second OCR-tool round (main 61/61, fully complete): 8 findings —
one (the round's bug:critical) REFUTED AT HEAD with no commit, seven
accepted and fixed. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4→8.
The round's shape: the critical drift (refuted — an 'ancestor'-read-as-
'anchor' misread of the resolution loop's variable), the stage-path
TOCTOU (a predictable pid-only spelling under check-then-act), the
scan-root guard's POSIX-only spellings, and the verdict-replacement
finally beside three smaller test contracts. Round 42 answered NEW
findings, so the OCR phase continues per plan. Fixed as
t31-ocr42-2..8 — one commit per finding, plus this docs record, the
full offline check green after every commit. Suite 1699 tests,
46305 → 46310 assertions, 3 skipped unchanged.

- **The critical drift is refuted at HEAD (t31-ocr42-1, bug:critical —
  NO COMMIT)** — the finding quoted `strlen($anchor)` in copyTree()'s
  resolution loop ("$anchor is never assigned anywhere"), but the
  token `$anchor` appears nowhere in `tests/harness/WpHarness.php`:
  the walk variable is `$ancestor` at every use, mechanically counted
  (`grep -c 'strlen(\$anchor)'` → 0, `'strlen(\$ancestor)'` → 1, at
  HEAD and at the line's ocr29-4 birth) — and the claimed symptom
  (every resolved-source copyTree aborting under the runner's warning
  conversion) is doubly impossible over a suite green through forty
  rounds of exactly those copies. An 'ancestor'-clipped-as-'anchor'
  misread; recorded here so the next lens does not re-derive it.
- **The stage tree owns an unpredictable name (t31-ocr42-2,
  security:medium)** — the staging spelling was pid-only and
  predictable (pids enumerable), and the is_link → rrmdir → sweep →
  recursive-mkdir sequence was check-then-act over it: a planted tree
  at the predicted name rode between the checks and the mkdir
  (TOCTOU). The name gains the mkdtemp-style random suffix
  (`bin2hex(random_bytes(8))`, the ocr10-2 model's own spelling), the
  same-name reclaim arm is gone (a collision is never ours to reclaim
  — the checked mkdir owns it loudly, nothing deleted), and the
  sweep's stale-detection pattern rides the tail tail-optionally.
  Driven: a random-suffixed dead-pid orphan is swept (red at HEAD: the
  pid-anchored pattern never matched it); the own-name link leg
  inverted to the new contract (a link at every guessable spelling
  neither blocks nor is touched).
- **The scan-root guard speaks both separator spellings
  (t31-ocr42-3, bug:medium)** — absoluteness ('/'-anchored first
  byte) and containment ('/'-joined needle) were judged POSIX-only,
  so on a '\'-separator host build's composed-tree gate falsely
  refused every legal root. The dual-separator doctrine (ocr40-2/
  ocr41-1, this owner): both separators in the rtrim, the
  spelling-class absoluteness judgment (leading '/', drive-letter
  prefix, UNC), either-separator containment needle. Construction-
  evident; POSIX rides byte-identical.
- **The dev-entry verdict dedupes per name (t31-ocr42-4,
  maintainability:low)** — the violation was pushed per occurrence
  while every neighboring collector implements the one-offense-one-
  line census (t31-ocr27-4/t31-ocr32-5); a byte-duplicated
  dev-segment name answered N identical lines beside its ONE
  duplicate line. A keyed guard at the push site; driven: the
  duplicated entry answers exactly one line (red at HEAD: 2).
- **The corrupt-reopen pin rides the CLEAN row's own exec gate
  (t31-ocr42-5, test:medium)** — the pin drove classifyClean()
  directly, past runState()'s row-level canSpawnChildren() gate
  (t31-ocr20-5), green on a disable_functions host only while the
  corrupt bytes failed ZipArchive::open() before the php -l walk's
  first spawn. The same gate premise now made explicit at the direct
  call; driven: `-d disable_functions=exec,escapeshellarg` answers
  the visible skip where HEAD answered an unearned green.
- **The scratch maker's collision regenerates, never reclaims
  (t31-ocr42-6, test:low)** — the reclaim arm rrmdir'd a
  pre-existing random-suffixed root, reintroducing the
  cross-run-destruction class (2^32 odds per pair) through the
  reclaim arm of the maker that closed it. The suffix rolls until
  free; the foreign tree stands untouched. Construction-evident.
- **The forced-close leg owns the handle (t31-ocr42-7, test:low)** —
  the ZipArchive object stayed live after the seam refused, its
  destructor firing at method-scope teardown past the silencer and
  the scratch release. unset($zip) inside the silencer window, per
  the seam's own never-re-close contract. Construction-evident.
- **The finally's residue sweep never throws (t31-ocr42-8, test:low)**
  — the lens carries the ocr41-3 fence's $this->fail(), and an
  exception from finally REPLACES the in-flight verdict (the
  ocr33-7 class): the diagnostic arm swallows-with-notice now, the
  assertion arms keeping the fence's loud spelling.
  Construction-evident; readable trees sweep byte-identically.

### Fixed (shared — M3 Task 3.1, OCR round 41)

Forty-first OCR-tool round (main 61/61, fully complete): 4 findings,
driver accepts all — tying the loop minimum. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8→4.
The round's shape: the serialized-boundary generation — the r40
collector sweep made the walks work, this round polices what crosses
the SERIALIZED boundary (zip localnames are '/' by spec, never the
walk's native vocabulary); beside it the fake's per-install contract
and the diagnostic helper's own fence. Round 41 answered NEW
findings, so the OCR phase continues per plan. Fixed as
t31-ocr41-1..3 — one commit per finding (the two zip legs one
defect class, one commit), plus this docs record, the full offline
check green after every commit. Suite 1698 → 1699 tests,
46301 → 46305 assertions, 3 skipped unchanged.

- **The zip localnames speak the '/' vocabulary at every serialized
  boundary (t31-ocr41-1, bug:medium ×2, one commit)** — the
  t31-ocr40-2 dual-separator sweep made both collectors work on '\'
  hosts, but the collected `$relative` still carries the iterator's
  native join, and both zip legs spliced it verbatim into the ENTRY
  LOCALNAME (the plugin tree's `$entries[]` and the embed leg's
  destination): on a '\' host every shipped entry name would contain
  '\', breaking the inspector's near-source check and the
  case-insensitive collision key (already '/'-joined from the
  manifest side). One normalization owner (`zipEntryPath()`) answers
  both legs at the seam where `$relative` composes an entry; the
  disk paths beside each seam keep the native vocabulary.
  Construction-evident on POSIX ('/' is the separator — the
  ocr28-3 doctrine).
- **`delete()` retires the install's save counters
  (t31-ocr41-2, maintainability:low)** — `InMemoryTokenStorage`'s
  delete() unset the persisted grant but left `saveCounts` intact,
  so `saveCount()` reported lifetime commits across a
  delete/reinstall rather than per-install ones; the counters retire
  with the grant now (per-install semantics, the class docblock's
  own contract — the encrypted-storage and refresh-coordination
  suites reuse the fake with deletes between phases). Driven:
  save→delete→save answers 1 (red at HEAD: 2); plain save sequences
  unchanged.
- **The residue sweep fences its walk (t31-ocr41-3, test:low)** —
  the freeze-kill pin's `$residueOf()` lens walked an unfenced
  `RecursiveDirectoryIterator` over tests/fixtures/plugins, the
  exact shape both production owners fence (rrmdir t31-ocr33-6,
  copyTree t31-ocr34-2): an unreadable subtree (a stranded chmod,
  an FS/AV lock) died in the SPL vocabulary inside the test's own
  diagnostic helper. The helper fences its boundary now — the
  UnexpectedValueException converts to the test's own failure
  vocabulary naming the path. Construction-evident; readable trees
  sweep byte-identically.

### Fixed (shared — M3 Task 3.1, OCR round 40)

Fortieth OCR-tool round (main 61/61, fully complete): 8 findings in
seven numbered commits (the collector twins — `bin/build.php`'s
`collectFiles()` and `bin/lib/plugin-tools.php`'s
`wp_connectors_php_source_files()`, one defect class — one commit),
driver accepts all. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8→8.
The round's shape: the sixth use-grammar generation — the brace-group
tail with a FAMILY prefix escaping the member grammar by pattern
shape — beside the dual-separator collector twins and the probe-junk
fence (the case probe planting into the judged repository tree).
Round 40 answered NEW findings, so the OCR phase continues per plan.
Fixed as t31-ocr40-1..7 — one commit per finding, plus this docs
record, the full offline check green after every commit. Suite
1696 → 1698 tests, 46282 → 46301 assertions, 3 skipped unchanged.

- **The family-prefix brace tail rides the ONE member grammar
  (t31-ocr40-1, bug:high)** — a group whose prefix is the family
  itself (`use …\Shared\{Clock as self};`, or deeper via the
  sub-segment tail) matches the PLAIN use-statement pattern, not the
  vendor-prefix group pattern below it, and the tail re-emitted
  verbatim beside the rewritten prefix with no member validation:
  compile-error bytes in the zip at exit 0 with every gate green.
  The member grammar is the one owner now (empty/dangling shapes,
  reserved and non-identifier alias, kind strip, leading-backslash
  refusal) and both group seams ride it — the vendor-prefix callback
  (which also rewrites the members' leading Shared segment) and the
  family-prefix return (which never re-spells a member, its prefix
  already carrying the rewrite).
- **The collector twins speak both separator spellings
  (t31-ocr40-2, bug:low ×2, one commit)** — `collectFiles()` and
  `wp_connectors_php_source_files()` stripped their walk root with
  `rtrim($root, '/')` and sliced the below-root relative with a
  '/'-only strip and segment split: on a '\' host every "relative"
  kept its absolute spelling and every downstream judgment
  mis-segmented (dev-entry/near-source logic, extension checks, the
  PSR-4 casing fence). The r31-5 dual-separator doctrine sweeps to
  both twins — the rtrim class list, the substr-past-the-root
  arithmetic, DIRECTORY_SEPARATOR at every below-root segment split;
  POSIX rides byte-identical.
- **The render fast path rides the canonical walk
  (t31-ocr40-3, maintainability:low)** — `utf8_for_safe_render()`'s
  early return probed validity through `preg_match('//u')`, a second
  engine-level spelling of UTF-8 validity beside the canonical
  `utf8_sequence_length_at()` walk (the exact duplication the
  t31-ocr8-8 doctrine exists to close, cited by the method's own
  docblock). The fast path consults `is_standalone_valid_utf8()` now
  — one validator, both consumers; the regex twin deleted.
- **releaseScratch()'s docblock sits above its own declaration
  (t31-ocr40-4, documentation:low)** — the contract rode orphaned
  above `stderrNotice()`'s own docblock, and only the last docblock
  before a declaration attaches: the guarded-release doctrine and
  its caller census were dead text one method up. Each contract is
  attached to its own declaration now.
- **The case probe plants in the volume's SCRATCH representative,
  never the judged tree (t31-ocr40-5, test:low)** — the probe planted
  `wpct-pathcase-*` directly into whichever directory the derivation
  first judged, routinely a REPOSITORY-rooted source tree (copyTree()
  from tests/fixtures/plugins), with only a bare @unlink between: a
  junk window in the repository on every measurement and, for a
  probe whose process died between the two, checkout residue nothing
  reclaims. The anchor is the base itself when it sits under the
  temp root, else the temp root when it shares the volume's device,
  else no plant at all (the r38-2 conservative unmeasured arm).
  Driven the FREEZE-KILL way — SIGSTOP holds the looping child
  inside its plant window, the kill answers the crash residue, red
  at HEAD on the first freeze.
- **The landing join speaks the iterator's own vocabulary
  (t31-ocr40-6, maintainability:low)** — the copyTree() landing loop
  joined `$to . '/' . $relative` two lines under the relativize
  prefix t31-ocr39-4 corrected to DIRECTORY_SEPARATOR, mingling
  vocabularies before dirname()/mkdir()/copy() on a '\' host; one
  spelling both lines now, POSIX byte-identical.
- **The trailing-separator arithmetic pin gains its SCANNER leg
  (t31-ocr40-7, test:low)** — the t31-ocr31-5 dual-separator
  arithmetic exists in two tools, and the scanner's variant lived in
  the lint twin's PROSE only. The new leg drives
  `wp_connectors_scan_paths()` directly with a trailing-separator
  root and a canary under the pruned first segment: the rtrim's
  presence is the drivable class on POSIX (a bare offset eats the
  first byte, the prune misses, the canary finds — verified red at
  the patched seam); the narrowing half is POSIX-judgment-neutral
  (the ocr29-3 residue trade) and the leg's platform gate skips the
  hosts whose vocabulary it serves.

### Fixed (shared — M3 Task 3.1, OCR round 39)

Thirty-ninth OCR-tool round (main 61/61, fully complete): 7
findings from 8 comments (the two inspect-artifact:511 comments one
finding plus the reviewer's own correction), driver accepts all.
Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5→8.
The round's shape: the regression-pin generation — the r38-2 pin
judged on whether it drives its own subject (it did not: the cache
short-circuits before the plant), plus the drive-letter degenerate
survivor and the extractTo escape. Round 39 answered NEW findings,
so the OCR phase continues per plan. Fixed as t31-ocr39-1..7 — one
commit per finding, plus this docs record, the full offline check
green after every commit. Suite 1696 → 1696 tests, 46278 → 46282
assertions, 3 skipped unchanged.

- **The extractTo throw answers the whole-or-not-at-all refusal,
  never an uncaught escape (t31-ocr39-1, bug:low)** — the r12-20
  finally restored the capture handler but owned nothing else, so a
  throw escaped uncaught past the restore, past $zip->close(), and
  past every verdict surface: the CLI died at exit 255 with an
  engine stack trace and no verdict, the test call site aborting its
  whole battery. The catch rides inside the try statement whose
  finally restores the handler — the catch's return runs that
  finally exactly once, no nested finally — and the throw answers
  the same refusal the false return answers, the reason rendered
  through the one printable seam.
- **The whole-path degenerate fence owns the bare drive-letter
  spelling (t31-ocr39-2, bug:low)** — 'C:' survived the fence (rtrim
  keeps it, no tail to strip) and is_dir('C:') is true on Windows:
  the walk would name the drive root, a directory the caller never
  named. The stripped probe refuses the drive-letter root spelling
  set the ocr33-5 root clause already names, judged before any
  is_dir() can follow the spelling; driven red at HEAD through a
  literal 'C:' directory — the walk emptied it.
- **save()'s @return names false's actual meaning (t31-ocr39-3,
  documentation:low)** — the summary claimed 'false when the
  precondition failed', contradicting the contract the docblock and
  @throws state: three of the four precondition classes throw,
  never return false, and the t31-ocr15-2 domain rule reserves false
  for genuine fence verdicts. The @return names that class alone;
  the throwing classes live in @throws only.
- **The landing loop's prefix speaks the iterator's own join
  (t31-ocr39-4, bug:medium)** — the relativize prefix was
  '/'-joined while RecursiveDirectoryIterator joins child pathnames
  through the native separator (the ocr28-8/ocr30-7 doctrine the
  file's own gates state), so on a separator host the prefix never
  matched any pathname and the first leaf tripped the
  cannot-relativize refusal. The prefix derives from
  DIRECTORY_SEPARATOR; POSIX byte-unchanged.
- **The case-vocabulary leg gates itself the sibling way
  (t31-ocr39-5, test:low)** — the battery's one copy leg with no
  isPosixHost() gate: on a '\' host the drive-letter-spelled temp
  base sends the '/SRC' variant into the Windows-absolute-target
  platform refusal before containment ever runs. The gate rides the
  leg, the skip message naming the premise.
- **The suite's STDERR diagnostics answer one stream-resolving
  spelling (t31-ocr39-6, maintainability:low)** — the ocr38-5 skip
  notice was the suite's only bare STDERR-constant writer executing
  in the test process, inconsistent with the ocr38-4 doctrine the
  release guard beside it already kept. WpHarness::stderrNotice() is
  the one writer — php://stderr in every SAPI, an unopenable stream
  degrading silently — and both sites ride it.
- **The ocr38-2 pin drives its own subject (t31-ocr39-7,
  test:high)** — caseProbeAnswer() short-circuits on the per-volume
  cache before any plant is attempted, and the pin's holder sat on
  the volume the battery's earlier legs already cached: in suite
  order the planted-fail arm never ran (the pin vacuous), and on a
  case-insensitive host the cached answer reddened the expectation
  spuriously. The seam unsets the volume's cache key — the plant
  path the only path — and the control re-measures and restores the
  host truth the unset set aside; expectations host-correct on both
  classes, the cache-hit path pinned beside the plant path it
  starved.

### Fixed (shared — M3 Task 3.1, OCR round 38)

Thirty-eighth OCR-tool round (main 61/61, fully complete): 5
findings, driver accepts all. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1→5.
The round's shape: the fold-machinery's own adolescence — the
r35/r36 case-derivation machinery judged on its edges
(unmeasured-answer caching, non-POSIX synthesis composition, the
guard's SAPI contract), plus the first shared/src production finding
in thirty rounds (the Referer/request-side twin of the r12 location
channel) and the mid-test skip that darkens the battery on root CI.
Round 38 answered NEW findings, so the OCR phase continues per plan.
Fixed as t31-ocr38-1..5 — one commit per finding, plus this docs
record, the full offline check green after every commit. Suite 1693
→ 1696 tests, 46253 → 46278 assertions, 3 skipped unchanged.

- **The sensitive-header catalog gains 'referer' and the RFC 7615
  authentication-exchange twins (t31-ocr38-1, security:low)** — the
  'location' entry rode the argument that a redirect query carries
  the RFC 6749 §4.1.2 authorization code, and a Referer value is the
  request-side TWIN of that same channel (the same redirect query,
  echoed by a caller's outbound navigation), yet it rendered verbatim
  through every safe debug form while the response side masked its
  own credential headers; 'authentication-info'/
  'proxy-authentication-info' (RFC 7615, the 401-protection twins of
  the masked 'proxy-authorization' class) join it — none of the three
  composes through the suffix class, so all three ride the one
  catalog. The first shared/src production finding since round 24.
- **The case probe never caches an UNMEASURED answer
  (t31-ocr38-2, bug:medium)** — when the probe plant failed (a
  read-only probe base, ENOSPC, quota) the unmeasured false rode
  into the per-volume cache unconditionally, so on a
  case-insensitive volume one failed plant poisoned every later
  derivation for the rest of the process. A failed plant answers the
  conservative case-sensitive verdict unmeasured now; the cache holds
  only measured answers, a later call with a plantable base
  re-measures.
- **The collapse speaks the anchor's own shape (t31-ocr38-3,
  bug:medium)** — the resolution loop's collapse unconditionally
  prepended '/', an arm that composes only on POSIX; on a separator
  host the folded drive anchor ('C:/repo') became '/C:/repo/sub/dst',
  a spelling no host resolves, the equality and containment verdicts
  then answering over vocabulary noise. The prefix derives from the
  anchor; POSIX byte-unchanged.
- **The release guard resolves the diagnostic STREAM, never the
  CLI-only STDERR constant (t31-ocr38-4, bug:low)** — STDERR is
  defined by the CLI SAPI only, so in any non-CLI SAPI the guard's
  fwrite raised an undefined-constant Error from inside the very
  catch that exists to guarantee the guard never throws, re-opening
  the t31-ocr33-7 verdict-replacement defect. php://stderr answers in
  every SAPI; an unopenable stream degrades silently to no
  diagnostic, never a throw.
- **The unlistable-source leg skips itself, the battery continues
  (t31-ocr38-5, test:low)** — the ocr32-9 leg's probe branch fired
  markTestSkipped MID-TEST, and a skip aborts the entire remaining
  battery: on the root-runner shape (the primary CI runner) the
  self-copy, mirror, alias, file-in-chain, dangling-link, and symlink
  legs plus the source-intact pins never ran, copyTree containment
  coverage going dark exactly where CI rides. The sibling legs' own
  shape gates the leg now, the r35-8 loudness surviving without the
  abort (the skip says so on STDERR).

### Fixed (shared — M3 Task 3.1, OCR round 37)

Thirty-seventh OCR-tool round (main 61/61, fully complete): 1
finding, the lowest count of the whole loop, driver accepts.
Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10→1.
The round's shape: a single-owner IO-seam residue — the loop's
first single-digit-minus-nine round; if round 38 answers 0 findings
or no NEW findings, the OCR phase of the pipeline ends per plan and
/code-review max begins. Fixed as t31-ocr37-1 — one commit (fix +
docs together), the full offline check green after it. Suite 1693
tests, 46253 assertions, 3 skipped unchanged (delta +0).

- **The staging-tree mkdir rides the owned-return idiom
  (t31-ocr37-1, other:low)** — the one filesystem seam left in
  buildPlugin() outside the glm17-16 idiom every sibling seam
  (copyNormalized, writeNormalized, the staging cleanup) already
  spelled: the staging-tree creation ran bare before the try,
  neither @-suppressed nor checked, so a refused mkdir surfaced as
  the engine's own warning and the build refused one seam late at
  the first copy's message naming a plugin file, never the staging
  tree that actually refused. The mkdir is checked now — @ on the
  call, the failed return owned below, the refusal naming the path
  — and the sweep's standing comment ("an unusable dist is the
  mkdir below's loud failure to own") tells the truth it always
  promised. Happy path unchanged.

### Fixed (shared — M3 Task 3.1, OCR round 36)

Thirty-sixth OCR-tool round (main 61/61, fully complete): 10
findings, driver accepts all. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9→10. The
round's shape: the loop's first FIX-INDUCED high (the r35
case-derivation's own pin guaranteed red on the host class that
motivated it); the fifth use-grammar generation; the iterator-fence
sweep finally whole-census; per-volume case folding. Fixed as
t31-ocr36-1..8 — one commit per finding (the walk-fence pair and the
rtrim-parity pair each ONE commit, same class) — plus this docs
record, the full offline check green after every commit. Suite
1692 → 1693 tests, 46241 → 46253 assertions, 3 skipped unchanged.

- **The group-use member grammar refuses the fully-qualified member
  name (t31-ocr36-1, bug:high)** — the grammar validated everything
  AROUND the member name (empty members, dangling/qualified/reserved
  aliases, the kind keyword) while the name rode unjudged: a
  fully-qualified member (`{ \Shared\Clock as C }`) passed every
  check, missed the leaf rewrite, and re-emitted verbatim — and
  beside a rewritten sibling the postcondition saw no family
  reference at all, compile-error bytes shipping at exit 0 (driven
  at HEAD, both escape arms). The engine verdict is DERIVED FIRST
  (php -l refuses the spelling at compile time — a group member
  resolves against the statement's prefix), and the member seam
  refuses it loudly now; the legal relative members ride unchanged.
- **Both tree collectors fence their recursion boundary, the
  iterator-fence census whole (t31-ocr36-2, bug:medium +
  maintainability:low)** — collectFiles() and
  wp_connectors_php_source_files() were the last two
  RecursiveDirectoryIterator walks with no UnexpectedValueException
  conversion: a chmod-000 child mid-tree aborted each descent in the
  SPL iterator's own vocabulary (red at HEAD, both walks). Both
  seams fenced the ocr33-6 way (construction inside the try, the
  abort converted to each walk's own named refusal, per-entry
  verdicts passing untouched), with the census comment naming every
  walk in the change set and its one standing exception (the secret
  scanner's scan_paths walk).
- **The below-root offsets spell the sibling's dual-separator strip,
  the comments telling the truth again (t31-ocr36-3,
  maintainability:low ×2)** — both scanners' offset comments claimed
  rtrim parity with a sibling that had moved to
  rtrim($root, '/\\') in t31-ocr31-5; the four sites (three FAIL
  sites of the unused-import scan and lint-php's exclusion strip)
  adopt the dual-separator class, byte-identical arithmetic on
  POSIX.
- **The relative arm folds the backslash-spelled target before the
  cwd-prepend on non-POSIX hosts (t31-ocr36-4, bug:medium)** — the
  platform gate refused only the ABSOLUTE drive/UNC spellings, so a
  backslash-spelled relative target mingled vocabularies
  ('C:\repo/a\src\dst') into the containment walk. Both the relative
  spelling and the cwd fold through the one comparison-vocabulary
  owner before the prepend (the landing keeps the caller's bytes);
  identity on POSIX.
- **The case verdicts derive per volume (t31-ocr36-5, bug:low)** —
  the r35 fold consulted one host-wide answer probed exclusively in
  temp, but case resolution is a per-VOLUME property and the suite's
  copyTree shapes span volumes (derivation: macOS case-sensitive
  APFS beside the case-insensitive system volume; the inverse one
  TMPDIR redirect away). One probe core plants in a directory on the
  volume judged (cached by its stat() device id), each containment
  side folds through its own volume's answer; single-volume hosts
  ride byte-identical.
- **The case-variant refusal arm proves the RESOLUTION
  (t31-ocr36-6, bug:high)** — the r35 leg's nothing-lands pin was a
  GUARANTEED RED on its own motivating host (macOS default APFS:
  the variant spelling resolves to the staged source file), the
  loop's first fix-induced high. Both arms prove what is true on
  each host: the resolution premise on case-insensitive hosts (the
  file answering the variant spelling; nothing landing through a
  name staged nowhere), the real second tree unchanged on
  case-sensitive ones.
- **classifyClean()'s @param names the needs_posix flag
  (t31-ocr36-7, documentation:low)** — the row-shape annotation now
  agrees with states()' @return and runState()'s @param.
- **Two whitespace-only lines removed (t31-ocr36-8, style:low)** —
  the only two in the copy battery.

### Fixed (shared — M3 Task 3.1, OCR round 35)

Thirty-fifth OCR-tool round (main 61/61, fully complete): 9
findings, driver accepts all — one of them refuted in-round at its
premise (the r21 doctrine, driven before the fix landed). Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9. The
round's shape: the case-variant keyword axes (the fourth generation of
the use-grammar family, this time in the keyword spelling); the \R
line-class twins the r33 detector close left in the test file's own
readers; the vacuous-green silent skip. Fixed as t31-ocr35-1..8 — one
commit per finding (the \R twins as ONE commit) — plus this docs
record, the full offline check green after every commit. Suite 1690 →
1692 tests, 46200 → 46241 assertions, 3 skipped unchanged.

- **All four use-grammar keyword axes ride the engine's
  case-insensitivity (t31-ocr35-1, bug:medium)** — the r7-9 census
  named two of four axes while `as`, `function`, and `const` rode
  unnamed: the use-statement patterns matched every keyword byte-exact
  lowercase, so `use …\Shared\Clock AS Alias;` and `use FUNCTION …`
  refused at the postcondition's anonymous seam. The four axes match
  through scoped (?i:…) groups at every pattern seam (plain statement,
  group prefix, member kind), the keyword casing riding the output
  verbatim while the family NAME keeps its byte-exact matching and its
  refuse-named label; the classifier's keyword label is deleted with
  its dead errand.
- **The manifest prune reclaims the pruned entry's checksum sidecar
  (t31-ocr35-2, maintainability:low)** — a zip deleted out-of-band left
  its dist/<zip>.sha256 standing beside the dropped line, a checksum
  naming a non-standing artifact. The reclaim fires inside the merge
  lock, fenced to a plain file name beside the manifest (never a
  traversal-woven name, never a link); standing entries' sidecars
  untouched.
- **The heredoc/nowdoc classification reads the quote delimiters
  (t31-ocr35-3, bug:low — premise refuted in-round)** — the finding's
  apostrophe-bearing label (<<<"E'OT") is a parse error at the opener
  (labels are identifiers; 0x27 is not a label byte), and the legal
  high-byte quote-lookalikes never trip an ASCII strpos: driven at
  both legs before the fix landed. What survives: the delimiter
  reading (the engine's own rule) and opener-spelling pins holding
  every legal opener to the engine's verdict, nowdoc resolving nothing
  while every heredoc opener resolves escapes.
- **The self-containment walk's docblock documents its deliberate
  RuntimeException (t31-ocr35-4, documentation:low)** — the scan-root
  boundary guard's @throws tag, the condition and the channel named.
- **The containment verdicts speak the host's DERIVED path-case
  vocabulary (t31-ocr35-5, bug:low)** — macOS resolves a case-variant
  target to the source tree while passing every isPosixHost() gate;
  the byte-wise verdicts were wrong exactly there. One probe owner
  (isCaseInsensitivePathHost, the isPosixHost shape) derives the
  host's answer and a sibling fold arm beside
  posix_comparison_vocabulary carries the comparison — the identity on
  this case-sensitive runner, pinned green-both-sides.
- **The sweep-side line lenses count only the tokenizer's terminators
  (t31-ocr35-6, bug:low ×2)** — numberedLines() and the whole-file
  diagnostic's two derivations matched PCRE's broader \R, so a \v/\f
  byte inflated every reported line after it; all three spell the
  exact three (\r\n, \r, \n), pinned by the vertical-tab drift fixture
  across all three lenses.
- **Every scratch-tree mkdir setup asserts its own landing
  (t31-ocr35-7, maintainability:low)** — five setups, eight call
  sites, the t31-ocr29-10 staging doctrine: a failed mkdir answers a
  staging message, never the downstream verdict's vocabulary.
- **The chmod-0000 leg skips loudly on root runners
  (t31-ocr35-8, test:low)** — the readable branch once restored 0755
  and rode green with the ocr32-9 regression pin never having run; the
  skip is visible now, the root-runner premise named.

### Fixed (shared — M3 Task 3.1, OCR round 34)

Thirty-fourth OCR-tool round (main 61/61, fully complete): 9
findings, driver accepts all. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9. The
round's shape: the twin-sweep generation — every r33 close had missed
an owner (the root fence's third owner, the copyTree recursion twin,
the one-guarded-consumer release), and each lands at the owner the
doctrine already names; beside it the Win32-premise family in
BuildSeamPropertyTest, and the super-root spelling finally lands at
the guard itself after r29-6 derived the host's answer. Fixed as
t31-ocr34-1..7 — one commit per finding (the three Win32-premise row
classes as ONE commit) — plus this docs record, the full offline
check green after every commit. Suite 1689 → 1690 tests, 46192 →
46200 assertions, 3 skipped unchanged.

- **The inspector's root fence owns the drive-root spelling
  (t31-ocr34-1, bug:medium)** — round 33 taught the container class
  to the harness's one predicate but this third owner was not swept:
  `wp_connectors_inspect_rrmdir('C:\')` passed the fence (realpath's
  raw answer over a drive root is 'C:\', never '/') and the walk
  emptied THE DRIVE ROOT's children, the t31-ocr10-1 shape the fence
  exists to kill. The fence judges the container class — the answer
  folded through the separator vocabulary and refused as '/' or a
  drive-letter root — with ONE census comment naming all three owners
  of the universal-container class. Construction-evident; the '/'
  refusal rides unchanged.
- **The copy walk fences its recursion boundary (t31-ocr34-2,
  bug:medium)** — the twin of the t31-ocr33-6 fence the removal walk
  gained, and the r33 ledger's own residual line: a chmod-000 child
  mid-tree passed hasChildren() on stat alone and the iterator died
  in the SPL vocabulary (driven red at HEAD) while the ocr32-9 gate
  probes only the source ROOT. The construction rides the try, the
  abort converts to the harness's refusal with the SPL message riding
  parenthetically, and the per-entry ocr30-4 refusals pass the fence
  untouched; regression gated by the opendir probe (the t31-ocr4-1
  doctrine).
- **The universal-container predicate owns the super-root spelling
  (t31-ocr34-3, bug:medium)** — POSIX leaves EXACTLY two leading
  slashes implementation-defined and the SysV-lineage libcs preserve
  realpath('//') as '//', so there the '/' compare alone let the
  resolved answer past and `copyTree('//', …)` walked the root as a
  copyable source. The predicate DERIVES the host's own realpath
  ('//') probe answer (never the literal, the r29-6 doctrine — the
  same both-answers acknowledgment the HarnessCopyTreeTest
  precondition carries, now answered by the guard itself); on this
  engine the probe collapses to '/' (driven), the arm subsumes into
  the '/' compare, and the '//' source leg pins the contract for the
  preserving host exactly the way the 'C:/' spelling does.
- **The guarded-release sweep reaches every rrmdir caller
  (t31-ocr34-4, bug:medium)** — the t31-ocr33-7 doctrine was applied
  to exactly ONE consumer while every pre-existing caller invoked
  rrmdir() bare, so a cleanup-time throw landed as an uncaught
  exception wearing the caller's frame. ONE owner —
  WpHarness::releaseScratch(), hoisted beside rrmdir() with the
  census naming every caller battery — serves every release call
  site (startup reclaims, mid-phase removals, every finally) across
  the nine batteries, HarnessCopyTreeTest's private twin deleted in
  the same sweep; rrmdir() keeps its loud contract and its
  under-test callers stay bare by design.
- **The Win32-premise gates ride the chmod-0000 and
  trailing-edge-junk rows (t31-ocr34-5, test:medium ×3, one
  commit)** — on Win32 chmod(0000) sets the read-only attribute only
  (reads succeed, the LOUD row fails as a phantom "run succeeded
  where it must refuse"), the namespace strips trailing dots/spaces
  and rejects control bytes (the staged near-source lands a DIFFERENT
  valid file), and libzip still reads the read-only staged source
  (the forced-close leg's assertNotNull reds as a phantom finalization
  defect). The needs_posix row flag consults the ONE platform owner
  at runState()'s head, the forced-close leg carrying its inline twin
  above the archive's creation.
- **The embedded-vs-sources comparison speaks one separator
  vocabulary (t31-ocr34-6, test:medium)** — zip entry names are
  always '/'-joined while getPathname() joins through the host
  separator, so on a non-POSIX host every stripped tail kept
  backslash joins and the completeness verdict judged separator noise
  (a permanent phantom FAIL). Both sides of the strip fold first;
  identity on POSIX where a legal '\' filename byte stays.
- **One backslash-authority leg restyled to the file's PSR style
  (t31-ocr34-7, style:low)** — the single test method written in
  WordPress coding style while the file and siblings use PSR;
  whitespace only, verdicts byte-identical.

### Fixed (shared — M3 Task 3.1, OCR round 33)

Thirty-third OCR-tool round (main 61/61, fully complete — the first
complete round since the BuildArtifactsTest ceiling issue): 10
findings, driver accepts all. Trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10. The
round's shape: the hard-keyword census hole — soft keywords lex as
T_STRING, hard keywords lex as their own token ids, a two-token-class
distinction no fourteen-entry enumeration can own; and the drive-root
family — the universal-container refusals were POSIX-spelling-only
and `rrmdir('C:\')` walks the drive root. Fixed as t31-ocr33-1..8 —
one commit per finding — plus this docs record, the full offline
check green after every commit. Suite 1686 → 1689 tests, 45867 →
46192 assertions, 3 skipped unchanged.

- **The reserved-alias census derives the HARD keyword half from the
  engine (t31-ocr33-1, bug:high)** — the round-32 owner enumerated
  only the fourteen spellings that lex as plain T_STRING (self/
  parent, the three literals, the type keywords), while every keyword
  that lexes as its OWN token id (`array`, `fn`, `list`, `if`,
  `foreach`, `function`, `class`, `new`, `match`, `readonly`, …)
  matched the alias grammar's identifier-byte check and shipped
  parse-error bytes in the zip at exit 0 (driven at HEAD: `use …
  \Shared\Clock as array;` rewrote the family and re-emitted the
  alias beside it). A php -l oracle over the whole keyword table
  refused every own-token keyword in the slot and accepted none, so
  the hard half is DERIVED AT RUNTIME — the candidate is tokenized
  in the alias position and anything the lexer does not spell T_STRING
  there is reserved — the one spelling of the class that cannot drift
  from the engine; both re-emit seams consult the ONE owner, and a
  gated oracle leg drives the whole class against `php -l` itself.
- **The text lens's line counter spells the tokenizer's exact
  terminator class (t31-ocr33-2, bug:low)** — PCRE `\R` also matches
  \v, \f, and \x85 while `token_get_all()` counts only \n, \r\n, and
  a lone \r, so a \v/\f byte in an earlier string literal inflated
  every line the lens reported after it (red at HEAD: a line-3
  docblock reported line 5). The counter spells the exact three.
- **Three behaviorally-dead catalog entries drop from the
  sensitive-header list (t31-ocr33-3, maintainability:low)** —
  'authorization' is an exact member of the suffix class and
  'proxy-authorization'/'x-api-key' end in '-authorization'/
  '-api-key', so all three rode the class identically (the
  t31-ocr29-5 subsumption doctrine over the catalog's own entries);
  the catalog keeps only what the class cannot spell.
- **The link probe folds BEFORE the tail strips (t31-ocr33-4,
  bug:medium)** — on a '\' host the tails arrived backslash-spelled
  ('\..', '\.', trailing '\') while the strips judge '/'-spelled
  tails only, so they never matched and the anchor walk judged the
  wrong chain; the ocr32-8 fold landed one operation too late to
  serve the strips it sits beside. Construction-evident; POSIX rides
  byte-unchanged.
- **The universal-container refusals own the drive-root spelling
  (t31-ocr33-5, bug:medium)** — the root-collapse guards compared '/'
  against realpath()'s answer alone, dead on a '\' host twice over:
  rrmdir()'s `$dir_real` was the RAW (never folded) answer, and even
  folded a drive root answers 'C:/', never '/' — so `rrmdir('C:\')`
  passed every probe and the walk deleted THE DRIVE ROOT's children
  (the t31-ocr10-1 shape the guard exists to kill), the copy twin
  reading the same container as a copyable source or an
  every-source-containing target. ONE owner (resolvesToUniversalContainer,
  the comparison folded through posix_comparison_vocabulary) serves
  rrmdir's refusal, copyTree's source-side refusal, and the mirror
  clause. Construction-evident; the '/' spellings still refuse.
- **The removal walk fences its recursion boundary (t31-ocr33-6,
  bug:low)** — hasChildren() passes on stat alone, so an unreadable
  SUBDIRECTORY mid-tree still died in the SPL iterator's own
  UnexpectedValueException (the r30-3 class the lint gate closed,
  the harness twin; the ocr32-9 gate probes only the source ROOT).
  The construction rides the try, the abort converts to the
  harness's refusal with the SPL message riding parenthetically (it
  names the path), and the per-entry ocr32-7 refusals pass the fence
  untouched.
- **The copy battery's scratch cleanup is guarded at every finally
  (t31-ocr33-7, test:medium)** — PHP REPLACES (never chains) an
  in-flight exception when finally throws, so an environmental
  cleanup failure superseded the test's real verdict; one owner
  (releaseScratch) surfaces the failure on STDERR and lets the
  verdict ride, the planted-throw leg driving the guard itself
  (rrmdir over '/' as the deterministic release failure).
- **Staging hygiene battery-wide (t31-ocr33-8, test:low)** — every
  stage write is asserted through one owner (the t31-ocr29-10
  doctrine swept whole-file), so a full disk or unwritable temp
  answers a staging error, never a misleading downstream verdict.

### Fixed (shared — M3 Task 3.1, OCR round 32)

Thirty-second OCR-tool round (main 61/62 with 10 findings per the
round doc; BuildArtifactsTest OCR-unreachable — context compression
kills it deterministically even solo at the 200k ceiling, so its test
legs ride the later claude-glm code-review phase): driver triage
accepted all — trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10. The round's
shape: the ALIAS-GRAMMAR FAMILY — the round-31 member grammar
validated members but not their ALIASES, and the engine-illegal alias
class leaked through three different re-emit seams in one file; the
fence lesson in its fifth generation: a grammar close at one seam
never closes the class at the re-emit seams. Beside it, the
removal-IO pair (the ocr30-4 two-way escape closed at both removal
twins) and the collectors' per-name dedup. Fixed as t31-ocr32-1..9 —
one commit per finding — plus this docs record, the full offline
check green after every commit. Suite 1683 → 1686 tests, 45838 →
45867 assertions, 3 skipped unchanged.

- **The alias grammar rejects what the engine rejects, at every seam
  that re-emits an alias (t31-ocr32-1/2/3, bug:medium ×3 — one census
  comment, one reserved-vocabulary owner)** — the optional alias
  capture of the use-statement pattern re-emitted any identifier
  spelling verbatim, including the fourteen engine-illegal ones
  (`as self`, `as true`, `as int`, … case-insensitively; php
  -l-derived) the relative-path alias state already refuses; the
  group-use member callback re-emitted its extracted identifier
  unvalidated; and a member whose `as`-tail the identifier grammar
  could not spell (`{Shared\Clock as Foo\Bar}`) fell through with the
  whole member flowing into the leaf rewrite, the qualified tail
  shipped verbatim — plus the rider composition (`use … as X {Y};`,
  alias and brace-group tail together) the pattern once matched and
  re-emitted whole. All four channels shipped compile-error bytes in
  the zip at exit 0 with every gate green (driven at HEAD). One
  owner (`aliasIdentifierIsEngineIllegal()`, the relative walk's
  hand-rolled list folded into it) now serves every seam; the member
  extraction matches any `as`-tail case-insensitively (`AS` is a
  legal keyword spelling that keeps riding) and judges the tail's
  shape; the rider composition refuses at the seam.
- **The inspector removal walk owns its per-entry IO returns
  (t31-ocr32-4, security:low)** — the per-entry rmdir()/unlink()
  calls ran unchecked over the extracted hostile tree, every path
  interpolated into an engine warning archive-controlled — the one
  diagnostics channel left off the printable seam: a stranded
  0555/0444 shape or removal race answered with a RAW E_WARNING
  (under failOnWarning PHPUnit's vocabulary, outside it raw bytes).
  The failed return answers the named refusal through the printable
  seam; the callsites own the conversion (the pre-extraction reclaim
  converts to a violation, the teardown finally degrades silently —
  its t31-ocr23-2 contract, a rethrow there replacing the artifact
  verdict in flight).
- **First-verdict-wins per name at the traversal/near-source
  collectors (t31-ocr32-5, bug:low)** — the byte-duplicate fence does
  not `continue`, so every COPY of a hostile name pushed its own
  identical violation line (driven at HEAD: three copies answered
  three lines). The collectors are keyed per name; one offense, one
  line (the t31-ocr27-4 doctrine at this collector).
- **The unused-import scan's file-naming rides the rtrim parity at
  all three FAIL sites (t31-ocr32-6, maintainability:low)** — the
  exact spelling lint-php.php replaced in this same update
  (t31-ocr14-4); one sweep, construction-evident (both scan roots are
  trailing-separator-free today).
- **The harness removal walk owns its IO returns
  (t31-ocr32-7, bug:medium)** — the exact two-way escape ocr30-4
  closed for the copy twin, one owner over: WpHarness::rrmdir()'s
  per-entry calls and its final root rmdir answer the loud policy
  refusal naming the path, never the engine's raw warning; the tree
  is reclaimed or refused loudly.
- **The link-probe anchor comparisons speak one vocabulary
  (t31-ocr32-8, bug:medium)** — the carry chain is '/'-joined while
  on a non-POSIX host both anchors answer backslash-spelled, so the
  prefix compares could never match and the probe answered the HOST,
  never the path. The path and anchors fold through the one
  comparison owner (posix_comparison_vocabulary, the ocr29-4 arm)
  off-POSIX; the POSIX host rides the identity, byte-unchanged; a
  drive-letter absolute joins the '/'-rooted arm (the ocr28-3
  predicate).
- **The copyTree source gate probes readability, not just kind
  (t31-ocr32-9, bug:low)** — an existing unlistable directory (mode
  0000, or a missing search bit) passed is_dir() and died in the SPL
  constructor's vocabulary; the gate probes opendir — the exact
  capability the iterator's own construction needs — and answers the
  policy refusal naming the path (driven red at HEAD: the engine's
  words where the harness refusal belongs).

### Fixed (shared — M3 Task 3.1, OCR round 31)

Thirty-first OCR-tool round (main 61/62 with 9 findings; BuildArtifactsTest
OCR-unreachable — context compression kills it deterministically even
solo at the 200k ceiling, so its test legs ride the later claude-glm
code-review phase): 9 findings, driver triage accepted all — trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9. The round's shape:
the heredoc re-entry (a state machine that tracks one open construct must
own the NESTED spelling of that construct — the lexer stack genuinely
produces it); the member-grammar pair (two owners, the member-start walk
and the group-use callback, each accepted engine-rejected member
spellings — one doctrine: the grammar rejects what the engine rejects);
and one engine-premise refutation driven at fix time (the hex-arm case
claim — the engine decodes `\X` exactly like `\x`, and the unescaper
already answered the engine's own bytes). Fixed as t31-ocr31-1..9 — one
commit per finding — plus this docs record, the full offline check green
after every commit. Suite 1681 → 1683 tests, 45821 → 45838
assertions, 3 skipped unchanged.

- **The heredoc text-lens state is a stack (t31-ocr31-1, bug:high)** —
  the lens's four scalars tracked ONE open construct with no re-entry
  guard, but the lexer genuinely produces a heredoc nested inside the
  outer body's interpolation (`{$a[<<<K … K]}`, tokenized and driven on
  this engine): the inner open clobbered the outer's chunks (lost with
  no flush of their own), its dynamic mark, and its offsets. Every
  nesting level carries its own frame now; the label closes the
  innermost open, EOF flushes every frame still open (the round-28 EOF
  doctrine holds for every stack level). Driven red at HEAD: the
  outer-head finding dropped while the nested body's survived.
- **The hex-arm case premise driven and refuted (t31-ocr31-2,
  bug:medium, the refuted-premise shape)** — the finding claimed the
  engine resolves only lowercase `\x`; driven at fix time on the runner
  engine (8.5.10), `"\X41"` computes 'A' exactly like its lowercase
  twin, so the unescaper already answered the engine's own bytes and
  the demanded narrowing would have invented the divergence it accused.
  The branch carries the engine truth; the battery drives the engine
  itself as the oracle over both spellings and every tail.
- **A separator consumed by the member grammar owes a name
  (t31-ocr31-3, bug:medium)** — the member-start separator branch
  consumed '\' unconditionally with no state recording
  separator-consumed-with-no-name, so the double separator and the
  dangling separator (before the terminator, comma, or alias) shipped
  verbatim beside the rewritten name at exit 0. The lexer bakes every
  legal separator into the member's own name tokens, so a bare '\' is
  always a doubled or dangling spelling; each refuses with the
  engine's own verdict, and the legal fully-qualified member (one
  baked token) keeps riding.
- **The group-use member grammar is validated before reassembly
  (t31-ocr31-4, bug:medium)** — the member callback reassembled bytes
  through explode/trim/implode with no refusal of its own, so the
  empty member, the trailing comma, the empty body, and a dangling
  `as` normalized into a silent pass that shipped the parse-error
  spelling at exit 0 (the empty body alone reached a late, mis-named
  postcondition refusal). Each shape refuses at the seam, naming the
  spelling; the survivors battery's round-10 empty-body row moved with
  it, its charge holding one seam earlier.
- **The scan_paths root normalization strips both separator spellings
  (t31-ocr31-5, bug:low)** — rtrim consulted only the native
  separator, so a '/'-suffixed root on a separator host kept its
  trailing byte in the prefix arithmetic while the iterator treats '/'
  as a separator there too. The strip judges the spelling class, never
  the host (the round-29 vocabulary doctrine); POSIX pays residue only
  for a path literally named with a trailing backslash byte.
- **The redaction rebuild has ONE owner (t31-ocr31-6,
  maintainability:low)** — the masked view's verification-uri rebuild
  hand-duplicated HttpRequest's redacted_url() construction with
  sameness asserted only by docblock prose. Url::redacted() glues the
  shape for both consumers (HttpRequest's now-callerless wrapper
  deleted); a construction-evident pin asserts byte-equality between
  the two consumers' outputs over the credential-carrying URI.
- **The braced-declaration brace-offset seeds from the running counter
  (t31-ocr31-7, performance:medium)** — the ledger re-summed every
  token length from index 0 per braced namespace, O(file) each and
  O(K²) over K declarations, while the running counter already carried
  the cumulative length through the keyword. Offsets byte-identical
  across the fix (driven before/after over a three-braced-declaration
  file).
- **The non-directory arm pins the guard's own class exactly
  (t31-ocr31-8, test:medium)** — refusalOf()'s family match would
  accept the iterator's UnexpectedValueException; the planted
  regression is driven: today the lazy-iterator fence answers it one
  seam earlier (no-throw verdict), and the exact-class assertion
  closes the family channel against the day the fence or guard
  re-shapes.
- **The in-process cli_args pin leaves the exec gate (t31-ocr31-9,
  test:low)** — a plain $GLOBALS read that spawns no child rode the
  spawn-bearing battery's capability skip and silently skipped on
  disable_functions hosts; its own method rides no gate and answers
  everywhere the suite runs.

### Fixed (shared — M3 Task 3.1, OCR round 30)

Thirtieth OCR-tool round (main 61/62 with 7 findings; BuildArtifactsTest
OCR-unreachable — context compression kills it deterministically even
solo at the 200k ceiling, so its test legs ride the later claude-glm
code-review phase): 7 findings, driver triage accepted all — trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7. The round's shape:
the trait-adaptation ';' — the r26-1 trait-fence class's third
generation, this time corrupting the frame stack from INSIDE the
adaptation block, with the driven red at the fence's worst case (a
silent trait retarget at exit 0); and the scan_paths residual head
landed (the lint walk fenced). Fixed as t31-ocr30-1..7 — one commit
per finding — plus this docs record, the full offline check green
after every commit. Suite 1676 → 1681 tests, 45776 → 45821
assertions, 3 skipped unchanged.

- **The use-statement state survives a trait-adaptation body
  (t31-ocr30-1, bug:high)** — the adaptation's grammar-required ';'
  (`use T { m as n; }`) fired the use-statement boundary reset
  mid-adaptation, the adaptation's closing '}' then popped the
  enclosing class's 'other' frame off the brace-kind stack, and every
  use statement after the class drew its trait fence from the
  corrupted stack: driven red at HEAD, `use T { m as n; } use
  namespace\Clock\SystemClock;` inside a class body shipped the second
  use spliced through the family map — a different trait loads, exit
  0. The adaptation-inner ';' rides the adaptation's frame, the '}'
  that closes it IS the trait use's terminator, and the stack stays
  balanced through the block and beyond; the commit carries the census
  over every other ';' consumer of the one boundary owner.
- **The unowned-spelling classifier's brace stack survives a trait
  adaptation too (t31-ocr30-2, bug:low)** — the same early-close in
  the classifier walk let the adaptation's '}' pop the class's frame
  with no matching push, and a trait clause list after the adaptation
  was judged an IMPORT (the dead 'write one use per line' errand the
  t31-ocr7-7 doctrine reserves for import lists); same frame doctrine,
  both twins on the anonymous trait verdict, genuine import lists keep
  their named errand. Driven red at HEAD.
- **The lint walk names an unreadable subdirectory instead of dying
  uncaught (t31-ocr30-3, bug:medium)** — a directory entry the walking
  process cannot open aborted the bare RecursiveDirectoryIterator walk
  with an uncaught UnexpectedValueException: the whole lint run died
  at exit 255 with a stack trace, no verdict, no summary. The fence is
  the repo's own iterator doctrine (the construction rides the try,
  the abort converts to the gate's FAIL vocabulary naming the root,
  the readable trees' partial count stays loud, the exit fails); the
  regression leg rides a permission-denial probe in the canSymlink
  shape. The scan_paths walk-unfenced residual stands one seam deeper
  (the secret scanner's own walk).
- **The copyTree landing loop owns its IO returns (t31-ocr30-4,
  bug:medium)** — unchecked mkdir()/copy() let a mid-landing failure
  escape the contract two ways: under PHPUnit the raw E_WARNING wore
  PHPUnit's vocabulary (driven: the refusal read 'mkdir(): Permission
  denied'), outside it the raw warning rode while copyTree() returned
  normally having moved nothing. Both returns refuse loudly naming
  the operation and the path (the builder's copyNormalized shape);
  each arm driven by its own permission-shaped leg, the happy path
  byte-unchanged.
- **The relative member past the comma routes through the main loop
  in BOTH lexer spellings (t31-ocr30-5, documentation:low — derived
  first)** — the routing comment's claim held for the fused spelling
  only; the interrupted member (a bare T_NAMESPACE at member-start)
  fell to the empty-member refusal, mis-naming a member that is
  there. Driven adjudication: refusing is the intended verdict on
  every route, so the routing extends to the bare keyword and both
  spellings answer the SAME mid-name refusal (one shape for both
  spellings of the operator, the r11-10 doctrine).
- **The apply closure's throws are row verdicts, never battery aborts
  (t31-ocr30-6, test:medium)** — the one row-verdict channel in the
  build-seam battery without its try/catch conversion let one row's
  throw abort the whole row table as a test error, masking the states
  behind it; the throw converts to a FAIL row naming the class and
  message (the extra channel's own doctrine shape), driven by a
  planted-throw leg.
- **The symlink-shapes battery rides the platform gate its siblings
  carry (t31-ocr30-7, test:low)** — the only filesystem battery in its
  file without the isPosixHost() gate (the t31-ocr22-2/t31-ocr28-8
  doctrine); the gate rides naming its own premise,
  construction-evident, no count moves.

### Fixed (shared — M3 Task 3.1, OCR round 29)

Twenty-ninth OCR-tool round (the union complete: main 61/62 with 11
findings; fill-in over 3 files with 2 — BuildArtifactsTest unchanged
since r27 and fully audited in r28's 62/62 run): 13 findings, driver
triage accepted all — trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13. The round's shape:
the print_r/__debugInfo ENGINE TRUTH driven to an inverted verdict —
the finding's premise (print_r walks the raw property table) was the
fabrication, refuted on the runner engine AND the 8.2 support floor;
the platform-separator family's THIRD generation (tails, containment
comparisons, patch spellings, the super-root precondition); and the
fill-in operational note — BuildArtifactsTest now exceeds the default
per-group token ceiling, so future fill-ins need `--max-tokens 64000`.
Fixed as t31-ocr29-1..10 — one commit per finding, the three
same-class pairs sharing one commit each — plus this docs record, the
full offline check green after every commit. Suite 1676 tests
unchanged, 45764 → 45776 assertions, 3 skipped unchanged.

- **The print_r engine premise driven and refuted on BOTH engines in
  range (t31-ocr29-1, security:medium — premise refuted in-round)** —
  the finding claimed print_r() "does NOT consult __debugInfo() (only
  var_dump does)" and renders both tokens in full cleartext; driven at
  fix time, print_r() answers the hook's masked view on the runner
  engine (8.5.10) and on the support floor (8.2, a php:8.2-cli
  container over the very class) — the two functions walk objects
  through the engine's ONE get_debug_info handler, and the claimed
  channel split constructs on no engine in the support range. The
  demanded "red at HEAD" was undrivable (the r11-5 pin has driven
  print_r masked since round 11); the close is the driven
  adjudication — the docblock carries the engine truth, the pin
  drives BOTH channels explicitly, and the one TRUE exclusion is
  pinned by its adjudicated shape: var_export() dumps the raw
  property tree through no hook (the t31-ocr2-1 display-material
  adjudication, its eval channel refusing).
- **The exact family ROOT is a member, never a "SIBLING"
  (t31-ocr29-2, bug:medium)** — the relative-use family check tested
  only the prefix-with-separator form, so `use namespace\WpConnectors
  \Shared;` under `namespace Deicod;` passed the vendor check, missed
  the family one, and refused with the SIBLING message — a member
  answered with the one verdict it cannot earn. The root rewrites to
  the REWRITTEN root now, the empty below-root tail never riding the
  prefix form's separator (which would ship a trailing-backslash
  parse error); the aliased twin keeps its alias, and everything
  outside the root keeps its refusal. Driven red at HEAD.
- **The inspector's tail-strip probe fences BOTH separator spellings
  (t31-ocr29-3, security:low)** — the loop fenced only '/'-spelled
  tails, so a backslash tail ('dir\..', the native spelling where '\'
  joins paths) survived the probe and the walk rode the raw spelling
  into the territory the tail names — the owner's own "every tail
  spelling" doctrine one separator short. The strip loop, the
  trailing-separator rtrims, and the fences judge both vocabularies:
  the fence judges the spelling CLASS, never the host it runs on
  (residue for a pathological POSIX filename beats the parent walk
  the same bytes ride elsewhere). Driven red at HEAD on this POSIX
  host through a real backslash-named tree; the Windows-native walk
  is construction-evident (DIRECTORY_SEPARATOR).
- **copyTree's containment verdicts compare in ONE separator
  vocabulary (t31-ocr29-4, bug:medium)** — the verdicts join their
  needles with '/' while realpath() answers in the host's own
  vocabulary, so on a separator host containment judged vocabulary
  noise (always-refuse or always-pass, the collapse folding the
  anchor into one giant segment). A private normalizer beside the
  platform owner (isPosixHost(), the ocr28-3 doctrine's comparison
  arm) is consulted at the two realpath consumers the verdicts read;
  the POSIX host rides the identity, byte-identical —
  construction-evident, no new leg.
- **'auth-token' leaves the suffix class: subsumed by 'token' every
  name it matched (t31-ocr29-5, maintainability:low)** — the round-15
  entry was behaviorally dead weight (every '…-auth-token' ends in
  '-token' and rode the round-24 entry identically); the docblock
  states the subsumption where the rule lives, and the battery's
  auth-token spellings ('x-auth-token', 'Auth-Token', present since
  round 15) ride 'token' green by construction — drop 'token' and
  they redden.
- **The degenerate-root precondition derives the host's two-slash
  shape (t31-ocr29-6, bug:high test-fence)** — the safety net pinned
  `realpath('//') === '/'`, but POSIX leaves exactly two leading
  slashes implementation-defined (libcs may preserve the super-root
  spelling) while the production guard is LEXICAL and fires on every
  host regardless; the precondition now asserts what the leg needs —
  the spelling resolves to the filesystem ROOT itself, in either
  spelling, never a real work tree. Green on super-root and collapse
  hosts by construction; a driven /tmp probe documents this host's
  shape ('/' — collapse).
- **The ungated POSIX-premise pair gates visibly
  (t31-ocr29-7, bug:medium ×2, ONE commit)** — the
  trailing-separator-root leg's patch spells '/' while the production
  offset strips DIRECTORY_SEPARATOR (on a separator host the offset
  re-eats the first byte the leg pins), and the exclusion-fold battery
  judges DIRECTORY_SEPARATOR-exploded relatives over '/'-composed
  trees (never splits there). Both consult the ONE platform owner and
  skip naming their own premise — the file's first platform gates.
- **The staging-assertion pair: the read twin and the vacuous-pass
  twin (t31-ocr29-8, test:low ×2, ONE commit)** — the trailing-root
  leg's tool read was the one staging READ unasserted (a bare
  (string) cast flowed '' downstream, the patch verdict wearing the
  read failure), and the conventions battery's planted dead-import
  write was the one staging WRITE unasserted (a silent false re-runs
  the clean tree, the gate exits 0, and the planted verdict passes
  vacuously). Both assert at the site, staging failing as staging.
- **The PHPUnit-less child leg's harness-path block re-indented
  (t31-ocr29-9, style:low)** — one indent level too deep (the
  copy/paste scar of its anchor-sim siblings) plus a whitespace-only
  line; back to the method level, no behavior byte moved.
- **The FoundationHarness pair (t31-ocr29-10, maintainability:low +
  test:low, ONE commit)** — the guarded-RDONLY source pin matched the
  harness source verbatim, so any mechanical reformat reddened it
  with no behavioral defect; the haystack is whitespace-normalized
  now (the pin owns the spelling's TOKENS, never their layout), and
  the corrupt-archive leg's ignored file_put_contents() return
  asserted (a failed write answered ER_NOENT, the staging failure
  wearing the ER_NOZIP verdict's vocabulary).

### Fixed (shared — M3 Task 3.1, OCR round 28)

Twenty-eighth OCR-tool round (62/62 complete): 9 findings, driver triage
accepted all — trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9. The round's shape: the
TWIN PROBLEM AGAIN — the heredoc lens missed the EOF flush its
name-walk sibling got one round earlier (census lesson: a fix applies
to the CLASS, and the sibling lens of the same detector IS the class),
plus the trivia-separated leading separator (the interrupted-absolute
spelling — token-stream shapes, not byte shapes, are the frontier
now), and one finding's ENGINE PREMISE driven and refuted in flight
(the surrogate \u{} refusal the Unicode-escape RFC spelled no longer
holds on the driven engine). Fixed as t31-ocr28-1..8 — one commit per
finding, the two same-class copyTree test-fence sites sharing one
commit — plus this docs record, the full offline check green after
every commit. Suite 1673 → 1676 tests, 45732 → 45764 assertions,
3 skipped unchanged.

- **The heredoc text lens flushes at EOF, the last boundary
  (t31-ocr28-1, bug:high)** — the flush lived inline under
  T_END_HEREDOC alone, so a source truncated inside a heredoc (no
  closing label ever tokenized) met no flush and every finding the
  body carried dropped without its report — the SAME totality gap the
  name walk's group-prefix EOF flush closed one round earlier,
  missed in the sibling lens of the same detector. The flush rides
  its ONE closure now (the lens half) while the loop keeps the state
  half; the label boundary and EOF call the same judgments and can
  never drift apart. Driven red at HEAD: zero references where the
  terminated twin reports two, the build shipping the bytes at exit 0.
- **An interrupted absolute name keeps its leading separator's
  verdict (t31-ocr28-2, bug:high)** — a leading separator standing
  APART from its name (`\ Deicod\…`, trivia between) lexes as a
  standalone T_NS_SEPARATOR the walk consumed, and the qualified name
  rode on as relative: the group prefix composed it into a name no
  family predicate matches — the r10-9 laundering verdict, driven red
  at HEAD at zero references while the glued twin reported, the build
  shipping at exit 0. A standalone separator the run assembly did not
  swallow ARMS the absolute expectation (the interrupted-relative
  sibling's own pending-arm pattern): the following name is judged
  fully-qualified regardless of the intervening trivia, at every
  verdict — composition, the empty-body fence, the alias slot.
- **The copyTree resolution machinery gates its POSIX premise at the
  owner (t31-ocr28-3, bug:medium)** — the cwd-prepend arm treated any
  target not starting with '/' as relative, so on a non-POSIX host a
  Windows absolute target ('C:\Temp\dst', a UNC root) was
  cwd-prepended and the collapse joined realpath() backslash output
  into garbage. The arm consults the harness's ONE platform owner
  (isPosixHost()) and a drive-letter/UNC root-shape check: those
  spellings answer a named refusal, never a cwd-prepend over an
  absolute spelling. The POSIX-side exactness is driven (the
  drive-letter spelling lands as a legal relative target, no false
  refusal); the non-POSIX refusal is construction-evident — no sim
  flips a platform constant.
- **The surrogate \u{} premise driven and refuted; the engine oracle
  pins the class (t31-ocr28-4, bug:low)** — the finding claimed the
  engine refuses surrogate codepoints at compile time; the DRIVEN
  engine (8.5.10, php -l and runtime, byte-hexed) compiles
  '\u{D800}' clean and computes ED A0 80 — the RFC-era refusal was
  lifted upstream, and only over-range and non-hex spellings still
  refuse. Making the class literal would have invented a refusal the
  running engine does not give (the ocr23-4 defect class inverted).
  The production behavior stands and the pin drives the ENGINE
  ITSELF as the oracle over every range boundary — the day an engine
  generation refuses the class again, the pin fails loudly naming
  the drift.
- **The text lens's line derivation answers a PCRE abort, never a
  silent line 1 (t31-ocr28-5, bug:low)** — $line_of ignored
  preg_match_all()'s false return, and the abort rode the arithmetic
  as false + 1 = 1: a line-count abort at any depth answered line 1,
  misattributing every text finding's quoted position. The false
  return answers the abort guard now (line 0, the named unknowable
  for a 1-based field), the sibling shape the lens's own pcre-abort
  row carries; construction-evident — a \R count cannot be driven to
  abort.
- **The URL authority refuses the backslash: the derived adjudication
  (t31-ocr28-6, security:low)** — DERIVED FIRST. On the PHP side the
  byte is internally harmless (parse_url and the rebuilt authority
  agree, the transport rides the same engine semantics), but the one
  browser-facing channel this VO feeds — the device-flow verification
  URI, passed through raw to the authorization redirect — is
  re-parsed by WHATWG, where '\' terminates the authority:
  'https://evil.example\@idp.example/' sends the browser to
  evil.example while this parse and every redacted form name
  idp.example. The screen rides the derived authority segment
  (userinfo included, the forging spelling's hiding place); RFC
  3986's authority grammar carries no backslash anywhere, so the
  refusal rejects nothing legal, and the round-1 space/tab
  adjudication stands beside it (those bytes forge nothing, pinned
  still-legal).
- **The manifest-unreadable row's finally restores the third chmod'd
  path (t31-ocr28-7, test:low)** — the row's apply() leaves
  dist/checksums.txt at mode 0000 and the finally restored only the
  other two chmod'd paths, so on a host whose unlink cannot remove a
  0000 file the wpct-battery-* tree leaked per run. The census drove
  the owner choice: the restore lives at the row that broke it (the
  ocr27-9 doctrine), not in rrmdir — the removal owner serves every
  caller and a chmod-before-unlink fallback there would widen the
  removal contract for a residue exactly one row plants.
- **The ungated copyTree legs ride the platform probe; the
  root-source leg pins its DISTINCTIVE vocabulary (t31-ocr28-8,
  test:low ×2, one commit)** — four legs asserted through
  POSIX-shaped machinery with no isPosixHost() gate (the nested
  same-name leg, the trailing-slash leg, the precondition control
  legs, the relative-target leg — the cwd-prepend arm's own premise);
  each gates visibly now, its skip naming its own premise. And the
  bare-'/' source-root leg rode a vacuous assertion — every copyTree
  refusal message starts with 'WpHarness::copyTree() refuses', a
  string that CONTAINS '/', so the contains-'/' check could never
  fail; the leg pins the source-side root-collapse vocabulary
  instead, a substring only that refusal carries.

### Fixed (shared — M3 Task 3.1, OCR round 27)

Twenty-seventh OCR-tool round (62/62 complete): 15 findings, driver triage
accepted all — trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15. The round's shape: the
FENCE-GENERATION TREADMILL named — the round-16 edge-junk close and the
round-26 tail close each begat the next spelling generation (junk
INTERLEAVED between the dots; whole-path degenerates with no tail at
all), and the round's OWN fix was audited against its comment (the
round-26 dedup claim overclaimed) — comment-vs-behavior drift is now a
finding class of its own, and it found the nowdoc guidance wrong since
round 7 while the only caller did it right. Fixed as t31-ocr27-1..10 —
one commit per finding, the census's same-class sites sharing one
commit — plus this docs record, the full offline check green after
every commit. Suite 1672 → 1673 tests, 45686 → 45732 assertions,
3 skipped unchanged.

- **The traversal fence judges the RESOLVED segment — junk interleaved
  or not (t31-ocr27-1, security:high)** — the two-phase strip (trailing
  non-dot junk first, then the all-dots requirement) missed every
  spelling with junk BETWEEN the dots: '. .', '..<tab>..',
  '..<0x01>.' folded to nothing the predicate judged while the host
  strips its own side of the junk class and lands the parent token —
  the fence's own comment still claimed it owns "every spelling that
  RESOLVES to '..'". The predicate folds EVERY junk byte out of the
  segment first (the ONE edge-junk owner's class minus the dot,
  derived not twinned), then judges the dot-shape of the residue:
  dots-only of two or more dots refuses, wherever the junk sat —
  interleaved, leading, or trailing. Unit-probed census: the round-16
  boundary matrix rides unchanged; content spellings ('x..', '..x',
  'a.b') keep their verdicts.
- **The removal seam fences the whole-path degenerates
  (t31-ocr27-2, security:medium)** — '..', './', '.' carry no '/..'
  tail, so the strip loop left them unflagged and they passed every
  probe: is_dir('..') names the parent of the process CWD, realpath
  is not the filesystem root on any host whose CWD sits deep, and the
  walk EMPTIED the parent of the caller's CWD through the spelling.
  Every degenerate whole-path refuses now (the parent-walking
  refusal's own silent vocabulary): a spelling that names no
  caller-named root at all is never walked. Driven red at HEAD from a
  controlled child CWD (the parent emptied), green now.
- **The group-prefix fence flushes at EOF (t31-ocr27-3, bug:medium)**
  — the empty-body fence fired only at the ';' boundary and the
  group's own '}' close, so a `use Prefix\{` or `use Prefix\{\Member`
  truncated at end-of-file met neither and the prefix was dropped
  without its report (a family-spelled prefix judged by no gate). EOF
  is the last boundary: the same fence, the same single report — the
  handlers null the prefix when they fire, so the flush cannot
  double-report.
- **The byte-duplicate verdict is actually deduped per name
  (t31-ocr27-4, maintainability:low)** — the r26-5 comment claimed
  "deduped per name" while the emission answered N−1 identical lines
  for a name carried N times (a pair one line, a triple two): that
  fix had closed the two-fences class, not the per-copy emission
  class beneath it. One offense, one line — the third and every later
  copy of the same bytes answers nothing the second copy did not.
- **label()'s unknown value answers a named failure
  (t31-ocr27-5, maintainability:low)** — the bare LABELS[$this->value]
  lookup answered a future case-without-row as the engine's own
  "Undefined array key" warning plus a TypeError: loud, but naming
  neither the enum, nor the missing case, nor the sync duty. An
  explicit lookup throws a LogicException naming the value, the
  table, and the add-them-together duty; the sync is
  construction-evident in both directions (a case without its row
  fails naming the case; an orphan row fails naming the key).
- **The nowdoc guidance names the real escape semantics
  (t31-ocr27-6, documentation:low)** — the unescaper's docblock
  advised "pass the raw inner text with the single-quote semantics of
  'nothing to do'", and the advice was wrong on its own terms: the
  single-quote branch resolves \\ → \ and \' → ', so following it over
  a nowdoc body corrupts exactly the bodies whose distinguishing
  feature is that nothing resolves. The docblock states the real
  semantics and the caller-side contract (never route a nowdoc body
  through the function — the only caller has done it right since
  round 7; the guidance was wrong while the caller was right).
- **The exec-capability census closes WHOLE (t31-ocr27-7,
  test:medium)** — the r26-9 census was incomplete, and the re-census
  of every inspector call site in the file found SEVEN ungated arms
  riding the internal php -l spawn (the finding's four plus three
  its own five-site count missed: the repo-relative-include,
  missing-header, and dev-files arms — violations ACCUMULATE, the
  header verdict is POST-extraction). Each gains the ocr20-5 gate;
  the arms that stay ungated are enumerated by verdict seam (every
  one refuses before the spawn). Driven under disable_functions=exec:
  seven visible skips where \Errors stood.
- **The skip path leaves no probe behind (t31-ocr27-8, test:medium)**
  — the dotted-slug pin wrote its parse probe into real dist/ BEFORE
  the canSpawnChildren() guard while the try/finally unlink started
  below it, so markTestSkipped()'s throw leaked the scratch file on
  every spawn-less host. The guard fires before the write; the write
  rides inside its own try. Driven both ways: at HEAD the skip leaks
  one probe into real dist/, with the fix zero.
- **Staging failures fail as staging, and the dir-link leg owns its
  own creation (t31-ocr27-9, test:medium)** — both child-process
  lint batteries staged their scratch trees through unchecked
  mkdir/copy/write calls, so a staging failure wore the lint verdict
  as its own defect (the t31-ocr26-12 doctrine's next two sites);
  and the dirlink leg passed VACUOUSLY when symlink() returned false
  silently — '5 file(s) checked' reads as the pinned green with or
  without the link. Every staging site feeding a child verdict is
  asserted now; the symlink creation is asserted, the leg naming its
  own failure instead of a skip-shaped green.
- **statIndex()'s false is a FAIL row, never a silent skip
  (t31-ocr27-10, maintainability:low)** — the battery's emptiness
  walk guarded the stat with is_array(), so a false return skipped
  the entry's judgment entirely and the CLEAN classification passed
  vacuously — the exact class the file's own strict-gate doctrine
  forbids for open()/extractTo()/getNameIndex-false. The row names
  the return, the handle closed, the verdict channel whole.

### Fixed (shared — M3 Task 3.1, OCR round 26)

Twenty-sixth OCR-tool round (62/62 complete): 15 findings, driver triage
accepted all — trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15. The round's shape: the
TRAIT-USE POSITION — the relative-use rewriter refused the operator
everywhere except where it ARMED (fence doctrine again: the finder
fences the closure spelling, the trait spelling rode through, and the
class-body spelling is LEGAL PHP the splice silently retargeted); the
comma carve that stopped the rider judgment one member early; the
parent-walking '/..' tail over REAL directories at the inspector's
removal seam; the engine-shape-blind getLastErrors() guard; and the
spawn-gate census the inspector's accumulating-verdict flow forced
wide. Fixed as t31-ocr26-1..12 — one commit per finding, the four
same-class spawn-gate sites sharing one commit — plus this docs
record, the full offline check green after every commit. Suite
1669 → 1672 tests, 45651 → 45686 assertions, 3 skipped unchanged.

- **The relative operator in a TRAIT use position refuses, never
  retargets (t31-ocr26-1, bug:high)** — the use-rewrite walk's fence
  (wp_connectors_use_opens_import()) draws its line from the FOLLOWER
  shape, and a name follower opens an import statement at the top
  level and a trait clause list inside a class body — the same bytes
  in both. The class-body spelling is legal PHP (php -l clean;
  resolves and loads under the declaration in effect), so $use_open
  armed the splice for `class C { use namespace\X; }` and the
  rewriter silently RETARGETED the trait reference through the family
  map (driven red at HEAD: a DIFFERENT trait shipped at exit 0; the
  comma lists rode the same splice). The trait fence derives from the
  brace-kind stack the classifier already rides (the t31-ocr7-7
  vocabulary): a use statement with an 'other' frame below it stands
  in a trait position, and its relative operator refuses with the
  position named — the rewrite owns import statements only.
- **The rider judgment owns every member of the comma list
  (t31-ocr26-2, bug:medium)** — the statement-tail rider walk (the
  t31-ocr16-4 gate) set 'terminated' AT the comma, so a following
  member carrying no relative trigger of its own was judged by nobody
  (the main loop's trigger condition skips non-relative members):
  driven red at HEAD, `use namespace\Clock, Other\Thing SystemClock;`
  spliced the first member and shipped the second's parse-error rider
  bytes beside it at exit 0. The judgment CONTINUES past the comma
  now — a separator-aware member grammar (a name piece continues the
  run only through a '\', so a second name with no separator reads as
  the rider it is), the kind keywords, optional aliases, and an
  empty-member refusal; the legal list shapes keep their verdicts and
  `as A as B` still refuses.
- **A '/..' tail over a REAL directory never walks the parent
  (t31-ocr26-3, bug:medium)** — the inspector removal twin's
  tail-stripped probe fenced only the LINK channel and realpath only
  collapse-to-the-fs-root, while the WALK rode the caller's RAW
  spelling: a caller-controlled 'dist/.inspect-x/..' over an existing
  real (non-link) directory resolved to the PARENT tree and the walk
  emptied it (driven red at HEAD, every fence passing). The strip
  loop tracks whether it consumed a '/..' tail and a parent-walking
  spelling refuses before any walk — the same territory doctrine the
  link probe owns, extended to every tail spelling.
- **The root reclaim rides the stripped spelling (t31-ocr26-4,
  bug:low)** — the removal twin rmdir'd the caller's RAW spelling
  while the fences judged the stripped probe, so a 'dir/.' root
  emptied its children through the iterator and then leaked to
  @rmdir('dir/.')'s EINVAL — the exact leak the ocr24-3 root-reclaim
  claim its own docblock states. After the parent-walking refusal
  every surviving tail names the root itself; the is_dir probe, the
  realpath fence, the iterator, and the final @rmdir all ride the
  stripped spelling — tail-spelled roots go whole.
- **One duplicate entry, one verdict line (t31-ocr26-5,
  maintainability:low)** — a byte-exact duplicate folded identically
  onto its own first copy, so the second copy tripped BOTH duplicate
  fences for the same name (one offense, two verdict lines; a triple
  answered four). The emission is deduped per name: the byte-exact
  fence wins, the folded fence judges only the copies it did not
  name; a case-fold twin keeps its own line.
- **Both getLastErrors() shapes are clean (t31-ocr26-6, bug:medium)**
  — parse_serialized_instant() read its warning/error counts behind a
  false !== guard alone, but since the 8.3 engine rewrite
  DateTimeImmutable::getLastErrors() returns an EMPTY ARRAY on clean
  parses: on those builds the guard passed and both key reads were
  undefined-key accesses — a warning pair per instant on every clean
  from_array parse (this runner's engine still hands false, probed;
  the empty-array shape is the cross-engine premise). The guard is
  ! empty() now — false AND array() are both clean, and the keys are
  read only when the engine populated them. Shape-driven pin: a clean
  round trip under a capturing handler asserts zero diagnostics on
  either engine shape.
- **The degenerate repo anchor rides the '/' prefix (t31-ocr26-7,
  bug:low)** — the harness link probe's repo-territory needle spelled
  '$repo . \'/\'' raw, and a repository root AT the filesystem root
  makes it '//' — which no single-slash carry ever starts with, so
  the repo territory was silently absent (the temp anchor's own
  degenerate guard already modeled the shape; the repo twin lacked
  it). At '/' the needle is '/' itself; every absolute carry sits
  beneath the root repo. Construction-evident — driving it needs a
  checkout at '/<dir>/…' (a root-writable host; the driven sim stays
  the unprivileged-host ceiling, the ocr25-4 residual's own class).
- **The manifest staging temp rides the crashed-run charter
  (t31-ocr26-8, maintainability:low)** — the staging temp was
  tempnam-pid-less while the sweep reclaims exactly this crash class
  for every other scratch the build lands; its own doc note carved
  the pid-less spelling out as unattributable, so a SIGKILL between
  staging and the landing rename left it forever. The name derives
  from the sweep's own doctrine now — '.checksums-<pid>-' before the
  random tail — and the sweep owns it on the same charter and
  liveness gate (dead run swept by the next build, live run never
  raced; a legacy pid-less spelling stays alone).
- **Every extract-and-lint inspector arm skips visibly on spawn-less
  hosts (t31-ocr26-9, test:medium ×4 → the census's full class, ONE
  commit)** — four ungated sites fatalled as undefined-function
  \Error under disable_functions=exec instead of skipping visibly,
  and the finding's own census demand found the class bigger than
  the four: violations ACCUMULATE in the inspector (nothing
  short-circuits on them), so extraction and the internal php -l
  spawn run even under arms whose asserted verdict is an entry-loop
  refusal, and the clean/green arms need the full sweep. Fifteen
  tests gained the exec-capability gate (the file's own ocr20-5
  doctrine, each skip naming its premise and what already passed);
  the early-return arms (near-source, traversal, root-file,
  multi-top-dir, invalid-slug), the extraction-refusal arms, and the
  walk-refusal arm own no spawn and stay ungated by census verdict.
  Driven: php -d disable_functions=exec over newly-gated tests
  answers visible skips, 0 errors.
- **The libzip read warning is optional, never the premise
  (t31-ocr26-10, test:low)** — the forced-close leg asserted its
  captured engine warning non-empty, making an ENGINE-OPTIONAL
  diagnostic a hard requirement (red on every quiet-zip host while
  the refusal it exists to pin had already fired). The expectation is
  dropped — the refusal assertions own the contract — and the capture
  handler stays as the silencer for chatty builds.
- **The battery's scratch maker owns its own cleanup (t31-ocr26-11,
  test:low)** — makeScratchRepo() guaranteed nothing-created only for
  the validation phase (the ocr25-7 needle close); a throw from any
  later CREATION step leaked the half-built wpct-battery-* tree in
  system temp despite every caller's try/finally, because every
  caller invokes the maker BEFORE its own try. The creation phase
  rides a try/catch that rrmdirs the root and rethrows.
- **Staging failures fail as staging (t31-ocr26-12, test:low)** — the
  conventions-gate shared-tree pin staged its scratch repo through
  unchecked mkdir()/copy()/file_put_contents() calls, so a staging
  failure surfaced only through the CHILD run — the clean-control leg
  failed as 'must pass the gate: PHP Warning: require_once … Failed
  to open stream', a staging problem wearing the gate's own defect.
  Every staging site feeding the child run is asserted now, each
  message naming the staging premise, before any child is spawned.

### Fixed (shared — M3 Task 3.1, OCR round 25)

Twenty-fifth OCR-tool round (main 57/62: 3 findings; fill-in over
25 files: 8 findings — union complete): 11 findings, driver triage
accepted all — trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4→11. The round's shape: Url
speaks TWICE — the parse_url/WHATWG split-divergence family (the
raw screen and the rebuilt authority disagreeing, over the
multi-'@' userinfo boundary and the leading-zero port) — the
destructive-root-leg PROBE-BEFORE-FIRE doctrine lands (safety must
not rest on post-hoc assertions over destroyed trees), and the
fixed-name-scratch census converts the concurrent-run collision
class reborn in test form. Fixed as t31-ocr25-1..9 — one commit
per finding, the two same-class pairs sharing one commit each —
plus the two-lens verifier pass's one follow-up close (rd-1) and
this docs record, the full offline check green after every commit.
Suite 1665 → 1669 tests, 45603 → 45651 assertions, 2 → 3 skipped
(the new capability-gated leg's visible skip).

- **The multi-'@' authority splits ONCE, authority-wide
  (t31-ocr25-1, bug:medium)** — the raw screens derived host_port
  after the LAST '@' (the WHATWG/curl split) while the rebuilt
  authority rode parse_url()'s own host/port answers: two
  derivations of one boundary, and on an engine whose parse_url()
  ends userinfo at the FIRST '@' the redacted authority would name
  a host the transport never contacts. The rebuild rides the raw
  derivation now (the segment the screens already judged feeds the
  authority whole — host and port, range check on the same int);
  an empty raw derivation refuses with the entry screen's own
  sentence. Engine premise probed twice: this build's parse_url is
  itself a last-'@' splitter (zend_memrchr; a 30,000-shape battery,
  zero divergence), so the close is BY CONSTRUCTION — the
  t31-ocr1-2 doctrine over build-dependent parse_url answers, never
  engine accident.
- **The archive lands FIRST, its descriptors after
  (t31-ocr25-2, bug:medium)** — the landing pre-flight rules out
  non-file targets only, and the old order (sidecar, manifest,
  archive) stranded exactly the archive-rename refusal: the NEW
  checksum standing beside the OLD zip, describing a release that
  is not the artifact beside it, with no later build obligated to
  heal it. The thing the descriptors NAME now stands before any
  descriptor naming it moves: the archive rename refuses while
  nothing has landed (the prior set byte-identical — driven on a
  capability runner through the immutable flag), and a
  mid-sequence descriptor refusal leaves only descriptors naming a
  STANDING artifact (stale, loud, healed by the next build's own
  regeneration). The seam charter docblock states the doctrine.
- **The leading-zero port refuses instead of diverging
  (t31-ocr25-3, bug:low)** — ':0443' is digits, so the r4-12 screen
  passed it while parse_url() normalized the value to 443: url()
  carried ':0443' against an authority spelling ':443', the exact
  raw/redacted divergence that screen exists to kill. url() holds
  the caller's bytes verbatim, so agreement means refusing the
  non-canonical spelling — the r4-12 pin's own acceptance clause
  ("spelled by its int value") reversed; the range screen rides
  first (rd-1) so the zero-valued ':000' wears the range sentence,
  never a dead-end remediation.
- **The destructive root legs gain PROBE-BEFORE-FIRE
  (t31-ocr25-4, test:high + test:medium sibling)** — the
  '/'-anchored fires (the silent inspector loop, the loud rrmdir
  loop, and copyTree's root-landing battery) answered their safety
  to the production guard alone: a regressed guard would walk the
  filesystem root's children as the very test runs, the sentinel
  assertions reading a destroyed tree. The fires now run only
  where the process cannot WRITE the root directory (the first
  level unmutable; the root runner skips visibly — the ocr4-1
  DAC-override premise), the canary is asserted standing BEFORE
  every fire, and a scratch-rooted sim drives the sentinel shape
  against an UNGUARDED walker (the canary dies — the assertions
  are a live detector). Honest boundary (the verifier's refutation,
  restated in place): the gate bounds the FIRST level — the deep
  user-writable-subtree walk on a regressed guard stays the
  ledgered residual the guard itself owns.
- **The fixed-name scratch trees go unique-per-run
  (t31-ocr25-5, test:medium)** — ~25 fixed-name scratch trees and
  work dirs under the shared repo dist/ ('.embed-test',
  '.teardown-masking', '.inspect-bad', …) each carried an
  if(is_dir()) pre-clean, re-growing exactly the concurrent-run
  collision class the unique-stage doctrine closed for the build.
  Every fixed-name tree converts to the one scratchPath() owner
  (label + bin2hex(random_bytes(4))); the pre-cleans rode along,
  dead on a unique name. The census names every converted label
  and every deliberate non-conversion (artifact names,
  production-derived spellings, scratch-relative names, the
  pid-suffixed class left to a future round). The scratch trees
  coexist across concurrent runs — the FILE-level claim is
  narrower than first narrated (the retained artifact-name class
  still collides; ledgered).
- **The umask-0444 walk-refusal leg skips visibly on root runners
  (t31-ocr25-6, test:medium)** — the leg's premise is a directory
  the process cannot open; uid 0 reads through mode 0333 (DAC
  override), the walk opens, and the refusal assertions would ride
  a premise that never constructed. The uid-0 skip fires BEFORE
  the construction, naming the premise (the ocr4-1 doctrine).
- **The skip-before-mutation pair (t31-ocr25-7, test:low ×2)** —
  the success-line digest pin's uid-0 skip threw AFTER the
  deleted-zip leg's mutation, leaving dist/ holding the deleted
  zip's stale sidecar and manifest entry; the leg's mutation is
  healed first (the rebuild lands the whole set) so the skip fires
  over a consistent dist/. And makeScratchRepo()'s needle-miss
  throw fired after the mkdir/copyTree work, leaking a half-built
  scratch tree on fixture drift — the needle validates first, on
  the fixture bytes at their own source, nothing created (rd-1
  gives the swallowed realpath its own channel: a broken checkout
  never reads as a needle drift).
- **The scanner-library path is asserted resolved before the child
  embed (t31-ocr25-8, maintainability:low)** — a realpath() false
  once embedded `require false;` into the child, whose fatal then
  read as the SCANNER's self-containment defect instead of the
  environment problem it was; rd-1 completes the class across
  every child-embed site in the suite.
- **The refusal verdict resolves without PHPUnit
  (t31-ocr25-9, maintainability:low)** — refusalOf()
  hard-referenced AssertionFailedError at throw time while
  WpHarness.php is required into PHPUnit-less child engines
  (the redirected-TMPDIR sims): the first throwing child leg
  would answer the class-not-found Error AS the verdict (driven:
  caught and echoed at exit 0 — a silent mis-answer). One verdict
  owner: AssertionFailedError where PHPUnit is loaded, the base
  \Exception in a bare engine (never RuntimeException — the family
  the guarded calls throw), the chain intact either way; the
  bare-engine regression drives the child directly.
- **The verifier's close (rd-1)** — the publication-seam charter
  rewritten to the archive-first doctrine (it still narrated
  "descriptors first, the archive LAST / byte-untouched BY
  CONSTRUCTION"), the realpath class census completed at every
  child-embed site, the probe-before-fire gates' narration
  restated to the honest first-level boundary, ':000' wearing the
  range sentence, the verdict docblock stating the driven shape,
  and the tab-bearing host pinned (parse_url rewrites a host tab
  to '_'; the raw derivation carries the byte verbatim —
  authority() spells exactly the bytes url() carries).

### Fixed (shared — M3 Task 3.1, OCR round 24)

Twenty-fourth OCR-tool round (62/62, complete): 4 findings, driver
triage accepted all — trajectory
27→10→11→7→11→33→9→9→4→12→8→7→9→4. The round's shape: shared/src
speaks again after nine rounds of silence — the SecretMask class
rule's own implication was the leak ('token', 'secret',
'authorization' are credential suffixes in the rule's own sense, and
every vendor spelling riding them rendered verbatim) — and the
walker-fence census completes: every walker in the change set now
fenced, the scanner-internal one ledgered as residual. Fixed as
t31-ocr24-1..4 — one commit per finding — plus the two-lens verifier
pass's one follow-up close and this docs record, the full offline
check green after every commit. The verifier pass (two driven
agents, three findings, all confirmed): the close below and two
narration corrections ledgered. Suite 1663 → 1665 tests, 45569 →
45603 assertions, 2 skipped unchanged.

- **The suffix class owns the credential suffixes its own rule
  statement implies (t31-ocr24-1, security:medium — the first
  shared/src finding since round 14)** — 'X-Amz-Security-Token'
  (AWS STS), 'X-Shopify-Access-Token', 'X-Client-Secret'/
  'X-Shared-Secret', and 'X-Authorization' rendered their full
  secrets verbatim through every safe debug channel while the
  catalog's exact 'authorization' spelling sat covered: the
  r12-4/ocr15-1 leak class under the class rule's own implied
  vocabulary. 'token', 'secret', 'authorization' join the suffix
  list; over-masking a non-credential '-token' header in debug
  output errs safe, and the hyphen boundary is unaffected
  ('x-api-keychain' stays outside).
- **The post-extraction syntax walk rides the glm31-4 fence, and the
  round's walker census completes one seam over the finding's own
  count (t31-ocr24-2, bug:medium)** — a directory the process cannot
  open inside the extracted tree escaped as an uncaught SPL
  exception: inspector dead at exit 255, no verdict recorded. The
  construction rides the try (the ocr23 rd-1 doctrine), the refusal
  converts to a named violation mirroring the self-containment
  walker's shape, and it RETURNS — the artifact is judged whole or
  not at all (the r12-1 doctrine at the walk seam; the secret scan
  never judges a partially readable tree). The census correction the
  driver's own repro forced: the shared self-containment scan's
  glm31-4 fence wrapped only its foreach, never the iterator
  construction — an unopenable scan root threw through the shared
  owner's own fence BEFORE the php -l walk ever ran (driven) — the
  construction rides its try now. The secret-scanner's own walk
  stays this round's ledgered residual; inside the inspector the
  syntax walk's early return keeps it one seam behind for every
  refusal shape. Engine premise probed: this host's extractTo does
  not land unix modes from external attributes, so the regression
  constructs the class through the landing environment (umask 0444
  lands every extraction directory at 0333 — creation and writes
  succeed, opendir refuses).
- **The inspector's removal twin reclaims the ROOT on its refusal
  path (t31-ocr24-3, bug:low)** — the catch returned before the
  rmdir, leaking the unique random-suffixed extraction root beside
  the unopened subtree, one temp tree per refusal with no sweeper
  anywhere (build's twin has the stage sweep; nothing ever revisits
  this owner's random names). A best-effort @rmdir falls through
  now: a no-op when children remain (the unopenable-subtree residue
  stays by doctrine), and it reclaims the root whenever it is
  empty-able — rmdir needs the parent's write bit, never the
  target's read bit. The ocr23-2 silent verdict is unchanged.
- **The canonical judgment gains the segment boundary every other
  family predicate carries (t31-ocr24-4 + the verifier's rd-1,
  bug:low)** — the bare prefix match mislabeled below-vendor
  siblings ('…\SHAREDly\Clock', the 'SharedStorage' shape) as "a
  case-variant spelling of the family name … write the family
  spelling": a dead errand, because complying leaves the sibling,
  which refuses in every casing. The fold matches the ONE family
  vocabulary's own ($is_family: exact, or prefix + '\\'), and the
  verifier's close deleted the vendor branch entirely — every label
  it could emit was the same dead errand one branch over (driven:
  the complied spelling refused byte-identically, the hint gone),
  so the label belongs to the family branch alone, where complying
  genuinely rewrites.

### Fixed (shared — M3 Task 3.1, OCR round 23)

Twenty-third OCR-tool round (62/62, complete): 9 findings, driver
triage accepted all — trajectory 27→10→11→7→11→33→9→9→4→12→8→7→9.
The round's shape: the SCANNER SIDE finally under the lens — teardown
masking, silent-return contracts, failure-channel discipline,
fence-pair consistency — with the harness and test fences carrying
the other three (a platform premise, a probe territory, a residual
head); zero shared/src findings, the production core's silence
continuing. Fixed as t31-ocr23-1..9 — one commit per finding —
plus the two-lens verifier pass's two follow-up closes and this
docs record, the full offline check green after every commit. The
verifier pass (two driven agents): all nine fixes stood — every
premise re-driven red at the round base, every mutation revert
reddening its committed test, every cited precedent verified — and
its two closes (the iterator CONSTRUCTION shape of the new silent
guards, found by both lenses; the \u{} MAGNITUDE shape of the new
hex guard) are fixed; its records (the probe's territory narrowing,
a Zai-suite putenv census as the next round's head, a one-off
order/runner flake) are ledgered. Suite 1657 → 1663 tests, 45541 →
45569 assertions, 2 skipped unchanged.

- **The finally teardown never masks the primary failure
  (t31-ocr23-1 + the verifier's rd-1, bug:medium)** — buildPlugin()'s
  rrmdir walked an unguarded RecursiveDirectoryIterator, and an
  exception in a finally REPLACES the primary in flight: the build
  answered the teardown's SPL vocabulary instead of its own refusal.
  The whole iteration seam — the walk AND the lazily-built
  constructor, one shape the first cut missed — rides the silent
  contract now: the iteration refusal is swallowed (the glob()
  fence's own shape), the partial removal stands, the primary
  verdict surfaces.
- **The inspector's removal walk honors its own docblock
  (t31-ocr23-2 + rd-1, bug:medium)** — "the silent return (a
  production finally must not throw)" was spelled in the docblock
  and honored by every root clause while the walk still iterated
  bare: a walk-hostile tree answered the engine's uncaught
  UnexpectedValueException. The construction and the walk ride the
  silent contract now, root shape included.
- **The scan-root boundary refusal carries the failure channel's
  own class (t31-ocr23-3, bug:medium)** — it threw
  InvalidArgumentException, a LogicException outside build's
  `catch (RuntimeException)`, breaking the channel the glm31-4
  sibling comment deliberately preserves: a firing boundary was an
  uncaught fatal exiting 255 where every sibling refusal reaches
  the build's named exit-1 verdict. The refusal throws
  RuntimeException now, message byte-identical; the battery's three
  legs pin the class through the family parameter.
- **The \u{} escape validates hex — and magnitude — before
  hexdec() (t31-ocr23-4 + rd-2, bug:low)** — hexdec() ignores every
  non-hex byte ('\u{zz}' modeled as the NUL byte, '\u{ 41 }' as
  'A') and deprecates on the input, while the engine refuses every
  such spelling at compile time; and a digit run past the int range
  answered a float the (int) cast collapsed to 0 ('\u{FFFF…}'
  resolving as NUL under a mid-gate cast warning). The digits are
  judged hex-ALONE before any conversion, the range keeps the
  float: every engine-refused spelling stays literal byte-for-byte,
  the model warning-free.
- **The redirected-TMPDIR sim carries the platform gate its POSIX
  premise owed (t31-ocr23-5, test:low)** — the child's whole
  premise is a fresh engine honoring TMPDIR (POSIX temp
  resolution), and nothing gated it; the gate rides the ONE owner
  this round's census threshold hoisted (WpHarness::isPosixHost(),
  three consumers, each keeping its own premise-naming skip).
- **The link probe anchors at the harness's own territory
  (t31-ocr23-6, bug:low)** — the r19 anchor exempted only the temp
  spelling's components, so every other absolute chain walked from
  '/' and a host-layout link ABOVE a source root outside the temp
  tree fired the planted-link verdict on a legal tree. A component
  is probed iff its chain-so-far sits strictly beneath one of the
  two anchors — the temp spelling and the repository root; the
  planted class keeps its full reach where it lives (the committed
  dist-side legs proved the plant surface is not temp-only, the
  first temp-only cut deleting the committed mid-path leg's victim
  before the suite caught it), and the entry-level guards keep
  their vocabulary below the root.
- **The fused relative operator inside a closure use(...) list
  refuses like its interrupted twin (t31-ocr23-7, bug:medium)** —
  a name in a lexical binding list is a parse error in every
  reading, but the two lexer spellings took opposite verdicts: the
  interrupted twin refused at the bare-keyword fence while the
  fused token fell through the use-statement gate and shipped
  parse-error bytes at exit 0. The walk tracks the closure-use
  region (depth-counted) and refuses the fused spelling inside it.
- **The plugin-tree collector skips near-source names — ONE
  judgment both fences ride (t31-ocr23-8, bug:low)** — collectFiles
  packaged 'notes.php.' while the inspector refused the same entry
  through the ONE near-source predicate: build shipped what inspect
  rejected, the r20 ledger's own named residual. The collector
  excludes the class through the same owner (the r5-10
  builder-excludes/inspector-rejects shape); the owner's docblock
  names all three channels.
- **The non-spawn putenv consumer converted — the r22 residual
  head (t31-ocr23-9, test:medium)** — the fold battery's env-pin
  leg putenv()s LC_CTYPE with no spawn, the spawn-pair floor a
  misfit and gate-whole-method a coverage narrowing. The leg split
  to its own test behind function_exists('putenv') (driven: one
  visible skip under the flag, the fold legs still green); the
  fold test keeps its setlocale-only premise.
- **The verifier's closes (rd-1, rd-2)** — the construction shape
  and the magnitude shape, each driven red at the round's HEAD and
  green at the close (the details ride the two bullets above).

### Fixed (shared — M3 Task 3.1, OCR round 22)

Twenty-second OCR-tool round (62/62, complete): 7 findings, driver
triage accepted all — trajectory 27→10→11→7→11→33→9→9→4→12→8→7.
The round's shape: test-fence order-dependence and silent-residue
classes, zero shared/src findings (the production core's silence
continues). Fixed as t31-ocr22-1..7 — one commit per finding —
plus the two-lens verifier pass's three follow-up closes and this
docs record, the full offline check green after every commit. The
verifier pass (two driven agents, both converging): all seven
fixes stood; its findings — the rrmdir walk's item-level
dead-resolution (found independently by both lenses as the
yield-order and double-tail shapes, closed by building the walk on
the collapsed root), the three locale-pressure spawn guards missing
their parent-side putenv, and one stale allow-set comment — are
fixed; the round's narration fabrications (an "established idiom"
that was the repo's first platform gate, a leak mechanism whose
parentheticals the drive refuted, a five-that-was-seven leg count)
are corrected in the ledger. Suite 1656 → 1657 tests, 45535 →
45541 assertions, 2 skipped unchanged.

- **The symlink battery's legs own FRESH copy targets (t31-ocr22-1,
  bug:high)** — seven refusal legs and one nothing-landed pin rode
  one shared target, and the in-tree link refusals fire mid-walk,
  so entries yielded before the link legitimately land: the
  file-shape leg's residue sat waiting for the mid-path leg's
  assertFileDoesNotExist, a red through no defect on every host
  whose yield order meets the real file first. Each leg draws a
  fresh uniqid target (the premise construction-pinned);
  order-proof by construction.
- **The root-anchored refusals ride a PLATFORM probe — the repo's
  first (t31-ocr22-2, bug:medium)** — five leg-groups premise their
  spellings on POSIX root resolution (the t31-ocr11-2 doctrine) and
  nothing gated them on a non-POSIX host. They moved byte-identical
  (both lenses' multiset comparisons: nothing lost, nothing
  altered, the moved set exactly the root-anchored class) into
  testRootAnchoredSpellingsRefuseBeforeIteratingOnPosixHosts behind
  a DIRECTORY_SEPARATOR gate with a visible skip naming the
  premise; the platform-neutral shapes keep running on every host.
- **The relative scan-root arm pins BOTH named paths
  (t31-ocr22-3)** — it judged only the exception family, so a
  guard refusing without the docblock's both-paths verdict passed
  invisible on the one shape that once walked the working
  directory.
- **The redirected-TMPDIR sim's spawn guard declares the TRIPLE
  (t31-ocr22-4)** — the child script's premise-critical first
  statement is a putenv(), and a putenv-disabled host fataled the
  child before its first read (driven). The guard rides
  canSpawnChildren('putenv') — the variadic extra the owner's own
  doctrine names.
- **The payload-API allow sets spell the per-type contract
  (t31-ocr22-5)** — all three audits granted
  retry_after_seconds to every concrete type, contradicting the
  method's own docblock and masking a wrong grant (a storage type
  growing the spelling passed green — mutation-driven). The
  additions ride the rate-limit type alone.
- **rrmdir()'s dead resolutions die at the OWNER — final rmdir AND
  walk (t31-ocr22-6 + the verifier's rd-1/sc-1 close)** — the
  final rmdir once targeted a resolution through a component the
  walk itself removed (a real 'parent/sub/..' tree leaked its top
  directory per call, driven at HEAD); the first fix handed it the
  pre-walk collapsed spelling, and the verifier then found the
  same class alive in the walk's item pathnames from both sides
  (the yield-order shape: siblings stranded when the walk meets
  the tail's component first; the double tail: stranded under
  every harness). The walk builds on the collapsed root now — no
  pathname carries a consumable '..' at any yield order; the
  spelling matrix re-driven byte-identical for every plain class.
- **The never-created zip pin is falsifiable (t31-ocr22-7)** — it
  judged the state AFTER the finally's unlink had erased the
  evidence, so a creating helper passed invisible (driven: green
  under the old order). The pin rides the state the open actually
  left behind, cleanup still guaranteed on every exit path.
- **The verifier's closes (rd-1 + sc-1, rd-2, rd-3)** — the
  collapsed walk (two regression legs driven red at the round's
  HEAD, green at the close), the three locale-pressure guards
  declaring their parent-side putenv (driven: three visible skips,
  zero errors under the flag; the non-spawn putenv consumer at
  BuildArtifactsTest:4564 carried to the ledger as a
  decision-plus-fence residual), and the r8-5 comment's per-type
  correction.

### Fixed (shared — M3 Task 3.1, OCR round 21)

Twenty-first OCR-tool round (62/62, complete): 8 findings, driver
triage accepted all — trajectory 27→10→11→7→11→33→9→9→4→12→8.
Fixed as t31-ocr21-1..5 — one commit per finding (classes as
noted), five fix commits plus the two-lens verifier pass's two
follow-up fixes and this docs record, the full offline check green
after every one. The round's lesson, now in the ledger: an
engine-matrix premise is a vendor-record claim — the round's own
bug:high premise (a phantom "php.net 8.3.0 chip" on
ZipArchive::RDONLY) survived driver triage AND the fix commit
because nobody fetched the record until the verifier; drive
version-history premises at the stub/constants page before the fix
lands. The verifier pass ran as a two-agent workflow, both lenses
driven and converging independently on the refutation: the
correctness lens's one code finding (a staging fix whose
warn-then-false arm still leaked — null-initialized now) and the
shared premise refutation are fixed; the refutation lens also
corrected two RECORD errors (the exec census count and the
owner-conversion arithmetic). Suite 1655 → 1656 tests, 45534 →
45535 assertions (+1 the shape pin; +0 the rest by construction),
2 skipped unchanged.

- **The zip reader's RDONLY rides the guarded spelling — the
  round's floor premise REFUTED (t31-ocr21-1)** — the finding
  claimed an 8.3-cycle-only registration fataling every 8.2 engine
  in the composer floor's range; refuted by both verifier lenses
  at three levels (php.net: available as of PHP 7.4.3 / PECL zip
  1.17.1 when the zip extension is built against libzip >= 1.0.0,
  no 8.3.0 chip; the php-src PHP-8.2 stub registers it
  identically; the repo's own r19 drive ran the bare constant on a
  real 8.2.33 engine to ER_NOENT, never an \Error). What survives
  is the build corner the vendor record does name: a zip extension
  built against libzip < 1.0.0 compiles no RDONLY — the open rides
  defined('ZipArchive::RDONLY') ? ZipArchive::RDONLY : 0 as that
  corner's guard, verdict-identical everywhere else (driven), with
  a shape pin beside the two open-gate legs (the undefined arm is
  not drivable on an engine that defines the constant — four
  escape shapes driven dead; the re-open rule names the libzip
  build corner, never "a PHP 8.2 engine").
- **The r20-named CLI-build exec/proc_open remainder gated; the
  test-fence census reads zero (t31-ocr21-2)** — six BuildArtifacts
  tests skipped visibly where they fataled mid-test on
  disable_functions hosts (the three all-plugin/explicit-slug CLI
  legs, the stage-tree pin's proc_open pair and 60s live sibling
  on the ocr17-10 trio, the slug-rebuild and failing-rebuild
  twins), every guard before staging; driven: six visible skips
  under the flag. The suite's own spawn statements (20, not the
  commit's 22 — a command-build line double-counted) are all
  behind probes; the residual is the PRODUCER fence:
  inspect-artifact's syntax loop exec()s in-process, 21 suite
  errors under the flag — the r20 fence lesson applied to the
  round's own census, ledgered as the next round's head.
- **The live sibling's spawn verdict rides inside the try, on a
  null-initialized handle (t31-ocr21-3 + verifier sc-1)** — the
  environmental spawn refusal leaked the scratch tree above the
  try, and the warn-then-false spelling (failOnWarning throws at
  the call, before the assignment) leaked it through an
  unassigned-variable error thrown from the finally itself;
  $live null-initializes before proc_open now — every exit path
  reaches the rrmdir, driven both shapes (pre: wrong verdict +
  leaked tree; post: the real verdict + clean).
- **The exec capability guard rides ONE owner
  (t31-ocr21-4)** — WpHarness::canSpawnChildren() beside its
  canSymlink probe twin, with the WpConnectorsTestCase thin
  wrapper: the exec/escapeshellarg pair as the floor, a consumer
  spawning through more naming its own variadic extra
  (canSpawnChildren('proc_open')), skip messages staying at the
  call sites. Twenty-four hand-rolled conversions across seven
  suites (the commit's own "seventeen/twelve" undercount corrected
  in the ledger); the only raw function_exists('exec') spelling in
  the tree is the owner's own body.
- **Path-fragment pins spell their separator the way the producers
  do (t31-ocr21-5)** — the scanner-report and conventions-gate
  fragments ('Tests/leak.conf', 'Clock/DeadImport.php', etc.) were
  hardcoded '/' against raw iterator pathnames joined with
  DIRECTORY_SEPARATOR — red through no defect on Windows; built
  with DIRECTORY_SEPARATOR now (creation paths stay literal — PHP
  accepts '/' on Windows, and zip-entry fragments correctly stay
  literal).

### Fixed (shared — M3 Task 3.1, OCR round 20)

Twentieth OCR-tool round (62/62, complete): 12 findings, driver
triage accepted all — trajectory 27→10→11→7→11→33→9→9→4→12, the
count RE-SPIKING on new ground: the extraction fence's edge-junk
gap is the r16 class at a NEW seam, and the value-lens backslash
twins are a new class. Fixed as t31-ocr20-1..9 — one commit per
finding (classes as noted), nine fix commits plus the two-lens
verifier pass's two follow-up fixes and this docs record, the full
offline check green after every one. The round's lesson, now in the
ledger: a class closed at one fence is not closed at the next — the
census doctrine must name the FENCE, not just the shape. The
verifier pass ran as a two-agent workflow, both lenses driven: the
security/correctness lens's one finding (an exec guard whose skip
stranded the extraction tree — cleanup is finally-owned now) and the
refutation lens's doctrine finding (the near-source judgment
extracted to ONE predicate both fences ride) are fixed; the
refutation lens also corrected two RECORD errors — the ocr20-1
census missed the glob('*.php') main-file-discovery shape (the
tenth extension-judging site, named in the ledger), and the ocr20-5
commit's driven figures were mis-attributed (the "482 assertions"
was the seven-test filter total; the battery alone answers 23 under
the flag). Suite 1654 → 1655 tests, 45508 → 45534 assertions
(per-commit deltas measured from output: +7 the extraction fence,
+7 the alias-slot battery, +6 the value-lens legs, +3 the
root-collapse preconditions, +0 the exec guards by construction, +3
the case-sensitivity rows), 2 skipped unchanged.

- **Near-source PHP spellings refuse extraction
  (t31-ocr20-1, the round's security:high headliner)** — the r16
  edge-junk class, alive at the EXTRACTION fence after being closed
  at the traversal fence: wp_connectors_is_php_source() judges only
  the last four bytes, so 'shell.php ', 'shell.php.',
  'shell.php\x01' entries were PHP sources to every
  path-normalizing host while every gate judged them as not one —
  extracted, unlinted, ACCEPTED at 0 violations (driven). The fence
  lives in the entry loop beside the traversal refusal, refuses the
  artifact whole BEFORE extractTo() runs, and rides the ONE
  near-source predicate the verifier pass extracted (both channels
  — the collector's throw and the inspector's refusal — one
  composition). The control-byte leg carries the UTF-8 flag bit:
  un-flagged, this engine's libzip remaps \x01 through CP437 to the
  U+263A bytes — driven, and named in storedZipBytes's docblock.
- **The alias skip's qualifiedness includes the relative arm
  (t31-ocr20-2)** — an interrupted `namespace\` relative in the
  ALIAS slot whose single-segment tail arrived as a bare T_STRING
  (the separator its own token, trivia after it) was silently EATEN
  as the alias while its re-attached spelling resolved into the
  family: zero references, the sweep waving through what the
  rewriter's own alias-slot fence refused. The skip eats only BARE
  runs now; the three interrupted spellings report like their glued
  twin (red at HEAD: zero references, driven).
- **The value lens folds the leading backslash
  (t31-ocr20-3)** — a literal whose computed VALUE is the
  fully-qualified family name matched no predicate when the
  backslash arrived through an escape the text lens cannot see
  (octal \134, hex \x5C): the class string laundered past both
  gates at zero references. Both value-lens sites (quoted and
  heredoc) fold through the same ltrim every other family fold
  carries; the two escaped spellings refuse the rewrite now.
- **The inspector-twin root-collapse legs carry preconditions
  (t31-ocr20-4)** — each '/' , '/.', '/..' destructive leg now
  asserts its spelling resolves to the filesystem ROOT the silent
  collapse guards, the ocr17-3 doctrine the harness twin already
  carried, BEFORE the removal runs.
- **The named exec-gate heads converted; the battery skip narrowed
  to the rows that need it; the one un-anchored spawn anchored
  (t31-ocr20-5)** — five BuildArtifactsTest tests gated at the leg
  their spawn opens (driven: visible skips with prior assertions
  counted where HEAD fataled mid-test); the publication-invariant
  battery's whole-test skip replaced by a CLEAN-row capability skip
  (the LOUD rows refuse in-process — the build path spawns nothing
  — and now keep their charge on disable_functions hosts, driven);
  the scan-secrets GPC leg's CWD-relative target anchored via
  realpath (from a foreign CWD the scanner's missing-root branch
  silently scanned nothing — driven). Census remainder, seven
  CLI-build sites, ledgered as the next round's head.
- **The superglobal case-sensitivity contract pinned — the round's
  /i premise REFUTED (t31-ocr20-6)** — the finding claimed a
  trailing /i on DIRECT_ENVIRONMENT_PATTERN; driven at three levels
  (engine: $globals/$_server/$_env all answer 0; bytes: the
  case-insensitivity is scoped to the call stems through the inline
  (?i:...) group, no global flag; history: the pattern was born
  that way in t31-r2-7), the premise dies — but the docblock's
  contract was pinned by NO row, so the three lowercase twins join
  the battery's clean list (construction; a future /i or un-scoped
  group now reddens).
- **Three small closes (t31-ocr20-7/8/9)** — the PKCE verifier
  class spells its dash last (the incidental `-.` range made
  explicit-literal, byte-equivalent, driven); the request redaction
  contract states the mask owner's actual threshold rule (last-four
  above the twelve-character OTP line, bare mask at or below); the
  corrupt-artifact row pins ER_NOZIP by its constant, never the
  hand-copied '19'.

### Fixed (shared — M3 Task 3.1, OCR round 19)

Nineteenth OCR-tool round (main 57/62 + bin/ fill-in 7/7, union
complete): 4 findings, driver triage accepted all — trajectory
27→10→11→7→11→33→9→9→4, the decline holds, and bin/ read zero in the
fill-in. Fixed as t31-ocr19-1..4 — one commit per finding, four fix
commits plus this docs record, the full offline check green after
every one. The two-lens verifier pass ran as a two-agent workflow,
both lenses driven: the independent-correctness lens confirmed all
four with zero findings (pre-fix vs post-fix consumer drives in
redirected-TMPDIR child engines, a reflection battery over the
private probe's edge shapes, and a 17-shape hostile spelling battery
on the real runner — byte-identical verdicts pre vs post everywhere
the temp spelling is real); the refutation lens REFUTED ONE PREMISE —
ocr19-1's floor claim, independently re-driven for this record on a
real 8.2.33/libzip engine: the omitted-flags open() on a missing file
returns ER_NOENT and creates nothing, the php-src stubs and UPGRADING
record no 8.3 default change, and the strict gate was already loud on
every engine in the support range. The flag stays as pinned read-only
intent; the doctrine is corrected in this record (the round-18
refutation precedent). Suite 1651 → 1652 → 1653 → 1654 tests, 45493 →
45497 → 45504 → 45505 → 45508 assertions (every per-commit delta
measured from output: +4 the missing-archive pin, +7 the anchor leg,
+1 the round-trip pin, +3 the chaining pin), 2 skipped unchanged.

- **The link probe anchors at the temp root, never at '/'
  (t31-ocr19-2, the round's bug:medium headliner)** — the ocr17-2
  full-chain walk judged every component of the passed chain from
  '/' down, so on a host whose temp spelling itself crosses a
  system-layout link (macOS: TMPDIR under /var → private/var, the
  /tmp fallback → private/tmp) the FIRST link found was the host's
  own spelling: rrmdir silently SKIPPED cleanup of every legal
  scratch tree and copyTree refused every legal source — the whole
  link vocabulary firing on the host layout, the round-17 ledger's
  portability residual, now closed. The walk anchors at the temp
  root (the same existing-component stop the copyTree ancestor walk
  rides): components of the temp spelling itself are skipped as the
  host's layout, everything strictly beneath keeps the full-chain
  reach, and a chain not spelled beneath the temp root keeps the
  walk unchanged. Driven red at HEAD through a redirected-TMPDIR
  child (the macOS shape is only reachable in a fresh engine —
  sys_get_temp_dir() is cached per process): pre-fix the child left
  the scratch tree and fataled on the legal copy; post-fix cleanup
  works, the legal copy lands, and the planted links below the
  anchor keep the doctrine (skip/refuse, victim survives).
- **The zip reader opens RDONLY — premise refuted, intent pinned
  (t31-ocr19-1)** — the accepted premise said the omitted-flags
  open() CREATES an empty archive on the 8.2 floor when the file is
  absent (helper returning [] over a missing zip); driven on a real
  8.2.33/libzip engine it returns ER_NOENT and creates nothing, no
  8.3 default change exists in the engine record, and the strict
  gate was already loud everywhere. The explicit RDONLY stays as the
  read site's own statement of intent, the missing-archive pin holds
  the loud contract (ER_NOENT named, path named, nothing created),
  and the 13 same-shape omitted-flags census sites are re-framed as
  style variance, not latent exposure.
- **The device session's happy path pins the poll credential
  (t31-ocr19-3)** — user_code/verification_uri/interval/expires_at
  were pinned while device_code() — the primary poll credential, the
  one property the device flow exists to carry — never was (the
  fake's random per-call spelling was passed inline); the generated
  code is captured and round-tripped like its siblings now,
  mutation-evident.
- **The family-mismatch verdict chains the original exception
  (t31-ocr19-4)** — refusalOf()'s out-of-family branch constructed
  its AssertionFailedError without the previous argument, discarding
  the real message and stack trace exactly where an unexpected
  exception IS the signal; the original rides the chain now (same
  instance pinned on getPrevious()), the verdict still naming the
  pinned family and the caught class.

### Fixed (shared — M3 Task 3.1, OCR round 18)

Eighteenth OCR-tool round (62/62, complete): 9 findings, driver triage
accepted all — trajectory 27→10→11→7→11→33→9→9, the residual plateau
two rounds deep. Fixed as t31-ocr18-1..5 — one commit per finding,
classes folded (the three exec-gate sites one class commit; the three
staging/spawn-heads one class commit) — five fix commits plus the docs
record, the full offline check green after every one. The two-lens
verifier pass ran as a two-agent workflow, both lenses driven: the
refutation lens found ZERO surviving counterexamples (every DST row
independently re-derived, both disable_functions directions driven,
message/payload byte-identity md5-verified pre vs post); the
independent-correctness lens re-drove every claim and confirmed every
census line number, surfacing one pre-existing runner-state ±1 in the
suite total (ledgered, not a round defect). Suite 1651 tests,
45493 → 45499 → 45493 assertions (every per-commit delta measured from
output: +6 the crossing pins, 0 the guards by construction, −6 the
spawn-loop restructure), 2 skipped unchanged.

- **The headliner data row was never red — and the derivation found
  what seventeen green runs had hidden (t31-ocr18-1, the round's
  test:high)** — the finding claimed the 'sydney fall-back' DST row
  read '01:00:00' against 01:30 constants and failed
  deterministically since birth. Refuted before any fix: the row was
  born internally consistent in the round-1 commit, never edited
  since (git -G over all history), runs on every build (the provider
  is unfiltered — six rows, thirty assertions), and passes. What the
  derivation DID surface, driven: **half the row set never crossed
  its transition** — a fall-back transition sits one ambiguous wall
  hour after any unambiguous pre-transition reading, so the
  3600-second fall-back lifetimes ended one wall hour short of every
  transition (offsets identical across all three windows), and the
  battery's zone-independent delta assertion could not notice — a
  window with no transition exercises no transition. The three
  fall-back lifetimes are 7200 seconds now (readings keep their
  unambiguous spellings; every window crosses strictly), the expiry
  constants re-derived, and a new per-row assertion pins the crossing
  premise itself — the reading's offset and the derived expiry's must
  differ, so a row whose dates ever drift off a transition day goes
  red on the premise instead of passing vacuously. Process lesson,
  ledgered: a data row's premise needs its own pin, or a vacuous row
  stays green indefinitely.
- **The r17-named exec-gate tail converted (t31-ocr18-2)** — the five
  ungated consumers across UnusedImportScanner (two tests),
  BuildSeamProperty (the five-site require-side pin plus the battery's
  per-state php -l loop, its probe at the test top per the doctrine),
  and SecureFixtures (the fresh-process scanner leg) carry the
  established two-function capability guard; driven under
  `-d disable_functions=exec` all five answer visible skips at zero
  assertions (the pre-fix fatal was driven first at a representative
  site). Live ungated remainder, mechanically censused: **15 sites,
  every one in BuildArtifactsTest** — BuildSeamProperty,
  UnusedImportScanner, and SecureFixtures now read zero.
- **Staging inside the try; spawn loops collect-before-assert
  (t31-ocr18-3)** — the conventions-gate scratch repo staged before
  its try/finally owned it (a failed copy leaked the partial tree),
  and both concurrent-build legs asserted each spawn mid-loop (a
  failed spawn aborted the loop with earlier children still writing
  into the repo the finally rrmdirs underneath them). Staging moved
  inside the try; both loops collect spawn refusals and assert after
  the reap — every child finishes before any verdict renders (the
  t31-ocr4-7 doctrine extended to the spawn loop).
- **Two one-owner folds (t31-ocr18-4 + t31-ocr18-5)** — Url's inline
  control-byte screen was a verbatim twin of
  `HeaderMap::assert_no_control_bytes()` (same predicate, same
  rejection sentence); Url rides the shared callable now with its
  field label, the thrown rejection byte-identical. And
  `HasMaskedHeaders::__debugInfo()/__serialize()` carried
  byte-identical bodies; both hooks ride one private
  `masked_debug_payload()` owner. Pure relocations, md5-verified
  byte-identical pre vs post by the verifier.

### Fixed (shared — M3 Task 3.1, OCR round 17)

Seventeenth OCR-tool round (62/62, complete): 9 findings, driver triage
accepted all — the trajectory 27→10→11→7→11→33→9 confirms the round-16
spike was the audit shape (that round censused the named test-hygiene
classes to zero; this round returns to the residual level). Fixed as
t31-ocr17-1..8 — one commit per finding — plus the verifier-pass closes
t31-ocr17-9/-10, eleven commits with the full offline check green after
every one. The two-lens verifier pass ran as a two-agent workflow: the
refutation lens drove ONE surviving counterexample straight through the
round's own fresh landing policy (closed in-round, the round-16
precedent) plus the exec guard's unprobed spawn function; the
consistency lens re-drove every red at the pre-round sources (including
gold-standard red runs of the new test files against a reconstructed
pre-round harness) and recounted every census the commits claim. Suite
1650 → 1651 tests, 45446 → 45493 assertions (every per-commit delta
measured from output; the guard/suffix/docs commits moved zero by
construction), 2 skipped unchanged.

- **The landing policy judges the target's PHYSICAL resolution
  (t31-ocr17-1 + the verifier's t31-ocr17-9 close, the round's
  substantive close)** — the ocr16-5 '/' sentinel judged the SPELLING'S
  chain only, so a `'..'`-woven target whose ancestor walk anchors at an
  existing component while the collapse resolves elsewhere escaped it
  entirely: driven at HEAD, `copyTree($src, '/../<first-level>')`
  anchored at the existing `/..`, collapsed to a first-level nonexistent
  target, passed every containment clause, and died in raw
  `mkdir()`/`copy()` warnings at the root's first level (real first-level
  writes as uid 0) having returned normally. The round's first close
  walked the collapsed resolution by the sentinel's own rule — and the
  verifier's refutation lens then drove a counterexample through THAT:
  the collapse was still lexical, and a `'..'` popping above the
  resolved anchor lets the post-pop descent cross symlinks nothing
  resolves (driven: the copy landed INSIDE the source while the plain
  spelling of the same landing refuses; the file-link variant died in
  raw engine warnings). The target resolution is a resolve-until-stable
  LOOP now — every judgment (root sentinel, file/dangling crossings,
  both containment directions, the landing walk) rides the resolution
  the filesystem actually answers, with the termination argument
  construction-evident in the code.
- **The link probe resolves the FULL component chain (t31-ocr17-2)** —
  the trailing-tail strip family let a symlink at a MID-PATH component
  route the `is_link()` probe through it; driven at HEAD,
  `rrmdir('planted-link/sub/..')` deleted the victim tree's entries
  through the link spelling before dying mid-flight. The probe walks the
  components now: the first ancestor that is itself a link is the
  spelling `is_link()` can trust, wherever it sits — one owner for both
  the rrmdir skip and the copyTree source refusal.
- **Destructive legs pin their refusal precondition (t31-ocr17-3, a
  doctrine)** — the `rrmdir('/')`-family and copyTree degenerate-target
  legs rested their safety entirely on the production refusal under
  test; each leg family now asserts the precondition its refusal claims
  (the spelling resolves to the root / names no directory / the chain's
  first component does not exist) BEFORE the destructive call. Writing
  the pin drove its own discovery, ledgered: `realpath('')` answers the
  CWD on this engine — exactly why the ocr11-22 guard judges that
  spelling lexically.
- **The concurrent-builds leg skips visibly on capability-less hosts
  (t31-ocr17-4, extended by the verifier's t31-ocr17-10)** — the
  manifest-merge race leg spawns through `proc_open()`/`escapeshellarg()`
  and fataled under `disable_functions` before a single verdict; the
  capability probe gates it first, and the verifier drove that the probe
  must name the spawn function this leg actually rides (`proc_open`),
  not just the established exec/escapeshellarg pair.
- **Every pid-only scratch name gained a random suffix
  (t31-ocr17-5)** — the scanner batteries' five `/wp-connectors-scan-*`
  spellings were pid-enumerable, pre-plantable, and collidable across
  concurrent suites; all five ride the `canSymlink` probe's own idiom
  (`getmypid() . '-' . bin2hex(random_bytes(4))`).
- **The version pins derive from the header the build itself reads
  (t31-ocr17-6)** — fourteen live `'0.1.0'` sites (artifact names, a
  staging path, sidecar blockers, header patch pairs) were hardcoded
  against the fixture's and the real zai plugin's headers, so a version
  bump broke the suite in non-obvious ways (a patch silently matching
  nothing, its leg vacuous green). `headerVersion()` reads the plugin
  header at runtime; driven by bumping the fixture to 9.9.9 and running
  the whole file green.
- **The overflow leg rides its named predicate (t31-ocr17-7)** —
  `offset_would_overflow()` joins `offset_would_underflow()`
  (t31-ocr2-6) as the single owner of the representability guard's
  upper condition, boundary-pinned on both sides of the flip.
- **The serializability bounds' 64-bit domain is stated
  (t31-ocr17-8)** — composer's `>=8.2` pins the engine, never the int
  width; on a 32-bit build both bound constants silently become floats.
  Docblock-only (no 32-bit path exists; ledgered with a re-derivation
  rule).

### Fixed (shared — M3 Task 3.1, OCR round 16)

Sixteenth OCR-tool round (62/62, complete): 33 findings, raw count but
~15 distinct classes (9 ungated-write sites + 3 staging-leak heads +
2 exec-guard heads + 5 doc-glitch singles inflate the raw figure) —
driver triage accepted all. The substantive core: one security:high
(the inspector's edge-junk `'..'` bypass), one test:high whose premise
the round REFUTED on the runner (the `var_export()` enum pin), three
bug:medium, and the tail of low test/doc hygiene. Fixed as
t31-ocr16-1..15 — one commit per finding (classes folded: the ×16
ungated writes in one census commit; the ×2 exec and ×2
staging/pid heads one commit each; the seven tail singles batched
where doctrinal), twenty commits including the verifier-pass close,
the full offline check green after every commit. Trajectory
27→10→11→7→11→33 — the spike is the audit shape: the round censused
the test-hygiene classes prior doctrines had named but never driven to
zero. The two-lens verifier pass: the refutation lens caught ONE
surviving counterexample in the round's own tail gate (keyword aliases
that lex as plain `T_STRING`) plus the closure's own variable
collision, both closed in-round; the correctness lens re-drove every
red at the pre-fix sources and ran a 6-probe boundary battery — zero
false refusals, zero fix findings. Suite 1645 → 1650 tests,
45385 → 45446 assertions (every per-commit delta measured from
output), 2 skipped unchanged.

- **The `'..'` traversal refusal owns every spelling that RESOLVES to
  `'..'` (t31-ocr16-1, security:high)** — the byte-exact segment
  check judged only the plain `'..'` while the duplicate fence's own
  edge-junk vocabulary knows the trailing junk collapses: `'.. '`
  (trailing space) and `'...'` (dots-only run) are the PARENT token
  to every path-normalizing host, and a zip carrying them judged at 0
  traversal violations (driven on a scratch zip). The refusal re-rides
  the ONE edge-junk owner's class, derived (class minus the dot — the
  dot is stripped and counted as the run's own byte): trailing
  space/control junk first, the LEADING side stays, then a dots-only
  remainder of two or more dots refuses — `'.'`, `'x..'`, `'..x'`,
  `'a.b'` judge as content (twelve boundary spellings unit-probed).
- **The `var_export()` enum pin is green and its engine premise is
  pinned (t31-ocr16-2, refutation of record)** — the claim that
  var_export() throws on enums since 8.1 is false on this runner and
  per the engine record (the 8.1-era defect was the missing leading
  backslash, corrected in 8.2.0 — the project floor); the export
  carries the raw token and eval refuses through the nested set's
  `__set_state()` (driven). One premise assertion now fails as itself
  if an engine ever stops exporting enums — never as an opaque
  mid-leg Error.
- **The corrupt-artifact FAIL row is reachable and unmasked
  (t31-ocr16-3, bug:medium)** — the classifyClean reopen branch sat
  below the shared zipEntryNames owner (whose open gate aborts the
  battery as a raw assertion on any corrupt zip) and carried
  `close()` on the never-opened handle (a ValueError on PHP ≥ 8,
  probed). The soundness reopen owns the first zip judgment now
  (a pure block relocation — no existing verdict moved), and the
  failure arm closes only a handle that opened.
- **The relative-use splice owns the statement tail
  (t31-ocr16-4, bug:medium)** — `use namespace\Clock SystemClock;`
  shipped at exit 0 as legal-looking output with the parse-error
  rider intact beside the rewritten name (php -l rejected the shipped
  line, driven). The tail is judged through the lexer's own
  boundaries: optional `as` + one plain identifier, terminated by the
  ONE boundary set or a comma (a comma-listed member rewrites in
  place, pinned green); anything else refuses naming the rider bytes.
  The verifier pass closed the round's own first cut twice: keyword
  aliases that lex as plain `T_STRING` (`as self`/`as True`/`as Int`)
  now refuse through a php -l-derived fourteen-word identifier-slot
  vocabulary (case-insensitive, the ASCII owner's fold), and the
  closure's `$tail_display` name collision (which silently dropped
  every aliased import's last below-root segment) is dead — the
  walk's variable is `$rider_display`.
- **The ancestor walk's `'/'` sentinel refuses (t31-ocr16-5,
  bug:medium)** — an all-nonexistent target chain walked to the
  sentinel, passed every guard, and died in raw mkdir()/copy()
  warnings at the filesystem root's first level, RETURNING NORMALLY
  having moved nothing (driven). The sentinel gets its siblings'
  vocabulary: a policy refusal naming the chain, before the iterator.
- **A dangling symlink in the target chain refuses with the policy
  vocabulary (t31-ocr16-6, bug:low)** — the walk stops at the link,
  `is_file()` follows it (false), realpath answers false, and the
  landing once died through the link in raw engine warnings with a
  normal return (driven). Link-to-FILE keeps the file verdict,
  link-to-DIR keeps resolving through realpath (the alias doctrine);
  the dangling twin refuses as a malformed chain first.
- **`\u{0}` resolves to the NUL byte (t31-ocr16-7, bug:low)** — the
  exclusive `> 0` range guard kept the literal `\u{0}` bytes in the
  value; the range is `>= 0` WITH a digit-presence term, so the
  empty-braces `\u{}` (a compile error the engine never resolves)
  and the over-range spellings stay literal — both boundaries pinned.
- **The empty-tails sibling pattern is the baseline
  (t31-ocr16-8, maintainability:low)** — an empty `$excluded_tails`
  built the exclusion lookahead over an EMPTY alternation, and the
  pattern silently refused every stem+continuation spelling (the
  separator-position artifact, driven); the clause exists only when a
  tail does, pinned with the with-tails control.
- **The interrupted relative operator's separator is REQUIRED
  (t31-ocr16-9, maintainability:low)** — `use namespace Clock;` (a
  parse error php -l rejects) was silently rewritten into a legal
  import at exit 0 (driven). The gate owns BOTH lexer spellings the
  separator arrives in: the standalone token behind a comment, and
  the one baked into a fully-qualified piece after a whitespace
  interruption — every interrupted row and the fused/aliased/
  function/const controls unchanged.
- **The classifier's brace stack survives interpolation
  (t31-ocr16-10, bug:low)** — `"{$a}"` lexes T_CURLY_OPEN plus a
  plain `'}'`, and the plain closer popped a frame never pushed: the
  stack ran one short per interpolation, and a trait clause list
  after an interpolation-bearing method wore the import-list errand
  (driven). Interpolation openers push their own frame on both
  spellings; the trait verdict is the twins' shared doctrine again.
- **The by-value capture premise refuted, the shape pinned
  (t31-ocr16-11, test:low)** — a by-value closure `use` capture
  re-initializes per call (probed; the described mutation is
  by-reference semantics the site never carried). The closure derives
  from the untouched source per call and the provider gains the
  second `$without` row pinning two consecutive calls end-to-end.
- **The exec-capability doctrine reaches the lint drivers
  (t31-ocr16-12, test:medium)** — under `disable_functions(exec)` the
  first child-process call was an undefined-function Error (driven at
  HEAD); both drivers skip visibly at the gate now, and the
  exclusions driver's skip message states plainly that the
  still-fails controls verdict through a spawned engine too — the
  canSymlink promise is only reached on hosts where it holds.
- **The SharedOAuthArchitectureTest write census reads ALL GATED
  (t31-ocr16-13, test:low)** — sixteen ungated fixture writes
  converted (the census lists every site; the four prior gates kept):
  a failed write left its probe EMPTY and every leg failed with a
  misleading verdict instead of naming the write.
- **The lint drivers' scratch trees are random-suffixed and staged
  inside the try (t31-ocr16-14, test:low)** — the pid-only names were
  the pre-plantable spelling and the staging rode outside the owning
  finally; zero leaked trees verified after the run.
- **The seven tail singles (t31-ocr16-15, test/documentation:low)**
  — the battery row-shape docblocks carry the skip flags and the
  'SKIP' verdict; the inspector's `@param` names the unique-sibling
  extraction contract; the exactly-once patch claim is a real
  substr_count pin; the capture-handler pin matches STRUCTURE by
  regex (variable-token-proof); the brace-line glitch and the
  four-backslash fixture row are aligned with their siblings; the
  short-write wrapper's `url_stat()` answers the protocol's `false`
  (stat unavailable), adjudicated over the ledger option.

### Fixed (shared — M3 Task 3.1, OCR round 15)

Fifteenth OCR-tool round (62/62, complete): 11 findings, driver triage
accepted all — one security:medium headliner, one maintainability:medium +
low pair, one maintainability:low ×2 pair, and test/style hygiene for the
rest. Fixed as t31-ocr15-1..8 — one commit per finding (three class
folds: the expectation-domain pair, the ×2 fixture-write gates, the
refusalOf sweep), eight commits, the full offline check green after every
commit — plus the round's two-lens verifier pass, clean on both lenses
for fix behavior (correctness: zero fix findings, both behavior reds
re-reproduced on a scratch worktree at the pre-fix sources; refutation:
a 36-probe boundary battery over the new suffix rule with zero surviving
counterexamples, and the bin/ ASCII-fold census re-derived over the whole
tree — three live folds remain, ledgered). The trajectory 27→10→11→7→11:
the round's substantive security close is the sensitive-name CLASS rule.
Suite 1643 → 1645 tests, 45543 → 45385 assertions (the −221 is the
refusalOf family check's spelling moving from assertInstanceOf to the
owner's identical instanceof verdict — no pin lost), 2 skipped unchanged.

- **The sensitive-header policy judges the CLASS, not the spelling
  (t31-ocr15-1, security:medium)** — the closed six-entry catalog let
  'api-key' (the documented authentication header of a whole cloud-AI
  vendor class, an archetypal 3.7 transport binding) render its full
  secret verbatim through every safe debug form while 'x-api-key' sat
  covered — the r12-4 leak class reopened under another
  vendor-documented name. `SecretMask` gains the suffix rule: any folded
  name that IS a credential token, or whose final hyphen-token is one
  (`api-key`, `subscription-key`, `auth-token`, `auth`), is sensitive —
  future vendor spellings mask the day they appear. The boundary is the
  hyphen ('x-api-keychain' is not the class); no extension seam is added
  (3.7's decision, ledgered).
- **The save() expectation domain is contract, both sites of the pair
  (t31-ocr15-2, maintainability:low ×2)** — an expected_generation below
  `EXPECT_NO_GRANT` (-1) names no observable state, so a typo'd -2
  answered a SILENT false a caller could retry on forever — the exact
  caller-bug class the identity and monotonicity rules reject typed. The
  contract states the domain; the reference fake rejects below-sentinel
  expectations with the typed rejection, nothing committed.
- **The is-a-php-source fold rides the ASCII owner, with the census
  (t31-ocr15-3, style:low)** — `wp_connectors_is_php_source()` folded
  through locale-consulting `strtolower()`; it rides
  `wp_connectors_ascii_lower()` now (behavior-identical, pinned by the
  '.PHP' extension tests). The census: the bin/ tree does NOT read zero —
  the secret scanner's extension gate and the two plugin-header name-key
  folds remain, each ledgered for its own finding.
- **The grant serialize pin names WHICH guard fires (t31-ocr15-4,
  test:low)** — the engine rebuilds nested members before the enclosing
  `__unserialize()` hook, so a Connected grant never reaches
  `StoredGrant::__unserialize()` (the token set's own refusal fires
  first, probed); the pin asserts each leg on its own fragment and adds
  the set-less shape that DOES reach the grant's hook.
- **The sweep's README leg existence-checks its path (t31-ocr15-5,
  test:low)** — an absent README made realpath() answer false and the
  failure land late and misleading inside the reader; the check is loud
  at the array-build site, naming the path.
- **The last two unchecked fixture writes are gated
  (t31-ocr15-6, test:low ×2)** — the PCRE burner and the offender loop
  left empty tempnam files on a failed write, failing with misleading
  'no abort'/'no violation' verdicts; both mirror their clean twins'
  ocr12-7 gates.
- **The refusal verdict has ONE owner across the suite
  (t31-ocr15-7, maintainability:medium + low)** — the implementation is
  hoisted to `WpHarness::refusalOf()` (the canSymlink hoist shape; two
  offending suites extend the bare TestCase, not the wrapper), the
  wrapper delegates, and every hand-rolled
  `$caught = null`/try/catch/fail-if-null shape in tests/ rides the
  owner with its original catch's family — the census grep reads zero.
- **The '/..' control leg's scratch pair rides a finally
  (t31-ocr15-8, test:low)** — an assertion failure between creation and
  inline cleanup leaked both temp trees; every exit path removes them
  now.

### Fixed (shared — M3 Task 3.1, OCR round 14)

Fourteenth OCR-tool round (62/62, complete): 7 findings, driver triage
accepted all — one security:medium headliner, one bug:medium (REFUTED at
HEAD, see below), one bug:low, two maintainability:low, two
documentation:low. Fixed as t31-ocr14-1..7 — one commit per finding,
seven commits, the full offline check green after every commit — plus
the round's two-lens verifier pass, clean on both lenses for fix
behavior (correctness: zero fix findings, every red re-reproduced on
scratch copies; refutation: zero surviving counterexamples across
live-probed URI spellings, root spellings, manifest line shapes, and
all-digit header names), with two latent pre-existing residuals
ledgered (the manifest merge's prefix-skip edge under a contrived
double-space-prefix name collision; a readonly-blind type pattern in
one Zai test's candidate collector). The trajectory 27→10→11→7: the
round's substantive security close is the masked_view URI leak, and
the round carries the loop's first REFUTED driver finding. Suite
1639 → 1643 tests, 45516 → 45543 assertions (every per-commit delta
measured), 2 skipped unchanged.

- **The device session's masked view renders the verification URI
  redacted (t31-ocr14-1, security:medium)** — `masked_view()` rendered
  the RAW provider-supplied `verification_uri` verbatim into every
  masked channel (dump, serialize, every container). `Url::parse_validated()`
  accepts userinfo and query strings by design, so a credential-carrying
  URI (throttled providers embed one-time tokens there; RFC 8628's
  `verification_uri_complete` rides the same class) rendered its
  credentials in cleartext into the exact surfaces the VO's masking
  exists to protect. The view now renders the scheme://authority/path
  rebuild from `parse_validated()` — `HttpRequest::redacted_url()`'s
  own shape — dropping userinfo, query, and fragment; the raw property
  stays intact for the authorization redirect. The session was the
  class's one site (every other VO swept); the ledger carries the
  one-owner note for the 3.2 providers (extract `Url::redacted()` when
  a third renderer appears, not before).
- **The token-set merge contract was deliverable all along
  (t31-ocr14-2, bug:medium — premise refuted)** — the claim that no
  receiver/argument pattern delivers the documented keep-on-null, with
  one merge leg red, did not survive contact with the tree: the null
  branch keeps the stored token, both rejection legs reject, and the
  three existing pins were green at HEAD (the strongest defense —
  json_decode's missing-key/null conflation — maps both spellings to
  keep-stored, which is the contract; a refresh response never revokes
  by omission, RFC 7009 owns revocation). The commit records the
  derivation and closes the two legs the pin was missing: null-on-null
  stays null, and a whitespace-only replacement rejects per the
  @throws spelling (only '' was pinned).
- **The manifest regeneration prune splits at the writer's separator,
  from the right (t31-ocr14-3, bug:low)** — `strstr($line, '  ', true)`
  cut at the FIRST double space, so an entry name containing one
  ('double  space.zip') split as its own prefix and the prune dropped
  a LIVE entry as stale. The writer joins `name . '  ' . sha256-hex`,
  so the LAST double space is the separator it wrote; strrpos reads
  it, whatever the name carries (names ending in spaces recover
  byte-exact). Foreign spellings the writer never produces flip
  keep→drop under the r12-7 malformed-line rule (ledgered).
- **The lint walk's below-root offset rides the sibling's rtrim
  spelling (t31-ocr14-4, maintainability:low)** — a root handed in
  with a trailing separator made the bare `strlen($root) + 1` start
  one byte late, eating the first byte of every relative path's first
  below-root segment ('vendor/x.php' read as 'endor/x.php', dodging
  the exclusion); green before only by accident (that first segment is
  a plugin dir in every production root). The pin drives a scratch
  copy with the trailing-separator spelling and the excluded tree as
  the first segment; the degenerate '/' root becomes correct too.
- **The type-declaration vocabulary has ONE owner
  (t31-ocr14-5, maintainability:low)** — the extension-owner test
  still carried the pre-r4-10 single-modifier spelling (trait- and
  readonly-blind) as an inline regex beside the gate's current one;
  the pattern is now `TYPE_DECLARATION_PATTERN`, the gate and the
  vocabulary pin ride the const, and the stale site CALLS the gate —
  with trait and readonly-final fixtures driven through that same
  site (both invisible to the stale copy).
- **The clock port's now() contract states absolute time
  (t31-ocr14-6, documentation:low)** — readings represent true
  absolute time (the UTC instant itself); an adapter over a host API
  returning local wall time MUST convert before returning, with the
  named trap that WordPress's localized 'mysql' wall-clock spelling
  is server-local time, not absolute. Worded inside the architecture
  fences (which scan docblocks — the first spelling tripped both; the
  commit records it).
- **HeaderMap's folded-index property annotation matches the corrected
  contract (t31-ocr14-7, documentation:low)** — `@var
  array<int|string, array{0: string, 1: string}>` with the all-digit
  own-spelling rule stated: a digit string has no case to fold and
  lands under its PHP-canonical integer key, the shape `headers()`'s
  ocr10-11 @return already carries.

### Fixed (shared — M3 Task 3.1, OCR round 13)

Thirteenth OCR-tool round (62/62, complete): 11 findings, driver triage
accepted all. Fixed as t31-ocr13-1..9 — one commit per finding (three
class folds: the strcasecmp sweep's two verdict-label sites rode
ocr13-1's commit, the localedef twin rode ocr13-4, the ×28
ZipArchive::open sites rode ocr13-5), nine commits, the full offline
check green after every commit — plus the round's two-lens verifier
pass, clean on both lenses for fix behavior (correctness: zero
findings; refutation: zero refuted — an exhaustive byte-pair
counterexample search under the live Turkish locale found no
old-vs-new fold divergence on this engine — with two reportable
items ledgered: the ocr13-5 commit message's census figures were
stale round-base numbers, corrected in the ledger to 31 hits / 3
conformant / 28 converted; and the ocr13-6 catch width is a named
residual — non-assertion Throwables inside an extra closure still
battery-abort, unreachable with the current closures by design).
The trajectory
27→10→11 is residual level: the ASCII-fold class surfaced one more
site-cluster (the build's collision fences), and the ocr12
census-artifact doctrine now rides that class's sweep — one commit
converts every strcasecmp-feeding-verdict in `bin/`, the census lists
them, the tree reads zero outside the ASCII owner. Suite 1638 → 1639
tests, assertions 45475 → 45516 (all deltas exact and re-measured at
every commit; the r11 +1 trace did not reproduce — the round base
measured exactly 45475), 2 skipped unchanged.

- **The strcasecmp locale-fold class is closed in `bin/`, with its
  census committed (t31-ocr13-1, bug:medium ×2 + bug:low, ONE
  commit)** — both collision fences (the LICENSE injection deference
  and the embed-destination refusal in `bin/build.php`) and the
  development-entry vocabulary fold owner
  (`wp_connectors_segment_is_named()`, which both release gates, the
  lint, and the repo-walk prune ride) fed their verdicts through
  locale-consulting `strcasecmp()` instead of the ONE ASCII owner
  (`wp_connectors_ascii_lower()`, the r11-6/ocr10-4 doctrine). On a
  locale-consulting engine a Turkish `tolower('I') = 0xFD` reads the
  plugin's `license` as foreign and injects the repo LICENSE beside
  it (the r6-1 both-entries overwrite one fold away), and reads a
  case-variant plugin-owned embed destination as foreign, shipping
  both entries (the r5-16 defect). The sweep's census (grep pattern +
  all five code sites with dispositions) lives in the commit message:
  the finding's three named sites plus the two anonymous-verdict
  label folds in the classifier — post-fix `bin/` carries zero
  `strcasecmp` outside the doctrine's own docblocks. The regression
  is the r11-6 posture executed honestly: C-locale controls for both
  territories, then the manufactured live tr_TR.ISO-8859-9 pressure
  half requiring both verdicts unchanged — with the round's own probe
  recorded in the commit (on this 8.5.10 engine `strcasecmp()` folds
  through the engine's internal ASCII table and the locale-driven red
  is NOT producible; the divergence is real at the C level and the
  fix is doctrine for the 8.2 platform floor — re-open with an engine
  whose string folds consult the locale).
- **The `shared-tree-symlink-dir` battery row carries
  `needs_symlink` (t31-ocr13-2, test:medium)** — its bare `symlink()`
  call fatals the whole battery on symlink-incapable hosts
  (`Error: Call to undefined function symlink()` — driven at HEAD
  under `-d disable_functions=symlink`) instead of the row skipping
  itself the way its `needs_symlink` siblings do. The row now rides
  the runState skip path: driven green under the same flag at 26
  assertions, the link rows skipping visibly, no row's setup fataling
  the battery.
- **`same_directory_spelling()` collapses root-separator spellings to
  the root they name (t31-ocr13-3, bug:low)** — `/.` and `//`
  collapsed to `''` (nothing), `is_dir('')` is false, and
  `rrmdir('/.')` silently returned — asymmetric with the loud
  refusals its siblings carry. They collapse to `/` now and ride the
  existing ocr10-1 root refusal, which names the CALLER's spelling
  (the copyTree precedent — both loud refusals read the pre-collapse
  spelling). Driven red at HEAD: the silent no-op caught by
  `refusalOf`; green with the fix.
- **The localedef `exec()` is capability-probed before it runs
  (t31-ocr13-4, test:low, both pre-existing sites)** — a host with
  `exec`/`escapeshellarg` in `disable_functions` fataled the
  locale-pressure legs with an undefined-function Error instead of
  the ocr6-12 visible skip. `function_exists()` on both functions the
  call rides, placed before the mkdir so a skipped leg leaves nothing
  behind; driven: fatal at HEAD, both legs skip visibly with the fix.
- **The unchecked inline `ZipArchive::open()` class is closed in
  BuildArtifactsTest, census committed (t31-ocr13-5, test:low, 28
  sites in ONE commit)** — read-mode reopens, create-mode fixture
  writes, hostile-zip creates, and the truthy-passing
  `assertTrue($zip->open())` entry reads all contradicted the
  strict-open doctrine the same suite pins (open() returns a TRUTHY
  ER_* int on failure — the old shapes passed it or walked an empty
  archive, every entry assertion vacuously green). Every site now
  carries `true === ($opened = $zip->open(...))` naming the raw
  return; the census in the commit lists all 30 hits (28 converted,
  the 2 already-conformant).
- **`classifyClean()` speaks one failure protocol: FAIL rows
  (t31-ocr13-6, test:low)** — the inspector-acceptance `assertSame()`
  and the row `extra` closures' assertions aborted the whole battery
  on failure, masking the states behind them. The inspector
  disagreement returns a FAIL row carrying the violation lines, and
  the extra closure runs inside a catch of PHPUnit's
  `AssertionFailedError` that converts its message to a FAIL row.
  Driven once with a planted failing extra: the failure surfaced as
  the aggregate's own row verdict (`[control-unmutated-rebuild]
  expected CLEAN: …`), the battery completing — then restored.
- **Dead `$refused = null` initializations dropped (t31-ocr13-7,
  test:low, ×2)** — both sat immediately before
  `$refusal->getMessage()` after `refusalOf()` (which fails the test
  on no refusal), an unreachable state; the live try/catch `= null`
  sites are untouched.
- **The still-fails lint controls run ABOVE the capability skip
  (t31-ocr13-8, test:low)** — the `canSymlink()` skip fired before
  the no-symlink-needed controls (broken-too.php, the ocr8-7 nested
  tests-named leg), so on symlink-incapable hosts they never ran.
  Hoisted, with the control files removed after their verdict so the
  dir-link leg lints the pristine tree; driven under
  `-d disable_functions=symlink`: 3 assertions then skip at HEAD, 6
  assertions (controls charged) then visible skip with the fix.
- **`copyTree()`'s `@param` words the relative-target truth
  (t31-ocr13-9, documentation:low)** — the docblock claimed an
  "Absolute target directory" while the contract has supported
  relative targets since t31-ocr11-5; the `@throws` line already
  carried it. Docblock-only, no behavior change.

### Fixed (shared — M3 Task 3.1, OCR round 12)

Twelfth OCR-tool round (62/62, complete): 10 findings, driver triage
accepted all. Fixed as t31-ocr12-1..9 — one commit per finding (two
class folds: the ocr12-2 probe rode ocr12-1's sweep commit, the ×2
`file_put_contents` class rode ocr12-7), eight commits, the full
offline check green after every commit — plus the round's two-lens
verifier pass, clean on both lenses (zero findings, zero refuted).
The trajectory 27→10 is the round-10 rule working: the older
doctrines finished eating their seams, and this round's findings are
mostly residuals of prior rounds' own fix classes. The recurring-class
lesson the round record carries: a SWEEP commits its CENSUS (grep
pattern + full site list) in the commit message, never just the
converted count — the canSymlink sweep needed three passes before the
tree read zero because no artifact existed to re-derive against.
Suite 1637 → 1638 tests, assertions 45466 → 45475 (measured; the r11
+1 trace did not reproduce this round), 2 skipped unchanged.

- **The canSymlink ONE-owner sweep is COMPLETE, with its census
  committed (t31-ocr12-1 + t31-ocr12-2, test:high)** — the last two
  inline `@symlink()` capability probes outside
  `WpHarness::canSymlink()` (the lint dir-link leg in
  ToolchainSmokeTest, which extends PHPUnit's TestCase directly but
  reaches the harness; the collector-refusal guard in
  SharedOAuthArchitectureTest, which rides the inherited owner) fatal
  under `disable_functions=symlink`: @ cannot suppress a
  missing-function `\Error`, so each probe ERRORED the very test it
  existed to guard (driven red at HEAD). Both ride the owner now,
  skipping VISIBLY (the lint leg was previously a SILENT skip on
  capability-missing hosts — and pid-predictably named, the
  ocr10-18 shape). The sweep commit carries the grep pattern and the
  full site list; the tree reads zero raw probe spellings outside the
  owner.
- **copyTree() refuses a source collapsed to the filesystem root
  (t31-ocr12-3, bug:medium)** — the THIRD symmetry: `rrmdir()`
  refuses `/`, the target side refuses a root-collapsed landing
  (ocr9-9), but a source spelling resolving to `/` (`'/'`, `'/..'`, a
  temp-parent `'..'` where temp sits at the root's edge) passed every
  guard and walked THE WHOLE ROOT TREE — driven red at HEAD on a
  scratch target: 135 root-tree entries (`/usr/include/…`) landed
  before a symlinked entry stopped the walk. The source resolution
  carries the same universal-container clause (`realpath($from) ===
  '/'` → the policy RuntimeException naming the caller's spelling),
  placed before the iterator is constructed. The pinned legs are
  root-anchored (the r11-2 portability doctrine: a temp-parent `'..'`
  is destructive exactly where the temp tree is deep).
- **The root-runner skip fires BEFORE the archive exists
  (t31-ocr12-4, bug:medium)** — the forced-close chmod-0000 leg's
  skip fired after `open()+addFile()`: `markTestSkipped()` throws,
  and the owning finally's `rrmdir()` deleted the destination while
  the ZipArchive handle was still open on it. The guard is hoisted
  above the archive's creation — no handle exists at skip time; all
  four `skipChmod0000LegOnRootRunner` sites inspected, only the one
  had the mid-open shape.
- **The serializability ceiling guard is overflow-free
  (t31-ocr12-5 + t31-ocr12-6, maintainability:low + test:low)** — the
  comparison was respelled from `expires_in > ceiling - obtained_at`
  (an obtained-at deep enough — below `253402300799 - PHP_INT_MAX`,
  ~year -292277022365 — promoted the difference to float, correctness
  surviving only by guard ORDER) to `obtained_at > ceiling -
  expires_in`: the subtraction now runs on the operand the
  constructor has already bounded (a validated positive int), so it
  bottoms at `ceiling - PHP_INT_MAX`, above `PHP_INT_MIN`, never
  float — the reading is never an arithmetic operand. Verified
  equivalent on every already-pinned edge; both sides of the deep
  corner are pinned by the new boundary legs (one second inside the
  int domain, one past — the refusal exact, never a
  float-promotion artifact).
- **Clean-direction controls gate their fixture writes
  (t31-ocr12-7, test:low)** — two clean-direction controls in
  SharedOAuthArchitectureTest wrote fixtures UNCHECKED: a failed
  `file_put_contents` left the tempnam EMPTY, the gate swept empty
  content, and the clean direction passed VACUOUSLY. Both writes
  gated with `assertNotFalse` naming the path; the sweep inspected
  the file's other unchecked writes (all in legs whose own
  assertions fail loudly on empty content — only the clean direction
  goes green on a failed write).
- **The corrupt-zip fixture name is unpredictable
  (t31-ocr12-8, security:low)** — the fixture rode a pid-only
  suffixed scratch path, the naming shape t31-ocr10-18 rejects
  (enumerable, pre-plantable under shared `/tmp`); random suffix
  added, the canSymlink probe's own shape.
- **RefreshPolicy's initial backoff is documented as a seed, not a
  floor (t31-ocr12-9, documentation:low)** — the docblock claimed the
  value is "also the policy's floor", but nothing enforces one and
  the SPEC intent contradicts it (Retry-After and fallback backoff
  share ONE cap; a negative parsed delta means "retry immediately",
  ocr12's check of the plan confirmed no caller exists yet — the
  sequencing is Task 3.3's). The docblock states what IS: the clamp
  range is the cap's alone, the initial bounds only the sequence
  that grows from it.

### Fixed (shared — M3 Task 3.1, OCR round 11)

Eleventh OCR-tool round (61/61, complete): 27 findings, driver triage
accepted all. Fixed as t31-ocr11-1..20 — one commit per finding (the
same-class groups ride one commit each), the full offline check green
after every commit — plus the round's two-lens verifier pass, whose
findings are all fixed in-round as t31-ocr11-21..26. The count
trajectory 19→27 is not divergence: the reviewer now reaches seams the
older doctrines never touched — the group-use composition internals
(two of the four bug:high/medium detector findings live inside `use
P\{…}` bodies) and the HOST-PORTABILITY of the test suite itself — and
the loop converges when a round finds nothing NEW, not when counts
fall. The round's headline lesson (r11-2): a TEST was hostile to its
own host — `sys_get_temp_dir().'/..'` legs are destructive exactly
where the temp tree is deep (macOS), the production root guard being
CORRECT — tests carry the portability doctrine the production code
already keeps. Suite 1634 → 1637 tests, assertions 45417 → 45466 (all
recorded absolute figures re-measured +1 on this runner today,
uniformly from the pre-round base — see the round record in the
refutation ledger; every per-commit delta exact), 2 skipped unchanged.

- **A RELATIVE group-use member resolves through the DECLARED
  namespace, never the group prefix (t31-ocr11-1 + t31-ocr11-21,
  bug:high)** — `use Psr\Log\{namespace\WpConnectors\Shared\Clock};`
  in a file declaring `namespace Deicod;` resolves to the FAMILY name,
  but the walk composed `Psr\Log\namespace\…` — a name no predicate
  matches — and the spelling laundered past both gates at zero
  references. The first cut guarded the fused lowercase token only;
  the verifier lens drove four more spellings of the same operator
  still laundering (the UPPERCASE/mixed-case fused keyword — the
  lexer's T_NAME_RELATIVE is case-insensitive; the interrupted
  `namespace \…` and comment-separated spellings — keyword +
  separator + name, the fused token never forms; the bare
  keyword + bare name) and, deepest, the LEDGER opening a declaration
  from the bare-keyword spelling inside the use statement, re-basing
  every LATER relative (the r8-10 misattribution class on the round's
  own resolution owner). Relativeness rides the TOKEN ID plus a
  pending arm the keyword sets inside an open use statement, and the
  ledger skips every namespace keyword inside one (declarations are
  file-level); seven spellings pinned family-detected, all red at
  HEAD.
- **The root-collapse legs are ROOT-ANCHORED, never a temp-parent
  '..' (t31-ocr11-2, bug:high)** — the `sys_get_temp_dir().'/..'`
  legs collapsed to '/' only where the temp dir sits directly beneath
  it; on hosts whose temp tree is deep (macOS TMPDIR) the spelling
  resolved to the temp dir's REAL parent, passed the production guard
  (correct, matching its contract), and the TEST ITSELF walked and
  deleted the host's tree. POSIX resolves '.' and '..' AT the root to
  the root on every host — '/', '/.', '/..' drive the root clauses
  harmlessly everywhere; the production code is unchanged.
- **The duplicate-entry fence folds CASE, TRAILING EDGE-JUNK, and
  SEGMENT COLLAPSE (t31-ocr11-3 + t31-ocr11-24,
  security:medium/low)** — Windows strips trailing dots/spaces/controls
  per component, and '.'/empty segments name the same file at
  extraction on EVERY host (driven here: `p/./logo.png` beside
  `p/logo.png` lands one file, the first copy's bytes judged by
  nobody); the fence's key composes the codebase's own owners —
  ascii_lower + path_edge_junk per segment, the dot/empty segments
  dropped — with '..' outside the fold (the traversal refusal owns
  it). Trailing-dot, trailing-space, dot-segment, and empty-segment
  twins all refuse, red at HEAD.
- **The empty-body fence arms on COMPOSITION (t31-ocr11-4,
  bug:medium)** — an absolute-only (or, since this round,
  relative-only) group body once armed `$group_member_seen` and the
  FAMILY-spelled prefix went unreported through every gate; the fence
  arms on members actually composed with the prefix now — one verdict
  path: a composed member carries the prefix's spelling, a body
  nothing composes trips the fence and the prefix reports itself,
  judged exactly once.
- **copyTree() judges and names its target through the TRUE tree
  (t31-ocr11-5 + t31-ocr11-22, bug:medium)** — a not-yet-existing
  RELATIVE target bottomed out at the one-byte '.' ancestor whose
  strlen ate the first byte of the remainder ('dst' → 'st'), and both
  containment verdicts judged a tree the caller never named (driven:
  a legal copy wrongly refused through the mangled spelling). The
  walk judges a cwd-prepended ABSOLUTE spelling; the verifier lens
  then drove the walk's own regression — the EMPTY and
  root-separators-only targets passed both checks and attempted
  FILESYSTEM-ROOT writes (pre-fix they were refused by a lexical
  accident the walk removed) — and the degenerate target refuses
  explicitly, naming both paths.
- **The suite's own hygiene class (t31-ocr11-6/7/16/17/18/19,
  test:medium/low)** — classifyClean()'s reopen/extract gates (a
  failed open walked an empty dir and the soundness half passed
  vacuously); the battery's artifact name derived from the fixture's
  Version header (a fixture bump reddened 5+ rows as phantom
  defects); the battery's scratch roots temp-rooted and
  random-suffixed (fixed names under repo dist/ collided between
  concurrent runs); the dev-entry pins quote-bounded (the dotless
  spelling was satisfied by the dotted twin's line alone); the
  bundler fixtures carry real newlines; the license-snapshot reopen
  strictly gated (the ocr10-16 shape).
- **The spawn/link capability class swept (t31-ocr11-8 +
  t31-ocr11-23, test:medium/low)** — the concurrency loops' ungated
  proc_open spawns (a false reached proc_close() as a TypeError);
  every symlink leg rides canSymlink() with VISIBLE skips (the
  function_exists gate was both wrong — the function exists without
  the privilege — and invisible mid-test); the one inline @symlink
  probe twin the sweep missed fatals under disable_functions(symlink)
  — @ cannot suppress a missing-function Error — and rides the owner.
- **canSymlink() is ONE owner, hoisted and guarded (t31-ocr11-9)** —
  the probe lives on WpHarness (loaded for every test through
  wp-stubs), function_exists-guarded so the disable_functions host
  answers false instead of fataling the battery the probe exists to
  protect; the WpConnectorsTestCase wrapper delegates, the private
  twin in HarnessCopyTreeTest is deleted.
- **Boundary contracts enforced, not assumed (t31-ocr11-10/14/15/26)** —
  zipEntryNames() gates getNameIndex()'s string|false (a malformed
  central directory coerced silently); the self-containment scanRoot
  validates absolute + realpath-inside + DIRECTORY (a file root died
  in the iterator's engine vocabulary); wp_connectors_cli_args()
  answers [] wherever no argv is bound (read through the symbol
  table — the `global` binding is one the analyzer must model as
  always populated).
- **Display and fold doctrines (t31-ocr11-11/12/13/25)** — the two
  build refusals derive their family spellings from the owner; the
  sibling-pattern folds ride the ASCII owner; every caller-controlled
  path interpolation in the inspector — the four basename() sites and
  the CLI guard's full-path line — renders through the printable seam
  (a newline in the path once forged a verdict-lookalike line).
- **rrmdir()'s realpath-false is the policy refusal
  (t31-ocr11-20)** — a TOCTOU/open_basedir false fell through to the
  SPL iterator's vocabulary; false never reaches an iterator again
  (the copyTree t31-ocr10-9 twin's shape).

The round's verifier pass (independent correctness + refutation
agents, every finding driven): correctness re-derived the 26-commit
count chain at every commit (all green, every delta exact, all five
red-at-HEAD replays confirmed, message-vs-diff audit clean) and found
the assertion figures each exactly +1 above the recorded claims —
uniformly from the pre-round base (round 10's ledgered 45417 measures
45418 today, deterministically; origin unisolated, recorded as a
trace); refutation confirmed two defects in the round's own first
cuts (the ocr11-1 completion above; the ocr11-5 empty-target
regression) and drove four traces — all six fixed in-round as
t31-ocr11-21..26, zero refuted, plus one named residual ledgered (the
EOF-unterminated group statement reports nothing — pre-existing since
t31-r10-9, the next round's candidate).

Tenth OCR-tool round — the FIRST fully complete pass (61/61, no
fill-in): 19 findings, driver triage accepted all. Two high (the
rrmdir root clause un-mirrored from its copyTree twin; the inspector's
planted-link WRITE half), one test:high (a probe that never forced its
subject's false return), two locale folds, one truthy-error-code gate.
Fixed as t31-ocr10-1..14 — one commit per finding (the same-class
groups — the ×2 fold sweep, the ×3 annotations, the ×3 platform
guards — ride one commit each), the full offline check green after
every commit. The finding-count trajectory across rounds
(9→13→9→13→8→15→10→19) is not monotone, and that is expected: each
round audits the PRIOR round's fixes and applies older doctrines to
seams they hadn't reached — the loop converges when a round finds
nothing NEW, which round 10 has not yet done (its own verifier pass
drove one: the PCRE /i text lens is locale-consulting — see below).
The round's two-lens verifier pass: correctness 8/8 HOLDS (the full
14-commit count chain re-derived at every commit; the refusalOf
census re-driven at 99 calls / 0 two-argument; exactly the two known
prose miscounts, no others); refutation 4 confirmed findings + one
trace — ALL fixed in-round as t31-ocr10-15/16/17/18, zero refuted.
Suite 1629 → 1634 tests, 45362 → 45417 assertions, 2 skipped
unchanged. Two prose miscounts corrected in the round record
(ocr10-1's message said 45369 — true 45370; ocr10-2's said 45380 —
true 45379).

- **rrmdir() refuses the ROOT collapse (t31-ocr10-1, bug:high)** —
  the deletion twin of ocr9-9's copyTree mirror clause: the walk
  spelling keeps a trailing '/..' (it names a different directory),
  so `rrmdir(sys_get_temp_dir().'/..')`, `rrmdir('/')`, any scratch
  spelling collapsing to '/' passed every guard and CHILD_FIRST
  deleted the root's children (driven both spellings: the literal
  root walked into unlink()/rmdir() over '/'; a `scratch/sub/..`
  spelling deleted the PARENT's entries). `realpath($dir) === '/'`
  now refuses with the policy exception naming the spelling; the
  link-spelling legs keep their earlier verdict (probe order: link
  doctrine first, root clause second, walk third).
- **The inspector's extraction dir is UNIQUE-OWNED (t31-ocr10-2,
  security:high)** — the WRITE half of the planted-link threat
  ocr9-10 closed for deletion only: the workDir spellings are fixed
  and predictable (the CLI's `/wp-connectors-inspect-<pid>`, pids
  enumerable; the tests' dist/.inspect-* literals), so a symlink
  pre-planted at the name had is_dir() follow it, mkdir() fail, and
  extractTo() WRITE through the link into the attacker's chosen tree
  (driven: the extracted plugin dir landed inside the victim tree).
  The extraction dir is now the base plus a 16-hex random suffix
  created by mkdir() itself (the race-free creation — a random name
  cannot be pre-planted); every inner use rides the unique dir, and
  the rrmdir link guard stays as depth. The uniqueness is pinned
  black-box: the NAME_MAX extraction refusal's captured engine
  diagnostic names the full extraction path, so two runs expose two
  different suffixes under one base.
- **The ocr1-1 reconstruction probe FORCES its subject (t31-ocr10-3,
  test:high)**: the refused spelling was the seven-digit microsecond
  tail — trailing input, whose false return is the engine's
  trailing-data POLICY (reclassifiable across versions), not the
  grammar's refusal. The spelling is now one the 'U u' grammar itself
  rejects (negative microseconds: sprintf('%06d', -1) spells
  '-00001', driven "Unexpected data found"), and the arm's
  PRECONDITION is pinned before the act — assertFalse() on
  createFromFormat over the exact spelling, so the refusal arm never
  again asserts a path whose execution went unproven.
- **The family-verdict folds ride the ASCII owner (t31-ocr10-4,
  bug:medium ×2, one class)**: every fold feeding a family verdict —
  the detector's roots and value-lens is_family, the 'lower' twins
  both lenses emit, the declaration ledger, the staging gate's root
  check, build.php's consumer folds — moved from locale-sensitive
  strtolower() to wp_connectors_ascii_lower() (the r11-6 mechanism);
  under a Turkish LC_CTYPE 'I' folds to the dotless ı at the C-library
  level (probed on this host), so a fold riding the engine's
  strtolower is a question about the engine and the process locale —
  a 'DEICOD\…' spelling could launder past the vendor predicate on
  the 8.2 floor. The Turkish leg rides the suite's manufactured
  locale idiom and requires the fold seam's verdict byte-identical
  under the live locale. DRIVEN DISCOVERY, next round's lead: the
  TEXT lens's PCRE /i STEM finding drops under the live tr locale
  (the /i fold consults the active locale — the already-ledgered
  engine behavior class); every fold-seam finding survives.
- **zipEntryNames() gates on `=== true` (t31-ocr10-5, bug:medium)**:
  ZipArchive::open() returns a TRUTHY ER_* int on failure (driven:
  ER_NOZIP=19), so assertTrue() passed a failed open and the shared
  zip reader handed back [] over numFiles=0 — every entry assertion
  vacuously green. The strict-expression gate fails loudly naming
  the ER_* code; the happy path keeps its counting assertion (a
  fail()-only first cut silently dropped one assertion at each of 42
  call sites — the suite's own count caught it).
- **refusalOf()'s family parameter is REQUIRED (t31-ocr10-6,
  maintainability:low)**: the \Throwable default pinned nothing (the
  ocr9-3 tautology), and an omitting call site silently re-opened
  that regression invisibly. Compile-enforced explicitness now — the
  census found exactly three omitting sites, each named with what
  its original context enforced (the two eval-reconstruction sites
  and the reflection readonly site, all legitimately \Throwable).
- **lint-php skips non-regular files (t31-ocr10-7, bug:low)**: a
  '*.php'-named symlink-to-directory is yielded as a LEAF by the
  LEAVES_ONLY walk, passes the extension owner, and reaches
  `php -l <dir>` — which passes VACUOUSLY (driven: exit 0 over a
  directory) while the linked tree's real sources escape the gate.
  is_link() || ! isFile() skips, the sibling collectors' parity;
  SKIP rather than the builders' refuse, because the tests tree is
  the gate's charge and the gate polices syntax, not the build
  doctrine.
- **A dev-entry top-level dir is never an embed territory
  (t31-ocr10-8, bug:low)**: the t31-r5-5 exemption keyed on the
  ARCHIVE-CONTROLLED top-level name — a hostile zip rooted at
  'vendor' exempted 'vendor/src/Shared/…' wholesale from dev-entry
  classification (driven: composer.json and vendor/ under the hostile
  territory un-flagged). The exemption now requires the top-level
  name to NOT be a development entry, judged through the ONE
  vocabulary owner.
- **copyTree(): realpath-false and FILE-in-chain refuse loudly
  (t31-ocr10-9 + t31-ocr10-10, bug:low ×2)**: a false realpath()
  string-concated to '' and refused every absolute target with the
  WRONG diagnosis (the containment message for a resolution failure)
  — now the policy exception naming the source and the resolution
  failure; and the ancestor walk stepped PAST a regular file in the
  target chain (its walk-on conditions were exactly "not a dir, not a
  link"), judged containment above the file, passed, and died in
  mkdir() as raw warnings (driven) — the walk stops at any existing
  component now, and a file-resolving one refuses with the policy
  vocabulary.
- **Three @return shapes match their own pinned execution
  (t31-ocr10-11, documentation:low ×3, one class)**:
  HeaderMap::headers(), HeaderMap::masked_headers(), and
  HasMaskedHeaders::masked_headers() said array<string,string> while
  the suite pins all-digit names surfacing under PHP-canonical
  INTEGER keys (assertSame(array(123 => 'x'), …)) — the
  machine-readable shape is array<int|string, string> at all three.
- **The try/finally structural pin is whitespace-normalized
  (t31-ocr10-12, maintainability:low)**: the t31-r12-20 pin asserted
  a byte-exact indentation-sensitive substring — the class
  demonstrated itself live when ocr10-2's one-variable rename
  reddened it mid-round. The pin now collapses whitespace and asserts
  the STRUCTURE (extractTo in a try whose finally restores the
  handler); mutation-checked that the happy-path-only pairing still
  does not match.
- **proc_open() is gated and $pipes reset per spawn
  (t31-ocr10-13, test:low)**: a failed spawn left a null handle whose
  close warned, and the stale $pipes double-closed the PRIOR child's
  stdin — six children shared one descriptor array at exactly the leg
  whose job is exit-code fidelity.
- **Symlink platform guards ride the capability probe
  (t31-ocr10-14, test:low ×3, one class)**: the excluded-path
  battery called symlink() bare, the seam-property row applied its
  link bare, and HarnessCopyTreeTest's leg gated on
  function_exists('symlink') — TRUE on Windows without the privilege
  to use it (the t31-ocr6-14 lesson). One owner — canSymlink() on
  WpConnectorsTestCase beside runningAsRootRunner (create+unlink
  probe, @-suppressed, the FALSE RETURN is the signal) — serves the
  test-level skips and the row-level skip (the skip_on_root pattern);
  the same-class function_exists guard in the removal-seam pin rides
  it too.

Verifier-pass fixes (the round's refutation lens, all confirmed by
driven or traced evidence, fixed in-round):

- **r9's ledgered re-open condition FIRED on the inspector's removal
  twin (t31-ocr10-15, bug:medium)**: the workDir is a PUBLIC
  parameter of wp_connectors_inspect_artifact(), so caller-controlled
  spellings reach wp_connectors_inspect_rrmdir() — and driven,
  'link/.' EMPTIED the victim tree past the r9 plain is_link() guard
  (the stat-transparent tail family), 'link/..' crashed the walk
  inside the target's parent mid-deletion, and '/' walked the
  filesystem root's children. The twin now carries BOTH clauses its
  siblings carry — the tail-stripped link probe and the root clause
  (silent returns: this owner's vocabulary; a production finally
  never throws).
- **The ocr10-5 truthy-gate class survived on the WRITE side
  (t31-ocr10-16, test:low ×4)**: four ZipArchive::open(CREATE)
  gates rode bare assertTrue() (the lens named two; the same-class
  grep found the other two — the sweep). The strict-expression gate
  with the RAW return in the message (lastErrorCode() is not compiled
  on this engine — driven).
- **The ocr10-2 retry loop leaked its own failure premise
  (t31-ocr10-17, bug:low)**: on an unwritable parent, sixteen RAW
  'mkdir(): Permission denied' warnings printed before the polite
  refusal — the r12-19 capture doctrine one screen below, missed by
  the loop sharing its screen. The capture rides the loop (r12-20
  finally), the refusal names the reason; pinned by the no-leak
  idiom with a root-runner skip.
- **The round's own probe planted a predictable name
  (t31-ocr10-18, test:low)**: the new canSymlink() probe wrote
  '/wpct-capability-<pid>' — pre-plantable, the exact ocr10-2 threat
  model on the round's fresh code (a planted entry flips the probe
  false and silently suppresses link legs; trace-confirmed, skipped
  legs only). Random suffix on both owners.



Ninth OCR-tool round (main 56/61 + the fill-in over tests/Zai); driver
triage accepted all 10 findings — two real bug:medium in WpHarness, one
performance:low, and one class regression our own round-8 sweep
introduced (the dropped exception families). Fixed as t31-ocr9-1..8 —
one commit per finding (the family class sweep = one commit), the full
offline check green after every commit. The round's two-lens verifier
pass (independent correctness + refutation agents) held every fix
claim — the correctness lens re-derived the 97-site family census
exactly and found one prose miscount (corrected in the round record) —
and the refutation lens raised 3 findings, all fixed in-round as
t31-ocr9-9/10/11. Suite 1628 → 1629 tests, 45129 → 45362 assertions,
2 skipped unchanged.

- **The trailing '/..' tail is the third stat-transparent link-probe
  family member (t31-ocr9-1, bug:medium)** — and round 8's carve-out
  ("it names the parent") is refuted by driven evidence: the parent it
  names is the LINK TARGET'S parent, so `rrmdir('link/..')` walked and
  deleted through the link with a strictly larger blast radius than
  the target, and `copyTree('link/..')` copied the whole parent tree
  through it (both driven). `link_probe_spelling()` strips the '/..'
  tail too (interleaved with '/' and '/.' to a fixpoint), but a
  stripped '/..' names a DIFFERENT directory — so the helpers split:
  the probe reads `link_probe_spelling()`, the walks ride
  `same_directory_spelling()` (rrmdir) or the caller's spelling
  (copyTree); real-'..'-spelled sources keep their pre-fix semantics
  (copy control pinned). Ledgered lesson: a carve-out needs a DRIVEN
  justification, never a naming argument.
- **copyTree() refuses the MIRROR containment (t31-ocr9-2,
  bug:medium)**: a target that CONTAINS the source —
  `copyTree('/a/src', '/a')` — passed the guard, and a nested
  same-name segment resolved the copy INSIDE the tree being read
  (driven: src/src/nested.php landed at src/nested.php). Same
  ancestor-resolved owner as the nested-target refusal, symmetric
  direction; the refutation lens then drove the ROOT collapse (a
  target resolving to '/' built the prefix '//' and passed both
  guards — the root is an ancestor of every source), closed in
  t31-ocr9-9 by judging the root as the universal container.
- **refusalOf() enforces the exception FAMILY its site's original
  catch declared (t31-ocr9-3, test:medium, 97 sites)**: the round-8
  sweeps silently dropped the family when converting catches to the
  \Throwable collector — a planted TypeError carrying the fragments
  kept the whole pin green (driven). Every converted site now passes
  its original family (census re-derived from the r8 diffs: 94 family
  args — 90 RuntimeException-family, 4 \Exception-family — plus 3
  sites whose original catch was \Throwable, legitimately on the
  no-op default); the family assert fails loudly naming expected and
  got. The round's class lesson, ledgered: mechanical refactors must
  carry the ORIGINAL contract's full semantics, enforced by a census.
- **The third removal owner joined the link doctrine (t31-ocr9-10,
  bug:low)**: bin/inspect-artifact.php's rrmdir had no link guard at
  all — a planted symlink at its predictable workDir name emptied the
  victim tree (driven). It carries its build-side twin's exact guard
  now (root-link return, `! isLink()` child branch); the probe-spelling
  machinery is deliberately absent (no caller passes any tail spelling
  — re-driven).
- **The Retry-After future-claim class is swept to its sibling owners
  (t31-ocr9-4 + t31-ocr9-11, documentation:low)**: no Retry-After
  parsing exists in shared/src — the property docblock, RefreshPolicy's
  header, and the exception's file header now all state what IS (an
  int supplied by the caller, already parsed; the HTTP/provider layer
  that will parse is Task 3.2 scope, the coordination that will honor
  it Task 3.3 design intent). GrantState's header words the terminal
  classes as terminal-INTENT (t31-ocr9-5): the VO is
  transition-permissive by design (the t31-r2-3 adjudication).
- **Small fixes (t31-ocr9-6/7/8)**: the secret scanner's per-file
  segment walk is skipped entirely when pruning is off (its guard was
  constant for the whole walk — pure per-file overhead on the
  artifact-scan path; both sides of the flag pinned over one fixture
  tree); rrmdir()'s docblock moved to its declaration (only the last
  docblock before a function attaches — the spelling helpers'
  docblocks had orphaned it); the OAuthRateLimitException @param block
  re-spaced to the one common alignment column.

### Fixed (shared — M3 Task 3.1, OCR round 8)

Eighth OCR-tool round (the same complementary deterministic reviewer,
eighth pass over the branch diff, main + two fill-ins, union coverage
complete — the final fill-in unblocked by escalating the reviewer's
provider timeout 900→1800s); driver triage accepted all 15 findings,
the largest cluster (5) being the fail()-inside-catch masking class in
BuildArtifactsTest. Fixed as t31-ocr8-1..11 — one commit per finding
(the class sweep = one commit), the full offline check green after
every commit, a regression per fix where meaningful. The round's
two-lens verifier pass (independent correctness + refutation agents
over the whole round diff, 222 driven tool calls between them) raised
4 refutation findings + 1 correctness finding: 3 fixed in-round as
t31-ocr8-12/13/14 (the census's own tokenizer blind spot — 25 further
masked sites under the fully-qualified catch spelling; the '/.'
twin of the trailing-slash link bypass; the non-public property
payload channel), 1 accepted-without-fix (degenerate copyTree
targets, ledgered), 1 corrected in the round record (the sweep
commit's prose site-count, 72 not 74). Suite 1628 tests unchanged,
45095 → 45129 assertions, 2 skipped unchanged.

- **The fail()-masking class is swept by ONE owner
  (t31-ocr8-1 + t31-ocr8-12, test:medium)**: AssertionFailedError
  extends RuntimeException, so a `$this->fail()` inside a try whose
  catch asserted fragments was vacuous per spelling whenever the fail
  message carried the fragment — driven on the exact leg (a no-throw
  stub passed both fragments against the fail message and the body
  continued green). The ocr6-3 fix had swept ONE file; this round
  found five more sites in the OTHER file — and the census behind the
  sweep found 72 sites (67 BuildArtifactsTest + 5 across four more
  files), all converted to `refusalOf()` hoisted on
  WpConnectorsTestCase (collect inside, fail on no-throw OUTSIDE any
  catch, caller asserts fragments on the returned verdict). The
  verifier's refutation lens then found the census ITSELF blind: PHP 8
  tokenizes `\RuntimeException` as T_NAME_FULLY_QUALIFIED, not
  T_STRING, so every fully-qualified catch spelling was invisible —
  25 more sites in seven files (four partially masked TODAY, the
  fragment literally inside the fail message; two at the widest
  `catch (\Throwable)` spelling whose whole catch was an assertion
  count — fully vacuous), all converted in t31-ocr8-12; both lenses'
  independent censuses now read zero tree-wide. The double class
  lesson: sweep the SHAPE (not the file), and match the shape's every
  spelling the engine tokenizes — a census must be re-derived by an
  independent eye before it can claim "complete".
- **The link-probe spelling is ONE owner (t31-ocr8-2 +
  t31-ocr8-13, security:low ×2)**: a trailing slash defeats `is_link()`
  (stat resolves through the link), so `rrmdir('link/')` emptied the
  TARGET tree and `copyTree('link/')` walked it — and the first fix's
  slash-only normalization was bypassed one spelling over by `/.`,
  which forces the same through-resolution (both spellings driven red
  both rounds). `link_probe_spelling()` (trailing slashes and trailing
  '/.' components, the root '/' preserved) is the one owner both
  guards probe; rrmdir() normalizes wholesale, copyTree() normalizes
  the root-link PROBE only (a trailing-slash REAL source keeps its
  ocr6-4 relativize refusal; a '/.'-spelled REAL source keeps copying
  — pinned as the control). A trailing '/..' is deliberately out:
  it names the link target's PARENT, not a disguise of the final
  component. bin/build.php's own is_link guards inspected and left
  alone — internally constructed paths only (no CLI dist-dir argument
  exists; unknown getopt args are ignored), no reachable spelling.
- **copyTree() containment sees through the alias class
  (t31-ocr8-3, maintainability:low)**: the not-yet-created target was
  judged purely lexically, so a '..'-woven target and a target behind
  a SYMLINKED ancestor both landed physically inside the source while
  the spelling looked foreign (the ocr7-8 ledger's named out-of-scope
  class). The guard judges through the nearest EXISTING ancestor now
  (walk up to the first component that exists, realpath THAT,
  re-attach the not-yet-existing remainder with a lexical '.'
  collapse — the remainder holds no symlinks by construction); a
  nothing-exists chain (a dangling-link ancestor) keeps the lexical
  ceiling. The '..'-woven leg's copy SUCCEEDED at HEAD before the fix
  (mkdir happily followed the dots into the tree under test).
- **The dead LEAVES_ONLY branch is gone, the knowledge kept
  (t31-ocr8-4, maintainability:low)**: `if ($file->isDir()) continue;`
  was unreachable (the iterator runs LEAVES_ONLY; the only dir-shaped
  yields are links, owned by the isLink() refusal above) — deleted,
  the comment names the actual semantics, and the docblock states the
  corollary: EMPTY source directories are silently dropped.
- **The no-payload-API audit sees overrides, properties, and the
  declared set (t31-ocr8-5 + t31-ocr8-6 + t31-ocr8-14, test:low ×3)**:
  the name-based audit let a concrete `__toString` override wear an
  allowed spelling (the override IS the payload channel) — the
  DECLARING CLASS decides now (engine-declared or a family addition);
  the methods-only API is pinned against public properties; and the
  verifier's channel: print_r()/var_export() dump PROTECTED and
  PRIVATE properties raw (driven), which the IS_PUBLIC probe never
  saw and which the methods-only contract even blocked mitigating
  (a __debugInfo override would fail the declaring-class pin) — the
  pin holds the family's DECLARED property set exactly (the one
  parsed int, retry_after_seconds); a new declared property of any
  visibility fails the pin, consciously.
- **The lint gate's exclusion is its OWN named subset
  (t31-ocr8-7, test:low)**: riding the whole development-entry
  vocabulary (r12-9) silently narrowed lint COVERAGE — the
  vocabulary's 'tests'/'test'/'.github' entries made a future
  connectors/<slug>/tests/ tree escape php -l (the old hand-rolled
  list never excluded tests). The gate consumes its own named subset
  (the generated/third-party class: vendor, node_modules, tools,
  dist, .git, both cache spellings) through the ONE fold owner —
  the coverage decision is explicit at the list; a nested tests-named
  tree is LINTED again (red-driven: a broken nested test invisible at
  HEAD, failing post-fix), the real tree unchanged (174 files both
  sides).
- **SecretMask's UTF-8 grammar is ONE spelling — and the collapse
  found a latent BUG (t31-ocr8-8, maintainability:low)**: the
  canonical-grammar regex and utf8_for_safe_render()'s hand-rolled
  table were two spellings of one grammar with no structural tie;
  both sites ride `utf8_sequence_length_at()` now and the regex is
  deleted. The byte-equivalence drive (94k+ cases, re-driven by both
  lenses with own seeds — 126k+) REFUTED the regex twin instead of
  confirming it: its F4 clause's quantifier demanded FIVE bytes, so
  it accepted the invalid beyond-U+10FFFF five-byte shape (mask()
  could ship it raw, breaking its own never-invalid contract) and
  rejected the valid U+100000..U+10FFFF plane (mask() shed those
  complete characters to the bare mask). The walk matches the engine
  (`//u`) on every disagreement class; both F4 legs pinned red-green.
- **The skip owns its cleanup (t31-ocr8-9), the zipEntryNames twin
  is hoisted (t31-ocr8-10), the shared exec() output is reset
  (t31-ocr8-11, test:low/maintainability:low)**: the capability-probe
  skip leaked the scratch tree on exactly the hosts that take it
  (markTestSkipped throws before the try owning rrmdir); the
  verbatim zipEntryNames() twin in two suites is one protected owner
  on WpConnectorsTestCase; and the two CLI-seam pins reset their
  by-ref `$output` before each exec() (exec appends — fragments were
  asserted over the earlier run's lines too).

### Fixed (shared — M3 Task 3.1, OCR round 7)

Seventh OCR-tool round (the same complementary deterministic reviewer,
seventh pass over the branch diff, main + fill-in, union coverage
complete); driver triage accepted all 8 findings (6 commits — the
two ×2 groups rode one commit each) — the ocr4-5 braced-namespace
expiry defect class REPRISED in the sibling declaration ledger
(the r4 fix owned only the rewriter's), two rewrite-seam
legal-spelling gaps, copyTree precondition/self-containment guards,
and two nondeterministic test tails. Fixed as t31-ocr7-1..6 — one
commit per finding, the full offline check green after every
commit, a regression per fix where meaningful. The round's
two-lens verifier pass (independent correctness + refutation
agents over the whole round diff via a deterministic workflow
under the round's ultracode directive, 127 driven tool calls)
raised 3 accepted findings — the correctness lens's trait-clause
mislabel (both lenses' copyTree mechanism refutations converged)
and the refutation lens's case-insensitivity axis — all three
fixed in-round as t31-ocr7-7/8/9, zero refuted; one named
residual ledgered for the next round (declaration-position
unowned spellings still refuse anonymously). Suite 1627 → 1628
tests, 45068 → 45095 assertions, 2 skipped unchanged.

- **The declaration ledger is ONE owner (t31-ocr7-1, bug:medium)**:
  the detector's resolution walk kept a braced `namespace X { … }`
  in effect to EOF — after the block PHP is global scope — so a
  post-block family-resolving relative resolved `X\Deicod\…`, not
  family, and LAUNDERED past both gates invisible while the
  rewriter's own ledger (t31-ocr4-5) refused the same bytes. The
  ledger is a shared owner now (wp_connectors_namespace_declaration_
  ledger() + wp_connectors_declaration_in_effect() in
  bin/lib/plugin-tools.php, verbatim extraction of the rewriter's);
  the rewriter's walk and the detector's walk both consume it — one
  resolution semantics at the sweep, the build postcondition, and
  the relative-use rewrite. Census (both bin/ files swept): exactly
  the two existed; the name walk's classification state and the
  PSR-4 fence's counting ledger are not in-effect twins.
- **Legal import spellings refuse NAMED, never anonymous
  (t31-ocr7-2 + t31-ocr7-7 + t31-ocr7-9, bug:low)**: comma lists
  (`use A\B, C\D;`), close-tag-terminated statements, and comments
  inside the statement are legal PHP the use pattern's byte grammar
  cannot see — they refused at the postcondition with the anonymous
  "spelling survived" text. The classifier names the class on the
  throw path (green builds pay nothing): the three surveyed classes
  refuse named, grouped `use …\{B, C};` stays OWNED, trait clause
  lists carry NO class (the brace-kind-stack carve — a use inside a
  non-namespace block is a trait use; the verifier lens caught the
  braceless shape and the un-carved comment label misnaming
  adaptations), and the case-variant axis (a `USE` keyword, a
  case-variant family prefix) refuses named — owning it would flip
  the r7-pinned case-variant refuse doctrine, so the seam names the
  class instead.
- **The bare-keyword fence's follower must be code-adjacent
  (t31-ocr7-3, bug:low)**: the follower walk treated PHP mode
  boundaries as trivia, so `namespace ?> html <?php Foo;` bound the
  re-entered name to the keyword as its declaration (a php -l
  parse error) and waved the fence. A mode boundary ENDS the scan
  now — the boundary spelling is judged as the bare keyword alone,
  the fence's own named refusal; the r5-9 tails and every legal
  control keep their verdicts (verified verdict-diffed both ends,
  including a worse shape the fix killed for free: a braced global
  block behind a boundary once shipped at exit 0).
- **copyTree() preconditions and self-containment (t31-ocr7-4 +
  t31-ocr7-8, bug:low ×2)**: a missing or FILE source refused with
  the SPL iterator's UnexpectedValueException, not the documented
  LOUD policy; a self-copy target and a target inside the source
  ran unguarded (probed mechanisms, corrected in-round: the
  self-copy is a silent no-op success on this engine; the nested
  copy is one self-polluting duplication — the SPL iterator never
  re-enumerates). All four shapes refuse with the policy exception
  before a single byte moves, one realpath-based containment check;
  the lexical carve-out names its class ('..'-woven and
  symlinked-ancestor aliases).
- **The PSR-4 gate derives the namespace (t31-ocr7-5,
  maintainability:low)**: the one-type-per-file gate hand-spelled
  'Deicod\WpConnectors\Shared' while sibling gates derive from
  wp_connectors_shared_source_namespace() — two owners of one
  spelling in one sweep. The gate consumes the owner's derivation.
- **The OTP-class tail pin derives from the actual value
  (t31-ocr7-6, test:low ×2)**: the dump and serialize channels'
  not-contains-'3502' literal collided with the mask's OWN output —
  the device code's random 4-hex tail spells '3502' once per 65,536
  runs (brute-forced ≈1/60,000 over 300k codes) and failed the pin
  spuriously. The pin rides the derived pair (the raw code never
  rides; mask($device_code) from the SAME code rides); the
  bare-mask shape stays pinned deterministically at the mask owner.

### Fixed (shared — M3 Task 3.1, OCR round 6)

Sixth OCR-tool round (the same complementary deterministic reviewer,
sixth pass over the branch diff, main + fill-in, union coverage
complete); driver triage accepted all 13 findings — the FIRST
shared/src security finding since round 3 (the SecretMask
tail-length policy, not a new channel), control-byte/UTF-8
screening obligations on the storage port and the provider_id
snapshots, the copyTree fallback plus BOTH inline twins that
re-grew the exact defect shapes ocr4-2/-3 killed, and test-infra
lows. Fixed as t31-ocr6-1..13 — one commit per finding, the full
offline check green after every commit, a regression per fix where
meaningful. The round's two-lens verifier pass (independent
correctness + refutation agents over the whole round diff via a
deterministic workflow, 152 driven tool calls between them — every
fix re-driven red/green through /tmp copies of the pre-round code)
raised 2 distinct CONFIRMED findings (both lenses independently
found the first) plus one dead-code note — all three fixed in-round
as t31-ocr6-14/15/16, zero refuted; one finding's stated mechanism
was corrected by the driver in-commit (ocr6-11's "silently pass
green" is actually a confusing red through the RuntimeException-
swallowing catch). Suite 1623 → 1627 tests, 45028 → 45068
assertions, 2 skipped unchanged.

- **The visible-tail policy never tails an OTP-class value
  (t31-ocr6-1, security:low)**: SecretMask's threshold sat one
  character under the canonical RFC 8628 user code — 'BCJK-3502'
  (9 chars with separator) rendered '…3502', half a short-lived
  credential's entropy on every safe debug form. MIN_LENGTH_FOR_
  VISIBLE_TAIL 8 → 12 at the OWNER (every credential-bearing
  consumer rides it; raising only masks more, no long-value render
  loses its tail); boundary pinned from both sides, the canonical
  user-code shape pinned bare-masked in both engine channels, the
  mechanics pins re-lengthened past the new boundary.
- **The storage key screens on ALL THREE port methods
  (t31-ocr6-2, security:low)**: the control-byte obligation was
  save()-only while load()/delete() accept the same caller-
  controlled key — a 3.2 adapter embedding it in an
  OAuthStorageException message on a failed load/delete reopens
  the forged-log-line class. The contract states the three-method
  obligation (header bullet + both docblocks); the reference fake
  enforces it with ONE screen owner (screen_key()) at three call
  sites.
- **provider_id snapshots escape invalid UTF-8 (t31-ocr6-3,
  bug:low ×2)**: a lone 0xE9 passes the control-byte screen (a
  config label is opaque) but rendered verbatim made both VOs'
  dump/serialize forms invalid UTF-8 — the json_encode-false
  log-drop class. The RENDERED form routes through
  SecretMask::utf8_for_safe_render() (the r8-6 one rendering
  owner), the constructor stays byte-permissive by design; the
  exception-message sprintf residual is named for the next round.
- **copyTree()'s no-match arm refuses (t31-ocr6-4, bug:low)**: a
  pathname not prefixed by `$from.'/'` (trailing-slash source)
  kept its FULL absolute path as the relative tail — every file
  silently nested under the target. Loud RuntimeException naming
  path and prefix.
- **BOTH fixture-copy twins ride the one copy owner
  (t31-ocr6-5 + t31-ocr6-15, maintainability:medium + the lens's
  catch)**: makeScratchRepo's inline copy (str_replace prefix
  strip, no isLink() guard) re-grew the exact ocr4-2/-3 defect
  shapes — and the verifier lens found BuildArtifactsTest::
  copyFixturePlugin() still carrying the SAME surviving twin,
  fixed in-round. Both ride WpHarness::copyTree(); the r6-7
  pre-create concern is absorbed (per-file recursive mkdir,
  order-free).
- **The dev-segment prune flag is typed bool (t31-ocr6-6,
  maintainability:low)**: documented bool, untyped under
  strict_types — truthiness silently accepted 0/'0'/'' as disable
  on a security-relevant control.
- **The forced-add pin pins the CONTRACT (t31-ocr6-7, test:low)**:
  add-time FALSE for a vanished staged source is a libzip-build
  detail (stat-at-add vs deferred read); the pin now asserts the
  add+close sequence never silently succeeds — failure observable
  by close time at the latest, green on both libzip shapes.
- **Mutation needles derive from the fixture (t31-ocr6-8,
  test:low)**: the traversal row's two exact literals
  ('Version:           0.1.0', "'0.1.0'") no-opped silently on
  fixture drift, failing as a phantom build defect — needles are
  regex-derived from what is there, each with an asserted
  replacement count; a miss fails loudly at the mutation step.
- **GrantState's count agrees with its list (t31-ocr6-9,
  documentation:low)**: "three distinct classes" over four states
  with the non-terminal one first — one LIVE state plus three
  TERMINAL classes now.
- **The nested-name pin's negative leg names the real glue
  (t31-ocr6-10, test:low)**: it asserted 'vendor/nested.php', a
  path that existed under NEITHER behavior (pre-fix str_replace
  GLUES 'vendornested.php') — the leg asserts the glue shape now,
  red under a hand-reverted str_replace strip.
- **Symlink-capability probes fire as skips, not errors
  (t31-ocr6-11 + t31-ocr6-14, test:medium + both lenses)**: the
  collector-refusal legs had no probe — on an incapable host they
  fail confusingly (fail() swallowed by the RuntimeException
  catch, fragment assertions re-failing over the wrong message).
  The probe idiom (create+unlink, named skip) was added — and the
  verifier pass then caught that its unsuppressed symlink() WARNING
  errors the test at the call line before markTestSkipped (driver-
  reproduced under the repo's own phpunit config): the probe is
  @-suppressed at both sites of the idiom, the round-4 origin
  included.
- **The locale-pressure half skips visibly (t31-ocr6-12,
  test:low)**: a host without localedef/tr_TR silently skipped
  the whole `if ($manufactured)` block while passing green under
  a name claiming pressure was applied — markTestSkipped with the
  exit code and what did not run.
- **The exception-family pin sees subdirectories (t31-ocr6-13,
  test:low)**: the round-5 exhaustiveness glob was FLAT — a type
  in a future Exception/ subdirectory would slip every family pin
  while the pin's message claimed whole-directory coverage
  (planted GapException: round-5 pin green, new pin red). The
  derivation is recursive, still a pure file-set pin composing
  with the one-type-per-file gate.
- **In-round verifier fixes (t31-ocr6-14/15/16)**: the @-suppressed
  capability probe (above), the surviving copyFixturePlugin twin
  (above), and one dead error-handler capture beside its own @ in
  the forced-add leg — collected, never asserted, redundant under
  @.

### Fixed (shared — M3 Task 3.1, OCR round 5)

Fifth OCR-tool round (the same complementary deterministic reviewer,
fifth pass over the branch diff, main + fill-in with union coverage
complete); driver triage accepted all 9 findings — ONE seam edge
(bug:medium, the build rewriter) and EIGHT test-infrastructure pins:
the trajectory holds — shared/src stays clean, the loop keeps
scraping the harness. Fixed as t31-ocr5-1..8 — one commit per
finding, the full offline check green after every commit, a
regression per fix. The round's two-lens verifier pass (independent
correctness + refutation agents over the whole round diff via a
deterministic workflow, 140 driven tool calls between them) verified
all 8 fixes red/green in both directions — the correctness lens
raised ZERO findings — while the refutation lens raised 4: three
CONFIRMED at the seam and fixed in-round (t31-ocr5-9, two fence
gaps in the round's own ocr5-1 fix; t31-ocr5-10, a silent /u abort
in the round's own ocr5-7 diagnostic), one REFUTED by the driver
(the claimed escape was already owned by the one-type-per-file
gate). Suite 1618 → 1623 tests, 44985 → 45028 assertions, 2 skipped
unchanged.

- **The interrupted `namespace` keyword refuses at EVERY position
  outside a use statement (t31-ocr5-1 + t31-ocr5-9, bug:medium +
  the lens's two fence gaps)**: the family detector's walk drops a
  bare keyword that opens no legal declaration (the r8-10 base-
  integrity rule) and reports only the following name run — a name
  no family predicate matches — so `namespace \WpConnectors\Shared\
  Clock` in a code position never produced the fused twin's
  'relative' report and the parse-error bytes rode the rewrite, the
  postcondition, and the sweep into a lintless zip at exit 0
  (reproduced; php -l "unexpected token namespace"). The rewriter
  owns the predicate totally now: the keyword must open a
  declaration (a name, or a braced block) standing at a statement
  boundary, judged across mode boundaries (a close tag, its inline
  HTML, the re-entry tag — the lens shipped `namespace ?> <?php
  \Junk;` end-to-end through the first fence); every other spelling
  — including `$x = namespace;` and a declaration SHAPE in an
  expression POSITION (`$x = namespace Junk;`) — refuses.
- **The conventions summary counts by SOURCE (t31-ocr5-2,
  maintainability:low)**: one pooled count read "N plugin dir(s)
  checked, M violation(s)" on a shared/src-only red run — dirs
  never scanned for them carried the count. One line, three named
  buckets: plugin-tree / shared/src / repo.
- **fail() never stands inside the catch's own reach
  (t31-ocr5-3, test:low)**: PHPUnit's AssertionFailedError extends
  RuntimeException, so the symlink-refusal legs' fail()-inside-try
  was swallowed by the very catch meant for copyTree() — collect
  the exception inside, fail()/assert outside (one shared closure,
  same three pins).
- **The stage-residue pins ride the glob helper (t31-ocr5-4,
  test:low ×2, vacuous)**: five assertions pinned the pid-less
  `.stage-example-connector` spelling, dead since t31-r10-4's
  `.stage-<slug>-<pid>` rename — every one now rides
  assertNoStageTree() (globs `.stage-<slug>*`) and can fail again.
- **The exception-family list is pinned against its directory
  (t31-ocr5-5, test:low)**: concreteTypes() was a hardcoded six with
  no exhaustiveness guard — a seventh type would silently escape
  every family pin. The glob's class set must equal the list plus
  the two named anchors; together with the one-type-per-file gate
  (which the verifier lens missed — its "escape" was already
  refused there) the family grows only by growing both.
- **The payload-API audit covers INHERITED methods (t31-ocr5-6,
  test:low)**: the declaring-class filter let a base-class
  raw_payload() pass invisible. All public non-static methods now,
  with the baseline derived from the engine's own \Exception
  reflection — the allow set never drifts with PHP versions.
- **The whole-file diagnostic speaks \R (t31-ocr5-7 +
  t31-ocr5-10, test:low ×2)**: the line number/excerpt derivation
  used "\n"-only semantics while the file's own r8-11 pin demands
  \R everywhere — on a CR-only file it reported line 1 with the
  whole file as the excerpt. The derivation rides the reader's
  \R-aware splitting now, and both /u calls REFUSE on abort
  (invalid UTF-8 degraded silently to the same line-1 symptom —
  the lens's find over the round's own fix).
- **The provider pattern's fences are letter-aware (t31-ocr5-8,
  test:medium)**: the trailing `\b` could never match the
  '_'-extended twins of its own vocabulary (`claude_pro_fallback`,
  `openai_compat`, `zai_anthropic_default`) — `_` is a word
  character to `\b`, the exact mechanism this file adjudicated as
  a bug for the WP stems (t31-r4-11). The r4-11 lookahead
  `(?![A-Za-z])` now, leading `\b` kept per the adjudication;
  the twin mechanisms are ledgered.

### Fixed (shared — M3 Task 3.1, OCR round 4)

Fourth OCR-tool round (the same complementary deterministic reviewer,
fourth pass over the branch diff); driver triage accepted all 12
findings — SIX of them one defect class (root-runner chmod-0000
brittleness) swept as one class. The trajectory this round is the
signal: shared/src carries ZERO correctness or security findings —
one unreachable-invariant guard (Url.php) is the round's only
production-source touch beside bin/build.php's braced-namespace
ledger, and everything else lives in the TEST INFRASTRUCTURE — the
loop is scraping the harness now. Fixed as t31-ocr4-1..7 — one
commit per finding (the class sweep one commit), the full offline
check green after every commit, a regression per fix where
meaningful. The round's two-lens verifier pass (independent
correctness + refutation agents over the whole round diff via a
deterministic workflow, 67 driven tool calls between them) raised 2
findings — one per lens, both reproduced — and both were fixed
in-round as t31-ocr4-8/9. Suite 1616 → 1618 tests, 44974 → 44985
assertions, 2 skipped unchanged.

- **chmod-0000 legs skip under a root runner (t31-ocr4-1, test:medium
  ×6, one class sweep)**: uid 0 reads through mode 0000, so every
  permission-bit refusal leg flips on a root CI container — the
  expected refusal never fires and a fail()/battery "silent third"
  fires instead. One guard lives ONCE in the harness parent
  (runningAsRootRunner() + skipChmod0000LegOnRootRunner(),
  posix_getuid-based, named skip reason); the three battery-table
  chmod rows skip ROW-LEVEL (never a whole-battery skip), and the
  opendir-probe twin in SelfContainmentLoopWritesTest stays its own
  owner. Grep census: every chmod(…,0000) leg is guarded.
- **copyTree() strips the source prefix positionally (t31-ocr4-2,
  bug:low)**: str_replace() stripped EVERY occurrence, so a source
  path repeating inside itself copied to the collapsed wrong target.
  Position 0, exactly once — with a red/green regression that replays
  the entire source path as nested literal dirs.
- **copyTree() refuses symlinks of both shapes (t31-ocr4-3 +
  t31-ocr4-8, other:low + test:medium)**: a linked FILE was followed
  by copy() (content duplicated), a linked DIRECTORY silently skipped
  — one policy now, the copy twin of rrmdir()'s no-symlinks doctrine:
  a link (root, file shape, or dir shape) refuses loudly naming the
  link. The verifier lens then caught the new pin's nothing-landed
  assertion over-claiming (the refusal fires at the first link the
  ITERATOR reaches; yield order is the filesystem's — false-fail on
  ext4, reproduced): the order-dependent assertion is gone.
- **Url's scheme-separator probe is false-first (t31-ocr4-4,
  maintainability:low)**: the ONE sibling position probe in the file
  without its `false !==` spelling — `(int) false` is 0 — now refuses
  loudly on the (construction-unreachable) missing-scheme spelling,
  per the file's own no-unreestablished-invariants doctrine.
- **Braced namespace blocks expire in the rewrite ledger
  (t31-ocr4-5 + t31-ocr4-9, bug:low ×2)**: `namespace X { … }` once
  stayed in effect to EOF, so a post-block use statement (legal PHP
  in global scope) misattributed to the expired declaration. The
  block's closing brace offset is recorded through the ONE
  brace-matching owner over the masked view. The refutation lens
  then reproduced both directions of an inline-HTML counterfeit
  (an HTML '}' expired early, an HTML '{' delayed the close — PHP
  itself continues the block past a close tag; only a CODE '}' closes
  it): inline-HTML spans are blanked in the ledger's OWN view, never
  in the masker owner its conventions consumers share.
- **The ancestor-boundary sub-test kills its regression
  (t31-ocr4-6, test:medium)**: 'Dist-ancestor-<pid>' matched no
  excluded name under EITHER judging, so the below-root pin
  (t31-ocr1-12) stayed green over the regression it documented. The
  ancestor is a real case-variant ('DIST') above the root now —
  verified: with the below-root slice removed the test fails exactly
  as the round-1 reproduction described.
- **Concurrent-build legs collect exits before asserting
  (t31-ocr4-7, test:low ×2)**: the assertion stood INSIDE the
  proc_close() loop, so a failing child aborted the reaping and
  leaked builds writing into the scratch tree the finally then
  rrmdirs — collect all exits first, assert after.

### Fixed (shared — M3 Task 3.1, OCR round 3)

Third OCR-tool round (the same complementary deterministic reviewer,
third pass over the branch diff); driver triage accepted all 9
findings (2 security:high, 3 bug/maintainability:medium, 4 low) —
the tool auditing our own prior rounds' fixes as much as the branch:
ocr3-1 extends the ocr2-1 serialize doctrine to the last
credential-bearing VOs (the round-2 verifier had refuted exactly this
exposure as producer-gated with the named re-open condition "a round
finding that names them" — the tool's finding named exactly them),
and ocr3-3/ocr3-8 close shapes the ledger lines themselves forward
(the dangling build.json symlink onto the config-seam line, the last
entry script onto the r12-11 sweep). Fixed as t31-ocr3-1..8 — one
commit per finding, the full offline check green after every commit,
a regression per fix. The round's two-lens verifier pass (independent
correctness + security agents over the whole round diff via a
deterministic workflow, 79 driven tool calls between them) raised
ZERO findings on either lens. Suite 1613 → 1616 tests, 44911 → 44974
assertions, 2 skipped unchanged.

- **serialize() is masked on the flow VOs (t31-ocr3-1, security:high)**:
  PkceCodePair, DeviceAuthorizationSession, and the nesting carrier
  PendingAuthorization hooked only `__debugInfo()` — serialize()
  bypasses it by engine design, so serialize() of the pair (the RFC
  7636 confidential half), the session (both device-flow codes), or
  any container holding them emitted the raw credentials. All three
  ride the ocr2-1 doctrine now: `masked_view()` is the ONE view the
  dump and serialize hooks render (the carrier's payload rides as the
  OBJECT, so the masking decision stays the payload's — the
  StoredGrant shape), `__unserialize()`/`__set_state()` refuse the
  lossy safe forms, and var_export() stays the one named-excluded
  channel.
- **The two render channels cannot drift (t31-ocr3-2, test:medium)**:
  the flow suite pinned print_r() only, so a future edit re-deciding
  one channel's mask would pass green with the engine channels
  disagreeing. The pin is direct — same object, both public hooks,
  identical arrays — beside the byte-level composition pin (each
  VO's own masked spelling in BOTH channels, the credential in
  neither).
- **A build.json symlink refuses at the config seam (t31-ocr3-3,
  bug:medium)**: `file_exists()` follows links, so a DANGLING
  build.json symlink read as absent — the seam never ran, the embed
  silently turned off, and a library-less zip built and published at
  exit 0 (the t31-r4-17 directory shape's link sibling, one gate
  further out). `is_link()` fires FIRST: dangling and resolving
  (out-of-tree) links both refuse naming the target; a regular
  build.json builds unchanged.
- **A mid-name relative use refuses loudly (t31-ocr3-4, bug:medium)**:
  `use Foo\ namespace \Bar;` (and the comment-interrupted spelling,
  which arrives as the fused T_NAME_RELATIVE) and the alias slot
  (`use Foo as namespace\Bar;`) spliced FROM the keyword, left the
  preceding separator standing, and shipped `use Foo\ \Deicod\…` — a
  double-separated parse error the postcondition waved through — at
  exit 0 (reproduced through the real rewriter, php -l-verified). The
  judgment is positional through a backward twin of the walk's own
  trivia vocabulary: the operator is only the grammar's as the
  import's LEADING name; every leading spelling (fused, interrupted,
  function/const kinds) still rewrites.
- **The scanner library owns its load path (t31-ocr3-5,
  maintainability:medium)**: the repo-walk prune's fold mechanic
  lives in the vocabulary owner (plugin-tools.php) and the scanner
  relied on callers loading it first — a fresh process requiring only
  bin/lib/secret-scanner.php fataled mid-scan on the first walked
  entry. The dependency is declared by require_once INSIDE the
  library (one-way: plugin-tools stays dependency-free); a subprocess
  regression pins the fresh-process load pattern scanning and pruning.
- **The clock pin asserts the port's contract (t31-ocr3-6, test:low)**:
  the SystemClock pin asserted `$second >= $first`, which an NTP step
  between adjacent now() calls breaks — an intermittent CI flake and
  a contract violation (ClockInterface disclaims monotonicity by
  docblock). The weakened pin is deterministic: every reading inside
  the ±5s sanity window around time(), in UTC.
- **The instant arithmetic declares its range rejection
  (t31-ocr3-7, documentation:low)**: plus_seconds() carried no
  @throws and minus_seconds() documented only the PHP_INT_MIN
  negation, yet both ride offset_in_utc()'s representable-range
  guard; both @throws tags name it now.
- **All five entry scripts ride the CLI helper (t31-ocr3-8,
  maintainability:low)**: scan-secrets.php — the last bin/ CLI
  wearing file-top error_reporting()/ini_set() calls that ran in
  every requiring process (the r9-4 class) — consumes
  wp_connectors_cli_entry()/wp_connectors_cli_args(); the GPC guard
  pin and the require-side display_errors pin cover all five, and
  the ledger's r12-11 line records the sweep closed.

### Fixed (shared — M3 Task 3.1, OCR round 2)

Second OCR-tool round (the same complementary deterministic reviewer —
alibaba open-code-review on glm-5.3 — two passes over the full branch
diff, union coverage 54/54 files); driver triage accepted 13/13
findings after tool dedup — THREE of them direct follow-ons of the
round-1 fixes (the serialize-masking doctrine's unextended VOs, the
storage fake's unfenced monotonicity, the undocumented control-byte
screen), the tool auditing our own prior round's work, not re-deriving
it — fixed as t31-ocr2-1..11 — one commit per finding, the full
offline check green after every commit, a regression per fix (all
eleven). A two-lens verifier pass (independent correctness + security
agents over the whole round diff via a deterministic workflow, every
raised finding driven to an adversarial skeptic prompted to refute,
probes executed where drivable) raised 3 raw findings — one found
independently by both lenses — deduped to 2 unique, BOTH refuted
high-confidence (one pre-existing outside the round's seams and twice
ledger-covered by name, one a below-the-bar wording nit on an
adjudication record whose re-open condition is unmet; both refutations
re-derived by the driver) — nothing to fix in-round, both ledgered as
boundaries. Suite 1605 → 1613 tests, 44798 → 44911 assertions,
2 skipped unchanged.

- **serialize() is masked on the token VOs (t31-ocr2-1, security:high)**:
  AccessTokenSet and StoredGrant hooked only `__debugInfo()` —
  serialize() bypasses it by engine design, so serialize() of the set,
  the grant, or any container holding them emitted both token
  positions in cleartext. Both VOs hook `__serialize()` to the SAME
  masked view the dump renders (one owner per VO; the grant's nested
  set delegates through its own hook), `__unserialize()`/`__set_state()`
  refuse (masked snapshots are lossy by design — to_array()/from_array()
  are the storage round trip), and var_export() stays the one
  named-excluded channel. The reference storage fake — whose
  detached-copy boundary rode exactly the closed serialize() channel —
  re-states through the strict storage serialization plus the
  class-scope private-constructor hydration the StoredGrant forward
  note reserves for Task 3.2's named producer.
- **HeaderMap's serialize channel has the parity its doctrine demands
  (t31-ocr2-2, security:high)**: the map hooked only `__debugInfo()`,
  so a bare serialize($map) dumped the folded header index with full
  Authorization/Cookie values — the cleartext its own docblock forbids
  ("the dump mirrors the masked map so the two channels cannot
  drift"). `__serialize()` rides the same masked view (one source,
  both channels, pinned to agree with print_r()), reconstruction
  refuses.
- **The storage fence is monotonic (t31-ocr2-3, bug:medium)**: save()'s
  CAS judged only PERSISTED === EXPECTED — a stale grant whose OWN
  generation sat below the expectation passed (persisted 4, expected
  4, grant at 3) and REGRESSED the persisted fence, resurrecting
  revoked tokens. The contract now requires grant.generation() >=
  expected_generation (every legitimate commit satisfies it: the
  writer observed the persisted state and moved forward from it) and
  the reference fake rejects a lower-generation grant loudly, nothing
  committed — the same typed-caller-bug class the provider-identity
  rule rides.
- **The save() contract states the control-byte screen (t31-ocr2-4,
  documentation:low)**: the reference fake screens the
  caller-controlled $provider_id before any other judgment (the
  round-1 verifier's fix), but the docblock never said so — a Task
  3.2 adapter implementing exactly the written contract could
  legitimately skip the screen and let an unscreened key ride a
  rejection message. The docblock now specifies everything the fake
  enforces.
- **A bracketed host must BE an IPv6 literal (t31-ocr2-5, bug:low)**:
  the URL bracket screen validated placement and pairing, never
  content — 'http://[abc]/x' passed every screen and constructed an
  authority that is not an IP literal, contradicting the refusal
  message's own claim. The inner literal is judged now
  (FILTER_VALIDATE_IP, IPV6 flag); garbage refuses, true literals
  stay green, and glued garbage with a legal inner literal still
  refuses at its own (glue) screen.
- **The totality guard's overflow predicate has one owner
  (t31-ocr2-6, maintainability:low)**: RefreshPolicy's unrepresentable-
  threshold corner spelled the InstantArithmetic overflow inequality by
  hand beside the arithmetic's own guard — nothing structural tied
  them. The lower leg of the guard is the named public predicate
  `InstantArithmetic::offset_would_underflow()` now, the guard consumes
  it, the policy consumes it, and the exact boundary
  (expires_ts == PHP_INT_MIN + skew) pins identical behavior on both
  sides.
- **Negative Retry-After has one truth (t31-ocr2-7,
  documentation:low)**: the rate-limit exception's input contract said
  both parser forms "land here as seconds" (and the policy documents
  and tests negative clamping — implying negatives flow from parsers)
  while its constructor REJECTED them. The parser reality wins: the
  constructor clamps a negative delta to zero ("retry immediately"),
  null stays the distinct "provider sent none", both docblocks state
  the one rule, and the pin rides both sides.
- **The PKCE pair enforces its own invariant (t31-ocr2-8,
  maintainability:low)**: only S256 is supported and from_verifier()
  was the sole producer, yet a hand-built or corrupted pair whose
  challenge != BASE64URL(SHA-256(verifier)) was representable and
  failed far away at the provider as an opaque invalid_grant. The
  constructor verifies the binding (constant-time compare through the
  one derivation owner from_verifier() also rides); no unverified
  rehydration path exists.
- **Exception finality is pinned (t31-ocr2-9, test:low)**: finality is
  the fence making "is this retryable?" answerable by type — a
  subclass of a non-final terminal type implementing the transient
  marker would satisfy both the marker check and the terminal catch
  arm. The pin holds `final` on every concrete OAuth exception type
  (and the marker stays a bare extending-nothing interface tag).
- **The PCRE burner trips deterministically (t31-ocr2-10,
  test:medium)**: the abort depended on the host's pcre.* ini — a
  host with a raised backtrack limit could let the burner's match
  complete and change the failure surface. A small pinned
  pcre.backtrack_limit wraps the burner invocation (restored in
  finally on every exit path), probed abort-deterministic as low as
  256 while the clean twin needs nowhere near it.
- **The namespace scan reads abort-as-refusal (t31-ocr2-11, test:low)**:
  a PCRE abort degraded the PSR-4 sweep's namespace scan to [] and the
  failure surfaced as the misleading "must declare exactly one
  namespace". The result is captured and asserted not-false with the
  file named — the doctrine its own type-scan sibling already spells.

### Fixed (shared — M3 Task 3.1, OCR round 1)

First OCR-tool round (a complementary deterministic reviewer — alibaba
open-code-review on glm-5.3 — over the full branch diff, 51/51 files);
driver triage accepted 9/9 findings (none ledger-covered; the neighbors
of earlier fixes adjudicated as seams those rounds missed, not dupes),
fixed as t31-ocr1-1..9 — one commit per finding, the full offline
check green after every commit, a regression per fix — plus a
two-lens verifier pass (independent correctness + security agents
over the whole round diff, every raised finding adversarially
re-derived by the implementer and reproduced where drivable) that
raised 8 distinct findings after cross-lens dedup (one found
independently by both lenses) — one MEDIUM, four LOW, three notes —
five fixed in-round as t31-ocr1-10..14 and three ledgered as stated
boundaries. Suite 1599 → 1605 tests, 44742 → 44798 assertions,
2 skipped unchanged.

- **The one unguarded `createFromFormat()` is guarded (t31-ocr1-1)**:
  InstantArithmetic's reconstruction chained `->setTimezone()` on a
  parse it never checked — a false return escaped as an engine Error
  instead of the documented InvalidArgumentException. The guard rides
  the extracted `reconstruct()` seam (the false is not drivable
  through the public arithmetic on a 64-bit build — the int domain IS
  the DateTime domain), and the regression drives the seam with a
  spelling the internal derivation cannot produce.
- **The empty-host URL spelling refuses explicitly (t31-ocr1-2)**:
  `parse_url()`'s answer for `http://:8080/` is build-dependent — some
  builds in the supported floor return `host => ''` (key present,
  empty), where the isset() gate passed and a hostless authority
  constructed; this build returns false outright. The explicit `''`
  leg refuses the spelling on every build.
- **The removal seam never deletes through a link (t31-ocr1-3 +
  t31-ocr1-11)**: the finally's stage teardown was the one seam
  without the r10-10 link guard — a mid-build swap of the stage
  directory for a symlink handed rrmdir() a linked root and the walk
  emptied the TARGET tree (pre-fix body reproduced). The single-owner
  fix hardens rrmdir() itself (a root link stands untouched; a linked
  child is unlinked AS ITSELF, never descended into), and the
  verifier round carried the same doctrine to the harness twin
  (WpHarness::rrmdir — both lenses found it independently; the
  tests' predictable /tmp scratch names are pre-plantable on a
  shared host).
- **The URL scheme/host folds ride AsciiFold (t31-ocr1-4)**: two
  locale-sensitive `strtolower()` folds sat beside the ONE case-fold
  owner while a Url.php docblock claimed the engine folds are
  locale-independent "since PHP 8.2" — a claim AsciiFold's own
  docblock contradicts. Both folds consume the byte table
  (identical everywhere BY CONSTRUCTION), the docblock states the
  fold owner instead of engine history, and the manufactured-locale
  pin folds `SIMPLE-I.EXAMPLE` byte-identically under a live Turkish
  locale (the glibc divergence is real at the libc level —
  `tolower('I')=0xFD`, probed via a C probe; on this engine PHP's
  own strtolower does not consult LC_CTYPE, probed over all 256
  bytes, so the pin is argued-from-the-tables the r11-6 way).
- **The scanner prune rides the vocabulary's own fold
  (t31-ocr1-5 + t31-ocr1-12)**: the repo walk's prune list is a
  SUBSET of the one development-entry vocabulary, judged by the same
  fold the vocabulary owner rides — `wp_connectors_segment_is_named()`
  is the extracted mechanic (a case-variant `VENDOR/` or `Tools/`
  prunes exactly where the folded gates judge it a development
  entry; `Tests/` and the dotless `phpunit.cache/` stay scanned —
  vocabulary members the subset does not name). The verifier round
  closed the seam beside it: the prune judged FULL pathname parts,
  so a dev-named ANCESTOR of the scan root silently pruned the whole
  scan (0 findings, exit 0 — reproduced under a `Dist/` ancestor;
  the exact-case shape predates the round); segments BELOW the root
  judge now.
- **The locale snapshots query (t31-ocr1-6 + t31-ocr1-14)**:
  `setlocale(LC_CTYPE, null)` SETS from the environment (reproduced)
  — a null-"snapshot" could restore a different LC_CTYPE than the
  one in effect. Both sites use the query spelling `'0'`, and the
  verifier-hardened pin installs a locale differing from the
  environment's and asserts the query leaves it standing (the naive
  current==current shape was vacuous).
- **save()'s provider identity is contract (t31-ocr1-7 +
  t31-ocr1-13)**: the storage key MUST equal the grant's own label —
  the port Task 3.2's adapters implement against now documents the
  rule (a disagreement is a misrouted call; implementations REJECT
  and commit nothing), and the reference fake enforces it — with the
  caller-controlled key screened through the ONE control-byte guard
  before it rides the rejection's message (the r13-2 class).
- **serialize() rides the masked view (t31-ocr1-8)**: the redaction
  docblocks claimed the masked contract for "the serialized form",
  but `__debugInfo()` covers only the debugger channel — serialize()
  and var_export() bypass it by engine design and dumped the raw
  property tree (URL with query/userinfo, raw Authorization/Cookie,
  body). `__serialize()` returns the byte-identical masked
  vocabulary, `__unserialize()`/`__set_state()` refuse (snapshots,
  not round-trip payloads), var_export() is the named exclusion, and
  the pin asserts exactly that documented truth.
- **The scratch-tree helpers live once (t31-ocr1-9 + t31-ocr1-10)**:
  WpHarness::copyTree() joins rrmdir() as the ONE pair (one error
  policy — loud), the per-test twins are gone, and the namespace-less
  harness files take no non-compound use statements (the engine
  warned on every load — ocr1-7's import, caught by the verifier).

### Fixed (shared — M3 Task 3.1, review round t31-r13)

Fix round over round-13's three counted findings (one MEDIUM
security with a fresh empirical repro satisfying the ledger's
re-open rule, one LOW correctness, one cleanup), fixed as
t31-r13-1..3 — one commit per finding, the full offline check green
after every commit, a revert-proven regression per repro fix — plus
a two-lens verifier pass (independent correctness + security agents
over the whole round diff, every raised finding adversarially
re-derived by the implementer, byte-level where mechanism claims
were made) whose one both-lens finding and one adopted precision
item landed in-round as t31-r13-4/5; one lens claim refuted under
byte-level re-derivation. Suite 1596 → 1599 tests,
44708 → 44742 assertions, 2 skipped unchanged.

- **Every inspector verdict line renders archive-controlled names
  through the ONE printable seam (t31-r13-1 + r13-4)**: a raw
  stored zip's entry name survives `getNameIndex()` byte-exact —
  newline included; neither ZipArchive side sanitizes control bytes
  in names on this runtime (both probed; the r12 ledger entry's
  "sanitizes both sides" premise corrected under the repro) — so six
  verdict lines printed a forged `inspect: … ACCEPTED (0
  violations)` line on STDERR beside the real REJECTED verdict. All
  six named sites ride the seam (plus the invalid-slug refusal, the
  same class one grammar step earlier), and the verifier round
  closed the residual the round's own claim missed: the MERGED
  helper lines — main-file basenames, header values, the
  version-constant value, the self-containment walk's landed paths
  and include statements — render through the seam at the merge
  (`wp_connectors_printable_lines()`), the helpers staying pure
  producers (build and the conventions gate render them over the
  repo's own trusted bytes; the inspector is the hostile-input
  surface).
- **The stored grant's provider id joins the ONE control-byte guard
  (t31-r13-2)**: the trim screen alone let a `\n`-bearing provider id
  construct, and `print_r()` of the grant forged a line BESIDE the
  masked token set (reproduced) — the exact channel the r12-5 guard
  closed on the pending flow's label. One `HeaderMap::
  assert_no_control_bytes()` call in the private constructor — the
  same callable, not a copy — holds for every produced grant
  (in_state and every transition funnel through it).
- **The use-walk's three fence predicates ride ONE owner each
  (t31-r13-3)**: the builder's use-rewrite walk re-implemented the
  detector's closure-use fence, statement-boundary tag set (r8-1),
  and declaration-shape predicate (r8-10) — the third hand-rolled
  copy of a vocabulary whose defect history is one copy drifting at
  a time. Hoisted to `wp_connectors_use_opens_import()` /
  `wp_connectors_is_use_statement_boundary()` /
  `wp_connectors_namespace_opens_declaration()` with the provenance
  comments moved into the owners; behavior-identical, pinned by the
  existing batteries, a fifteen-case before/after differential
  (byte-identical), and the verifier lens's own 70,000-input fuzz
  (zero mismatches).
- **Record precision, adopted and refuted (t31-r13-5)**: libzip's
  captured `extractTo()` warning SUBSTITUTES control bytes with
  visible glyphs (U+25D9/U+2190 — probed byte-level), it does not
  omit them; and the verifier's counter-claim that `php -l` omits
  the newline from a hostile filename was REFUTED (`od -c` shows the
  raw byte inside the engine's message) — both php -l interpolations
  stay load-bearing on this runtime.

### Fixed (shared — M3 Task 3.1, review round t31-r12)

Fix round over round-12's ten counted findings (four correctness, six
cleanup; two struck, two noted), fixed as t31-r12-1..14 — one commit
per finding, the full offline check green after every commit, a
regression per fix proven red under its pre-fix predicate (mutation or
revert) — executing two driver adjudications: the r6 ledger line's
secret-scanner pruning owned as this round's material, and `location`
re-opened on RFC 6749 §4.1.2 vendor-doc proof. Sequencing per the
driver: the HTTP facade trait (r12-13) landed before the masking
changes (r12-4/5), and the embed-prefix owner (r12-10) before the
scoped postcondition (r12-12). A two-lens verifier pass (independent
correctness + security agents over the whole round diff, every raised
finding adversarially re-derived and — where drivable — empirically
reproduced) raised six findings, all six fixed in-round as
t31-r12-15..20. Suite 1581 → 1596 tests, 44540 → 44708 assertions,
2 skipped unchanged.

- **A partial extraction refuses loudly, never inspects green
  (t31-r12-1)**: the inspector's second zip open()/extractTo() pair
  was unchecked — a >NAME_MAX entry made extractTo() return false
  mid-tree, the engine warning leaked raw to output, and every check
  ran over whatever subset HAD extracted while the never-extracted
  remainder (webshell, live key) was judged by nobody: ACCEPTED at
  exit 0 (reproduced). Extraction failure is a refusal naming the
  captured reason (silenced, never leaked raw), and no content check
  runs over a partial tree — the artifact is judged whole or not at
  all.
- **A lone `]` refuses; the bracket pair wraps the whole host
  (t31-r12-2 + r12-18)**: the glued-bracket screen trusted the last
  `]` as the IPv6 closer without asking for its opener —
  `http://host:44x]/p` was accepted with authority `host:44` (the
  port truncated at the raw bracket; url() and the redacted form
  diverged inside the VO). The pair leg now enforces the RFC 3986
  host grammar whole: exactly one `[` at the host's first byte, one
  `]`, non-empty literal between — the verifier round closed the
  under-enforcement (the empty literal `[]` and mid-host pairs
  `a[b]`/`x[y]:8080` constructed against the rule's own claim).
- **Artifact secret scans run unpruned — the r6-owned HIGH closed
  (t31-r12-3)**: the scanner's dev-segment prune applied to extracted
  artifacts too, so a live key at `<slug>/src/Shared/vendor/keys.txt`
  inspected ACCEPTED while the identical key one directory up
  rejected (reproduced — the r6 ledger line's named defect). The
  prune is now a parameter, a repo-walk concept: the inspector scans
  the shipped tree unpruned, closing the composition with the
  src/Shared dev-entry exemption (classification exempts, content
  still judges every shipped file).
- **`location` joins the sensitive-header catalog, on vendor-doc
  proof (t31-r12-4)**: RFC 6749 §4.1.2 carries the authorization code
  in the 3xx redirect's Location query — a 302's Location IS a
  credential-bearing surface by specification, and it rendered
  verbatim through every safe debug form (reproduced). One owner, the
  catalog; the value masks like any bearer surface, and the
  HttpResponse contract prose names its own credential channel now.
- **Provider-supplied codes ride the ONE control-byte guard
  (t31-r12-5)**: device_code/user_code constructed with a raw CRLF
  and print_r forged lines in the MASKED debug tail (the mask keeps
  the last four characters, controls included — reproduced); the
  pending authorization's provider id rendered raw through a flow
  dump. HeaderMap::assert_no_control_bytes() — the vocabulary owner's
  own callable, no new vocabulary — gates all three (a future
  code/state field on the VO joins the same guard, not a copy).
- **The success line's digest is guarded (t31-r12-6)**: the CLI echo
  interpolated hash_file() unchecked, so a zip unreadable between
  buildPlugin() returning and the echo printed `sha256=` BLANK at
  exit 0. The guarded helper owns the digest — same shape as the
  sidecar seam's own refusal.
- **checksums.txt regeneration prunes (t31-r12-7)**: the merge kept
  every other line forever, so a deleted connector's zip left a stale
  line that failed verification on every future check while builds
  exited 0 (reproduced). Regeneration drops entries whose artifact no
  longer sits beside the manifest (inside the merge lock; malformed
  lines die by the same rule), superseding the old "deleted
  out-of-band leaves its entry behind" note — the manifest is an
  inventory whose every line names an existing artifact.
- **The rebuilt authority is re-validated post-parse (t31-r12-8)**:
  the entry gate guarantees the INPUT bytes; nothing re-checked the
  OUTPUT side (parsed host + case fold). The mangler it guards
  against is real C-library behavior (manufactured
  tr_TR.ISO-8859-9: tolower(0xC3)=0xE3 breaks a UTF-8 host's second
  byte — pinned live via localedef + ctype), but the engine folds
  have been locale-independent since PHP 8.2 (this project's floor,
  the strtolower-ascii RFC), so the guard is the three-line
  class-killer, not a reproduced defect; the probe is driven directly
  with the pre-8.2 mangled spelling. (The round's first locale pin
  leaked the manufactured locale into the suite — glibc resolves the
  RESTORE through LOCPATH, and a partial locale dir made the restore
  fail silently, breaking later tests' `/i` matching ~1 run in 3;
  caught by this round's own verifier pass, fixed in-place: LOCPATH
  restored before the locale, the restore checked.)
- **The lint gate's exclusions ride the ONE vocabulary (t31-r12-9)**:
  the hand-rolled case-sensitive list had drifted — the dotless
  `phpunit.cache/` and `VENDOR/` were linted while the builder
  excluded and the inspector rejected both spellings. The walk
  consumes wp_connectors_is_development_entry() per segment,
  root-relative (the tests ROOT stays the gate's own charge); the
  judged file set on the current tree is unchanged.
- **The embed destination prefix has ONE owner, two fold roles
  (t31-r12-10 + r12-16)**: the writer spelled the territory with a
  case-insensitive collision fence while the inspector's exemption
  was byte-exact — two verdicts on one destination. One helper in the
  library serves both sides, and the verifier round separated the
  fold roles by doctrine: the writer's fence folds (any case-variant
  of a generated destination refuses the build), the inspector's
  exemption matches the CANONICAL prefix only — the first cut folded
  the exemption too and both lenses reproduced the regression (a
  hostile zip's `SRC/SHARED/composer.json` went REJECTED→ACCEPTED at
  exit 0); the byte-exact exemption is back, foreign case-variants
  judged by the vocabulary.
- **The CLI-entry guard is ONE helper (t31-r12-11 + r12-17)**: four
  hand-maintained copies of the t31-r9-4/t31-r10-3 idiom (guard +
  diagnostics) collapsed into wp_connectors_cli_entry(); the
  verifier round moved it off $_SERVER['argv'] (unpopulated under a
  variables_order ini without "S" — every entry script became a
  silent exit-0 no-op, reproduced) onto the auto-global the CLI SAPI
  always populates. scan-secrets.php keeps its own guard for its own
  round (ledgered).
- **The embed postcondition walks the subtree, anchored at the
  composed root (t31-r12-12)**: the composed-tree scan re-tokenized
  the whole staged tree although the pre-gate had already judged the
  plugin files byte-for-byte against the same anchor (30 embedded
  sources instead of all 36 staged PHP files per example-connector
  build). The load-bearing subtlety is pinned: the WALK narrows, the
  ANCHOR does not — a naive subtree anchor would refuse a shared
  source whose include is anchored at the plugin root above the
  subtree (proven by mutation: exactly that false refusal fires).
- **The HTTP VOs' header facade is ONE trait (t31-r12-13)**:
  byte-identical twins of headers()/header()/__toString()/
  __debugInfo() across the two VOs (four copies of the masking
  plumbing after the location round) collapsed into the
  HasMaskedHeaders trait; the only divergence kept is the
  request-line/status-line head. The r2-13 repeated-header note and
  the r10-5 digit-key caveat moved to the trait's single docblock;
  every existing string-form and dump pin stayed green unchanged.
- **buildPlugin's docblock states the layout coupling (t31-r12-14)**:
  the public static API derives the shared library from
  `dirname($distDir)/shared/src` — documented with the wrong-content
  failure mode (a loud refusal covers absence, not identity);
  parameterizing is ledgered for a second caller. Doc-only.

Verifier round (t31-r12-15..20, both lenses' findings, all confirmed
and fixed in-round): **byte-exact duplicate entry names refuse
(t31-r12-15, the security lens's HIGH)** — a hostile zip may carry one
entry name twice; extractTo() returns TRUE keeping only the last copy,
so a webshell or live key in the first copy shipped green (reproduced
end-to-end on a real built zip); the classification loop now fences
byte-exact AND case-fold duplicates (the latter consumes the r6
deferred collision class's inspector half — the builder-side fence
stays the r6 line's own round), with interpolated names rendered
through a new one-seam printable filter (every C0 control and DEL
neutralized). **The captured extraction reason renders through that
same seam (t31-r12-19)** and **the capture handler's restore rides a
finally (t31-r12-20)** — both hardening with stated runtime
boundaries (this libzip sanitizes control bytes in entry names on
both write and read; extractTo() warns-and-returns rather than
throwing). **The CLI guard's argv fix (t31-r12-17)** and **the bracket
whole-host wrap (t31-r12-18)** are the two lenses' MEDIUM findings,
both reproduced and closed as above.

### Added (shared — M3 OAuth foundation, Task 3.1)

Provider-neutral OAuth contracts under `shared/src` (source namespace
`Deicod\WpConnectors\Shared`, PSR-4; the build's namespace rewriter
already targets it, record 0005). Six commits, each suite-green:

- `AccessTokenSet` (Token): immutable token-set VO. Nullable refresh
  token models "no replacement issued" distinctly from empty; expiry is
  derived (obtained-at + expires_in, never stored independently);
  validation rejects empty/whitespace tokens and non-positive
  expires_in; `with_replacement_refresh_token()` encodes the Task 3.3
  merge rule (null keeps the stored token); strict
  `to_array()`/`from_array()` storage serialization (the five modelled
  keys required and strictly typed — no coercion, microsecond instants,
  serialized expiry must re-derive — while unmodelled extra keys are
  deliberately ignored so a newer version's payload still loads; format
  versioning stays the encrypted envelope's job, never the payload's).
- `ClockInterface` + `SystemClock` (Clock): the time port; the harness
  gains `DeterministicClock` for time-sensitive contract tests.
- `OAuthRuntimeException` family (Exception): abstract base plus six
  concrete types — transport and rate-limit (both transient, sharing the
  `OAuthTransientException` marker so retryability is decided by type
  alone), terminal-auth (reconnect required), configuration (update
  required, the third terminal class), storage (fails closed),
  malformed-response. Rate-limit exposes parsed Retry-After seconds;
  none of them carries payload or credential material (reflection-pinned).
- `HttpRequest`/`HttpResponse`/`HttpTransportInterface` (Http) +
  `SecretMask` (Support): the neutral HTTP port. Both VOs' `__toString()`
  is safe by construction — URL query/userinfo dropped, sensitive header
  values masked `…last4`, body always omitted; a token in an
  Authorization header, URL, or body can never reach a string form.
  Statuses are responses at the port; timeouts/redirect policy/TLS are
  the Task 3.7 binding's documented contract (redirects disabled or
  origin-revalidated for credential-bearing requests). `Url` owns the
  one absolute-http(s) validation rule.
- `GrantState`/`StoredGrant`/`TokenStorageInterface` (Grant) +
  `RefreshPolicy` (Policy): the persisted grant carries the fencing
  generation (strictly forward; `revoke()` produces the tombstone that
  late refreshes/exchanges commit against) and the state/token
  compatibility rules; the storage port documents the envelope
  invariants now (atomic replace, versioned, provider+site bound,
  never partial plaintext, fail closed) for Task 3.2 to implement. The
  refresh policy holds neutral numbers only (skew, initial backoff, one
  cooldown cap governing BOTH Retry-After forms and the fallback
  backoff) and the two pure rules: expiry-minus-skew and the
  Retry-After clamp.
- `AvailabilityState`/`AvailabilityContext`/`OAuthAvailabilityInterface`
  (Availability) + flow shapes (Flow): five availability states
  (including the configuration-error "Update required" state) with
  neutral labels; the context parameter carries the GET-render
  read-only contract (cached grant state only — no HTTP, no rotation,
  no event creation); `DeviceAuthorizationSession`, `PkceCodePair`
  (S256 pinned against the RFC 7636 appendix B vector), and
  user-scoped `PendingAuthorization` (exactly one flow payload,
  structurally enforced). No endpoints or client ids anywhere.

Architecture enforcement: `SharedOAuthArchitectureTest` sweeps
`shared/src` for WordPress reach (functions, hooks, options, globals,
auth-salt constants — case-insensitive), provider names (code and
docs), inline namespace spellings that would break the Task 3.8
rewrite, static mutable state, and PSR-4 discipline — non-vacuity
guarded by a file floor plus anchor files, mutation-batteried
(`add_action`, `WP_REMOTE_GET`, a provider name in a docblock, an
inline FQCN reference, a static property each fail their sweep).

Review round (two-lens, four dimension reviewers + adversarial
verification over the branch diff; 8 findings raised, 4 confirmed,
4 refuted): CRLF in header names/values is rejected at both VO
constructors (a forged `Authorization:` line in the debug form was
constructible — `X-Foo\r\nAuthorization` rendered its value verbatim);
the method-token and PKCE verifier patterns anchor `\z` (a trailing
newline passed both); `with_state(Revoked)` is rejected outright —
`revoke()` is the only tombstone producer, so no un-advanced tombstone
is mintable; the sweep's dead `doing_it_wrong` entry is fixed to
`_doing_it_wrong` and the per-site/URL vocabulary
(`get_blog_option`, `switch_to_blog`, `network_admin_url`,
`add_query_arg`, ...) joins the ban list, mutation-batteried. Refuted
and consciously deferred: the PKCE CSRF `state` member (plugin flow
config, Tasks 3.6/M6) and parse_url host-charset hardening
(below-the-bar for constructor validation).

New test suites: `SharedOAuthContracts*Test` (token/clock 34, errors 9,
HTTP 36, grant 22, policy 16, availability 5, flow 21) +
`SharedOAuthArchitectureTest` (5) — 1305 → 1453 tests, 42179 → 42668
assertions, 2 skipped unchanged (live-key gates).

### Fixed (shared — M3 Task 3.1, review round t31-r1)

Fix round over the review's 15 findings (12 counted defects + 3 scope
decisions), 21 commits (t31-r1-1..12, a saturation follow-up,
t31-r1-13/14 executing the two DECIDE+IMPLEMENT scope notes, and six
verifier-round fixes t31-r1-16..21 after the two-lens verifier pass),
each suite-green:

- **DST-proof absolute-second arithmetic** (t31-r1-1..3 + follow-up):
  expiry was derived via wall-clock `modify()` in the reading's named
  timezone — across a spring-forward a 7200-second lifetime really
  spans 3600 absolute seconds, and `from_array()`'s re-parse then
  rejected the payload `to_array()` itself produced (permanently
  unloadable grant). New single owner `Support\InstantArithmetic`
  (raw integer-timestamp arithmetic — DST-proof AND
  saturation-proof: `modify()` silently no-ops near a trillion
  seconds); serialization is canonical UTC (the only
  DST-unambiguous spelling); the policy's expiry-minus-skew
  threshold rides the same owner. Pinned across Europe/Berlin,
  America/New_York, and Australia/Sydney in both transition
  directions, plus pre-epoch and saturation-scale boundaries.
- **`expires_in` upper bound** (t31-r1-4): the derived expiry must
  stay inside the serializable range (the last UTC second of year
  9999) — an unchecked derivation saturated silently (expiry ==
  obtained-at with every gate green). Boundary pinned exactly;
  obtained-at readings are floored at year 0000 for the same
  round-trip reason (t31-r1-20).
- **`Http\HeaderMap`, the single header-map owner** (t31-r1-5): the
  validation loop, case-insensitive lookup, and masked render lived
  near-verbatim in both HTTP VOs; all three now live once (behavior
  identical, lockstep-pinned). Hardened inside the owner:
  the full control-byte class in values with HTAB legal per RFC 7230
  (t31-r1-9) and the UTF-8 spellings of C1/U+2028/U+2029 (t31-r1-19),
  case-variant duplicate names rejected (t31-r1-10), and header
  NAMES must be RFC 7230 tokens — off-grammar spellings
  ('Authorization ' et al.) had dodged the SecretMask vocabulary and
  rendered full secrets unmasked (t31-r1-16, the verifier's MEDIUM
  redaction hole). All gates read abort-as-reject.
- **`SecretMask` is character-wise, never invalid UTF-8** (t31-r1-6):
  the byte-wise tail split multibyte characters mid-sequence and
  `json_encode()` dropped the redacted log line. Pure byte-level
  UTF-8 awareness (no mbstring dependency): the tail is the last
  four complete characters, binary values degrade to the longest
  valid trailing run or the bare mask.
- **Architecture sweep hardening** (t31-r1-7/8/17/21): a PCRE abort
  and an unreadable swept file both fail LOUDLY (the old silent
  fallbacks swept the file as zero-or-one contentless lines with
  every gate skipping it); the static-mutable pattern catches typed,
  DNF-typed, and untyped spellings (battery-pinned both directions).
- **`StoredGrant::revoke()` at PHP_INT_MAX** (t31-r1-11) rejects with
  the documented type instead of overflowing the int to a float
  TypeError; `InstantArithmetic::minus_seconds(PHP_INT_MIN)` likewise
  (t31-r1-18).
- **Serialized instants must be the exact canonical spelling**
  (t31-r1-12): `createFromFormat()` alone accepted 'Z' suffixes,
  padded fractions, whitespace, non-UTC offsets, and silently ROLLED
  calendar-impossible dates — laundering corrupted payloads into
  valid sets. Shape-validated and calendar-honest now.

### Changed (shared — M3 Task 3.1, review round t31-r1 scope decisions)

- **The storage port commits generation-checked** (t31-r1-13):
  `save(provider, grant, expected_generation): bool` is a
  compare-and-set against the persisted generation — Task 3.3's
  fencing requirement fixed into the port before the envelope and
  coordination tasks pin the three-method shape. `EXPECT_NO_GRANT`
  names the absent precondition (first install); a false return is a
  fence verdict (the late writer discards its tokens, never merges),
  not an error. The in-memory fake implements the CAS; the
  revoke-versus-late-refresh race is pinned end-to-end.
- **Token-set reads are forward-tolerant** (t31-r1-14): the five
  modelled keys stay required and strictly validated; keys this
  version does not model are ignored, so Task 4.4's sixth key loads
  on older readers instead of fail-closing every stored grant into a
  forced re-connect. Versioning stays the envelope's single
  authority; a load → save round trip through an older reader drops
  the unmodelled keys (documented and pinned).
- **`OAuthRateLimitException::retry_after_seconds()` stays raw**
  (t31-r1-15, adjudicated, no fix): the clamp is the consumer's job
  (`RefreshPolicy::capped_retry_after_seconds()`); the adjudication
  and its reopen condition (a second consumer) are recorded at the
  accessor and in the ledger.

Verifier pass: two independent lenses (correctness + security) over
the whole round diff — every fix-claim HELD (re-driven empirically,
the DST battery extended into +30-minute and +12:45 zones), six
findings fixed as t31-r1-16..21, below-bar notes ledgered. Suite
1453 → 1495 tests, 42668 → 42899 assertions, 2 skipped unchanged.

### Fixed (shared — M3 Task 3.1, review round t31-r2)

Fix round over the round-2 review's 15 findings — 11 counted defects
fixed as t31-r2-1..12 (one of them, the build.php embed_shared
defect, a fix-now forward recommendation on pre-existing master
code), plus three cheap halves: the repeated-header collapse
documented honestly and deferred to Task 3.7 (t31-r2-13), the
locale-sensitive fold hardened (t31-r2-14), and the sensitive-header
list staying ledgered-refuted (t31-r2-15, no fix). A two-lens
verifier pass over the whole round diff found every one of the 14
fix-claims HELD and confirmed four new findings, fixed as
t31-r2-16..19. Each commit suite-green:

- **Control bytes cannot ride the URL surface** (t31-r2-1):
  `Url::parse_validated()` passed the raw path/host through, so
  U+2028/U+2029/NEL and the C0 range reached
  `redacted_url()`/`__toString()` verbatim — the forged-log-line
  class t31-r1-19 closed in header VALUES, reopened in the URL
  position. The screen rides ONE vocabulary, owned by HeaderMap
  (public constant; the header rule and the URL rule cannot drift),
  applied before `parse_url` with abort-as-reject. The space-in-host
  tolerance stays per the round-1 host-charset adjudication (a tab
  normalizes to an underscore inside modern parse_url anyway).
- **The bidi/override controls are banned** (t31-r2-6): the whole
  class — U+202A-U+202E embeddings/overrides and U+2066-U+2069
  isolates — joins the shared vocabulary, closing character-reorder
  spoofing in provider header values (RLO rendering 'ok' + 'evac' as
  a mirrored run) and, through the shared constant, the URL position
  too.
- **The harness clock rides the shared instant arithmetic**
  (t31-r2-2): `DeterministicClock::advanceBy()` spelled its shift as
  wall-clock `modify('+N seconds')` — across a DST transition +7200
  moved 3600 real seconds, and a trillion-second advance silently
  no-opped. It routes through `InstantArithmetic::plus_seconds()`
  like the production expiry derivations; both repro shapes pinned
  (absolute timestamp deltas and wall spellings, both transition
  directions).
- **The revocation tombstone is only ever minted by revoke()**
  (t31-r2-3): the constructor minted `Revoked` at ANY un-advanced
  generation — a tombstone persisted that way does not fence (an
  in-flight refresh CAS-commits over the revoke). The constructor is
  private now (revoke() and the immutable transitions are its only
  callers) and a named constructor `StoredGrant::in_state()` is the
  public entry, rejecting `Revoked` with the typed exception —
  un-advanced tombstones are unrepresentable through every public
  path. A tombstone transitioning back to a live state at the same
  generation stays by adjudication (fence-neutral; terminality is
  Task 3.3's policy), pinned with the citation. Task 3.2's hydration
  of a persisted tombstone gets a forward note: it needs its own
  deliberate producer, never the reopened plain constructor.
- **All-digit header names are the legal tokens they are**
  (t31-r2-4): PHP coerces a canonical digit-string array key ('123')
  to an int before the `is_string` gate, so legal RFC 7230 tokens
  were rejected with a misleading message. The int key is restored
  to its string spelling and the token grammar decides; `headers()`
  carries the name's PHP-canonical (integer) key.
- **The architecture sweep is whole-file, fail-loud, and owns its
  reads** (t31-r2-5/7/9/16/17): the static-mutable gate matches the
  WHOLE file (multiline property spellings escaped the per-line
  application; line-located diagnostics kept, mutation-tested
  end-to-end through the actual gate); a new gate bans direct
  clock/environment reach (time/microtime/hrtime/date + their
  clock-read twins + getdate/localtime + getenv/putenv + the
  environment superglobals — whole-file, so a call split between
  name and paren cannot slip it; prose naming a call shape is
  rewritten, never exempted); the line reader owns failed reads
  under BOTH runtime spellings (false, and the empty string a PHP
  8.5 directory read degrades to); and a PCRE abort REFUSES the
  whole-file gate (the verifier's catch: `1 === preg_match` read the
  recursion-limit abort as a clean pass on ~100 KB subjects — the
  glm36-8 doctrine applied to the round's own helper). shared/README
  now states the clock/env ban it implies.
- **HeaderMap keeps its folded index; the fold is locale-independent**
  (t31-r2-10/14): the constructor builds the lowercase index for the
  duplicate fence and `header()` is one isset probe instead of a
  rescan folding every entry per lookup; all three fold sites (fence,
  index lookup, SecretMask's vocabulary match) ride the new
  `Support\AsciiFold::lower()` — an explicit byte table with no
  LC_CTYPE to consult, so a Turkish-locale process cannot make the
  fence, the lookup, and the masking vocabulary disagree on
  'AUTHORIZATION' (argued from the fold tables; no tr_* locale
  exists on this host to reproduce).
- **One token-grammar owner** (t31-r2-11): `HttpRequest::
  METHOD_TOKEN_PATTERN` was a second verbatim copy of the tchar
  grammar with a drifted anchor; it is a constant-expression alias
  of `HeaderMap::NAME_TOKEN_PATTERN` now, identity-pinned.
- **embed_shared ships exactly the shared PHP sources** (t31-r2-12/
  18/19): the collection walked shared/'s PARENT directory (dev
  files into plugin zips) with a global `src/` strip that mangled
  nested segments; it now collects from shared/src, takes `.php`
  files only, validates namespace_suffix as a namespace segment, and
  escapes the provenance interpolation (backreference material in a
  preg_replace replacement shipped parse errors into zips). All
  three predate this branch (master code) — fixed here because this
  branch populates shared/.
- **Docs tell the truth** (t31-r2-8/13): the Unreleased Added bullet
  states from_array()'s real contract (modelled keys required and
  strict, unmodelled extra keys ignored for forward tolerance,
  versioning is the envelope's); the repeated-header collapse is
  stated at the response VO and the transport port with the
  representation decision assigned to Task 3.7.

Verifier pass: two independent lenses (correctness + security) plus
adversarial verification per finding — every one of the 14
fix-claims HELD (re-driven: an old-vs-new HeaderMap differential
over every constructible input class, both clock repro shapes with
named zones, an independent scratch-root build with a planted
nested-src file, token-stripped docblock-only proofs). Four
confirmed findings fixed as t31-r2-16..19: the PCRE-abort fail-open
(both lenses independently), the getdate()/localtime() vocabulary
gap, non-PHP files inside shared/src shipping, and the
backreference-material namespace rewrite. Residuals ledgered. Suite
1495 → 1513 tests, 42899 → 43122 assertions, 2 skipped unchanged.

### Fixed (shared — M3 Task 3.1, review round t31-r11)

Fix round over round-11's eight counted findings (two correctness with
exit-0 repros, three redaction/hygiene, two tool-output, one doc
drift), fixed as t31-r11-1..8 per the driver's adjudication on the
relative-use finding; then a two-lens verifier pass over the round diff
(independent correctness + security agents, every raised finding
adversarially re-derived by an independent verifier) raised six
findings — three distinct defects after the cross-lens duplicates
(both lenses independently found the relative-member splice and the
interrupted-relative ride), ALL CONFIRMED with reproduced evidence
(two exit-0 ships through the real builder, both produced by the
round's own new code path), one refuted, all fixed in-round as
t31-r11-9..11. Suite 1572 → 1581 tests, 44418 → 44539 assertions,
2 skipped unchanged.

- **The rewriter owns the namespace-relative USE spelling (t31-r11-1 +
  the r11-9/r11-10 verifier follow-ups)**: a relative use statement
  (`use namespace\Foo;`) is a parse error on every runtime and once
  rode every rewrite pattern, the postcondition's rewrite-ownership
  carve-out, and the sweep — the zip shipped the parse-error line at
  exit 0 (the round's planted finding, reproduced through the real
  builder). The rewriter owns the spelling now: the operator resolves
  against the declaration in effect exactly as PHP does, the family
  rewrite applies to the RESOLVED name, and the fully-qualified
  rewritten import is spliced over the relative bytes — in BOTH
  lexings (the fused token and the interrupted keyword, whose trivia
  once dropped it from the step's keying entirely) and in every
  standalone use form (plain, aliased, `use function`, `use const`).
  Relatives the rewrite cannot carry refuse loudly: no declaration in
  effect, an escaping resolution (which would silently re-resolve
  against the REWRITTEN declaration), a sibling resolution, the
  group-PREFIX shape, and any relative inside a group BODY (the
  fully-qualified member the splice would emit is an illegal spelling
  — the verifier's exit-0 ship, refused now). The detector owns the
  spelling in use positions (a survivor is a rewriter miss); CODE
  positions keep the r8-2 adaptation carve-out, where the premise
  actually holds.
- **An invisible /proc entry is no longer a death verdict
  (t31-r11-2)**: under hidepid=2 another user's live build is
  invisible in /proc, and the sweep's liveness shortcut read that as
  DEAD — the startup reclaim would have deleted a live sibling
  build's in-flight stage tree, the exact deletion the sweep's own
  docblock forbids. Invisibility falls through to the signal-0 probe
  (EPERM = alive, ESRCH = the only invisible-and-dead verdict); the
  /proc entry probe is injectable so the regression pins the hidepid
  view without a second user on the host.
- **The URL port screen bans the whole glued-bracket class
  (t31-r11-3 + t31-r11-11)**: the colon search starts after the last
  `]`, so anything glued to the closing bracket dodged the digit
  check while parse_url() misread it identically (host `[:`, port 1 —
  the verifier swept 159 visible-ASCII spellings), and the rebuilt and
  redacted authorities diverged from the raw URL. After the last `]`
  comes `:` or the end of the authority now — the allow form,
  abort-refusing — with every legal bracket authority shape green.
- **The dotted-slug test no longer pollutes dist/ (t31-r11-4)**: its
  end-to-end build ran against the real dist/ and its zip name matched
  neither tearDown glob — every suite run left a permanent zip +
  sidecar whose checksum the restored manifest records nowhere (the
  round found exactly that pair leaked). The build rides the
  artifact-state machinery (zip, sidecar, manifest snapshotted and
  restored byte-for-byte on every exit path) and the leaked pair is
  deleted.
- **The serialization channel rides the redaction contract
  (t31-r11-5)**: the r1 contract enumerated the string cast, the
  redacted URL, and the masked header lines — but print_r()/var_dump()
  dumped the raw property tree (Authorization values, token-bearing
  queries, bodies, both token positions, the PKCE verifier, both
  device-flow codes; reproduced). Every secret-carrying VO defines
  `__debugInfo()` mirroring the masked vocabulary: HeaderMap is the
  one header-render owner (the line form, the new masked map form, and
  its own dump share one decision), tokens/verifiers/codes mask
  through SecretMask, the HTTP VOs carry the redacted URL and an
  omitted body, and the nested grant renders through its token set's
  own masked dump. var_export()/serialize() bypass `__debugInfo()` by
  engine design — export/persistence channels, not debug rendering
  (ledgered).
- **The slug→identifier core folds ASCII-only (t31-r11-6)**:
  strtolower/ucfirst/strtoupper consult LC_CTYPE, and under a Turkish
  locale the dotted-I rule made `zai` derive `ZAİ_VERSION`/
  `İnkOauth`-shaped identifiers — derived vocabulary every plugin file
  spells bare, so it must be identical in every process. The core
  folds through explicit byte tables (the bin-side twins of the shared
  tree's AsciiFold owner), with the tr_TR setlocale regression
  attempting the locale and restoring it.
- **CONVENTIONS states the hyphen-or-dot separator rule the check
  enforces (t31-r11-7)**: the check (through the one slug→identifier
  core) splits slugs on `/[-.]/` and the build derives legal labels
  from dotted slugs — the document now says so (both the
  namespace-derivation sentence and the constant rule's mapping).
- **Octal escapes past \377 unescape deprecation-free (t31-r11-8)**:
  chr() deprecates above 255 on the 8.5 runtime, and the string-lens
  unescaper ran mid-gate; the value is masked to the low byte
  explicitly — the same wrap the engine itself applies — so the
  semantics are byte-identical and the notice is gone.

### Fixed (shared — M3 Task 3.1, review round t31-r10)

Fix round over round-10's eight counted findings (two correctness with
end-to-end exit-0 repros, three smaller, three cleanup), fixed as
t31-r10-1..8; then a two-lens verifier pass over the round diff
(independent correctness + security agents, every raised finding
adversarially re-derived by a third agent before fixing) raised ten
findings — five distinct defects after the cross-lens duplicates —
ALL CONFIRMED with reproduced evidence (one an exit-0 ship through
the real builder), zero refuted, all fixed in-round as t31-r10-9..13.
Suite 1569 → 1572 tests, 44283 → 44418 assertions, 2 skipped
unchanged.

- **The trait-adaptation block no longer launders family references
  (t31-r10-1)**: the walk read `use SomeTrait { … }` (brace glued
  DIRECTLY to the clause, no separator) as a GROUP-USE prefix and
  composed every member name against the TRAIT name — a
  fully-qualified family reference inside the braces produced ZERO
  carriers, invisible to the detector, the build postcondition, and
  the sweep (reproduced end-to-end: an exit-0 ship of dangling
  source-namespace bytes; the r8-noted misparse debt upgraded to a
  demonstrated ships-broken channel). The grammar's two
  brace-after-clause shapes are distinguished now: `Prefix\{` is the
  group prefix (unchanged), the glued brace opens an ADAPTATION block
  — the clause and members report as un-composed code positions and
  the `as` skip never arms inside; legal adaptations and group uses
  stay green (byte-identical at HEAD).
- **b/B-prefixed literals no longer blind the value lens (t31-r10-2)**:
  the token text of `b"…"`/`B'…'` includes the prefix byte, so the
  lens read the PREFIX as the quote and the unescape shifted by one —
  a b-prefixed family class-string (`b"\x44eicod\\WpConnectors…"`)
  yielded zero findings while its unprefixed twin refused. The prefix
  is value-free; both enclosures normalize identically now.
- **lint-php's file scope carries only the require (t31-r10-3)**: the
  t31-r9-4 display_errors class, already fixed in build.php,
  inspect-artifact.php, check-conventions.php, and scan-secrets.php,
  was left standing in lint-php.php — file-scope diagnostics AND the
  whole walk AND the exit() ran in every process that required the
  file. Same guard shape as the siblings; the r9-4 pin covers it.
- **The stage tree is PID-named (t31-r10-4)**: `.stage-<slug>` was
  shared between concurrent same-plugin builds — run B's
  startup/finally rrmdir deleted run A's in-flight tree and A refused
  on a spurious "cannot add … to" (the reopen the t31-r5-11 ledger
  entry itself names). `.stage-<slug>-<pid>` is unique per run; the
  finally releases exactly the run's own tree; the startup sweep
  reclaims only DEAD-pid orphans of the same plugin (live runs never
  touched; /proc liveness, signal-0 posix fallback, never-delete when
  undeterminable).
- **Both headers() docblocks state the all-digit int-key caveat
  (t31-r10-5)**: execution falsified the `array<string, string>`
  annotation (an all-digit RFC 7230 token name is returned under its
  PHP-canonical INTEGER key) while the HeaderMap owner's docblock
  already stated it. Both VO docblocks carry the caveat in
  byte-identical wording; the digit-name pin extends to the response
  VO.
- **The provider-name pattern derives from the provider set
  (t31-r10-6)**: the hand-maintained PROVIDER_NAME_PATTERN was a
  checklist-in-code that lagged the provider set, and more connectors
  are scheduled (M4 codex/openai, M5 grok/xai, M6 claude/anthropic).
  PROVIDER_SET (per SPEC §1's connector table) drives a derived,
  preg_quoted, word-bounded pattern, a SPEC-sync pin fails loudly both
  directions, and the derived vocabulary is a superset of the former
  (whole IDs `zai_anthropic`/`claude_pro` join as words).
- **The duplicate fence probes the one folded index (t31-r10-7)**:
  `$seen_lowercase` was a redundant parallel of `$by_lowercase`'s
  keys — two copies of one fact, one silent-weakening edit away from a
  dead fence. The fence probes the index itself; a canonical
  digit-string probe coerces to the same int slot the store lands in,
  so the all-digit fence is the same fence by construction.
- **One slug→identifier core (t31-r10-8)**: the version-constant stem
  rode a second hand-spelled derivation beside
  `wp_connectors_namespace_suffix_from_slug()` — twins synchronized
  by hand twice already (r5-8, r5-12). Both spellings (namespace
  suffix, constant stem) derive from
  `wp_connectors_identifier_from_slug()` over one segmenter —
  separator vocabulary, acronym casing, and digit-initial underscore
  once — with byte parity against each former hand spelling pinned at
  the cutover.
- **Verifier: three more zero-carrier spellings die (t31-r10-9)**: a
  FULLY-QUALIFIED group member composed against a non-family prefix
  (`use OtherVendor\{ \Deicod\… }` — reproduced as an exit-0 ship
  through the real builder), a QUALIFIED name after `as` (eaten by the
  alias skip though an alias is a bare identifier), and an EMPTY group
  body (`use Deicod\WpConnectors\{};` — the prefix never reports and
  no member exists). An absolute run never composes; the alias skip
  eats only bare identifiers; a group statement reporting no member
  reports its prefix. All pre-existing at the pre-round base —
  completeness debt of the same machinery, not regressions.
- **Verifier: the sweep never deletes through a symlink
  (t31-r10-10)**: is_dir() follows links, so a dist entry symlinked as
  `.stage-<slug>-<dead-pid>` passed the sweep's gate and rrmdir()
  emptied the TARGET tree through the link (both lenses confirmed
  independently). Links are never this code's product: stage-shaped
  links stand untouched, and a link at the run's OWN stage name
  refuses the build loudly.
- **Verifier: the multi-trait classification, stated honestly
  (t31-r10-11)**: the r10-1 docblock claimed every adaptation clause
  reports as 'code' — in `use A, B {…}` the pre-brace clauses report
  as un-composed 'use' import positions (the flag arms at the brace).
  The classification was the code's intent; the contract docblock and
  a battery row (a multi-trait CLAUSE naming the family refuses via
  ownership) now match it.
- **Verifier: the SPEC-sync pin counts every table row (t31-r10-12)**:
  a connector row in a deviant shape (unquoted or off-vocabulary ID,
  inserted column) was INVISIBLE to the pin's parse — the fail-open
  direction the pin exists to kill. Every digit-first table row must
  parse into a provider ID; a deviant row fails loudly.
- **Verifier: the sweep's crashed-run charter covers the pid-named
  temps (t31-r10-13)**: the same crash that orphans a stage tree also
  leaves `.<zip>.tmp-<pid>` (+ `.sha256`, + libzip's in-window
  `.part`) forever — reproduced with a planted dead-pid state and a
  real SIGKILL. Dead-pid temp files are unlinked under the same
  liveness gate; pid-less `.checksums-*` staging temps stay exempt
  (unattributable — reclaiming one could race a live run).

### Fixed (shared — M3 Task 3.1, review round t31-r9)

Fix round over round-9's nine counted findings — the headliner: the
r8-5 ALM ban encoded the WRONG BYTES (U+065C instead of U+061C), so
the real reorder-spoof mark passed while a legitimate Arabic vowel was
falsely refused — plus one one-verdict drift on the publish path, one
3.2-envelope gap, and six smaller items, fixed as t31-r9-1..9; then a
two-lens verifier pass over the round diff (the correctness lens
clean, the security lens's one finding adversarially CONFIRMED with
end-to-end repros) falsified the round's own fix docs and fixed
in-round as t31-r9-10. The round's noted item (the clock port's
"fresh instant per call" wording vs the deterministic clock's
same-instance reading) fixed as the trivial one-line shape.

- **The ALM ban encodes U+061C (t31-r9-1)**: the r8-5 arm banned
  \xD9\x9C — the UTF-8 encoding of U+065C ARABIC VOWEL SIGN DOT BELOW,
  a VISIBLE combining vowel sign (category Mn, bidi NSM: it decorates
  a letter, reorders nothing) — while the documented mark U+061C
  (category Cf, bidi AL: the zero-width format control in the
  LRM/RLM family) encodes to \xD8\x9C and PASSED: the exact
  reorder-spoof channel r8-5 claimed closed, verbatim through
  rendered_lines(), while legitimate Arabic content was refused (the
  swap verified against the Unicode character database). The arm
  carries the correct bytes; the r8-5 decision restated honestly
  (ban the format control; U+065C returns to allowed obs-text); both
  test spellings the round had pinned wrong corrected, and the
  byte-swap pinned on both surfaces the one vocabulary owns — the
  vowel sign constructs and renders VERBATIM, the real mark refuses.
- **The build's self-containment gate scans the composed artifact
  tree (t31-r9-2)**: the gate judged the plugin DIRECTORY alone, so
  an escaping include appended to a shared source built and
  PUBLISHED at exit 0 while the inspector — which scans the
  extracted zip, embedded src/Shared included — refused the same
  artifact: one-verdict doctrine broken on the publish path
  (reproduced; distinct from the r8-noted WP-reach curation item).
  The SAME gate now runs over the STAGED tree the zip will pack —
  plugin files plus the embedded subtree — at the staging path (the
  r5-S doctrine: every byte verified at its temp path), only when an
  embed composed bytes the pre-gate had not already judged.
- **Token material is VSCHAR-screened at construction
  (t31-r9-3)**: non-UTF-8 token bytes constructed fine while
  json_encode(to_array()) returned FALSE — and to_array() is the
  documented Task-3.2 envelope payload (the saves-never-loads shape,
  one layer out). The screen is the OAuth BCP's own grammar, not an
  invented encoding probe: RFC 6749 fixes the token positions as
  1*VSCHAR (%x20-%x7E, printable US-ASCII). Both positions checked at
  the constructor (from_array() rides it, so corrupted payloads
  refuse at load); the grammar's own edges stay legal (an interior
  space is %x20) and a legal set's payload always json_encodes.
- **display_errors diagnostics live inside the CLI guard
  (t31-r9-4)**: bin/build.php and bin/inspect-artifact.php carried
  error_reporting(E_ALL)+ini_set('display_errors','1') at FILE scope
  — and both are REQUIRED into the PHPUnit process, so a php-cli
  host with display_errors off had it flipped process-wide just by
  running the tests (reproduced: the require printed 1). The
  glm17-16 class, already fixed in check-conventions.php but
  unguarded here; both files wear that shape now, pinned through a
  child process.
- **Identity never survives the storage boundary (t31-r9-5)**:
  InMemoryTokenStorage::load() returned the caller's very instance
  and the port tests pinned assertSame on it — semantics no
  DECRYPTING (real) adapter can honor. The fake round-trips grants
  through serialize/unserialize (the same whole-graph encode/decode
  a real adapter performs; a Revoked tombstone reconstructs too),
  the port's load() contract states the reconstruction rule, and the
  port tests pin by VALUE (accessors + the token set's own storage
  serialization as the compare) with assertNotSame pins in both
  directions of the boundary.
- **Every legal opener carries the provenance banner (t31-r9-6)**:
  the banner-insertion pattern was case- and BOM-sensitive with no
  zero-match guard — '<?PHP' and a BOM-prefixed source matched
  nothing and shipped BANNER-LESS silently (reproduced), and a
  tag-less source was only caught incidentally by the postcondition.
  Doctrine BANNER-IN-PLACE: the pattern matches an optional BOM then
  the open tag case-insensitively, PRESERVES the opener bytes
  verbatim, and a zero-match refuses loudly at the banner seam,
  naming the file.
- **The unused-import gate scans shared/src (t31-r9-7)**: the scan
  covered only connectors/, so a dead import in shared/src passed
  every gate and shipped into EVERY embedding plugin. Same scanner
  over the shared tree (real tree verified clean), pinned through
  the CLI itself against a scratch repo.
- **The short-write pin leaks nothing (t31-r9-8)**: the stream-URL
  scratch was one segment deep, so the seam's @mkdir(dirname($to))
  created a literal scheme-named directory in the repo root (two
  leaked dirs verified present, both removed). The scratch URL is
  two segments deep and the wrapper owns its namespace's mkdir —
  pinned: no literal dir after the refused write.
- **The rewriter's family patterns derive from the namespace helper
  (t31-r9-9)**: four independent hand-spellings of the family
  namespace in rewriteSharedNamespace() while
  wp_connectors_shared_source_namespace() claims single ownership —
  a rename needed synchronized two-file edits. Every spelling
  derives from the helper's segments via preg_quote now (byte parity
  with all eight former literals verified IDENTICAL); a family rename
  is a one-edit change in the helper.
- **Verifier follow-up (t31-r9-10, adversarially confirmed)**: the
  r9-1 docblock's "only format controls are banned" claim was FALSE
  as coverage — the invisible bidi-ACTIVE Cf siblings of the banned
  marks passed on both surfaces: U+070F (bidi AL: invisible
  STRONG-RTL, the banned ALM/RLM mechanism, reproduced rendering
  verbatim), U+110BD/U+110CD/U+13430-U+1343F (bidi L: the LRM
  mechanism), U+0600-U+0605/U+06DD/U+0890/U+0891/U+08E2 (invisible
  AN), plus the invisible-neutral homograph class ZWSP/ZWNJ/ZWJ
  (glyph-joining), U+FEFF, U+00AD. All banned at the one owner; the
  docblock states the honest doctrine (a CURATED BYTE LIST of
  invisible direction/joining material, never a category derivation
  — the residual invisible set ledgered as curation decisions);
  completeness verified exhaustively against the Unicode database
  (zero bidi-ACTIVE Cf pass), with the visible-content counter-pin
  (Arabic letters and vowel signs render verbatim).

Suite 1560 → 1569 tests, 44212 → 44283 assertions, 2 skipped
unchanged; green in default and random order throughout.

### Fixed (shared — M3 Task 3.1, review round t31-r8)

Fix round over round-8's seven counted findings — three state-machine/
predicate gaps in the r7 token detector (each with an exit-0 repro), two
HeaderMap surface defects, one directory-casing axis, one cleanup — fixed
as t31-r8-1..7, then a two-lens verifier pass over the round diff
(both lenses adversarial-re-derived before fixing) raised four more
findings, all CONFIRMED with end-to-end repros and fixed in-round as
t31-r8-8..11 (two of them gaps in this round's own fixes, one a
state-corruption vector, one a diagnostics drift the refactor
introduced):

- **The use-statement boundary is a SET (t31-r8-1)**: r7-7 taught the
  walk's use-tracking state to die at ';' — but a close tag is a
  statement terminator exactly like it (the engine implies the
  semicolon at the tag), and `use Foo\Bar as ?>` left the alias skip
  — and an unclosed group's prefix — armed across the mode boundary,
  silently eating the next name run in the re-entered code: the family
  reference after the tag shipped at exit 0 with both gates green.
  ';' plus every PHP-mode tag token resets the state now; pinned by a
  boundary matrix over both laundering halves and a clean
  close-tag-tailing source.
- **The relative operator resolves before the family predicates
  (t31-r8-2)**: T_NAME_RELATIVE carried its literal `namespace\`
  prefix through the walk, so `namespace\WpConnectors\Shared\Clock`
  in a file declaring `namespace Deicod;` — family once resolved, the
  resolution PHP itself performs — never matched the vendor predicate
  and shipped un-rewritten (class-not-found at runtime, both gates
  green; the escaping declaration sat outside the prefix and escaped
  every gate too). The detector resolves relatives against the file's
  in-effect declared namespace, with the ADAPTATION carve-out pinned
  as the legal other half: a relative under a rewrite-owned tree
  (source root, or the target root on rewritten bytes) adapts through
  the rewrite in any position and reports nothing; only a
  family-resolving relative under a base the rewrite does not own
  reports — under its own 'relative' kind, never 'use', whose
  rewritable-position reading would wave the un-rewritable spelling
  through the sweep while the build refuses it.
- **The text lens judges the full sibling/family vocabulary
  (t31-r8-3, tightened by t31-r8-9)**: the lens tried only the source
  and target spellings, so a docblock naming a SIBLING under the
  vendor prefix laundered exactly where the same sibling in a code or
  string position refuses — and the dev sweep rides the same
  detector, so nothing caught it anywhere. The lens applies the full
  family predicate now (the bare vendor prefix and every sibling
  continuation, via wp_connectors_family_sibling_pattern() on the
  generator's totality dimensions); the exclusion owns exactly the
  dedicated patterns' FULL below-vendor tails ('Shared';
  '<Suffix>\Shared'), because the first cut excluded the suffix
  segment alone and waved target-SEGMENT siblings
  (`…\<Suffix>\OAuth`) through the build postcondition while the
  sweep refused the same file — the r7-8 verdict-drift class, one
  segment inside the target tree, closed both directions.
- **The embedded tree's directory casing agrees with the declared
  namespace (t31-r8-4, closed to every declaration by t31-r8-8)**: the
  r5-3 extension-casing doctrine never fenced the DIRECTORIES —
  shared/src/tools/Helper.php declaring `…\Tools;` collected, staged
  at src/Shared/tools/, passed inspection, and published while the
  shipped autoloader maps `…\Tools\Helper` onto
  src/Shared/Tools/Helper.php verbatim: class_exists through the real
  shipped loader FALSE with every gate green (end-to-end reproduced).
  The ONE collector the build's embed and the sweep both ride now
  refuses unless every declaration sits under the shared root with
  below-root segments equal to the staged path's directories
  CASE-EXACTLY, depth included — a missing declaration, an
  outside-root declaration, and a SECOND declaration block (the embed
  stages one path per file, so a second block's classes stage
  nowhere) all refuse loudly. Supersession pinned: unreadable and
  declaration-less shared sources now refuse one seam earlier (the
  collector's fence at the config seam), with the read seams kept as
  defense in depth.
- **The bidi screen bans the direction marks (t31-r8-5)**: LRM
  (U+200E), RLM (U+200F), and ALM (U+061C) — the same zero-width
  reorder/mirror material as the r2-6 class, spelled below its byte
  range (and, for ALM, outside the U+2xxx run) — joined the one
  control vocabulary at its single owner; Url rides the same constant,
  so the extension lands on both surfaces with no second pattern to
  drift.
- **rendered_lines() is always valid UTF-8 (t31-r8-6)**: a Latin-1
  (obs-text) header value is legal at construction (r1-19) but its
  bytes are invalid UTF-8 — json_encode of the rendered line returned
  false, the log line dropped rather than degraded, the exact r4-13
  failure mode killed on the URL surface by rejecting the input. The
  render seam owes the same OUTCOME without rejecting the value:
  well-formed sequences render verbatim (the pinned obs-text
  rendering), invalid bytes render percent-encoded — encoded, never
  destroyed (SecretMask::utf8_for_safe_render(), the render
  vocabulary's one owner beside the grammar it already owns).
- **One tokenization feeds both lenses (t31-r8-7, line semantics
  unified by t31-r8-11)**: the detector tokenized every file twice —
  the name walk and the text lens each re-tokenizing the same bytes,
  the dominant cost paid twice. The walk takes the token stream
  directly (wp_connectors_name_references_from_tokens()); the line
  derivation rides the engine's token lines with the text lens
  counting the same \R class at its push seam — the refactor's first
  cut left the text lens on a "\n"-only count, drifting the two
  lenses' lines apart on CR-only files within one detector run.
- **Verifier follow-ups (t31-r8-8/9/10/11, each adversarially
  re-derived, each repro reproduced before fixing)**: the PSR-4 fence
  judged only a file's FIRST namespace block, so a legal two-block
  source shipped with the second block's class unloadable
  (interface_exists through the shipped autoloader false, build and
  inspect green — found independently by both lenses); the sibling
  exclusion over-covered the target segment (the verdict-drift row
  above); a parse-error fully-qualified namespace spelling
  (`namespace \Junk;`) was classified as a declaration and corrupted
  the file's in-effect namespace, laundering a family-resolving
  relative past both gates where its control refused — only the two
  legal declaration shapes open one now, and the invalid spellings'
  names fall to code positions where family spellings refuse
  everywhere; and the CR-only line drift (above).

Suite 1554 → 1560 tests, 44107 → 44212 assertions, 2 skipped
unchanged; green in default and random order throughout.

### Fixed (shared — M3 Task 3.1, review round t31-r7)

Fix round over round-7's five counted findings — all in the
build/rewrite seam, and all one more spelling of the class the seam had
carried since t31-r4: the K1 survivor surface was still "one spelling
away" for the third time. THE TERMINAL FIX replaces text-level
namespace detection with TOKEN-level detection; one more commit fixes
the unchecked generated-member write; a two-lens verifier pass over the
round diff raised four findings (all adversarially confirmed with
end-to-end repros — two of them genuine regressions the round itself
introduced), fixed as t31-r7-6/7/8:

- **Token-based namespace reference detection — one detector, two
  consumers** (t31-r7-K, findings 1/2/4/5): a comment between a use
  name's segments, a sibling `Deicod\WpConnectors\*` import, and a
  double-backslash class-string literal each shipped past both gates at
  exit 0 (all reproduced red under the pre-fix code before the
  commit). `wp_connectors_shared_family_references()`
  (bin/lib/plugin-tools.php — loadable by the build, the sweep, and
  the conventions tooling alike) reassembles name runs across comments
  and whitespace (a comment can only INTERRUPT a run, never carry
  one), composes group-use members with their prefix, judges string
  literals by their unescaped RUNTIME VALUE (quoted, heredoc, nowdoc —
  the static half of the ledgered K1 split-composed boundary, closed),
  and judges comment/docblock/inline-HTML text with the former
  survivor pattern, now GENERATED from any family spelling
  (byte-identical for the source side). The build's postcondition
  refuses every family reference that is not the rewritten target
  prefix; the sweep's namespace gate allows own-namespace
  declarations and imports only and verifies OWNERSHIP by rewriting
  each swept file through the build's own postcondition — the sweep
  and the build give one verdict by construction, and the old
  every-use-statement whitelist is gone. The legal tree's import
  vocabulary is enumerated and pinned (DateTimeImmutable, DateTimeZone,
  InvalidArgumentException, RuntimeException, Throwable — a new import
  joins only by being pinned). Doctrine flips ledgered with the
  spelling history: the SharedStorage spelling, the `{Other\Shared}`
  group member, and the bare vendor-prefix import move from
  untouched-legal pins to sibling refusals.
- **The generated-member write is checked and the staged bytes
  verified** (t31-r7-3): `writeNormalized()` ignored
  `file_put_contents()`'s return, so a short write staged a truncated
  PHP file that zip close() happily packed and published at exit 0 —
  the t31-r5-S "verified whole at its staging path" claim was false
  for generated members. The write layer's word is checked (false or
  short → loud refusal naming file + expected bytes) and what landed
  is re-read and length-compared before the archive opens; pinned at
  the reflection seam with a REAL forced short write (a stream
  wrapper driving PHP's own "Only X of Y bytes written" write loop).
- **Verifier follow-ups** (each adversarially confirmed, each pin
  proven red under the pre-fix code): the run assembly dropped the
  separator byte on trivia-after-separator joins, making the
  whitespace-interrupted spellings the r4-era regex REFUSED into
  exit-0 ships under the token walk — a genuine regression, raised
  independently by both lenses (t31-r7-6); the alias skip survived its
  use statement's end and silently ate the next name run anywhere in
  the file, laundering a family reference with nothing family in the
  hostile statement itself (t31-r7-7); the postcondition waved
  target-rooted CODE/STRING references through — a hand-spelled
  dangling reference to the building plugin's own prefix shipped at
  exit 0 while the sweep refused the same file, verdict drift — and
  the text lens was blind to target spellings entirely; the allow-list
  narrows to the two positions the rewrite produces, measured over
  the real tree × every suffix shape (t31-r7-8).

Suite 1552 → 1554 tests, 43763 → 44107 assertions, 2 skipped
unchanged; green in default and random order throughout.

### Fixed (shared — M3 Task 3.1, review round t31-r6)

Fix round over round-6's three counted findings — one theme: the
case-insensitivity doctrine this branch established (t31-r5-16:
comparisons that gate what ships vs what collides fold case, because
zip extraction on Windows/macOS targets folds case) was applied
inconsistently in the build tooling. Three commits t31-r6-1..3, then
a proportionate two-lens verifier pass (correctness + security) that
held all three fix-claims (each pin proven red under its pre-fix
predicate by mutation) and raised ten findings, four fixed in-round
as t31-r6-4..7, the rest adjudicated and ledgered with repros and
fix shapes:

- **The repo-LICENSE injection rides the collision fence's case
  doctrine** (t31-r6-1): the exact-case in_array let a plugin carrying
  'license'/'License' at its root ship BOTH entries — its own file AND
  the injected repo copy, inspection green — and on a case-insensitive
  extraction target the plugin's copy extracted second (sort order)
  and silently overwrote the repo license. One doctrine, two
  territories, one comparison (strcasecmp over the collected entries):
  generated embed destinations refuse a plugin-owned collision
  (t31-r5-16), while the injected convenience DEFERS — the plugin's
  license wins in any casing and the repo copy is never injected
  beside it. The pre-existing exact-case skip is pinned the same way,
  and the inspector agrees on the single-license artifact.
- **The near-source fence owns both edges of every byte class**
  (t31-r6-2, completed by the verifier follow-up t31-r6-4): r5-14's
  tail strip was " \t." — 'ClockMath.php\n' (a trailing newline IN THE
  FILENAME) was silently neither collected nor refused, the exact
  silently-invisible-ship class r5-14 claims closed; r6-2's first
  widening still missed the C0 controls and DEL
  ('ClockMath.php\x01'), and the LEADING side was unfenced entirely —
  ' ClockMath.php', '.ClockMath.php', and a 'Clock /' directory
  segment COLLECTED and SHIPPED while the shipped autoloader maps
  class names onto label-shaped paths, a dead entry with build and
  inspect green (class_exists false through the real shipped
  autoloader). The edge-junk byte class is owned ONCE
  (wp_connectors_path_edge_junk(): every byte 0x00-0x20, DEL, and the
  dot): the tail strip rides it, and every segment of a collected
  source's path must survive its own edge strip. The ledgered
  merely-different boundary ('ClockMath.phpé', 'Notes.md') stays
  silent.
- **The development-entry vocabulary folds case at ONE comparison
  owner — trailing junk included** (t31-r6-3, extended by the
  verifier follow-up t31-r6-5): the ONE vocabulary (t31-r5-10) was
  compared byte-exactly by BOTH gates, so 'Tests/Bootstrap.php',
  'Build.json', and 'VENDOR' shipped in release zips AND passed
  inspection — both gates agreeing on the wrong verdict. The
  comparison owner (wp_connectors_is_development_entry()) now folds
  case over the trailing-junk-stripped segment (Windows path
  normalization strips trailing dots and spaces per component, so
  'vendor '/'.git ' fold onto the real dev entries at extraction —
  and still carry dev content on hosts that preserve the odd
  spelling); the leading side is deliberately not stripped, since the
  vocabulary's own members may begin with a dot.
- **Verifier follow-ups** (all adversarially re-driven before
  fixing): the collision fences' "unconstructible" claims scoped to
  the both-FILES overwrite they close, the file-vs-directory fold
  residual named at the fence (t31-r6-6); the fixture-copy loops
  create the plugin root explicitly — they relied on readdir order
  yielding a subdirectory before the first root file, demonstrated
  broken on a tmpfs clone (t31-r6-7).

Verifier pass: both lenses over the whole round diff, every claim
empirically re-driven (scratch fixtures through the real build and
inspector, the shipped autoloader probed for the dead-ship claims,
each committed pin proven red under its pre-fix predicate in a cloned
tree). All three fix-claims HELD; ten findings raised — the two that
falsified this round's own fixes' claims (the C0/DEL tails and the
leading edge; the dev-entry fold's sibling byte-class) fixed as
t31-r6-4/5 with two hygiene items, the remaining seven adjudicated
and ledgered with repros and fix shapes (headlined by the secret
scanner pruning the very subtree the embed ships by design, and the
case-variant 'Plugin Name:'/build.json spellings). Suite 1549 → 1552
tests, 43676 → 43763 assertions, 2 skipped unchanged; green in
default and random order throughout.

### Fixed (shared — M3 Task 3.1, review round t31-r5)

Fix round over round-5's counted findings, executed per the round's own
mandate: the "silent library-less / 0-byte / broken zip at exit 0"
class had survived four targeted rounds, so this round killed it with
a STRUCTURAL restructure plus a PROPERTY BATTERY instead of more
per-spelling pins — 18 commits t31-r5-S/B/1..9, then a two-lens
verifier pass (independent correctness + security agents, every raised
finding adversarially re-driven by a third before fixing) whose
all-11 fix-claims HELD and whose 7 confirmed findings are fixed as
t31-r5-10..16:

- **The artifact set is staged whole at temp paths and landed by
  checked rename, LAST** (t31-r5-S, the structural restructure): the
  zip is built, closed, and checksummed at a PID-unique staging path;
  the sidecar writes beside it; the manifest merges under a
  tempnam()-unique stage (closing the two-process write interleave);
  and the previous good release is replaced only by checked renames —
  descriptors first, the archive LAST — behind a landing pre-flight
  that refuses any non-file destination across all three targets
  before the first rename. Every constructible failure leaves the
  prior artifact set byte-untouched BY CONSTRUCTION, deleting the
  compensating apparatus (the $zipOpened/$zipOverwritten flags, the
  publication catch, removeManifestEntry, writeManifestAtomically) and
  its bug history (t31-r3-16 → t31-r4-3 → this round's catch-path
  findings).
- **The build-seam property battery** (t31-r5-B, the round's
  centerpiece, the SseAggregatorMutationPropertyTest idiom): for every
  ENUMERATED adversarial build state — unreadable/whitespace-only
  sources, the empty shared tree, malformed/escaped-duplicate/
  wrongly-typed build.json, the plugin-owned src/Shared collision (and
  its case variant), the .PHP-cased and near-source spellings, the
  shared-tree symlink, the excluded-path symlink (CLEAN), blocked
  sidecar/manifest/zip/staging paths, the unreadable manifest, the
  traversal-spelled Version header, and the unmutated control — a run
  is exactly CLEAN (success AND the artifact complete and sound:
  entries present and non-empty, every PHP entry parsing after
  extraction, sidecar/manifest consistent, the embedded tree exactly
  the shared PHP-source set, the inspector accepting) or LOUD
  (refusal AND the previous good artifact set byte-untouched, nothing
  landed, no residue) — never the silent third. Authored red-first:
  8 states failed against the pre-fix tree, each fixed by its finding.
- **The embed seam reads loudly at both collection points**
  (t31-r5-2, the sanctioned reopen of the ledgered t31-r3 note — its
  own reopen condition met): a failed read laundered through (string)
  shipped an unreadable shared source as a 0-byte library file and an
  unreadable plugin file as a 0-byte zip entry at exit 0, and a
  whitespace-only source shipped the same with no read failure; all
  three refuse naming the file. A plugin-owned src/Shared path
  colliding with an embed destination REFUSES instead of silently
  replacing the author's file (t31-r5-1), the fence folding case
  (t31-r5-16); a source-less shared tree refuses — a library-less zip
  is never silently built (t31-r5-4).
- **The shared-source collector accepts only the canonical lowercase
  '.php' casing** (t31-r5-3, a recorded doctrine change superseding
  the r3-9/r4-9 collect-any-case posture for shared/src): a .PHP-cased
  source shipped rewritten while the shipped autoloader probes
  lowercase '.php' — an unreachable class with build AND inspect green
  (verified through the real shipped autoloader). Refusing is
  strictly stronger than both earlier postures; the case-insensitive
  JUDGMENT owner is unchanged for plugin-tree gates, and near-source
  spellings (trailing space/dot hiding the extension) refuse too
  (t31-r5-14).
- **The inspector's forbidden-entry vocabulary is scoped to
  plugin-owned paths** (t31-r5-5, an adjudicated doctrine alignment):
  the generated <slug>/src/Shared/ subtree is exempt from the segment
  check (shared/src has no exclusion concepts — the r3-4 doctrine the
  embed ships by) so build and inspect give ONE verdict, while
  traversal, syntax, secret, and self-containment checks still judge
  every entry. The builder's and the inspector's development-entry
  lists had drifted (the dotless 'phpunit.cache' shipped through
  builds the inspector rejected — verifier-confirmed) and now ride ONE
  shared vocabulary (t31-r5-10).
- **build.json duplicate keys refuse on the DECODED key**
  (t31-r5-6): a \u-escaped duplicate is the same key once decoded, so
  the raw-text spelling count let last-wins silently mean no-embed at
  exit 0; a depth-1 object-frame scanner counts decoded keys, any two
  spellings that decode alike refusing.
- **Scoped refusals and legal labels**: the plugin collector's
  exclusion filter runs BEFORE the symlink refusal, so a
  vendor/node_modules link (a composer path repo, an npm .bin shim)
  no longer refuses a build whose zip would be byte-identical
  (t31-r5-7); the version-constant derivation underscores digit-initial
  slugs like the namespace derivation, the shipped main file parsing
  with its bare '_3CX_OAUTH_VERSION' reference (t31-r5-8), and dotted
  slugs derive legal labels for both ('my.plugin' → 'MY_PLUGIN_VERSION'
  / 'MyPlugin' — t31-r5-12); the test file's extension judgments ride
  the one owner (t31-r5-9).
- **Verifier-pass findings** (all adversarially confirmed before
  fixing): the manifest merge's read-merge-write lost update across
  concurrent builds now holds an exclusive flock across
  read-through-landing (t31-r5-11 — the tempnam had closed the write
  interleave, not the lost entry, 30/72 synchronized trials pre-fix);
  an unreadable checksums.txt refuses instead of silently landing a
  one-entry replacement that destroyed every other plugin's checksum
  (t31-r5-13); and the Version header must be a version TOKEN — its
  bytes reached the artifact filename and staging paths unchecked, a
  traversal spelling staging the archive and sidecar outside dist/
  (t31-r5-15).

Verifier pass: both lenses over the whole round diff, every claim
empirically re-driven (the battery proven non-vacuous by three
independent mutations of bin/build.php, each turning exactly its
encoded rows red; the scanner probed over 30+ hostile-but-valid JSON
shapes; the pre-round code A/B'd for the escaped-duplicate, the
excluded-path symlink, and the traversal-version shapes). All 11
fix-claims HELD (six HELD_WITH_NOTES); 7 findings raised, all 7
CONFIRMED and fixed as t31-r5-10..16; residuals adjudicated and
ledgered. Suite 1538 → 1549 tests, 43498 → 43676 assertions, 2
skipped unchanged; green in default and random order throughout.

### Fixed (shared — M3 Task 3.1, review round t31-r4)

Fix round over round-4's 13 counted findings, executed as two
structural class-kills plus eleven single-finding commits — the
per-spelling regex patching strategy was declared exhausted (the same
seams had been closed "one spelling away" twice already) and the round
kills the CLASSES instead: 15 commits t31-r4-K1/K2/3/7/8/9/10/11/12/
13/14, then a two-lens verifier pass (independent correctness +
security, every finding adversarially re-driven before fixing) whose
all-11 fix-claims HELD and whose four confirmed defects are fixed as
t31-r4-15..18:

- **The rewrite postcondition is a TOTAL scan** (t31-r4-K1, findings
  4+5): after rewriting a shared source, the build asserts the output
  contains ZERO occurrences of the source namespace
  `Deicod\WpConnectors\Shared` — case-insensitive (PHP namespaces
  are; the ledgered case-variant acceptance is closed by totality,
  refused loudly now), whitespace-tolerant between segments (a
  string/docblock spelling may break the line — the shape that
  defeated every contiguous probe and the sweep's per-line whitelist
  end-to-end at exit 0), and brace-aware (a group-use member carries
  `Shared` at a member position where the substring never appears
  contiguously). The rewriter additionally OWNS the group-use member
  spelling now (suffix inserted at the member's leading `Shared`
  segment; aliases never rewritten — though a member aliased exactly
  `Shared` refuses, fail-loud); sound by construction (the rewritten
  target `…\WpConnectors\<Suffix>\Shared` cannot contain the source
  spelling — verified empirically over the whole real tree and pinned
  per suffix shape); the architecture sweep's namespace gate went
  whole-file on the SAME pattern (token-blanked rewritable statements,
  one vocabulary, two consumers).
- **The build.json seam is a CLOSED SCHEMA** (t31-r4-K2, findings
  1+2+6): unknown keys refuse (a typo silently meant no-embed),
  `embed_shared` must be a JSON boolean (the string `"false"` was
  truthy and embedded while reading as no-embed), `namespace_suffix`
  is typed before any use (a JSON array cast to `'Array'` and PASSED
  the segment check, building under `…\Array\Shared`; an object
  fataled with an uncaught Error), and an explicit suffix must EQUAL
  the slug-derived autoloader prefix the plugin actually maps — a
  custom suffix shipped an unloadable library with every gate green.
- **Release-artifact integrity** (t31-r4-3/8/15): a failed zip
  finalization refuses the build (close()'s false return was ignored
  after OVERWRITE had already destroyed the previous good zip — the
  sidecar carried a blank checksum at exit 0); the checksum manifest
  is per-run-atomic (the CLI's pre-run wipe dropped every other
  plugin's entry on a `--slug` rebuild and left the manifest gone
  after a failing rebuild, sidecars orphaned — each run now merges
  only its own entry and lands it temp+rename); and every publication
  write is checked (the sidecar write was the one unchecked artifact
  seam — a blocked `.sha256` path shipped a sidecar-less zip with
  exit 0).
- **The collectors refuse symlinks loudly** (t31-r4-7/16): a symlinked
  directory in shared/src — and, per the verifier, a symlinked source
  in the plugin tree — loaded in development, was invisible to (or
  scanned through by) the gates, and missed every zip: an unloadable
  artifact at exit 0. Both collectors refuse the build naming link
  and target.
- **One case-insensitive php-extension owner** (t31-r4-9/18): collect
  (the shared-source vocabulary), strip (the PSR-4 type-name stem),
  and classify (the self-containment walker, the unused-import
  scanner, the inspector's syntax loop, the lint gate) all ride
  `wp_connectors_is_php_source()` — a `.PHP` file can no longer be
  collected by one gate, mis-stemmed by another, skipped by a third,
  and linted by none.
- **Gate vocabulary debts** (t31-r4-10/11): the PSR-4 type vocabulary
  covers `trait` and `readonly class` (a class+trait file passed as
  "exactly one type"; a readonly class counted as no type); the
  WP-reach stems match their `_`-suffixed twins
  (`apply_filters_ref_array`/`do_action_ref_array` — `\b` treats `_`
  as a word character) and the obviously-conditional/admin surface
  (`is_admin`, `get_bloginfo`, `is_user_logged_in`, `get_locale`)
  joins the curated list, the curation doctrine itself now ledgered.
- **URL validation closes two divergences** (t31-r4-12/13,
  t31-r4-12 ADJUDICATED FIX, ledgered): parse_url() silently truncates
  a malformed raw port (`:443x` reads as 443) while `url()` carries
  the raw text — the raw port segment must be fully digits now
  (userinfo colons are not ports; leading zeros stay legal); and the
  whole URL must be valid UTF-8 — a lone raw C1 byte (0x85/0x9B)
  passed the UTF-8-spelling-only control screen verbatim into the
  debug forms, where json_encode of the log line returned FALSE (the
  t31-r1-6 dropped-log-line failure mode).
- **Abort-as-reject at the rewrite seams** (t31-r4-14): the
  `(string)` casts on preg_replace results turned a PCRE abort's null
  into an empty file inside the zip — every seam refuses loudly now.

Verifier pass: both lenses over the whole round diff, every claim
empirically re-driven (the pre-round code A/B'd through scratch
scaffolds; a REAL PCRE abort driven through the use-rewrite on a
300k-segment subject; a real failing close() driven at the seam). All
11 fix-claims HELD (six HELD_WITH_NOTES, none falsified); four
confirmed defects fixed as t31-r4-15..18 (the unchecked sidecar
write; the plugin-tree symlink skip; build.json-as-directory and
duplicate-key silent-no-embed; the exact-case lint/inspect extension
checks letting a broken `.PHP` entry ship and pass inspection).
Residuals adjudicated and ledgered — headlined by the K1 honest
boundary: split-composed namespace spellings (runtime
concatenation/interpolation) are outside a spelling-level scan's
charter. Suite 1521 → 1538 tests, 43256 → 43498 assertions, 2 skipped
unchanged; green in default and random order throughout.

### Fixed (shared — M3 Task 3.1, review round t31-r3)

Fix round over round-3's 14 counted findings. The reviewer's own
structural read — findings 1–6 are one theme, the embed_shared
pipeline's failure modes being silent or leaky — was executed as one
coherent seam design: validate the configuration ONCE before any
filesystem mutation, wrap the staging lifecycle in try/catch/finally,
make the rewrite/whitelist/collect vocabularies agree, and keep the
ONE assertion (PHP sources only) in the test pair. 14 commits
t31-r3-1..14, then a two-lens verifier pass whose every fix-claim
HELD and whose two confirmed defects are fixed as t31-r3-15/16:

- **The embed pipeline fails loud and ships total** (t31-r3-1/4/9/15):
  build.json is resolved and validated once at a config seam BEFORE
  any filesystem mutation — readable, a JSON OBJECT (trailing comma,
  empty file, scalar, and — the verifier's catch, found by both
  lenses — a top-level ARRAY each refuse the build; the array shape
  silently skipped embed_shared and shipped a library-less zip with
  exit 0); the embed collection rides ONE shared-source collector
  (no exclusion-name segments — a source under shared/src/tools/
  loads in development and must ship, or the plugin fatals on the
  missing class; case-insensitive `.php` extension, so a
  `.PHP`-spelled source ships instead of riding past every gate) —
  and the architecture sweep's file vocabulary is that same
  collector, so what ships and what is judged cannot drift.
- **A failed build leaks nothing and destroys nothing**
  (t31-r3-6/16): the namespace suffix is validated once at the seam
  (`assertNamespaceSegment()`, kept as defense in depth inside the
  rewriter), the whole staging lifecycle — tree, copy, embed rewrite,
  zip — runs inside one try/catch/finally (the stage tree is torn
  down and a half-written archive released on every throw), and the
  artifact cleanup is scoped to what THIS run wrote: a failure
  before the archive opens leaves the previous good zip, its sidecar,
  and its manifest entry byte-for-byte intact (the verifier
  reproduced the old shape orphaning a checksum for a deleted zip),
  while a failure after open removes the corrupted zip, sidecar, and
  manifest entry together.
- **Every shared-namespace use spelling is rewritten**
  (t31-r3-2): the rewriter's use-pattern required a trailing
  separator, so `use …Shared;`, its aliased form, and every
  `use function/const` spelling survived byte-identical — the
  embedded copy imported a namespace that no longer exists
  (class-not-found fatal). One pattern covers plain, aliased,
  function, const, fully-qualified, exact, and brace-group spellings,
  and a postcondition REFUSES the build when any spelling survives
  (a comment-interrupted use line names its file); a
  Shared-prefixed foreign namespace (`SharedStorage`) stays
  untouched. The sweep's use-line whitelist states the honest
  contract it rides, and the spelling battery is pinned end to end.
- **The namespace derivation produces legal labels** (t31-r3-5): a
  digit-initial slug (`3cx-oauth`, a legal plugin slug) derived
  `3cxOauth` — not a declarable PHP label, rejected by the suffix
  validator while all three consumers (conventions checker, builder,
  dev autoloader) derived it. The ONE derivation underscores
  digit-initial suffixes (`_3cxOauth`); a 3cx-oauth plugin builds
  end to end with embed_shared, its embedded copy lint-clean.
  Documented in CONVENTIONS.md beside the enforcing check.
- **`should_refresh()` is a total predicate** (t31-r3-3): a
  year-0000-floor obtained_at plus a saturation-scale skew (both
  legal) drove expiry-minus-skew out of the representable range and
  a global `InvalidArgumentException` escaped the bool predicate —
  an unhandled type Task 3.3's coordinator would crash on. The
  corner is decided inside the predicate from the arithmetic's own
  meaning (a threshold before every representable instant is reached
  by every reading — refresh due), with the largest skew whose
  threshold stays representable keeping the exact flip point.
- **The sweep is whole-file, loud, and cheap** (t31-r3-8/13): the
  WP-reach and provider-name gates apply to the whole file through
  the shared helper (multiline spellings caught — `$saved = __` /
  `( 'save' );` evaded every per-line pass — and a PCRE abort
  refuses), mutation-tested end to end; the PSR-4 gate reads through
  the loud `fileContents()` reader; the walk and successful reads
  are cached per run (the sweep re-walked shared/src 7×/re-read each
  file ~6×).
- **The method fold is locale-independent** (t31-r3-10): the HTTP
  method's normalization rode the locale-sensitive byte upper-case
  mapping — under the Turkish dotted-I rule `'post'` would stop being
  a method in exactly the processes whose locale folds it (argued
  from the fold tables, no tr_* locale on this host).
  `AsciiFold::upper()` (the byte-table twin of `lower()`) owns it,
  pinned by the same class-closure posture as t31-r2-14.
- **`HeaderMap` carries one structure** (t31-r3-14): the folded index
  alone — `headers()` derives via `array_column` (digit-key
  canonicalization unchanged), `rendered_lines()` iterates the pairs.
- **Test hygiene** (t31-r3-7/11/12): the embed test pair states the
  ONE contract (PHP sources ship at exact paths; every `src/Shared/`
  entry is a PHP source); the PCRE-burner and rewrite-lint scratch
  lifecycles ride try/finally (no tearDown glob matched either
  shape); `copyFixturePlugin()`/`zipEntryNames()` own the two shapes
  every zip-inspecting test had copied verbatim (the master-era
  sites included).

Verifier pass: two independent lenses (correctness + security, each
finding adversarially verified) over the whole round diff — every one
of the 14 fix-claims HELD (several HELD_WITH_NOTES, none falsified;
the seam re-driven against a non-existent dist, the rewrite battery
extended with FQ/group/newline spellings and PCRE-abort attempts, the
policy pre-check driven byte-identical to the arithmetic guard at
every boundary, HeaderMap's derivation proven key-identical over
digit/leading-zero maps, the caches audited for write-after-read).
Two confirmed defects fixed as t31-r3-15/16: the build.json
array-top-level silent skip (found independently by both lenses) and
the failed build's orphaned-checksum state. Residuals ledgered. Suite
1513 → 1521 tests, 43122 → 43256 assertions, 2 skipped unchanged.

### Changed (tooling — PHP floor 8.2, user decision 2026-09-11)

The supported PHP floor is **8.2**, not 7.4 — "Pff PHP 7.4 wird nicht mal
mehr maintained. Nur weil WP sagt es läuft auf 7.4 müssen wir das nicht
auch tun. 8.2 ist die Untergrenze." This supersedes every prior 7.4-floor
decision, including glm38-1's 7.4-compat fixes and the PHPCompatibility
7.4-8.4 scan range. Four commits (floor82-1..4):

- floor82-1 — declared everywhere: composer `php >=8.2` and the platform
  config 8.2.0 (lock re-hashed, validate/install verified), phpcs-compat
  testVersion 8.2-8.4, the plugin header and readme Requires PHP 8.2, the
  conventions gate's header literal and every fixture header it judges,
  the composer script descriptions, and the live docs (CONVENTIONS,
  TESTING, SPEC, IMPLEMENTATION_PLAN, architecture 0003/0005 — historical
  records untouched). Floor-claiming docblocks updated honestly; one
  floor-exploiting deletion rode along because it gated the check: the
  composer platform is 8.2, so phpstan's curl signature matches the
  runtime and CurlPsr18Client's two argument-type suppressions are gone.
- floor82-2 — the function-floor sweep flipped to the POST-floor side:
  with 8.2 every pre-8.2 function is legal, so the sweep now bans the
  8.3/8.4 additions PHPCompatibility 9.3.5 (pinned 2019) cannot see and
  that would fatal every 8.2 install (json_validate, str_increment, the
  posix trio, array_find/any/all, the mb_trim family, bcdivmod).
  Mutation-tested. The NAN spelling stays (clearer than fdiv, the zai
  sibling suite's idiom since GLM2 #4); the runtime pin asserts >= 8.2.
- floor82-3 — the PHP<8.1 reflection guards (glm38-2's three plus their
  ten sibling idioms) and WpConnectorsTestCase::openPrivateProperty()
  are deleted: reflection needs no accessibility opening on 8.1+, and
  the guarded calls would be deprecation-emitting no-ops on the 8.5
  runtime. glm38-2's fixes complete as no-ops kept honestly.
- floor82-4 — the last floor-claiming stragglers (the fixture readme, the
  tool_schema_memo and GLM5 #5 docblocks); every remaining 7.4 mention
  is a historical record or an SDK fact.
- floor82-5 (verifier round) — the sweep's ban list rebuilt from the php.net
  migration pages: the first form misattributed mysqli_execute_query (a PHP
  8.2.0 function, legal on the floor) to 8.3 and missed ten families
  (mb_str_pad, socket_atmark, mb_ucfirst/lcfirst, bcceil/bcfloor/bcround,
  request_parse_body, the http_*_last_response_headers pair,
  grapheme_str_split); the pattern gained the /i flag (PHP calls are
  case-insensitive — an uppercase spelling evaded it). Mutation-batteried
  both directions; the verifier's cosmetic residues cleaned (the harness's
  bare $instances; no-op, two comment phrasings).

### Fixed (zai / M2 — GLM39 round, /code-review max)

The 39th review round (15 emitted; the caller dropped 13 as
ledger-covered re-flags, each naming its own ledger citation and
offering no new evidence — zero correctness defects, both survivors
cleanup-class). 2 fix commits, then a two-lens verifier pass
(independent correctness + security agents over the full round diff) —
verdicts in round 39 of docs/review/REFUTATION_LEDGER.md:

- The declaration-validation walk is ONE shared scaffold (glm39-1):
  the sequence (empty tool name → duplicate name → declared-names
  bookkeeping → list-root parameter schema, per declaration,
  first-bad-wins) was maintained as near-verbatim loop twins in
  prepare_tools_param() and reject_misshapen_wire_values() while the
  rules already lived on RequestShapeGuard (glm19-5) — and the parity
  records show each rule landing one surface late while the loops were
  twins (GLM12 #5, glm13-9, glm16-11). The walk joins the rules on the
  guard (the glm26-7/glm30-4 composition idiom): the zai surface calls
  it bare (rejections only; the vendor parent assembles tools[]
  itself), the zai_anthropic surface's memo read/store, eager
  encodability oracle, empty-object normalization, and tools entry run
  as the per-declaration continuation. Behaviorally inert by the DTO's
  immutability (the schema check now precedes the memo read and re-runs
  on hits — a no-op, the same immutable parameters that passed once).
  New inherited lockstep pin holds the order on BOTH surfaces
  (identity before schema within one declaration; declaration order
  across declarations) — the coverage the duplication lacked.
- The DebugLogger option pair joins the glm23-13 class-free pin
  (glm39-2): the debug literals were hand-spelled on both the
  uninstall side and the suite's own plants/assertions, so a constant
  rename left both sides consistently wrong together — the suite green
  while the running plugin's log row (redacted request URLs) survived
  uninstall as an orphan. The pin derives both names from
  DebugLogger::OPTION_ENABLED/OPTION_LOG and the plants ride the
  constants too; mutation-tested (a planted rename fails three
  assertions across both directions). uninstall.php itself is
  untouched — the class-free constraint stands.

### Fixed (zai / M2 — GLM38 round, /code-review max)

The 38th review round (15 emitted; the caller dropped 3 as
ledger-covered re-flags — the zai 200-envelope directory gap (glm18-4),
the setLogprobs(false) rejection (glm4-4/glm37-2), and the per-surface
guard_settings_save neighborhood (GLM2 #8, closed by scope decision)):
10 fix commits over the survivors and one adjudicated carve-out, then a
two-lens verifier pass (independent correctness + security agents over
the full 10-commit diff) whose every claim HELD with zero confirmed
defects — plus this record. See round 38 in
docs/review/REFUTATION_LEDGER.md:

- The NAN guard pin rides the 7.4-safe NAN constant (glm38-1): fdiv()
  is PHP 8.0+ and fataled the test on the composer-declared floor
  instead of exercising the GLM2 #4 pin — locked PHPCompatibility
  9.3.5 has no sniff for it. A floor sweep in ToolchainSmokeTest now
  pins a curated 8.0+ call vocabulary over the phpcs-compat tree set
  (array_is_list stays unlisted: the SDK polyfills it, glm31-9).
- The three unguarded reflection invokes carry the PHP<8.1
  setAccessible idiom (glm38-2): the chokepoint-net pins and the
  entry-rejection lockstep pin errored with ReflectionException on
  PHP <= 8.0. Verified on real 7.4.33 in docker both directions.
- The protocol wrap's plain-class requirement goes EXACT (glm38-3):
  an ApiKeyRequestAuthentication subclass — accepted by the registry's
  instanceof gate, never produced by the SDK — was silently rebuilt
  from getApiKey() alone, stripping its overridden
  authenticateRequest() behavior (fail-OPEN where the foreign shape
  refuses typed). The subclass shape refuses typed now, and the
  probe's raw shape check judges the exact class BEFORE the funnel so
  the refusal cannot launder into the ladder fallback (the glm16-1
  cross-credential shape; mutation-tested).
- The token-limit payload names the max_tokens the WIRE carried
  (glm38-4): the finish-reason walk re-read the live config at parse
  time, so a mid-request setMaxTokens() (middleware capturing the
  model inside send()) retold the limit; the build stashes the wire
  value and the payload names it unconditionally.
- The registry's endpoint column is pinned to each settings class's
  own ENDPOINT_CLASS (glm38-5): the pairing was owned twice with no
  tie — a one-sided edit split the settings-change and uninstall
  invalidation worlds. Per-row pairing identity, mutation-tested.
- The error/malformed channels ride the shared aggregator base
  (glm38-6): $error/has_error() and $malformed_event/
  has_malformed_event() were byte-identical twins in both
  aggregators; flags (protected) and getters (final) hoisted, each
  subclass keeping its genuinely divergent raising sites. The
  property harness and every pin stay green unchanged.
- The unanchored-include judgment is per statement, not per quoted
  literal (glm38-7): the per-literal loop never read its variable and
  appended the identical violation N times; exactly-once pinned.
- The content-block vocabulary's membership rides once-flipped sets
  (glm38-8): parse_content_block()'s per-block in_array scans over
  MAPPED_TYPES/KNOWN_UNMAPPED_TYPES became isset() on the vocabulary
  owner's set helpers (the glm26-12/glm37-11 idiom);
  differential-verified identical over the hostile spellings.
- The stop-reason consistency check reuses the build loop's
  tool-call presence (glm38-9): the third full parts pass
  reconstructing $has_tool_call is deleted; the tool_use branch sets
  it in the loop that already judges it.
- The probe CLI parses argv ONCE (glm38-10, supersession): the scan
  collects name=>value pairs as it validates, deleting the getopt()
  call, its composed spec, and the agreement-maintenance class
  glm37-9 existed to police. getopt's repeat semantics stated
  deliberately (first occurrence carries the value, any repeat
  marks '') so the ledgered valued-repeat diagnostics survive
  verbatim. Old-vs-new differential byte-identical over 4,735
  hostile invocations (every 1-/2-token combination of a 34-token
  hostile set, the core 3-token space, 800 seeded randoms), then the
  exhaustive 3-token differential (44,495 invocations, zero
  divergences), plus the verifier lenses' own independent batteries.
- glm38-11 LEDGERED as a confirmed carve-out (no code change): the
  refusal gate's endpoint resolution is lazy by design — the flag
  read precedes region_switch_pending()'s resolution and the
  region_pending return precedes binding(), so no steady-state
  double resolution exists to thread away and an eager resolution
  would ADD cost to the no-state path (the finding's per-request
  cost premise was wrong).

### Fixed (zai / M2 — GLM37 round, /code-review max)

The 37th review round (11 emitted; the caller dropped one
behavioral re-flag as the ledgered glm31-verifier adjudication,
keeping its doc-drift half, and the review's own verifiers refuted 3
candidates): 13 commits — 11 fixes plus two from the round's
two-lens verifier pass, every claim HELD. See round 37 in
docs/review/REFUTATION_LEDGER.md:

- The refutation ledger's falsy-option line is scoped to the keys it
  still governs (glm37-2): "setLogprobs(false) etc. are neutral
  no-ops" contradicted the pinned glm4-4 rejection of ANY
  explicitly-set wire-forwarded value — the stale line had just
  burned a verifier on a non-defect. The wire-inert keys (topK,
  webSearch, output-*) keep the no-op tolerance.
- The corrupt-only usage docblock states the zero-choices corner
  (glm37-1): aggregated()'s choices gate returns before the glm31-1
  resolution, so a corrupt-only stream with NO choices frame rejects
  through the generic no-usable-event channel (the adjudicated,
  fail-closed corner — no silent zeroing, generic message). Pinned;
  behavior unchanged.
- The Anthropic Messages route map is pinned to the matrix's plan
  vocabulary (glm37-3): MESSAGES_ROUTE_BY_PLAN had zero test
  references, so a plan added to PLANS+MATRIX but missed there hit
  an undefined index → TypeError at the first real generation. The
  pin asserts key-set identity and per-combination resolution.
- The availability gate hooks state their un-wired read/recorder
  contract (glm37-4 + glm37-12): a FRESH instance probed through
  isConfigured() answers SILENTLY — configured-pending TRUE (FALSE
  under region-switch distrust) on zero network evidence, plus a
  planted 60s miss marker; verifier-reproduced. Docblock
  disposition; no current consumer probes through the hooks.
- The probe-miss sweep survives a quarantined endpoint child
  (glm37-5): probe_miss_transient_ids() mirrors its discovery twin's
  guard, composing identities through the new parameterized
  AbstractZaiEndpoint::compose_cache_key() (which cache_key() now
  delegates to); identity equality pinned on both surfaces.
- The SDK cache neutralization is one trait both directories compose
  (glm37-6): Support\NeutralizesSdkModelCache owns the trio,
  parameterized by a per-surface endpoint hook; the endpoint-scoping
  and PSR-16 poison-entry pins moved to the shared directory test
  base and now execute once per surface (the anthropic side was
  verified once by glm36-6 and pinned never).
- effective_key()'s guarded read rides wired_or_null() (glm37-7,
  completing glm36-4): a reader throwing beyond its documented
  RuntimeException contract now surfaces loudly instead of
  laundering into "nothing wired" with the ladder key reported
  effective.
- One isConfigured() consult resolves its endpoint once (glm37-8):
  the plan/region option reads ran twice per consult; threaded
  through binding()'s precomputed parameter and
  region_switch_pending()'s new optional one. glm15-6's boundary
  untouched.
- The probe CLI's long-option vocabulary and key-source guidance
  compose from single owners (glm37-9/10 + glm37-13): the getopt
  spec, the whitelist, the diagnostics, and the usage/no-key prose
  derive from zai_live_probe_long_options() and the key-source
  constants — closing the scan-passes/spec-drops silent-defaults
  direction (a key-present run billable on unchosen settings);
  source-pinned with an order-independent spec ban.
- The aggregator's event-name membership rides once-flipped sets
  (glm37-11): isset() over derived static sets replaces the
  per-frame strict in_array scans; the list constants stay the
  vocabulary owners. Behavior identical (proph harness green).

### Fixed (zai / M2 — GLM36 round, /code-review max)

The 36th review round (13 emitted, 6 correctly dropped as
ledger-covered re-flags; 7 fixes over the survivors): 10 commits plus
this record — the round's own first scanner fix was falsified by the
verifier pass and completed in a hardening commit. See round 36 in
docs/review/REFUTATION_LEDGER.md:

- Every destructuring and dynamic write spelling refuses the
  self-containment include proof on both write paths (glm36-1 +
  glm36-8): the PHP 7.1+ '[ ... ] =' spelling and the list() twin
  were invisible to the plain-variable collector (and the square
  spelling to both paths), so a foreign array rewrite laundered the
  proof. The verifier pass then found the first form's holes — all
  empirically reproduced with the runtime include escaping the plugin
  dir while the scanner reported zero violations — and glm36-8 closes
  them: the statement anchor is gone ('([$f] = ...)', 'if ([$f] =
  ...)', 'return[$f] = ...'), nested list() parens cross, foreach
  VALUE bindings refuse ('as [$f]', "as ['k' => $f]", 'as $k =>
  [$f]', 'as &$f'), variable-variable writes ('$$name', "{'f'}")
  refuse every proof in the file, and every refusal reads
  '0 !== preg_match(...)' — a PCRE backtrack-limit abort is a
  REFUSAL, never a no-match (a ~4 KB burner statement could spend the
  call's budget before the real write was examined). Documented
  safe-direction over-approximations: an element write KEYED by the
  map ('$rows[$map] = 1') now refuses; word boundaries end the
  '$mapx' false-refusal class.
- stop_sequence's non-string degrade is the ledgered envelope-metadata
  tolerance, stated honestly (glm36-2, boundary adjudication): a
  present-but-non-string stop_sequence latches nothing and flags
  nothing on the stream (null in additionalData) while the
  non-streaming body's vendor pass-through carries the value
  verbatim — every schema-legal input agrees on both channels.
  Accepted as GLM1 #9's non-load-bearing metadata class (the proph
  harness allow-lists the degrade; zero plugin consumers); the false
  "envelope parity" comments at the latch site and aggregated()'s
  header are rewritten, and a behavioral pin asserts the accepted
  divergence in both directions.
- The live probe's missing-value check rides the argv consumption
  site, and an empty '='-attached value is a missing value (glm36-3 +
  glm36-9): a trailing bare repeat ('--plan coding --plan') was
  silently ignored into the full live round trip, and getopt() drops
  '--plan=' entirely — the option fell to defaults (billable on
  settings the operator never chose, glm31-3's silent-defaults
  class). One sequential scan checks at consumption time; the
  option-named diagnostic fires before the key lookup.
- One wired_or_null() helper owns the guarded auth-reader invocation
  (glm36-4): the identical RuntimeException-guarded reader call was
  hand-copied three times in AbstractZaiProviderAvailability — the
  GLM5 #11 divergence class. Behavior-preserving at all three sites.
- The guidance-to-system-instruction merge rides the shared builder
  (glm36-5): the append-or-replace rule was duplicated verbatim in
  both models; JsonOutputGuidance::merge_into_system_instruction()
  serves both byte-identically, with per-suite source pins.
- The zai_anthropic directory rides the SDK's route-agnostic base
  (glm36-6, boundary adjudication): the docblock's custom-directory
  justification targeted the OpenAI-compat abstract's route
  assumptions — the route-agnostic parent imposes none. The
  hand-rolled list/has/get trio is gone (the consult is the parent's
  sendListModelsRequest()), the sibling's cache neutralization rides
  with it so the WordPress transient stays the single cache, and the
  unknown-model rejection wording unifies on the vendor trio's (the
  per-surface divergence was the finding's own drift evidence).
- The usage validator's member list and mode are required arguments
  (glm36-7): the defaults baked the Anthropic surface's vocabulary
  into the shared both-surface class — a default-riding OpenAI call
  site would validate the wrong member list and answer null
  fail-open. Every call site names its protocol's members and mode; a
  missing argument fails loudly.
- The glm36-6 rename's stale models_map() doc references corrected
  (glm36-10, the glm19-13 doc-drift class).

Verifier pass (two independent lenses — correctness + security — over
the full round diff): every confirmed defect is fixed above (five
scanner laundering channels + the probe silent-default + the doc
drift); every other equivalence and adjudication claim HELD — glm36-2
per-shape on both channels, glm36-3's shape battery with no
scan-vs-getopt divergence, glm36-4's catch-scope equivalence,
glm36-5's byte-identity, glm36-6 empirically inert against a counting
PSR-16 stub (zero cache ops, next-consult retarget, poison entry not
served, sweep pin green), glm36-7's loud failure mode. Residuals,
ledgered: an absent stop_sequence leaves the stream's key present-null
while the body omits it; valued probe repeats reject through a
value-blaming whitelist diagnostic (loud-only); the inherited
invalidateCaches() issues one delete-only no-op against a host-shared
PSR-16 store. Full suite green in default and random order (1288
tests, 42100 assertions, 2 skipped — the live-key gates).

### Added (zai / M2 — proph property harness)

The mutation-invariant property harness for both SSE aggregators
(tests/SseAggregatorMutationPropertyTest.php) — infrastructure, not a
review round: it closes the silent-divergence/silent-loss defect
class the per-round loop had been chasing one shape at a time
(glm23-6, glm28-2, glm31-1, glm31-2, glm33-1, glm34-1 were all
members) as an INVARIANT over the mutation space. For every
(well-formed corpus stream × mutation operator × site) the
aggregation must be CLEAN, FLAGGED, or an explicitly allow-listed
TOLERATED class with a ledger citation — never a diff without a flag
and without an entry, never an escaping Throwable. 18 operators
cover every mutation family the ledger ever flagged; the allow-list
is enumerated, not discovered, and widening it requires adjudication.
A cross-surface parity battery checks shared corruption shapes earn
matching verdicts on both twins, with the pinned divergences encoded
(the required-lifecycle [Anthropic] vs sentinel-optional [OpenAI]
terminal drop). The default run (2000 cases/surface, fixed seed) rides
`composer check` (~0.6 s of suite time); WP_CONNECTORS_FUZZ_CASES /
WP_CONNECTORS_FUZZ_SEED scale it for soaks (ceiling 250k).

Bring-up found ZERO production defects: every diff-no-flag observation
adjudicated to a tolerance, each ledgered — the GLM9 #1/glm33-4
stop_reason string-latch family, the GLM4 #11/Codex R15 #1 usage
default-zero and value-transparency classes, the GLM12 #9/glm34-5
empty-usage shapes, the GLM1 #15/glm13-4 unknown-type forward-compat
drops (empty-string spellings included), the entry-granularity
drop-frame and GLM7 #1/GLM10 #13 index-value merge-identity classes,
and the GLM5 #3 associative {} / [] collapse for array-shaped
members. See the proph section in docs/review/REFUTATION_LEDGER.md.

Verifier pass (two independent lenses over proph-1): the allow-list
is SOUND — no entry swallows a shape a ledgered rule says must flag
(20 hand-built lifecycle damage shapes all flag past the generic
entries), the coverage/non-vacuity gates go red under hostile env
input, and the parity expectations reproduce against the real
aggregators. Five harness-side findings fixed as proph-2: the loose
!= payload comparator (null/false/[] flips classified CLEAN — now a
type-strict, map-order-insensitive deep comparator), a mislabeled
operator target (dead allow-list entry), a dead cut tolerance entry
(removed with the wire-construction proof; decodable-scalar skip
frames joined the corpus), the missing CASES ceiling (clamped to
250k), and a seed-clamp float overflow (strict-types TypeError —
now digit-length-exact, SEED=0 honored). Residual, ledgered: the
effective env CASES/SEED are visible only through the assertion
count and failure reports, not printed on green runs.

Validated: 50k cases/surface soak green twice (pre- and post-fix;
100k total mutations each time, 300040 assertions, ~17 s), alternate
seeds (0, 15-digit, 20-digit, three fixed) green, and the full
suite green in default and random order (1264 tests, 42032
assertions).

### Fixed (zai / M2 — GLM35 round, /code-review max)

The 35th review round (zero novel correctness defects; one candidate
struck as a glm26-2 re-flag — the ledgered ascending-stops-over-
interleaved-starts tolerance): 10 fixes, then a two-lens verifier
pass whose one confirmed finding (a message-only divergence in the
glm35-4 guard) is fixed as glm35-11. Ten commits plus this record;
see round 35 in docs/review/REFUTATION_LEDGER.md:

- The zai_anthropic stream parse rides the malformed-event channel
  alone (glm35-1): every aggregated() null return raises the flag
  first (this wire's required message_start/message_delta/message_stop
  lifecycle makes every unusable stream a flagged truncation), and the
  model checks the flag before any null — so the separate 'No usable
  message event was received.' branch was unreachable dead code.
  Deleted and source-pinned absent; the zai twin's null channel stays
  live (an OpenAI choices-less stream is unusable without being
  malformed, a shape this wire cannot produce).
- Every canonical SSE segment a builder can emit rides the builder
  (glm35-2, completing glm34-10's conversion): 28 hand-spelled
  message_delta+message_stop tails, 4 content_block_stop frames, and
  3 message_start frames — each byte-verified against the builder's
  runtime output before the swap, through a layout-independent
  matcher. Malformed fixtures whose damage is the tail, canonical
  tails the builder cannot emit byte-identically, and the array-idiom
  compositions stay hand-spelled.
- The protocol-trait pin derives its class set by sweep (glm35-3):
  the pin hand-listed the three SDK-interfaced files, so the fourth
  class it exists for could never be seen — and the omission fails
  open (fallback_authentication()'s base default is plain ApiKey
  auth, which z.ai accepts). The set is derived now: every class
  under connectors/zai/src implementing the SDK's own
  WithRequestAuthenticationInterface under this surface's naming
  convention must compose the trait (checked through the reflection
  trait chain), supply the raw hook, and carry no bespoke wrap
  override; a >= 3 lower bound proves the sweep sees the real tree.
- The body parse's member rule rides the content-block table
  (glm35-4, corrected by glm35-11): the text/thinking arms' hand-
  rolled isset+is_string probes are one hoisted guard reading
  AnthropicContentBlocks::STRING_CONTENT_MEMBERS, with the message
  derived from the closed constant keys; the lockstep pin asserts the
  model rides the table and forbids the hand-rolled spellings, plus a
  behavioral member battery. The verifier pass found the unscoped
  lookup also caught the table's two DELTA names (a body block typed
  as text_delta answered the member message instead of the
  unsupported-type rejection) — scoped to MAPPED_TYPES (the block
  half) as glm35-11, with delta-named-block shapes added to the
  battery (mutation-tested).
- The map memo stores no digest nothing reads (glm35-5): glm26-6
  replaced the digest compare with a strict stored-list compare but
  left the md5 computed and stored on every rebuild — zero readers.
  The key, the md5, and the shape clause are gone; the docblocks
  state the strict-compare contract in present tense.
- The availability children describe their aliasing (glm35-6): both
  children opened with the pre-alias "mirror plus consistency test"
  contract directly above constant-expression aliases that cannot
  drift — an instruction manual for reintroducing the two-list drift
  the aliasing made structurally impossible. The headers' deleted
  KEY_ENV_NAME listing is corrected to the four constants that exist.
- DebugSettings registers through the declaring owner (glm35-7):
  register_settings() reached the plugin-wide option group through
  the concrete first-surface child although the group is declared on
  AbstractPlanRegionSettings — the reach-through class glm29-14
  eliminated from the same file's neighbor. Same string, the
  abstract owner named.
- The stop-lifecycle walks ride the stopped-prefix count (glm35-8):
  the content_block_stop handler's O(B^2) per-stop probe walk and the
  message_delta closed-lifecycle gate's per-block loop are each
  provably one compare (while block i is open no stop above i can
  have been recorded, so the stopped blocks are exactly a prefix);
  the count is incremented at the one stop-recording site.
  Equivalence held under the round's 41,066-sequence exhaustive
  ordering differential and 4,000-run hostile fuzz, plus the verifier
  pass's own 8,000-stream old-vs-new differential (zero divergences,
  full private state compared).
- The directory suites' surface-constant twins ride one base
  (glm35-9): the plan/region retargeting, unauthorized-fallback, and
  general-fallback tests were byte-identical twins in both directory
  suites except for each surface's constants — the glm22-6 drift
  class. AbstractZaiModelDirectoryTestCase owns the four tests; each
  concrete suite supplies its constants through hooks, with the
  endpoint URLs a literal per-surface map (the pin, never derived).
  Deliberately not consolidated, ledgered as debt: the twins whose
  bodies embed per-surface decision history (verdict recording, memo,
  parse shape) — merging those comments would orphan the ledger's
  citations and their assertion bodies genuinely differ.
- anthropicModelsBody() derives last_id from the input like first_id
  (glm35-10): the $previous accumulator threaded the foreach solely
  to mirror the input list by hand, desyncable from the emitted
  'data' entries under any future in-loop filtering; output
  byte-identical for the empty, single, and multi-entry shapes.

Verifier pass (two independent lenses, correctness + security, over
the full 10-commit diff): every equivalence claim held except one —
the glm35-4 unscoped-lookup divergence above (LOW, message-only, same
class/channel/verdict), fixed as glm35-11 and pinned. Evidence: an
8,000-stream randomized/pathological old-vs-new differential on the
aggregator (glm35-8 — full private state, aggregated(), and all three
flags, zero divergences); byte-comparison of every glm35-2 builder
swap; a live run of the glm35-3 sweep (the derived set is exactly the
old three, no wrong entries); the glm35-4 exception-message escape
claim proven end-to-end (is_string gate + closed non-numeric-key
table + table-value member; no unescaped render path anywhere the
message travels); OPTION_GROUP proven single-declared and equal
(glm35-7); and glm35-9's inherited tests assertion-for-assertion
faithful to both originals (each runs exactly twice, once per
concrete suite). Security lens clean on all shipped code; three LOW
residuals ledgered (test-code only, none a regression): the
auth-headers pin's override regexes name two historical spellings,
the sweep's class gate is the surface's naming convention, and
class_exists() autoloads the scanned classes (side-effect-free today,
fail-loud if not). Full suite green in default and random order
(1261 tests, 29992 assertions, 2 skipped — the live-key gates; +1
test, +19 assertions over glm34).

### Fixed (zai / M2 — GLM34 round, /code-review max)

The 34th review round (15 findings emitted after the ledger filter
struck 4 as re-flags): 10 fixes, one doc-drift correction, one
conscious accept. Nine commits plus this record; see round 34 in
docs/review/REFUTATION_LEDGER.md:

- An undeclared UNDECODABLE data-only SSE frame flags through the
  malformed-event channel (glm34-1): the one corruption corner every
  sibling rule missed (Codex R4 #3 needs a declaration, glm33-1 a
  decodable payload, glm16-15 a decodable non-object) — a data: line
  cut mid-JSON with no event: field was silently dropped, the stream
  completing with the chunk missing and every flag false. json_last_error()
  distinguishes the undecodable shape from decodable scalars exactly as
  the zai twin does (glm23-6, both phases); the decodable tolerances
  (scalar skip, trailing typeless noise, bare trailing [DONE]) are
  untouched, and the GLM12 #15 call-site pin is superseded to 8.
- The zero-translatable-part and zero-parts rejections throw the marker
  exception (glm34-2, glm28-4 parity): the plain ResponseException was
  nulled out by the mislabeled-Content-Type fallback's catch, surfacing
  the generic stream error where the zai twin surfaces the precise
  message — the asymmetry glm14-2's marker contract exists to prevent.
- Content presence is judged with array_key_exists semantics (glm34-3):
  an explicitly-present "content": null now rejects through the
  invalid-data channel like every sibling envelope member, not
  fromMissingData.
- guard_wire_values() states the old-eager-order contract (glm34-4,
  adjudicated): NO behavioral drift — both surfaces' compositions are
  exactly glm20-8's documented pinned orders, and the finding's claim
  about the zai twin misread it; what was wrong is the zai_anthropic
  docblock's "matches the mapping order" phrasing (the glm19-13
  doc-drift class), now corrected.
- effective_key() reads the raw wired instance (glm34-6): the last
  availability reader routing through the protocol wrap, where a
  foreign wiring's wrap() throw laundered into "nothing wired" — output
  identical today (the wrap product is an ApiKeyRequestAuthentication
  subclass carrying the same key), the states now structurally
  distinct; source-pinned to the probe's one remaining funnel call.
- The wpdb::prepare() test stub substitutes in one pass over the
  original query (glm34-7): a bound value carrying a literal %s/%d no
  longer consumes the next argument's placeholder (core's
  left-to-right once-each semantics); glm20-9's pinned net encoding
  (quotes escaped, backslashes and $ tokens verbatim) and get_col()'s
  LIKE-to-regex contract are byte-identical, and the old stub's latent
  NUL-byte backreference corruption is gone with the preg_replace.
- One shared Anthropic content-block vocabulary table (glm34-8):
  Support\AnthropicContentBlocks owns the mapped set, the known-unmapped
  trio, and the glm15-21 string-member map; the body parse drops the
  trio through the constant, and a lockstep pin drives the catalog
  constants behaviorally on both transports — the two-file edit with no
  failing test is gone (the unknown-type divergence stays the documented
  GLM1 #15/glm26-2 decision).
- Test model wiring rides HARNESS_MODEL_ID (glm34-9): the eight sites
  spelling 'glm-5.3' beside a discovery prime (whose default IS the
  catalog-derived constant) were the glm29-10 catalog-refresh desync
  re-opening; a sweep pin forbids future ::model('glm-…') literals.
- The Anthropic SSE envelope boilerplate rides HttpResponseFactory
  builders (glm34-10): anthropicStreamStart()/anthropicBlockStop()/
  anthropicStreamEnd() emit byte-identical frames to the canonical
  hand-spelled forms; 297 of ~330 envelopes converted (net -542 lines).
  Deliberately-malformed fixtures, the framing suite's own fixtures,
  and the bespoke OpenAI chunk streams (whose implode composition
  carries no envelope boilerplate beyond the data: prefix) stay
  hand-spelled.
- sort_callback() compares memoized per-ID keys (glm34-11, the glm26-12
  idiom): the O(N log N) comparator no longer re-runs the two
  extraction regexes per comparison; the ordering rule is unchanged.

Consciously accepted, no code change (ledger round 34): the zeroed
input_tokens shape (glm34-5 — absent-members on both frames, each half
a documented tolerance, with the byte-equivalent non-streamed
absent-usage body pinned to parse zeroed since Codex R14 #5; the
present-but-wrong-type half already flags through the shared validator).

Verifier pass (two independent lenses, correctness + security, over the
full round diff): ZERO confirmed defects — every equivalence claim HELD
empirically (a 21-case pre/post aggregator differential with every
divergence exactly the new rule's class and byte-identical aggregated
payloads; a 32-case plus 200,000-iteration wpdb fuzz with zero
divergences outside the intended fix and the NUL class the old stub
itself corrupted; a 3,000-shuffle comparator differential including
hostile ids; mechanical multiset balance of the fixture conversion —
all 118 removed hand-spelled message_start ids reappear verbatim as
builder arguments, every token bucket exact, no assert/catch line
drifted; and the sort-key memo's key universe proven bounded to the
constant catalog on every feeder path). Security lens CLEAN across all
six areas (no DoS — a 200k-frame flood within milliseconds of
pre-round; no value or credential leakage — both new marker throws are
constant-fed by construction; no new file/network/eval constructs; no
benign frame class false-flags). Residuals ledgered: a pre-terminal
bare [DONE] now flags (was a silent drop — fail-closed, twin parity,
no pinned tolerance broke), the marker's trace origin one frame deeper
(glm32's diagnostic class), the builder id-input domain is implicitly
ASCII (a /- or unicode-bearing id would emit valid-but-differently-
escaped JSON), and the wpdb %% divergence from core stays (cosmetic,
caller-free). Full suite green in default and random order (1260
tests, 29973 assertions, 2 skipped — the live-key gates).

### Fixed (zai / M2 — GLM33 round, /code-review max)

The 33rd review round (the fork's relayed board after the round-32
loop: one PLAUSIBLE correctness finding, one latent mirror finding, two
vendor-contingent adjudications, one header-spelling cleanup; the eight
label-only survivors were never relayed with detail and are recorded as
not-adjudicated — see round 33 in docs/review/REFUTATION_LEDGER.md).
Three fixes, one commit each; two conscious accepts, ledgered:

- An undeclared TYPELESS payload carrier flags, never silently drops
  (glm33-1): a data-only frame whose event: declaration was cut AND
  whose payload carries no top-level type member derived '' and fell
  through dispatch_event() as an unknown type — the stream completed
  successfully with the carrier's chunk silently missing and every flag
  false (empirically reproduced first; glm21-1's adjudication presumed
  the type member present, and the typeless variant was the gap). The
  gate flags through the one classifier (GLM12 #15's null →
  malformed-event channel), the same silent-loss verdict glm23-1 gives
  cut declarations; every tolerance is pinned both directions
  (undeclared scalars, typeless trailing frames, typed carriers, the
  split-form reunion). The GLM12 #15 call-site count pin superseded to
  7 at its site (the GLM10 #4 lesson).
- Object-typed tool arguments are judged by their serialized shape
  (glm33-2): the scalar rejection's is_object() carve-out presumed
  every object encodes as a JSON object — false for a JsonSerializable
  returning a list/scalar, which shipped "input": ["Oslo"] past both
  shape rejections and the replay oracle (stable serialization): the
  misattributed-upstream-400 class. An OBJECT-typed argument now
  rejects typed when a successful raw encode does not lead with '{'
  (stamped calls skip — inbound acceptance proved the shape); a FAILED
  encode stays silent under the replay guard's own message. ArrayObject
  is verified NOT in the bypass class (encodes object-led on 8.5 and
  7.4 — the storage-encoding concern is the glm22-16 walker's, never
  the wire shape's).
- carries_json_body() resolves Content-Type through the vendor's
  HeadersCollection (glm33-5): the glm14-4 mirror read the exact-case
  $headers['Content-Type'] key while Request::getBody() resolves
  case-insensitively, so a 'content-type' spelling (legal HTTP) made
  the assembled array ride as $data with the vendor re-encoding the
  payload at send time through the path the ride deleted. The mirror
  builds the SAME collection the Request constructor builds from the
  same array, closing the spelling divergence and the SDK-bump desync
  by delegation; pinned across lowercase/uppercase/multi-value
  spellings plus the non-JSON and body-less-method negatives.

Consciously accepted, no code change (ledger round 33): the tool-id
uniqueness scope divergence (glm33-3 — outbound whole-conversation vs
parse per-response; no documented vendor guarantee, a real
Anthropic-compat layer shipped the reuse shape, parse-side
conversation scope is structurally impossible, and the outbound
rejection is loud and typed) and the unknown stop_reason hard-reject
(glm33-4 — founding design with vendor-parent parity: the SDK's own
OpenAI parse throws on unknown finish_reason; mapping unknown values to
stop() would fabricate a natural-stop claim).

Verifier pass (two independent lenses, correctness + security, over the
full round diff): ZERO confirmed defects — every equivalence claim and
both adjudications held empirically (a 43-sequence curated battery plus
a 30,000-interleaving seeded fuzz with zero unexplained divergences for
glm33-1, a 24-case reflection battery through the real
message_part_block() for glm33-2, a 16-shape mirror differential against
the real vendor Request for glm33-5 with end-to-end createRequest()
rides, mutation tests proving all three gates' pins fail on deletion,
and a comma-bearing Content-Type fidelity check showing the post-round
mirror matches the vendor where the pre-round mirror lied). Full suite
green in default and random order (1254 tests, 29926 assertions, 2
skipped — the live-key gates).

### Fixed (zai / M2 — GLM32 round, /code-review max)

The 32nd review round (10 finder angles, 20 candidates, per-candidate
verification, empty gap sweep) produced ZERO correctness defects: 13 of
its 15 findings were dropped at triage as ledgered re-flags or in-code
documented decisions (see round 32 in docs/review/REFUTATION_LEDGER.md
for the citation list). The two surviving cleanup findings, fixed one
commit each:

- apply_delta()'s text/thinking arms append unconditionally (glm32-1):
  the arms re-checked isset()+is_string() on the very member
  dispatch_event()'s has_string_content_member() gate (the glm15-21
  map) had already proved present-and-string, and that gated site is
  apply_delta()'s only caller — dead by construction (the glm24-5
  dead-conjunct class), completing the glm15-21 unification. The
  per-arm block-type mismatch checks and the input_json_delta
  partial_json check are untouched; a source pin holds both halves of
  the invariant (exactly one call site; no re-check pasted back),
  mutation-tested all three ways.
- JsonEncodeGuard::must_encode() rides encode(), the one oracle
  (glm32-2): the guard method hand-copied encode()'s check-plus-throw
  in the class that exists to single-source the raw oracle (the glm30-6
  micro class). It delegates with the returned encoding discarded, so
  its exception type and message are byte-identical to the encoding
  call sites' by construction.

Verifier pass (two independent lenses, correctness + security, over the
full round diff): ZERO confirmed defects — every equivalence claim held
empirically (a static only-caller proof, 84 curated frame sequences,
3000 seeded plus 35,000 hostile fuzz interleavings all byte-identical
pre/post, a dead-check instrument proving the deleted guards never
fired on any reachable input, and a 22-class hostile value battery for
the guard delegation), with both mapping suites' message pins green and
the full suite green in default and random order (1248 tests, 29898
assertions, 2 skipped).

### Fixed (zai / M2 — GLM31 round, /code-review max)

The 31st review round (13 finder angles, 17 verifiers; 11 candidates
refuted directly by the refutation ledger; the review caller dropped a
12th — the ledger's own deferred `$ships_forwarded_values` item, no new
evidence) left 10 verified findings: 9 fixed one commit per item, 1
consciously accepted (the DebugLogger concurrency shape, pre-existing
and out of charter) and recorded in the ledger (round 31). Verifier
pass (two independent lenses, correctness + security, over the full
round diff): ZERO confirmed defects — every fix's equivalence claim
held empirically (a 19-interleaving usage battery, a 17-shape
tool-input battery, a 300-shape CLI fuzz with a canary key, a
200k-case array_is_list differential including PHP 7.4 in docker, the
walk guard proven fail-closed against a hiding attack, and a whole-diff
sweep finding no new runtime channel of any kind).

- A corrupt-only streamed usage declaration flags the stream (glm31-1):
  a present non-array `usage` member on a streamed frame skipped the
  merge silently, so a stream whose ONLY usage declaration was corrupt
  ("usage":"unavailable") completed with zeroed accounting while the
  byte-equivalent non-streaming body rejects typed (the validator's
  object rule — lenient mode rescues null, never a scalar): the glm18-4
  cross-channel divergence class, on the one member glm28-2's hardening
  left to the GLM6 #3/GLM7 #8 pins (whose letter covers absent/null
  members and partial objects, never a present non-object member). The
  member is remembered in both phases and flags at end of stream ONLY
  when no valid usage member merged (the post-[DONE] gap-fill included)
  — GLM6 #3's pin survives verbatim (a late corrupt member after a
  valid merge stays superseding noise), pinned both ways now.
- Fragments over a non-empty start input fail as a parse error
  (glm31-2): the tool_use consolidation replaced the
  content_block_start-carried input wholesale with the decoded
  input_json_delta fragments — a start input {"a":1} plus fragments
  decoding to {"b":2} shipped {"b":2} on a success, silently discarding
  the start-carried arguments. The Messages streaming wire never
  carries both (vendor-documented: tool_use starts ship an EMPTY
  placeholder input), so the shape is nonconforming and ambiguous: it
  flags the tool-input channel now. The adjacent pins survive (an
  empty-string fragment keeps the start input standing; a start-block
  input with no fragments still becomes the call args).
- The probe CLI rejects unknown options and answers --help (glm31-3):
  getopt() drops unrecognized options and the argv pre-scan knew only
  the three exact tokens, so a flag typo, a single-dash spelling, a
  stray positional, or a help request all fell to the defaults — with a
  key present, the probe ran the full live, BILLABLE round trip on the
  default surface while reporting PASS (live-reproduced by the review).
  A sequential raw-argv scan rejects every unknown shape before the key
  lookup; --help/-h prints a usage composed from the same owners the
  validation rides (the surface map, the settings layer's
  PLANS/REGIONS) and exits 0.
- An unreadable subdirectory converts the walk abort to a violation
  (glm31-4): the self-containment tree walk aborted with an uncaught
  UnexpectedValueException — a fatal exiting 255 under `composer
  check` and `bin/inspect-artifact.php` — while the sibling
  unused-import scan has converted the same abort to a counted FAIL
  since glm17-17. The guard lives at the ONE shared walk (plugin-tools)
  so every consumer's failure channel fires automatically; partial
  violations kept. Pinned with the chmod-000 shape on non-root hosts.
- The availability classes stop mirroring KEY_ENV_NAME (glm31-6): the
  mirrors were production-dead (env/constant resolution is the settings
  layer's own ladder) — deleted with glm19-10-style no-redeclaration
  pins; the settings class is the one owner.
- The plugin-row Settings link follows the settings-page owner
  (glm31-7): action_links() hand-named PlanRegionSettings::PAGE_SLUG
  while boot() and DebugSettings derive the owner from the ZaiSurfaces
  registry — a page move would have stranded the link on a dead admin
  URL with no failing test. The link derives the owner now and the
  behavioral pin reads it the same way.
- The artifact must-ship pin sweeps the whole source tree (glm31-8):
  the hand-enumerated eight-class list could pass a third surface's
  silently-missing classes; the pin now asserts EVERY connectors/zai/
  src file ships.
- JsonShape::is_list() rides the native array_is_list() (glm31-9): the
  SDK already polyfills it for the 7.4 floor and its own hot paths
  (PromptBuilder, the OpenAI-compatible parse the zai surface extends)
  call it on every request — every functional installation provides it.
  The hand-rolled range-over-count idiom is deleted and forbidden
  everywhere (the GLM8 #13 pin superseded at its site); a canary pins
  the harness context loading the function.
- The is_object_shape() docblock loses its paste artifact (glm31-10):
  duplicated summary line with a stray inline '/*' opener.

### Accepted (zai / M2 — GLM31 round)

- DebugLogger's read-append-write on the debug-log option is not atomic
  (glm31-5): two concurrent workers can lose one diagnostic row (last
  writer wins with a COMPLETE array — a dropped row, never a corrupted
  one, in an off-by-default 50-row ring buffer). WordPress's option
  store carries no row locking and core itself accepts this class for
  option-backed state; the shape is pre-existing (this connector adds
  producers, not the race) with no production loss report. No locking
  machinery; the boundary is documented at log() and in the ledger.

### Fixed (zai / M2 — GLM30 round, /code-review max)

The 30th review round (ledger-filtered: 10 of its 15 raw findings were
re-flags of ledgered decisions, dropped) left 5 surviving findings,
fixed one commit per item, plus 2 fixes over the 10 verified cleanup
candidates the round's 15-cap had cut — the other 8 were refuted on
re-examination against the ledger and recorded there (round 30).
Verifier pass (two independent lenses, correctness + security, over the
full round diff): every fix's equivalence claim HELD — the credential
reset reaches every SDK credential store (vendor-wide enumeration), the
OpenAI catalog byte-identity held across all branches, the status-class
mapping matched the vendor for every status, and no new attack surface
opened. One defect confirmed (the round's OWN refutation text stated
PHP's strict array compare backwards — it judges objects by identity,
not value); corrected in the ledger as glm30-9.

- The test harness erases SDK credentials between tests (glm30-1): a
  credential wired onto the process-wide SDK state rode every later
  test in the same PHP process — the registry's per-provider
  authentication map (re-applied by any later registerProvider(), and
  bindModelDependencies() stamps it onto new model instances) and the
  AbstractProvider static instances themselves (reachable through the
  direct setRequestAuthentication() several suites use). Under the
  pipeline's own --order-by=random ordering, a test asserting the
  no-credential path failed as a pure function of execution order.
  setUp() empties the registry map and nulls the SDK trait's nullable
  credential storage on every cached provider instance — through the
  DECLARING class, because reflection does not see the private
  trait-composed property from a concrete surface class. Regression
  pair mutation-tested; both suite orders green.
- The HTTP-error catalog names each surface by its card name (glm30-2):
  safe_http_message()/to_wp_error() hardcoded the single 'z.ai API'
  identity for BOTH surfaces' non-2xx generation errors, so with both
  cards configured a 401 never named which surface rejected the key.
  The identity is the caller's now: each model declares HTTP_API_LABEL
  on the card-name chain (the settings layer's PROVIDER_LABEL, glm24-2's
  direction). The OpenAI surface passes 'z.ai API' (messages
  byte-identical to the old wording); the Anthropic surface passes
  'z.ai (Anthropic API)' verbatim. Both surfaces' suites pin that the
  catalog text names the surface.
- A final 1xx status is an invalid status, never a redirect (glm30-3):
  throwIfNotSuccessful()'s <400 fall-through labeled a final 1xx
  informational (representable in the Response DTO, though no real
  transport yields one) as RedirectException → zai_redirect_error. The
  three families get the vendor ResponseUtil's explicit ranges now and
  every other non-2xx status throws the SDK RuntimeException family
  with a label-carrying, status-only message on the zai_error mapping.
  Pinned by a status-family mapping battery; mutation-tested.
- One shared owner for the translatable-part predicate (glm30-4):
  message_has_translatable_part() was a private near-verbatim twin in
  the two models, differing only in the Anthropic empty-text clause.
  Support\TranslatableMessageParts::has_translatable_part() owns the
  shared shape; the ONE deliberate divergence (glm28-4's pinned
  tolerance) rides a parameter each model passes from its own
  EMPTY_TEXT_TRANSLATES policy constant. Both pinned behaviors survive
  byte-for-byte.
- The absent-total derivation vocabulary is a named, pinned constant
  (glm30-5): derive_absent_total_tokens() hand-listed the
  prompt+completion pair inline while validation rode
  UsageValidator::OPENAI_MEMBERS. The set is
  OPENAI_TOTAL_DERIVATION_MEMBERS now — the WIRE SEMANTIC of
  total_tokens, deliberately not computed from OPENAI_MEMBERS — and a
  pin asserts the sets agree, so any OPENAI_MEMBERS change forces the
  constant's conscious revisit.
- The wired-credential pair construction has one owner (glm30-6):
  effective_key() and effective_for_authentication() each spelled the
  wired-ApiKey credential pair inline; wired_credential() owns the
  non-empty-key mapping (the GLM5 #11 divergence class closed at the
  last duplication).
- The zai directory's auth-reader closure has one owner (glm30-7): the
  deferred reader was spelled inline twice (refuse_discovery() and
  record_rejection_for_status()); wired_authentication_reader() is the
  one factory — a closure factory, since the consumers invoke the
  reader in their own scope and the closure keeps the raw getter
  callable without widening visibility.

### Fixed (zai / M2 — GLM29 round, /code-review max)

Verifier pass (two independent lenses, correctness + security, over the
full round diff): all 15 fixes' equivalence claims held; the two
confirmed defects were the round's own hardening additions and are fixed
as glm29-16 — the glm29-7 redaction canary could not fail (PHPUnit's
fail() throws the same class the canary catches, empirically
demonstrated; the canary records a flag and asserts it outside,
mutation-tested), and the glm29-9 round-trip call is guarded at the CLI
shell so an uncaught fatal inside the runner can no longer print the
live key in the stack-trace argument frame
(zend.exception_ignore_args=Off default; no realistic trigger today —
re-verified LIVE through the guarded shell). The security lens'
bypass battery found no open, non-ledgered vector against the glm29-3
interpolation fix — including the escaped-quote and backslash-parity
shapes, caught by the escape-aware runtime-segment layer's
composability.

All 15 findings of the round-29 max review (the 29th review round on
this branch, ledger-filtered; one PLAUSIBLE finding verified first —
its mechanism held but production reachability refuted, recorded as a
conscious accept), one commit per item:

- A diverging JsonSerializable no longer ships a zero-length request
  body as success (glm29-1, the round's top defect): the encodability
  net's fall-through `return '';` was claimed unreachable, but
  jsonSerialize() is user code whose output can differ between
  invocations — the full-payload oracle failed, the attribution walk's
  re-encodes succeeded, and the ride discipline shipped the empty
  string AS the body. The fall-through returns the re-encoded artifact
  (or rejects typed when no invocation encodes); the invocation count
  and the shipped body are pinned.
- The zai (OpenAI-surface) SSE aggregator gained the error-event
  channel the Anthropic twin added in this same branch (glm29-2): a
  streamed provider error frame (`data: {"error":{...}}`) fell through
  the object-without-choices tolerance and completed the generation
  clean. A PRESENT error member or an `event: error` declaration sets
  has_error() (absent/null keeps its skip), pre- and post-sentinel
  identically, and the model rejects first with the surface-worded
  fixed message. glm23-6's pinned tolerances stay (a scalar
  `data: null`, a choices-less object without an error member).
- SECURITY: interpolated double-quoted literals no longer launder
  '../' traversal past the self-containment gate (glm29-3): the proof
  treated `"/sub/$name.php"` as static because only '${' counted as
  dynamic, missing `$name`/`{$name}`/`$$var` spellings AND suppressing
  the unanchored flag for the one form it saw — the value's runtime
  control was invisible to every layer. One quote-aware predicate
  (any '$' in a double-quoted literal; single quotes never interpolate;
  escaped dollars over-detect on purpose) serves every dynamic
  judgment, and the segment blanking keeps interpolated literals
  visible. Fixtures pin every spelling in both positions, the
  assignment-mediated route, and the tolerances.
- A present-but-wrong-type tool_use id or name in content_block_start
  flags at the stream channel (glm29-4, the glm28-2 parity): the
  accumulator ternaries nulled `"id":5` silently and the corruption
  surfaced only as parse_content_block()'s generic identity rejection —
  the coincidental-downstream-catch channel the zai twin's own comment
  says corruption must not rely on. Absent/null keeps its parse-time
  rejection (pinned both directions).
- The empty-prompt rejection rides the shared RequestShapeGuard for
  both surfaces (glm29-5, glm18-3's maxTokens-parity class closed for
  this member): the zai surface shipped the vendor parent's assembled
  `"messages": []` to a spec-faithful 400 with the generic
  misattributed message after the wasted round trip. One
  reject_empty_prompt() rule; the twin's inline copy rides it
  byte-identically; one inherited pin runs on both surfaces.
- The transport-failure redaction pin sees the key that actually flew
  (glm29-7): it asserted against a freshly drawn fixture key while the
  model was wired with a different random draw — an ErrorMapper
  embedding the wired key passed green. One draw wires and asserts
  (model_with_key()), and a canary proves the assertion flags exactly
  this key instance when present.
- The live probe's discovery evidence reads the named
  discovery_cache_id() (glm29-8), never a positional pick out of the
  owner's [positive, '_miss'] pair — a reorder would have read the
  marker that stores literal true and reported 'live' after a FAILED
  discovery.
- ONE round-trip runner owns the live acceptance sequence (glm29-9):
  the CLI probe and the PHPUnit smoke skeleton had each
  hand-maintained the sequence and drifted in both directions (the CLI
  carried the state delete, the miss-marker clear, the
  definitive-verdict rule, the transient clearing and live-vs-fallback
  evidence, and the preferred-model fallback; the skeleton alone
  checked the state option for plaintext). tests/harness/
  ZaiLiveRoundTrip.php owns the ordered steps once — report lines
  byte-identical — with both shells reconciled to the stricter side;
  the pins re-target the runner with supersessions documented, and
  both surfaces verified LIVE (PASS, openai coding/intl and anthropic
  general/intl, 2026-09-07).
- One catalog-derived harness model id feeds all four wiring/prime
  sites (glm29-10): the four independent 'glm-5.3' literals had to
  agree for the vendor directory lookup to resolve.
- The eight inline Anthropic error envelopes ride
  HttpResponseFactory::anthropicErrorBody() (glm29-11): the glm25-10
  drift class re-opened for error bodies. The message-less
  `{"type":"forbidden"}` fixtures stay inline by design.
- One harness-owned CapturingTransporter double (glm29-12, the
  OpaqueAuthentication pattern) replaces the byte-identical anonymous
  transporter classes in both request-mapping suites.
- The plugin boot facts ride the loadPlugin() owner (glm29-13):
  ZAI_PLUGIN_FILE/ZAI_PLUGIN_BOOT plus loadZaiPlugin()/
  bootZaiPluginAndInit() replace eleven spellings of the fact across
  eight files — the private copies had already drifted (the same
  bootPlugin() name meant load-only in three suites and load+init in
  the fourth).
- The debug hook's equality check calls the declaring
  AbstractPlanRegionSettings owner (glm29-14, the glm21-12
  reach-through pattern), not the concrete first-surface child whose
  rename would fatal the update_option hook for both surfaces.
- The wpdb::get_col stub fails loud on unrecognized queries (glm29-15):
  the silent array() answer let negative assertions pass vacuously on
  query drift; an unrecognized shape throws with the query named.

### Accepted (GLM29 round)

- normalize_empty_object_members() does not descend stdClass nodes
  (glm29-6, the round's one PLAUSIBLE finding — mechanism confirmed,
  production reachability refuted): a HAND-BUILT mixed tree embedding a
  stdClass node with a hand-written `[]` at a schema position ships
  that `[]` verbatim, but both schema entry points type-gate the root
  to array, the realistic json_decode'd embed needs no normalization
  (`{}` arrives as stdClass already; its `[]` members are
  genuinely-declared lists — converting them would misread the
  caller's JSON), and no production writer builds the hand-cast form.
  Ledger-recorded with the reachability argument; the boundary is
  pinned both directions.

### Fixed (zai / M2 — GLM28 round, /code-review max)

All 16 actionable items of the round-28 max review (the 28th review
round on this branch; the finder fleet's six remaining UNVERIFIED
candidates were adversarially verified FIRST per the round's own
protocol — four CONFIRMED and fixed, two REFUTED and ledgered;
is_object_shape stays on the deferred list by disposition), one commit
per item:

- A trailing comment in a `use` statement no longer poisons the
  unused-import verdict (glm28-1 + glm28-22, the round's one confirmed
  scanner defect): the single-form scanner derived the qualified name
  from the REAL statement bytes, so a legal trailing comment rode into
  the short name — a string that appears nowhere else, flagging a
  genuinely used import (verifier repro: return type + `new
  Request()`). The name derives from the MASKED match text now (the
  security verifier's pass caught the first fix's real-bytes cut
  regressing the keyword-to-name comment position — `use /* note */
  Dead;` silently skipped); every comment position derives the clean
  name, the flag message prints it (pinned through a child-process
  STDERR capture), and the dead-import shapes keep flagging.
- Present-but-wrong-type stream members flag instead of silently
  dropping (glm28-2, extends glm26-3): non-string content/
  reasoning_content, non-array tool_calls/delta/choices, and the
  tool-call fragment's own members all skipped silently while the
  stream completed clean — the harmful repro was `"arguments":123` as
  the only arguments fragment fabricating a successful no-argument
  call. Every guard joins the present-wrong-type rule (flag + skip);
  absent/null keeps the absent-semantics skip, the ''-string fragment
  keeps its merge-nothing skip, and the choices rule lands pre- AND
  post-sentinel identically.
- Zero-part completions reject typed instead of poisoning the history
  (glm28-4, the GLM3 #1/GLM5 #4 parity the Anthropic twin has enforced
  since those rounds): a role-only delta stream, a contentless choice
  message, or a thought-only turn parsed as a successful generation
  whose empty assistant Message replayed as
  `{"role":"assistant","content":[]}` (verifier-confirmed end-to-end
  against the vendor mapper), poisoning every later request of the
  conversation. One guard after the parent parse covers all three
  transports; the empty-STRING text tolerance is kept and pinned.
- normalize_base_url() strips suffixes to a fixpoint and collapses
  interior doubled slashes (glm28-5, latent hardening): the single
  ordered pass left a doubled suffix half-standing (longest suffix per
  iteration now, so a shared-tail suffix cannot eat half of a longer
  one's compound). The ExactlyOnce resolver pins are consciously
  superseded to the LosesThemAll contract.
- The credential gate rides raw_request_authentication() directly
  (glm28-6, supersedes glm18-14's delegation shape): after the
  delegation merge, two hook names shared one body on both surfaces
  and every new surface had to keep them identical. One abstract (the
  name SpeaksAnthropicMessagesProtocol already standardizes); the
  zai_anthropic delegation is deleted, the zai surface gains the
  one-line raw hook.
- The duplicate tool_use-id check relies on the callee's contract
  (glm28-7): the re-validation guards were dead (parse_content_block()
  throws on any bad id before its tool_use return) and the adjacent
  comment was stale; both fixed.
- SseFrameBuffer compacts at one site (glm28-8): the trailing
  last-frame compaction block was byte-identical to the entry check
  and behaviorally redundant for every interleaving.
- parse_decoded_message reads its own parameter (glm28-9): the
  `$raw = $raw_body` rebinding serviced the glm24-7-deleted mirror
  and survived it as a pure no-op.
- One depth-zero split walk serves every split family (glm28-10, the
  glm20-10 matcher precedent): four structurally parallel hand-rolled
  split loops (group-use commas, runtime-segment dots, map-literal
  commas and '=>') now ride wp_connectors_depth_zero_spans()
  (parameterized depth classes and a byte-length cut predicate);
  every walk's existing fixtures pass unchanged.
- One shared skeleton for both live smoke tests (glm28-11, the
  Abstract*MappingTestCase pattern): the two opt-in twins rode the
  same ~40-line scaffold line-for-line and neither runs in CI. The
  glm25-6 lockstep pin is superseded to the new shape (derivations
  pinned once in the base; each twin pins its owner-class hooks).
- The probe's credential ladder is one named helper (glm28-12):
  resolve_probe_authentication() owns the ~70-line
  empty/opaque/funnel/fallback resolution unchanged; probe() is a
  flat read-then-verdict method.
- The tool_use input member is judged by one presence check
  (glm28-13): three property_exists evaluations and two flag branches
  collapsed to one condition class (absent-or-null), verdicts
  byte-identical.
- One per-assignment proof body serves both hidden-include depths
  (glm28-15, the corrected verified shape — naive recursion was
  refuted: it memory-fatals on variable cycles and flips the 3-hop
  verdict): wp_connectors_assignment_value_reasons() owns the body and
  re-enters only from depth 0; the TWO-LEVEL cap is documented and
  pinned as the cycle guard; all three reason wordings preserved.
- The usage-rejection wording is reject()'s private detail
  (glm28-16): the REASON_* constants and message_for_reason() served
  only reject()'s internal routing. Privatized; the two direct-call
  test assertions superseded behaviorally through the public
  rejection channel.
- The JSON-output guidance builder rides one shared trait
  (glm28-17, the StashesGenerationPrompt precedent):
  Support\BuildsJsonOutputGuidance owns the byte-identical lazy-init
  block; the glm23-2 memo reflection pin survives the trait
  flattening unchanged.
- Two diagnostic-wording drifts name their real mechanisms
  (glm28-19+20): the replay-guard bound compare is PHP's numeric-
  string comparison (not lexical — both floors decide exactly,
  verified), and the encode-rejection template names the
  JSON_ERROR_DEPTH cause.

Two round-28 candidates were REFUTED on verification and ledgered:
the trailing finish_reason type gap (no pre-sentinel asymmetry
exists; verbatim storage is the documented pinned design whose
outcome is byte-identical either way) and the generation_url() alias
deletion (the delegator is the cost of the glm15-4 cross-surface
probe contract; deleting it re-opens what that round closed).
is_object_shape() stays on the ledger deferred list by disposition.

A two-lens verifier pass over the full diff (independent security +
correctness agents) found ONE confirmed defect — glm28-1's first fix
regressed the keyword-to-name comment position in `use` statements
(`use /* note */ Dead;` silently skipped a dead import the pre-round
scanner flagged) — fixed as glm28-22 (the name derives from the
masked match text; every comment position derives the clean name).
Everything else held under their evidence: a 4000-stream
merge_tool_calls fuzz (byte-identical accumulated state; every flag
difference a verified new present-wrong-type catch), a 21-shape
tolerance battery (zero flags on legitimate shapes), the
malformed-event/zero-part/fallback precedence battery, a pre/post
non-streamed model differential (8 legitimate shapes byte-identical,
5 poisoning shapes now typed), a 3996-URL normalize_base_url corpus
(every difference in the documented direction; clean cells
byte-identical), old-vs-new differentials over the split walks and
the hidden-include resolution (byte-identical verdict/reason lists),
SseFrameBuffer interleavings, the probe ladder's six wiring shapes,
and pin mutation tests (glm28-15's cap, glm28-17's composition, and
glm28-11's lockstep pins all non-vacuous).

Suite: 1211 tests / 29718 assertions (round baseline 1193/29652),
green in default AND --order-by=random order.

### Fixed (zai / M2 — GLM27 round, Codex R21)

All 3 findings of Codex review round 21, one commit each, plus one
verifier subagent over the full diff (ALL CLEAR — zero confirmed
defects; writer-exhaustiveness traces, a 24-shape scanner pre/post
differential, marker/memo flow repros, and schema-walk cross-checks):

- Cached model rows must carry recognized chat-model IDs (glm27-1):
  the glm23-7 all-string soundness rule still accepted rows whose
  every entry cannot map to metadata — array(''),
  array('unknown-model') — so cached_ids() skipped discovery while
  map_from_ids() filtered every entry out, leaving both directories an
  EMPTY catalog for the 12-hour TTL with no probe and no fallback.
  Soundness rides the one map rule (id_maps_to_metadata()); every
  in-repo writer caches already-chat-filtered parse output, so no
  legitimate row is excluded.
- Mixed group-use typed members parse in the import scanner (glm27-2):
  `use Vendor\Pkg\{function helper, const FLAG, Widget};` members
  failed both member regexes and were silently skipped — an unused
  typed member reported no violation. The optional kind prefix is
  stripped before the alias/name parse (it never affects the short
  name); the alias form works.
- dependentSchemas entries normalize as subschemas (glm27-3): the
  keyword joins the object-map list, so a dependent's empty schema
  encodes as {} (never []) exactly like properties/patternProperties/
  definitions/$defs — a strict JSON Schema validator can no longer
  reject an otherwise-valid tool definition for the shape.

Suite: 1193 tests / 29652 assertions (round baseline 1188/29636),
green in default AND --order-by=random order.

### Fixed (zai / M2 — GLM26 round)

All 12 ledger-filtered findings of code-review round 26 (high; the
26th round on this branch — one genuine user-facing availability bug,
one protocol-strictness gap, one parity gap, one latent refactor
hazard, and eight dedup/simplification items), one commit each, plus a
two-lens verifier pass (independent security + correctness agents over
the full diff; ZERO confirmed behavioral findings — two doc-drift
items fixed as glm26-13 — with every equivalence claim holding under
their differentials: a 46,656-sequence stop-rule differential, 642,235
exhaustive plus 200,000 random answer-window histories, a 20,000-id
chat-membership fuzz, A→B→A cache-content sequences with mid-process
plan/region retargets, binding-poisoning repros for every wiring
shape, and byte-identity checks over both surfaces' usage rejections).
Three review findings were dropped as ledger-covered re-flags
(glm21-2, GLM3 #14-tenant, GLM1 #15's drop tolerance):

- Uncarriable credential material is a definitive INVALID verdict
  (glm26-1, the round's one user-facing bug): a z.ai Anthropic key
  pasted with a trailing newline or comma threw the glm16-13
  pre-transport rejection inside the probe, and the probe's blanket
  catch(Throwable) converted it to INCONCLUSIVE — isConfigured() kept
  answering true through key-save validation (the card showed
  connected), every generation then 500'd with no persisted verdict.
  The rejection now rides UncarriableCredentialException, a marker
  subclass (the FixedMessageResponseException pattern: identical
  message, family, and ErrorMapper 500 mapping), and the probe
  catches it narrowly: nothing flew, the verdict names exactly the
  credential that could not (the glm13-1/glm14-5 one-credential-
  flies-AND-binds discipline), region distrust settles, and the
  refusal gate answers before the pre-transport throw. The glm16-13
  rejection itself is untouched.
- An out-of-order content_block_stop flags the Anthropic stream
  malformed (glm26-2): the wire serializes block lifecycles, so a
  stop for N while a lower index is still open (a corrupting proxy's
  interleave) is the same corruption class its start-side twins
  reject — previously it aggregated a protocol-impossible completion
  with no malformed flag. Pinned both ways (the interleave repro
  fails typed; the serialized control still aggregates); the
  aggregator's drop comment also stops overclaiming model-parse
  parity for unknown block types (the glm19-13 class).
- A non-string streamed delta.role flags the zai stream malformed
  (glm26-3, GLM9 #2 parity with the Anthropic twin): the vendor
  parent coerces any non-'user' role into a model message, so the
  corrupt chunk completed as a clean generation. An explicit null
  keeps the historical absent-semantics skip.
- The zai_anthropic directory's discovery auth-reader rides the raw
  hook (glm26-4, glm16-1 alignment): the wrap reader held the
  glm14-5 cross-credential-poisoning discipline only by
  call-ordering accident. Byte-identical today (the flight keeps the
  one wrap funnel); the discipline is structural, pinned by a
  superseded glm21-14 pin, a wrap-return prohibition, and a new
  opaque-wiring behavioral test.
- The region-pending settle rule lives in one helper (glm26-5): the
  three hand-copied settle sites (isConfigured's fresh-verdict and
  post-probe branches, record_definitive_verdict's current-endpoint
  guard) ride settle_region_pending(); the phpstan classConstant
  count drops 13→11 exactly by the two consolidated reads.
- One discovery-consult orchestrator serves both directories
  (glm26-6): ZaiDiscoveryCache::resolved_map() owns the endpoint
  resolve → cache id → cached_ids → memoized_map skeleton both spelled
  inline — GLM4 #10's 'one orchestration' claim is now true at the
  composition layer too. The map memo keeps the filtered id list and
  proves unchanged content by a strict list compare instead of
  re-deriving the digest (still content-keyed under any transient
  mutation); the option and transient reads stay per-consult by the
  glm15-6 boundary. The prebuilt seed stays eagerly evaluated
  (verifier-corrected comment); the GLM8 #11 owner-call pin and the
  glm15-22 seed pin are superseded at their sites.
- UsageValidator::reject() owns the usage-rejection throw (glm26-7):
  the composition hand-copied in the Anthropic parse block and the
  zai model's reject_bad_usage() lives beside the rules and messages
  it applies; both models pass only their varying arguments, and a
  lockstep pin forbids any inline recomposition.
- Directories state their availability pairing through one hook
  (glm26-8): the buried inline 'new' at three gate sites was the
  glm24-1 pairing-swap class; a protected availability() hook (the
  models' credential_gate_availability() shape) plus a behavioral
  lockstep pin tying each hook's product to the registry row's
  settings class.
- The closed-lifecycle state lives on the Anthropic block accumulator
  (glm26-9): the $stopped_indexes parallel map (the GLM10 #7
  hand-synced-mirror shape) collapses into a per-block 'stopped'
  member across all four sync sites.
- The answer-window verdicts derive from the outstanding-IDs map
  (glm26-10): the $awaiting_answer mirror, the $opens_tools flag, and
  the file's only by-ref parameter are gone — identical verdicts on
  every reachable state (verifier-exhausted).
- The zai aggregated() gate reads the choices emptiness alone
  (glm26-11): $event_count's only production reader was subsumed by
  the choices disjunct beside it; the field, its increment, and the
  disjunct are deleted, consciously superseding glm19-11's
  load-bearing-fields note (GLM10 #4 lesson) — the four reflection
  pins that counted the field are superseded by the behavioral
  assertions that implied them.
- is_chat_model() answers from a once-built flipped set (glm26-12):
  the per-ID loops stop re-merging the three constant catalogs per
  call; the membership rule is unchanged.

Suite: 1188 tests / 29636 assertions (baseline 1179/29603), green in
default AND --order-by=random order.

### Fixed (zai / M2 — GLM25 round)

All 11 findings of code-review round 25 (high, ledger-filtered; the
25th round on this branch — zero correctness findings again, all
cleanup/drift-risk), one commit each, plus a two-lens verifier pass
(independent security + correctness agents over the full diff; 1
confirmed finding — the round's own glm25-9 stat-guarded head memo
served a stale head on same-second same-size rewrites, security-lens
repro 30/30, excised as glm25-12 — with every other production
change holding byte-identical behavior under the verifiers'
differentials: a 22,757-sequence SSE prefix-machine differential, a
60,000-case fuzz of the same, a two-tree discovery-seed write
differential, a refusal-gate decision matrix including the empty
ApiKey key, byte-compared tokenizer views over 62 real files and 12
hostile fixtures, and mutation-tested pins catching every revert
shape they name):

- The discovery seed stores its row through the cache owner
  (glm25-1): the availability base's probe seed hand-synced its own
  set_transient() for the 12h positive discovery row, a second writer
  of a row ZaiDiscoveryCache owns while the row contract had already
  evolved once on the reader side (glm23-7). ZaiDiscoveryCache::
  store_ids() is the one positive-row store (the discovery flow's own
  write routes through it), and a tree-scan pin holds that every
  set_transient() under connectors/zai/src outside the owner is the
  availability base's single probe-miss marker write.
- The refusal gate and message builder go private (glm25-2): the
  predicate's public default-null path resolved an effective_key()-
  based verdict bypassing the wrapper's ApiKey-shape skip (the GLM3 #9
  gate-divergence class) and had no production caller. Tests ride the
  production paths now — gate decisions through the wrapper with
  explicit credentials, the fixed wording through the refuse_
  generation() throw both model surfaces ride — plus a new pin that
  foreign wiring skips the THROWING wrapper too.
- The generation-prompt stash rides one shared trait (glm25-3):
  Support\StashesGenerationPrompt owns the encodability attribution
  walk's stash (the MemoizesToolLoopVerdicts precedent for the
  parents-differ shape); each suite pins the composition, the absent
  hand-synced declaration, and the assignment at the params build.
- The Messages non-streaming parse collapses to one hop (glm25-4):
  the parse_message_body()/parse_body_string() chain stranded after
  glm15-7/glm16-8 moved the JSON fallback to the shared owners; the
  decode inlines at the sniff else-branch (the zai twin's shape), and
  the glm15-7 one-decode pin's per-surface substring is superseded at
  its site to name each surface's own decode plus the shared
  JsonFallbackResult funnel both fallbacks ride.
- The no-BOM branch drops its dead settle assignment (glm25-5) in
  SseFrameBuffer::feed(): zero statements stood between it and the
  unconditional settle; the comment states the shared transition.
- The live-smoke lockstep pin covers both twins (glm25-6): the zai
  smoke test still hand-stringed its option literals and provider id
  where the pin scanned only the Anthropic twin — offline check
  green, then the next live run writing options nothing reads. Both
  files ride owner constants; the pin loops both with per-surface
  owners.
- The endpoint test rides the settings constants (glm25-7), matching
  its Anthropic twin — a renamed option now fails at the write, not
  on a URL mismatch naming nothing about the stale literals.
- One tokenizer provider serves both conventions checks (glm25-8):
  the self-containment analyzer and the unused-import scanner each
  tokenized every connectors/*.php file (two full token_get_all
  passes per check, four per gate run). wp_connectors_file_code_
  views() computes the (source, code, masked) triple once per file
  CONTENT (path + md5 key — sound under rewrite by design, no mtime
  granularity), with the unreadable return caller-owned as before.
- The version check rides the caller's main-file scan (glm25-9):
  version_constant_violations() accepts the pre-scanned $mainFiles
  (the main_file_violations() idiom, rescan-when-empty fallback), and
  the 8 KB head reads ride one uncached owner,
  wp_connectors_plugin_file_head() — deliberately unmemoized after
  the verifier round (glm25-12): the stat-guarded memo first added
  here served a stale head for same-second same-size rewrites while
  saving only page-cached reads.
- Every Anthropic /models success body rides the factory (glm25-10):
  four hand-rolled minimal bodies replaced by HttpResponseFactory::
  anthropicModelsBody() — the glm15-20 drift class, closed on this
  surface.
- The declared-constants walk is one harness helper (glm25-11):
  WpConnectorsTestCase::declared_constants() (the aggregator_state()
  precedent) replaces five hand-rolled copies backing the base-vs-
  child constant lockstep pins.

### Fixed (zai / M2 — GLM24 round)

All 9 findings of code-review round 24 (high, ledger-filtered; zero
correctness findings — all drift-risk/maintenance), one commit each,
plus a two-lens verifier pass (independent security + correctness
agents over the full diff; 1 confirmed finding — the round's own new
slug pin was vacuous against the verbatim revert it named, fixed as
glm24-10 — while every production change held byte-identical behavior
under the verifiers' differentials: a 200k-sequence fuzz on the
has_json derivation, a 13-shape truth table on the content probe, and
byte-identical scanner violation lists on hostile fixtures and the
real tree):

- The live probe states no availability class (glm24-1): the facts
  table's 'availability' column was the one probe fact no lockstep pin
  covered — a pairing swap wrote surface A's key option and deleted
  surface B's validation state while generation ran on A. The column
  is gone: KEY_OPTION/STATE_OPTION are constants the availability
  layer itself aliases from the settings class the registry row
  already carries, so the probe reads both through
  $surface_facts['settings']:: and ZaiSurfaceLockstepTest pins the two
  derivation statements plus a word-bounded zero-restatement scan
  (per provider, through its own availability() factory).
- Both card names alias the settings layer's PROVIDER_LABEL (glm24-2):
  ZaiAnthropicProvider::PROVIDER_NAME and the zai twin's
  provider_display_name() were hand-mirrored literals with each side's
  tests pinning only their own copy — a one-sided rename left the
  Connectors card and the settings header naming one connector two
  ways (the per-side-pin vacuity glm21-18 documented). Both surfaces
  ride the constant-expression alias now (the PROVIDER_ID/CACHE_SCOPE
  pattern), pinned through provider_metadata_args() on both surfaces.
- The auth rejection messages ride the REFUSAL_LABEL chain (glm24-3),
  closing the glm19-5/6 "next label pass" deferral: the uncarriable-
  credential and wrap() refusal messages interpolate a private
  PROVIDER_LABEL (byte-identical output), so a CACHE_SCOPE rename no
  longer strands ErrorMapper's admin-facing 500 text naming a provider
  id that exists nowhere else. The verifier round (glm24-10) hardened
  the pin to name the verbatim-revert shape — the positive
  sprintf-idiom count plus the mid-string sentence-prefix and
  standalone-slug prohibitions — and both behavioral pins ride
  REFUSAL_LABEL-derived expectations.
- The tool-input presence flag derives from the accumulated JSON
  (glm24-4): the per-block 'has_json' boolean duplicated ('' !== json)
  on every reachable state (closed write set: an '' init plus one
  string-gated append; fuzz-verified), the GLM10 #7 hand-synced-mirror
  drift class. The read site derives; the stored field is gone.
- start_block() drops its five dead null-raw_block conjuncts
  (glm24-5): $type derives from $raw_block and the null-type early
  return settles null-ness, so the tool_use branch and the four
  accumulator member reads could never see a null raw block — the
  conjuncts advertised a null path that cannot reach them.
- The self-containment analysis rides one masked view per file
  (glm24-6): the write-shape and assignment helpers re-tokenized the
  whole file through the string masker on every consult although the
  per-file driver had computed the identical view; the driver's one
  pass is threaded down through the collector, runtime-segment,
  hidden-include, and array-literal helpers. Measured on connectors/
  zai: 63 mask passes / 861 KB → 55 / 742 KB per scan, identical
  verdicts.
- The decoded-message content probe states its order plainly
  (glm24-7): property_exists first (truth-table-identical over a
  13-shape battery — the ?? null existed only to keep the inverted
  order safe), and no $raw_content_ok mirror — a non-null raw decode
  implies a present-array content member past the entry gates.
- effective_key() states its first-rung resolution directly (glm24-8):
  array_key_first() over the owner-ordered non-empty ladder replaces a
  foreach that returned unconditionally on its first iteration — a
  loop silhouette implying rung iteration mattered on a
  credential-resolution path.
- The uninstall ladder collection rides array_values() (glm24-9): the
  foreach-append only discarded the source labels the owner keyed the
  rungs by; the glm13-15 one-occurrence pin keeps holding.

### Fixed (zai / M2 — GLM23 round)

All 15 findings of review round 23 (high, ledger-filtered), one commit
each, plus a two-lens verifier pass (independent security + correctness
agents over the full diff — zero confirmed findings; the security lens
refuted 12 candidate classes, the correctness lens attacked the SSE
window with a 30-case battery and the stubs against WP 6.8 core):

- A data-less content-block declaration is a CUT frame, not noise
  (glm23-1): a content_block_delta frame whose data: line an
  intermediary cut left NO downstream trace (deltas carry no lifecycle
  marker) — the verifier-reproduced silent-truncation hole in the Codex
  R4 #3 invariant. The verdict is a ONE-FRAME completion window, not an
  immediate flag: the split wire form (declaration and data-only
  carrier as consecutive frames — the GLM1 #14 bare-event tolerance the
  suite's fixtures pin) reunites and is judged as if the declaration
  rode its carrier's frame, inheriting every downstream corruption
  rule; a declaration whose next frame is anything else, or still
  pending at finish(), classifies through the one flag_corrupt_event()
  site (the GLM12 #15 pin grows to six).
- The dropped outputSchema embeds guidance on zai, from one shared
  builder (glm23-2): a configured schema under a non-JSON mime flew
  with NO constraint on the zai surface (the SDK parent drops
  response_format outside application/json; the Codex R1 #4 remedy
  existed on the twin only). The guidance sentences and the glm21-7/16
  memoized schema encode ride the new Support\JsonOutputGuidance owner;
  the zai surface embeds through a prepareMessagesParam() override
  scoped exactly to the dropped case the glm14-1 guard scopes. The
  finding's leave-the-mime-unset shape is unreachable through the
  public setters (the vendor setOutputSchema() auto-promotes a null
  mime to application/json); the constructible dropped case names a
  non-JSON mime explicitly. Byte-identical parity pinned cross-surface.
- A register_argc_argv=0 probe run walks the usage path, not a fatal
  (glm23-3): the argv pre-scan and getopt()'s false return both
  normalize, so the unpopulated-argv run stops at the key lookup with
  its named exit-2 diagnostic instead of a strict-types TypeError
  fatal (empirically unset in docker php:7.4-cli/8.3-cli; builds newer
  than the platform ceiling always populate argv). The two guard reads
  ride count-pinned phpstan ignores with the docker evidence recorded.
- The enum renderer resolves label overrides on the child (glm23-4):
  render_enum_field()'s label call rode self:: (early binding) while
  every other extension point on the same lines rode static:: — pinned
  with an overriding-child fixture rendering both fields.
- DEFAULT_PLAN is child-owned like every per-surface identifier
  (glm23-5): the base's inheritable 'coding' default was the one
  genuinely per-surface value ('coding' vs 'general') escaping the
  GLM6 #12 child-owned-identifiers reflection pin; the base declares
  none, both children own theirs, the pin's list covers it.
- An undecodable zai data frame flags, restoring the channel's claim
  (glm23-6): the aggregator docblock claimed malformed JSON events are
  "flagged via has_malformed_event() and skipped", but the flag covered
  index corruption only — glm19-11's counter deletion left the claim
  describing nothing. The UNDECODABLE shape flags in both phases now
  (the flag half of GLM7 #2's "malformed ones still counted");
  json_last_error() keeps decodable non-array payloads at their
  non-event skip. Two GLM7-era assertions encoding the silent skip are
  superseded at their sites.
- A corrupt discovery row is a cache miss, not an empty catalog
  (glm23-7): is_array() alone validated the 12h transient — a corrupt
  or foreign row served verbatim and reported an EMPTY catalog with no
  probe for the full TTL. A sound row is a NON-EMPTY all-string list
  (both surfaces' discovery rejects the empty list, glm13-2); anything
  else reads as a miss and the probe/fallback paths run as for an
  absent row.
- The update_option stub short-circuits unchanged values first (glm23-8)
  and fires the core hook order and arity (glm23-9): core returns false
  BEFORE any autoload handling on an unchanged value (the old
  `null === $autoload` condition let an unchanged save with an explicit
  autoload argument rewrite the row and return true), and fires
  generic 'update_option' first pre-write, then the specific hook with
  THREE args, then 'updated_option' — verified against WP 6.8 core;
  pinned with an order/arity/pre-write assertion over all three hooks.
- Harness: bootedCorePromptBuilder() stores and wires ONE key (glm23-10
  — two fresh FakeSecrets draws left the stored row matching no
  credential that flies, so any later database-key path read an
  unsettled binding); wiredZaiSurfaceModel() owns the model wiring,
  class-parameterized with the surface row derived from the ZaiSurfaces
  registry by PROVIDER_ID (glm23-11); bootedCorePromptBuilder() derives
  its surface facts from the same registry — a third surface boots with
  no harness edit, the one protocol-specific fact (the models body)
  became the caller's parameter (glm23-12).
- The uninstall class-free literals ride a lockstep pin (glm23-13): a
  settings owner constant rename left every uninstall run deleting
  names nothing stores while the suite (planting the same literals)
  stayed green; the pin derives the expected set from ZaiSurfaces plus
  the owners' constants and asserts the uninstall SOURCE.
- The tool-loop memo machinery rides one Support trait (glm23-14):
  the note/prune/replayable trio plus the three SplObjectStorage stores
  were byte-identical between the models (the memo-rule fixes already
  landed twice — glm21-17, glm22-3); Support\MemoizesToolLoopVerdicts
  owns them now, the per-surface encode halves (TRUE verdict vs encoded
  string) stay by design, and the reflection pins read the
  trait-flattened properties unchanged.
- The unit-interval rule's range phrase rides the call site (glm23-15):
  reject_out_of_unit_interval() is a one-protocol rule whose message
  hardcoded the protocol name while living in the protocol-neutral
  shared guard; the range phrase is the caller's parameter now (the
  PROVIDER_LABEL pattern), wire message byte-identical.

### Fixed (zai / M2 — GLM22 round)

All 15 findings of review round 22 (high, ledger-filtered) plus the two
verifier-round confirmations, one commit each:

- The conservative walker recurses into every object and judges the
  DECODED WIRE FORM (glm22-1 + glm22-16): has_out_of_range_integer_float()
  recursed only into arrays and stdClass, so a caller-built plain value
  object carrying an out-of-range integral float shipped to the wire
  byte-identical to its rejected array/stdClass twins
  ('{"count":9.3e+18}') on both surfaces' outbound replay channels —
  the glm12-8 poisoning class (empirically reproduced end-to-end). The
  verifier round then demonstrated that no property-graph walk is exact
  for every encodable class: a JsonSerializable's cyclic property graph
  fatals the walk uncatchably while its clean serializer output encodes
  fine, and an ArrayObject's dynamic property never ships yet rejected
  while its STORAGE ships ('{"x":9.3e+18}') yet passed. The walk judges
  the decode of the oracle's own phase-one encoding — literally the
  values that ride the wire — with a cycle-guarded misuse backstop for
  direct object handoffs; decode products and plain arrays/stdClass
  keep identical verdicts.
- '+' joins the plain-integer scan's adjacency guard class (glm22-2):
  JSON's only '+' is the positive exponent sign, so the digit run after
  'e+' is always the exponent of a float token — the plain scan read
  0e+9223372036854775809 as the standalone integer …809 and rejected an
  exact zero (decodes to 0.0, re-encodes stably) as precision loss
  while its e- and unsigned twins replayed, typed-rejecting a valid
  generation on the zai inbound parse hook and flagging the Anthropic
  streamed tool block as malformed input JSON. Pinned at the rule, on
  the zai inbound channel, and on the streamed channel.
- The zai surface memoizes the tool-result encodability verdict
  (glm22-3) and interposes the replay-verdict memo through the $oracle
  hook (glm22-4): the glm21-4/glm21-5 identity-keyed memos and the
  glm21-17 build-set sweep, ported whole from the zai_anthropic twin —
  a K-turn tool loop replaying the full conversation every request paid
  O(K²) guard encodes and O(K²) oracle serializations it no longer pays
  (the vendor parent's own shipping encode is structural and stays).
- Both endpoints derive CANONICAL_BASE_URL from their MATRIX cell
  (glm22-5, value-identical, PHP 7.4-legal constant expression) — the
  second literal could drift from a MATRIX migration with no failing
  test.
- The protocol-independent mapping members ride shared surface bases
  (glm22-6): the transport-failure test, three data providers, and
  nine config-option rejection wrappers were byte-identical copies
  across the suites pinning the SHARED guard layer; one harness-owned
  abstract base per suite pair executes one copy per surface. The
  drifted maxTokens pins (0 and -5 zai-side, 0 only Anthropic-side)
  unified: BOTH values now execute on BOTH surfaces — coverage strictly
  grew (test count unchanged, assertions up).
- corePromptBuilder() consolidates on the harness's
  bootedCorePromptBuilder($slug) (glm22-7, skip-first ordering
  preserved); wiredZaiModel() ports the glm20-11 wiring consolidation
  to the zai surface (glm22-8, six inline copies rewired; the two
  deliberately-unbound factory calls stay); the discovery-priming twins
  parameterize onto one helper with one-line delegates (glm22-9, the
  glm15-12 composition pin is exactly one now); the opaque
  identity-passthrough auth double is one harness-owned class
  (glm22-10, was five anonymous spellings under three names); the
  model-directory fixture folds into directory(?string $key)
  (glm22-11); the availability/directory wiring rides one
  class-parameterized wiredZaiSdkInstance() (glm22-12).
- Fixed-length strncmp replaces the strpos prefix probes in the SSE
  sniff and the BOM tests (glm22-13; byte-identical verdicts, fuzzed
  at 60k×6 locally and 3M pairs by the verifier — ~6 whole-body scans
  per non-streaming response parse removed); the redundant
  content_block_start pre-check defers to start_block()'s own rejection
  (glm22-14, observationally identical across every member state); the
  array-literal walk's duplicated blanking closure is one named helper
  (glm22-15, deliberately not the token-aware masker).

### Fixed (zai / M2 — GLM21 round)

All 15 findings of review round 21 (high, ledger-filtered), one commit
each:

- The frame buffer strips the sniff's plain leading-whitespace prefix
  (glm21-1): EventStreamSniff ltrimmed the body it routed while
  SseFrameBuffer returned a BOM-less body unchanged, so a
  sniff-accepted stream whose first field line carried a leading
  space/tab matched no column-0 field and dropped its first frame
  silently. Empirically reproduced: a whitespace-prefixed lone
  'data: [DONE]' lost the OpenAI sentinel (the appending gateway's
  post-sentinel frame then merged as pre-sentinel content, mutating a
  completed generation), and a whitespace-prefixed 'event: error' with
  an undecodable payload was swallowed whole on the Anthropic surface
  (no error flag, no malformed flag). The one canonical prefix rule
  strips the plain run on both layers now (the sniff's private ltrim
  is gone); the strip stays stream-start only, so mid-stream
  whitespace keeps its spec-strict unknown-field meaning, and the JSON
  decode paths extend the same gateway-prefix tolerance to
  NUL/vertical-tab prefixes. The GLM8 #2-era "ws-prefixed first frame
  drops, master-identical" pin is consciously superseded (the GLM10 #4
  lesson; documented at the rewritten pins and in the ledger).
- Region-switch distrust under a persistently-inconclusive /models is
  a consciously-accepted residual (glm21-2, documentation only): under
  the region-pending flag an INCONCLUSIVE probe reads disconnected —
  including the 200-body classes glm13-2 made inconclusive (an empty
  data list, an incomplete has_more page, a non-JSON body) that
  master's status-only probe blessed as connected. The residual cost
  (the connector not-configured and generation refused until the
  endpoint answers definitively or the admin intervenes; probes
  throttled to one per 60s window) is recorded in the ledger and at
  the isConfigured() branch: SPEC §3.3 wins over the empty-2xx master
  delta.
- A broken-install uninstall leaves the discovery transients standing
  (glm21-3, documentation only): the class-based discovery sweep is
  gated on the eight-file owner chain (GLM8 #15 fatal-avoidance), and
  the broken-install fallback literals cover only the class-free
  options and the key-state probe-miss prefixes. On a broken install
  the 12h discovery transients (zai_connector_zai_models_&lt;md5&gt;
  and the zai_anthropic twin, plus their '_miss' markers) survive —
  invisible to the wp_options enumeration on object-cache installs —
  so a reinstall inside the TTL window is served the stale
  pre-uninstall catalog. Recorded as a ledger tradeoff: the ids are
  derivable only through the endpoint classes, and a literal formula
  mirror in uninstall.php is the drift class GLM8 #11/GLM9 #8 removed
  twice.
- Historical tool-result responses encode once per conversation
  (glm21-4): every request build re-ran JsonEncodeGuard::encode() on
  each historical tool result's response value, so a K-turn tool loop
  replaying the full conversation paid O(K^2) cumulative encodes of
  unchanging values (often large scraped/JSON payloads). The vendor
  FunctionResponse DTO is immutable, so the encoding is a pure
  function of the DTO: an identity-keyed SplObjectStorage memo (the
  tool_schema_memo precedent) encodes each DTO once — byte-identical
  first-run wire content, rejections never memoizing. [The memo's
  release bound is the completed build's mapped tool-DTO set since
  glm21-17 — see the verifier round below.]
- Caller-built tool calls run the replay oracle once per conversation
  (glm21-5): unstamped FunctionCalls (every rehydrated
  toArray()/fromArray() conversation included — the GLM12 #12 stamp
  does not survive the vendor round trip) re-ran the full
  ToolArgsReplayGuard oracle on every request for all history. An
  identity-keyed verdict memo runs the full oracle on first sight and
  serves the repeat; rejections never memoize (pinned with glm19-1's
  1e23 REJECT class across two consecutive builds).
- temperature and top_p ride one shared unit-interval rule
  (glm21-6): the NAN/closed-interval [0,1] guards were copy twins
  differing only in getter and member name — a bound tweak edited on
  one member only would validate the two members of one request
  against different ranges. RequestShapeGuard's label- and
  member-parameterized rule serves both call sites with byte-identical
  messages.
- The configured outputSchema encodes once per schema value
  (glm21-7): the schema is a config member that never changes across a
  structured-output agent loop, but every request build re-encoded the
  whole schema into the system guidance for identical bytes. The
  tool_schema_memo compare pattern (config identity + value compare)
  memoizes the encoded STRING only — the translated guidance sentence
  stays per-call. [Refined by glm21-16: the memo serves OBJECT-FREE
  graphs only — see the verifier round below.]
- One shared outbound replay rejection serves both surfaces (glm21-8):
  the stamp skip, the serializing oracle, and the typed pre-transport
  rejection were near-verbatim twins with wording already drifted
  ('tool call arguments' vs 'tool arguments'). One block on
  ToolArgsReplayGuard (label-parameterized, with an optional oracle
  callable so the zai_anthropic verdict memo interposes without
  forking); the anthropic wording joins the zai surface's more precise
  'tool call arguments' (three pins updated).
- The absent-total derivation rides the validator's member sum
  (glm21-9): derive_absent_total_tokens() hand-rolled the
  overflow-checked prompt+completion sum that UsageValidator owns —
  an overflow-rule change could reach one copy only. sum_members() is
  public and the derivation rides it; no behavior change.
- The live probe derives its surface pairing from the registry
  (glm21-10): the probe's map restated the SDK-free settings/endpoint
  pairings ZaiSurfaces owns and hardcoded the CLI whitelist, while the
  lockstep test only substring-pinned class names — a pairing swap
  between rows kept every assertion green. The map is built from
  ZaiSurfaces::SURFACES (the probe keeps only its SDK-dependent facts,
  keyed by settings class; a registry surface without a facts row
  exits loudly), the whitelist and default derive from the built map,
  and the lockstep pin is upgraded to the full-pairing form (the
  endpoint classes leave the probe source entirely).
- Both aggregators judge stream indexes by one value predicate
  (glm21-11): sound_index() hand-copied raw_block_index()'s
  non-negative-int-or-corruption rule — an index-rule change on one
  aggregator would give the two protocols different corruption
  verdicts for the same malformed shape. Support\StreamIndex::sound()
  owns the value rule; each aggregator keeps its container fetch.
- The debug field's page owner derives from the surface registry
  (glm21-12): the hardcoded PlanRegionSettings PAGE_SLUG/SECTION_ID
  pair restated 'the first surface owns the shared page' — a registry
  reorder would strand the checkbox on the old section (the Codex R6
  #6 silent-disappearance class, no test failing). The owner derives
  from ZaiSurfaces::settings_classes()[0], like zai.php's boot().
- The discovery map's filter+key rule lives in one predicate
  (glm21-13): take_discovery_built_map() hand-copied map_from_ids()'
  build rule, already diverged (no stringiness guard; the stash's own
  sort presumed). ZaiDiscoveryCache::id_maps_to_metadata() owns the
  rule; the seed applies the canonical newest-first comparator itself
  (glm15-22's contract now structural, not vendor-coincidence).
- One auth-reader local and one rejection helper serve the discovery
  sites (glm21-14): the identical closure was spelled three times and
  the byte-identical five-line rejection throw twice inside
  discover_model_ids() — a missed edit would make the 401/403 branch
  and the 200-envelope branch record verdicts under different
  credentials or report the same failure differently.
- The live smoke test rides the owner constants (glm21-15): the
  opt-in zai_anthropic test hand-stringed the surface's plan/region
  option names and provider id where owner constants exist (the
  GLM10 #15 class the live probe was fixed in) — after a rename it
  would write options nothing reads and report the wrong surface as
  acceptance evidence.

### Fixed (zai / M2 — GLM21 verifier round)

Independent security + correctness verification over the full glm21
diff (6 verifier lenses — security, the SSE prefix change, the memo
lifecycle, behavior parity, test honesty, ledger/convention
discipline; each candidate adversarially refuted; 7 raw candidates, 4
CONFIRMED and fixed here, 3 refuted):

- The outputSchema memo serves OBJECT-FREE graphs only (glm21-16,
  verifier round on glm21-7): the strict value compare judges nested
  OBJECT elements by identity, so an in-place mutation of a nested
  schema object left the old and new schemas ===-equal forever — the
  memo served the pre-mutation encoding with no reset, ever, silently
  instructing structured-output generations against the stale schema
  (reproduced end to end). schema_is_strict_comparable() gates the
  memo: object-free graphs (the realistic decoded-JSON shape) memoize
  as before; object-carrying, absurdly deep, or reference-cyclic
  graphs skip it and encode every build (the pre-glm21-7 behavior,
  correct for every shape). A serialize()-signature compare was tried
  and rejected — serialize() fatals on anonymous-class and closure
  members that json_encode accepts, trading staleness for a new
  rejection class.
- The tool-loop memos are pruned to the completed build's set
  (glm21-17, verifier round on glm21-4/5): the anchor-based release
  never fired for the rotating-tail shape — builds keeping the SAME
  first tool DTO while replacing later ones — so every superseded
  response DTO stayed strongly keyed (five sequential conversations on
  one call pinned five entries while each held one; reproduced) — the
  unbounded-per-instance shape glm16-6 forbids. The anchor is replaced
  by a build-set sweep: every tool DTO a build maps is noted, and once
  the messages are prepared the memos are pruned to it. The bound is
  structural — at most the previous and current build's tool parts,
  whatever the conversation shape.
- The two verifier-caught pins run and bite (glm21-18/19): the
  glm21-15 source pin lived inside the opt-in smoke suite whose setUp
  skips everything without the live key — dead in every offline check
  run; it moved to ZaiSurfaceLockstepTest. The glm21-12 behavioral pin
  was vacuous while the first registry row IS PlanRegionSettings (it
  passes on the pre-fix hardcode); it gained its source half — no
  hardcoded page/section pair in DebugSettings, the derivation
  statement present.

### Fixed (zai / M2 — GLM20 verifier round)

Independent security + correctness verification over the full glm20
diff (8 verifier lenses — security/fail-open gates, guard arithmetic,
stream precedence, walk-order preservation, harness parity, scanner
regressions, the owner rewire, ledger/pin discipline; each candidate
adversarially refuted; 2 raw candidates converging on ONE defect,
CONFIRMED and fixed here):

- The float-form exponent bound is the INT-SAFE magnitude width
  (glm20-13, verifier round on glm20-1): clamping every >3-digit
  exponent magnitude to 9999 over-broadened the saturation fix — the
  old (int) cast was exact through 18-digit magnitudes, so the clamp
  corrupted the digit-shift arithmetic for tokens whose mantissa
  padding cancels a genuine exponent in [1000, 10^18). Three live
  flips, reproduced old-vs-new by two independent refuters: the padded
  exact spellings of 0.1 and 2^100/2^300 flipped accept→reject at the
  wire entry point (violating glm19-1's pinned exact-accept contract),
  the padded inexact `1<1050 zeros>e-1000` (= 1e50) flipped
  reject→accept (reopening the raw-wire-vs-decoded divergence glm19-1
  closed), and the helper's INF belt became unreachable for the padded
  negative-exponent INF class. Only 19+-digit magnitudes clamp now, to
  18 nines (itself int-exact) — verdict-exact for every
  physically-possible token. All repros pinned at both the helper
  (reflection) and the live wire entry point.

### Fixed (zai / M2 — GLM20 round)

All 12 findings of review round 20 (high, ledger-filtered), one commit
each:

- Giant-exponent float tokens are bounded before any int cast
  (glm20-1): float_literal_is_lossy_integer()'s (int) arithmetic
  saturated in the wrong direction for a saturating exponent —
  (int)'9223372036854775808' clamps to PHP_INT_MAX, the length sum
  widens to float, and the (int) re-cast lands at PHP_INT_MIN — so a
  positive giant exponent exited "not lossy" through the "<= 0"
  fractional branch and the INF belt was unreachable for exactly that
  class (latent: the wire oracle's json_encode rejects the INF decode
  first). The exponent string is bounded before any cast; the header
  comment's wrong "both saturation directions land outside (0, 309]"
  claim is corrected. [Refined by glm20-13 — see the verifier round
  above.]
- The tool-args stream diagnosis outranks the generic frame error
  (glm20-2): the malformed-event and malformed-tool-input flags latch
  independently on one stream; the event-first order permanently
  masked the actionable "malformed input JSON" diagnosis behind
  "malformed event frame". The GLM8 #5 JSON fallback is unaffected
  (its live scenario produces no tool blocks).
- The unused-import scanner recognizes group-use declarations
  (glm20-3): the single-class pattern stopped at the '{', so every
  import inside a `use Foo\{A, B as C};` group was invisible to the
  gate. Openings are matched on the same token-masked view; members
  are unrolled from the MASKED statement bytes, so a comma inside a
  blanked comment cannot hide a member (the fail-open shape the
  fixture pins); nested and function/const groups recurse; an
  unterminated group stays neutral (@lint owns unparseable files).
- The (zai, zai_anthropic) surface set rides one cross-file owner
  (glm20-4): the pair was hand-enumerated in four lockstep lists
  (Plugin::PROVIDER_CLASSES, zai.php, uninstall.php, the live probe) —
  the drift class the repo's own comments record happening twice.
  Support\ZaiSurfaces (SDK-free, slug-less: slugs stay the settings
  layer's CACHE_SCOPE per glm15-23) is derived by zai.php and
  uninstall.php (the constant read guarded so the broken-install
  fatal-avoidance path keeps holding) and pinned to the provider
  registrations and the probe by the new ZaiSurfaceLockstepTest.
  Superseded pins documented at the pins: glm16-14's per-file registry
  (the surface classes now appear zero times in uninstall.php) and
  glm15-13's literal list; the class-free literals stay separate by
  design.
- The aggregator headers stop claiming a deleted counter (glm20-5):
  both file headers and one inline comment still said malformed frames
  are "counted" — the exact glm19-13 class, missed at the headers
  (glm19-11 deleted the counters; the flag is the record).
- One shared usage validator serves both usage-carrying frames
  (glm20-6): the message_start and message_delta blocks in
  AnthropicSseAggregator carried the validate-before-cast usage rule as
  near-verbatim copies — a usage-rule edit could land on one frame
  type only and the same stream's verdict silently diverge by which
  frame carried it. One validated_usage_view() helper serves both,
  source-pinned to exactly one failure_reason() call site.
- reject_unsupported() probes the ModelConfig object, not toArray()
  (glm20-7): ten scalar probes no longer pay the whole-config sparse
  serialization (the nested schema rebuild for tool-heavy
  conversations) on every request of both surfaces; the getter's null
  is exactly the sparse array's absent key, and the camelCase getter
  name is derived — no parallel key→getter map. The unit test's
  array-only falsy flavors are gone with their input class (the DTO's
  typed setters cannot hold them).
- Identity and stop-sequence encodability join the attribution walk
  (glm20-8): the eager JsonEncodeGuard composites re-encoded strings
  that ride the assembled params the net already encodes once — ~2K
  redundant json_encode calls per request for a K-call tool loop. The
  SHAPE halves stay eager with byte-identical messages; two
  EncodabilityNet segments carry the encodability attributions,
  composed per surface at the old eager positions so every glm16-7
  order pin holds (two new per-surface pins). The glm13-11 tool-result
  RESPONSE exception stays pre-mapping, untouched. One fixture
  superseded at the pin: the mismatched-id isolation trick now names
  the eager pairing rejection — the more actionable diagnosis.
- wpdb::prepare() substitutes bound values verbatim (glm20-9,
  harness): preg_replace() processes $n backreference tokens inside
  replacements even with no capture groups, so a bound value carrying
  '$1' was silently consumed where core substitutes verbatim. Only '$'
  is escaped — the addslashes/replace backslash collapse get_col()'s
  LIKE-to-regex conversion relies on is unchanged.
- One delimiter-parameterized matcher serves the brace and paren walks
  (glm20-10, bin tooling): the two copies had already diverged
  (EOF vs false on unbalanced input). The shared walk answers 'closed
  here' or false; each named wrapper states its own unbalanced policy
  at one documented place (the visibility spans' EOF
  over-approximation; the honest false), and a source pin holds the
  depth loop to exactly one.
- One harness helper owns the zai_anthropic model wiring (glm20-11):
  the 4-statement wiring (prime the discovery transient, resolve,
  bind the transporter, authenticate) was copy-pasted five times
  across the suites; a wiring change had to land five places and a
  missed edit silently left one suite testing a differently-wired
  model. WpConnectorsTestCase::wiredZaiAnthropicModel() serves all
  five; the per-suite helpers stay as one-line delegates.
- current_time('mysql') honors gmt and the site offset (glm20-12,
  harness): the stub returned UTC unconditionally while the timestamp
  branch honored the offset — core renders local time for the non-gmt
  form, so the harness could never exercise local-time mysql
  timestamps (latent: no plugin caller uses the mysql form).

### Fixed (zai / M2 — GLM19 verifier round)

Independent security + correctness verification over the full glm19
diff (7 verifier lenses — security, the glm19-1 float-form arithmetic,
the exception/stamp commits, refactor behavior-neutrality, deletion
completeness, test-pin integrity, ledger consistency; 11 raw
candidates, 2 CONFIRMED on adjudication and fixed here, 9 refuted with
empirical repros, source traces, and ledger lines):

- Docblocks stop claiming deleted behavior (glm19-13): the DISCOVERY_TTL
  owner's docblock still said in the present tense that "the directory
  classes alias this as their public constant" — the exact aliasing
  glm19-10 deleted and now pins AGAINST, so a maintainer reading the
  owner could re-add the dead alias straight into a failing pin. Two
  minor drift sites joined it (the glm19-7 absint() phpcs
  justifications, the SseAggregator narration addressing the
  glm19-11-deleted event_count() getter).
- The stdClass stamp's trust boundary is stated and pinned (glm19-14):
  glm19-3's "the stamp rides a proof" claim was sub-path-scoped — under
  the same null-raw defensive branch the stdClass sub-path still stamps
  with no validation of its own, and its justification conflated the
  production caller (the aggregator channel, replay-validated at
  acceptance) with the sub-path, which cannot prove origin. Closing it
  in code would undo glm12-8's precise big-literal acceptance on its
  own production channel, so the boundary is documented at the sub-path
  and pinned as DELIBERATE: the boundary test asserts the stdClass
  channel stamps an aggregator-accepted exact big literal (1e20) the
  conservative walker would reject.

### Fixed (zai / M2 — GLM19 round)

All 12 findings of review round 19 (high, ledger-filtered), one commit
each:

- Float-form integer literals face the wire replay exactness oracle
  (glm19-1): wire_arguments_are_replayable()'s plain-integer scan
  guarded its digit runs against e/E/./- adjacency, which also made
  every float token invisible — the exponent spelling of a lossy
  beyond-int integer (9223372036854775809e0, …809 collapsing to the
  …808 double) skipped the exactness check its plain-literal twin
  fails, was stamped replayable, and permanently suppressed the
  outbound oracle, while the decoded channels rejected the same value —
  the raw-wire half of the glm12-8 contract and the same
  collapse-window class glm18-1 closed on the decoded walker. A second
  scan reconstructs each float-form token's exact decimal integer
  expansion by digit arithmetic and applies the same %.0f oracle; a
  float-form token decodes to a double even below the int range, so its
  exact-integer guarantee ends at 2^53 (9007199254740993e0 is lossy);
  genuinely fractional expansions keep their stable-double exemption.
- The malformed-body rewrite covers the vendor
  InvalidArgumentException family (glm19-2): a USER-role choice message
  makes the vendor Candidate constructor throw
  InvalidArgumentException — disjoint from the ResponseException the
  rewrite caught — so the SDK-internal message escaped to the boundary
  (zai_invalid_request 400) while the byte-equivalent corruption on
  zai_anthropic yielded its typed 502-class role rejection, on all
  three zai transports. Every plugin InvalidArgumentException is
  outbound and never runs under the parent's parse; the marker family
  still passes through and the mislabeled fallback keeps its
  stream-typed error (glm14-2).
- The replay stamp rides a proof on the defensive associative tool-input
  sub-path (glm19-3): a pre-decoded associative payload with a null raw
  oracle (caller-built territory, no aggregator behind it) used to
  reach the GLM12 #12 return with no replay check ever having run —
  stamping the call and permanently disabling the outbound oracle. The
  sub-path now proves replayability through the FULL is_replayable()
  oracle (a caller-built tree can carry INF/NAN/invalid UTF-8/recursion
  the decoded fast path would miss); the stdClass channel keeps its
  aggregator-backed stamp, with the trust boundary stated and pinned
  (glm19-14).
- PreDecodedResponse forwards the original headers and body (glm19-4):
  the shim hollowed out the Response it wrapped (empty header map, null
  body) though the original was in scope at both construction sites;
  the constructor now takes the whole original Response so the
  forwarding is structural.
- Five twin request rules live on one shared label-parameterized guard
  (glm19-5): empty tool name, duplicate tool name, list-root parameter
  schema, list-root output schema, and maxTokens-positive were
  near-verbatim twins across the two model classes — the branch's
  CHANGELOG records five one-surface-late incidents of exactly that
  drift shape. They live on Support\RequestShapeGuard once now
  (the AdvertisedOptionGuard pattern).
- Rejection messages interpolate PROVIDER_LABEL, not the slug literal
  (glm19-6): the slug-rename path glm15-23 designed silently left 27
  user-facing messages naming a provider id that no longer exists;
  sprintf over the constant is byte-identical (the message pins pass
  unchanged).
- One effective_max_tokens() helper serves the wire member and the
  token-limit payload (glm19-7): the defaulting expression was written
  twice; a one-site edit would make the token-limit error report a
  limit the request never carried.
- The partially-answered-tool-turn rejection rides one thrower
  (glm19-8): the message was written out twice; a one-copy wording edit
  would break the byte-stable error contract.
- The live probe's preferred model rides the catalog owner (glm19-9):
  the hardcoded 'glm-5.3' literal would silently stale-date after the
  next catalog refresh (and was plan-blind); the probe rides
  ZaiModelCatalog::ids_for_plan($plan)[0] and the fallback to the
  first discovered id emits a diagnostic.
- The metadata directories' cache alias constants are deleted
  (glm19-10): eight production-dead mirrors (four per directory) that
  only tests read; the GLM4 #10 alias-agreement pins are superseded by
  no-redeclaration anti-regression pins, and the 37 test references
  rewire to the real owners (ZaiDiscoveryCache, the settings classes).
- The SSE aggregators' observability API is gone (glm19-11):
  is_done()/event_count()/malformed_count() were public API only tests
  called, and the dead-store counters behind two of them existed purely
  to feed the getters. Deleted; termination and event-accounting pins
  read the live fields through a harness reflection helper, and the
  malformed-count pins became behavioral assertions.
- Plugin::PROVIDER_ID is deleted (glm19-12): orphaned by the
  PROVIDER_CLASSES rewire; nothing referenced it, and a future consumer
  would have read a value that no longer derives from the settings-layer
  owner after a slug rename.

### Fixed (zai / M2 — GLM18 verifier round)

Independent security + correctness verification over the full glm18
diff (6 verifier lenses — replay boundary, parse guards,
availability/discovery, the conventions scanner, security, refactor
safety; 41 raw candidates, 5 CONFIRMED on adjudication and fixed here,
36 refuted with quoted guards, tests, and ledger lines):

- One verdict precedence for 2xx bodies (glm18-15): the glm18-4 body
  recorder consulted the envelope predicate alone, so a HYBRID body (a
  well-formed models list that also carries success:false + a
  definitive code) persisted VALID at the probe (models-list checked
  first) and INVALID at the zai_anthropic directory from the same
  evidence — the fresh invalid state then answered isConfigured()
  false with no new request while core clears keys on false
  (empirically reproduced in the repo's own harness). The recorder
  applies the probe's precedence: a body that passes the models-list
  entry rule is never a rejection.
- The log-viewer member guard requires SCALARS (glm18-16): isset()
  alone passes an array-valued member and the renderer's (string)
  casts raise the Array-to-string warning — the exact settings-page
  fatal class glm18-5 was written to prevent.
- do-while visibility spans cover the trailing condition (glm18-17):
  the construct re-executes `while (cond);` after every pass, so a
  tail write executed before the include's next pass while sitting
  outside every span (empirically laundered, braced and braceless).
  The do span extends through the tail's parens; braceless do
  over-approximates to EOF.
- A by-reference alias of the include variable refuses the proof
  (glm18-18): `$alias = &$f;` made every later write through the alias
  invisible to the plain-variable collector (`$alias = &$f; $alias =
  '/outside/...'; require $f;` laundered) — the map path has refused
  the same channel since the GLM10 #14 round.
- Function-declaration spans join the visibility set (glm18-19):
  recursion and repeated callback invocation are backward edges the
  loop spans did not model — a write after the include inside a
  function that re-enters executed before the include's next
  activation (empirically laundered). Every write in the enclosing
  function of an include is visible to it now.

### Fixed (zai / M2 — GLM18 round)

All 14 findings of review round 18 (high, ledger-filtered), one commit
each:

- The decoded-only walker's out-of-range bound is the 2^63 FLOAT
  literal (glm18-1): comparing \abs($value) > PHP_INT_MAX widens the
  int constant to the 2^63 double and ties at the boundary magnitude,
  so every literal of the ~2048-wide collapse window above
  PHP_INT_MAX passed the conservative walker and was stamped
  replayable, silently altering the value on every later replay — the
  exact precision-loss poisoning the glm12-8 split rule's conservative
  half exists to refuse, with the two delivery channels of one surface
  giving opposite verdicts (input_json_delta fragments rejected the
  same literal through the precise wire rule).
- A zai tool call without non-empty identity members rejects at parse
  time (glm18-2): the SDK parent coerces a missing id/name to null (an
  empty string passes through), so a corrupt tool_calls entry —
  including a stream whose id fragments never arrived — consolidated
  into a successful generation whose turn the outbound replay guard
  then rejects on every later request: GLM3 #1 poisoning at one
  remove. The zai_anthropic twin's Codex R9 #3 discipline, extended.
- maxTokens positivity rejects typed on the zai surface (glm18-3):
  the SDK setter is a bare assignment, so 0/-x rode "max_tokens"
  verbatim into the endpoint's generic misattributed 400 instead of
  the twin's typed pre-transport rejection.
- The zai_anthropic directory records the 200-carried rejection
  envelope (glm18-4): this route answers HTTP 200 for any or no
  credential, carrying the rejection in the body — the glm12-1
  envelope the probe judges definitive INVALID — but discovery
  recorded verdicts only for the 401/403 STATUS set, so a revocation
  persisted nothing and a stale VALID verdict kept isConfigured()
  answering true. The directory rides the probe's rule through the
  same guarded recorder (glm14-5 opaque-wire and glm13-1 empty-wire
  binding rules unchanged) with the ONE decode serving verdict and
  parse. The zai (OpenAI) directory keeps status-only recording — the
  envelope shape is live-attested for the Anthropic route only.
- The debug log viewer skips malformed entries (glm18-5): entries()
  validates only the top level, and a scalar/null entry fatalled the
  settings page mid-render (PHP 8 throw; 7.4 warnings and epoch rows).
  Only well-formed entries render; the rest of the list does.
- A list-root output schema rejects typed on zai_anthropic (glm18-6):
  a JSON list is never a valid schema root, but the SDK setter accepts
  any array, so ['a','b'] embedded verbatim into the system-prompt
  guidance as a meaningless pseudo-instruction while the zai twin
  rejected typed (glm13-8). Same JsonShape::is_list() rule, before the
  encodability guard, on either signal.
- The self-containment scanner sees loop-nested writes (glm18-7): the
  offset cut ("an assignment after the include cannot be read by it")
  modeled straight-line execution only — inside a loop the include
  also sits in, a later write executes before the include's next
  iteration, and loop shapes laundered foreign include paths past the
  CI gate (empirically confirmed). Write visibility is a REGION SET
  now: the pre-include prefix plus every loop construct containing the
  include, over-approximated (unparsable shapes extend to EOF — a
  wider region only refuses proofs, never launders one).
- Compound-assignment writes are recognized (glm18-8): the write-shape
  checks matched only '$var =' / '$var .=', so '$map += $other;' was
  an invisible write channel and the element-literal proof concluded
  all runtime values were the proven literals while the array union
  injected foreign entries (empirically confirmed). The full compound
  set matches in both regexes; collecting a compound write with its
  RHS keeps the every-assignment-must-prove rule covering the union.
- The map-memo digest rides the string-only view of the id list
  (glm18-9): memoized_map() md5()-imploded the RAW cached list, so a
  non-string transient row raised Array-to-string warnings on every
  directory lookup for the transient's 12h TTL.
- One sanitize_enum() serves the plan/region read and write paths
  (glm18-10): the private helpers were byte-identical twins; a future
  rule change edited into one could desynchronize what is stored from
  what reads normalize.
- The broken-install fallback rides the endpoint base's one formula
  (glm18-11): the settings layer re-composed the discovery cache-id
  inline; the base now owns the parameterized
  compose_discovery_cache_id() (late static binding stays out of it,
  the constant values stay settings-owned per glm15-23).
- messages_url() routes through the inherited api_url() (glm18-12):
  byte-identical today, drift-proof against future append-rule fixes.
- The zero-caller mark_region_switch_pending() delegator is deleted
  (glm18-13): the SDK-free settings owner is the one entry point.
- The credential gate's auth hook delegates to the raw hook
  (glm18-14): the two byte-identical bodies could drift apart on a
  security-sensitive path.

### Fixed (zai / M2 — GLM17 verifier round)

Independent security + correctness verification over the full glm17
diff (6 verifier lenses — memo lifecycle, scanner false
negatives/positives, docs accuracy, security, suite health; 11 raw
candidates, all confirmed on adjudication; 8 fixed here, 3
pre-existing boundaries documented in the refutation ledger):

- strip_comments keeps the comment's line terminator (glm17-14): on
  PHP < 8.0 the tokenizer includes the trailing newline inside
  T_COMMENT, so the all-spaces strip joined the next line onto the
  comment's line in the code view and un-anchored the scanner's
  /^use/m — REAL dead imports silently unflagged on the
  composer-pinned 7.4 floor, with the verdict flipping by runtime
  (empirically reproduced in docker php:7.4-cli: 0 violations before,
  1 after). Length-preserving, a no-op on 8.0+, pinned by a
  version-independent contract test.
- The scanner's view invariant is LENGTH, and the statement bytes are
  real (glm17-15): a legal mid-statement comment blanks to spaces in
  the masked view, so "matched text is identical in both views" was
  false — the statement is sliced from the raw source at the captured
  offset (truthful flag message included), and the case-insensitivity
  rationale now scopes itself to classes and functions (constants
  resolve case-sensitively; the i modifier stays the conservative
  direction).
- Requiring the scanner is side-effect-free, and the loud/directory
  pins bite (glm17-16): the CLI diagnostics no longer flip
  display_errors in every requiring process; glm17-12's directory-skip
  test was vacuous (the iterator never yields plain directories — a
  symlink to a directory is the fixture now); glm17-10's loud
  unreadable branch is pinned by a dangling symlink, the one
  unreadable shape that fails under root too.
- The unreadable-subdirectory abort rides the counted channel
  (glm17-17): a mode-000 subdirectory mid-recursion used to escape as
  an uncaught fatal — the CLI gate converts it to a named FAIL with
  the partial count kept. The setAccessible() guard comments are
  re-dated (a silent no-op since 8.1, deprecated only since 8.5).

### Fixed (zai / M2 — GLM17 code review)

All 13 CONFIRMED findings of review round 17 (high, ledger-filtered),
one commit each:

- The tool-schema memo releases on the clear-to-empty idiom (glm17-1):
  every reset site sat behind the params build's non-empty gate, so
  setFunctionDeclarations([]) reset nothing and the cleared window
  kept the superseded set pinned while the config held none — the
  exact pinning glm16-16's bound claim said was gone. A build with a
  cleared/absent list releases the memo; the at-most-current-set bound
  holds at every request build under every idiom (pinned: the cleared
  build nulls the memo, and a fresh set after the clear ships its own
  schema with no stale hit).
- The unused-import scanner is hardened on its finding/mention/removal
  edges (glm17-8/9/11/13): imports are located on the token-masked
  view (a column-0 `use ...;` inside a nowdoc/heredoc body or a block
  comment is data, not an import — the phantom reproduced end-to-end
  with the real scanner), mentions match case-insensitively, the
  removal rides the regex's own offset capture (no strpos re-derivation,
  no full-source rescan, dead guard deleted), and the dead `$lines`
  store is gone. Unreadable files fail the gate loudly instead of
  passing vacuously, and a directory named *.php is skipped
  explicitly (glm17-10). The whole contract is pinned by fixture
  tests for the first time (glm17-12), including glm16-17's CRLF/EOF
  detection delta.
- CHANGELOG and ledger accuracy (glm17-2..7): the glm16-2 headline
  states the corrected claim; the verifier-round section sits at the
  top of Unreleased with every cross-reference pointing the right
  way; the glm16-17 entry owns its pass-to-fail CRLF/EOF delta; the
  glm16-2 no-op boundary names its exact payload condition (decodable
  object, type member absent or agreeing); the glm16-6 memo entries
  tell the storage's real semantics (identity-keyed like WeakMap but
  STRONG-keyed — pinned until a reset releases) and the three-idiom
  bound truthfully.

### Fixed (zai / M2 — GLM16 verifier round)

Independent security + correctness verification over the full glm16
diff (8 verifier agents, per-finding adjudication; 8 raw candidates,
4 confirmed — two distinct issues, each confirmed by two independent
angles, the other four refuted by the verifiers themselves against the
ledger or by reachability):

- A garbled duplicate terminal rides the pipeline's shape check
  (glm16-15, see the corrected glm16-2 entry below).
- The tool-schema memo resets under same-config declaration mutation
  (glm16-16, see the completed glm16-6 entry below).
- The unused-import scanner removes only the first occurrence of a use
  statement (glm16-17): a theoretical str_replace false positive
  where a comment line ending in the exact use-statement text would be
  stripped as well; not present in the repo today, hardened anyway.
  The rework also dropped the old removal's trailing-"\n" requirement
  (str_replace could only match a statement followed by a bare LF), a
  pass-to-fail gate delta the entry must own: dead imports in CRLF
  files and at EOF-without-newline are detected for the first time —
  the old removal never matched there, so such imports were never
  flagged (glm17 review #5; pinned by the scanner's fixture tests).

### Fixed (zai / M2 — GLM16 code review)

- The probe judges the RAW wired authentication (glm16-1): the glm14-5
  opaque-wiring guard was unreachable on the zai_anthropic surface —
  its protocol-wrapping `getRequestAuthentication()` funnels every
  wired instance through `wrap()`, whose RuntimeException for a foreign
  implementation the probe's unwired catch misread as NO wiring, so the
  fallback flew the effective key a caller never wired and its
  rejection persisted as that key's invalid verdict (empirically
  reproduced). The probe reads the raw instance through the new
  `raw_request_authentication()` base hook (same name and meaning as
  the protocol trait's abstract, so the zai_anthropic availability
  satisfies it with the inherited implementation); the throw now means
  exactly one thing — nothing is wired — and the FLIGHT credential
  still goes back through the surface's one wrap funnel so a wired
  Api-key flies with the surface's headers. Opaque wiring is
  inconclusive on BOTH surfaces now: nothing flies, nothing persists.

- A WELL-FORMED duplicate trailing terminal is inert (glm16-2,
  corrected by glm16-15): a proxy or replaying intermediary duplicating
  the final terminal frame discarded a fully valid completed generation
  ('malformed event frame'), while the OpenAI twin treats the identical
  appending-gateway shape as harmless (duplicate `[DONE]` is a no-op;
  pinned). A WELL-FORMED duplicate no-ops — well-formed means a
  decodable object payload whose type member is absent or agrees with
  the declaration (an object with a non-string or contradicting type
  member still rejects, through the same termination-independent
  declaration checks the pre-termination twin faces); the
  pre-termination case ignores a well-formed payload entirely.
  Content-bearing trailing events (message_start, content_block_*,
  message_delta) keep their malformed rejection, and a GARBLED
  duplicate (decodable non-object payload, e.g. `data: []`) rejects
  through the pipeline's non-object check, which judges the terminal
  name post-termination too (glm16-15, verifier round: the check was
  pre-termination-only, so the name-keyed no-op initially swallowed
  that shape — the one declared trailing name whose corruption
  detection was silently lost; restored and pinned both ways).

- `enum` is a data-valued schema keyword (glm16-3): the empty-object
  normalization walk descended into enum members and rewrote their
  schema-keyword-named empty arrays to `{}`, so the wire advertised a
  DIFFERENT constant than the one declared, silently (the same class
  GLM8 #6 fixed for default/examples/const; the walk's own docblock
  already claimed enum passed untouched). enum joins the exempt set,
  pinned with a member carrying `patternProperties: []`.

- The zai surface's tool-declaration parameters schema gets the shape
  rule (glm16-11): a list-root schema rode the wire verbatim inside
  `tools[].function.parameters` to the generic misattributed upstream
  400 — the error class glm13-8 fixed for the sibling output-schema
  member and the zai_anthropic twin already rejects typed. Identity
  rules stay first, with the twin's exact boundary: only a NON-EMPTY
  list rejects (null and `[]` keep their pass-through).

- Credential material the Authorization header cannot carry is refused
  typed (glm16-13): the header value was raw concatenation with no
  validation — the vendor HeadersCollection splits comma-joined values
  (one credential silently became several header values) and nothing
  before PSR-7 rejects CR/LF. A non-empty key containing control
  characters or a comma now rejects pre-transport in the
  RuntimeException binding-failure family, with a fixed message that
  never interpolates the key; the empty key keeps its glm13-1
  semantics, and the probe's fallback path validates through the same
  chokepoint (an uncarriable DB key is inconclusive — nothing flown,
  nothing persisted).

- The scaffold test loads zai.php before asserting ZAI_VERSION
  (glm16-12): the constant is defined only in that file (the PSR-4
  autoloader cannot provide constants), so the test errored under
  `--order-by=random` whenever it ran before any `bootPlugin()` test.
  The harness's require_once makes it deterministic under every order.

### Changed / hardened (zai / M2 — GLM16 code review)

- One shared encodability-net owner (glm16-7): the request-build net
  and its attribution walk were near-verbatim private twins between
  the model files, kept in lockstep only by comments and already
  diverged once historically. `Support\EncodabilityNet` owns the one
  raw encode, the failure sequence, and the four verbatim-identical
  walk segments; each surface keeps a thin composer pinning ITS
  mapping order (which member a multi-bad payload names — pinned both
  ways). The one genuine divergence is documented at its method: the
  sampling-options segment is zai-only because the zai_anthropic
  surface's eager transforms already reject those members before its
  net. The glm13-11/glm14-4/glm15-5 one-pass source pins superseded to
  scan the owner for the single raw oracle (documented supersession).
- Same-role coalescing is linear (glm16-5): the coalescing loop
  re-merged and re-validated the full accumulated turn per adjacent
  SDK message (array_merge plus a whole-turn rescan — O(K²) in blocks
  for the per-tool-result message shape). One seen-text bit per
  coalesced turn seeds the per-message scan (equivalent to judging the
  merged turn), and the merge appends in place.
- Tool-schema normalization memoizes by declaration identity (glm16-6,
  completed by glm16-16): a tool loop re-ran the encodability oracle
  and the recursive normalize walk per declaration per request for a
  wire form that never changes (the DTO is immutable). An
  SplObjectStorage keyed by declaration identity — identity-keyed like
  the PHP 8.0+ WeakMap it substitutes for on the 7.4 floor, but
  STRONG-keyed: the storage itself pins every declaration it holds
  until a reset releases it (glm17-7) — holds the results, reset when the config
  identity changes OR the declaration list itself changes (glm16-16,
  verifier round: the vendor ModelConfig is mutable — an in-place
  `setFunctionDeclarations()` loop never changes identity, and the
  strong-keyed storage pinned every superseded declaration; the strict
  list compare restores the documented bound for the two NON-EMPTY
  reconfiguration idioms — glm17-1 completed the third: every reset
  site sat behind the params build's non-empty gate, so clearing the
  list to empty reset nothing and the cleared window kept the
  superseded set pinned while the config held none; a build with a
  cleared/absent list releases the memo entirely, and the memo pins at
  most the CURRENT declaration set at every request build under every
  idiom). Rejections never memoize.
- One shared mislabeled-body JSON fallback (glm16-8): the ~25-line
  fallback scaffold (one decode, object-root gate, glm14-2 marker
  propagation) lived twice. `Support\JsonFallbackResult` owns it,
  taking the surface's parse callable over the already-decoded pair;
  pinned by a direct unit test of the scaffold's contract.
- `is_object_shape()` rides the shared sequential-key predicate
  (glm16-9): the private re-encode probe was a fifth hand-rolled copy
  of the rule `JsonShape::is_list()` was extracted to own, and paid a
  full json_encode per tool_use input member per response parse. Key
  inspection decides identically on every decoded input; the GLM7 #10
  raw-oracle mechanism pin superseded to assert the predicate ride and
  the absence of any encode.
- The scalar tool_result JSON-encode convention is pinned, not
  accidental (glm16-4): every response value ships as its JSON
  encoding, strings included (artifact quotes visible to the model). A
  raw string would be Anthropic-protocol-legal, but the zai twin's
  tool-message content is the VENDOR parent's own `json_encode()`
  (unoverridable), so raw strings here would present the same tool
  result differently on the two surfaces. One convention, both
  surfaces, every response type — the pin makes any change conscious.
- Uninstall's surface set rides one registry (glm16-14): the
  zai/zai_anthropic class pair was hand-enumerated three separate times
  inside uninstall.php (endpoint pair, two settings pairs); one
  registry drives all three class-based sweeps. The BY-DESIGN
  class-free listings (pre-chain option deletions, broken-install
  fallback literals) stay separate, and a source pin forbids the
  surface classes from creeping back into a second listing.
- Seven dead imports deleted and an unused-import guard added
  (glm16-10): imports with no code use of the short name (FunctionCall,
  SseFrameBuffer, Message, Response ×2, and one more the new scanner
  found, PlanRegionSettings) implied call paths that do not exist.
  The guard rides `bin/check-conventions.php` — conservative by
  design: only an import whose short name appears NOWHERE else in the
  file fails (a docblock TYPE is a real phpstan-resolved use; the
  ModelMetadata import stays for exactly that reason).

### Fixed (zai / M2 — GLM15 code review)

- The uninstall constant-rung test runs ISOLATED in a subprocess
  (glm15-1): the in-process `define( 'ZAI_API_KEY', … )` was
  irreversible and isolated only by a file-ordering accident
  ("deliberately runs LAST") — under `--order-by=random` the leaked
  constant flipped every later test's credential ladder (12 failures +
  2 errors at seed 1, reproduced), and any future test file sorting
  after `ZaiUninstallTest.php` broke the default order too. The
  constant is defined in a child process only (the sibling GLM8 #15
  runner pattern), the parent pins that it never defined it, and the
  check pipeline's test step runs `--order-by=random` so an order
  dependency fails every check instead of a lucky default order. The
  same randomized run exposed a second order dependency — the
  auth-header generation tests relied on the vendor parent's statically
  cached directory instance being UNWIRED; a test that wired
  authentication onto it earlier in the process made discovery consume
  the test's single queued response — fixed by priming the discovery
  transient (the suite's standing convention) so model resolution
  makes no discovery HTTP regardless of process-wide SDK state.

- The self-containment analyzer is TOKEN-based (glm15-2): comment
  stripping and the include/assignment/signature scans were regexes
  over comment-stripped source, so string and heredoc CONTENTS were
  judged as PHP code — comment-looking lines inside strings were
  deleted, a 'function' token inside a string literal counted as a
  signature, and '$var = …;' text inside a string body was collected
  as a real assignment. A phantom in-string assignment could satisfy a
  variable include the runtime never resolves that way (a build
  artifact falsely accepted as self-contained — demonstrated), and
  phantom includes and writes refused legitimate files (5 phantom
  violations on the new fixtures). Comments become spaces through
  `token_get_all`, a same-length string-masked view carries the
  code-shape scans, and the real statement text is sliced from the
  code view, so literals inside include statements stay visible while
  string bodies can never masquerade as code.

- The live probe skips the key-file fallback when HOME is unset
  (glm15-3): under cron/systemd `getenv( 'HOME' )` returns false, and
  the bare concatenation probed the filesystem ROOT
  ('/.config/z.ai/api_key'), silently using whatever unrelated
  readable file lives there as the live API key.

- The probe's generation-route evidence rides the endpoint owner
  (glm15-4): the route line picked its URL through an instanceof
  ternary plus an inline chat-completion route literal that existed
  nowhere else in src — a vendor or plan route change (Anthropic
  already varies /messages vs /v1/messages by plan) would print
  acceptance evidence for a URL the plugin never requests, with
  nothing failing. `generation_url()` on the endpoint layer is the one
  owner (the OpenAI surface declares GENERATION_ROUTE; the Anthropic
  surface derives its plan-dependent Messages route), pinned to the
  CAPTURED wire URL by both mapping suites.

- Uninstall's marker enumeration and plan/region loops ride their
  owners (glm15-9/10): the wp_options LIKE sweep hand-mirrored the
  settings owner's marker-name prefix as two literals (the mirror
  class that stranded hashed markers twice before), and the discovery
  sweep hard-coded the plan/region pairs beside the declared owner —
  plus a third hand-copy in the probe's `--plan/--region` whitelists.
  The prefixes compose through `probe_miss_transient_name()`'s new
  one-owner export (`probe_miss_transient_prefix()`) when the owner
  chain loads (the broken-install literals remain the bounded
  fallback), and every plan/region list rides
  `AbstractPlanRegionSettings::PLANS/REGIONS`, with source pins
  forbidding the hand-copied shapes.

### Changed / hardened (zai / M2 — GLM15 code review)

- The zai_anthropic surface rides the one-encode net at request build
  (glm15-5): the surface's own mapping eagerly json_encoded every wire
  member per request (every text part of the conversation history,
  the system instruction, every tool declaration name/description)
  and the transport re-encoded the identical assembled payload — the
  exact per-member-walk pattern the zai surface replaced (glm13-11)
  plus its glm14-4 ride. ONE raw encode now proves encodability (the
  typed pre-transport rejection, with a per-member attribution walk
  naming the first bad member in the mapping order) and rides as the
  request's body string. The mapping sites keep every non-encodability
  rule (identity, shape, transforms whose strings ride the wire) —
  and the tool SCHEMA's encodability guard stays eager because the
  recursive normalize transform below it would fatal on a
  self-referential structure no net can reject (a memory-exhaustion
  fatal the suite demonstrated before the guard was restored).
- The pure derivations of a credential consult memoize (glm15-6):
  `for()` shares the immutable endpoint value instance per surface and
  plan × region, and the binding hash memoizes keyed by the COMPLETE
  input tuple (class, source, endpoint, key) — everything reading
  mutable state stays per-consult, so the retarget-the-next-request
  contract keeps its pin and no invalidation hook class exists.
- The mislabeled-JSON fallback decodes the body once on both surfaces
  (glm15-7) and the zai fallback rides the shared non-streaming
  pipeline helper (glm15-11): three json_decodes and two prefix strips
  of one body become one strip and one decode pair (the decoder's raw
  view IS the object-root oracle the hand-rolled pre-flight
  re-derived), and the usage-validation/derived-total/pre-decoded
  pipeline that lived twice in one class is one
  `parse_decoded_chat_body()` (the GLM7 #9 hand-off pin consciously
  superseded, documented at the pin).
- One trait owns the Anthropic protocol authentication wrap (glm15-8):
  `SpeaksAnthropicMessagesProtocol` carries the
  `getRequestAuthentication()` override (one raw-authentication hook
  per class) and the unwired-probe fallback's wrap, so a fourth class
  speaking the surface cannot silently send plain ApiKey auth — an
  omission that fails open (z.ai ignores the version header) and
  undetected.
- The remaining lockstep copies consolidated: boot() wires every
  surface through one SDK-free surface list (glm15-13 — the
  copy-pasted hook block whose missed line was the stranded-invalidation
  bug class the file's own comments document); the enum settings
  fields ride one parameterized render/sanitize pair (glm15-16); the
  harness primes discovery transients through the endpoint owner and
  hoists the directory-suite helpers (glm15-12/19); the auth-header
  suite posts the canonical Messages fixture (glm15-20); the choice
  index rule lives in one SseAggregator predicate (glm15-14);
  apply_delta() maps and applies the delta type in one switch
  (glm15-15); the content-member rule lives in one map (glm15-21);
  cold-window discovery hands its parse-built metadata to the map
  memo through the SAME chat-filter rule instead of rebuilding it at a
  second, already-diverged site (glm15-22); and each surface identity
  slug ('zai'/'zai_anthropic') has one owner — the SDK-free settings
  layer's CACHE_SCOPE — with the registration and refusal constants
  aliasing it (glm15-23).
- SseFrameBuffer's incremental-feed bound is documented, not fixed
  (glm15-24): each feed() re-normalizes and rescans the unconsumed
  buffer, so chunk-by-chunk feeding of one large frame is quadratic in
  the TAIL — latent (both model parsers feed the complete body in one
  call). The honest statement rides feed()'s docblock and the ledger;
  the cursor fix is deferred with its re-open condition.

### Fixed (zai / M2 — GLM14 code review)

- An unencodable configured output schema is rejected typed under EVERY
  output MIME again (glm14-1): glm13-11's move of the schema's
  encodability check onto the whole-payload net lost the case the SDK
  parent never forwards — `response_format` ships only under
  `application/json`, so a schema configured under, say, `text/plain`
  never reached the assembled payload and flew unchecked (reproduced:
  typed rejection before glm13-11, silent flight after), where the
  pre-glm13-11 eager walk rejected it and the zai_anthropic twin guards
  the schema on EITHER signal. The typed walk guards exactly the
  dropped case eagerly; under the JSON mime the net still covers the
  schema once and the attribution walk names it precisely.

- The precise tool-arguments diagnostics surface on the mislabeled-JSON
  fallback paths too (glm14-2): glm13-7's
  `FixedMessageResponseException` pass-through lived only in the
  non-streaming parse, so a doubly-nonconforming gateway (a JSON body
  labeled `text/event-stream`) still degraded the precise message to
  the generic stream error — the round's "precise message on BOTH
  surfaces" claim did not hold on that path. Both surfaces' fallbacks
  pass the marker through, and the zai_anthropic surface's tool_use
  input rejections (missing input member, non-object input value,
  non-replayable arguments) carry the marker type now — byte-identical
  on the wire, `instanceof`-compatible everywhere; every other parse
  failure keeps the GLM12 #3 / GLM8 #5 recovery contracts (null, and
  the stream-typed error).

- One credential flies AND binds for opaque wiring as well (glm14-5):
  glm13-1's empty-wire skip covered only an EMPTY Api-key; a non-Api-key
  `RequestAuthentication` (wirable only by third-party code calling
  `setRequestAuthentication()` directly) flew the probe and the
  generation/discovery recorders while the verdict binding named the
  ladder/database key — the cross-credential poisoning class. The probe
  stays inconclusive under opaque wiring (nothing flies, nothing
  persists, configured-pending instead of a poisoned not-connected) and
  the recorders record nothing; unwired and empty-wire semantics are
  unchanged.

- The whole-payload encodability net's ONE serialization is also the
  request's LAST (glm14-4): the encoded body string rides the Request
  directly (`getBody()` returns a stored string as-is), so the vendor
  transport's send-time re-encode — a second O(payload) serialization
  on every zai chat/completions call, paid end-to-end on every request
  of a tool-loop history — is gone. The wire bytes are unchanged (the
  same encoder, flags, and depth `getBody()` used); requests that would
  not JSON-encode their data (query params, form bodies) keep the array
  verbatim.

### Changed / hardened (zai / M2 — GLM14 code review)

- The encodability net's documented coverage contract matches its real
  boundary (glm14-3): the net covers every member the SDK parent
  forwards VERBATIM; members the parent PRE-ENCODES with a
  string-cast (the tool-result response, the tool-call arguments) or
  DROPS conditionally (the schema under a non-JSON mime) keep their own
  eager guards. The comments no longer promise the net "auto-covers
  every member the parent forwards" while naming one exception — the
  vendor parent has two launder sites and one drop today, and a future
  fourth site needs its own eager guard.
- The generation-route verdict recorder rides the shared
  `SafeGenerationBoundary` (glm14-6): one concrete mechanic through the
  trait's existing availability/authentication hooks and a
  `capture_generation_endpoint()` stash, replacing verbatim copies in
  both model classes that had already begun drifting textually.
- The model-list parser's entry-failure reasons are typed constants
  with ONE rejection map (glm14-7): an unmapped reason — one a future
  edit returns without extending the map — throws the internal lockstep
  RuntimeException instead of the old switch's silent default-arm
  degradation to the missing-data rejection; the `entry_rejection()`
  method the docblock referenced since glm13-2 exists.
- The marker class derives its message from the SDK's
  `ResponseException::fromInvalidData()` factory (glm14-8) — the
  byte-identical wire shape holds by construction, so an SDK release
  rewording the factory can no longer silently split the contract
  between SDK-thrown and plugin-thrown exceptions.
- Test hardening: the uninstall sweep's CONSTANT rung executes
  behaviorally (glm14-9 — the constant is defined, markers planted
  through the settings owner's own derivation, and the sweep must
  delete them; the source pin stays as the composition guard), and the
  probe's one-decode contract is pinned by a counting Response double
  (glm14-10) instead of source substring counts alone.

### Fixed (zai / M2 — GLM13 code review)

- One credential flies and the same credential binds: an EMPTY
  registry-wired key (`ZAI_API_KEY=''` in wp-config/.env — the SDK
  wires the empty string verbatim) no longer authenticates the
  availability probe with an empty Bearer while the verdict lands on
  the ladder-resolved database key's binding; the probe treats an empty
  wired credential exactly like none and authenticates with the
  EFFECTIVE key, and a definitive answer earned by a request the empty
  credential flew records nothing. Previously the 401 the empty
  credential earned persisted under a VALID key's binding, reporting
  not-connected for a working credential and clearing it through core's
  key-save validation. A 401/403 on the GENERATION routes now records
  its invalid verdict too (the same definitive evidence the probe and
  the discovery routes already persist — request-time endpoint
  capture, glm13-1 binding discipline), so a server-side-revoked key
  stops reporting connected — and stops re-transmitting the dead
  credential — within the request that learned the revocation instead
  of the whole 300s TTL; the recorded verdict immediately feeds the
  refusal gate.

- A damaged Anthropic stream can no longer complete with fabricated
  content: an unknown delta type on an index whose
  `content_block_start` was never received is corruption, not a
  synthesized-seed tolerance (live-reproduced: a stream whose only
  delta was unknown completed successfully with an empty fabricated
  text block and no flag — the missing-start-must-fail class every
  KNOWN delta type already enforced; the Codex R14 #3 seed pin is
  consciously superseded). Unknown deltas on a STARTED block keep
  their forward-compatible tolerance. Position rejections inside
  `start_block()` (duplicate index, contiguity gap) now run BEFORE
  content validation, so a position-rejected tool_use start no longer
  pollutes the tool-input error channel for a stream whose actual tool
  arguments were fine.

- The availability verdict predicate rides the discovery parser's ONE
  extracted model-list entry rule: the verdict's private shape-check
  copy had already diverged — its vacuous foreach accepted an EMPTY
  `data` list (`{"data":[]}`), persisting VERDICT_VALID for a body the
  parser rejects and the discovery seed refuses to cache. Both now
  share `ZaiModelListParser::entry_failure_reason()` (the empty list
  and the incomplete page are not the models-list shape and stay
  inconclusive — superseding the verdict's earlier has_more tolerance
  per the glm12-1 narrowing), and the cold-window probe body is decoded
  ONCE, shared between the verdict and the discovery seed.

- The zai surface's tool-arguments diagnostics survive its parse
  sanitizer: the three precise rejections (not-valid-JSON fragments,
  unencodable/precision-loss values, wire and decoded variants) now
  carry a `FixedMessageResponseException` marker type the blanket
  rewrite passes through, so the byte-identical corruption surfaces its
  precise message on BOTH surfaces (the pre-decoded-INF test's fixture
  was discovered to be mis-nested JSON that never exercised its path —
  fixed). Cross-surface parity also lands for duplicate declared tool
  names (a returned tool_call identifies the declaration only by name —
  typed rejection like the twin's R18 rule) and list-root output
  schemas (typed rejection instead of the misattributed upstream 400),
  and both surfaces now judge an unbound instance in the same order
  (transporter binding before option guards — the vendor-mandated order
  of the zai surface's final parent method, which the zai_anthropic
  surface's own generateTextResult() now mirrors; guards-first is not
  implementable on the zai surface).

- One serialization per request in the zai encodability pipeline: the
  per-member pre-pass no longer eagerly json_encodes every member
  (system instruction, every text part, every tool schema) before the
  chokepoint's whole-payload net encodes the assembled params again —
  the typed identity/shape walk runs eagerly, the encodability walk
  runs only to ATTRIBUTE a net failure with the precise per-member
  message, and the one exception (the tool-result response, whose
  failed encode the SDK parent's own mapping launders into
  `"content": "false"` before the net could see it) stays eager.
  Tool-arguments strings decode ONCE per call (the replay guard takes
  the caller's pre-decoded tree), and the cold-window probe body's
  double decode is gone.

- The `TokenLimitReachedException` owns its message at the WP_Error
  boundary: the mapper passes the model's precise text (the limit
  number, the 'Raise maxTokens' guidance) through under its
  controlled-string policy — only this plugin constructs the exception
  — and carries the typed `max_tokens` payload in the error data,
  deleting the second message catalog that drifted from the model's
  own. The debug enabled-change hook rides the shared
  `option_values_equal()` comparison (a non-scalar payload from
  out-of-band code no longer raises an Array-to-string warning that
  could abort the log clear), and uninstall's probe-miss sweep
  collects env/constant credentials through the settings owner's ONE
  `env_constant_ladder()` — its hand-rolled rungs were the fourth copy
  of the ladder body, the drift class that stranded hashed markers
  twice before.

### Fixed (zai / M2 — GLM12 code review)

- Availability verdicts read the models BODY, not the bare 2xx: z.ai's
  Anthropic `/v1/models` route answers HTTP 200 for any or no
  credential with the rejection in the body
  (`{"code":401,"msg":"token expired or incorrect","success":false}`;
  live curl capture 2026-09-04), so the status-only rule could never
  detect an invalid key on that surface and its unauthenticated 200
  cleared region-switch distrust. A 2xx now decides by body — an
  authenticated model list (the parser's minimal `data[].id` entry
  rule) is valid, the failure envelope with a definitive-rejection code
  is invalid, anything else stays inconclusive — and the M2
  exit-criterion test is re-mocked against the real shape (the old 401
  mock passed only against a response the live endpoint never
  produces). The probe's models response also seeds the discovery
  transient through the shared parser, collapsing the cold-window
  double round trip to the same URL into one fetch (catalog-unusable
  bodies seed nothing; the live probe still clears its caches before
  each acceptance step).

- The zai (OpenAI-compat) surface gains the twin-parity mechanics it
  was missing: the glm8-5 stream-label JSON fallback (a complete
  chat.completion body mislabeled `text/event-stream` now completes the
  generation instead of dying as no-usable-chunk), empty-list omission
  (an explicitly-cleared `[]` on the non-nullable setters means "not
  set", never `"stop":[]`/`"tools":[]` on the wire), a typed
  pre-transport rejection for empty declared-tool names (the
  encodability check passed `""` straight to the misattributed 400),
  and absent-`total_tokens` derivation by prompt+completion summation
  (the lenient GLM7 #8 tolerance stays for the other members; a present
  total — even an explicit 0 — stands verbatim).

- Tool-argument replay verdicts are stamped at acceptance instead of
  re-derived per request: the parsers construct a
  `ReplayValidatedFunctionCall` (sound because the SDK DTO is
  immutable) and both surfaces' outbound guards skip their serializing
  oracle for stamped calls — first-seen caller-built values keep the
  full oracle. The stamp also restores parse/replay agreement for the
  exact big-integer literals the guard now accepts: a beyond-int
  literal replays when the platform decode keeps it EXACT (1e20, 2^63 —
  re-read through the platform's own `%.0f` formatter against the raw
  wire token, with JSON strings stripped and exponent giants rejected
  through the encode oracle), while genuinely lossy literals
  (…809 collapsing to …808) still reject; decoded-only paths keep the
  conservative walker because post-decode the two are
  indistinguishable. The old blanket rejection failed valid
  generations on a factually false justification (every double ≥ 2^63
  is integral).

- The generic encodability net lives once at the `createRequest()`
  chokepoint over the fully assembled request params: any unassemblable
  value in ANY member — known, forgotten, or added by a future SDK
  release — rejects as the typed pre-transport 400 instead of the
  transport's untyped JsonException, closing the per-family lockstep
  that already broke once (GLM6 #5's verifier round) and is live
  today for members neither guard layer knows.

- A zero-normalized pre-sentinel usage member (`"prompt_tokens":0` on
  every chunk) no longer blocks the post-`[DONE]` gap-fill: zero is
  exactly the lenient validator's absent-member default, so such a
  member carries no token data and the appending gateway's real final
  counts complete the payload instead of silently zeroing it; one
  non-zero count keeps the member standing, and corrupt members are
  deliberately not rescued.

- The settings-change discovery-transient sweep runs regardless of the
  endpoint owner's load state: a plan switch on a partially-broken
  install no longer leaves the old combination's 12h transient alive.
  The ids come from the endpoint owner's one formula when it loads and
  from the settings layer's own identifier constants when it cannot
  (pinned equal by a consistency test), with the `_miss` suffix still
  riding the shared constant.

- The live probe's discovery-source evidence names the URL the
  selected surface actually requested (`models_url()`, the MODELS_ROUTE
  owner) instead of hardcoding the Anthropic route for both surfaces.

- Internal drift closures: the Anthropic model's private
  `is_schema_list()` merged into the shared `JsonShape::is_list()`
  predicate the same file already calls; the four hand-copied
  corrupt-declared-event classifications in the Anthropic SSE
  aggregator ride one `flag_corrupt_event()` helper (the copies drifted
  twice before — GLM9 #2, GLM10 #4); and the byte-identical
  capture/snapshot/reject test helpers across the twin mapping suites
  live once in `WpConnectorsTestCase`, parameterized by per-suite facts,
  with the already-forked `captureRequest()` try/catch reconciled.

### Fixed (zai / M2 — GLM11 code review)

- The GLM10 #9 label drift guard grew teeth and one mechanic: the
  source scan now covers the zai surface's own directory (one of
  `parse_chat_ids()`'s two call sites, previously unscanned so its
  rejection path could regress unguarded), enforces BOTH directions
  — every guarded file (both models, the shared list parser, both
  directories, the availability base) must carry no bare label
  literal of either surface in ANY argument position (the i18n
  text-domain calls, the one sanctioned home of a domain literal
  that shares the zai label's value, are stripped first; the old
  comma anchor missed last-argument positions entirely), and a
  surface-owned file must not name the other surface's availability
  class — the only thing a swapped REFUSAL_LABEL constant betrays,
  since it interpolates no literal at all. The guard's two copies
  (the models' ReflectionClass loop and the scan's hand-escaped
  regex plus repo-root path arithmetic, which would drift apart the
  next time one was tightened) folded into one class=>owner loop,
  and its comment scopes the invariant to what it enforces: the
  model and discovery rejections — the endpoint layer's
  unknown-combination message deliberately keeps the z.ai brand
  (GLM8 #10's display-facing branding) and stays out of the guard.

- The live probe's last two hand-composed identity facts ride their
  owners: `provider_id` and `default_plan` in the per-surface fact
  table are `ZaiProvider`/`ZaiAnthropicProvider::PROVIDER_ID` and
  `PlanRegionSettings`/`ZaiAnthropicPlanRegionSettings::DEFAULT_PLAN`
  constants, and the pinning test forbids the quoted-literal shape
  outright — a PROVIDER_ID or DEFAULT_PLAN rename can no longer
  leave the probe wiring `Plugin::register()`'s authentication to a
  stale id while it prints the stale plan as acceptance evidence,
  failing with a diagnostic that never points at the stale literal.

### Fixed (zai / M2 — GLM10 code review)

- Availability verdicts bind what actually answered: a 401/403
  discovery rejection was recorded bound to the endpoint the settings
  re-resolved at RESPONSE time, so an admin saving the region while an
  intl discovery was in flight got the intl rejection persisted under
  the cn identity — `isConfigured()` on cn then reported not-connected
  for a key never tested against cn (up to the 300s TTL) while the
  endpoint that rejected kept no verdict at all; the recorder now binds
  the endpoint captured at REQUEST time (both directories pass theirs),
  and the region-switch distrust flag only clears when the recorded
  verdict concerns the CURRENT endpoint (an other-endpoint verdict
  settles nothing about the new region and must not re-open the R19
  hole). And `state_is_fresh()` bounded the elapsed time below at zero:
  a `checked_at` in the future (clock skew between web nodes, a state
  restored from an ahead-clocked server) yielded a negative elapsed
  that always passed the TTL test, trusting a verdict for as long as
  the skew lasted — a future `checked_at` cannot be aged, so the state
  is distrusted and re-probed (the probe rewrites it on this node's
  clock, healing the skew).

- Gateway BOM tolerance on the last uncovered route: the `/v1/models`
  discovery parser read the vendor `getData()` (a bare `json_decode`)
  and ran its own raw decode — both failing wholesale on the UTF-8 BOM
  a gateway/CDN can prepend to JSON bodies, the documented threat class
  GLM8-2/3 and GLM9-3 hardened on the SSE and Messages/completions
  paths — so dynamic discovery silently degraded to the 60s `_miss`
  marker plus static fallback on every request, retrying identically
  forever. The parser owns ONE BOM-safe decode through the shared
  `SseFrameBuffer::strip_stream_prefix()` rule now, object-tree reads
  throughout, with the R14 #4 object-ness oracle and the R15 #3
  has_more rejection preserved on the single decode.

- Error-declaration coverage completed in the Anthropic stream
  aggregator: the R7 #6 agreement branch was the only corruption
  channel still omitting the error flag — `event: error` with a
  contradicting STRING type member (`data: {"type":"ping"}`) was
  classified malformed_event, surfacing 'malformed event frame' instead
  of the documented 'error event' verdict, in contradiction of the
  GLM7 #4 invariant every sibling branch upholds (glm9-16 extended it
  one branch over; this closes the last one). The GLM4 #6 trailing pin
  that encoded the old behavior is consciously SUPERSEDED: the same
  frame now yields the error-event verdict in both phases, pinned
  through both the aggregator flags and the model verdict. And the
  trailing-[DONE] skip ran BEFORE any declaration judgment, so a
  post-message_stop frame DECLARING a known content-bearing event with
  a [DONE] payload bypassed the trailing corruption policy entirely —
  even `event: error` + `data: [DONE]` set nothing, and whether a
  declared trailing frame was flagged depended purely on payload-text
  coincidence. The skip is gated on a null event name now (the bare
  sentinel a gateway appends keeps its tolerated status) while declared
  frames fall through to the one pipeline.

- Tool schemas: empty-array SUBSCHEMAS at every schema-valued position
  — a property value of `[]`, `items: []` — normalized to the empty
  object {} the Messages input_schema meta-schema demands (previously
  only the four object-MAP keywords converted, so such positions
  shipped as JSON `[]` and a strict endpoint's 400 surfaced as the
  generic misattributed upstream error; verifier round: an empty items
  with a sibling additionalItems keeps its tuple [] verbatim —
  converting it would silently drop the declared constraint). The zai
  surface's streamed encodability oracle is the GLM9 #13 structural
  walker now (the payload is 100% decode output, so INF at any depth
  rejects identically with zero serialization per streamed generation;
  the walker's beyond-PHP_INT_MAX strict superset is disclosed and
  pinned). One shared `JsonBodyDecoder` owns the vendor
  getData()-mirroring decode block both models hand-rolled with
  already-diverged mechanics, and both model surfaces name themselves
  one way in every rejection (per-surface PROVIDER_LABEL constants
  ridden on the availability owner's REFUSAL_LABEL; verifier round: the
  discovery paths — the shared list parser, the zai_anthropic
  directory's throws, refuse_discovery() — ride the same labels, with
  source-scan drift guards).

- Streaming efficiency without retention: the OpenAI-surface aggregator
  merges every decoded frame into the accumulators at FEED time (the
  Anthropic twin's pattern) instead of retaining the full decode of
  every frame until end-of-stream aggregation — peak memory no longer
  holds all decoded frames of the stream simultaneously (decoded PHP
  arrays run ~2-5x the JSON text) — and the raw-usage oracle is
  captured as the winner's data STRING and decoded once, lazily and
  memoized, where the last-wins merge already decided the winner
  (gateways emitting `"usage":{}` on every chunk previously paid a
  second full parse per token-delta frame for an oracle the next frame
  discarded). The Anthropic aggregator's separate `$block_order`
  tracking is gone — the R17 #2 contiguity guard guarantees the blocks
  map's insertion order IS the stream order (pinned). One shared
  availability owner decides what counts as a definitive credential
  rejection (the 401/403 set, the predicate, and the recording the
  probe and both directories consult — the hand-copied blocks the
  lockstep had already failed once), the uninstall owner chain rides
  one class=>file map through the same two-phase fatal-avoidance load,
  and the live probe rides the owner constants through one per-surface
  fact table instead of hand-composed option names and scattered
  ternaries.

- Tooling (verifier round on the uninstall map change): the
  self-containment conventions checker learned the map+foreach include
  idiom the owner chain needs — a foreach VALUE binding resolves as a
  synthetic assignment, and an array() literal's element values must
  each pass the anchored in-root proof — behind a strict write-forms
  gate (element writes, list() targets, array-write helpers,
  by-reference aliasing, and function-signature occurrences all refuse
  the proof, since the analyzed values must be a superset of the
  runtime ones). The verifier empirically demonstrated the first cut
  laundering two escape shapes a direct include is flagged for (an
  anchored map value with a runtime tail, and unmodeled element writes
  after the literal); both are closed and pinned alongside the
  sanctioned shape.

### Fixed (zai / M2 — GLM9 code review)

- Streamed-transport schema parity and the error channel: a streamed
  `message_delta` carrying the schema-legal `{"stop_reason":null}`
  failed the `isset()` presence probe, so a complete valid stream died
  as 'malformed event frame' while the byte-identical non-streaming
  body parsed (GLM8 #4 accepted it there) — reception now latches on a
  PRESENT string-or-null member (other values keep the truncation
  channel, and a received null still contradicts tool blocks); and a
  present-but-non-string payload `type` member collapsed onto the `''`
  sentinel of the ABSENT member, so the declared-event agreement rule
  was skipped and an `event: ping` frame carrying a text delta under
  `{"type":false}` silently dropped its chunk while the aggregation
  reported success — the corrupt declaration is a typed rejection now,
  the same channel every sibling corruption class uses, with the
  absent member keeping its documented tolerance. (Verifier round on
  that change: an `event: error` DECLARATION keeps the error channel
  through the new corruption class too — only the malformed-event flag
  was set initially, regressing the frame to 'malformed event frame'
  plus the JSON-fallback attempt instead of GLM7 #4's documented
  'error event' verdict, the invariant the undecodable- and
  non-object-payload siblings uphold.)

- Wire-guard and verdict parity on the zai surface: a BOM-prefixed
  `application/json` chat.completion body failed both decodes as 'The
  chat-completions payload was malformed.' where the zai_anthropic
  twin tolerated the identical prefix — the shared
  `SseFrameBuffer::strip_stream_prefix()` now runs before both decodes,
  a BOM before garbage still failing typed; a FunctionResponse part's
  null/empty id shipped as `"tool_call_id": null` to an upstream 400
  surfaced as the generic rejected-request message, where the
  zai_anthropic twin and the same walk's FunctionCall ids give the
  precise typed pre-transport rejection; and a 401/403 on the zai
  `/models` discovery route records the definitive invalid verdict
  through the availability layer's own persist path (via the SDK
  parent's overridable `throwIfNotSuccessful()` hook, the one place
  the status is visible on this surface's delegated HTTP flow), so a
  key revoked server-side stops passing `isConfigured()` after at most
  the 300s verdict TTL instead of persisting no verdict at all — same
  upstream evidence, the same outcome the zai_anthropic twin's GLM7
  #12 gave.

- Scope and logging honesty: the settings layer's discovery-transient
  sweep skips through a `class_exists()` pre-check (the uninstall.php
  owner-chain pattern) instead of a `catch (\Error)` that swallowed
  every Error family while its own comment promised only load-family
  ones — a quarantined src file still degrades exactly as documented,
  but a logic bug in the owner now surfaces loudly; and the logging
  transporter records its status-0 entry for engine `\Error`s too (an
  `\Error` escaping the wrapped PSR-18 client previously propagated
  with no trace of the failed round trip in the admin's request log).

- The uninstall probe-miss composition lives once: the sha256 binding
  formula, the marker-name build, and the derivable-name sweep moved
  into the SDK-free settings layer beside the invalidation identifiers
  (`credential_binding()` — the pure hash; the availability writer
  keeps its GLM5 #11 runtime→database normalization on its own side so
  the sweep still derives the literal-runtime historical names —
  `probe_miss_transient_name()`, and `probe_miss_transient_ids()`
  covering every plan × region × source label). The writer delegates
  to the same owner and uninstall.php iterates the two settings
  classes instead of hand-mirroring the formula, the source-label set,
  and the option constants — under a persistent object cache a
  composition change can no longer strand hashed markers no sweep
  finds (GLM5 #11's label split already forced one such lockstep edit
  on the mirror), and a drift between writer and sweeper now fails a
  test that plants markers through the real availability flows of both
  surfaces.

- Structural cleanups, no behavior change beyond the pinned intents:
  the three content-event cases' verbatim guard blocks are one
  `content_frame_index()` helper; the per-content map memo the two
  directories carried as verbatim twins (GLM7 #13 / GLM8 #9) is one
  `ZaiDiscoveryCache::memoized_map()`, bounded per endpoint cache id —
  strictly dominating the per-instance single entries, which thrashed
  whenever both providers were consulted alternately; the two model
  classes' byte-identical `generate_text()`/transporter-wrap/
  credential-gate sequence rides one `SafeGenerationBoundary` trait (a
  sibling of `ThrowsSafeHttpErrors` — no common base is possible
  across their different SDK parents) with per-surface wiring hooks,
  each routing to its own availability class; the stop-sequence and
  tool-identity guards ride the shared `JsonEncodeGuard` parameterized
  by provider label (the duplication had already forced one re-land:
  GLM3 #3 → GLM7 #7), messages byte-identical; the availability
  layer's two copy-pasted optional-authentication ternaries are one
  `effective_for_authentication()`; and the replay guard gained a
  structural fast path for `json_decode()`-produced values — the
  inbound acceptance points re-serialized already-validated immutable
  arguments on every request (O(K·S) JSON work growing with the
  conversation), and decode-origin trees provably cannot carry the
  hazards the string round trip detects, so the walker alone decides
  them while the outbound sites keep the full oracle for caller-built
  values.

- Parse-CPU halving on the dominant stream frame: the Anthropic
  aggregator decodes each frame's payload ONCE — the
  non-associative, object-ness-preserving decode whose tree every
  case now reads directly. The associative decode it dropped is what
  collapsed `{}` and `[]` (the reason the second decode existed as the
  object-ness oracle), and it ran for every `content_block_delta`,
  i.e. once per output token — double the OpenAI aggregator's parse
  CPU on the dominant frame type of the dominant transport. The usage
  members hand the shared validator the same two views it always
  judged by (the `(array)` cast as the associative view, the property
  itself as the raw oracle, a JSON null keeping its null-ness), a
  47-class differential harness verified byte-identical outcomes
  against the previous implementation, and the `{}`/`[]` distinction
  is pinned in one place for tool inputs and usage members alike.

### Fixed (zai / M2 — GLM8 code review)

- Silent wrong-success holes in the streamed-transport layers closed: a
  bare `event: error` frame truncated right after its `event:` line (no
  `data:` line at all) was invisible to the Anthropic aggregator — no
  error flag, no malformed count — so a COMPLETE valid stream followed
  by the bare declaration aggregated as a success; the declaration
  itself is the error signal (the GLM7 #4 policy its undecodable- and
  non-object-payload siblings follow), in both phases and in the
  finish()-flushed cut-between-lines variant. And the body sniff and
  the frame buffer now share ONE canonical stream-prefix rule
  (`SseFrameBuffer::strip_stream_prefix()` — whitespace, a BOM, then
  whitespace, stripped only when a BOM is present): the sniff privately
  ltrimmed and BOM-stripped while the buffer stripped the BOM at byte 0
  only, so a whitespace-then-BOM body (or its BOM-then-whitespace
  mirror) misrouted to the SSE aggregator whose first frame then
  matched no field — silently dropped, corrupted content as success,
  where master failed loudly. Whitespace WITHOUT a BOM still strips
  nothing (the spec-correct dropped first frame, byte-identical to
  master), the buffer holds undecided whitespace and split BOMs across
  chunk boundaries, and an alignment pin feeds every prefixed body at
  every chunk boundary against the canonical composition.

- Schema-tolerance gaps on the JSON path: a UTF-8 BOM prepended to an
  otherwise-valid JSON Messages body died as 'Missing the "content"
  key' (the vendor decode fails on the BOM — the same gateway threat
  class this branch's own SSE-side hardening already tolerated, one
  request short); an explicitly-present `"stop_reason": null` was
  treated as MISSING although the Messages schema types it
  string|null (accepted now, mapping to the neutral natural-stop
  finish reason while the stop-reason/content consistency check still
  rejects a null over tool blocks — the streamed twin's truncation
  channel is untouched); a valid JSON body mislabeled
  `text/event-stream` (the sniff trusts the header, and there was no
  JSON fallback after aggregation) died as 'malformed event frame' —
  aggregation failing is now the signal to try the JSON the label
  promised, with garbage and genuinely truncated streams keeping the
  stream-typed verdict and the typed truncation exception propagating;
  and a non-empty tool schema carrying an empty-array member at an
  object-demanding keyword (`"properties":[]`) shipped where the
  protocol's meta-schema wants an object — the object-map keywords
  (properties/patternProperties/definitions/$defs) normalize
  recursively at every depth, list-valued keywords like `required`
  keep their schema-valid `[]`, and (verifier round) the
  data-bearing annotation keywords `default`/`examples`/`const` pass
  through verbatim: the walk initially descended into them, silently
  converting a caller's `[]`-valued default data to `{}` with no
  upstream error to surface the change.

- Tooling and invalidation plumbing: the live probe's getopt used
  optional-value `'option::'` declarations, which in PHP capture only
  the `--option=value` form — the conventional space-separated form
  returned false, cast to `''`, and rejected a valid invocation as
  '--surface must be openai or anthropic' for exactly that value;
  required-value declarations accept both forms, a bare `--option`
  (which getopt drops silently or swallows the next token for) is
  detected against raw argv with a truthful 'requires a value'
  diagnostic, and a repeated option normalizes to a rejection instead
  of casting 'Array' (pinned by keyless subprocess runs). The
  discovery cache-id formula (prefix + md5(scope|plan|region) plus the
  miss suffix), hand-composed in five places with the suffix bypassed
  literally at three, is owned once by the endpoint layer
  (`discovery_cache_id()`/`discovery_transient_ids()`), consumed by
  the settings invalidation, both directories, uninstall.php, and the
  probe — the owner chain stays SDK-free loadable (pinned by a
  subprocess test that composes without vendor/), the composition is
  frozen to the historical formula the invalidation/uninstall tests
  still seed by, and a source pin (now swept over the whole plugin and
  probe surface) forbids any consumer from re-rolling it. (Verifier
  round: the settings invalidation and uninstall now DEGRADE when the
  owner chain cannot load — a quarantined src file on a partially
  updated install used to fatal the WP Delete request with zero
  cleanup calls, before any deletion, bricking uninstall on every
  retry; the class-free option deletions run first and only the
  class-derived transient sweep is skipped, never fataled.)

- Structural cleanups, no behavior change beyond the pinned intents:
  the aggregators' byte-identical frame-consumption protocol
  (constructor/feed/finish/pull loop) rides one
  `AbstractSseAggregator` base (extraction-pinned); the OpenAI-surface
  directory memoizes its metadata map per transient content (the exact
  GLM7 #13 memo the Anthropic twin already had — the rebuild ran twice
  or more per AI request); the two endpoint classes' value-object
  skeleton (matrix lookup, unknown-combination rejection,
  current-settings resolution, accessors, api_url(), models_url(),
  cache_key(), base-URL normalization) rides one `AbstractZaiEndpoint`
  base with child-owned identifier constants (reflection-pinned,
  phpstan static:: accesses pinned to their exact counts) — bringing
  the double-append guard to the OpenAI surface, which ran a bare
  rtrim; the providers' identical `createModel()` capability walk and
  `createProviderMetadata()` ride the shared provider base with a
  `model_class()` hook; and the sequential-key JSON-list predicate,
  hand-rolled four times, is one `JsonShape::is_list()` — documenting
  once the trap the four copies proved by existing (the empty array
  needs its own clause: `range(0, -1)` is a descending two-element
  sequence, not `[]`).

### Fixed (zai / M2 — GLM7 code review)

- Stream-merge parity and error channels: the legacy zai merge rejects
  unusable chunk-choice and tool-call-delta indexes typed (missing,
  null, non-integer, or negative indexes were silently skipped — losing
  that delta's content from a successful stream — or int-coerced into
  the WRONG accumulator, where the Anthropic twin added in this branch
  rejects the identical corruption as a malformed event), and the
  `[DONE]` sentinel now terminates the CONTENT stream only: a frame an
  appending gateway emits after the sentinel still completes the payload
  with terminal metadata it lacks — a finish reason for an accumulated
  choice missing one, a usage member when no usage data merged — never
  overwriting data already carried (GLM5 #7's mutation guard, narrowed
  to the overwrite shapes it existed to stop), never opening new choice
  turns, restoring master's behavior for the documented
  final-chunk-after-sentinel gateway shape that previously failed the
  SDK parse or silently zeroed token usage (verifier round: an EMPTY
  pre-sentinel `"usage":{}` member carries no token data and is
  completable too — it must not block the gap-fill). On the Anthropic
  twin, an `event: error` DECLARATION with a malformed payload (truncated
  JSON, or a decodable non-object) sets the error flag in BOTH phases —
  a proxy cutting the connection mid-error-event previously surfaced as
  "malformed event frame", indistinguishable from protocol corruption;
  a lost or shapeless `message_delta` frame flags the stream malformed
  through the same channel as its missing-`message_start`/`message_stop`
  siblings instead of the vague "No usable message event" verdict.

- Guard and message parity: the zai surface validates stop sequences
  per entry (non-empty strings; `['']`/`[0]` encoded fine and shipped
  verbatim) and requires non-empty tool-call ids and names typed (the
  `(string)` casts let a null id pass while null rode the wire) — the
  zai_anthropic twin's GLM3 #3/Codex R9 #3 contracts; the
  `WIRE_FORWARDED` rejection keeps its shared RULE but justifies itself
  truthfully per surface (the zai surface's SDK-parent builder really
  would send the value; the zai_anthropic builder never emits those
  keys, so its message cites the cross-surface option contract instead
  of a forwarding it does not perform); and the zai_anthropic
  tool-arguments judgment runs ONE serialization pass (the replay
  guard's own first branch already proved encodability — the separate
  `must_encode()` encoded the identical tree only to discard it, four
  serializations per replayed call in a tool loop).

- Availability and discovery plumbing: region-switch distrust no longer
  bypasses the 60s probe-miss negative cache (every consult re-issued a
  live blocking authenticated HTTPS probe — re-transmitting the
  old-region credential to the new endpoint — for as long as the
  endpoint answered inconclusively, with no cap); a definitive 401/403
  on the zai_anthropic `/v1/models` route records the invalid verdict
  through the availability layer's own persist path before throwing a
  status-aware error naming the auth rejection (previously
  indistinguishable from a malformed body and converted into a silent
  60s `_miss` marker plus static-plan fallback with no persisted
  verdict); and the live probe catches `Throwable` at both step
  handlers, so a PHP `Error` from a strict-types mismatch reports the
  step FAILED instead of crashing with an uncaught stack trace outside
  the safe-facts contract.

- Surface semantics restored and hot paths thinned: the legacy zai
  surface keeps master's usage semantics through a lenient validator
  mode (`"usage":null`, `"usage":[]`, and explicitly-null members count
  as absent/zero — the GLM5 #3 shared validator had silently extended
  the Anthropic surface's strict rejection to every existing zai
  consumer; genuinely corrupt shapes still reject on both surfaces);
  non-streaming bodies decode ONCE per flavor with the SDK parent
  receiving the pre-decoded payload (three full-body parses where
  master paid one); the zai_anthropic metadata map is memoized per
  transient content (the full rebuild-plus-sort ran on every
  list/has/get call — two or more per AI request — while the transient
  read stays authoritative); the tool_use input shape probe uses the
  RAW `json_encode()` oracle (production `wp_json_encode()` lossily
  rescues invalid UTF-8, so the defensive branch decided differently
  under the test stub than in production for the same payload); and the
  env-to-constant credential ladder, hand-rolled three times over, is
  one shared `env_constant_ladder()` in the SDK-free settings layer.

- Structural cleanups, no behavior change: the Anthropic aggregator's
  termination state is ONE flag (`$done` and `$terminated` were always
  set in the same statement), and the `event:`/`data:` field parsing
  copy-pasted between both aggregators' `consume_frame()` methods is
  one shared `SseFieldParser` with its behavior contract pinned by a
  dedicated suite. Deliberately left for later (verifier-swept,
  low-value or latent): the `ZaiDiscoveryCache` empty-list positive
  cache (unreachable by current parsers), the `PreDecodedResponse`
  null-body/empty-headers latent hazards, `ZaiModelMetadataDirectory`'s
  re-entrant `$discovery_endpoint`, the dead `Plugin::PROVIDER_ID`, and
  the live probe's hardcoded option/cache literals.

### Fixed (zai / M2 — GLM6 code review)

- Guard-parity round two — every guard suite this branch built had gaps
  where it was not APPLIED. On the zai (OpenAI-compatible) surface,
  tool-call arguments that fail JSON decode are a typed rejection
  instead of a fabricated no-argument call (a stream that loses one
  arguments fragment, or a truncated body string, previously SUCCEEDED
  with `getArgs() null` — a consumer could execute a possibly
  side-effecting tool with arguments the model never produced; the
  empty string keeps the parent's null-args semantics, since the
  streamed aggregator structurally consolidates zero-argument calls to
  `''`); the object-ness walk covers LIST-rooted arguments too, so
  nested `{}`/numeric-keyed objects no longer re-encode as JSON lists
  under a list root; and the GLM3 #4/GLM4 #1 wire-value encodability
  oracle finally guards this surface's caller-authored values —
  visible text parts, the system instruction, stop sequences, declared
  tool names/descriptions/schemas, tool-call ids/names, tool-result
  values (whose plain `json_encode()` failure shipped
  `"content": false`, telling the model the tool returned nothing),
  and the sampling floats/response-format schema — as typed
  pre-transport 400s instead of the transport's untyped
  `JsonException` surfaced as the generic 500. The shared
  `JsonEncodeGuard` message names the consuming provider via a new
  call-site label parameter (zai_anthropic messages unchanged).

- The streamed chat.completions path judges what the payload carries:
  the usage oracle is captured under the SAME condition the
  consolidation merges by, so a trailing non-merging `"usage"` member
  (a string, a null) can neither reject a valid generation nor flip
  the verdict through the object-ness fallback; the WHOLE consolidated
  payload passes the raw-`json_encode()` encodability oracle (an INF
  `finish_reason` previously collapsed the re-encoded body to `''` and
  the failure surfaced as the generic "payload was malformed", masking
  the cause); and the consolidated payload reaches the SDK parser
  already DECODED (a pre-decoded `Response` shim), deleting the
  `wp_json_encode()`/re-decode round trip this branch removed from the
  Anthropic twin in GLM2 #10 — pinned at the source level since the
  hand-off is behavior-preserving by design.

- The Anthropic aggregator keeps what it validates: `message_delta`'s
  input-side usage counts (`input_tokens` plus the cache variants) are
  stored, overflow-checked, superseding the `message_start` estimate
  exactly like the one usage object of the identical non-streaming
  body — a stream whose start carried no usage previously aggregated
  `input_tokens: 0` (silent usage/billing undercounting). A trailing
  `event: error` frame whose payload fails JSON decoding reaches the
  error policy ("error event") instead of the "malformed event frame"
  verdict the unified pipeline's undecodable branch mis-assigned. The
  zai_anthropic OUTBOUND tool-argument path applies the shared
  `ToolArgsReplayGuard` (encodability alone let a precision-loss
  integral float beyond `PHP_INT_MAX` ship silently, where this
  surface's own inbound parser and the zai mapper reject the identical
  value), and the declared/replayed tool identity strings (declaration
  name and description, `tool_use` id/name, `tool_result`
  `tool_use_id`) route through the shared encodability guard like
  every other wire string.

- Structural repairs: the provider-card description literals sit
  inside their `__()` calls again (the shared-base indirection was
  invisible to literal-scanning POT extractors — regenerating a
  catalog silently dropped both msgids); the shared `EventStreamSniff`
  strips leading whitespace with PHP's DEFAULT `ltrim` set
  (NUL/vertical-tab-first streams misrouted to the JSON parser under
  the narrowed list, where master's bare `ltrim()` aggregated them);
  the idempotent `LoggingHttpTransporter` wrap rule lives in one
  `wrap()` helper instead of five copy-pasted `setHttpTransporter()`
  blocks; and the provider identifier constants (option/env names,
  section ids, labels, cache scopes, `PROVIDER_ID`) are declared by
  the provider CHILDREN — the shared bases carried the zai provider's
  values as runtime-dead defaults, so a future child forgetting one
  override would silently read and write the zai provider's options; a
  missing declaration now fails loudly, pinned by reflection tests
  (with the bases' `static::` accesses count-pinned in
  `phpstan.neon.dist`, since PHPStan cannot express abstract class
  constants).

### Fixed (zai / M2 — GLM5 code review)

- The zai (OpenAI-compatible) surface reached inbound-hardening parity
  with the zai_anthropic surface: four guards that had landed on the
  Anthropic surface only now protect this surface's ordinary tool loop
  too. Tool-call arguments are re-derived from a RAW (non-associative)
  decode through the shared `Support\ToolArgsObjectNess` walk (moved
  out of the Anthropic model, GLM1 #2), so a nested `{}` or
  numeric-keyed object no longer re-encodes as a JSON list on the
  conversation's very next replay — silently altered arguments the
  model never produced. The shared `ToolArgsReplayGuard` (GLM4 #2)
  rejects arguments decoding to INF (`1e999`) or a lossy
  beyond-`PHP_INT_MAX` float before acceptance, where the SDK parent's
  outbound plain `json_encode()` previously shipped
  `"arguments": false` on every later request. And both transports
  validate their usage member through the now-neutral shared validator
  (`Support\UsageValidator`, renamed from `AnthropicUsageValidator`
  and parameterized by member set): a string/INF member was a raw
  strict-types `TypeError` from `TokenUsage` (generic 500), and a
  streamed INF member collapsed the consolidated body to `''`, masking
  the real cause as "payload was malformed". (Verifier round: the
  replay guard also runs on OUTBOUND caller-supplied arguments — the
  SDK parent's mapper plain-`json_encode()`s them, silently shipping
  `"arguments": false` on a generation that then succeeded — and the
  streamed usage validation now decides through a real object-ness
  oracle the aggregator hands along, so a streamed final `"usage": []`
  no longer slips past where the identical non-streamed member rejects.)

- A Messages refusal turn that cannot be replayed is no longer a
  generation (GLM5 #4, completing the GLM3 #1 parse/replay contract):
  the documented tolerance for `content: []` under `stop_reason:
  "refusal"` manufactured a ZERO-part assistant Message that this
  adapter's own outbound mapper rejects pre-transport — appending it to
  the history poisoned every later request of the conversation, and
  `toText()` threw the SDK's untyped `RuntimeException`. Zero parts now
  reject through the typed channel regardless of the stop reason; the
  protocol's ordinary shape (refusal WITH content) still parses as a
  successful `contentFilter` result and replays cleanly.

- Two Anthropic-surface parser edges closed: the content-block `type`
  member is `is_string`-guarded before the `switch` (loose `==`
  semantics accepted `true`/`0` as `'text'` on the declared PHP 7.4
  target, bypassing the typed unsupported-type rejection — the GLM2 #5
  coercion class), and `tool_use` id uniqueness spans the WHOLE outbound
  history instead of resetting at every role change (the same id reused
  across two properly answered assistant turns shipped ambiguous
  identities).

- `data: [DONE]` is terminal on the OpenAI streaming surface: the
  sentinel set a flag nothing consulted, so frames an intermediary
  APPENDED after it still merged into the aggregated payload — content
  concatenated, finish reason and usage overwritten — silently mutating
  a completed generation (parity with the Anthropic twin's
  message_stop latch). On the Anthropic aggregator, the `event:` field
  parser trims field-value whitespace (spaces AND tabs, both ends) and
  treats an EMPTY name as absent, so a spec-legal `event:` value or a
  tab-separated `event:\t<name>` no longer trips the event/payload
  agreement rule and invalidates an otherwise valid whole stream.

- Plan/region settings invalidation compares hook payloads type-aware:
  the previous `(string)` casts raised an Array-to-string warning per
  side and equated two DIFFERENT corrupt array values
  (`'Array' === 'Array'`), silently skipping the availability-state and
  discovery-cache invalidation a plan change must perform. Scalars keep
  their comparison; non-scalars compare by strict identity (distinct
  arrays are CHANGED — the safe direction).

- A DATABASE-only API key validates for real: the SDK registry wires
  provider credentials from env/constant only, so whenever no auth was
  wired the availability probe threw the binding `RuntimeException`,
  counted inconclusive, and `isConfigured()` reported connected
  (configured-pending) forever without a single validation request —
  defeating the class's own "nonempty-but-invalid key must report
  not-connected" contract. The unwired probe now authenticates with the
  EFFECTIVE key through a per-surface `fallback_authentication()` hook
  (protocol-wrapped on zai_anthropic). The credential binding is also
  stable across the save→store transition: the `'runtime'` save-time
  candidate label normalizes to the `'database'` identity at binding
  construction, so a fresh invalid verdict persisted while the candidate
  was wired still refuses the identical credential once it is read back
  from the stored option.

- Uninstall deletes the DERIVABLE probe-miss transients directly
  through the transients API (the current env/constant/stored credential
  under every source label, across every plan × region endpoint of both
  surfaces): the wpdb option-name LIKE sweep sees nothing when a
  persistent object cache (Redis/Memcached) backs transients, so the
  binding-hashed markers survived uninstall with no path that deleted
  them. The sweep itself is null-guarded (real `get_col()` returns null
  on a database error — a foreach over null warned and silently skipped
  the cleanup while uninstall reported success), and the debug flag
  gained the `add_option_{option}` fresh-install companion so the first
  persisted save of a disabled flag still clears the log. `ZAI_VERSION`
  is defined behind a `defined()` guard: a foreign plugin defining the
  same generic constant first no longer triggers a per-request notice.

- Cleanup, same round: the raw-`json_encode()` encodability oracle is
  single-sourced on `Support\JsonEncodeGuard` (seven inlined call sites
  with drifted messages unified); the credential-refusal gate WRAPPERS
  are absorbed into the availability layer's
  `refuse_generation()`/`refuse_discovery()` (four hand-synced copies,
  each surface now contributing only its wiring); the Anthropic SSE
  aggregator runs every frame — including post-`message_stop` trailing
  frames — through ONE decode/agreement pipeline with a single
  post-termination policy handler (verifier round: trailing frames skip
  the declared-object-shape gate, so an `event: error` frame with a
  malformed payload still sets the error flag exactly as the GLM4 #6
  policy documents); and two provably dead branches were removed with
  their docblocks corrected (`advance_answer_window()`'s non-user
  expiry and the empty-array `elseif` in `parse_content_block()`).

### Fixed (zai / M2 — GLM4 code review)

- The R18/R19/R20 unencodable-value guards now use the RAW
  `json_encode()` oracle (the GLM3 #4 primitive) instead of
  `wp_json_encode()`: core's sanity fallback lossily rescues invalid
  UTF-8 and never returns `false` for a string in production, so the
  guards were dead code on real sites — only the deliberately stricter
  test stub made the committed rejection tests pass. An invalid-UTF-8
  tool result was silently re-encoded and shipped (the model was told
  altered tool output), an unencodable declared tool schema or
  outputSchema threw the transport's raw `JsonException` as the generic
  500 `zai_error`, and the outbound `tool_use` input had no
  encodability check at all (only shape checks) — all three oracles are
  raw now and the outbound tool arguments gain the same typed
  pre-transport rejection.

- Tool arguments decoded from responses are round-trip checked before
  acceptance: `1e999` decodes to INF and an integer beyond
  `PHP_INT_MAX` to a lossy float, and both flowed into `FunctionCall`
  args whose replay threw at the transport's whole-request encode —
  poisoning every later request of that conversation (the GLM3 #1
  "a turn that cannot be replayed cannot be a generation" contract,
  applied to argument values). The new shared
  `Support\ToolArgsReplayGuard` (raw-encode oracle, decode/re-encode
  stability, and an integral-float-beyond-int-range walk) is enforced
  at all three inbound acceptance points — the non-streaming parser
  and both SSE acceptance points — so the two transports of one
  generation can never diverge on what replays. (Verifier round: the
  one benign encoding instability — negative zero, whose shortest
  encoding `-0` decodes to the int `0` — is compared semantically and
  accepted, so a valid `{"delta":-0.0}` argument still parses.)

- The zai (OpenAI) surface's event-stream sniff reached parity with the
  Anthropic twin through one shared `Support\EventStreamSniff`: the old
  inline copy recognized only a leading `data:` line, so a gateway that
  mangles or omits the `text/event-stream` Content-Type and prepends a
  UTF-8 BOM, a `: keepalive` comment, or an `event:`/`id:`/`retry:`
  field misrouted the stream to the JSON parser and every such streamed
  generation died as "The chat-completions payload was malformed"
  although the shared frame buffer would have parsed the stream fine.
  The sniff recognizes all five SSE-only body leads now (a JSON body
  never starts with one) and lives once, so the two surfaces cannot
  drift again.

- Explicitly-set falsy values of the wire-forwarded unsupported options
  (`presencePenalty`, `frequencyPenalty`, `logprobs`, `topLogprobs`)
  are rejected typed before transport: the SDK's OpenAI request builder
  forwards every non-null value of these options onto the wire, so
  neutralizing with the only non-nullable neutral value
  (`setLogprobs(false)`, `setTopLogprobs(0)`, `setPresencePenalty(0.0)`)
  passed the `!empty()` guard and then shipped `"logprobs":false` /
  `"top_logprobs":0` / `"presence_penalty":0` anyway — a spec-faithful
  OpenAI-compatible endpoint rejects `top_logprobs` without
  `logprobs=true`, and the caller got the generic upstream error where
  the guard exists to give the precise local one. The never-forwarded
  options (`topK`, `webSearch`, the output-* family) keep the falsy
  tolerance: a set value there is wire-inert.

- A usage total past `PHP_INT_MAX` is a typed `zai_invalid_response`
  instead of an uncaught `TypeError`: every member passed the
  is_int/non-negative validation individually, but int+int overflow
  silently promoted the sum to float and `TokenUsage`'s int-typed
  constructor threw, surfacing as the generic 500 "The z.ai request
  failed." The totals are computed with an explicit per-member bound
  check (so no intermediate ever promotes and the exact-boundary total
  `PHP_INT_MAX` stays representable), and the streamed input-side sum
  is overflow-checked too.

- Trailing SSE frames after `message_stop` are judged by the same
  dispatch rules the main path applies instead of a private
  post-termination whitelist: the old copy accepted a trailing
  `event: error` regardless of a contradicting payload type (the main
  path rejects event/payload contradictions), and any trailing event
  name outside its whitelist — a future benign telemetry/heartbeat
  frame — marked the whole stream corrupt and discarded an otherwise
  fully-received generation. Error events still set the error flag,
  frames declaring a known content-bearing event still invalidate (they
  would mutate a completed generation), and unknown trailing names are
  tolerated noise.

- The x-api-key header strip no longer doubles a GET request's query
  parameters: the request rebuild passed `getUri()` — which already
  folds GET array data into the query string — together with the data
  component, so the rebuilt request appended every parameter a second
  time on its own next `getUri()` call. For that one shape the folded
  URI now travels without the data component (wire-identical for GETs);
  unreachable from the current plugin callers, but it is the
  defense-in-depth reuse case the strip documents.

- The credential-refusal gate lives once on
  `AbstractZaiProviderAvailability` instead of copy-pasted at four
  credential consumers (both model surfaces, both metadata
  directories) with already-divergent wiring:
  `generation_refusal_for_wired_authentication()` is the one predicate
  all four consult and `refusal_message()` the one builder for both
  model surfaces' fixed wording — the next gate-rule change can no
  longer land on some surfaces and leave the others authenticating a
  region-pending or definitively-rejected key against the newly
  selected endpoint. The same single-source treatment went to the
  discovery-cache orchestration (`Metadata\ZaiDiscoveryCache`: cache-id
  build, negative marker, TTLs, plan fallback, and the chat-filtered
  map — previously duplicated line-for-line between the two
  directories) and to the Messages usage validation
  (`Support\AnthropicUsageValidator` — the parser and the aggregator's
  streamed copies had to be fixed in lockstep once already). The
  unknown-model rejection test now pins the SDK-typed
  `InvalidArgumentException` the directory actually throws (the file
  never imported it, so the assertion bound the global class the SDK
  exception merely subclasses — an untyped regression would have kept
  passing).

### Fixed (zai / M2 — GLM3 code review)

- An inbound turn with zero translatable parts no longer parses as a
  successful generation: the parser accepted an empty text block
  (`{"type":"text","text":""}`) and thinking-only turns, but the
  outbound mapper drops exactly those parts and rejects the turn on
  replay — one such response permanently poisoned the conversation
  history, and every later request in that conversation failed
  pre-transport until the turn was removed (the streamed path shared
  the gap). The parser now applies the outbound contract at parse time:
  a turn that cannot be replayed cannot be a generation.

- A response whose content list is empty or consists only of unmapped
  block types no longer parses as a SUCCESS with zero parts: the
  stop-reason/content consistency check cross-checked `tool_use` alone,
  contradicting the guarantee documented at the `parse_content_block()`
  drop site — consumers hit the SDK's untyped `toText()`
  RuntimeException instead of the typed `zai_invalid_response` channel.
  Zero-part responses now reject regardless of the stop reason, with a
  message naming the dropped blocks when that was the case. One pinned
  tolerance is preserved: `content:[]` under `stop_reason` `refusal` is
  the protocol's pre-output-refusal shape and keeps surfacing as a
  successful `contentFilter` result.

- Stop sequences are validated per element before transport: entries
  were only checked for list-ness, so a non-string or empty-string
  entry (`[0]`, `['']`, `['END', null]`) reached the wire verbatim and
  failed upstream with the generic misattributed client-error message
  instead of the typed `zai_invalid_request` rejection every
  neighboring malformed input already receives.

- Invalid-UTF-8 strings in text parts, the system instruction, and stop
  sequences are rejected before transport via the raw `json_encode()`
  oracle — the same primitive the SDK transport's request-body encode
  throws on (`mb_check_encoding()` was considered and rejected:
  WordPress does not require ext-mbstring; core's `wp_json_encode()`
  was rejected too after the verifier round confirmed empirically that
  its sanity fallback lossily rescues invalid UTF-8 and never returns
  `false` for a string, which would have made the guards dead code in
  production). Previously these strings detonated as a raw
  `JsonException` in the transport's whole-request encode and surfaced
  as the generic 500 `zai_error` while the same unencodable value in a
  tool result got the precise typed 400 rejection.

- A stream-shaped body opening with a legal SSE comment line
  (`: keepalive`) or a UTF-8 BOM is no longer misrouted to the JSON
  parser when the gateway omits or mangles the `text/event-stream`
  Content-Type (it failed with `Missing the "content" key` instead of
  returning the aggregated completion): the body sniff recognizes a
  leading comment line — `:` can only be SSE framing, a JSON body never
  starts with one — and skips a leading BOM.

- Trailing frames after `message_stop` are routed by their `event:`
  name: the keepalive whitelist matched only data payloads decoding to
  exactly `{"type":"ping"}`, so a type-less ping (`event: ping` +
  `data: {}`), an OpenAI-style `data: [DONE]` sentinel appended by a
  gateway, or an error event all marked the stream malformed and
  discarded an otherwise fully-received generation. Pings and `[DONE]`
  sentinels are now tolerated, error events set the error flag (the
  typed stream-error message, not the generic corruption one), and
  every other post-termination frame is still corrupt; where both the
  event name and the payload type declare, they must agree.

- A leading UTF-8 BOM prepended by a gateway/CDN is stripped once at
  stream start in the shared SSE frame splitter (both surfaces
  inherit): the BOM previously glued itself to the first frame, which
  then matched no `data:`/`event:` prefix and was silently dropped —
  not even counted malformed — so a single-event stream aggregated to
  null and a multi-event stream lost its first delta. A BOM split
  across chunks is held until its bytes disambiguate; a mid-stream BOM
  stays frame content.

- The settings-save guard requires a string `option_page` before
  calling `sanitize_key()`, and the harness's `sanitize_key` stub now
  mirrors core exactly (scalar-only sanitization, no string coercion,
  the `sanitize_key` filter firing with the raw value). Verified
  against core source: `sanitize_key()` guards with `is_scalar()` and
  returns `''` for non-scalars, so there is no production TypeError —
  the defect was the stub's `(string)` cast silently masking array
  POSTs (`option_page[]=x`), which a `FoundationHarnessTest` fidelity
  pin now prevents from recurring.

- A foreign (non-API-key) request-authentication wiring on the
  zai_anthropic surface no longer surfaces as a 400
  `zai_invalid_request` thrown before option validation: the credential
  gate consulted its protocol-wrapping `getRequestAuthentication()`
  override, whose `wrap()` threw through the gate's
  RuntimeException-only catch. The gate now reads the raw wired
  instance (the OpenAI twin's instanceof early-return pattern), and
  `wrap()` refuses foreign wiring with the binding-family
  `RuntimeException` — so wherever a wiring failure eventually surfaces
  (model request-build, directory discovery, availability probe) it
  maps to 500 `zai_error`, never the caller-input 400 channel.

- OpenAI-surface model discovery parses and caches with the endpoint
  captured at request time, matching the zai_anthropic twin: the parse
  re-resolved the current settings, so a plan save landing during the
  HTTP round-trip filtered the old endpoint's response with the new
  plan's catalog and cached the wrong list under the old endpoint's key
  for the 12-hour discovery TTL.

### Fixed (zai / M2 — GLM2 code review)

- A conversation history ending on an assistant turn with unanswered
  `tool_use` blocks is now rejected before transport with the adapter's
  typed tool-linkage error. The end-of-history completeness check
  required the last turn to be a `user` turn, so the trailing unanswered
  tool turn shipped to the wire and failed as an upstream 400 surfaced
  through the generic client-error channel. A trailing assistant TEXT
  turn (the prefill shape) stays legitimate.

- Scalar tool-call arguments are rejected before transport: the outbound
  `tool_use` input validation caught only non-empty sequential arrays, so
  a scalar argument (a string like `'Oslo'`, an int, a bool, NAN/INF
  floats) reached the wire as a non-object `input` — an upstream 400
  with the generic surface, or a raw `JsonException` from the transport's
  whole-request encode for the unencodable floats — despite the adjacent
  comment claiming scalars were rejected. The empty string joins null
  and the empty array in the `{}` no-argument normalization; `stdClass`
  arguments (the inbound parser's nested object-ness preservation) pass
  untouched.

- The zai_anthropic credential gate no longer reads the wired
  authentication before `validate_request()` runs: an unbound model
  (directly constructed, without the registry binding) carrying invalid
  options threw the SDK's binding `RuntimeException` instead of the
  typed option rejection — the exact divergence the zai (OpenAI)
  surface's gate already guards against. The gate is extracted to
  `refuse_refused_credentials()` mirroring the twin, and skips unbound
  models so the typed rejection wins on both surfaces.

- NAN temperature/top_p values are rejected by the sampling-parameter
  range guard: NAN compares false against both bounds of the
  closed-interval check, so the unencodable float reached the transport
  and threw a raw `JsonException` instead of the typed rejection the
  guard exists to produce. `is_nan()` is checked explicitly alongside
  the bounds.

- A non-string `stop_reason` is rejected by an `is_string` shape guard
  like every sibling envelope member (type, role, id): the bare
  `(string)` cast on a list-shaped value emitted an Array-to-string
  warning before the typed rejection and, on warning-strict installs,
  aborted the parse with an `ErrorException`-family throwable that
  bypassed the `zai_invalid_response` channel.

- The live probe (`bin/zai-live-probe.php`) clears the negative-cache
  markers alongside the positive caches before its acceptance steps: a
  re-run within the 60-second marker TTL of one transient failure served
  the cached inconclusive verdicts (`DISCOVERY FALLBACK`,
  `INCONCLUSIVE`) with zero live requests instead of exercising the live
  network path. New
  `AbstractZaiProviderAvailability::clear_probe_miss_marker()` derives
  and deletes the exact binding-scoped marker the next consult would
  read (the transient-name construction is shared with the writer).

- Uninstall enumerates and deletes the availability probe-miss
  transients, whose names embed an md5 of the credential+endpoint
  binding and are therefore unknowable at uninstall time: the rows are
  matched by option-name prefix via one prepared LIKE query per state
  option and removed through `delete_transient()` (which also removes
  the timeout companion row), per site on multisite. The test harness
  gains a minimal `wpdb` stub so the sweep is exercised by the existing
  single-site and multisite uninstall tests.

- One unauthorized settings save records exactly one
  `zai_connector_unauthorized` settings error: both provider settings
  classes hook their guard on the shared option group, so the strip-and-
  notify path ran once per provider and appended byte-identical errors.
  The strip stays per-guard (idempotent); the emission consults the
  core getter `get_settings_errors()` scoped to the group's setting slug
  and stays silent when the notice already exists. (The verifier round
  caught the first cut consulting `settings_errors()` — a display
  function that echoes and returns void in real WordPress — and a
  harness wpdb LIKE emulation that stripped `esc_like()`'s underscore
  escapes; both are fixed with regression pins.)

### Changed (zai / M2 — GLM2 code review)

- The five request-usage rejections the two surfaces advertise
  identically (candidateCount, text-only output modalities, the MIME
  whitelist, text-only input, custom options) and the output MIME list
  are shared once in `Support/AdvertisedUsageGuard`, consumed by both
  model classes' `validate_request()` — they were verbatim twins under
  the `AdvertisedOptionGuard` call introduced to stop exactly that
  duplication pattern. Messages are byte-identical; surface-specific
  checks stay in the owning models.

- The streamed Messages parse passes the aggregator's consolidated
  payload through decoded instead of round-tripping it through the wire
  format (one `wp_json_encode` into a synthetic Response plus two whole-
  payload re-decodes per streamed generation are gone). The non-streamed
  parser keeps both decodes exactly as before; the streamed path relies
  on the aggregator's already-unambiguous shapes (tool input stays the
  raw-decoded object, usage is object-keyed, content is a constructed
  list), with object-ness guarantees unchanged.

### Fixed (zai / M2 — GLM1 code review)

- Failed model discovery is negatively cached for 60 seconds per
  endpoint: every metadata lookup (and every model instantiation) on a
  persistently failing route — the China-region 404 shape — re-issued a
  blocking doomed remote GET. The short miss marker keeps failure
  non-fatal and retryable (after the TTL the endpoint is probed again)
  and never touches the positive cache; the settings invalidation and
  uninstall clear it with the positive cache.

- Inconclusive AVAILABILITY probes carry the same 60-second binding-scoped
  miss marker (verifier completion of the same finding): the per-request
  `isConfigured()` consult no longer pays a doomed blocking GET on a
  persistently inconclusive route. The marker stores no verdict — a cached
  inconclusive returns exactly what a live one returns — and the
  region-switch distrust flow stays EXEMPT so the definitive validation
  happens as soon as the endpoint can answer.

- A keyless `isConfigured()` no longer calls `delete_option()` on every
  invocation: the availability state row is deleted only when it actually
  exists, removing a needless database DELETE per request.

### Fixed (zai / M2 — Codex PR review, round 14)

- A streamed envelope that explicitly declares a nested `message.type`
  other than `message` (e.g. an error object wearing an assistant role)
  now invalidates the stream: `aggregated()` hardcodes the envelope
  type, so the contradictory shape succeeded where the non-streaming
  path rejects it. An explicit `message` type and an absent type member
  keep the documented tolerance.

- A stop reason that contradicts the parsed content is now rejected:
  `stop_reason: tool_use` without any `tool_use` block returned a
  `toolCalls()` signal with nothing to execute, and tool blocks paired
  with an ordinary completion reason (`end_turn`, `stop_sequence`,
  `pause_turn`, `refusal`) executed nothing while signaling completion.
  The check runs in the shared conversion (non-streaming and
  consolidated streams) after the typed truncation exceptions, so
  `max_tokens`/`model_context_window_exceeded` keep their precedence.

- The forward-compatible unknown-delta seed is intact after round 13's
  start-member validation: an unknown future delta type for an index
  with NO preceding content_block_start seeded a bare type-only text
  block, which the new guard marks malformed — breaking the intended
  tolerance. The synthesized block now carries an explicit empty text
  value, so such streams still complete with empty text. Known delta
  types on an unseen index remain rejected.

- Discovery data on the zai_anthropic surface must be a JSON list: an
  object-shaped catalog (`{"data":{"only":{"id":"glm-5.3"}}}`) passed
  the associative `is_array()` check and iterated its VALUES as
  entries, so a malformed `/v1/models` response was treated as
  successful live discovery and cached for 12 hours. The raw
  object-ness oracle now rejects an object-shaped `data` member, and
  discovery falls back to the static catalog exactly like every other
  malformed response. List-shaped catalogs (including the empty list's
  existing fallback) are unchanged.

- A malformed `usage` member on a successful response is now rejected:
  a list-shaped `[1,2]` passed `is_array()`, and strings (`"5"`),
  bools (`true`), floats, and negatives survived the integer casts as
  plausible prompt/completion/total accounting. A present usage member
  must now be a JSON object whose supplied token counts
  (`input_tokens`, `cache_creation_input_tokens`,
  `cache_read_input_tokens`, `output_tokens`) are non-negative
  integers; an absent member keeps the documented default-zero
  tolerance, and a valid `{}` still means zero tokens.

### Fixed (zai / M2 — Codex PR review, round 15)

- Streamed usage is validated BEFORE the aggregator's casts store it:
  a numeric string, float, boolean, or negative in
  `message_delta.usage.output_tokens` (or the three input-token fields
  of `message_start`) was normalized into an integer before the
  consolidated response reached the strict usage validator, and a
  list-shaped usage silently became zero because the named member was
  absent. A present streamed usage must now be object-shaped with
  non-negative integer members (same rule as the response validator);
  absent members keep the default-zero tolerance.

- The final `message_delta` is no longer accepted while a content
  block is still open: a stream that started a block but lost its
  `content_block_stop` frame completed successfully with a truncated
  block lifecycle. Every started (or seeded) block index must be
  stopped before the final metadata is accepted; streams with no blocks
  and fully-stopped multi-block streams behave exactly as before.

- A paginated `/v1/models` response (`has_more: true`) on the
  zai_anthropic surface is now treated as discovery failure: the parser
  previously cached the single returned page for 12 hours, freezing the
  directory to a partial list that could omit known in-plan models.
  The static plan catalog is served instead and nothing is cached.
  Cursor-following was deliberately not implemented — discovery here is
  opportunistic and the plan catalog is authoritative — and the check
  is strict: a present `has_more` that is not exactly `false`
  (including `"true"`, `1`, `null`) fails the same way. `has_more:
  false` and an absent member discover exactly as before.

- Draining the SSE frame queue is now constant-time per frame: every
  `array_shift()` reindexed the remaining PHP array, so consuming a
  long stream (thousands of token-delta frames) was quadratic in the
  frame count — ~57 s to drain 200k frames versus ~5 ms with the new
  read cursor. Public behavior is unchanged (same frames, same order,
  same null-on-exhaustion); the queue compacts itself once drained, so
  a reused buffer instance accepts new feeds.

### Fixed (zai / M2 — Codex PR review, round 16)

- `message_stop` is now required before an aggregation is returned: a
  transport that ended after a valid `message_delta` left `stop_reason`
  populated, so the truncated stream was returned as a successful
  generation while `is_done()` was false. A missing terminal event is
  the same corruption class as the missing `message_start` — the stream
  is marked malformed and no payload is built. This inverts an earlier
  tolerance that treated `message_stop` as conventional; stream
  fixtures now close their lifecycle explicitly.

- Content-block events now require `message_start` at dispatch time:
  the missing-start guard only fired when the flag was still false at
  the END of the stream, so a late-but-valid `message_start`
  legitimized content blocks that had arrived before it and a complete
  lifecycle then produced a successful response from an invalidly
  ordered stream. The malformed flag is sticky — the late start cannot
  launder the early content. Normal ordering (message_start, blocks,
  metadata, message_stop) is unchanged.

### Fixed (zai / M2 — Codex PR review, round 17)

- `message_delta` — and, by the same rule, `message_stop` — now
  require `message_start` at dispatch time, like content-block events
  since round 16: final metadata or a terminal event that arrived
  before the envelope was laundered by a later valid start into a
  successful (empty) completion carrying the late metadata. The
  malformed flag is sticky, so the late start cannot repair it.

- Streamed block starts must form the contiguous zero-based sequence:
  a truncated stream that lost block 0 but delivered a complete block
  at index 1 passed the non-negative-integer index check, and the
  arrival-order repacking made the gap invisible — the surviving block
  became content position 0 of a successful but truncated completion.
  Any gap or reorder (a started index other than the next expected one)
  now invalidates the stream; synthesized seeds from the unknown-delta
  compatibility path obey the same rule, and in-order multi-block
  streams are unchanged.

### Fixed (zai / M2 — Codex PR review, round 17 review body)

- The live probe reports an INCONCLUSIVE availability verdict instead
  of a vacuous `connected`: after the round-13 state-option clear, a
  probe request that fails outright (transport error, 5xx, 429, 404,
  region distrust) answers `isConfigured()` with the credential-"not
  yet disproven" default without persisting a verdict — the step now
  requires the freshly persisted definitive state and fails otherwise.
- The live probe fails on empty generation output: a successfully
  parsed but blank (or whitespace-only) answer to the sentinel prompt
  was reported without affecting the verdict, so all three acceptance
  steps could end in `PASS` despite producing no answer. Empty output
  now fails the probe like the other acceptance steps.

### Fixed (zai / M2 — Codex PR review, round 18)

- A `FunctionDeclaration` with an empty name is rejected before
  transport: the declaration path had none of the identity validation
  the tool-call and tool-result paths already perform, so a malformed
  configuration reached the endpoint's `tools` array only to fail with
  an upstream 400. Identity errors surface before the schema checks
  (first-bad-wins); valid multi-tool configs are unchanged.

- Splitting fed SSE bodies is linear-time as well: the frame splitter
  copied the entire unconsumed suffix once per delimiter, so feeding a
  complete response with thousands of token-delta frames (one
  `feed($body)` call, as both model parsers do) was quadratic — ~3.1 s
  to split 80k small frames versus ~12 ms with the new offset scan,
  which discards the consumed prefix once after the loop. Draining was
  already constant-time since round 15; chunk-boundary semantics,
  `finish()` flushing, and buffer reuse are byte-identical.

### Fixed (zai / M2 — Codex PR review, round 18, second batch)

- An unencodable tool-result value is rejected before transport:
  `wp_json_encode()` failure on a `FunctionResponse` (NAN, a resource,
  a recursive structure) was string-cast to `''`, so the request
  succeeded structurally while telling the model the tool returned no
  content — corrupting the conversation instead of reporting the
  serialization failure. The typed pre-transport rejection now fires in
  the same channel as the other tool-result validations, and the test
  harness's `wp_json_encode` stub matches core's string-or-false
  semantics (it previously masked failures behind a `"null"` string).

- Duplicate declared tool names are rejected before transport: two
  `FunctionDeclaration` entries under one name were both emitted even
  though a returned `tool_use` identifies the selected declaration only
  by that name — the caller could not determine which declaration the
  model selected and might validate or execute the call against the
  wrong tool. Duplicates now throw the typed pre-transport rejection
  next to the empty-name check; distinct-name configurations are
  unchanged.

### Fixed (zai / M2 — Codex PR review, round 19)

- An unencodable `outputSchema` is rejected before transport: a
  constructible but non-JSON-encodable value (NAN, invalid UTF-8, a
  recursive structure) made the guidance encoder return false, which
  the string cast silently turned into an empty string — the request
  succeeded with guidance ending in `JSON Schema: `, so the model
  produced unconstrained output even though the caller requested a
  schema. The typed pre-transport rejection now fires in the same
  channel as the round-18 tool-result encoding failure.

- A successful response must carry a non-empty string `id`: the
  fallback returned a `GenerativeAiResult` with no message identity
  when `id` was absent, empty, or non-string, and the consolidated-
  stream path shared the gap because the aggregator fabricates an empty
  id when `message_start.message.id` is absent. One rejection where the
  two paths merge covers both.

- The public direct-generation path refuses stale environment keys: a
  credential from `ZAI_ANTHROPIC_API_KEY` cannot be deleted by a region
  switch, so the settings layer marks that exact key region-pending and
  the availability layer persists definitive invalid verdicts — yet
  generation authenticated unconditionally, sending the old region's
  credential to the newly selected regional endpoint even while the
  connector reported disconnected. A new availability-layer gate
  (reusing its own state readers, no probe request) is consulted with
  the model's exact credential before authenticating, and generation is
  refused while the key is region-pending or carries a fresh matching
  invalid verdict.

### Fixed (zai / M2 — Codex PR review, round 20)

- Model enumeration no longer discloses a stale environment key to a
  newly selected region: an env/constant credential that survived an
  `intl`/`cn` switch is region-pending (or carries a definitive invalid
  verdict), and while the generation path already refused it, the
  `/v1/models` discovery request still authenticated with it. The
  directory now consults the same availability gate — skipped
  discovery degrades to the static plan catalog (never fatal, never
  cached), and no authenticated request leaves until the credential is
  revalidated.

- A declared tool's parameter schema is JSON-validated before
  transport: an unencodable value (NAN, invalid UTF-8, a resource, a
  recursive structure) previously reached the request untouched and
  failed in the transport's whole-request serialization instead of
  producing the adapter's typed pre-transport configuration error —
  the same `wp_json_encode()` oracle the output-schema and tool-result
  rejections already use. Empty schemas keep their empty-object
  normalization.

### Fixed (zai / M2 — Codex PR review, round 13)

- Content-block events (`content_block_start`/`delta`/`stop`) arriving
  AFTER the final `message_delta` now invalidate the stream: the
  duplicate-delta guard only forbade another `message_delta`, so a
  damaged stream could keep mutating the accumulators after the final
  message metadata and complete successfully with text or tool
  arguments received post-`message_delta`. Streams whose content events
  all precede the `message_delta` aggregate exactly as before.
- A second `message_start` — even a valid one — now invalidates the
  stream: the R12 payload validation ran on every event, so a duplicate
  passed it and overwrote the first message id and input-token usage
  while the generated content still succeeded. The protocol sends
  exactly one; the already-started state is now guarded like duplicate
  block starts and duplicate message deltas.
- A `text`/`thinking` start block that omits its content member or
  supplies a non-string now invalidates the stream instead of silently
  fabricating an empty initial value; later valid deltas could then
  produce a successful response from a known-malformed start payload.
  Valid starts (including an initial empty string) keep their values.
- A response — non-streaming or consolidated stream — containing two
  `tool_use` blocks with the same NON-EMPTY id is now rejected as
  malformed: both parsed independently into two ambiguous `FunctionCall`
  parts, which a consumer cannot correlate results to and which hits
  this adapter's own outbound duplicate-id rejection when the assistant
  turn is replayed after tools may already have executed. Distinct ids
  and single tool calls are unchanged.
- The openai-surface `/v1/models` parse requires its discovery data to
  be a JSON list too (the R14 verifier's twin of the fix above): an
  object-shaped catalog passed the same associative `is_array()` check
  on the zai surface's directory and was likewise cached as successful
  live discovery. Same oracle, same fallback.

- The live probe now clears the selected provider's validation-state
  option before its availability step: within the five-minute TTL the
  persisted verdict previously satisfied the "live" acceptance check
  without any network request, so a revoked credential or unavailable
  route could still report `connected` (mirroring the discovery-transient
  clear the probe already performs).

### Fixed (zai / M2 — Codex PR review, round 12)

- A `message_start` whose `message` member is missing, null, a list, or
  a scalar now invalidates the stream instead of satisfying the
  completion prerequisite: later valid content and `message_delta`
  previously fabricated an assistant envelope with a blank id and zero
  input usage — a bypass of the missing-message_start guard. The
  prerequisite is set only after the payload carries a valid message
  object; a well-formed message_start aggregates exactly as before.
- A user wire turn answering tool calls must carry its `tool_result`
  blocks BEFORE any text block: text preceding a result (across the
  coalescing merge or within a single SDK message's block order) passed
  local validation — the linkage checks consume IDs regardless of
  position — and failed upstream with a 400. Misordered turns are now a
  typed pre-transport rejection; results-then-text order and text-only
  turns are unaffected.

### Fixed (zai / M2 — Codex PR review, round 11)

- Tool-answer completeness is now evaluated at the coalesced WIRE-turn
  boundary, not per SDK message: a multi-tool answer split across
  ADJACENT user `Message` objects was rejected after the first message
  even though the adapter's same-role coalescing emits one valid wire
  user turn containing every result. The window now advances only at a
  role change (or end of history) — the partial-answer rejection fires
  exactly there. Consistently, the stale/expiry semantics are also
  wire-level now: an intervening ASSISTANT text message coalesces into
  the tool turn (its following result is wire-adjacent and valid — the
  R9 SDK-level 'intervening turn' rejection is superseded), adjacent
  assistant tool messages form ONE answerable turn (partially answering
  it is the R10 partial rejection, superseding the R9 window-replacement
  probe), and only an intervening USER turn genuinely breaks adjacency.
- The region-change hook compares EFFECTIVE regions before invalidating
  the credential: a corrupt stored value ('bogus', empty, whitespace,
  wrong case) already routes to the default region on every read, so
  saving the displayed default on top of it is not a switch — the
  raw-vs-sanitized comparison previously deleted a valid key whose
  effective endpoint never changed. Both hook values now run through
  the same normalization get_region() uses; only genuinely different
  effective regions invalidate. Genuine switches and same-value saves
  behave exactly as before.

### Fixed (zai / M2 — Codex PR review, round 10)

- A partially answered tool-call turn is now rejected before transport:
  when the user turn following an assistant tool turn answers only some
  of its tool calls (or none), the unanswered remainder was silently
  discarded and the history sent — Anthropic requires that one user turn
  to carry results for ALL of the preceding turn's tool calls, so the
  request failed upstream with a 400. Fully-answered multi-tool turns
  and end-of-history unanswered turns (the normal tool loop) are
  unaffected.
- Two FunctionCall parts sharing the same ID within ONE assistant turn
  are rejected before transport: the map assignment silently overwrote
  the first entry, so a single later result satisfied linkage while the
  wire carried two ambiguous tool_use identities. ID reuse across
  DIFFERENT turns stays legal.
- A text or thinking delta for a stream index whose
  `content_block_start` was never received now invalidates the stream
  (the same typed error the tool-delta path already raised): the
  synthesized accumulator returned a successful truncated completion
  with the content before the missing start silently absent. Unknown
  (future) delta types keep the seeded tolerance — they carry no
  content this aggregator maps.
- The duplicate tool-call-id check is scoped to the coalesced WIRE turn,
  not the SDK message: two adjacent assistant Messages sharing a tool id
  coalesce into one wire turn and previously slipped the per-message
  check while emitting the ambiguous duplicate-identity shape
  (verifier probe on round 10).

### Fixed (zai / M2 — Codex PR review, round 9)

- Tool results must now arrive in the user turn IMMEDIATELY following
  the assistant tool-use turn: an intervening turn (assistant or user)
  expires the outstanding tool-use IDs, so a stale result later in the
  history is rejected before transport. Multi-tool assistant turns must
  be answered entirely by that one next user turn — a result arriving a
  turn later is stale even if its ID was never answered.
- A duplicate `message_delta` event now invalidates the stream: the
  protocol sends exactly one, and the later one silently overwrote the
  first stop reason and usage (end_turn then tool_use made the result
  report `toolCalls()` with no corresponding function call). Even a
  byte-identical repeat is rejected — the payloads may differ, so a
  repeat is never an idempotent no-op (supersedes the R8 verifier's
  tolerance judgment).
- Empty tool-call identities are rejected before transport in BOTH
  directions: a FunctionCall or FunctionResponse with `''` as its ID
  (or `''` name) passed the null-only guards and emitted a tool_use /
  tool_result block with an empty identity — Messages requires
  non-empty ids and names — and an inbound `tool_use` block with an
  empty id/name now fails the typed parse error instead of producing a
  FunctionCall with an empty identity.

### Fixed (zai / M2 — Codex PR review, round 8)

- A delta arriving after an index's `content_block_stop` still appended
  to the closed block, and a `content_block_stop` for a never-started
  (or twice-stopped) index passed silently: stopped indexes are now
  tracked, and both shapes invalidate the stream with the typed parse
  error.
- `message_stop` is now terminal: any non-keepalive data event after it
  (including a second `message_stop`) invalidates the stream —
  post-termination frames could previously modify the returned
  text/tool args/stop reason/usage while the response succeeded.
  Ping/comment keepalive frames stay tolerated.
- A stream omitting `message_start` now invalidates instead of
  fabricating an assistant envelope with blank id/usage (which had also
  bypassed the R6 streamed-role validation): message_start receipt is
  required before aggregation produces a payload.
- A `content_block_start` whose `content_block.type` is missing or
  non-string now invalidates the stream: it silently became a `text`
  block that a following text_delta completed on fabricated state.
- Outbound tool results are validated against the preceding tool calls:
  every `tool_result` must answer a preceding `tool_use`'s ID exactly
  once — stale, mistyped, out-of-order, or duplicate results are
  rejected before transport instead of failing upstream with a 400.
- The live probe's discovery step now detects fallback results: the
  directory silently returns the static catalog when the live /v1/models
  request fails or malforms, so the probe used to report a model count
  (and PASS) with no live discovery. Successful discovery is cached in a
  per-endpoint transient while fallbacks never are — the probe clears
  that transient first and reports `discovery source` as `live /v1/models`
  or `DISCOVERY FALLBACK`, failing (nonzero exit) on the latter.

### Fixed (zai / M2 — Codex PR review, round 7)

- A `tool_use` response block OMITTING its `input` member or setting it
  to `null` is now rejected as a typed parse error instead of being
  normalized into a no-argument FunctionCall: the Messages protocol
  requires the member, and an empty call is represented by `{}`
  alone (supersedes the R2-era tolerance; `{}` stays legitimate). The
  streamed `content_block_start` path got the same strictness (verifier
  sweep sibling): an absent or explicitly-null start-block input also
  invalidates the stream instead of fabricating a no-argument call.
- A FunctionDeclaration whose parameter schema is a non-empty
  SEQUENTIAL array is rejected before transport: it serializes
  `input_schema` as a JSON LIST and the tools contract requires an
  object (upstream 400). String-keyed and mixed-key schemas still
  encode as objects; null/empty keep their `{}` normalization.
- A duplicate `content_block_start` for an already-started index now
  invalidates the stream: the silent accumulator reset discarded every
  fragment collected before the duplicate while the completion still
  reported success with altered content.
- An SSE frame whose `event:` field and `data.type` member are BOTH
  present as strings but disagree now invalidates the stream: the field
  always won, so `event: ping` carrying a `content_block_delta` payload
  was ignored as keep-alive and the answer completed with the content
  chunk missing. Frames with only one declaration keep their behavior.
- The live probe validates `--plan` and `--region` exactly like
  `--surface`: a typo previously printed (e.g. `china`) while the
  settings getters silently fell back to defaults, making the evidence
  misleading and potentially exercising the wrong billing surface.
  Invalid values exit 2 before any key lookup or network call.
- The live probe now fails when the availability step fails, instead of
  continuing with `$exit = 0`: a later generation success could print a
  final PASS although the first documented acceptance step failed (the
  two routes can apply different access policy).

### Fixed (zai / M2 — Codex PR review, round 6)

- An explicitly-null response envelope `type` (`"type": null`) is now
  rejected like any contradictory type value: isset() treated null as an
  omitted member; the presence check now uses array_key_exists(), so
  only a genuinely omitted member keeps the documented tolerance.
- A streamed `message_start` declaring a role other than `assistant`
  (explicit `user`, `null`, or any other value) is now rejected: the
  aggregator hardcodes `role:assistant` in the consolidated payload, so
  such a stream previously bypassed the model's exact-role check — a
  bypass the non-streaming path never had. An omitted streamed role
  keeps the documented assistant default.
- A `content_block_start`/`content_block_delta`/`content_block_stop`
  event whose `index` member is missing or not a non-negative integer
  (string, float, null, negative) now invalidates the stream: the value
  was previously coerced to index 0, so a malformed event mutated the
  WRONG block while the stream still reported success.
- A delta whose type conflicts with the started block's type now
  invalidates the stream instead of being silently discarded:
  `input_json_delta` on a text/thinking block previously finished with
  `stop_reason: tool_use` while omitting the tool call entirely, and
  text/thinking deltas on a tool block accumulated then dropped.
  Unknown delta types keep their forward-compatible tolerance. As a
  side effect, a thinking delta on a start-less index now seeds a
  thinking accumulator, so its content surfaces instead of being
  dropped (supersedes the R5 tolerance note).
- A top-level `content` member that is a JSON OBJECT (`"content": {}`
  or numeric-keyed) is now rejected: associative decoding collapses it
  onto the empty-array/PHP-array shapes of a legal list, so it
  previously parsed as a successful candidate with no parts. The raw
  decode's array/object distinction decides; `"content": []` stays
  protocol-legal.

- The Debug logging checkbox renders again on Settings → z.ai: the
  field was still attached to the pre-refactor option-group section id,
  which no registered section uses — `do_settings_sections()` renders
  only fields of registered sections, so the toggle silently
  disappeared when the settings sections became per-provider. It now
  attaches to the registered zai section (one shared debug toggle,
  exactly the M1 UX), pinned by a test asserting every registered
  field's section id belongs to a registered section of the page.

### Fixed (zai / M2 — Codex PR review, round 5)

- Tool parts are validated against their message's role before
  transport: a FunctionCall in a user message or a FunctionResponse in
  an assistant message (histories the Messages protocol rejects with a
  400) now fail with the typed invalid-request error. The SDK's Message
  DTO already blocks both pairings at every construction path, so the
  guard is defense-in-depth for SDK bypasses (relaxed future DTO,
  unserialized state) — pinned by a reflection-bypass regression test.
- An `input_json_delta` for a stream index whose `content_block_start`
  was never received now invalidates the stream: the previous tolerant
  default created a text accumulator, so the tool's JSON fragments were
  collected and ignored and a tool_use completion "succeeded" with no
  FunctionCall at all. A genuine text (or thinking) delta on an unseen
  index keeps the documented tolerance — the chunk is still surfaced.
- A Messages generation response must identify itself with the exact
  `assistant` role: a missing, unknown, or `user` role previously
  fabricated an assistant turn or exposed the payload as a generated
  USER message — both now fail as a typed parse error instead of
  mis-attributing content into downstream history.
- `model_context_window_exceeded` is no longer folded into the
  `max_tokens` case: the model's overall CONTEXT window being exhausted
  cannot be recovered by raising maxTokens (it leaves even less room),
  so it now carries its own advice — reduce the input, truncate the
  history, shorten the prompt — and a null typed maxTokens payload that
  ErrorMapper keys on; the raise-maxTokens advice stays on genuine
  `max_tokens` stop reasons only.
- A success-shaped body whose top-level envelope identifies as anything
  other than `"message"` (e.g. a `type:"error"` envelope carrying a
  valid-looking payload) is now rejected as a typed parse error instead
  of parsing as a generation (verifier residual on the R5 round). The
  unseen-index tolerance note was also corrected: a thinking delta on a
  start-less index is tolerated with its thought content dropped, not
  surfaced.

### Fixed (zai / M2 — Codex PR review, round 4)

- `authenticateRequest()` now strips any pre-existing `x-api-key`
  header (case-insensitively) before setting Bearer + anthropic-version:
  a reused or decorated request could otherwise transmit a stale second
  credential alongside the Bearer key, violating the never-x-api-key
  contract. The SDK's header collection has no removal API, so the
  request is rebuilt from its remaining headers with method, URI,
  body/data, and transport options carried over verbatim.
- Empty text parts are dropped from outbound Messages requests: the
  protocol rejects empty text blocks with a 400, and a message whose
  visible parts are all empty now falls through to the existing
  no-translatable-content pre-transport rejection instead of failing
  upstream.
- A FunctionCall from chat history whose arguments are a non-empty
  SEQUENTIAL array (which would encode `input` as a JSON list) is
  rejected before transport with the typed invalid-request error — the
  Messages tool schema requires an object. Mixed/string-keyed arrays
  still encode as objects and the empty-array→`{}` normalization is
  unchanged.
- An `input_json_delta` whose `partial_json` member is NULL or OMITTED
  is now flagged as malformed streamed arguments (isset() is false for
  both, so both were silently ignored — letting the initial `{}` become
  an executable no-argument call). The legitimate empty-string fragment
  keeps its no-op semantics.
- A frame DECLARING a known event name (message_start, content_block_*,
  message_delta, message_stop, error) with an undecodable payload now
  fails the whole stream as a typed parse error: silently dropping a
  malformed content_block_delta returned a successful completion with
  that chunk of the answer missing. Unknown event names and
  ping/keep-alive frames stay ignorable for forward compatibility.
- The independent verification sweep extended that invalidation to the
  same corruption class it found still slipping through: a declared
  event with a valid-JSON LIST payload (is_array cannot distinguish a
  JSON list from an object — the raw non-associative decode can), a
  decodable-but-wrong-shape `content_block_delta` (delta member
  missing/null/scalar, non-string delta.type, non-string text/thinking
  members), the same shapes arriving via data-only frames dispatched by
  their type member, and a `content_block_start` whose content_block
  member is absent or not an object (which silently swallowed the
  block's deltas). Unknown delta types stay ignorable.

### Fixed (zai / M2 — Codex PR review, round 3)

- `"input": []` (the empty JSON LIST) and boolean tool inputs are now
  rejected on the regular Messages response path: associative decoding
  collapses `{}` and `[]` to the same empty PHP array, so object-ness is
  decided against a parallel NON-associative decode of the body (the raw
  value is stdClass only for objects). `{}` and a missing input member
  stay legitimate no-argument calls. The SSE consolidated payload now
  preserves `{}` as an object across the aggregator/model boundary for
  the reason.
- A malformed `content_block_start` tool input (scalar, list — including
  `[]` — or boolean, with no argument deltas following) is no longer
  silently replaced with `{}`: the stream start block's ORIGINAL input
  shape is validated against a non-associative decode of the same frame
  and flagged as the same typed stream-parse error; object inputs become
  the initial argument value and missing/null stay the no-argument
  placeholder.
- The build-cleanup regression test now drives the guard with a
  throwaway artifact name, so it runs (and passes) against a prepared
  release `dist/` too instead of failing on its unconditional
  no-sidecar precondition.
- Successful `zai_anthropic` model discovery is intersected with the
  active plan's catalog before caching: the live `/v1/models` route
  returns the full list on the coding plan (record 0007), but the coding
  subscription exposes only its restricted model set, so general-only
  GLM 4.x entries are no longer advertised or cached while coding is
  selected; the general plan keeps the full discovered list. A discovery
  response with no in-plan models falls back to the plan catalog without
  caching.
- A non-string `partial_json` member inside an `input_json_delta` event
  (a corrupt streamed-arguments shape the protocol types as a string) is
  now flagged as the same typed stream-parse error instead of being
  silently dropped, which could surface a no-argument call built from a
  broken stream (verifier finding on the R3 round).

### Fixed (zai / M2 — Codex PR review, round 2)

- The non-streaming Messages response path now applies the same
  tool-argument object-ness validation as the streaming path (R1): a
  tool_use `input` that is not a JSON object (scalars, lists) fails as a
  typed parse error instead of passing fabricated/invalid arguments to a
  FunctionCall; `{}` and a missing input member stay legitimate
  no-argument calls.
- The artifact build test's dist/ preservation moved into a reusable
  two-level-finally guard: a failing build assertion can no longer skip
  the removal of the seeded checksum sidecar/manifest (which tearDown
  does not clean), so no introduced file lingers to contaminate later
  runs.
- Settings invalidation no longer autoloads SDK classes: on WP 6.9
  without the optional PHP AI Client plugin the settings UI still boots,
  and a plan/region save previously reached dynamic class-constant
  accesses on the availability/directory classes (which implement
  missing SDK types) — a fatal error right after the option write. The
  invalidation identifiers now live in the SDK-free settings layer (the
  availability/directory constants mirror them; the discovery-cache key
  is composed inline), the region-pending implementation moved there
  too, and a consistency test pins every identifier pair plus the
  cache-key format. An out-of-process test runs the invalidation with
  the SDK genuinely absent and proves it completes cleanly.

### Fixed (zai / M2 — Codex PR review, round 1)

- Streamed tool arguments that do not decode to a JSON object (truncated
  input_json_delta fragments, scalars) now fail as a typed stream-parse
  error instead of silently becoming a no-argument tool call — a consumer
  could have executed a side-effecting tool with inputs the model never
  produced. The legitimate empty-object stream (`{}`) still yields a
  no-argument call.
- Consecutive same-role turns (two user turns in a row, etc.) are now
  coalesced into one message with merged content blocks — the protocol's
  own combining rule — instead of being rejected pre-transport; generic
  chat histories with repeated turns now round-trip. An empty prompt and
  a non-user first message are still rejected.
- The real-artifact build test no longer damages release-verification
  state: the zip, its `.sha256` sidecar, and the zip's entry in
  `dist/checksums.txt` are all snapshotted and restored (or removed when
  the test introduced them), and the class tearDown restores a prepared
  manifest instead of deleting it — a prepared `dist/` directory is left
  exactly as it was, verified by post-restore assertions.
- An `outputSchema` now requests the JSON guidance on its own — the MIME
  option and the schema are advertised independently, and a schema
  without `outputMimeType: application/json` was silently discarded into
  an unconstrained request.
- A parameterless tool declaration whose parameters are an empty array
  (not null) now normalizes to the empty-object input schema — the raw
  `[]` encoding failed the Messages tool-schema validation upstream.

### Fixed (zai / M2 — independent review round)

- Two independent reviews of the full M2 diff (security-focused and
  correctness-focused) found no critical/high issues; their actionable
  findings are fixed:
- A message whose parts all drop (thought-only replay, or no parts at
  all) is now rejected before transport with a precise message instead of
  degrading to an empty text block the Messages API answers with a
  misleading 400.
- A stream truncated before `message_delta` now fails with the fixed
  parse-error message instead of fabricating a clean `end_turn` stop.
- `TokenLimitReachedException` now carries the applied limit in its typed
  `maxTokens` payload (consumers of the accessor no longer see null).
- `ZaiAnthropicRequestAuthentication::wrap()` fails closed on a foreign
  authentication implementation instead of silently sending the request
  unauthenticated.
- The first persisted PLAN change on a fresh install now runs the state/
  cache invalidation (add_option_{plan} companion hooks, symmetric with
  the region hooks).
- The zai_anthropic live smoke test defaults to the general plan (the
  coding surface cannot generate per record 0007), several SSE fixtures
  now use genuine single-frame event:/data: pairing, the webSearch
  rejection gained its own test, and record 0007's note about the
  thinking `signature` member was corrected (the SDK can carry thought
  signatures since 1.3.0; this adapter drops them deliberately).

### Added (zai / M2 — validation, docs, live evidence; Task 2.7)

- Uninstall now removes BOTH providers' options and discovery caches
  (still leaving the core-owned key options), and the artifact test suite
  proves the standalone zip ships both providers' source trees.
- `bin/zai-live-probe.php` grew a `--surface=anthropic` mode; opt-in live
  smoke test for the second provider
  (`WP_CONNECTORS_TEST_ZAI_API_KEY`).
- Live evidence (2026-08-31, record 0007): `/v1/models` works on both
  Anthropic plans (same 10-model GLM list, Anthropic shape) — the
  Anthropic half of O1 is resolved; the two plans route Messages
  differently (general `/v1/messages`, coding `/messages`), and the
  coding surface cannot generate at all as of that date (wrapped 404s for
  every probed model/auth combination). `zai_anthropic` therefore now
  defaults to general+intl — the production-proven path for Coding-Plan
  keys — and the SPEC (§3.1–§3.3) was amended in the same change.
  End-to-end live PASS on general+intl through the plugin classes.

### Changed (zai / M2 — live-evidence amendment, record 0007)

- `zai_anthropic` default plan general (was coding): the coding-surface
  Anthropic Messages routes answer with wrapped 404 errors for every
  probed combination, so a coding default would fail out of the box.
  The zai provider keeps its coding default; the coding Anthropic base
  stays selectable and its `/v1/models` route works.

### Added (zai / M2 — `zai_anthropic` endpoint resolution, auth, metadata, Messages protocol; Tasks 2.2–2.6)

- Anthropic-surface endpoint resolver with the four SPEC §3.1 `/anthropic`
  bases; `/v1/messages` and `/v1/models` are appended exactly once (a base
  already carrying a suffix is normalized first), and the per-surface
  canonical `baseUrl()` stays fixed.
- Requests authenticate with `Authorization: Bearer <key>` plus
  `anthropic-version: 2023-06-01`; `x-api-key` is never sent (unverified on
  z.ai). Exactly one of each header leaves the authentication method, even
  over a stale preset value.
- Custom (non-OpenAI-compat) model directory: opportunistic, transient-
  cached `/v1/models` discovery keyed provider+plan+region (12 h) with the
  shared plan-partitioned GLM fallback on every failure shape; the
  fallback is not cached beyond a 60-second short-TTL negative cache
  (still retryable), so a later valid key can still discover.
- Full Messages request mapping: system instruction, alternating
  user/assistant content blocks, tools + tool results (empty arguments
  encode as `{}`), JSON output guidance with `outputSchema` embedded in
  the system prompt (native `output_format` is unverified on z.ai), and
  the protocol-required `max_tokens` with a 4096 default. Role-order
  violations and unsupported options fail before any transport work.
- Messages response and stream mapping: content blocks (text, thinking,
  tool_use), stop reasons, usage (cache token variants included), and the
  Anthropic SSE event sequence (message_start, content_block_start/delta/
  stop, message_delta, message_stop, ping, error) incl. interleaved
  text/thinking/tool deltas, split frames, malformed events, and a final
  event without a trailing blank line. Errors map through the one shared
  redacted catalog; a max_tokens stop surfaces as the typed token-limit
  error. The protocol-neutral SSE frame splitting moved into a shared
  `SseFrameBuffer` used by both aggregators (extend, not fork).

### Added (zai / M2 — second provider `zai_anthropic`, Task 2.1)

- The `zai` plugin now registers a second provider, `zai_anthropic`
  (card "z.ai (Anthropic API)"), alongside `zai`. Both registrations are
  individually idempotent, and the registrar refuses to register onto a
  provider ID a foreign class already holds — the SDK's `registerProvider()`
  would silently overwrite it — so one provider's registration can never
  replace the other's.
- `zai_anthropic` has its own plan/region options
  (`zai_connector_zai_anthropic_plan` / `_region`; initially coding +
  intl, later amended to general + intl by the record-0007 live evidence
  above) rendered as a second section on the shared settings page; the
  two providers' selections cannot bleed into each other.
- A `zai_anthropic` region switch clears that provider's validated state,
  discovery caches, region-pending flag, and stored key
  (`connectors_ai_zai_anthropic_api_key`) — never the `zai` provider's data.
- Availability is validated independently per provider: separate state
  options and endpoint-scoped bindings mean a validated `zai` key can never
  establish `zai_anthropic`'s connected status (and vice versa); a
  nonempty-but-invalid key reports not-connected for this provider too.
- The M1 settings/availability logic moved into shared per-provider base
  classes (`AbstractPlanRegionSettings`, `AbstractZaiProviderAvailability`)
  with the `zai` classes as thin children — no behavior change for `zai`.

### Fixed (zai / M1 — Codex PR review, round 3)

- Network uninstall pagination actually advances: the multisite cleanup
  passed `paged` to `get_sites()`, an argument WP_Site_Query does not
  support — on networks of 100+ sites every batch re-returned the same
  first 100 sites, so the loop never advanced (request timeout) and later
  sites were never cleaned. The batches now advance the supported `offset`
  (0, 100, 200, …) and stop on the first short batch; the test harness's
  `get_sites()` is core-faithful (ignores `paged`, fails a non-advancing
  loop fast) so the tests prove the advance across multiple batches.
- A region switch now also distrusts environment/constant credentials
  until they are DEFINITIVELY validated for the new region: those sources
  are immutable (the plugin cannot delete them like the stored key), so an
  inconclusive probe (the China /models route 404s → configured-pending)
  previously left the connector "connected" with the old-region env key
  against the new endpoint indefinitely. The switch records a
  region-pending flag (new region + SHA-256 fingerprint of the riding
  credential): while exactly that key is effective, an inconclusive probe
  reports not-connected; an authenticated 2xx (connected) or 401/403
  (rejected) settles it, and any different credential — including the
  candidate core wires during key-save validation — keeps the normal
  pending-accept semantics. New option `zai_connector_zai_region_pending`
  (non-autoloaded, fingerprint only, removed on uninstall; record 0004
  updated).

### Fixed (zai / M1 — Codex PR review, round 2)

- Region switch now deletes the STORED key, not just the validated-state
  verdict: with an inconclusive probe (the China /models route 404s) the
  connector previously stayed "connected" and would send the old
  international key against the China endpoint indefinitely. After a
  switch no key is stored, so the connector stays not-connected until a
  key for the new region is supplied (plan changes never touch the key —
  coding/general share one account). Pending-accept semantics remain only
  for core's key-save validation path (record 0004 region-switch note).
- Generation through the real core prompt path works again: the model
  catalog now advertises `outputModalities` (text). The SDK's prompt
  builders — including core's `wp_ai_client_prompt()` — require that
  option during model resolution, so every builder-driven `generate_text()`
  previously matched no zai model at all; covered by a test that drives the
  genuine `WP_AI_Client_Prompt_Builder` end to end.
- Error mapping is honest about the two surfaces it lives on (SPEC §6.2,
  plan Task 1.7, readme updated): through the core builder, callers get
  core's fixed codes (`prompt_client_error`, …) with the message passed
  through VERBATIM — the plugin now builds every model exception from the
  one shared `ErrorMapper::safe_http_message()` catalog, which is what
  keeps that path redacted; the typed `zai_*` codes remain the direct
  model-use API (`generate_text()`/`ErrorMapper`) and cannot be delivered
  through the core builder (WordPress core limitation, no filter exists).
- SSE streams ending directly after the last `data:` line (no trailing
  blank line) no longer lose that frame: `finish()` flushes the remaining
  buffered frame — single-event streams previously failed as
  `zai_invalid_response`, multi-event streams lost their final content.
- Network uninstall removes plugin-owned data from EVERY site: options and
  transients are per-site, so a network-activated uninstall now iterates
  the sites (offset batches of 100, blog context restored) instead of
  cleaning only the current site.

### Fixed (tooling — Codex PR review, round 2)

- `bin/inspect-artifact.php`: a zip whose sole top-level entry is a FILE
  (e.g. `plugin.php`) is now rejected as a normal violation instead of
  crashing the directory iterator, and the temp extraction tree is cleaned
  up on every path (try/finally).
- `bin/lib/plugin-tools.php`: exactly one main plugin file is now enforced
  — archives with two root-level `Plugin Name` headers (something WordPress
  would expose as two plugins) are rejected by the conventions check, the
  builder, and the inspector, which previously all accepted the first match
  and ignored the second.
- `bin/build.php`: the version-constant check is part of the build refusal
  gate — a bumped header with a stale `{SLUG}_VERSION` constant can no
  longer be packaged as a mislabeled zip.

### Fixed (zai / M1 — independent multi-agent review)

- Directory caching hardened: the SDK-level cache key (`getBaseCacheKey()`)
  is now endpoint-scoped (plan+region), so a warm SDK cache — including a
  persistent PSR-16 cache configured via `AiClient::setCache()` — can never
  serve the previous endpoint's catalog after a settings switch, and the
  static fallback is never persisted in ANY cache layer (previously the
  24h SDK cache could pin a fallback or a stale catalog; plan switches now
  also invalidate plugin transients like region switches).
- Availability probe: only 401/403 persist an invalid verdict; 429 and other
  4xx stay transient — z.ai returns 429 "Insufficient balance" (code 1113)
  for plan mismatches on an otherwise VALID key, which must not poison the
  connected state (nor let core's REST key validation erase a good key).
- SSE framing per spec: CR/LF/CRLF terminators mixed freely are now
  recognized (a `\n\r\n` boundary previously merged two events and lost both).
- Malformed-payload exceptions carry fixed messages: the SDK embeds the
  upstream `finish_reason` verbatim in its parse exceptions; the model now
  re-wraps them so no response-body content can reach error surfaces.
- Live probe builds the model through `getProviderModel()` (a PHPStan fix had
  broken transporter/auth binding, making the recorded evidence
  non-reproducible with the committed tool); re-verified coding+intl PASS.
- Settings save guard restructured so the capability strip path is genuinely
  reachable (nonce failures are terminal in core via `check_admin_referer`).
- Record 0004 autoload table amended to match shipped behavior (tiny
  non-secret options use the core autoload default; log and key-state
  options stay non-autoloaded).

### Added (zai / M1)

- `connectors/zai` plugin scaffold: 6.9-compatible header, guarded bootstrap
  with missing-SDK admin notice, own PSR-4 autoloader, idempotent provider
  registration at `init` priority 5; registers only the `zai` provider in M1
  (Task 1.1).
- Plan/region settings (`zai_connector_zai_plan` coding|general,
  `zai_connector_zai_region` intl|cn) via the Settings API on a dedicated
  Settings → z.ai page: whitelist sanitization with corrupt-value fallback,
  capability+nonce save guard, and region-switch invalidation of plugin-owned
  credential-derived state (the core-owned key option is never written)
  (Task 1.2).
- Immutable endpoint resolver (`ZaiEndpoint`) covering the four SPEC §3.1
  plan × region base URLs with request-time option reads; provider
  `baseUrl()` stays fixed to the intl-general canonical URL (Task 1.3).
- Provider metadata with SDK version guards (description ≥1.2.0, logo ≥1.3.0,
  shipped logo asset) and availability as an authenticated /models probe with
  a persisted verdict bound to the complete key hash + key source + endpoint
  identity; transport/5xx failures stay transient and never persist a verdict
  (Task 1.4).
- Model metadata directory (O1 resolved, record 0006): dynamic `/models`
  discovery with a 12-hour transient cache scoped to provider+plan+region,
  newest-first GLM sorting, plan-partitioned static fallbacks (coding: GLM
  5.x family; general: full catalog), malformed/401/404/transport responses
  falling back without poisoning the cache, and conservative text-only
  capability/option declarations (Task 1.5).
- Chat-completions request mapping on the SDK OpenAI-compatible base class
  with request-time endpoint resolution; unsupported option/model
  combinations (image input, candidateCount, penalties, topK, logprobs,
  web search, non-text output modalities/MIME types, custom options) are
  rejected before transport. Committed redacted request snapshots cover
  minimal, conversation, structured-output, tool, and multimodal-text cases
  (Task 1.6).
- Response/streaming/error mapping: non-streaming and SSE responses (split
  frames, `[DONE]`, comments, malformed events, tool-call delta merging,
  finish reasons, usage) normalize into SDK results; 401/403/429/4xx/5xx/3xx
  throw SDK-typed exceptions whose messages never include upstream bodies,
  with `ErrorMapper::to_wp_error()` exposing stable typed codes
  (`zai_unauthorized`, `zai_rate_limited`, …); no retries in v1 (Task 1.7).
- Observability and admin links: option-gated debug logging (default OFF) of
  method + redacted URL (query stripped) + status + duration only, recorded
  via a transporter decorator across inference, availability, and discovery
  requests into a bounded ring buffer; a plugin-row Settings link and the
  debug checkbox + log viewer on the settings page (Task 1.8).
- M1 validation and documentation: WordPress-style `readme.txt` (usage, key
  storage disclosure, endpoint behavior, multisite, troubleshooting),
  `uninstall.php` removing plugin-owned options/caches only, opt-in live
  smoke tooling (`bin/zai-live-probe.php` + env-gated `ZaiLiveSmokeTest`,
  key read at runtime from `ZAI_LIVE_API_KEY`/`~/.config/z.ai/api_key`),
  and recorded live evidence in record 0006 — coding+intl PASS end to end,
  general-plan 429/1113 (account property) surfaced as the typed rate-limit
  error (Task 1.9).

### Added (tooling / M0 foundation)

- Architecture records (`docs/architecture/`) verifying the WP 7.0/7.1
  Connectors API and PHP AI Client SDK 1.3.1 contracts; z.ai `/models`
  evidence resolving SPEC open question O1 for the OpenAI/intl surface.
- Composer dev toolchain (PHPUnit, PHPCS + WordPress standards,
  PHPCompatibility, PHPStan) with the pinned genuine SDK 1.3.1; offline
  validation entry point `composer check`.
- WordPress API test harness (`tests/harness/`): deterministic clock, option/
  cron reset between tests, users/capabilities, nonces, fail-closed HTTP on
  both `wp_remote_*` and SDK transport, encrypted-option/secret assertions.
- Repository conventions document with automated enforcement
  (`composer conventions`).
- Standalone artifact builder (`bin/build.php`) with deterministic zips +
  SHA-256 checksums and an artifact inspector
  (`bin/inspect-artifact.php`) rejecting repo-relative includes, missing
  headers, dev files, and embedded secrets.
- Secure test-fixture rules: fake secret factories, HTTP response builders,
  secret-pattern scanner (`composer scan-secrets`), documented opt-in
  environment variables for live tests.

### Fixed (M0 independent review hardening)

- Unmocked-HTTP leak audit moved from `tearDown()` (a no-op under PHPUnit
  9.6) to `assertPostConditions()`, covering SDK-transport attempts too;
  a leaking test now fails the run as documented.
- Shared-source namespace rewrite inserts provenance after `<?php` so
  generated files stay valid PHP with `declare(strict_types=1)`.
- Builder never follows file symlinks (out-of-tree files can no longer be
  packaged) and excludes more development files (package.json, Makefile…).
- Secret scanner: fixture-marker allowlist narrowed to unambiguous markers
  (generic prose words no longer suppress a scan), `toml` scanned, and the
  default scan covers the whole repository root (mise.toml, .github/,
  composer.json…) instead of enumerated subdirectories.
- Harness semantics aligned with WordPress core: filter/action stack
  membership (`doing_action`, `current_filter` inside `apply_filters`),
  `has_filter()` priority return, negative transient TTL, `add_query_arg`
  replace semantics, case-insensitive response headers, Settings API state
  reset between tests.
- Artifact inspector matches forbidden entries on whole path segments
  (no more false rejects like `assets/latest/…`).
- `composer conventions` now enforces the autoloader PSR-4 prefix and
  single-registration rule via the shared helpers (no duplicated logic).
- Docs corrected: offline entry point is `composer check`, enforced header
  list and shared-copy-freshness timing in record 0005, Composer bootstrap
  step for clean checkouts in the testing guide; `.env`/`*.pem`/`*.key`
  gitignored; fixture plugins now covered by PHPCS/compat scans.
