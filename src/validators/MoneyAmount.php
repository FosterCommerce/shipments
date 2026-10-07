<?php

declare(strict_types=1);

namespace fostercommerce\shipments\validators;

use Craft;
use fostercommerce\shipments\helpers\MoneyValues;
use fostercommerce\shipments\Plugin;
use InvalidArgumentException;
use yii\validators\Validator;

/**
 * Validate a nullable pair of exact minor units and an ISO currency.
 */
class MoneyAmount extends Validator
{
	public string $currencyAttribute;

	public $skipOnEmpty = false;

	public function validateAttribute($model, $attribute): void
	{
		$amount = $model->{$attribute};
		$currency = $model->{$this->currencyAttribute};
		if ($amount === null && $currency === null) {
			return;
		}

		if (! is_string($amount) || preg_match('/^\d+$/D', $amount) !== 1 || ! is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
			$this->addError($model, $attribute, Craft::t(Plugin::HANDLE, 'delivery.validation.moneyPair'));
			return;
		}

		try {
			MoneyValues::restore([
				'amount' => $amount,
				'currency' => $currency,
			]);
		} catch (InvalidArgumentException) {
			$this->addError($model, $this->currencyAttribute, Craft::t(Plugin::HANDLE, 'delivery.validation.currency'));
		}
	}
}
