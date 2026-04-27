<?php

declare(strict_types=1);

namespace fostercommerce\shipments\base;

/**
 * HMAC helpers for webhook signature verification. Constant-time compare via
 * `hash_equals` to avoid timing attacks. Provider classes `use` this trait.
 */
trait WebhookSigning
{
	/**
	 * @param string $body      raw request body
	 * @param string $signature signature from the request header (hex-encoded)
	 * @param string $secret    shared secret for this integration
	 * @param string $algorithm hash_hmac algorithm (default sha256)
	 */
	protected function verifyHmacSignature(string $body, string $signature, string $secret, string $algorithm = 'sha256'): bool
	{
		if ($signature === '' || $secret === '') {
			return false;
		}

		$expected = hash_hmac($algorithm, $body, $secret);
		return hash_equals($expected, $signature);
	}

	/**
	 * @param string $body      raw request body
	 * @param string $signature base64-encoded signature (e.g. Shopify-style)
	 * @param string $secret    shared secret
	 * @param string $algorithm hash_hmac algorithm (default sha256)
	 */
	protected function verifyHmacSignatureBase64(string $body, string $signature, string $secret, string $algorithm = 'sha256'): bool
	{
		if ($signature === '' || $secret === '') {
			return false;
		}

		$expected = base64_encode(hash_hmac($algorithm, $body, $secret, true));
		return hash_equals($expected, $signature);
	}
}
