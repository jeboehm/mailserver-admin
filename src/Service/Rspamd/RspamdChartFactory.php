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

        // Process datasets in the defined order
        foreach (RspamdConstants::ACTION_ORDER as $actionName) {
            if (!isset($series->datasets[$actionName])) {
                continue;
            }

            $datasets[] = $this->createDataset($actionName, $series->datasets[$actionName], true);
        }

        // Include any remaining datasets not in the standard order
        foreach ($series->datasets as $name => $values) {
            if (\in_array($name, RspamdConstants::ACTION_ORDER, true)) {
                continue;
            }

            $datasets[] = $this->createDataset($name, $values, false);
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
                        'text' => 'Message rate, msg/min',
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
        $apiColors = $distribution->getColors();

        $backgroundColors = [];
        foreach ($labels as $index => $label) {
            // Use color from API if available, otherwise try to map from constants, otherwise random
            $color = $apiColors[$index] ?? null;
            if (null === $color) {
                $color = RspamdConstants::ACTION_COLORS[strtolower($label)] ?? $this->getRandomColor();
            } else {
                // Convert hex to rgba if needed (Chart.js supports hex, but rgba with alpha is better)
                $color = $this->hexToRgba($color);
            }
            $backgroundColors[] = $color;
        }

        $borderColors = array_map(
            [$this, 'makeBorderColor'],
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

    /**
     * Create a dataset configuration for a line chart.
     */
    private function createDataset(string $name, array $values, bool $isAction): array
    {
        $label = $isAction ? $this->formatActionLabel($name) : $this->formatDatasetLabel($name);
        $color = $this->getColorForName($name);

        return [
            'label' => $label,
            'data' => $values,
            'borderColor' => $this->makeBorderColor($color),
            'backgroundColor' => $color,
            'fill' => true,
            'tension' => 0.3,
        ];
    }

    private function getColorForName(string $name): string
    {
        return RspamdConstants::ACTION_COLORS[strtolower($name)] ?? RspamdConstants::DATASET_COLORS[0];
    }

    private function getRandomColor(): string
    {
        return RspamdConstants::DATASET_COLORS[array_rand(RspamdConstants::DATASET_COLORS)];
    }

    private function makeBorderColor(string $color): string
    {
        return str_replace('0.8)', '1)', $color);
    }

    /**
     * Convert hex color to rgba format with 0.8 alpha.
     */
    private function hexToRgba(string $hex): string
    {
        // Remove # if present
        $hex = ltrim($hex, '#');

        // Handle 3-digit hex
        if (3 === \strlen($hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        // If already rgba, return as is
        if (str_starts_with($hex, 'rgba') || str_starts_with($hex, 'rgb')) {
            return $hex;
        }

        // Convert hex to rgb
        if (6 === \strlen($hex) && ctype_xdigit($hex)) {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));

            return sprintf('rgba(%d, %d, %d, 0.8)', $r, $g, $b);
        }

        // Fallback: return original if conversion fails
        return $hex;
    }
}
