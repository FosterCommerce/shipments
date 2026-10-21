/* Shipments plugin: CP JS. Initializes these features:
 *   1. Remove-shipment button on each shipment card in the order-edit tab.
 *   2. Staging-group creation flow (add/remove groups, live remaining recompute, save-gating).
 *   3. Integration-references repeater on the shipment edit page.
 *   4. Provider-panel visibility on the integration edit page.
 *
 * Each feature looks for its own root element; if absent, that feature is skipped. This means
 * the same bundle can register for any page and self-select which features to wire.
 */
(function ($) {
	'use strict';

	// Mark Commerce's outer `<form id="main-form">` as clean. Our staging-qty inputs and the
	// requires-shipping hidden input live inside it, so changing them dirties Craft's
	// `data-confirm-unload` snapshot and triggers the browser's leave-page dialog. After our
	// AJAX writes succeed (or before we reload), the form genuinely matches the server again,
	// so re-snapshot the serialized value.
	function markMainFormClean() {
		const $mainForm = $('#main-form');
		if ($mainForm.length) {
			$mainForm.data('initialSerializedValue', $mainForm.serialize());
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		initRemoveShipmentButtons();
		initStagingGroups();
		initLineItemsEditor();
		initPushIntegrationButtons();
		initIntegrationReferencesRepeater();
		initIntegrationProviderPanels();
		initCarrierMappings();
		initShipDateAutosave();
		initDeliveryMethod();
		initCreateDeliveryButton();
		initCopyDeliveryResponse();
		initVoidDeliveryConfirmation();
		initSettingsAddRuleButtons();
	});

	function initShipDateAutosave() {
		const $field = $('[data-shipments-ship-date]');
		if (!$field.length) {
			return;
		}

		const $date = $field.find('input[name="dateScheduledShip[date]"]');
		const $spinner = $field.find('.shipment-ship-date-spinner');
		const $mainForm = $('#main-form');
		let savedDate = $date.val();
		$date.on('change', async function () {
			const dateInput = {};
			$field.find('input').serializeArray().forEach(({name, value}) => {
				dateInput[name.match(/\[([^\]]+)\]$/)[1]] = value;
			});
			const $buttons = $('#main-form, #delivery').find('button[type="submit"], input[type="submit"]').add($field.find('button')).filter(':enabled');
			$buttons.prop('disabled', true);
			$date.prop('disabled', true);
			if ($date.data('datepicker')) {
				$date.datepicker('disable');
			}
			$field.attr('aria-busy', 'true');
			$spinner.removeClass('invisible');

			try {
				const response = await Craft.sendActionRequest('POST', 'shipments/shipments/save-ship-date', {
					data: {id: $field.data('shipments-ship-date'), dateScheduledShip: dateInput},
				});
				savedDate = $date.val();
				// Only the saved date changes the baseline; other form edits stay unsaved.
				const initial = new URLSearchParams($mainForm.data('initialSerializedValue'));
				initial.set($date[0].name, savedDate);
				$mainForm.data('initialSerializedValue', $.param(Array.from(initial, ([name, value]) => ({name, value}))));
				$('#delivery').html(response.data.deliveryHtml);
				initDeliveryMethod();
				initCopyDeliveryResponse();
			} catch (error) {
				if ($date.data('datepicker')) {
					$date.datepicker('setDate', savedDate);
				}
				$date.val(savedDate);
				Craft.cp.displayError(error?.response?.data?.message || Craft.t('shipments', 'shipmentEdit.couldNotSaveShipDate'));
			} finally {
				$date.prop('disabled', false);
				if ($date.data('datepicker')) {
					$date.datepicker('enable');
				}
				$buttons.prop('disabled', false);
				$field.removeAttr('aria-busy');
				$spinner.addClass('invisible');
			}
		});
	}

	function initVoidDeliveryConfirmation() {
		document.querySelectorAll('form[data-void-confirm]').forEach(form => {
			form.addEventListener('submit', event => {
				if (!window.confirm(form.dataset.voidConfirm)) {
					event.preventDefault();
				}
			});
		});
	}

	function initCopyDeliveryResponse() {
		document.querySelectorAll('.shipment-copy-response').forEach(link => {
			link.addEventListener('click', async event => {
				event.preventDefault();
				try {
					await navigator.clipboard.writeText(link.dataset.response);
					Craft.cp.displayNotice(Craft.t('shipments', 'delivery.responseCopied'));
				} catch (error) {
					Craft.cp.displayError(Craft.t('shipments', 'delivery.couldNotCopyResponse'));
				}
			});
		});
	}

	function initDeliveryMethod() {
		const form = document.getElementById('shipment-delivery-select-method');
		if (!form) {
			return;
		}
		const radios = Array.from(document.querySelectorAll('input[form="shipment-delivery-select-method"][name="method"]'));
		const status = document.getElementById('shipment-method-status');
		const prepared = document.getElementById('shipment-prepared-details');
		let saved = radios.find(radio => radio.checked)?.value;
		radios.forEach(radio => radio.addEventListener('change', async function () {
			const controls = Array.from(document.querySelectorAll('#delivery input, #delivery button, #main-form button[type="submit"]'));
			const enabled = controls.filter(control => !control.disabled);
			enabled.forEach(control => { control.disabled = true; });
			status.classList.remove('hidden');
			prepared.setAttribute('aria-busy', 'true');
			prepared.style.opacity = '0.5';
			try {
				const response = await Craft.sendActionRequest('POST', 'shipments/deliveries/select-method', {
					data: {id: form.elements.id.value, method: radio.value},
				});
				prepared.innerHTML = response.data.html;
				saved = radio.value;
			} catch (error) {
				radios.forEach(input => { input.checked = input.value === saved; });
				Craft.cp.displayError(error?.response?.data?.message || Craft.t('shipments', 'delivery.couldNotSaveMethod'));
			} finally {
				enabled.forEach(control => { control.disabled = false; });
				status.classList.add('hidden');
				prepared.removeAttribute('aria-busy');
				prepared.style.opacity = '';
			}
		}));
	}

	function initCreateDeliveryButton() {
		const form = document.getElementById('shipment-delivery-create');
		const button = document.querySelector('button[form="shipment-delivery-create"]');
		if (!form || !button) {
			return;
		}

		let submitting = false;
		form.addEventListener('submit', event => {
			if (submitting) {
				event.preventDefault();
				return;
			}

			submitting = true;
			const currentButton = document.querySelector('button[form="shipment-delivery-create"]');
			currentButton.disabled = true;
			currentButton.classList.add('disabled');
			currentButton.setAttribute('aria-disabled', 'true');
		});
	}

	function initPushIntegrationButtons() {
		document.querySelectorAll('.shipments-push-integration').forEach(function (pushButton) {
			pushButton.addEventListener('click', function () {
				pushButton.disabled = true;

				Craft.sendActionRequest('POST', 'shipments/shipments/push', {
					data: {
						id: pushButton.getAttribute('data-shipment-id'),
						integrationId: pushButton.getAttribute('data-integration-id'),
					},
				}).then(function () {
					markMainFormClean();
					window.location.reload();
				}).catch(function (error) {
					pushButton.disabled = false;
					const errorBody = error?.response?.data ?? {};
					Craft.cp.displayError(errorBody.message || errorBody.error || 'Unable to push shipment.');
				});
			});
		});
	}

	// Line-items editor on the shipment edit page: posts the current quantities so the service
	// can split or rebalance the shipment in place. Reloads on success so the freed pool, the
	// order-allocation badge, and the line-item maxes all reflect the new state.
	function initLineItemsEditor() {
		const saveButton = document.getElementById('shipments-save-line-items');
		const editor = document.getElementById('shipments-line-items-editor');
		if (!saveButton || !editor) {
			return;
		}

		saveButton.addEventListener('click', function () {
			saveButton.disabled = true;

			const lineItems = {};
			editor.querySelectorAll('input.shipments-line-item-qty').forEach(function (qtyInput) {
				lineItems[qtyInput.getAttribute('data-line-item-id')] = qtyInput.value;
			});

			Craft.sendActionRequest('POST', 'shipments/shipments/save-line-items', {
				data: { id: saveButton.getAttribute('data-shipment-id'), lineItems: lineItems },
			}).then(function (response) {
				const responseBody = response?.data ?? {};
				if (responseBody.message) {
					Craft.cp.displayNotice(responseBody.message);
				}
				markMainFormClean();
				window.location.reload();
			}).catch(function (error) {
				saveButton.disabled = false;
				const errorBody = error?.response?.data ?? {};
				Craft.cp.displayError(errorBody.message || Craft.t('shipments', 'error.couldNotSaveLineItems'));
			});
		});
	}

	function initSettingsAddRuleButtons() {
		const pairs = [
			['shipments-add-rule', '.shipments-new-rule-pane'],
			['shipments-add-category-rule', '.shipments-new-category-rule-pane'],
		];
		pairs.forEach(function (pair) {
			const button = document.getElementById(pair[0]);
			const pane = document.querySelector(pair[1]);
			if (!button || !pane) {
				return;
			}
			button.addEventListener('click', function () {
				pane.classList.remove('hidden');
				button.classList.add('hidden');
			});
		});
	}

	function initRemoveShipmentButtons() {
		document.querySelectorAll('.shipments-remove-shipment').forEach(function (removeButton) {
			removeButton.addEventListener('click', function () {
				const shipmentId = removeButton.getAttribute('data-shipment-id');
				const shipmentReference = removeButton.getAttribute('data-reference') || shipmentId;
				const confirmMessage = Craft.t(
					'shipments',
					'shipmentEdit.deleteConfirmWithReference',
					{ reference: shipmentReference }
				);
				if (!confirm(confirmMessage)) {
					return;
				}

				Craft.sendActionRequest('POST', 'shipments/shipments/delete', {
					data: { id: shipmentId },
				}).then(function (response) {
					const responseBody = response?.data ?? {};
					if (responseBody.success) {
						window.location.reload();
					} else {
						alert(responseBody.error || Craft.t('shipments', 'error.deleteFailed'));
					}
				}).catch(function (error) {
					const errorBody = error?.response?.data ?? {};
					alert(errorBody.error || Craft.t('shipments', 'error.deleteFailed'));
				});
			});
		});
	}

	function initStagingGroups() {
		const stagingContainer = document.getElementById('shipments-staging-groups');
		const addGroupButton = document.getElementById('shipments-add-group');
		const saveButton = document.getElementById('shipments-save-button');
		const stagingStatus = document.getElementById('shipments-staging-status');
		if (!stagingContainer || !saveButton) {
			return;
		}

		const remainingByLineItem = {};
		stagingContainer.querySelectorAll('.shipments-staging-group[data-group-index="0"] tr[data-line-item-id]').forEach(function (row) {
			const lineItemId = row.getAttribute('data-line-item-id');
			remainingByLineItem[lineItemId] = parseInt(row.getAttribute('data-remaining-qty'), 10) || 0;
		});

		function groupLabelFor(groupIndex) {
			const letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
			if (groupIndex < letters.length) {
				return 'Shipment ' + letters.charAt(groupIndex);
			}
			return 'Shipment ' + (groupIndex + 1);
		}

		function renumberGroups() {
			const groups = stagingContainer.querySelectorAll('.shipments-staging-group');
			groups.forEach(function (groupElement, groupIndex) {
				groupElement.setAttribute('data-group-index', groupIndex);
				const label = groupElement.querySelector('.shipments-staging-group-label');
				if (label) {
					label.textContent = groupLabelFor(groupIndex);
				}
				groupElement.querySelectorAll('input.shipments-staging-qty').forEach(function (qtyInput) {
					const lineItemId = qtyInput.getAttribute('data-line-item-id');
					qtyInput.setAttribute('name', 'groups[' + groupIndex + '][' + lineItemId + ']');
				});
				const removeButton = groupElement.querySelector('.shipments-remove-group');
				if (removeButton) {
					removeButton.classList.toggle('hidden', groups.length <= 1);
				}
			});
		}

		function recompute() {
			let allBalanced = true;
			Object.keys(remainingByLineItem).forEach(function (lineItemId) {
				let sum = 0;
				stagingContainer.querySelectorAll('input.shipments-staging-qty[data-line-item-id="' + lineItemId + '"]').forEach(function (qtyInput) {
					let parsed = parseInt(qtyInput.value, 10);
					if (isNaN(parsed) || parsed < 0) {
						parsed = 0;
					}
					sum += parsed;
				});
				const required = remainingByLineItem[lineItemId];
				const remaining = required - sum;
				if (sum !== required) {
					allBalanced = false;
				}

				stagingContainer.querySelectorAll('.shipments-staging-remaining[data-line-item-id="' + lineItemId + '"]').forEach(function (remainingCell) {
					remainingCell.textContent = remaining;
					remainingCell.classList.toggle('is-over', remaining < 0);
				});
			});

			if (stagingStatus) {
				stagingStatus.classList.toggle('is-valid', allBalanced);
				stagingStatus.classList.toggle('is-invalid', !allBalanced);
			}
			saveButton.disabled = !allBalanced;
		}

		function createTh(text, className) {
			const th = document.createElement('th');
			if (className) {
				th.className = className;
			}
			th.textContent = text;
			return th;
		}

		function buildGroupHeader(groupElement) {
			const headerElement = document.createElement('div');
			headerElement.className = 'shipments-staging-group-header';

			const titleElement = document.createElement('h3');
			titleElement.className = 'shipments-staging-group-label';
			// Label text is set by renumberGroups() after insertion.
			headerElement.appendChild(titleElement);

			const removeButton = document.createElement('button');
			removeButton.type = 'button';
			removeButton.className = 'btn shipments-remove-group';
			removeButton.textContent = Craft.t('shipments', 'orderTab.staging.removeGroup');
			removeButton.addEventListener('click', function () {
				groupElement.remove();
				renumberGroups();
				recompute();
			});
			headerElement.appendChild(removeButton);

			return headerElement;
		}

		function buildStagingRow(sourceRow, groupIndex) {
			const lineItemId = sourceRow.getAttribute('data-line-item-id');
			const remaining = remainingByLineItem[lineItemId];

			const newRow = document.createElement('tr');
			newRow.setAttribute('data-line-item-id', lineItemId);
			newRow.setAttribute('data-remaining-qty', String(remaining));

			// Clone the first cell (line-item description + SKU + status pill) as a safe
			// structured copy. The source was rendered by Twig so contents are already
			// escaped; cloneNode preserves them verbatim without re-parsing HTML.
			newRow.appendChild(sourceRow.children[0].cloneNode(true));

			const remainingCell = document.createElement('td');
			remainingCell.className = 'shipments-staging-remaining';
			remainingCell.setAttribute('data-line-item-id', lineItemId);
			remainingCell.textContent = '0';
			newRow.appendChild(remainingCell);

			const qtyCell = document.createElement('td');
			const qtyInput = document.createElement('input');
			qtyInput.type = 'number';
			qtyInput.className = 'text shipments-staging-qty';
			qtyInput.min = '0';
			qtyInput.max = String(remaining);
			qtyInput.step = '1';
			qtyInput.name = 'groups[' + groupIndex + '][' + lineItemId + ']';
			qtyInput.value = '0';
			qtyInput.setAttribute('data-line-item-id', lineItemId);
			qtyCell.appendChild(qtyInput);
			newRow.appendChild(qtyCell);

			return newRow;
		}

		function buildGroupTable(groupIndex) {
			const tableElement = document.createElement('table');
			tableElement.className = 'data fullwidth';

			const theadElement = document.createElement('thead');
			const headRow = document.createElement('tr');
			headRow.appendChild(createTh(Craft.t('shipments', 'shipmentEdit.lineItems.lineItem')));
			headRow.appendChild(createTh(Craft.t('shipments', 'orderTab.staging.remaining'), 'thin'));
			headRow.appendChild(createTh(Craft.t('shipments', 'orderTab.staging.qtyInGroup'), 'thin'));
			theadElement.appendChild(headRow);
			tableElement.appendChild(theadElement);

			const tbody = document.createElement('tbody');
			const firstGroupRows = stagingContainer.querySelectorAll('.shipments-staging-group[data-group-index="0"] tr[data-line-item-id]');
			firstGroupRows.forEach(function (sourceRow) {
				tbody.appendChild(buildStagingRow(sourceRow, groupIndex));
			});
			tableElement.appendChild(tbody);

			return tableElement;
		}

		function addGroup() {
			const groupIndex = stagingContainer.querySelectorAll('.shipments-staging-group').length;
			const groupElement = document.createElement('div');
			groupElement.className = 'pane shipments-staging-group';
			groupElement.setAttribute('data-group-index', groupIndex);

			groupElement.appendChild(buildGroupHeader(groupElement));
			groupElement.appendChild(buildGroupTable(groupIndex));

			stagingContainer.appendChild(groupElement);
			renumberGroups();
			recompute();
		}

		if (addGroupButton) {
			addGroupButton.addEventListener('click', addGroup);
		}

		stagingContainer.addEventListener('input', function (event) {
			if (event.target && event.target.classList.contains('shipments-staging-qty')) {
				recompute();
			}
		});

		stagingContainer.querySelectorAll('.shipments-staging-group').forEach(function (groupElement) {
			const removeButton = groupElement.querySelector('.shipments-remove-group');
			if (!removeButton) {
				return;
			}
			removeButton.addEventListener('click', function () {
				groupElement.remove();
				renumberGroups();
				recompute();
			});
		});

		const createForm = document.getElementById('shipments-create-form');
		saveButton.addEventListener('click', function () {
			if (saveButton.disabled) {
				return;
			}
			saveButton.disabled = true;

			const payload = { orderId: createForm ? createForm.getAttribute('data-order-id') : null, groups: {} };
			stagingContainer.querySelectorAll('.shipments-staging-group').forEach(function (groupElement) {
				const groupIndex = groupElement.getAttribute('data-group-index');
				payload.groups[groupIndex] = {};
				groupElement.querySelectorAll('input.shipments-staging-qty').forEach(function (qtyInput) {
					payload.groups[groupIndex][qtyInput.getAttribute('data-line-item-id')] = qtyInput.value;
				});
			});

			Craft.sendActionRequest('POST', 'shipments/shipments/create-shipment', {
				data: payload,
			}).then(function (response) {
				const responseBody = response?.data ?? {};
				if (responseBody.message) {
					Craft.cp.displayNotice(responseBody.message);
				}
				markMainFormClean();
				window.location.reload();
			}).catch(function (error) {
				saveButton.disabled = false;
				const errorBody = error?.response?.data ?? {};
				Craft.cp.displayError(errorBody.message || Craft.t('shipments', 'error.couldNotSaveShipments'));
			});
		});

		renumberGroups();
		recompute();
	}

	function initIntegrationReferencesRepeater() {
		const referencesContainer = document.getElementById('shipments-integration-references');
		const addReferenceButton = document.getElementById('shipments-add-reference');
		const referenceTemplate = document.getElementById('shipments-reference-template');
		if (!referencesContainer || !addReferenceButton || !referenceTemplate) {
			return;
		}

		function nextReferenceIndex() {
			return referencesContainer.querySelectorAll('.shipments-integration-reference').length;
		}

		function bindRemoveReference(row) {
			const removeButton = row.querySelector('.shipments-remove-reference');
			if (!removeButton) {
				return;
			}
			removeButton.addEventListener('click', function () {
				row.remove();
			});
		}

		referencesContainer.querySelectorAll('.shipments-integration-reference').forEach(bindRemoveReference);

		addReferenceButton.addEventListener('click', function () {
			// Clone the inert `<template>` contents as a structured fragment, then swap
			// `__INDEX__` in name attributes only. No HTML string parsing.
			const referenceIndex = nextReferenceIndex();
			const fragment = referenceTemplate.content.cloneNode(true);
			fragment.querySelectorAll('[name]').forEach(function (inputElement) {
				const currentName = inputElement.getAttribute('name') || '';
				inputElement.setAttribute('name', currentName.replace(/__INDEX__/g, String(referenceIndex)));
			});
			const newRow = fragment.firstElementChild;
			referencesContainer.appendChild(fragment);
			if (newRow) {
				bindRemoveReference(newRow);
			}
		});
	}

	// Order requires shipping lightswitch.
	// The lightswitchField's `toggle` / `reverseToggle` options handle showing/hiding the
	// dependent panes natively. This handler is only responsible for persisting the new state:
	// AJAX POST to set-active or set-ignored. On flip-off, prompts for confirmation first and
	// reverts the visual toggle on cancel. Shipments are left intact either way.
	const requiresShippingPane = document.querySelector('[data-shipments-requires-shipping]');
	if (requiresShippingPane) {
		const lightswitchEl = requiresShippingPane.querySelector('.lightswitch');
		if (lightswitchEl) {
			// Craft's turnOn/turnOff skip their change event when passed `true`. Reverting with
			// `toggle()` fires one, which re-enters this handler and prompts again.
			const revertSwitchTo = function (wasOn) {
				const lightswitch = $(lightswitchEl).data('lightswitch');
				if (wasOn) {
					lightswitch.turnOn(true);
				} else {
					lightswitch.turnOff(true);
				}
			};

			// Craft's `Craft.LightSwitch.onChange()` triggers `change` on the lightswitch
			// element itself (jQuery event), not on the hidden input. Bind via jQuery to match.
			$(lightswitchEl).on('change', function () {
				if (lightswitchEl.classList.contains('disabled') || lightswitchEl.classList.contains('noteditable')) {
					return;
				}

				const wasOn = requiresShippingPane.getAttribute('data-switch-on') === '1';

				if (wasOn) {
					if (!window.confirm(Craft.t('shipments', 'orderTab.requiresShippingOffConfirm'))) {
						revertSwitchTo(wasOn);
						return;
					}
				}

				const orderId = requiresShippingPane.getAttribute('data-order-id');
				const actionPath = wasOn
					? 'shipments/tracked-orders/set-ignored'
					: 'shipments/tracked-orders/set-active';

				Craft.sendActionRequest('POST', actionPath, {
					data: { orderId: orderId },
				}).then(function (response) {
					const responseBody = response?.data ?? {};
					requiresShippingPane.setAttribute('data-switch-on', wasOn ? '0' : '1');
					markMainFormClean();
					if (responseBody.message) {
						Craft.cp.displayNotice(responseBody.message);
					}
				}).catch(function (error) {
					revertSwitchTo(wasOn);
					const errorBody = error?.response?.data ?? {};
					Craft.cp.displayError(errorBody.message || Craft.t('shipments', 'error.couldNotUpdateOrder'));
				});
			});
		}
	}

	// Restore trashed shipments.
	// Surfaces only when the order has trashed shipments AND the toggle is on. POSTs to the
	// restore action and reloads on success so the existing-shipments list reflects the
	// restored rows and the trashed-count drops.
	const restorePane = document.querySelector('[data-shipments-restore-pane]');
	const restoreButton = document.getElementById('shipments-restore-button');
	if (restorePane && restoreButton) {
		restoreButton.addEventListener('click', function () {
			restoreButton.disabled = true;
			Craft.sendActionRequest('POST', 'shipments/tracked-orders/restore-shipments', {
				data: { orderId: restorePane.getAttribute('data-order-id') },
			}).then(function (response) {
				const responseBody = response?.data ?? {};
				if (responseBody.message) {
					Craft.cp.displayNotice(responseBody.message);
				}
				markMainFormClean();
				window.location.reload();
			}).catch(function (error) {
				restoreButton.disabled = false;
				const errorBody = error?.response?.data ?? {};
				Craft.cp.displayError(errorBody.message || Craft.t('shipments', 'error.couldNotRestoreShipments'));
			});
		});
	}

	function initIntegrationProviderPanels() {
		const providerSelect = document.getElementById('provider');
		if (!providerSelect) {
			return;
		}

		const panels = document.querySelectorAll('.shipments-provider-panel');
		const providerTabLink = document.querySelector('#tab-provider-settings a, a[href="#provider-settings"]');
		const providerPane = document.getElementById('provider-settings');
		const integrationTabLink = document.querySelector('a[href="#integration"]');
		const integrationPane = document.getElementById('integration');
		const mappingTabLink = document.querySelector('a[href="#carrier-mapping"]');
		const mappingPane = document.getElementById('carrier-mapping');

		function applyProviderVisibility() {
			const selected = providerSelect.value;
			panels.forEach(function (panel) {
				const inactive = panel.getAttribute('data-provider') !== selected;
				panel.classList.toggle('hidden', inactive);
			});
			const hasCarrier = Array.from(document.querySelectorAll('.shipments-carrier-mapping')).some(panel => panel.dataset.provider === selected);
			if (mappingTabLink) {
				mappingTabLink.classList.toggle('hidden', !hasCarrier);
				mappingTabLink.closest('li')?.classList.toggle('hidden', !hasCarrier);
			}
			if (!hasCarrier && mappingPane && !mappingPane.classList.contains('hidden')) {
				integrationTabLink?.click();
			}

			const hasProvider = !!selected;
			if (providerTabLink) {
				providerTabLink.classList.toggle('hidden', !hasProvider);
			}
			// If the Provider tab was active and we just cleared the provider, fall back to Integration.
			if (!hasProvider && providerPane && !providerPane.classList.contains('hidden')) {
				providerPane.classList.add('hidden');
				if (integrationPane) {
					integrationPane.classList.remove('hidden');
				}
				if (integrationTabLink) {
					integrationTabLink.classList.add('sel');
				}
				if (providerTabLink) {
					providerTabLink.classList.remove('sel');
				}
			}
		}

		providerSelect.addEventListener('change', applyProviderVisibility);
		applyProviderVisibility();
	}

	function initCarrierMappings() {
		document.querySelectorAll('.shipments-carrier-mapping').forEach(function (panel) {
			const sources = JSON.parse(panel.dataset.sources);
			const services = JSON.parse(panel.dataset.services);
			const rows = panel.querySelector('tbody');
			let nextIndex = 0;
			const label = key => Craft.t('shipments', 'settings.integrations.' + key);
			function addServiceOptions(input, options) {
				const entries = Object.entries(options);
				const counts = new Map();
				entries.forEach(([, name]) => counts.set(name, (counts.get(name) ?? 0) + 1));
				entries.forEach(([code, name]) => input.add(new Option(counts.get(name) > 1 ? name + ' (' + code + ')' : name, code)));
			}

			function addRow(mapping = {source: '', sourceService: '', service: ''}) {
				const row = document.createElement('tr');
				const prefix = panel.dataset.inputPrefix + '[carrierMappings][' + nextIndex++ + ']';
				function select(column, heading) {
					const cell = row.insertCell();
					const wrap = document.createElement('div');
					wrap.className = 'select fullwidth';
					const input = document.createElement('select');
					input.name = prefix + '[' + column + ']';
					input.setAttribute('aria-label', label(heading));
					wrap.append(input);
					cell.append(wrap);
					return input;
				}
				const source = select('source', 'shippingSource');
				const sourceService = select('sourceService', 'sourceService');
				const emptySourceService = document.createElement('input');
				emptySourceService.type = 'hidden';
				emptySourceService.name = sourceService.name;
				emptySourceService.value = '';
				sourceService.before(emptySourceService);
				const service = select('service', 'integrationService');
				source.required = true;
				service.required = true;
				source.add(new Option(label('chooseSource'), ''));
				const groups = new Map();
				sources.forEach(function (item) {
					if (!groups.has(item.group)) {
						const group = document.createElement('optgroup');
						group.label = item.group;
						groups.set(item.group, group);
						source.append(group);
					}
					groups.get(item.group).append(new Option(item.label, item.value));
				});
				function keepSavedOption(input, value) {
					if (value && !Array.from(input.options).some(option => option.value === value)) {
						input.add(new Option(label('unavailable') + ' (' + value + ')', value));
					}
					input.value = value;
				}
				keepSavedOption(source, mapping.source);
				function updateIntegrationServices(savedValue = '') {
					service.replaceChildren(new Option(label('chooseService'), ''));
					const options = Object.fromEntries(Object.entries(services).filter(([code]) => code !== 'auto' || source.value.startsWith('postie:')));
					addServiceOptions(service, options);
					keepSavedOption(service, savedValue);
				}

				function updateSourceServices(savedValue = '') {
					const selected = sources.find(item => item.value === source.value);
					const options = selected?.services ?? {};
					sourceService.replaceChildren();
					if (Object.keys(options).length) {
						addServiceOptions(sourceService, options);
						if (savedValue && !Object.hasOwn(options, savedValue)) {
							sourceService.add(new Option(label('chooseService'), ''), 0);
							sourceService.value = '';
						} else {
							sourceService.value = savedValue || '*';
						}
					} else {
						sourceService.add(new Option(label('noSourceService'), ''));
						keepSavedOption(sourceService, savedValue);
					}
					const noSourceService = sourceService.options.length === 1 && sourceService.options[0].value === '';
					sourceService.required = Object.keys(options).length > 0;
					sourceService.disabled = noSourceService;
					sourceService.parentElement.classList.toggle('disabled', noSourceService);
					emptySourceService.disabled = !noSourceService;
				}
				updateSourceServices(mapping.sourceService);
				updateIntegrationServices(mapping.service);
				source.addEventListener('change', () => {
					updateSourceServices();
					updateIntegrationServices(service.value === 'auto' && !source.value.startsWith('postie:') ? '' : service.value);
				});
				const remove = document.createElement('button');
				remove.type = 'button';
				remove.className = 'delete icon';
				remove.setAttribute('aria-label', label('removeMapping'));
				remove.addEventListener('click', () => { row.remove(); panel.dispatchEvent(new Event('change', {bubbles: true})); });
				row.insertCell().append(remove);
				rows.append(row);
				return source;
			}

			JSON.parse(panel.dataset.mappings).forEach(addRow);
			panel.querySelector('[data-add-mapping]').addEventListener('click', () => {
				addRow().focus();
				panel.dispatchEvent(new Event('change', {bubbles: true}));
			});
		});
	}
})(jQuery);
