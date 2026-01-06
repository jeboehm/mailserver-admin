<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Rspamd;

use App\Service\Rspamd\DTO\ActionDistributionDto;
use App\Service\Rspamd\DTO\TimeSeriesDto;
use App\Service\Rspamd\RspamdChartFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

class RspamdChartFactoryTest extends TestCase
{
    private MockObject|ChartBuilderInterface $chartBuilder;
    private RspamdChartFactory $factory;

    protected function setUp(): void
    {
        $this->chartBuilder = $this->createMock(ChartBuilderInterface::class);
        $this->factory = new RspamdChartFactory($this->chartBuilder);
    }

    public function testThroughputLineChart(): void
    {
        $series = new TimeSeriesDto(
            TimeSeriesDto::TYPE_HOURLY,
            ['00:00', '01:00', '02:00'],
            [
                'spam' => [10, 20, 30],
                'ham' => [100, 200, 300],
            ]
        );

        $chart = $this->createMock(Chart::class);
        $chart->expects($this->once())->method('setData')->with($this->callback(function (array $data) {
            return isset($data['labels'], $data['datasets'])
                && 3 === \count($data['labels'])
                && 2 === \count($data['datasets']);
        }));
        $chart->expects($this->once())->method('setOptions');

        $this->chartBuilder
            ->expects($this->once())
            ->method('createChart')
            ->with(Chart::TYPE_LINE)
            ->willReturn($chart);

        $result = $this->factory->throughputLineChart($series);

        self::assertSame($chart, $result);
    }

    public function testActionsPieChart(): void
    {
        $distribution = new ActionDistributionDto([
            'reject' => 100,
            'no action' => 500,
            'add header' => 50,
        ]);

        $chart = $this->createMock(Chart::class);
        $chart->expects($this->once())->method('setData')->with($this->callback(function (array $data) {
            return isset($data['labels'], $data['datasets'])
                && 3 === \count($data['labels'])
                && [100, 500, 50] === $data['datasets'][0]['data'];
        }));
        $chart->expects($this->once())->method('setOptions');

        $this->chartBuilder
            ->expects($this->once())
            ->method('createChart')
            ->with(Chart::TYPE_DOUGHNUT)
            ->willReturn($chart);

        $result = $this->factory->actionsPieChart($distribution, true);

        self::assertSame($chart, $result);
    }

    public function testActionsPieChartAsPie(): void
    {
        $distribution = new ActionDistributionDto(['reject' => 100]);

        $chart = $this->createMock(Chart::class);
        $chart->method('setData');
        $chart->method('setOptions');

        $this->chartBuilder
            ->expects($this->once())
            ->method('createChart')
            ->with(Chart::TYPE_PIE)
            ->willReturn($chart);

        $this->factory->actionsPieChart($distribution, false);
    }

    public function testEmptyChart(): void
    {
        $chart = $this->createMock(Chart::class);
        $chart->expects($this->once())->method('setData')->with($this->callback(function (array $data) {
            return isset($data['labels']) && ['No data available'] === $data['labels'];
        }));
        $chart->expects($this->once())->method('setOptions');

        $this->chartBuilder
            ->expects($this->once())
            ->method('createChart')
            ->with(Chart::TYPE_BAR)
            ->willReturn($chart);

        $result = $this->factory->emptyChart();

        self::assertSame($chart, $result);
    }

    public function testEmptyChartWithCustomMessage(): void
    {
        $chart = $this->createMock(Chart::class);
        $chart->expects($this->once())->method('setData')->with($this->callback(function (array $data) {
            return isset($data['labels']) && ['Custom message'] === $data['labels'];
        }));
        $chart->method('setOptions');

        $this->chartBuilder
            ->method('createChart')
            ->willReturn($chart);

        $this->factory->emptyChart('Custom message');
    }
}
