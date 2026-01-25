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

final class RspamdChartFactoryTest extends TestCase
{
    private ChartBuilderInterface&MockObject $chartBuilder;
    private RspamdChartFactory $factory;

    protected function setUp(): void
    {
        $this->chartBuilder = $this->createMock(ChartBuilderInterface::class);
        $this->factory = new RspamdChartFactory($this->chartBuilder);
    }

    public function testThroughputLineChartCreatesLineChart(): void
    {
        $chart = $this->createMock(Chart::class);
        $this->chartBuilder->expects(self::once())
            ->method('createChart')
            ->with(Chart::TYPE_LINE)
            ->willReturn($chart);

        $series = new TimeSeriesDto(
            'day',
            ['10:00', '11:00', '12:00'],
            [
                'reject' => [1.0, 2.0, 3.0],
                'no action' => [10.0, 20.0, 30.0],
            ]
        );

        $chart->expects(self::once())
            ->method('setData')
            ->with(self::callback(static function (array $data) {
                return isset($data['labels']) && isset($data['datasets']);
            }));

        $chart->expects(self::once())
            ->method('setOptions')
            ->with(self::callback(static function (array $options) {
                return isset($options['responsive']) && isset($options['scales']);
            }));

        $result = $this->factory->throughputLineChart($series);

        self::assertInstanceOf(Chart::class, $result);
    }

    public function testThroughputLineChartHandlesEmptySeries(): void
    {
        $chart = $this->createMock(Chart::class);
        $this->chartBuilder->expects(self::once())
            ->method('createChart')
            ->with(Chart::TYPE_LINE)
            ->willReturn($chart);

        $series = new TimeSeriesDto('day', [], []);

        $chart->expects(self::once())->method('setData');
        $chart->expects(self::once())->method('setOptions');

        $result = $this->factory->throughputLineChart($series);

        self::assertInstanceOf(Chart::class, $result);
    }

    public function testActionsPieChartCreatesDoughnutChart(): void
    {
        $chart = $this->createMock(Chart::class);
        $this->chartBuilder->expects(self::once())
            ->method('createChart')
            ->with(Chart::TYPE_DOUGHNUT)
            ->willReturn($chart);

        $distribution = new ActionDistributionDto(
            ['reject' => 10, 'no action' => 90],
            ['reject' => '#FF0000']
        );

        $chart->expects(self::once())
            ->method('setData')
            ->with(self::callback(static function (array $data) {
                return isset($data['labels']) && isset($data['datasets']);
            }));

        $chart->expects(self::once())
            ->method('setOptions')
            ->with(self::callback(static function (array $options) {
                return isset($options['responsive']) && isset($options['plugins']);
            }));

        $result = $this->factory->actionsPieChart($distribution);

        self::assertInstanceOf(Chart::class, $result);
    }

    public function testActionsPieChartHandlesEmptyDistribution(): void
    {
        $chart = $this->createMock(Chart::class);
        $this->chartBuilder->expects(self::once())
            ->method('createChart')
            ->with(Chart::TYPE_DOUGHNUT)
            ->willReturn($chart);

        $distribution = ActionDistributionDto::empty();

        $chart->expects(self::once())->method('setData');
        $chart->expects(self::once())->method('setOptions');

        $result = $this->factory->actionsPieChart($distribution);

        self::assertInstanceOf(Chart::class, $result);
    }

    public function testEmptyChartCreatesBarChart(): void
    {
        $chart = $this->createMock(Chart::class);
        $this->chartBuilder->expects(self::once())
            ->method('createChart')
            ->with(Chart::TYPE_BAR)
            ->willReturn($chart);

        $chart->expects(self::once())
            ->method('setData')
            ->with(self::callback(static function (array $data) {
                return isset($data['labels']) && 'No data available' === $data['labels'][0];
            }));

        $chart->expects(self::once())
            ->method('setOptions')
            ->with(self::callback(static function (array $options) {
                return isset($options['responsive']) && isset($options['plugins']);
            }));

        $result = $this->factory->emptyChart();

        self::assertInstanceOf(Chart::class, $result);
    }

    public function testEmptyChartWithCustomMessage(): void
    {
        $chart = $this->createMock(Chart::class);
        $this->chartBuilder->expects(self::once())
            ->method('createChart')
            ->with(Chart::TYPE_BAR)
            ->willReturn($chart);

        $message = 'Custom error message';
        $chart->expects(self::once())
            ->method('setData')
            ->with(self::callback(static function (array $data) use ($message) {
                return isset($data['labels']) && $data['labels'][0] === $message;
            }));

        $chart->expects(self::once())->method('setOptions');

        $result = $this->factory->emptyChart($message);

        self::assertInstanceOf(Chart::class, $result);
    }
}
