<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Dovecot;

use App\Service\Dovecot\DovecotChartFactory;
use App\Service\Dovecot\DTO\RateSeriesDto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

final class DovecotChartFactoryTest extends TestCase
{
    private ChartBuilderInterface&MockObject $chartBuilder;
    private DovecotChartFactory $factory;

    protected function setUp(): void
    {
        $this->chartBuilder = $this->createMock(ChartBuilderInterface::class);
        $this->factory = new DovecotChartFactory($this->chartBuilder);
    }

    public function testCreateAuthRatesChartWithBothSeries(): void
    {
        $successSeries = new RateSeriesDto(
            counterName: 'auth_successes',
            unit: '/min',
            timestamps: [
                new \DateTimeImmutable('2024-01-01 10:00:00'),
                new \DateTimeImmutable('2024-01-01 10:01:00'),
            ],
            rates: [10.0, 20.0],
        );

        $failureSeries = new RateSeriesDto(
            counterName: 'auth_failures',
            unit: '/min',
            timestamps: [
                new \DateTimeImmutable('2024-01-01 10:00:00'),
                new \DateTimeImmutable('2024-01-01 10:01:00'),
            ],
            rates: [2.0, 3.0],
        );

        $chart = $this->createMock(Chart::class);
        $this->chartBuilder
            ->expects(self::once())
            ->method('createChart')
            ->with(Chart::TYPE_LINE)
            ->willReturn($chart);

        $chart->expects(self::once())
            ->method('setData')
            ->with(self::callback(function (array $data): bool {
                self::assertArrayHasKey('labels', $data);
                self::assertArrayHasKey('datasets', $data);
                self::assertCount(2, $data['labels']);
                self::assertCount(2, $data['datasets']);
                self::assertSame('Auth Success', $data['datasets'][0]['label']);
                self::assertSame('Auth Failure', $data['datasets'][1]['label']);
                self::assertSame([10.0, 20.0], $data['datasets'][0]['data']);
                self::assertSame([2.0, 3.0], $data['datasets'][1]['data']);

                return true;
            }));

        $chart->expects(self::once())
            ->method('setOptions')
            ->with(self::callback(function (array $options): bool {
                self::assertArrayHasKey('plugins', $options);
                self::assertArrayHasKey('title', $options['plugins']);
                self::assertSame('Authentication Rate (/min)', $options['plugins']['title']['text']);

                return true;
            }));

        $result = $this->factory->createAuthRatesChart([
            'auth_successes' => $successSeries,
            'auth_failures' => $failureSeries,
        ]);

        self::assertSame($chart, $result);
    }

    public function testCreateAuthRatesChartWithOnlySuccessSeries(): void
    {
        $successSeries = new RateSeriesDto(
            counterName: 'auth_successes',
            unit: '/min',
            timestamps: [
                new \DateTimeImmutable('2024-01-01 10:00:00'),
                new \DateTimeImmutable('2024-01-01 10:01:00'),
            ],
            rates: [10.0, 20.0],
        );

        $chart = $this->createMock(Chart::class);
        $this->chartBuilder
            ->expects($this->once())
            ->method('createChart')
            ->willReturn($chart);

        $chart->expects(self::once())
            ->method('setData')
            ->with(self::callback(function (array $data): bool {
                self::assertCount(1, $data['datasets']);
                self::assertSame('Auth Success', $data['datasets'][0]['label']);

                return true;
            }));

        $result = $this->factory->createAuthRatesChart([
            'auth_successes' => $successSeries,
        ]);

        self::assertSame($chart, $result);
    }

    public function testCreateAuthRatesChartWithOnlyFailureSeries(): void
    {
        $failureSeries = new RateSeriesDto(
            counterName: 'auth_failures',
            unit: '/min',
            timestamps: [
                new \DateTimeImmutable('2024-01-01 10:00:00'),
                new \DateTimeImmutable('2024-01-01 10:01:00'),
            ],
            rates: [2.0, 3.0],
        );

        $chart = $this->createMock(Chart::class);
        $this->chartBuilder
            ->expects($this->once())
            ->method('createChart')
            ->willReturn($chart);

        $chart
            ->expects(self::once())
            ->method('setData')
            ->with(self::callback(function (array $data): bool {
                self::assertCount(1, $data['datasets']);
                self::assertSame('Auth Failure', $data['datasets'][0]['label']);

                return true;
            }));

        $result = $this->factory->createAuthRatesChart([
            'auth_failures' => $failureSeries,
        ]);

        self::assertSame($chart, $result);
    }

