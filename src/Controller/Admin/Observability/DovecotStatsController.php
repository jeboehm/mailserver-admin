<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller\Admin\Observability;

use App\Exception\Dovecot\DoveadmException;
use App\Service\Dovecot\DoveadmHttpClient;
use App\Service\Dovecot\DovecotChartFactory;
use App\Service\Dovecot\DovecotRateCalculator;
use App\Service\Dovecot\DovecotStatsSampler;
use App\Service\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

#[AdminRoute('/observability/dovecot', name: 'dovecot_stats')]
#[IsGranted(Roles::ROLE_ADMIN)]
final readonly class DovecotStatsController
{
    public function __construct(
        private Environment $twig,
        private DoveadmHttpClient $httpClient,
        private DovecotStatsSampler $sampler,
        private DovecotRateCalculator $rateCalculator,
        private DovecotChartFactory $chartFactory,
    ) {
    }

    /**
     * Main page - shell template that loads fragments.
     */
    #[AdminRoute('/', name: 'index')]
    public function index(): Response
    {
        return new Response($this->twig->render('admin/observability/dovecot_stats/index.html.twig', [
            'isConfigured' => $this->httpClient->isConfigured(),
        ]));
    }

    /**
     * Fragment: Health status and KPI tiles.
     */
    #[AdminRoute('/_summary', name: 'summary_fragment')]
    public function summaryFragment(): Response
    {
        $health = $this->httpClient->checkHealth();
        $latestSample = null;

        if ($health->isHealthy()) {
            try {
                $latestSample = $this->sampler->getLatestSample();
            } catch (DoveadmException) {
                // Silently handle - health already reflects the status
            }
        }

        return new Response($this->twig->render('admin/observability/dovecot_stats/_summary.html.twig', [
            'health' => $health,
            'sample' => $latestSample,
            'lastSampleTime' => $this->sampler->getLastSampleTime(),
        ]));
    }

    /**
     * Fragment: Rate charts.
     */
    #[AdminRoute('/_charts', name: 'charts_fragment')]
    public function chartsFragment(): Response
    {
        $samples = $this->sampler->getSamples();

        $authChart = null;
        $mailDeliveriesChart = null;
        $hasData = !empty($samples) && count($samples) >= 2;

        if ($hasData) {
            $authRates = $this->rateCalculator->calculateAuthRates($samples);
            $authChart = $this->chartFactory->createAuthRatesChart($authRates);

            $mailDeliveryRates = $this->rateCalculator->calculateMailDeliveryRates($samples);
            $mailDeliveriesChart = $this->chartFactory->createMailDeliveriesChart($mailDeliveryRates);
        }

        return new Response($this->twig->render('admin/observability/dovecot_stats/_charts.html.twig', [
            'hasData' => $hasData,
            'sampleCount' => count($samples),
            'authChart' => $authChart,
            'mailDeliveriesChart' => $mailDeliveriesChart,
        ]));
    }

    /**
     * Fragment: Detailed raw counter tables.
     */
    #[AdminRoute('/_raw', name: 'raw_fragment')]
    public function rawFragment(): Response
    {
        $latestSample = $this->sampler->getLatestSample();

        $authCounters = [];
        $sessionCounters = [];
        $ioCounters = [];
        $indexCounters = [];
        $ftsCounters = [];
        $systemCounters = [];
        $otherCounters = [];

        if (null !== $latestSample) {
            $counters = $latestSample->counters;

            // Group counters by category
            foreach ($counters as $name => $value) {
                if (str_starts_with($name, 'auth_')) {
                    $authCounters[$name] = $value;
                } elseif (str_starts_with($name, 'num_')) {
                    $sessionCounters[$name] = $value;
                } elseif (str_starts_with($name, 'disk_') || str_starts_with($name, 'mail_')) {
                    $ioCounters[$name] = $value;
                } elseif (str_starts_with($name, 'idx_')) {
                    $indexCounters[$name] = $value;
                } elseif (str_starts_with($name, 'fts_')) {
                    $ftsCounters[$name] = $value;
                } elseif (str_starts_with($name, 'user_') || str_starts_with($name, 'sys_')
                         || in_array($name, ['clock_time', 'min_faults', 'max_faults', 'vol_cs', 'invol_cs'], true)) {
                    $systemCounters[$name] = $value;
                } else {
                    $otherCounters[$name] = $value;
                }
            }
        }

        return new Response($this->twig->render('admin/observability/dovecot_stats/_raw.html.twig', [
            'sample' => $latestSample,
            'authCounters' => $authCounters,
            'sessionCounters' => $sessionCounters,
            'ioCounters' => $ioCounters,
            'indexCounters' => $indexCounters,
            'ftsCounters' => $ftsCounters,
            'systemCounters' => $systemCounters,
            'otherCounters' => $otherCounters,
        ]));
    }

    /**
     * API: Refresh stats sample.
     */
    #[AdminRoute('/_refresh', name: 'refresh')]
    public function refresh(): Response
    {
        try {
            $sample = $this->sampler->forceFetchSample();

            return new Response(
                json_encode(['success' => true, 'fetchedAt' => $sample->fetchedAt->format(\DateTimeInterface::ATOM)], JSON_THROW_ON_ERROR),
                Response::HTTP_OK,
                ['Content-Type' => 'application/json']
            );
        } catch (DoveadmException $e) {
            return new Response(
                json_encode(['success' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR),
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Content-Type' => 'application/json']
            );
        }
    }
}
