<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller\Admin\Observability;

use App\Service\Rspamd\DTO\TimeSeriesDto;
use App\Service\Rspamd\RspamdChartFactory;
use App\Service\Rspamd\RspamdStatsService;
use App\Service\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

#[AdminRoute('/observability/rspamd', name: 'observability_rspamd')]
#[IsGranted(Roles::ROLE_ADMIN)]
final readonly class RspamdStatsController
{
    public function __construct(
        private Environment $twig,
        private RspamdStatsService $statsService,
        private RspamdChartFactory $chartFactory,
    ) {
    }

    /**
     * Main dashboard page.
     */
    #[AdminRoute('/', name: '_index')]
    public function index(): Response
    {
        $summary = $this->statsService->getSummary();

        return new Response($this->twig->render('admin/observability/rspamd/index.html.twig', [
            'summary' => $summary,
        ]));
    }

    /**
     * Fragment: Summary section (health + KPIs).
     */
    #[AdminRoute('/_summary', name: '_summary')]
    public function summary(): Response
    {
        $summary = $this->statsService->getSummary();

        return new Response($this->twig->render('admin/observability/rspamd/_summary.html.twig', [
            'summary' => $summary,
        ]));
    }

    /**
     * Fragment: Throughput chart.
     */
    #[AdminRoute('/_throughput', name: '_throughput')]
    public function throughput(Request $request): Response
    {
        $type = $request->query->getString('type', TimeSeriesDto::TYPE_DAY);

        if (!TimeSeriesDto::isValidType($type)) {
            $type = TimeSeriesDto::TYPE_DAY;
        }

        $series = $this->statsService->getThroughputSeries($type);

        if ($series->isEmpty()) {
            $chart = $this->chartFactory->emptyChart('No throughput data available');
        } else {
            $chart = $this->chartFactory->throughputLineChart($series);
        }

        return new Response($this->twig->render('admin/observability/rspamd/_throughput.html.twig', [
            'type' => $type,
            'chart' => $chart,
            'validTypes' => TimeSeriesDto::VALID_TYPES,
            'embedded' => true,
        ]));
    }

    /**
     * Fragment: Action distribution pie chart.
     */
    #[AdminRoute('/_actions_pie', name: '_actions_pie')]
    public function actionsPie(): Response
    {
        $distribution = $this->statsService->getActionDistribution();

        if ($distribution->isEmpty()) {
            $chart = $this->chartFactory->emptyChart('No action data available');
        } else {
            $chart = $this->chartFactory->actionsPieChart($distribution);
        }

        return new Response($this->twig->render('admin/observability/rspamd/_actions_pie.html.twig', [
            'distribution' => $distribution,
            'chart' => $chart,
            'embedded' => true,
        ]));
    }

    /**
     * Fragment: Action thresholds table.
     */
    #[AdminRoute('/_thresholds', name: '_thresholds')]
    public function thresholds(): Response
    {
        $thresholds = $this->statsService->getActionThresholds();

        return new Response($this->twig->render('admin/observability/rspamd/_thresholds.html.twig', [
            'thresholds' => $thresholds,
            'embedded' => true,
        ]));
    }

    /**
     * Fragment: Top symbols/counters table.
     */
    #[AdminRoute('/_counters', name: '_counters')]
    public function counters(Request $request): Response
    {
        $limit = $request->query->getInt('limit', 20);
        $limit = max(5, min(100, $limit));

        $counters = $this->statsService->getTopSymbols($limit);

        return new Response($this->twig->render('admin/observability/rspamd/_counters.html.twig', [
            'counters' => $counters,
            'limit' => $limit,
            'embedded' => true,
        ]));
    }

    /**
     * Fragment: Recent history table.
     */
    #[AdminRoute('/_history', name: '_history')]
    public function history(Request $request): Response
    {
        $limit = $request->query->getInt('limit', 50);
        $limit = max(10, min(200, $limit));

        $history = $this->statsService->getHistory($limit);

        return new Response($this->twig->render('admin/observability/rspamd/_history.html.twig', [
            'history' => $history,
            'limit' => $limit,
            'embedded' => true,
        ]));
    }
}
