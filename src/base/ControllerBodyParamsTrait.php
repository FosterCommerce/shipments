<?php

declare(strict_types=1);

namespace fostercommerce\shipments\base;

/**
 * Small trait shared by the plugin's CP controllers for narrowing POST body params to the
 * shapes services expect. Every public save/edit entry point coerces raw mixed input here
 * before handing it to a service, so the services can assume typed data.
 *
 * Not a substitute for Craft's `Typecast` (which is model-shaped); this is request-shaped.
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
	 * Normalizes an "ids" param (typically posted by reorder JS) to a list of ints. Anything
	 * non-numeric is dropped silently; the caller asked for ids and that's all this returns.
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
