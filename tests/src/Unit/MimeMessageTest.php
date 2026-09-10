<?php

namespace Drupal\Tests\o365_smtp\Unit;

use Drupal\Component\Utility\EmailValidator;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\o365_smtp\Service\O365Client;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the MIME message built by the Office 365 client.
 *
 * @group o365_smtp
 */
#[Group('o365_smtp')]
class MimeMessageTest extends UnitTestCase {

  /**
   * Directory standing for public://.
   *
   * @var string
   */
  protected string $publicDirectory;

  /**
   * The client under test.
   *
   * @var \Drupal\o365_smtp\Service\O365Client
   */
  protected O365Client $client;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->publicDirectory = sys_get_temp_dir() . '/o365_smtp_test_' . bin2hex(random_bytes(4));
    mkdir($this->publicDirectory);
    file_put_contents($this->publicDirectory . '/report.pdf', '%PDF-1.4 test');

    $file_system = $this->createMock(FileSystemInterface::class);
    $file_system->method('realpath')->willReturnMap([
      ['public://', $this->publicDirectory],
      ['public://report.pdf', $this->publicDirectory . '/report.pdf'],
      // Where a traversal resolves: outside the public directory.
      ['public://../outside.txt', dirname($this->publicDirectory) . '/outside.txt'],
    ]);

    $this->client = new O365Client(
      $this->getConfigFactoryStub([
        'o365_smtp.settings' => [
          'from_name' => 'Example Site',
          'max_attachment_size' => 1,
        ],
      ]),
      $this->createMock(ClientInterface::class),
      $this->createMock(StateInterface::class),
      $this->createMock(LoggerInterface::class),
      $file_system,
      new EmailValidator(),
      new RequestStack(),
      $this->createMock(LockBackendInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    array_map('unlink', glob($this->publicDirectory . '/*'));
    rmdir($this->publicDirectory);
    parent::tearDown();
  }

  /**
   * Tests that a non-ASCII subject is encoded.
   */
  public function testEncodedSubject(): void {
    $message = $this->client->buildMimeMessage('box@example.com', 'jane@example.com', 'Réservation confirmée', '<p>Bonjour</p>');
    $headers = $this->getHeaders($message);

    $this->assertStringContainsString('Subject: =?utf-8?Q?R=C3=A9servation_confirm=C3=A9e?=', $headers);
    $this->assertStringContainsString('From: Example Site <box@example.com>', $headers);
    $this->assertMatchesRegularExpression('/^[\x00-\x7F]*$/', $headers, 'Headers are pure ASCII.');
  }

  /**
   * Tests that a long HTML body is encoded within the SMTP line length limit.
   */
  public function testLongBody(): void {
    $body = '<p>' . str_repeat('Lorem ipsum dolor sit amet, é ', 400) . '</p>';
    $message = $this->client->buildMimeMessage('box@example.com', 'jane@example.com', 'Subject', $body);

    $this->assertStringContainsString("Content-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable", $message);
    foreach (explode("\r\n", $message) as $line) {
      $this->assertLessThanOrEqual(998, strlen($line));
    }
    $encoded_body = substr($message, strpos($message, "\r\n\r\n") + 4);
    $this->assertSame($body, rtrim(quoted_printable_decode($encoded_body), "\r\n"));
  }

  /**
   * Tests plain text messages and recipient headers.
   */
  public function testPlainTextAndRecipients(): void {
    $message = $this->client->buildMimeMessage('box@example.com', '"Doe, John" <john@example.com>, jane@example.com', 'Subject', "Line 1\nLine 2", [], [
      'Content-Type' => 'text/plain; charset=UTF-8; format=flowed; delsp=yes',
      'Cc' => 'cc@example.com',
      'Bcc' => 'hidden@example.com',
      'Reply-To' => 'reply@example.com',
    ]);
    $headers = $this->getHeaders($message);

    $this->assertStringContainsString('To: "Doe, John" <john@example.com>, jane@example.com', $headers);
    $this->assertStringContainsString('Cc: cc@example.com', $headers);
    $this->assertStringContainsString('Reply-To: reply@example.com', $headers);
    $this->assertStringContainsString('Content-Type: text/plain; charset=utf-8', $headers);
    $this->assertStringNotContainsString('hidden@example.com', $message, 'Bcc recipients are not written in the message.');
  }

  /**
   * Tests attachments from a stream wrapper URI and from memory.
   */
  public function testAttachments(): void {
    $message = $this->client->buildMimeMessage('box@example.com', 'jane@example.com', 'Subject', '<p>Body</p>', [
      ['filepath' => 'public://report.pdf', 'filemime' => 'application/pdf'],
      ['filecontent' => 'a,b', 'filename' => 'export.csv', 'filemime' => 'text/csv'],
    ]);

    $this->assertStringContainsString('Content-Type: multipart/mixed; boundary=', $message);
    $this->assertStringContainsString('filename=report.pdf', $message);
    $this->assertStringContainsString(base64_encode('%PDF-1.4 test'), $message);
    $this->assertStringContainsString('filename=export.csv', $message);
    $this->assertStringContainsString(base64_encode('a,b'), $message);
  }

  /**
   * Tests that unsafe or oversized attachments are refused.
   */
  #[DataProvider('refusedAttachmentProvider')]
  public function testRefusedAttachment(array $attachment): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->client->buildMimeMessage('box@example.com', 'jane@example.com', 'Subject', 'Body', [$attachment]);
  }

  /**
   * Data provider for ::testRefusedAttachment().
   */
  public static function refusedAttachmentProvider(): array {
    return [
      'absolute path' => [['filepath' => '/etc/passwd']],
      'unsupported stream wrapper' => [['filepath' => 'https://example.com/file.pdf']],
      'path traversal' => [['filepath' => 'public://../outside.txt']],
      'missing file' => [['filepath' => 'public://missing.pdf']],
      'too large' => [['filecontent' => str_repeat('a', 1024 * 1024 + 1), 'filename' => 'big.bin']],
    ];
  }

  /**
   * Tests that CR/LF in header values cannot inject headers.
   */
  public function testHeaderInjectionIsNeutralized(): void {
    $message = $this->client->buildMimeMessage('box@example.com', 'jane@example.com', "Hello\r\nBcc: evil@example.com", 'Body', [
      [
        'filecontent' => 'x',
        'filename' => "notes.txt\r\nX-Injected: 1",
        'filemime' => "text/plain\r\nX-Injected: 1",
      ],
    ]);

    $this->assertDoesNotMatchRegularExpression('/^(Bcc|X-Injected):/mi', $message);
  }

  /**
   * Tests that CR/LF in a recipient makes the address invalid.
   */
  public function testInjectedRecipientIsRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->client->buildMimeMessage('box@example.com', "jane@example.com\r\nBcc: evil@example.com", 'Subject', 'Body');
  }

  /**
   * Returns the header section of a message.
   *
   * @param string $message
   *   The MIME message.
   *
   * @return string
   *   The headers.
   */
  protected function getHeaders(string $message): string {
    return strstr($message, "\r\n\r\n", TRUE);
  }

}
