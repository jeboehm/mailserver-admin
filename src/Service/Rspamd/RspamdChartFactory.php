<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Rspamd;

use App\Service\Rspamd\DTO\ActionDistributionDto;
use App\Service\Rspamd\DTO\TimeSeriesDto;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

/**
 * Factory for creating Chart.js charts for Rspamd statistics.
 */
final readonly class RspamdChartFactory
{
    /**
     * Color palette for charts.
     */
    private const array COLORS = [
        'reject' => 'rgba(220, 53, 69, 0.8)',    // Red
        'rewrite subject' => 'rgba(255, 193, 7, 0.8)',    // Yellow
        'add header' => 'rgba(255, 159, 64, 0.8)',  // Orange
        'greylist' => 'rgba(108, 117, 125, 0.8)',  // Grey
        'soft reject' => 'rgba(102, 16, 242, 0.8)', // Purple
        'no action' => 'rgba(40, 167, 69, 0.8)',   // Green
    ];

    private const array DATASET_COLORS = [
        'rgba(54, 162, 235, 0.8)',   // Blue
        'rgba(255, 99, 132, 0.8)',   // Red
        'rgba(75, 192, 192, 0.8)',   // Teal
        'rgba(255, 206, 86, 0.8)',   // Yellow
        'rgba(153, 102, 255, 0.8)',  // Purple
        'rgba(255, 159, 64, 0.8)',   // Orange
        'rgba(40, 167, 69, 0.8)',    // Green
    ];

    public function __construct(
        private ChartBuilderInterface $chartBuilder,
    ) {
    }

    /**
     * Create a line chart for throughput time series.
     */
    public function throughputLineChart(TimeSeriesDto $series): Chart
    {
        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);

        $datasets = [];
        $colorIndex = 0;

        foreach ($series->datasets as $name => $values) {
            $color = self::DATASET_COLORS[$colorIndex % \count(self::DATASET_COLORS)];
            $borderColor = str_replace('0.8)', '1)', $color);

            $datasets[] = [
                'label' => $this->formatDatasetLabel($name),
                'data' => $values,
                'borderColor' => $borderColor,
                'backgroundColor' => $color,
                'fill' => true,
                'tension' => 0.3,
            ];

            ++$colorIndex;
        }

        $chart->setData([
            'labels' => $series->labels,
            'datasets' => $datasets,
        ]);

        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'top',
                ],
                'tooltip' => [
                    'mode' => 'index',
                    'intersect' => false,
                ],
            ],
            'scales' => [
                'x' => [
                    'display' => true,
                    'title' => [
                        'display' => false,
                    ],
                    'ticks' => [
                        'maxRotation' => 45,
                        'minRotation' => 0,
                    ],
                ],
                'y' => [
                    'display' => true,
                    'title' => [
                        'display' => true,
                        'text' => 'Messages',
                    ],
                    'beginAtZero' => true,
                ],
            ],
            'interaction' => [
                'mode' => 'nearest',
                'axis' => 'x',
                'intersect' => false,
            ],
        ]);

        return $chart;
    }

    /**
     * Create a pie/doughnut chart for action distribution.
     */
    public function actionsPieChart(ActionDistributionDto $distribution, bool $doughnut = true): Chart
    {
        $chart = $this->chartBuilder->createChart(
            $doughnut ? Chart::TYPE_DOUGHNUT : Chart::TYPE_PIE
        );

        $labels = $distribution->getLabels();
        $values = $distribution->getValues();

        $backgroundColors = array_map(
            fn (string $label) => self::COLORS[strtolower($label)] ?? $this->getRandomColor(),
            $labels
        );

        $borderColors = array_map(
            static fn (string $color) => str_replace('0.8)', '1)', $color),
            $backgroundColors
        );

        $chart->setData([
            'labels' => array_map([$this, 'formatActionLabel'], $labels),
            'datasets' => [
                [
                    'data' => $values,
                    'backgroundColor' => $backgroundColors,
                    'borderColor' => $borderColors,
                    'borderWidth' => 1,
                ],
            ],
        ]);

        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'right',
                ],
                'tooltip' => [
                    'callbacks' => [
                        // Will be handled by Chart.js defaults
                    ],
                ],
            ],
        ]);

        return $chart;
    }

    /**
     * Create an empty placeholder chart.
     */
    public function emptyChart(string $message = 'No data available'): Chart
    {
        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);

        $chart->setData([
            'labels' => [$message],
            'datasets' => [
                [
                    'data' => [0],
                    'backgroundColor' => ['rgba(108, 117, 125, 0.3)'],
                ],
            ],
        ]);

        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
            ],
            'scales' => [
                'y' => [
                    'display' => false,
                ],
            ],
        ]);

        return $chart;
    }

    private function formatDatasetLabel(string $name): string
    {
        return ucfirst(str_replace('_', ' ', $name));
    }

    private function formatActionLabel(string $action): string
    {
        return ucwords(str_replace('_', ' ', $action));
    }

    private function getRandomColor(): string
    {
        $colors = self::DATASET_COLORS;

        return $colors[array_rand($colors)];
    }
}
