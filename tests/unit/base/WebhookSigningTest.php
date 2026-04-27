<?php

declare(strict_types=1);

namespace fostercommerce\shipments\tests\unit\base;

use fostercommerce\shipments\base\WebhookSigning;
use PHPUnit\Framework\TestCase;

final class WebhookSigningTest extends TestCase
{
	public function testHexSignatureAccepts(): void
	{
		$subject = $this->subject();
		$body = '{"event":"shipped"}';
		$secret = 'test-secret';
		$signature = hash_hmac('sha256', $body, $secret);

		self::assertTrue($subject->verify($body, $signature, $secret));
	}

	public function testHexSignatureRejectsTampered(): void
	{
		$subject = $this->subject();
		$body = '{"event":"shipped"}';
		$secret = 'test-secret';
		$signature = hash_hmac('sha256', $body, $secret);
		$tampered = $signature . '00';

		self::assertFalse($subject->verify($body, $tampered, $secret));
	}

	public function testHexSignatureRejectsEmpty(): void
	{
		$subject = $this->subject();
		self::assertFalse($subject->verify('body', '', 'secret'));
		self::assertFalse($subject->verify('body', 'sig', ''));
	}

	public function testBase64SignatureAccepts(): void
	{
		$subject = $this->subject();
		$body = '{"event":"shipped"}';
		$secret = 'test-secret';
		$signature = base64_encode(hash_hmac('sha256', $body, $secret, true));

		self::assertTrue($subject->verifyBase64($body, $signature, $secret));
	}

	public function testBase64SignatureRejectsHexInput(): void
	{
		$subject = $this->subject();
		$body = '{"event":"shipped"}';
		$secret = 'test-secret';
		$hexSignature = hash_hmac('sha256', $body, $secret);

		self::assertFalse($subject->verifyBase64($body, $hexSignature, $secret));
	}

	private function subject(): object
	{
		return new class () {
			use WebhookSigning;

			public function verify(string $body, string $signature, string $secret, string $algorithm = 'sha256'): bool
			{
				return $this->verifyHmacSignature($body, $signature, $secret, $algorithm);
			}

			public function verifyBase64(string $body, string $signature, string $secret, string $algorithm = 'sha256'): bool
			{
				return $this->verifyHmacSignatureBase64($body, $signature, $secret, $algorithm);
			}
		};
	}
}
