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
		var $mainForm = $('#main-form');
		if ($mainForm.length) {
			$mainForm.data('initialSerializedValue', $mainForm.serialize());
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		initRemoveShipmentButtons();
		initStagingGroups();
		initIntegrationReferencesRepeater();
		initIntegrationProviderPanels();
		initSettingsAddRuleButtons();
		initStatusHistoryFilter();
	});

	function initStatusHistoryFilter() {
		var filterGroup = document.getElementById('shipments-history-filter');
		var historyTable = document.getElementById('shipments-history-table');
		if (!filterGroup || !historyTable) {
			return;
		}

		var rows = historyTable.querySelectorAll('tbody tr[data-history-type]');
		filterGroup.querySelectorAll('button[data-history-filter]').forEach(function (button) {
			button.addEventListener('click', function () {
				var filter = button.getAttribute('data-history-filter');
				filterGroup.querySelectorAll('button[data-history-filter]').forEach(function (other) {
					other.classList.toggle('active', other === button);
				});
				rows.forEach(function (row) {
					var rowType = row.getAttribute('data-history-type');
					row.classList.toggle('hidden', filter !== 'all' && rowType !== filter);
				});
			});
		});
	}

	function initSettingsAddRuleButtons() {
		var pairs = [
			['shipments-add-rule', '.shipments-new-rule-pane'],
			['shipments-add-category-rule', '.shipments-new-category-rule-pane'],
		];
		pairs.forEach(function (pair) {
			var button = document.getElementById(pair[0]);
			var pane = document.querySelector(pair[1]);
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
				var shipmentId = removeButton.getAttribute('data-shipment-id');
				var shipmentReference = removeButton.getAttribute('data-reference') || shipmentId;
				if (typeof Craft === 'undefined' || typeof Craft.sendActionRequest !== 'function') {
					// Craft itself didn't load. Translation infrastructure isn't available either,
					// so fall back to English; this state shouldn't reach a real user.
					alert('Craft CP JS not loaded. Cannot delete.');
					return;
				}

				var confirmMessage = Craft.t(
					'shipments',
					'Delete shipment {reference}? Its line items will go back to the unallocated pool.',
					{ reference: shipmentReference }
				);
				if (!confirm(confirmMessage)) {
					return;
				}

				Craft.sendActionRequest('POST', 'shipments/shipments/delete', {
					data: { id: shipmentId },
				}).then(function (response) {
					var responseBody = response && response.data ? response.data : {};
					if (responseBody.success) {
						window.location.reload();
					} else {
						alert(responseBody.error || Craft.t('shipments', 'Delete failed.'));
					}
				}).catch(function (error) {
					var errorBody = error && error.response && error.response.data ? error.response.data : {};
					alert(errorBody.error || Craft.t('shipments', 'Delete failed.'));
				});
			});
		});
	}

	function initStagingGroups() {
		var stagingContainer = document.getElementById('shipments-staging-groups');
		var addGroupButton = document.getElementById('shipments-add-group');
		var saveButton = document.getElementById('shipments-save-button');
		var stagingStatus = document.getElementById('shipments-staging-status');
		if (!stagingContainer || !saveButton) {
			return;
		}

		var remainingByLineItem = {};
		stagingContainer.querySelectorAll('.shipments-staging-group[data-group-index="0"] tr[data-line-item-id]').forEach(function (row) {
			var lineItemId = row.getAttribute('data-line-item-id');
			remainingByLineItem[lineItemId] = parseInt(row.getAttribute('data-remaining-qty'), 10) || 0;
		});

		function groupLabelFor(groupIndex) {
			var letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
			if (groupIndex < letters.length) {
				return 'Shipment ' + letters.charAt(groupIndex);
			}
			return 'Shipment ' + (groupIndex + 1);
		}

		function renumberGroups() {
			var groups = stagingContainer.querySelectorAll('.shipments-staging-group');
			groups.forEach(function (groupElement, groupIndex) {
				groupElement.setAttribute('data-group-index', groupIndex);
				var label = groupElement.querySelector('.shipments-staging-group-label');
				if (label) {
					label.textContent = groupLabelFor(groupIndex);
				}
				groupElement.querySelectorAll('input.shipments-staging-qty').forEach(function (qtyInput) {
					var lineItemId = qtyInput.getAttribute('data-line-item-id');
					qtyInput.setAttribute('name', 'groups[' + groupIndex + '][' + lineItemId + ']');
				});
				var removeButton = groupElement.querySelector('.shipments-remove-group');
				if (removeButton) {
					removeButton.classList.toggle('hidden', groups.length <= 1);
				}
			});
		}

		function recompute() {
			var allBalanced = true;
			Object.keys(remainingByLineItem).forEach(function (lineItemId) {
				var sum = 0;
				stagingContainer.querySelectorAll('input.shipments-staging-qty[data-line-item-id="' + lineItemId + '"]').forEach(function (qtyInput) {
					var parsed = parseInt(qtyInput.value, 10);
					if (isNaN(parsed) || parsed < 0) {
						parsed = 0;
					}
					sum += parsed;
				});
				var required = remainingByLineItem[lineItemId];
				var remaining = required - sum;
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
			var th = document.createElement('th');
			if (className) {
				th.className = className;
			}
			th.textContent = text;
			return th;
		}

		function buildGroupHeader(groupElement) {
			var headerElement = document.createElement('div');
			headerElement.className = 'shipments-staging-group-header';

			var titleElement = document.createElement('h3');
			titleElement.className = 'shipments-staging-group-label';
			// Label text is set by renumberGroups() after insertion.
			headerElement.appendChild(titleElement);

			var removeButton = document.createElement('button');
			removeButton.type = 'button';
			removeButton.className = 'btn shipments-remove-group';
			removeButton.textContent = Craft.t('shipments', 'Remove group');
			removeButton.addEventListener('click', function () {
				groupElement.remove();
				renumberGroups();
				recompute();
			});
			headerElement.appendChild(removeButton);

			return headerElement;
		}

		function buildStagingRow(sourceRow, groupIndex) {
			var lineItemId = sourceRow.getAttribute('data-line-item-id');
			var remaining = remainingByLineItem[lineItemId];

			var newRow = document.createElement('tr');
			newRow.setAttribute('data-line-item-id', lineItemId);
			newRow.setAttribute('data-remaining-qty', String(remaining));

			// Clone the first cell (line-item description + SKU + status pill) as a safe
			// structured copy. The source was rendered by Twig so contents are already
			// escaped; cloneNode preserves them verbatim without re-parsing HTML.
			newRow.appendChild(sourceRow.children[0].cloneNode(true));

			var remainingCell = document.createElement('td');
			remainingCell.className = 'shipments-staging-remaining';
			remainingCell.setAttribute('data-line-item-id', lineItemId);
			remainingCell.textContent = '0';
			newRow.appendChild(remainingCell);

			var qtyCell = document.createElement('td');
			var qtyInput = document.createElement('input');
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
			var tableElement = document.createElement('table');
			tableElement.className = 'data fullwidth';

			var theadElement = document.createElement('thead');
			var headRow = document.createElement('tr');
			headRow.appendChild(createTh(Craft.t('shipments', 'Line item')));
			headRow.appendChild(createTh(Craft.t('shipments', 'Remaining'), 'thin'));
			headRow.appendChild(createTh(Craft.t('shipments', 'Qty in group'), 'thin'));
			theadElement.appendChild(headRow);
			tableElement.appendChild(theadElement);

			var tbody = document.createElement('tbody');
			var firstGroupRows = stagingContainer.querySelectorAll('.shipments-staging-group[data-group-index="0"] tr[data-line-item-id]');
			firstGroupRows.forEach(function (sourceRow) {
				tbody.appendChild(buildStagingRow(sourceRow, groupIndex));
			});
			tableElement.appendChild(tbody);

			return tableElement;
		}

		function addGroup() {
			var groupIndex = stagingContainer.querySelectorAll('.shipments-staging-group').length;
			var groupElement = document.createElement('div');
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
			var removeButton = groupElement.querySelector('.shipments-remove-group');
			if (!removeButton) {
				return;
			}
			removeButton.addEventListener('click', function () {
				groupElement.remove();
				renumberGroups();
				recompute();
			});
		});

		var createForm = document.getElementById('shipments-create-form');
		saveButton.addEventListener('click', function () {
			if (saveButton.disabled) {
				return;
			}
			saveButton.disabled = true;

			var payload = { orderId: createForm ? createForm.getAttribute('data-order-id') : null, groups: {} };
			stagingContainer.querySelectorAll('.shipments-staging-group').forEach(function (groupElement) {
				var groupIndex = groupElement.getAttribute('data-group-index');
				payload.groups[groupIndex] = {};
				groupElement.querySelectorAll('input.shipments-staging-qty').forEach(function (qtyInput) {
					payload.groups[groupIndex][qtyInput.getAttribute('data-line-item-id')] = qtyInput.value;
				});
			});

			Craft.sendActionRequest('POST', 'shipments/shipments/create-shipment', {
				data: payload,
			}).then(function (response) {
				var responseBody = response && response.data ? response.data : {};
				if (responseBody.message) {
					Craft.cp.displayNotice(responseBody.message);
				}
				markMainFormClean();
				window.location.reload();
			}).catch(function (error) {
				saveButton.disabled = false;
				var errorBody = error && error.response && error.response.data ? error.response.data : {};
				Craft.cp.displayError(errorBody.message || Craft.t('shipments', 'Couldn’t save shipments.'));
			});
		});

		renumberGroups();
		recompute();
	}

	function initIntegrationReferencesRepeater() {
		var referencesContainer = document.getElementById('shipments-integration-references');
		var addReferenceButton = document.getElementById('shipments-add-reference');
		var referenceTemplate = document.getElementById('shipments-reference-template');
		if (!referencesContainer || !addReferenceButton || !referenceTemplate) {
			return;
		}

		function nextReferenceIndex() {
			return referencesContainer.querySelectorAll('.shipments-integration-reference').length;
		}

		function bindRemoveReference(row) {
			var removeButton = row.querySelector('.shipments-remove-reference');
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
			var referenceIndex = nextReferenceIndex();
			var fragment = referenceTemplate.content.cloneNode(true);
			fragment.querySelectorAll('[name]').forEach(function (inputElement) {
				var currentName = inputElement.getAttribute('name') || '';
				inputElement.setAttribute('name', currentName.replace(/__INDEX__/g, String(referenceIndex)));
			});
			var newRow = fragment.firstElementChild;
			referencesContainer.appendChild(fragment);
			if (newRow) {
				bindRemoveReference(newRow);
			}
		});
	}

	// ══════════════════════════ ORDER REQUIRES SHIPPING LIGHTSWITCH ══════════════════════════
	// The lightswitchField's `toggle` / `reverseToggle` options handle showing/hiding the
	// dependent panes natively. This handler is only responsible for persisting the new state:
	// AJAX POST to set-active or set-ignored. On flip-off with shipments attached, prompts for
	// confirmation first and reverts the visual toggle on cancel.
	var requiresShippingPane = document.querySelector('[data-shipments-requires-shipping]');
	if (requiresShippingPane) {
		var lightswitchEl = requiresShippingPane.querySelector('.lightswitch');
		if (lightswitchEl) {
			// Craft's `Craft.LightSwitch.onChange()` triggers `change` on the lightswitch
			// element itself (jQuery event), not on the hidden input. Bind via jQuery to match.
			$(lightswitchEl).on('change', function () {
				if (lightswitchEl.classList.contains('disabled') || lightswitchEl.classList.contains('noteditable')) {
					return;
				}

				var wasOn = requiresShippingPane.getAttribute('data-switch-on') === '1';
				var shipmentCount = parseInt(requiresShippingPane.getAttribute('data-shipment-count') || '0', 10);
				var revertToggle = function () {
					var instance = $(lightswitchEl).data('lightswitch');
					if (instance && typeof instance.toggle === 'function') {
						instance.toggle();
					}
				};

				if (wasOn) {
					var confirmMessage = shipmentCount > 0
						? Craft.t(
							'shipments',
							'Turning this off will trash {count} shipment(s) on this order and stop tracking it for fulfillment. Continue?',
							{ count: shipmentCount }
						)
						: Craft.t('shipments', 'Turn off shipping for this order? It will drop off the Attention page.');

					if (!window.confirm(confirmMessage)) {
						revertToggle();
						return;
					}
				}

				var orderId = requiresShippingPane.getAttribute('data-order-id');
				var actionPath = wasOn
					? 'shipments/tracked-orders/set-ignored'
					: 'shipments/tracked-orders/set-active';

				Craft.sendActionRequest('POST', actionPath, {
					data: { orderId: orderId },
				}).then(function (response) {
					var responseBody = response && response.data ? response.data : {};
					requiresShippingPane.setAttribute('data-switch-on', wasOn ? '0' : '1');
					markMainFormClean();
					if (responseBody.message) {
						Craft.cp.displayNotice(responseBody.message);
					}
					// Server cascade-disabled the existing shipments. Reload so the shipments
					// list reflects their new disabled state; the simple visual toggle isn't
					// enough when there's actual data to refresh.
					if (wasOn && shipmentCount > 0) {
						window.location.reload();
					}
				}).catch(function (error) {
					revertToggle();
					var errorBody = error && error.response && error.response.data ? error.response.data : {};
					Craft.cp.displayError(errorBody.message || Craft.t('shipments', 'Couldn’t update this order.'));
				});
			});
		}
	}

	// ══════════════════════════ RESTORE TRASHED SHIPMENTS ══════════════════════════
	// Surfaces only when the order has trashed shipments AND the toggle is on. POSTs to the
	// restore action and reloads on success so the existing-shipments list reflects the
	// restored rows and the trashed-count drops.
	var restorePane = document.querySelector('[data-shipments-restore-pane]');
	var restoreButton = document.getElementById('shipments-restore-button');
	if (restorePane && restoreButton) {
		restoreButton.addEventListener('click', function () {
			restoreButton.disabled = true;
			Craft.sendActionRequest('POST', 'shipments/tracked-orders/restore-shipments', {
				data: { orderId: restorePane.getAttribute('data-order-id') },
			}).then(function (response) {
				var responseBody = response && response.data ? response.data : {};
				if (responseBody.message) {
					Craft.cp.displayNotice(responseBody.message);
				}
				markMainFormClean();
				window.location.reload();
			}).catch(function (error) {
				restoreButton.disabled = false;
				var errorBody = error && error.response && error.response.data ? error.response.data : {};
				Craft.cp.displayError(errorBody.message || Craft.t('shipments', 'Couldn’t restore shipments.'));
			});
		});
	}

	function initIntegrationProviderPanels() {
		var providerSelect = document.getElementById('provider');
		if (!providerSelect) {
			return;
		}

		var panels = document.querySelectorAll('.shipments-provider-panel');
		var providerTabLink = document.querySelector('#tab-provider-settings a, a[href="#provider-settings"]');
		var providerPane = document.getElementById('provider-settings');
		var integrationTabLink = document.querySelector('a[href="#integration"]');
		var integrationPane = document.getElementById('integration');

		function applyProviderVisibility() {
			var selected = providerSelect.value;
			panels.forEach(function (panel) {
				panel.classList.toggle('hidden', panel.getAttribute('data-provider') !== selected);
			});

			var hasProvider = !!selected;
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
})(jQuery);
