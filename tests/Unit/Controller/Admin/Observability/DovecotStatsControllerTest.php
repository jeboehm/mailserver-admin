<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Controller\Admin\Observability;

use App\Controller\Admin\Observability\DovecotStatsController;
use App\Exception\Dovecot\DoveadmException;
use App\Service\Dovecot\DoveadmHttpClient;
use App\Service\Dovecot\DovecotChartFactory;
use App\Service\Dovecot\DovecotRateCalculator;
use App\Service\Dovecot\DovecotStatsSampler;
use App\Service\Dovecot\DTO\DoveadmHealthDto;
use App\Service\Dovecot\DTO\RateSeriesDto;
use App\Service\Dovecot\DTO\StatsDumpDto;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\UX\Chartjs\Model\Chart;
use Twig\Environment;

#[AllowMockObjectsWithoutExpectations]
class DovecotStatsControllerTest extends TestCase
{
    private MockObject|Environment $twig;
    private MockObject|DoveadmHttpClient $httpClient;
    private MockObject|DovecotStatsSampler $sampler;
    private MockObject|DovecotRateCalculator $rateCalculator;
    private MockObject|DovecotChartFactory $chartFactory;
    private DovecotStatsController $controller;

    protected function setUp(): void
    {
        $this->twig = $this->createMock(Environment::class);
        $this->httpClient = $this->createMock(DoveadmHttpClient::class);
        $this->sampler = $this->createMock(DovecotStatsSampler::class);
        $this->rateCalculator = $this->createMock(DovecotRateCalculator::class);
        $this->chartFactory = $this->createMock(DovecotChartFactory::class);

        $this->controller = new DovecotStatsController(
            $this->twig,
            $this->httpClient,
            $this->sampler,
            $this->rateCalculator,
            $this->chartFactory,
        );
    }

    public function testIndex(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('isConfigured')
            ->willReturn(true);

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with(
                'admin/dovecot_stats/index.html.twig',
                $this->callback(fn (array $context) => isset($context['isConfigured']) && true === $context['isConfigured'])
            )
            ->willReturn('rendered html');

        $response = $this->controller->index();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('rendered html', $response->getContent());
    }

