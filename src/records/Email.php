<?php

declare(strict_types=1);

namespace fostercommerce\shipments\records;

use craft\db\ActiveRecord;
use fostercommerce\shipments\db\Table;

/**
 * @property int $id
 * @property string $name
 * @property string $subject
 * @property string $recipientType
 * @property ?string $to
 * @property ?string $bcc
 * @property ?string $cc
 * @property ?string $replyTo
 * @property bool $enabled
 * @property string $templatePath
 * @property ?string $plainTextTemplatePath
 * @property string $language
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class Email extends ActiveRecord
{
	public const LOCALE_ORDER_LANGUAGE = 'orderLanguage';

	public const TYPE_CUSTOMER = 'customer';

	public const TYPE_CUSTOM = 'custom';

	public static function tableName(): string
	{
		return Table::EMAILS;
	}
}
