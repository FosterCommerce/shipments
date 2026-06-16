<?php

declare(strict_types=1);

namespace fostercommerce\shipments\base;

/**
 * Narrows POST body params to the shapes services expect.
 *
 * Request-shaped, not a substitute for Craft's model-shaped `Typecast`.
 */
trait ControllerBodyParamsTrait
{
	/**
	 * Reads a body param, scalar-narrows it to a string, trims it, and returns `null` for an
	 * empty string. Non-scalar values (arrays, objects) resolve to `null`.
	 */
	private function bodyString(string $name): ?string
	{
		$value = $this->request->getBodyParam($name);
		if (! is_scalar($value)) {
			return null;
		}

		$stringValue = trim((string) $value);
		return $stringValue === '' ? null : $stringValue;
	}

	/**
	 * Normalizes an "ids" param to a list of ints, dropping non-numeric entries.
	 *
	 * @return list<int>
	 */
	private function normalizeIntList(mixed $value): array
	{
		if (! is_array($value)) {
			return [];
		}

		$ids = [];
		foreach ($value as $item) {
			if (is_numeric($item)) {
				$ids[] = (int) $item;
			}
		}

		return $ids;
	}
}
