<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Rspamd;

/**
 * Shared constants and definitions for Rspamd services.
 */
final class RspamdConstants
{
    /**
     * KPI definitions: key => [label, icon].
     */
    public const array KPI_DEFINITIONS = [
        'scanned' => ['Messages scanned', 'fa-envelope'],
        'spam' => ['Spam detected', 'fa-ban'],
        'ham' => ['Ham (clean)', 'fa-check'],
        'learned' => ['Learned', 'fa-graduation-cap'],
        'connections' => ['Connections', 'fa-plug'],
    ];

    /**
     * Map Rspamd graph series indices to action names.
     * Rspamd /graph endpoint returns series in a fixed order.
     */
    public const array GRAPH_SERIES_ACTIONS = [
        0 => 'reject',
        1 => 'soft reject',
        2 => 'rewrite subject',
        3 => 'add header',
        4 => 'greylist',
        5 => 'no action',
    ];

    /**
     * Action order for consistent display in charts (matching Rspamd web interface).
     */
    public const array ACTION_ORDER = [
        'reject',
        'soft reject',
        'rewrite subject',
        'add header',
        'greylist',
        'no action',
    ];

    /**
     * Color palette for charts.
     */
    public const array ACTION_COLORS = [
        'reject' => 'rgba(220, 53, 69, 0.8)',    // Red
        'rewrite subject' => 'rgba(255, 193, 7, 0.8)',    // Yellow
        'add header' => 'rgba(255, 159, 64, 0.8)',  // Orange
        'greylist' => 'rgba(108, 117, 125, 0.8)',  // Grey
        'soft reject' => 'rgba(102, 16, 242, 0.8)', // Purple
        'no action' => 'rgba(40, 167, 69, 0.8)',   // Green
    ];

    /**
     * Default dataset colors for charts.
     */
    public const array DATASET_COLORS = [
        'rgba(54, 162, 235, 0.8)',   // Blue
        'rgba(255, 99, 132, 0.8)',   // Red
        'rgba(75, 192, 192, 0.8)',   // Teal
        'rgba(255, 206, 86, 0.8)',   // Yellow
        'rgba(153, 102, 255, 0.8)',  // Purple
        'rgba(255, 159, 64, 0.8)',   // Orange
        'rgba(40, 167, 69, 0.8)',    // Green
    ];

    /**
     * Map stat keys to KPI keys.
     */
    public const array STAT_TO_KPI_MAP = [
        'scanned' => 'scanned',
        'spam_count' => 'spam',
        'ham_count' => 'ham',
        'learned' => 'learned',
        'connections' => 'connections',
    ];

    private function __construct()
    {
        // Prevent instantiation
    }
}
