<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Rspamd\DTO;

use App\Service\Rspamd\DTO\ActionDistributionDto;
use PHPUnit\Framework\TestCase;

class ActionDistributionDtoTest extends TestCase
{
    public function testConstruction(): void
    {
        $dto = new ActionDistributionDto([
            'reject' => 100,
            'no action' => 500,
            'add header' => 50,
        ]);

        self::assertFalse($dto->isEmpty());
        self::assertSame(650, $dto->getTotal());
        self::assertSame(['reject', 'no action', 'add header'], $dto->getLabels());
        self::assertSame([100, 500, 50], $dto->getValues());
    }

    public function testEmpty(): void
    {
        $dto = ActionDistributionDto::empty();

        self::assertTrue($dto->isEmpty());
        self::assertSame(0, $dto->getTotal());
        self::assertSame([], $dto->getLabels());
        self::assertSame([], $dto->getValues());
    }

    public function testColors(): void
    {
        $dto = new ActionDistributionDto(
            [
                'Clean' => 100,
                'Rejected' => 10,
            ],
            [
                'Clean' => '#66cc00',
                'Rejected' => '#FF0000',
            ]
        );

        self::assertSame('#66cc00', $dto->getColor('Clean'));
        self::assertSame('#FF0000', $dto->getColor('Rejected'));
        self::assertNull($dto->getColor('NonExistent'));
        self::assertSame(['#66cc00', '#FF0000'], $dto->getColors());
    }
}
