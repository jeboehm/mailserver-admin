<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Mailbox;

use App\Service\Mailbox\TlsImapConnectionFactory;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionException;
use PHPUnit\Framework\TestCase;

/**
 * Real sockets, in a process of their own: what matters here is what happens on the wire, and a TLS
 * handshake cannot be answered from the process that is waiting for it.
 */
class TlsImapConnectionFactoryTest extends TestCase
{
    /**
     * Greets and then keeps the connection open without saying anything else.
     */
    private const string SERVER = <<<'PHP'
        $server = stream_socket_server($argv[1] . '://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create(['ssl' => [
            'local_cert' => $argv[2],
            'allow_self_signed' => true,
            'verify_peer' => false,
        ]]));
        echo stream_socket_get_name($server, false), "\n";
        $client = @stream_socket_accept($server, 10);
        if (false !== $client) {
            fwrite($client, "* OK ready\r\n");
            sleep(10);
        }
        PHP;

    /** @var resource|null */
    private $process;
    private ?string $certificate = null;

    #[\Override]
    protected function tearDown(): void
    {
        if (\is_resource($this->process)) {
            proc_terminate($this->process, 9);
            proc_close($this->process);
        }

        if (null !== $this->certificate) {
            unlink($this->certificate);
        }
    }

    public function testConnectsWithImplicitTlsToAnUnverifiableCertificate(): void
    {
        $connection = new TlsImapConnectionFactory($this->startServer('tls'), 5)->connect();

        self::assertTrue($connection->connected());
    }

    public function testRefusesAServerWithoutTls(): void
    {
        $this->expectException(ImapConnectionException::class);

        new TlsImapConnectionFactory($this->startServer('tcp'), 1)->connect();
    }

    private function startServer(string $transport): string
    {
        $this->certificate = $this->selfSignedCertificate();

        $process = proc_open(
            [\PHP_BINARY, '-d', 'xdebug.mode=off', '-r', self::SERVER, '--', $transport, $this->certificate],
            [1 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $this->process = $process;

        $address = fgets($pipes[1]);
        self::assertIsString($address, 'The server did not start.');

        return trim($address);
    }

    private function selfSignedCertificate(): string
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);

        $request = openssl_csr_new(['commonName' => 'mail.example.com'], $key);
        self::assertNotFalse($request);
        self::assertInstanceOf(\OpenSSLCertificateSigningRequest::class, $request);
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);

        $certificate = openssl_csr_sign($request, null, $key, 1);
        self::assertNotFalse($certificate);

        openssl_x509_export($certificate, $pem);
        openssl_pkey_export($key, $privateKey);
        self::assertIsString($pem);
        self::assertIsString($privateKey);

        $path = tempnam(sys_get_temp_dir(), 'imap-test-');
        self::assertIsString($path);
        file_put_contents($path, $pem . $privateKey);

        return $path;
    }
}
