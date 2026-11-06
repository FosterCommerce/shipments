<?php

declare(strict_types=1);

namespace fostercommerce\shipments\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\enums\Status;
use fostercommerce\shipments\Plugin;
use Throwable;

/**
 * Sets the selected shipments to one status.
 */
class SetShipmentStatus extends ElementAction
{
	public ?string $status = null;

	public function getTriggerLabel(): string
	{
		return Craft::t(Plugin::HANDLE, 'action.setStatus');
	}

	public function getTriggerHtml(): ?string
	{
		$options = [
			[
				'label' => '',
				'value' => '',
			],
			...array_map(static fn (Status $status): array => [
				'label' => $status->label(),
				'value' => $status->value,
			], Status::cases()),
		];

		Craft::$app->getView()->registerJsWithVars(static fn (string $type, string $options, string $heading, string $apply, string $chooseStatus): string => <<<JS
(() => {
	new Craft.ElementActionTrigger({
		type: {$type},
		activate: () => {
			const field = Craft.ui.createSelectField({label: {$heading}, name: 'status', options: {$options}});
			const body = $('<div class="body"/>').append(field);
			const form = $('<form class="modal fitted" method="post" accept-charset="UTF-8"/>').append(body);
			const buttons = $('<div class="buttons right"/>').appendTo(body);
			$('<button type="submit" class="btn submit"/>').text({$apply}).appendTo(buttons);
			const modal = new Garnish.Modal(form);
			form.on('submit', (event) => {
				event.preventDefault();
				const status = field.find('select').val();
				if (!status) {
					Craft.cp.displayError({$chooseStatus});
					return;
				}

				modal.hide();
				Craft.elementIndex.submitAction({$type}, {status});
			});
		},
	});
})();
JS, [static::class, $options, Craft::t(Plugin::HANDLE, 'action.setStatus'), Craft::t('app', 'Apply'),
			Craft::t(Plugin::HANDLE, 'action.setStatusChoose'),
		]);

		return null;
	}

	public function performAction(ElementQueryInterface $query): bool
	{
		$status = Status::from((string) $this->status);
		$plugin = Plugin::getInstance();
		$user = Craft::$app->getUser()->getIdentity();
		$failed = 0;
		/** @var list<Shipment> $shipments */
		$shipments = $query->all();

		foreach ($shipments as $shipment) {
			if ($shipment->getStatusEnum() === $status) {
				continue;
			}

			// Reject a canceled shipment, since reopening it counts its units as allocated again
			if ($shipment->getStatusEnum() === Status::Cancelled) {
				$failed++;
				continue;
			}

			try {
				$plugin->shipments->applyTransition($shipment, $status, $user);
			} catch (Throwable $throwable) {
				$failed++;
				Craft::error("Could not set shipment {$shipment->id} to {$status->value}: " . $throwable->getMessage(), Plugin::HANDLE);
			}
		}

		$this->setMessage($failed === 0
			? Craft::t(Plugin::HANDLE, 'action.statusUpdated')
			: Craft::t(Plugin::HANDLE, 'action.statusPartiallyUpdated', [
				'count' => $failed,
			]));

		return $failed < count($shipments);
	}

	/**
	 * @return array<array-key, mixed>
	 */
	protected function defineRules(): array
	{
		$rules = parent::defineRules();
		$rules[] = [['status'], 'required'];
		$rules[] = [['status'],
			'in',
			'range' => array_column(Status::cases(), 'value')];

		return $rules;
	}
}
