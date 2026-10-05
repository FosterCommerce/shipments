<?php

declare(strict_types=1);

namespace fostercommerce\shipments\helpers;

use craft\helpers\Json;
use craft\helpers\MoneyHelper;
use InvalidArgumentException;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;
use Money\Parser\DecimalMoneyParser;

final class MoneyValues
{
	/**
	 * Parse an exact carrier decimal, rounding excess fractional digits half up.
	 *
	 * @throws InvalidArgumentException
	 */
	public static function fromDecimal(string $amount, string $currency): Money
	{
		if ($amount === '' || preg_match('/^-?\d+(?:\.\d+)?$/D', $amount) !== 1) {
			throw new InvalidArgumentException('A decimal money amount is required.');
		}

		return (new DecimalMoneyParser(new ISOCurrencies()))->parse($amount, self::currency($currency));
	}

	/**
	 * Normalize an existing Commerce float at the integration boundary.
	 *
	 * @throws InvalidArgumentException
	 */
	public static function fromCommerce(float $amount, string $currency): Money
	{
		if (! is_finite($amount)) {
			throw new InvalidArgumentException('The shipping rate is not a finite amount.');
		}

		/** @var Money $money */
		$money = MoneyHelper::toMoney([
			'value' => (string) $amount,
			'currency' => self::currency($currency),
		]);
		return $money;
	}

	/**
	 * Format an exact decimal string for an external API, not for display.
	 */
	public static function decimal(Money $money): string
	{
		return (new DecimalMoneyFormatter(new ISOCurrencies()))->format($money);
	}

	/**
	 * Serialize exact minor units and the ISO currency.
	 *
	 * @return array{amount: string, currency: string}
	 */
	public static function serialize(Money $money): array
	{
		return [
			'amount' => $money->getAmount(),
			'currency' => $money->getCurrency()->getCode(),
		];
	}

	/**
	 * Restore exact minor units and validate their ISO currency.
	 *
	 * @param array<array-key, mixed> $value
	 * @throws InvalidArgumentException
	 */
	public static function restore(array $value): Money
	{
		$amount = $value['amount'] ?? null;
		$currency = $value['currency'] ?? null;
		if (! is_string($amount) || ! is_numeric($amount) || preg_match('/^-?\d+$/D', $amount) !== 1 || ! is_string($currency)) {
			throw new InvalidArgumentException('Invalid stored money value.');
		}

		return new Money($amount, self::currency($currency));
	}

	/**
	 * Preserve numeric JSON tokens as strings before decoding carrier data.
	 *
	 * @return array<string, mixed>
	 * @throws InvalidArgumentException
	 */
	public static function decodeJson(string $json): array
	{
		$preserved = preg_replace_callback(
			'/"(?:[^"\\\\]|\\\\.)*"|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/s',
			static fn (array $match): string => str_starts_with($match[0], '"') ? $match[0] : '"' . $match[0] . '"',
			$json,
		);
		$decoded = Json::decode($preserved ?? $json);
		if (! is_array($decoded)) {
			throw new InvalidArgumentException('The carrier response must be a JSON object.');
		}

		/** @var array<string, mixed> $decoded */
		return $decoded;
	}

	private static function currency(string $code): Currency
	{
		$code = strtoupper($code);
		if ($code === '' || preg_match('/^[A-Z]{3}$/D', $code) !== 1) {
			throw new InvalidArgumentException('A three-letter ISO currency is required.');
		}

		$currency = new Currency($code);
		if (! (new ISOCurrencies())->contains($currency)) {
			throw new InvalidArgumentException('An ISO currency is required.');
		}

		return $currency;
	}
}
