<?php

declare(strict_types=1);

namespace fostercommerce\shipments\controllers;

use Craft;
use craft\web\Controller;
use fostercommerce\shipments\base\ControllerBodyParamsTrait;
use fostercommerce\shipments\enums\Status;
use fostercommerce\shipments\models\Email;
use fostercommerce\shipments\Plugin;
use fostercommerce\shipments\records\Email as EmailRecord;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * CP CRUD for shipment notification emails. Writes go through project config.
 */
class EmailsController extends Controller
{
	use ControllerBodyParamsTrait;

	public function actionIndex(): Response
	{
		$this->requirePermission(Plugin::PERMISSION_MANAGE_EMAILS);

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		return $this->renderTemplate(Plugin::HANDLE . '/settings/emails/index', [
			'emails' => $plugin->emails->getAllEmails(),
		]);
	}

	/**
	 * @throws NotFoundHttpException
	 */
	public function actionEdit(?int $id = null, ?Email $email = null): Response
	{
		$this->requirePermission(Plugin::PERMISSION_MANAGE_EMAILS);

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		if (! $email instanceof Email) {
			if ($id !== null) {
				$loaded = $plugin->emails->getEmailById($id);
				if (! $loaded instanceof Email) {
					throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'error.emailNotFound'));
				}

				$email = $loaded;
			} else {
				$email = new Email();
			}
		}

		$statusBindings = $email->id !== null
			? $plugin->transitionEmails->findBindingsForEmailId($email->id)
			: [];

		return $this->renderTemplate(Plugin::HANDLE . '/settings/emails/_edit', [
			'email' => $email,
			'recipientTypes' => [
				EmailRecord::TYPE_CUSTOMER => Craft::t(Plugin::HANDLE, 'emails.recipientType.customer'),
				EmailRecord::TYPE_CUSTOM => Craft::t(Plugin::HANDLE, 'emails.recipientType.custom'),
			],
			'statusOptions' => Status::labelMap(),
			'statusBindings' => $statusBindings,
			'title' => $email->id === null
				? Craft::t(Plugin::HANDLE, 'emails.createNew')
				: (string) $email->name,
		]);
	}

	/**
	 * @throws BadRequestHttpException
	 */
	public function actionSave(): ?Response
	{
		$this->requirePostRequest();
		$this->requirePermission(Plugin::PERMISSION_MANAGE_EMAILS);

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$idInput = $this->request->getBodyParam('id');
		if ($idInput !== null && $idInput !== '' && ! is_numeric($idInput)) {
			throw new BadRequestHttpException(Craft::t(Plugin::HANDLE, 'error.invalidEmailId'));
		}

		$existingId = is_numeric($idInput) ? (int) $idInput : null;

		if ($existingId !== null) {
			$existing = $plugin->emails->getEmailById($existingId);
			$email = $existing instanceof Email ? $existing : new Email();
		} else {
			$email = new Email();
		}

		$email->name = $this->bodyString('name') ?? (string) $email->name;
		$email->subject = $this->bodyString('subject') ?? (string) $email->subject;
		$email->recipientType = $this->bodyString('recipientType') ?? $email->recipientType;
		$email->setTo($this->bodyString('to'));
		$email->setBcc($this->bodyString('bcc'));
		$email->setCc($this->bodyString('cc'));

		$email->replyTo = $this->bodyString('replyTo');
		$email->enabled = (bool) $this->request->getBodyParam('enabled', $email->enabled);
		$email->templatePath = $this->bodyString('templatePath') ?? (string) $email->templatePath;
		$email->plainTextTemplatePath = $this->bodyString('plainTextTemplatePath');
		$email->language = $this->bodyString('language') ?? $email->language;

		if (! $plugin->emails->saveEmail($email)) {
			Craft::$app->getSession()->setError(Craft::t(Plugin::HANDLE, 'error.couldNotSaveEmail'));
			Craft::$app->getUrlManager()->setRouteParams([
				'email' => $email,
			]);
			return null;
		}

		$statusRaw = $this->request->getBodyParam('statusBindings', []);
		$statusCodes = is_array($statusRaw) ? array_values(array_filter($statusRaw, 'is_string')) : [];

		if ($email->id !== null) {
			$plugin->transitionEmails->saveBindingsForEmailId($email->id, $statusCodes);
		}

		Craft::$app->getSession()->setNotice(Craft::t(Plugin::HANDLE, 'emails.saved'));
		return $this->redirectToPostedUrl($email);
	}

	/**
	 * @throws BadRequestHttpException
	 */
	public function actionDelete(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();
		$this->requirePermission(Plugin::PERMISSION_MANAGE_EMAILS);

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$idInput = $this->request->getRequiredBodyParam('id');
		if (! is_numeric($idInput)) {
			throw new BadRequestHttpException(Craft::t(Plugin::HANDLE, 'error.invalidEmailId'));
		}

		$id = (int) $idInput;

		if (! $plugin->emails->deleteEmailById($id)) {
			return $this->asJson([
				'success' => false,
				'error' => Craft::t(Plugin::HANDLE, 'error.emailNotDeleted'),
			]);
		}

		return $this->asJson([
			'success' => true,
		]);
	}
}
