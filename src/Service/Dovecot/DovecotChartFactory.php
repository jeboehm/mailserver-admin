<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Dovecot;

use App\Service\Dovecot\DTO\RateSeriesDto;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

/**
 * Factory for creating Dovecot statistics charts using Symfony UX Chart.js.
 */
readonly class DovecotChartFactory
{
    /**
     * Color palette for chart datasets.
     */
    private const array COLORS = [
        'success' => 'rgba(40, 167, 69, 0.8)',
        'success_bg' => 'rgba(40, 167, 69, 0.2)',
        'danger' => 'rgba(220, 53, 69, 0.8)',
        'danger_bg' => 'rgba(220, 53, 69, 0.2)',
        'primary' => 'rgba(0, 123, 255, 0.8)',
        'primary_bg' => 'rgba(0, 123, 255, 0.2)',
        'warning' => 'rgba(255, 193, 7, 0.8)',
        'warning_bg' => 'rgba(255, 193, 7, 0.2)',
        'info' => 'rgba(23, 162, 184, 0.8)',
        'info_bg' => 'rgba(23, 162, 184, 0.2)',
        'secondary' => 'rgba(108, 117, 125, 0.8)',
        'secondary_bg' => 'rgba(108, 117, 125, 0.2)',
    ];

    public function __construct(
        private ChartBuilderInterface $chartBuilder,
    ) {
    }

    /**
     * Create an authentication rates chart (success/failure per minute).
     *
     * @param array<string, RateSeriesDto> $rates
     */
    public function createAuthRatesChart(array $rates): Chart
    {
        $successSeries = $rates['auth_successes'] ?? null;
        $failureSeries = $rates['auth_failures'] ?? null;

        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);

        // Use the success series for labels (both should have the same timestamps)
        $labels = $successSeries?->getLabels() ?? [];

        $datasets = [];

        if (null !== $successSeries && !$successSeries->isEmpty()) {
            $datasets[] = [
                'label' => 'Auth Success',
                'data' => $successSeries->rates,
                'borderColor' => self::COLORS['success'],
                'backgroundColor' => self::COLORS['success_bg'],
                'fill' => true,
                'tension' => 0.3,
            ];
        }

        if (null !== $failureSeries && !$failureSeries->isEmpty()) {
            $datasets[] = [
                'label' => 'Auth Failure',
                'data' => $failureSeries->rates,
                'borderColor' => self::COLORS['danger'],
                'backgroundColor' => self::COLORS['danger_bg'],
                'fill' => true,
                'tension' => 0.3,
            ];
        }

        $chart->setData([
            'labels' => $labels,
            'datasets' => $datasets,
        ]);

        $chart->setOptions($this->getDefaultLineOptions('Authentication Rate (/min)'));

        return $chart;
    }

    /**
     * Create a mail deliveries rate chart.
     */
    public function createMailDeliveriesChart(RateSeriesDto $series): Chart
    {
        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);

        $chart->setData([
            'labels' => $series->getLabels(),
            'datasets' => [
                [
                    'label' => 'Mail Deliveries',
                    'data' => $series->rates,
                    'borderColor' => self::COLORS['success'],
                    'backgroundColor' => self::COLORS['success_bg'],
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
        ]);

        $chart->setOptions($this->getDefaultLineOptions('Mail Deliveries (/min)'));

        return $chart;
    }

    /**
     * Get default options for line charts.
     *
     * @return array<string, mixed>
     */
    private function getDefaultLineOptions(string $title, bool $useKbScale = false): array
    {
        $options = [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                ],
                'title' => [
                    'display' => true,
                    'text' => $title,
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
                'x' => [
                    'display' => true,
                ],
            ],
            'elements' => [
                'point' => [
                    'radius' => 2,
                    'hoverRadius' => 4,
                ],
            ],
        ];

        if ($useKbScale) {
            $options['scales']['y']['ticks']['callback'] = "function(value) { return (value).toFixed(1) + ' KB'; }";
        }

        return $options;
    }
}
