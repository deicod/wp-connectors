<?php
/**
 * Unused-import scanner fixtures (glm17-12).
 *
 * wp_connectors_unused_import_violations() is wired into `composer
 * check` as a pass/fail gate (glm16-10) and has been reworked since —
 * glm16-17's one-copy removal (plus its incidental CRLF/EOF detection
 * delta), glm17-8's token-masked import finding, glm17-9's
 * case-insensitive mentions, and glm17-11's offset-capture removal —
 * with nothing in the suite pinning any of it. These fixtures pin the
 * documented contract: only an import whose short name appears
 * NOWHERE else in the real source (case-insensitively — comments and
 * docblocks count) is flagged; imports are LOCATED on the
 * token-masked view, so string/heredoc/comment text is never code;
 * and a directory named *.php is skipped, not scanned.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

require_once __DIR__ . '/../bin/check-conventions.php';

use PHPUnit\Framework\TestCase;

final class UnusedImportScannerTest extends TestCase
{
    /**
     * @var string Per-test fixture root.
     */
    private $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wp-connectors-unused-import-' . uniqid('', true);
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ((glob($this->root . '/*') ?: array()) as $entry) {
            if (is_dir($entry)) {
                @rmdir($entry);
            } else {
                @unlink($entry);
            }
        }
        @rmdir($this->root);
    }

    /**
     * @dataProvider importFixtureProvider
     */
    public function testScannerVerdict(string $source, int $expected): void
    {
        file_put_contents($this->root . '/fixture.php', $source);

        $this->assertSame(
            $expected,
            wp_connectors_unused_import_violations($this->root),
            'The scanner verdict must match the documented contract for this fixture.'
        );
    }

    public function importFixtureProvider(): array
    {
        return array(
            'unused import flags' => array(
                <<<'FIXTURE'
<?php
use Vendor\Package\Widget;
FIXTURE
                ,
                1,
            ),
            'code use does not flag' => array(
                <<<'FIXTURE'
<?php
use Vendor\Package\Widget;
$x = new Widget();
FIXTURE
                ,
                0,
            ),
            'mixed-case use does not flag (glm17-9)' => array(
                <<<'FIXTURE'
<?php
use Vendor\Package\Widget;
$x = new widget();
FIXTURE
                ,
                0,
            ),
            'docblock-only mention does not flag (conservative contract)' => array(
                <<<'FIXTURE'
<?php
use Vendor\Package\Widget;
/**
 * @param Widget $w
 */
function f( $w ) {}
FIXTURE
                ,
                0,
            ),
            'prose comment mention does not flag' => array(
                <<<'FIXTURE'
<?php
use Vendor\Package\Widget;
// The Widget handles this.
FIXTURE
                ,
                0,
            ),
            'comment carrying the exact statement text does not flag (glm16-17)' => array(
                <<<'FIXTURE'
<?php
// see use Vendor\Package\Widget;
use Vendor\Package\Widget;
FIXTURE
                ,
                0,
            ),
            'alias used via alias does not flag' => array(
                <<<'FIXTURE'
<?php
use Vendor\Package\Widget as W;
$x = new W();
FIXTURE
                ,
                0,
            ),
            'unused alias flags' => array(
                <<<'FIXTURE'
<?php
use Vendor\Package\Widget as UnusedAlias;
FIXTURE
                ,
                1,
            ),
            'function import used does not flag' => array(
                <<<'FIXTURE'
<?php
use function Vendor\helper;
helper();
FIXTURE
                ,
                0,
            ),
            'unused function import flags' => array(
                <<<'FIXTURE'
<?php
use function Vendor\helper;
FIXTURE
                ,
                1,
            ),
            'heredoc column-0 use line is data, not an import (glm17-8)' => array(
                <<<'FIXTURE'
<?php
$scaffold = <<<'EOT'
use WP_Fix\PhantomImport;
EOT;
FIXTURE
                ,
                0,
            ),
            'block comment column-0 use line is data, not an import (glm17-8)' => array(
                <<<'FIXTURE'
<?php
/*
use WP_Fix\CommentImport;
*/
FIXTURE
                ,
                0,
            ),
            'closure use is not an import' => array(
                <<<'FIXTURE'
<?php
$f = function () use ( $x ) { return $x; };
FIXTURE
                ,
                0,
            ),
            'CRLF file with a dead import flags (glm16-17 delta)' => array(
                str_replace("\n", "\r\n", "<?php\nuse Vendor\\Package\\DeadOne;\n"),
                1,
            ),
            'EOF without a trailing newline flags (glm16-17 delta)' => array(
                "<?php\nuse Vendor\\Package\\DeadTwo;",
                1,
            ),
        );
    }

    public function testADirectoryNamedPhpIsSkipped(): void
    {
        /*
         * glm17-10: the recursive iterator yields directories too, so a
         * directory NAMED *.php passes the extension gate; it must be
         * skipped as a directory, not read as a file.
         */
        mkdir($this->root . '/looks-like-a-file.php');
        file_put_contents($this->root . '/real.php', "<?php\nuse Vendor\\Package\\Used;\n\$x = new Used();\n");

        $this->assertSame(0, wp_connectors_unused_import_violations($this->root));
    }
}
