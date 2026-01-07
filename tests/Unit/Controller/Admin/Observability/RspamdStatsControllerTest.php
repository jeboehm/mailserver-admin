<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Controller\Admin\Observability;

use App\Controller\Admin\Observability\RspamdStatsController;
use App\Service\Rspamd\DTO\ActionDistributionDto;
use App\Service\Rspamd\DTO\HealthDto;
use App\Service\Rspamd\DTO\RspamdSummaryDto;
use App\Service\Rspamd\DTO\TimeSeriesDto;
use App\Service\Rspamd\RspamdChartFactory;
use App\Service\Rspamd\RspamdStatsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\UX\Chartjs\Model\Chart;
use Twig\Environment;

class RspamdStatsControllerTest extends TestCase
{
    private MockObject|Environment $twig;
    private MockObject|RspamdStatsService $statsService;
    private MockObject|RspamdChartFactory $chartFactory;
    private RspamdStatsController $controller;

    protected function setUp(): void
    {
        $this->twig = $this->createMock(Environment::class);
        $this->statsService = $this->createMock(RspamdStatsService::class);
        $this->chartFactory = $this->createMock(RspamdChartFactory::class);

        $this->controller = new RspamdStatsController(
            $this->twig,
            $this->statsService,
            $this->chartFactory
        );
    }

    public function testIndex(): void
    {
        $summary = new RspamdSummaryDto(
            HealthDto::ok(),
            [],
            ActionDistributionDto::empty(),
            new \DateTimeImmutable()
        );

        $this->statsService
            ->expects($this->once())
            ->method('getSummary')
            ->willReturn($summary);

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with(
                'admin/observability/rspamd/index.html.twig',
                $this->callback(fn (array $context) => isset($context['summary']) && $context['summary'] === $summary)
            )
            ->willReturn('rendered html');

        $response = $this->controller->index();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('rendered html', $response->getContent());
    }

    public function testSummary(): void
    {
        $summary = new RspamdSummaryDto(
            HealthDto::ok(),
            [],
            ActionDistributionDto::empty(),
            new \DateTimeImmutable()
        );

        $this->statsService
            ->expects($this->once())
            ->method('getSummary')
            ->willReturn($summary);

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with('admin/observability/rspamd/_summary.html.twig', $this->anything())
            ->willReturn('rendered html');

        $response = $this->controller->summary();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testThroughputWithValidType(): void
    {
        $series = new TimeSeriesDto(TimeSeriesDto::TYPE_DAY, ['00:00'], ['spam' => [10]]);
        $chart = $this->createMock(Chart::class);

        $this->statsService
            ->expects($this->once())
            ->method('getThroughputSeries')
            ->with('day')
            ->willReturn($series);

        $this->chartFactory
            ->expects($this->once())
            ->method('throughputLineChart')
            ->with($series)
            ->willReturn($chart);

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with(
                'admin/observability/rspamd/_throughput.html.twig',
                $this->callback(fn (array $context) => 'day' === $context['type'] && $context['chart'] === $chart)
            )
            ->willReturn('rendered html');

        $request = new Request(['type' => 'day']);
        $response = $this->controller->throughput($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testThroughputWithInvalidType(): void
    {
        $series = TimeSeriesDto::empty('day');
        $chart = $this->createMock(Chart::class);

        $this->statsService
            ->expects($this->once())
            ->method('getThroughputSeries')
            ->with('day') // Invalid type falls back to day
            ->willReturn($series);

        $this->chartFactory
            ->expects($this->once())
            ->method('emptyChart')
            ->willReturn($chart);

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->willReturn('rendered html');

        $request = new Request(['type' => 'invalid']);
        $response = $this->controller->throughput($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testActionsPie(): void
    {
        $distribution = new ActionDistributionDto(['reject' => 100]);
        $chart = $this->createMock(Chart::class);

        $this->statsService
            ->expects($this->once())
            ->method('getActionDistribution')
            ->willReturn($distribution);

        $this->chartFactory
            ->expects($this->once())
            ->method('actionsPieChart')
            ->with($distribution)
            ->willReturn($chart);

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with('admin/observability/rspamd/_actions_pie.html.twig', $this->anything())
            ->willReturn('rendered html');

        $response = $this->controller->actionsPie();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testThresholds(): void
    {
        $this->statsService
            ->expects($this->once())
            ->method('getActionThresholds')
            ->willReturn([]);

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with('admin/observability/rspamd/_thresholds.html.twig', $this->anything())
            ->willReturn('rendered html');

        $response = $this->controller->thresholds();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testCounters(): void
    {
        $this->statsService
            ->expects($this->once())
            ->method('getTopSymbols')
            ->with(20)
            ->willReturn([]);

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with('admin/observability/rspamd/_counters.html.twig', $this->anything())
            ->willReturn('rendered html');

        $request = new Request(['limit' => '20']);
        $response = $this->controller->counters($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testCountersWithLimitBounds(): void
    {
        $this->statsService
            ->expects($this->once())
            ->method('getTopSymbols')
            ->with(100) // Max limit is 100
            ->willReturn([]);

        $this->twig->method('render')->willReturn('');

        $request = new Request(['limit' => '200']);
        $this->controller->counters($request);
    }

    public function testHistory(): void
    {
        $this->statsService
            ->expects($this->once())
            ->method('getHistory')
            ->with(50)
            ->willReturn([]);

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with('admin/observability/rspamd/_history.html.twig', $this->anything())
            ->willReturn('rendered html');

        $request = new Request(['limit' => '50']);
        $response = $this->controller->history($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testHistoryWithLimitBounds(): void
    {
        $this->statsService
            ->expects($this->once())
            ->method('getHistory')
            ->with(10) // Min limit is 10
            ->willReturn([]);

        $this->twig->method('render')->willReturn('');

        $request = new Request(['limit' => '1']);
        $this->controller->history($request);
    }
}
