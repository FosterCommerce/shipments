<?php

declare(strict_types=1);

namespace fostercommerce\shipments\enums;

use Craft;

enum DeliveryDocumentType: string
{
	case BILL_OF_LADING = 'BILL_OF_LADING';

	case CERTIFICATE_OF_ORIGIN = 'CERTIFICATE_OF_ORIGIN';

	case COMMERCIAL_INVOICE = 'COMMERCIAL_INVOICE';

	case CUSTOM_PACKAGE_DOCUMENT = 'CUSTOM_PACKAGE_DOCUMENT';

	case CUSTOM_SHIPMENT_DOCUMENT = 'CUSTOM_SHIPMENT_DOCUMENT';

	case CUSTOMER_SPECIFIED_LABELS = 'CUSTOMER_SPECIFIED_LABELS';

	case DANGEROUS_GOODS_SHIPPERS_DECLARATION = 'DANGEROUS_GOODS_SHIPPERS_DECLARATION';

	case EXPORT_DECLARATION = 'EXPORT_DECLARATION';

	case GENERAL_AGENCY_AGREEMENT = 'GENERAL_AGENCY_AGREEMENT';

	case LABEL = 'LABEL';

	case NET_RATE_SHEET = 'NET_RATE_SHEET';

	case OP_900 = 'OP_900';

	case PENDING_SHIPMENT_EMAIL_NOTIFICATION = 'PENDING_SHIPMENT_EMAIL_NOTIFICATION';

	case PRO_FORMA_INVOICE = 'PRO_FORMA_INVOICE';

	case RETURN_INSTRUCTIONS = 'RETURN_INSTRUCTIONS';

	case VICS_BILL_OF_LADING = 'VICS_BILL_OF_LADING';

	case USMCA_COMMERCIAL_INVOICE_VALIDATION_OF_ORIGIN = 'USMCA_COMMERCIAL_INVOICE_VALIDATION_OF_ORIGIN';

	case USMCA_VALIDATION_OF_ORIGIN = 'USMCA_VALIDATION_OF_ORIGIN';

	case OTHER = 'OTHER';

	public function label(): string
	{
		return Craft::t('shipments', match ($this) {
			self::BILL_OF_LADING => 'delivery.documents.billOfLading',
			self::CERTIFICATE_OF_ORIGIN => 'delivery.documents.certificateOfOrigin',
			self::COMMERCIAL_INVOICE => 'delivery.documents.commercialInvoice',
			self::CUSTOM_PACKAGE_DOCUMENT => 'delivery.documents.customPackageDocument',
			self::CUSTOM_SHIPMENT_DOCUMENT => 'delivery.documents.customShipmentDocument',
			self::CUSTOMER_SPECIFIED_LABELS => 'delivery.documents.customerSpecifiedLabels',
			self::DANGEROUS_GOODS_SHIPPERS_DECLARATION => 'delivery.documents.dangerousGoodsShippersDeclaration',
			self::EXPORT_DECLARATION => 'delivery.documents.exportDeclaration',
			self::GENERAL_AGENCY_AGREEMENT => 'delivery.documents.generalAgencyAgreement',
			self::LABEL => 'delivery.documents.label',
			self::NET_RATE_SHEET => 'delivery.documents.netRateSheet',
			self::OP_900 => 'delivery.documents.op900',
			self::PENDING_SHIPMENT_EMAIL_NOTIFICATION => 'delivery.documents.pendingShipmentEmailNotification',
			self::PRO_FORMA_INVOICE => 'delivery.documents.proFormaInvoice',
			self::RETURN_INSTRUCTIONS => 'delivery.documents.returnInstructions',
			self::VICS_BILL_OF_LADING => 'delivery.documents.vicsBillOfLading',
			self::USMCA_COMMERCIAL_INVOICE_VALIDATION_OF_ORIGIN => 'delivery.documents.usmcaCommercialInvoiceValidationOfOrigin',
			self::USMCA_VALIDATION_OF_ORIGIN => 'delivery.documents.usmcaValidationOfOrigin',
			self::OTHER => 'delivery.documents.other',
		});
	}
}
