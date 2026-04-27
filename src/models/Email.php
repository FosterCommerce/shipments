<?php

declare(strict_types=1);

namespace fostercommerce\shipments\models;

use Craft;
use craft\base\Model;
use craft\commerce\elements\Order;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use fostercommerce\shipments\Plugin;
use fostercommerce\shipments\records\Email as EmailRecord;
use yii\base\InvalidArgumentException;

/**
 * @property-read string $cpEditUrl
 * @property ?string $to
 * @property ?string $bcc
 * @property ?string $cc
 */
class Email extends Model
{
	public ?int $id = null;

	public ?string $name = null;

	public ?string $subject = null;

	public string $recipientType = EmailRecord::TYPE_CUSTOMER;

	public ?string $replyTo = null;

	public bool $enabled = true;

	public ?string $templatePath = null;

	public ?string $plainTextTemplatePath = null;

	public string $language = EmailRecord::LOCALE_ORDER_LANGUAGE;

	public ?string $uid = null;

	private ?string $_to = null;

	private ?string $_bcc = null;

	private ?string $_cc = null;

	public function setTo(?string $to): void
	{
		$this->_to = $to;
	}

	public function getTo(bool $parse = true): ?string
	{
		if (! $parse) {
			return $this->_to;
		}

		$parsed = App::parseEnv($this->_to);
		return is_string($parsed) ? $parsed : null;
	}

	public function setBcc(?string $bcc): void
	{
		$this->_bcc = $bcc;
	}

	public function getBcc(bool $parse = true): ?string
	{
		if (! $parse) {
			return $this->_bcc;
		}

		$parsed = App::parseEnv($this->_bcc);
		return is_string($parsed) ? $parsed : null;
	}

	public function setCc(?string $cc): void
	{
		$this->_cc = $cc;
	}

	public function getCc(bool $parse = true): ?string
	{
		if (! $parse) {
			return $this->_cc;
		}

		$parsed = App::parseEnv($this->_cc);
		return is_string($parsed) ? $parsed : null;
	}

	/**
	 * Returns the language the email template should render in.
	 */
	public function getRenderLanguage(?Order $order = null): string
	{
		$language = $this->language;

		if (! $order instanceof Order && $language === EmailRecord::LOCALE_ORDER_LANGUAGE) {
			throw new InvalidArgumentException('Cannot resolve email language without an order when language is set to "orderLanguage".');
		}

		if ($order instanceof Order && $language === EmailRecord::LOCALE_ORDER_LANGUAGE) {
			return $order->orderLanguage ?? Craft::$app->getSites()->getPrimarySite()->language;
		}

		return $language;
	}

	public function getCpEditUrl(): string
	{
		return UrlHelper::cpUrl('shipments/settings/emails/' . ($this->id ?? ''));
	}

	/**
	 * Returns the project config payload for this email.
	 *
	 * @return array<string, mixed>
	 */
	public function getConfig(): array
	{
		$to = $this->getTo(false);
		$bcc = $this->getBcc(false);
		$cc = $this->getCc(false);

		return [
			'name' => $this->name,
			'subject' => $this->subject,
			'recipientType' => $this->recipientType,
			'to' => $to === null || $to === '' ? null : $to,
			'bcc' => $bcc === null || $bcc === '' ? null : $bcc,
			'cc' => $cc === null || $cc === '' ? null : $cc,
			'replyTo' => $this->replyTo === null || $this->replyTo === '' ? null : $this->replyTo,
			'enabled' => $this->enabled,
			'templatePath' => $this->templatePath === null || $this->templatePath === '' ? null : $this->templatePath,
			'plainTextTemplatePath' => $this->plainTextTemplatePath === null || $this->plainTextTemplatePath === '' ? null : $this->plainTextTemplatePath,
			'language' => $this->language,
		];
	}

	/**
	 * @return array<array-key, mixed>
	 */
	protected function defineRules(): array
	{
		return [
			[['name', 'subject', 'templatePath', 'language'], 'required'],
			[['recipientType'],
				'in',
				'range' => [EmailRecord::TYPE_CUSTOMER, EmailRecord::TYPE_CUSTOM]],
			[['to'],
				'required',
				'when' => static fn (self $email): bool => $email->recipientType === EmailRecord::TYPE_CUSTOM,
				'message' => Craft::t(Plugin::HANDLE, 'To: is required when Recipient Type is Custom.')],
			[[
				'bcc',
				'cc',
				'enabled',
				'id',
				'language',
				'name',
				'plainTextTemplatePath',
				'recipientType',
				'replyTo',
				'subject',
				'templatePath',
				'to',
				'uid',
			], 'safe'],
		];
	}
}
