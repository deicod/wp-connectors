<?php
/**
 * Self-containment case-variant include fixtures (the R31-C2 class:
 * t31-glm31-2's include owner).
 *
 * PHP lexes require/include (and their _once forms)
 * case-insensitively, but the include owner's keyword arm spelled
 * the keywords byte-exact lowercase — so '<?PHP REQUIRE …' and
 * '<?php Include_Once …', both lint-clean, were INVISIBLE to every
 * gate riding the owner (driven: 0 violations where the lowercase
 * twin flags). The phpcs lowercase-keywords boundary gates the repo
 * tree only; the artifact channel is a demonstrated production path
 * php -l passes and phpcs never touches — the re-open rule's
 * demonstrated-production-path leg. The keywords match through
 * SCOPED (?i:…) groups now (the ocr46-9 idiom at this owner, the
 * same widening the import scanner and the argument derivations
 * already ride): the case fold lives on the keyword tokens alone,
 * and every spelling reaches the same terminator-matched statement
 * the lowercase ones always did. These fixtures pin the driven
 * fail-opens closed and the lowercase spellings byte-identical.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

require_once __DIR__ . '/../bin/check-conventions.php';

use PHPUnit\Framework\TestCase;

final class SelfContainmentCaseVariantIncludesTest extends TestCase
{
    /**
     * @var string Per-test fixture root.
     */
    private $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wp-connectors-case-variant-' . uniqid('', true);
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ((glob($this->root . '/*') ?: array()) as $entry) {
            if (is_file($entry)) {
                @unlink($entry);
            }
        }
        @rmdir($this->root);
    }

    /**
     * @return list<array{0: string, 1: string}> Fixture name and code pairs.
     */
    public function provideCaseVariantEscapes(): array
    {
        return array(
            'uppercase REQUIRE with uppercase open tag' => array(
                'REQUIRE',
                '<?PHP REQUIRE dirname(__DIR__, 2) . "/outside.php";',
            ),
            'mixed-case Include_Once' => array(
                'Include_Once',
                '<?php Include_Once dirname(__DIR__, 2) . "/outside2.php";',
            ),
        );
    }

    /**
     * @dataProvider provideCaseVariantEscapes
     */
    public function testACaseVariantIncludeFlagsLikeItsLowercaseTwin(string $spelling, string $code): void
    {
        /*
         * The driven fail-open (R31-C2, security): both payloads are
         * lint-clean (php -l verified this round) and answered 0
         * violations at HEAD — the keywords invisible to the owner's
         * lowercase arm (red at HEAD: 0 violations). The scoped
         * (?i:…) groups widen the keyword tokens; the escape flags
         * naming its statement exactly like the lowercase twin.
         */
        $lowercase_twin = str_replace($spelling, strtolower($spelling), $code);

        file_put_contents($this->root . '/fixture.php', $code);
        $violations = wp_connectors_self_containment_violations($this->root);
        $report = implode("\n", $violations);

        $this->assertNotEmpty($violations, sprintf('A %s escape is a live include — PHP lexes the keyword case-insensitively (red at HEAD: 0 violations).', $spelling));
        $this->assertStringContainsString($spelling . ' dirname(__DIR__, 2)', $report, 'The violation names the case-variant include.');

        $twin_root = $this->root . '-twin';
        mkdir($twin_root, 0755, true);
        file_put_contents($twin_root . '/fixture.php', $lowercase_twin);
        $twin_violations = wp_connectors_self_containment_violations($twin_root);

        $this->assertSame(
            str_replace(array($spelling, basename($this->root)), array(strtolower($spelling), basename($twin_root)), $report),
            implode("\n", $twin_violations),
            'The case variant and its lowercase twin answer byte-identical reports modulo the keyword spelling and the fixture roots the slug names — the widening touched the keyword tokens, never the derivations.'
        );
        foreach ((glob($twin_root . '/*') ?: array()) as $entry) {
            @unlink($entry);
        }
        @rmdir($twin_root);
    }
}