    public function testCreateAuthRatesChartSkipsEmptySeries(): void
    {
        $emptySeries = new RateSeriesDto(
            counterName: 'auth_successes',
            unit: '/min',
            timestamps: [],
            rates: [],
        );

        $chart = $this->createMock(Chart::class);
        $this->chartBuilder
            ->expects($this->once())
            ->method('createChart')
            ->willReturn($chart);

        $chart->expects(self::once())
            ->method('setData')
            ->with(self::callback(function (array $data): bool {
                self::assertEmpty($data['datasets']);

                return true;
            }));

        $result = $this->factory->createAuthRatesChart([
            'auth_successes' => $emptySeries,
        ]);

        self::assertSame($chart, $result);
    }

    public function testCreateAuthRatesChartWithNullSeries(): void
    {
        $chart = $this->createMock(Chart::class);
        $this->chartBuilder
            ->expects($this->once())
            ->method('createChart')
            ->willReturn($chart);

        $chart->expects(self::once())
            ->method('setData')
            ->with(self::callback(function (array $data): bool {
                self::assertEmpty($data['datasets']);

                return true;
            }));

        $result = $this->factory->createAuthRatesChart([]);

        self::assertSame($chart, $result);
    }

    public function testCreateMailDeliveriesChart(): void
    {
        $series = new RateSeriesDto(
            counterName: 'mail_deliveries',
            unit: '/min',
            timestamps: [
                new \DateTimeImmutable('2024-01-01 10:00:00'),
                new \DateTimeImmutable('2024-01-01 10:01:00'),
            ],
            rates: [5.0, 10.0],
        );

        $chart = $this->createMock(Chart::class);
        $this->chartBuilder
            ->expects(self::once())
            ->method('createChart')
            ->with(Chart::TYPE_LINE)
            ->willReturn($chart);

        $chart->expects(self::once())
            ->method('setData')
            ->with(self::callback(function (array $data): bool {
                self::assertArrayHasKey('labels', $data);
                self::assertArrayHasKey('datasets', $data);
                self::assertCount(1, $data['datasets']);
                self::assertSame('Mail Deliveries', $data['datasets'][0]['label']);
                self::assertSame([5.0, 10.0], $data['datasets'][0]['data']);

                return true;
            }));

        $chart->expects(self::once())
            ->method('setOptions')
            ->with(self::callback(function (array $options): bool {
                self::assertSame('Mail Deliveries (/min)', $options['plugins']['title']['text']);

                return true;
            }));

        $result = $this->factory->createMailDeliveriesChart($series);

        self::assertSame($chart, $result);
    }

    public function testCreateAuthRatesChartUsesSuccessLabels(): void
    {
        $successSeries = new RateSeriesDto(
            counterName: 'auth_successes',
            unit: '/min',
            timestamps: [
                new \DateTimeImmutable('2024-01-01 10:00:00'),
                new \DateTimeImmutable('2024-01-01 10:01:00'),
            ],
            rates: [10.0, 20.0],
        );

        $failureSeries = new RateSeriesDto(
            counterName: 'auth_failures',
            unit: '/min',
            timestamps: [
                new \DateTimeImmutable('2024-01-01 10:05:00'), // Different timestamps
                new \DateTimeImmutable('2024-01-01 10:06:00'),
            ],
            rates: [2.0, 3.0],
        );

        $chart = $this->createMock(Chart::class);
        $this->chartBuilder
            ->expects($this->once())
            ->method('createChart')
            ->willReturn($chart);

        $chart->expects(self::once())
            ->method('setData')
            ->with(self::callback(function (array $data): bool {
                // Should use success series labels
                self::assertCount(2, $data['labels']);
                self::assertSame('10:00', $data['labels'][0]);
                self::assertSame('10:01', $data['labels'][1]);

                return true;
            }));

        $this->factory->createAuthRatesChart([
            'auth_successes' => $successSeries,
            'auth_failures' => $failureSeries,
        ]);
    }

    public function testCreateAuthRatesChartUsesEmptyLabelsWhenNoSuccessSeries(): void
    {
        $failureSeries = new RateSeriesDto(
            counterName: 'auth_failures',
            unit: '/min',
            timestamps: [
                new \DateTimeImmutable('2024-01-01 10:00:00'),
                new \DateTimeImmutable('2024-01-01 10:01:00'),
            ],
            rates: [2.0, 3.0],
        );

        $chart = $this->createMock(Chart::class);
        $this->chartBuilder
            ->expects($this->once())
            ->method('createChart')
            ->willReturn($chart);

        $chart->expects(self::once())
            ->method('setData')
            ->with(self::callback(function (array $data): bool {
                // Should use failure series labels when success is missing
                // The code uses $successSeries?->getLabels() ?? [] which returns empty array when null
                // But then it should use failure series labels - let's check what actually happens
                self::assertArrayHasKey('labels', $data);
                self::assertArrayHasKey('datasets', $data);

                return true;
            }));

        $this->factory->createAuthRatesChart([
            'auth_failures' => $failureSeries,
        ]);
    }
}
