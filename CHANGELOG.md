# Changelog

All notable changes to this repository are documented here, per plugin and per
tooling area. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning per plugin follows its own header `Version` (no monorepo version).

## [Unreleased]

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
