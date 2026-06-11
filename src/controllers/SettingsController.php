<?php

declare(strict_types=1);

namespace fostercommerce\shipments\controllers;

use Craft;
use craft\commerce\models\ShippingCategory;
use craft\commerce\Plugin as Commerce;
use craft\web\Controller;
use fostercommerce\shipments\Plugin;
use fostercommerce\shipments\rules\LineItemStatusRule;
use fostercommerce\shipments\rules\ShippingCategoryRule;
use fostercommerce\shipments\web\assets\cp\ShipmentsCpAsset;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Renders the Shipments plugin's general settings page and handles saves. Mirrors
 * `craft\commerce\controllers\SettingsController`; we use a custom CP route instead
 * of Craft's default `plugins/save-plugin-settings` flow so the form isn't subject to
 * the delta-tracking wrapper in `_layouts/cp` (which silently strips our POST body).
 */
class SettingsController extends Controller
{
	public function actionEdit(): Response
	{
		$this->requirePermission(Plugin::PERMISSION_MANAGE_SETTINGS);

		return $this->renderSettings();
	}

	/**
	 * @throws BadRequestHttpException
	 * @throws ForbiddenHttpException
	 */
	public function actionSaveSettings(): ?Response
	{
		$this->requirePostRequest();
		$this->requirePermission(Plugin::PERMISSION_MANAGE_SETTINGS);

		// Settings persist to project config, which is read-only when admin changes are
		// disabled; without this guard the save surfaces a raw NotSupportedException 500.
		if (! Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
			throw new ForbiddenHttpException(Craft::t(Plugin::HANDLE, 'error.adminChangesDisallowed'));
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		$settings = $this->request->getBodyParam('settings', []);
		if (! is_array($settings)) {
			$settings = [];
		}

		$previouslyIgnoredOrderStatuses = $plugin->getSettings()->orderStatusesToIgnore;
		$newlyIgnoredStatusHandles = $this->resolveNewlyIgnoredOrderStatuses($settings, $previouslyIgnoredOrderStatuses);

		// Validate the incoming payload against the settings model before mutating it.
		// If it's invalid, render errors without altering anything so an admin's existing
		// per-source config isn't wiped from the model during the error re-render.
		$plugin->getSettings()->setAttributes($settings, true);
		if (! $plugin->getSettings()->validate()) {
			Craft::$app->getSession()->setError(Craft::t(Plugin::HANDLE, 'error.couldNotSaveSettings'));
			return $this->renderSettings();
		}

		// Payload is valid. Zero out per-source group configs that don't match the active
		// `groupingSource` so stale config doesn't linger after a source switch.
		$activeSource = $plugin->getSettings()->groupingSource;
		if ($activeSource !== LineItemStatusRule::HANDLE) {
			$settings['lineItemStatusGroups'] = [];
		}

		if ($activeSource !== ShippingCategoryRule::HANDLE) {
			$settings['shippingCategoryGroups'] = [];
		}

		if (! Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
			Craft::$app->getSession()->setError(Craft::t(Plugin::HANDLE, 'error.couldNotSaveSettings'));
			return $this->renderSettings();
		}

		// Settings that influence the Attention-needed query (ignoredLineItemStatuses, grouping
		// source, groups) can change the badge count, so drop the cached value.
		$plugin->shipmentLineItems->invalidateAttentionCount();

		if ($newlyIgnoredStatusHandles !== []) {
			$sweepResult = $plugin->trackedOrders->sweepForNewlyIgnoredStatuses($newlyIgnoredStatusHandles);
			if ($sweepResult['ordersAffected'] > 0) {
				Craft::$app->getSession()->setNotice(Craft::t(
					Plugin::HANDLE,
					'settings.savedWithSweep',
					[
						'orders' => $sweepResult['ordersAffected'],
						'shipments' => $sweepResult['shipmentsTrashed'],
					],
				));
				return $this->redirectToPostedUrl();
			}
		}

		Craft::$app->getSession()->setNotice(Craft::t(Plugin::HANDLE, 'settings.saved'));
		return $this->redirectToPostedUrl();
	}

	/**
	 * Diff of `orderStatusesToIgnore` between the incoming settings payload and the previous
	 * value on the settings model. Returns only the handles that are new to the list; used
	 * to scope the retroactive sweep so re-saving settings with no changes triggers nothing.
	 *
	 * @param array<string, mixed> $incomingSettings
	 * @param list<string> $previouslyIgnored
	 * @return list<string>
	 */
	private function resolveNewlyIgnoredOrderStatuses(array $incomingSettings, array $previouslyIgnored): array
	{
		$incomingRaw = $incomingSettings['orderStatusesToIgnore'] ?? [];
		if (! is_array($incomingRaw)) {
			return [];
		}

		$incoming = [];
		foreach ($incomingRaw as $handleRaw) {
			if (is_string($handleRaw) && $handleRaw !== '') {
				$incoming[] = $handleRaw;
			}
		}

		return array_values(array_diff($incoming, $previouslyIgnored));
	}

	/**
	 * Builds the render-variables payload for the settings page. Used by both the GET handler
	 * and the POST failure path so validation errors stay visible on the re-rendered page.
	 */
	private function renderSettings(): Response
	{
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$builtInRuleNamespacePrefix = 'fostercommerce\\shipments\\rules\\';
		$availableRules = [];
		foreach ($plugin->rules->allRules() as $rule) {
			$isCustom = ! str_starts_with($rule::class, $builtInRuleNamespacePrefix);
			$name = $rule->getName();
			$availableRules[] = [
				'handle' => $rule->getHandle(),
				'name' => $isCustom
					? Craft::t(Plugin::HANDLE, 'emails.recipientType.customWithName', [
						'name' => $name,
					])
					: $name,
				'description' => $rule->getDescription(),
			];
		}

		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();

		$lineItemStatusOptions = [];
		$lineItemStatuses = [];
		foreach ($commerce->getLineItemStatuses()->getAllLineItemStatuses() as $lineItemStatus) {
			if ($lineItemStatus->handle === null) {
				continue;
			}

			$lineItemStatusOptions[] = [
				'value' => $lineItemStatus->handle,
				'label' => ($lineItemStatus->name ?? $lineItemStatus->handle) . ' (' . $lineItemStatus->handle . ')',
			];

			$lineItemStatuses[] = [
				'handle' => $lineItemStatus->handle,
				'name' => $lineItemStatus->name ?? $lineItemStatus->handle,
			];
		}

		$orderStatusOptions = [];
		foreach ($commerce->getOrderStatuses()->getAllOrderStatuses() as $orderStatus) {
			if ($orderStatus->handle === null) {
				continue;
			}

			$orderStatusOptions[] = [
				'value' => $orderStatus->handle,
				'label' => ($orderStatus->name ?? $orderStatus->handle) . ' (' . $orderStatus->handle . ')',
			];
		}

		$shippingCategoryOptions = [];
		foreach ($commerce->getShippingCategories()->getAllShippingCategories() as $shippingCategory) {
			if (! $shippingCategory instanceof ShippingCategory) {
				continue;
			}

			if ($shippingCategory->handle === null) {
				continue;
			}

			$shippingCategoryOptions[] = [
				'value' => $shippingCategory->handle,
				'label' => ($shippingCategory->name ?? $shippingCategory->handle) . ' (' . $shippingCategory->handle . ')',
			];
		}

		Craft::$app->getView()->registerAssetBundle(ShipmentsCpAsset::class);

		return $this->renderTemplate(Plugin::HANDLE . '/settings/general/index', [
			'settings' => $plugin->getSettings(),
			'availableRules' => $availableRules,
			'lineItemStatusOptions' => $lineItemStatusOptions,
			'lineItemStatuses' => $lineItemStatuses,
			'orderStatusOptions' => $orderStatusOptions,
			'shippingCategoryOptions' => $shippingCategoryOptions,
		]);
	}
}
