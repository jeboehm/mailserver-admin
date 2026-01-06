<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Rspamd;

use App\Service\Rspamd\RspamdMetricsParser;
use PHPUnit\Framework\TestCase;

class RspamdMetricsParserTest extends TestCase
{
    private RspamdMetricsParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RspamdMetricsParser();
    }

    public function testParseAllSimpleMetrics(): void
    {
        $metricsText = <<<'METRICS'
# HELP rspamd_scanned_total Total messages scanned
# TYPE rspamd_scanned_total counter
rspamd_scanned_total 12345
# HELP rspamd_learned_total Total learned messages
# TYPE rspamd_learned_total counter
rspamd_learned_total 100
rspamd_spam_total 500
rspamd_ham_total 11000
METRICS;

        $result = $this->parser->parseAll($metricsText);

        self::assertSame(12345, $result['rspamd_scanned_total']);
        self::assertSame(100, $result['rspamd_learned_total']);
        self::assertSame(500, $result['rspamd_spam_total']);
        self::assertSame(11000, $result['rspamd_ham_total']);
    }

    public function testParseAllWithLabels(): void
    {
        $metricsText = <<<'METRICS'
rspamd_actions_total{action="reject"} 100
rspamd_actions_total{action="no action"} 5000
rspamd_actions_total{action="add header"} 200
METRICS;

        $result = $this->parser->parseAll($metricsText);

        self::assertSame(100, $result['rspamd_actions_total{action="reject"}']);
        self::assertSame(5000, $result['rspamd_actions_total{action="no action"}']);
        self::assertSame(200, $result['rspamd_actions_total{action="add header"}']);
    }

    public function testParseAllWithFloats(): void
    {
        $metricsText = <<<'METRICS'
rspamd_stat_avg_scan_time 0.05
rspamd_stat_memory_bytes 1.5e+08
METRICS;

        $result = $this->parser->parseAll($metricsText);

        self::assertSame(0.05, $result['rspamd_stat_avg_scan_time']);
        self::assertSame(1.5e+08, $result['rspamd_stat_memory_bytes']);
    }

    public function testExtractKpis(): void
    {
        $metricsText = <<<'METRICS'
rspamd_scanned_total 12345
rspamd_spam_total 500
rspamd_ham_total 11000
rspamd_learned_total 100
rspamd_connections_total 5000
METRICS;

        $kpis = $this->parser->extractKpis($metricsText);

        self::assertArrayHasKey('scanned', $kpis);
        self::assertArrayHasKey('spam', $kpis);
        self::assertArrayHasKey('ham', $kpis);
        self::assertArrayHasKey('learned', $kpis);
        self::assertArrayHasKey('connections', $kpis);

        self::assertSame(12345, $kpis['scanned']->value);
        self::assertSame(500, $kpis['spam']->value);
        self::assertSame(11000, $kpis['ham']->value);
        self::assertSame(100, $kpis['learned']->value);
        self::assertSame(5000, $kpis['connections']->value);
    }

    public function testExtractKpisWithMissingMetrics(): void
    {
        $metricsText = <<<'METRICS'
rspamd_scanned_total 12345
METRICS;

        $kpis = $this->parser->extractKpis($metricsText);

        self::assertSame(12345, $kpis['scanned']->value);
        self::assertNull($kpis['spam']->value);
        self::assertNull($kpis['ham']->value);
    }

    public function testExtractActionDistribution(): void
    {
        $metricsText = <<<'METRICS'
rspamd_actions_total{action="reject"} 100
rspamd_actions_total{action="no action"} 5000
rspamd_actions_total{action="add header"} 200
rspamd_actions_total{action="greylist"} 50
METRICS;

        $distribution = $this->parser->extractActionDistribution($metricsText);

        self::assertFalse($distribution->isEmpty());
        self::assertSame(5350, $distribution->getTotal());
        self::assertCount(4, $distribution->actions);
        self::assertSame(100, $distribution->actions['reject']);
        self::assertSame(5000, $distribution->actions['no action']);
        self::assertSame(200, $distribution->actions['add header']);
        self::assertSame(50, $distribution->actions['greylist']);
    }

    public function testParseAllWithEmptyInput(): void
    {
        $result = $this->parser->parseAll('');

        self::assertSame([], $result);
    }

    public function testParseAllWithCommentsOnly(): void
    {
        $metricsText = <<<'METRICS'
# This is a comment
# Another comment
METRICS;

        $result = $this->parser->parseAll($metricsText);

        self::assertSame([], $result);
    }
}
