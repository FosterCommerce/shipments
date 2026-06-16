<?php

declare(strict_types=1);

namespace fostercommerce\shipments\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\helpers\Locale;
use craft\db\Query;
use craft\events\ConfigEvent;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\Typecast;
use craft\mail\Message;
use craft\web\View;
use Exception as PhpException;
use fostercommerce\shipments\db\Table;
use fostercommerce\shipments\models\Email;
use fostercommerce\shipments\models\ShipmentEmailContext;
use fostercommerce\shipments\Plugin;
use fostercommerce\shipments\records\Email as EmailRecord;
use Illuminate\Support\Collection;
use Throwable;
use yii\base\Component;
use yii\base\Exception;

/**
 * Shipment email CRUD + send. Writes go through project config; sends are
 * synchronous calls from `SendShipmentEmailJob` or direct service use.
 */
class Emails extends Component
{
	public const CONFIG_EMAILS_KEY = 'shipments.emails';

	/**
	 * @var Collection<int, Email>|null
	 */
	private ?Collection $allEmails = null;

	/**
	 * @return Collection<int, Email>
	 */
	public function getAllEmails(): Collection
	{
		if (! $this->allEmails instanceof Collection) {
			$this->allEmails = collect();

			/** @var list<array<string, mixed>> $rows */
			$rows = (new Query())
				->select([
					'id',
					'name',
					'subject',
					'recipientType',
					'to',
					'bcc',
					'cc',
					'replyTo',
					'enabled',
					'templatePath',
					'plainTextTemplatePath',
					'language',
					'uid',
				])
				->from(Table::EMAILS)
				->orderBy([
					'name' => SORT_ASC,
				])
				->all();

			foreach ($rows as $row) {
				$this->allEmails->push($this->modelFromRow($row));
			}
		}

		return $this->allEmails;
	}

	/**
	 * Finds an email by id.
	 */
	public function getEmailById(int $id): ?Email
	{
		return $this->getAllEmails()->firstWhere('id', $id);
	}

	/**
	 * Finds an email by uid.
	 */
	public function getEmailByUid(string $uid): ?Email
	{
		return $this->getAllEmails()->firstWhere('uid', $uid);
	}

	/**
	 * @throws Exception
	 */
	public function saveEmail(Email $email, bool $runValidation = true): bool
	{
		$isNewEmail = $email->id === null;

		if ($runValidation && ! $email->validate()) {
			Craft::warning(
				'Email not saved due to validation errors: ' . Json::encode($email->getErrors()),
				Plugin::HANDLE,
			);
			return false;
		}

		if ($isNewEmail) {
			$email->uid = StringHelper::UUID();
		} elseif ($email->uid === null) {
			$email->uid = Db::uidById(Table::EMAILS, (int) $email->id);
		}

		if ($email->uid === null) {
			throw new Exception("No email exists with id {$email->id}.");
		}

		Craft::$app->getProjectConfig()->set(self::CONFIG_EMAILS_KEY . '.' . $email->uid, $email->getConfig());

		if ($isNewEmail) {
			$email->id = Db::idByUid(Table::EMAILS, $email->uid);
		}

		$this->allEmails = null;

		return true;
	}

	/**
	 * @throws Throwable
	 */
	public function handleChangedEmail(ConfigEvent $event): void
	{
		$emailUid = (string) ($event->tokenMatches[0] ?? '');
		$data = $event->newValue;

		if ($emailUid === '' || ! is_array($data)) {
			return;
		}

		$transaction = Craft::$app->getDb()->beginTransaction();

		try {
			$emailRecord = $this->getEmailRecord($emailUid);
			$emailRecord->name = (string) ($data['name'] ?? '');
			$emailRecord->subject = (string) ($data['subject'] ?? '');
			$emailRecord->recipientType = (string) ($data['recipientType'] ?? EmailRecord::TYPE_CUSTOMER);
			$emailRecord->to = isset($data['to']) ? (string) $data['to'] : null;
			$emailRecord->bcc = isset($data['bcc']) ? (string) $data['bcc'] : null;
			$emailRecord->cc = isset($data['cc']) ? (string) $data['cc'] : null;
			$emailRecord->replyTo = isset($data['replyTo']) ? (string) $data['replyTo'] : null;
			$emailRecord->enabled = (bool) ($data['enabled'] ?? true);
			$emailRecord->templatePath = (string) ($data['templatePath'] ?? '');
			$emailRecord->plainTextTemplatePath = isset($data['plainTextTemplatePath']) ? (string) $data['plainTextTemplatePath'] : null;
			$emailRecord->language = (string) ($data['language'] ?? EmailRecord::LOCALE_ORDER_LANGUAGE);
			$emailRecord->uid = $emailUid;

			$emailRecord->save(false);

			$transaction->commit();
		} catch (Throwable $throwable) {
			$transaction->rollBack();
			throw $throwable;
		}

		$this->allEmails = null;
	}

