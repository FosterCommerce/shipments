<?php

declare(strict_types=1);

namespace fostercommerce\shipments\models;

use verbb\shippy\models\PackageBox as ShippyPackageBox;

class PackageBox extends ShippyPackageBox
{
	// Carrier dimensions retain precision lost by the packer's integer scaling.
	public float $lengthInches;

	public float $widthInches;

	public float $heightInches;
}