    public function testIndexWhenNotConfigured(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('isConfigured')
            ->willReturn(false);

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with(
                'admin/dovecot_stats/index.html.twig',
                $this->callback(fn (array $context) => isset($context['isConfigured']) && false === $context['isConfigured'])
            )
            ->willReturn('rendered html');

        $response = $this->controller->index();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testSummaryFragmentWithHealthySystem(): void
    {
        $health = DoveadmHealthDto::ok(new \DateTimeImmutable());
        $sample = $this->createSample();
        $lastSampleTime = new \DateTimeImmutable();
        $cacheHitRate = 85.5;
        $resetDateTime = new \DateTimeImmutable();

        $this->httpClient
            ->expects($this->once())
            ->method('checkHealth')
            ->willReturn($health);

        $this->sampler
            ->expects($this->once())
            ->method('getLatestSample')
            ->willReturn($sample);

        $this->rateCalculator
            ->expects($this->once())
            ->method('calculateCacheHitRate')
            ->with($sample)
            ->willReturn($cacheHitRate);

        $this->sampler
            ->expects($this->once())
            ->method('getLastSampleTime')
            ->willReturn($lastSampleTime);

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with(
                'admin/dovecot_stats/_summary.html.twig',
                $this->callback(function (array $context) use ($health, $cacheHitRate) {
                    return isset($context['health'])
                        && $context['health'] instanceof DoveadmHealthDto
                        && $context['health']->isHealthy() === $health->isHealthy()
                        && isset($context['sample'])
                        && $context['sample'] instanceof StatsDumpDto
                        && isset($context['cacheHitRate'])
                        && $context['cacheHitRate'] === $cacheHitRate
                        && isset($context['resetDateTime'])
                        && $context['resetDateTime'] instanceof \DateTimeImmutable
                        && isset($context['lastSampleTime'])
                        && $context['lastSampleTime'] instanceof \DateTimeImmutable;
                })
            )
            ->willReturn('rendered html');

        $response = $this->controller->summaryFragment();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('rendered html', $response->getContent());
    }

    public function testSummaryFragmentWithUnhealthySystem(): void
    {
        $health = DoveadmHealthDto::notConfigured();

        $this->httpClient
            ->expects($this->once())
            ->method('checkHealth')
            ->willReturn($health);

        $this->sampler
            ->expects($this->never())
            ->method('getLatestSample');

        $this->sampler
            ->expects($this->once())
            ->method('getLastSampleTime')
            ->willReturn(null);

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with(
                'admin/dovecot_stats/_summary.html.twig',
                $this->anything()
            )
            ->willReturn('rendered html');

        $response = $this->controller->summaryFragment();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testSummaryFragmentWithException(): void
    {
        $health = DoveadmHealthDto::ok(new \DateTimeImmutable());

        $this->httpClient
            ->expects($this->once())
            ->method('checkHealth')
            ->willReturn($health);

        $this->sampler
            ->expects($this->once())
            ->method('getLatestSample')
            ->willThrowException(new DoveadmException('Connection failed'));

        $this->sampler
            ->expects($this->once())
            ->method('getLastSampleTime')
            ->willReturn(null);

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with(
                'admin/dovecot_stats/_summary.html.twig',
                $this->anything()
            )
            ->willReturn('rendered html');

        $response = $this->controller->summaryFragment();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testChartsFragmentWithData(): void
    {
        $samples = [
            $this->createSample(),
            $this->createSample(),
        ];
        $authRates = [
            'auth_successes' => new RateSeriesDto('auth_successes', '/min', [], []),
            'auth_failures' => new RateSeriesDto('auth_failures', '/min', [], []),
        ];
        $mailDeliveryRates = new RateSeriesDto('mail_deliveries', '/min', [], []);
        $authChart = $this->createMock(Chart::class);
        $mailDeliveriesChart = $this->createMock(Chart::class);

        $this->sampler
            ->expects($this->once())
            ->method('getSamples')
            ->willReturn($samples);

        $this->rateCalculator
            ->expects($this->once())
            ->method('calculateAuthRates')
            ->with($samples)
            ->willReturn($authRates);

        $this->chartFactory
            ->expects($this->once())
            ->method('createAuthRatesChart')
            ->with($authRates)
            ->willReturn($authChart);

        $this->rateCalculator
            ->expects($this->once())
            ->method('calculateMailDeliveryRates')
            ->with($samples)
            ->willReturn($mailDeliveryRates);

        $this->chartFactory
            ->expects($this->once())
            ->method('createMailDeliveriesChart')
            ->with($mailDeliveryRates)
            ->willReturn($mailDeliveriesChart);

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with(
                'admin/dovecot_stats/_charts.html.twig',
                $this->callback(function (array $context) {
                    return isset($context['hasData'])
                        && true === $context['hasData']
                        && isset($context['sampleCount'])
                        && 2 === $context['sampleCount']
                        && isset($context['authChart'])
                        && $context['authChart'] instanceof Chart
                        && isset($context['mailDeliveriesChart'])
                        && $context['mailDeliveriesChart'] instanceof Chart;
                })
            )
            ->willReturn('rendered html');

        $response = $this->controller->chartsFragment();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('rendered html', $response->getContent());
    }

    public function testChartsFragmentWithoutData(): void
    {
        $this->sampler
            ->expects($this->once())
            ->method('getSamples')
            ->willReturn([]);

        $this->rateCalculator
            ->expects($this->never())
            ->method('calculateAuthRates');

        $this->chartFactory
            ->expects($this->never())
            ->method('createAuthRatesChart');

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with(
                'admin/dovecot_stats/_charts.html.twig',
                $this->anything()
            )
            ->willReturn('rendered html');

        $response = $this->controller->chartsFragment();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testChartsFragmentWithInsufficientData(): void
    {
        $samples = [$this->createSample()];

        $this->sampler
            ->expects($this->once())
            ->method('getSamples')
            ->willReturn($samples);

        $this->rateCalculator
            ->expects($this->never())
            ->method('calculateAuthRates');

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with(
                'admin/dovecot_stats/_charts.html.twig',
                $this->callback(function (array $context) {
                    return isset($context['hasData'])
                        && false === $context['hasData']
                        && isset($context['sampleCount'])
                        && 1 === $context['sampleCount'];
                })
            )
            ->willReturn('rendered html');

        $response = $this->controller->chartsFragment();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testRawFragmentWithSample(): void
    {
        $sample = $this->createSampleWithCounters([
            'auth_successes' => 100,
            'auth_failures' => 5,
            'num_logins' => 50,
            'num_cmds' => 200,
            'disk_input' => 1024,
            'mail_read_bytes' => 2048,
            'idx_read' => 10,
            'fts_read' => 5,
            'user_cpu' => 1.5,
            'sys_cpu' => 0.5,
            'other_counter' => 99,
        ]);

        $this->sampler
            ->expects($this->once())
            ->method('getLatestSample')
            ->willReturn($sample);

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with(
                'admin/dovecot_stats/_raw.html.twig',
                $this->callback(function (array $context) {
                    return isset($context['sample'])
                        && $context['sample'] instanceof StatsDumpDto
                        && isset($context['authCounters'])
                        && is_array($context['authCounters'])
                        && isset($context['authCounters']['auth_successes'])
                        && isset($context['authCounters']['auth_failures'])
                        && isset($context['sessionCounters'])
                        && is_array($context['sessionCounters'])
                        && isset($context['sessionCounters']['num_logins'])
                        && isset($context['ioCounters'])
                        && is_array($context['ioCounters'])
                        && isset($context['ioCounters']['disk_input'])
                        && isset($context['indexCounters'])
                        && is_array($context['indexCounters'])
                        && isset($context['indexCounters']['idx_read'])
                        && isset($context['ftsCounters'])
                        && is_array($context['ftsCounters'])
                        && isset($context['ftsCounters']['fts_read'])
                        && isset($context['systemCounters'])
                        && is_array($context['systemCounters'])
                        && isset($context['systemCounters']['user_cpu'])
                        && isset($context['otherCounters'])
                        && is_array($context['otherCounters'])
                        && isset($context['otherCounters']['other_counter']);
                })
            )
            ->willReturn('rendered html');

        $response = $this->controller->rawFragment();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('rendered html', $response->getContent());
    }

    public function testRawFragmentWithoutSample(): void
    {
        $this->sampler
            ->expects($this->once())
            ->method('getLatestSample')
            ->willReturn(null);

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with(
                'admin/dovecot_stats/_raw.html.twig',
                $this->anything()
            )
            ->willReturn('rendered html');

        $response = $this->controller->rawFragment();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testRefreshSuccess(): void
    {
        $sample = $this->createSample();
        $fetchedAt = $sample->fetchedAt;

        $this->sampler
            ->expects($this->once())
            ->method('forceFetchSample')
            ->willReturn($sample);

        $response = $this->controller->refresh();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));

        $content = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($content['success']);
        self::assertSame($fetchedAt->format(\DateTimeInterface::ATOM), $content['fetchedAt']);
    }

    public function testRefreshWithException(): void
    {
        $exception = new DoveadmException('Connection failed');

        $this->sampler
            ->expects($this->once())
            ->method('forceFetchSample')
            ->willThrowException($exception);

        $response = $this->controller->refresh();

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));

        $content = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($content['success']);
        self::assertSame('Connection failed', $content['error']);
    }

    private function createSample(): StatsDumpDto
    {
        return new StatsDumpDto(
            fetchedAt: new \DateTimeImmutable(),
            lastUpdateSeconds: time(),
            resetTimestamp: time() - 3600,
            counters: [],
        );
    }

    private function createSampleWithCounters(array $counters): StatsDumpDto
    {
        return new StatsDumpDto(
            fetchedAt: new \DateTimeImmutable(),
            lastUpdateSeconds: time(),
            resetTimestamp: time() - 3600,
            counters: $counters,
        );
    }
}