	public function deleteEmailById(int $id): bool
	{
		$email = $this->getEmailById($id);
		if (! $email instanceof Email || $email->uid === null) {
			return false;
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		$plugin->transitionEmails->pruneForEmailId((int) $email->id);

		Craft::$app->getProjectConfig()->remove(self::CONFIG_EMAILS_KEY . '.' . $email->uid);

		return true;
	}

	/**
	 * @throws Throwable
	 */
	public function handleDeletedEmail(ConfigEvent $event): void
	{
		$emailUid = (string) ($event->tokenMatches[0] ?? '');
		if ($emailUid === '') {
			return;
		}

		$transaction = Craft::$app->getDb()->beginTransaction();

		try {
			$emailRecord = $this->getEmailRecord($emailUid);
			if ($emailRecord->id === null) {
				$transaction->commit();
				return;
			}

			$emailRecord->delete();
			$transaction->commit();
		} catch (Throwable $throwable) {
			$transaction->rollBack();
			throw $throwable;
		}

		$this->allEmails = null;
	}

	/**
	 * Renders + sends one shipment notification email.
	 *
	 * @throws Throwable
	 */
	public function sendForShipment(Email $email, ShipmentEmailContext $context, string &$error = ''): bool
	{
		if (! $email->enabled) {
			$error = Craft::t(Plugin::HANDLE, 'error.emailNotEnabled');
			return false;
		}

		$view = Craft::$app->getView();
		$originalTemplateMode = $view->getTemplateMode();
		$originalLanguage = Craft::$app->language;
		$originalFormattingLocale = Craft::$app->getFormattingLocale();

		try {
			$view->setTemplateMode($view::TEMPLATE_MODE_SITE);
			Locale::switchAppLanguage($email->getRenderLanguage($context->order));
			return $this->renderAndSend($email, $context, $error);
		} finally {
			$view->setTemplateMode($originalTemplateMode);
			Locale::switchAppLanguage($originalLanguage, $originalFormattingLocale->id);
		}
	}

	private function renderAndSend(Email $email, ShipmentEmailContext $context, string &$error): bool
	{
		$view = Craft::$app->getView();
		$shipment = $context->shipment;
		$order = $context->order;

		$renderVariables = [
			'shipment' => $shipment,
			'order' => $order,
			'fromCode' => $context->fromCode,
			'toCode' => $context->toCode,
			'statusHistory' => $context->history,
			'user' => $context->user,
			'message' => $context->message,
		];

		$mailer = Craft::$app->getMailer();
		/** @var Message $newEmail */
		$newEmail = $mailer->compose();

		$recipients = $this->resolveRecipients($email, $order, $view, $renderVariables, $error);
		if ($recipients === []) {
			return false;
		}

		$newEmail->setTo($recipients);

		$bccRaw = $email->getBcc();
		if ($bccRaw !== null && $bccRaw !== '') {
			try {
				$bccRendered = $view->renderString($bccRaw, $renderVariables);
				$bccAddresses = array_values(array_filter((array) preg_split('/[\s,;]+/', (string) $bccRendered)));
				if ($bccAddresses !== []) {
					$newEmail->setBcc($bccAddresses);
				}
			} catch (PhpException $phpException) {
				return $this->recordRenderError($email, 'BCC', $phpException, $error);
			}
		}

		$ccRaw = $email->getCc();
		if ($ccRaw !== null && $ccRaw !== '') {
			try {
				$ccRendered = $view->renderString($ccRaw, $renderVariables);
				$ccAddresses = array_values(array_filter((array) preg_split('/[\s,;]+/', (string) $ccRendered)));
				if ($ccAddresses !== []) {
					$newEmail->setCc($ccAddresses);
				}
			} catch (PhpException $phpException) {
				return $this->recordRenderError($email, 'CC', $phpException, $error);
			}
		}

		if ($email->replyTo !== null && $email->replyTo !== '') {
			try {
				$newEmail->setReplyTo($view->renderString($email->replyTo, $renderVariables));
			} catch (PhpException $phpException) {
				return $this->recordRenderError($email, 'Reply-To', $phpException, $error);
			}
		}

		try {
			$newEmail->setSubject($view->renderString((string) $email->subject, $renderVariables));
		} catch (PhpException $phpException) {
			return $this->recordRenderError($email, 'Subject', $phpException, $error);
		}

		$templatePath = (string) $email->templatePath;
		if ($templatePath === '' || ! $view->doesTemplateExist($templatePath)) {
			$error = Craft::t(Plugin::HANDLE, 'error.emailTemplateMissing', [
				'templatePath' => $templatePath,
				'email' => $email->name ?? '',
			]);
			Craft::error($error, Plugin::HANDLE);
			return false;
		}

		try {
			$newEmail->setHtmlBody($view->renderTemplate($templatePath, $renderVariables));
		} catch (PhpException $phpException) {
			return $this->recordRenderError($email, 'body', $phpException, $error);
		}

		if ($email->plainTextTemplatePath !== null && $email->plainTextTemplatePath !== '' && $view->doesTemplateExist($email->plainTextTemplatePath)) {
			try {
				$newEmail->setTextBody($view->renderTemplate($email->plainTextTemplatePath, $renderVariables));
			} catch (PhpException $phpException) {
				return $this->recordRenderError($email, 'plain-text body', $phpException, $error);
			}
		}

		if (! $mailer->send($newEmail)) {
			$error = Craft::t(Plugin::HANDLE, 'error.shipmentEmailNotSent', [
				'email' => $email->name ?? '',
				'reference' => $shipment->reference,
			]);
			Craft::error($error, Plugin::HANDLE);
			return false;
		}

		return true;
	}

	private function recordRenderError(Email $email, string $fieldLabel, PhpException $phpException, string &$error): bool
	{
		$error = Craft::t(Plugin::HANDLE, 'error.failedToRenderField', [
			'field' => $fieldLabel,
			'email' => $email->name ?? '',
			'message' => $phpException->getMessage(),
		]);
		Craft::error($error, Plugin::HANDLE);
		return false;
	}

	/**
	 * @param array<string, mixed> $renderVariables
	 * @return list<string>
	 */
	private function resolveRecipients(Email $email, Order $order, View $view, array $renderVariables, string &$error): array
	{
		if ($email->recipientType === EmailRecord::TYPE_CUSTOMER) {
			$customerEmail = $order->getEmail();
			if (! is_string($customerEmail) || $customerEmail === '') {
				$error = Craft::t(Plugin::HANDLE, 'error.noCustomerEmail', [
					'order' => $order->getShortNumber(),
				]);
				Craft::error($error, Plugin::HANDLE);
				return [];
			}

			return [$customerEmail];
		}

		$toRaw = $email->getTo();
		if ($toRaw === null || $toRaw === '') {
			$error = Craft::t(Plugin::HANDLE, 'error.emailNoRecipient', [
				'email' => $email->name ?? '',
			]);
			Craft::error($error, Plugin::HANDLE);
			return [];
		}

		try {
			$rendered = $view->renderString($toRaw, $renderVariables);
		} catch (PhpException $phpException) {
			$error = Craft::t(Plugin::HANDLE, 'error.failedToRenderTo', [
				'email' => $email->name ?? '',
				'message' => $phpException->getMessage(),
			]);
			Craft::error($error, Plugin::HANDLE);
			return [];
		}

		$addresses = [];
		foreach ((array) preg_split('/[\s,;]+/', $rendered) as $address) {
			if (is_string($address) && $address !== '') {
				$addresses[] = $address;
			}
		}

		return $addresses;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function modelFromRow(array $row): Email
	{
		$recipientTypeRaw = $row['recipientType'] ?? null;
		if (! is_string($recipientTypeRaw) || $recipientTypeRaw === '') {
			$row['recipientType'] = EmailRecord::TYPE_CUSTOMER;
		}

		$languageRaw = $row['language'] ?? null;
		if (! is_string($languageRaw) || $languageRaw === '') {
			$row['language'] = EmailRecord::LOCALE_ORDER_LANGUAGE;
		}

		$toRaw = $row['to'] ?? null;
		$bccRaw = $row['bcc'] ?? null;
		$ccRaw = $row['cc'] ?? null;
		unset($row['to'], $row['bcc'], $row['cc']);

		Typecast::properties(Email::class, $row);
		$email = new Email($row);
		$email->setTo(is_scalar($toRaw) ? (string) $toRaw : null);
		$email->setBcc(is_scalar($bccRaw) ? (string) $bccRaw : null);
		$email->setCc(is_scalar($ccRaw) ? (string) $ccRaw : null);

		return $email;
	}

	private function getEmailRecord(string $uid): EmailRecord
	{
		$emailRecord = EmailRecord::findOne([
			'uid' => $uid,
		]);

		return $emailRecord instanceof EmailRecord ? $emailRecord : new EmailRecord();
	}
}
