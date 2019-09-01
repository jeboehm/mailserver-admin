<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Service\DKIM;

use App\Service\DKIM\FormatterService;
use PHPUnit\Framework\TestCase;

class FormatterServiceTest extends TestCase
{
    /**
     * @var FormatterService
     */
    private $instance;

    protected function setUp(): void
    {
        $this->instance = new FormatterService();
    }

    /**
     * @dataProvider dataProviderForTestGetTXTRecord
     */
    public function testGetTXTRecord(string $expect, string $publicKey, string $algorithm): void
    {
        $this->assertEquals($expect, $this->instance->getTXTRecord($publicKey, $algorithm));
    }

    public function dataProviderForTestGetTXTRecord(): array
    {
        return [
            [
                'v=DKIM1\; h=sha256\; t=s\; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAquTL5cOnOaQ5WBU//UZ20di90Sdy39jZq2exSH0F7K1czItBL8nU0zalto5ZLk1zZKUNYLD5ys1CkoMFzvKsudTlIDnkHhXZqZCmGsExFbTAacUvmeOpGZQaESo9dk+0opwJKUUxn6nJKlWhSnPGQqMQsFey4iJ0Gyc/h+SpNzjjiLBnoqmKWu0G7tpqOZ1wjOxAlBij5ZSnEHchsmeodYMi+YTuHCDMKH9wruTdmUMSeakPpP42HoZPoxK+rRUBJ98WmlF4cQ2T0OhqLe6hACEANUxe2Clt0LK+iwI9TVF/v3r8Z3K9OYhKjGqSmHIO3j0WO0vHnGs+/aFnqRpKJQIDAQAB',
                '-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAquTL5cOnOaQ5WBU//UZ2
0di90Sdy39jZq2exSH0F7K1czItBL8nU0zalto5ZLk1zZKUNYLD5ys1CkoMFzvKs
udTlIDnkHhXZqZCmGsExFbTAacUvmeOpGZQaESo9dk+0opwJKUUxn6nJKlWhSnPG
QqMQsFey4iJ0Gyc/h+SpNzjjiLBnoqmKWu0G7tpqOZ1wjOxAlBij5ZSnEHchsmeo
dYMi+YTuHCDMKH9wruTdmUMSeakPpP42HoZPoxK+rRUBJ98WmlF4cQ2T0OhqLe6h
ACEANUxe2Clt0LK+iwI9TVF/v3r8Z3K9OYhKjGqSmHIO3j0WO0vHnGs+/aFnqRpK
JQIDAQAB
-----END PUBLIC KEY-----
',
                'sha256',
            ],
        ];
    }
}
