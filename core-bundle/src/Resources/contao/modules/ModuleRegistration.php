<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao;

use Patchwork\Utf8;

/**
 * Front end module "registration".
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class ModuleRegistration extends \Module
{

	/**
	 * Template
	 * @var string
	 */
	protected $strTemplate = 'member_default';

	/**
	 * Display a wildcard in the back end
	 *
	 * @return string
	 */
	public function generate()
	{
		if (TL_MODE == 'BE')
		{
			/** @var BackendTemplate|object $objTemplate */
			$objTemplate = new \BackendTemplate('be_wildcard');

			$objTemplate->wildcard = '### ' . Utf8::strtoupper($GLOBALS['TL_LANG']['FMD']['registration'][0]) . ' ###';
			$objTemplate->title = $this->headline;
			$objTemplate->id = $this->id;
			$objTemplate->link = $this->name;
			$objTemplate->href = 'contao/main.php?do=themes&amp;table=tl_module&amp;act=edit&amp;id=' . $this->id;

			return $objTemplate->parse();
		}

		$this->editable = \StringUtil::deserialize($this->editable);

		// Return if there are no editable fields
		if (empty($this->editable) || !\is_array($this->editable))
		{
			return '';
		}

		return parent::generate();
	}

	/**
	 * Generate the module
	 */
	protected function compile()
	{
		/** @var PageModel $objPage */
		global $objPage;

		$GLOBALS['TL_LANGUAGE'] = $objPage->language;

		\System::loadLanguageFile('tl_member');
		$this->loadDataContainer('tl_member');

		// Call onload_callback (e.g. to check permissions)
		if (\is_array($GLOBALS['TL_DCA']['tl_member']['config']['onload_callback']))
		{
			foreach ($GLOBALS['TL_DCA']['tl_member']['config']['onload_callback'] as $callback)
			{
				if (\is_array($callback))
				{
					$this->import($callback[0]);
					$this->{$callback[0]}->{$callback[1]}();
				}
				elseif (\is_callable($callback))
				{
					$callback();
				}
			}
		}

		// Activate account
		if (strncmp(\Input::get('token'), 'RG', 2) === 0)
		{
			$this->activateAcount();

			return;
		}

		if ($this->memberTpl != '')
		{
			/** @var FrontendTemplate|object $objTemplate */
			$objTemplate = new \FrontendTemplate($this->memberTpl);

			$this->Template = $objTemplate;
			$this->Template->setData($this->arrData);
		}

		$this->Template->fields = '';

		$objCaptcha = null;
		$doNotSubmit = false;
		$strFormId = 'tl_registration_' . $this->id;

		// Predefine the group order (other groups will be appended automatically)
		$arrGroups = array
		(
			'personal' => array(),
			'address'  => array(),
			'contact'  => array(),
			'login'    => array(),
			'profile'  => array()
		);

		// Captcha
		if (!$this->disableCaptcha)
		{
			$arrCaptcha = array
			(
				'id' => 'registration',
				'label' => $GLOBALS['TL_LANG']['MSC']['securityQuestion'],
				'type' => 'captcha',
				'mandatory' => true,
				'required' => true
			);

			/** @var FormCaptcha $strClass */
			$strClass = $GLOBALS['TL_FFL']['captcha'];

			// Fallback to default if the class is not defined
			if (!class_exists($strClass))
			{
				$strClass = 'FormCaptcha';
			}

			/** @var FormCaptcha $objCaptcha */
			$objCaptcha = new $strClass($arrCaptcha);

			if (\Input::post('FORM_SUBMIT') == $strFormId)
			{
				$objCaptcha->validate();

				if ($objCaptcha->hasErrors())
				{
					$doNotSubmit = true;
				}
			}
		}

		$objMember = null;

		// Check for a follow-up registration (see #7992)
		if ($this->reg_activate && \Input::post('email', true) != '' && ($objMember = \MemberModel::findUnactivatedByEmail(\Input::post('email', true))) !== null)
		{
			$this->resendActivationMail($objMember);

			return;
		}

		$arrUser = array();
		$arrFields = array();
		$hasUpload = false;
		$i = 0;

		// Build form
		foreach ($this->editable as $field)
		{
			$arrData = $GLOBALS['TL_DCA']['tl_member']['fields'][$field];

			// Map checkboxWizards to regular checkbox widgets
			if ($arrData['inputType'] == 'checkboxWizard')
			{
				$arrData['inputType'] = 'checkbox';
			}

			// Map fileTrees to upload widgets (see #8091)
			if ($arrData['inputType'] == 'fileTree')
			{
				$arrData['inputType'] = 'upload';
			}

			/** @var Widget $strClass */
			$strClass = $GLOBALS['TL_FFL'][$arrData['inputType']];

			// Continue if the class is not defined
			if (!class_exists($strClass))
			{
				continue;
			}

			$arrData['eval']['required'] = $arrData['eval']['mandatory'];

			// Unset the unique field check upon follow-up registrations
			if ($objMember !== null && $arrData['eval']['unique'] && \Input::post($field) == $objMember->$field)
			{
				$arrData['eval']['unique'] = false;
			}

			$objWidget = new $strClass($strClass::getAttributesFromDca($arrData, $field, $arrData['default'], '', '', $this));

			$objWidget->storeValues = true;
			$objWidget->rowClass = 'row_' . $i . (($i == 0) ? ' row_first' : '') . ((($i % 2) == 0) ? ' even' : ' odd');

			// Increase the row count if its a password field
			if ($objWidget instanceof FormPassword)
			{
				$objWidget->rowClassConfirm = 'row_' . ++$i . ((($i % 2) == 0) ? ' even' : ' odd');
			}

			// Validate input
			if (\Input::post('FORM_SUBMIT') == $strFormId)
			{
				$objWidget->validate();
				$varValue = $objWidget->value;

				// Check whether the password matches the username
				if ($objWidget instanceof FormPassword && password_verify(\Input::post('username'), $varValue))
				{
					$objWidget->addError($GLOBALS['TL_LANG']['ERR']['passwordName']);
				}

				$rgxp = $arrData['eval']['rgxp'];

				// Convert date formats into timestamps (check the eval setting first -> #3063)
				if ($varValue != '' && \in_array($rgxp, array('date', 'time', 'datim')))
				{
					try
					{
						$objDate = new \Date($varValue, \Date::getFormatFromRgxp($rgxp));
						$varValue = $objDate->tstamp;
					}
					catch (\OutOfBoundsException $e)
					{
						$objWidget->addError(sprintf($GLOBALS['TL_LANG']['ERR']['invalidDate'], $varValue));
					}
				}

				// Make sure that unique fields are unique (check the eval setting first -> #3063)
				if ($arrData['eval']['unique'] && $varValue != '' && !$this->Database->isUniqueValue('tl_member', $field, $varValue))
				{
					$objWidget->addError(sprintf($GLOBALS['TL_LANG']['ERR']['unique'], $arrData['label'][0] ?: $field));
				}

				// Save callback
				if ($objWidget->submitInput() && !$objWidget->hasErrors() && \is_array($arrData['save_callback']))
				{
					foreach ($arrData['save_callback'] as $callback)
					{
						try
						{
							if (\is_array($callback))
							{
								$this->import($callback[0]);
								$varValue = $this->{$callback[0]}->{$callback[1]}($varValue, null);
							}
							elseif (\is_callable($callback))
							{
								$varValue = $callback($varValue, null);
							}
						}
						catch (\Exception $e)
						{
							$objWidget->class = 'error';
							$objWidget->addError($e->getMessage());
						}
					}
				}

				// Store the current value
				if ($objWidget->hasErrors())
				{
					$doNotSubmit = true;
				}
				elseif ($objWidget->submitInput())
				{
					// Set the correct empty value (see #6284, #6373)
					if ($varValue === '')
					{
						$varValue = $objWidget->getEmptyValue();
					}

					// Encrypt the value (see #7815)
					if ($arrData['eval']['encrypt'])
					{
						$varValue = \Encryption::encrypt($varValue);
					}

					// Set the new value
					$arrUser[$field] = $varValue;
				}
			}

			if ($objWidget instanceof \uploadable)
			{
				$hasUpload = true;
			}

			$temp = $objWidget->parse();

			$this->Template->fields .= $temp;
			$arrFields[$arrData['eval']['feGroup']][$field] .= $temp;

			++$i;
		}

		// Captcha
		if (!$this->disableCaptcha)
		{
			$objCaptcha->rowClass = 'row_'.$i . (($i == 0) ? ' row_first' : '') . ((($i % 2) == 0) ? ' even' : ' odd');
			$strCaptcha = $objCaptcha->parse();

			$this->Template->fields .= $strCaptcha;
			$arrFields['captcha']['captcha'] .= $strCaptcha;
		}

		$this->Template->rowLast = 'row_' . ++$i . ((($i % 2) == 0) ? ' even' : ' odd');
		$this->Template->enctype = $hasUpload ? 'multipart/form-data' : 'application/x-www-form-urlencoded';
		$this->Template->hasError = $doNotSubmit;

		// Create new user if there are no errors
		if (\Input::post('FORM_SUBMIT') == $strFormId && !$doNotSubmit)
		{
			$this->createNewUser($arrUser);
		}

		$this->Template->loginDetails = $GLOBALS['TL_LANG']['tl_member']['loginDetails'];
		$this->Template->addressDetails = $GLOBALS['TL_LANG']['tl_member']['addressDetails'];
		$this->Template->contactDetails = $GLOBALS['TL_LANG']['tl_member']['contactDetails'];
		$this->Template->personalData = $GLOBALS['TL_LANG']['tl_member']['personalData'];
		$this->Template->captchaDetails = $GLOBALS['TL_LANG']['MSC']['securityQuestion'];

		// Add the groups
		foreach ($arrFields as $k=>$v)
		{
			// Deprecated since Contao 4.0, to be removed in Contao 5.0
			$this->Template->$k = $v;

			$key = $k . (($k == 'personal') ? 'Data' : 'Details');
			$arrGroups[$GLOBALS['TL_LANG']['tl_member'][$key]] = $v;
		}

		$this->Template->categories = $arrGroups;
		$this->Template->formId = $strFormId;
		$this->Template->slabel = \StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['register']);
		$this->Template->action = \Environment::get('indexFreeRequest');

		// Deprecated since Contao 4.0, to be removed in Contao 5.0
		$this->Template->captcha = $arrFields['captcha']['captcha'];
	}

	/**
	 * Create a new user and redirect
	 *
	 * @param array $arrData
	 */
	protected function createNewUser($arrData)
	{
		$arrData['tstamp'] = time();
		$arrData['login'] = $this->reg_allowLogin;
		$arrData['activation'] = 'RG' . substr(md5(uniqid(mt_rand(), true)), 2);
		$arrData['dateAdded'] = $arrData['tstamp'];

		// Set default groups
		if (!array_key_exists('groups', $arrData))
		{
			$arrData['groups'] = $this->reg_groups;
		}

		// Disable account
		$arrData['disable'] = 1;

		// Send activation e-mail
		if ($this->reg_activate)
		{
			$this->sendActivationMail($arrData);
		}

		// Make sure newsletter is an array
		if (isset($arrData['newsletter']) && !\is_array($arrData['newsletter']))
		{
			$arrData['newsletter'] = array($arrData['newsletter']);
		}

		// Create the user
		$objNewUser = new \MemberModel();
		$objNewUser->setRow($arrData);
		$objNewUser->save();

		// Assign home directory
		if ($this->reg_assignDir)
		{
			$objHomeDir = \FilesModel::findByUuid($this->reg_homeDir);

			if ($objHomeDir !== null)
			{
				$this->import('Files');
				$strUserDir = \StringUtil::standardize($arrData['username']) ?: 'user_' . $objNewUser->id;

				// Add the user ID if the directory exists
				while (is_dir(TL_ROOT . '/' . $objHomeDir->path . '/' . $strUserDir))
				{
					$strUserDir .= '_' . $objNewUser->id;
				}

				// Create the user folder
				new \Folder($objHomeDir->path . '/' . $strUserDir);

				$objUserDir = \FilesModel::findByPath($objHomeDir->path . '/' . $strUserDir);

				// Save the folder ID
				$objNewUser->assignDir = 1;
				$objNewUser->homeDir = $objUserDir->uuid;
				$objNewUser->save();
			}
		}

		// HOOK: send insert ID and user data
		if (isset($GLOBALS['TL_HOOKS']['createNewUser']) && \is_array($GLOBALS['TL_HOOKS']['createNewUser']))
		{
			foreach ($GLOBALS['TL_HOOKS']['createNewUser'] as $callback)
			{
				$this->import($callback[0]);
				$this->{$callback[0]}->{$callback[1]}($objNewUser->id, $arrData, $this);
			}
		}

		// Create the initial version (see #7816)
		$objVersions = new \Versions('tl_member', $objNewUser->id);
		$objVersions->setUsername($objNewUser->username);
		$objVersions->setUserId(0);
		$objVersions->setEditUrl('contao/main.php?do=member&act=edit&id=%s&rt=1');
		$objVersions->initialize();

		// Inform admin if no activation link is sent
		if (!$this->reg_activate)
		{
			$this->sendAdminNotification($objNewUser->id, $arrData);
		}

		// Check whether there is a jumpTo page
		if (($objJumpTo = $this->objModel->getRelated('jumpTo')) instanceof PageModel)
		{
			$this->jumpToOrReload($objJumpTo->row());
		}

		$this->reload();
	}

	/**
	 * Send the activation mail
	 *
	 * @param array $arrData
	 */
	protected function sendActivationMail($arrData)
	{
		// Prepare the simple token data
		$arrTokenData = $arrData;
		$arrTokenData['domain'] = \Idna::decode(\Environment::get('host'));
		$arrTokenData['link'] = \Idna::decode(\Environment::get('base')) . \Environment::get('request') . ((strpos(\Environment::get('request'), '?') !== false) ? '&' : '?') . 'token=' . $arrData['activation'];
		$arrTokenData['channels'] = '';

		$bundles = \System::getContainer()->getParameter('kernel.bundles');

		if (isset($bundles['ContaoNewsletterBundle']))
		{
			// Make sure newsletter is an array
			if (!\is_array($arrData['newsletter']))
			{
				if ($arrData['newsletter'] != '')
				{
					$arrData['newsletter'] = array($arrData['newsletter']);
				}
				else
				{
					$arrData['newsletter'] = array();
				}
			}

			// Replace the wildcard
			if (!empty($arrData['newsletter']))
			{
				$objChannels = \NewsletterChannelModel::findByIds($arrData['newsletter']);

				if ($objChannels !== null)
				{
					$arrTokenData['channels'] = implode("\n", $objChannels->fetchEach('title'));
				}
			}
		}

		// Deprecated since Contao 4.0, to be removed in Contao 5.0
		$arrTokenData['channel'] = $arrTokenData['channels'];

		$objEmail = new \Email();

		$objEmail->from = $GLOBALS['TL_ADMIN_EMAIL'];
		$objEmail->fromName = $GLOBALS['TL_ADMIN_NAME'];
		$objEmail->subject = sprintf($GLOBALS['TL_LANG']['MSC']['emailSubject'], \Idna::decode(\Environment::get('host')));
		$objEmail->text = \StringUtil::parseSimpleTokens($this->reg_text, $arrTokenData);
		$objEmail->sendTo($arrData['email']);
	}

	/**
	 * Activate an account
	 */
	protected function activateAcount()
	{
		$this->strTemplate = 'mod_message';

		/** @var FrontendTemplate|object $objTemplate */
		$objTemplate = new \FrontendTemplate($this->strTemplate);

		$this->Template = $objTemplate;

		$objMember = \MemberModel::findOneByActivation(\Input::get('token'));

		if ($objMember === null)
		{
			$this->Template->type = 'error';
			$this->Template->message = $GLOBALS['TL_LANG']['MSC']['accountError'];

			return;
		}

		// Update the account
		$objMember->disable = '';
		$objMember->activation = '';
		$objMember->save();

		// HOOK: post activation callback
		if (isset($GLOBALS['TL_HOOKS']['activateAccount']) && \is_array($GLOBALS['TL_HOOKS']['activateAccount']))
		{
			foreach ($GLOBALS['TL_HOOKS']['activateAccount'] as $callback)
			{
				$this->import($callback[0]);
				$this->{$callback[0]}->{$callback[1]}($objMember, $this);
			}
		}

		// Log activity
		$this->log('User account ID ' . $objMember->id . ' (' . \Idna::decodeEmail($objMember->email) . ') has been activated', __METHOD__, TL_ACCESS);

		// Redirect to the jumpTo page
		if (($objTarget = $this->objModel->getRelated('reg_jumpTo')) instanceof PageModel)
		{
			/** @var PageModel $objTarget */
			$this->redirect($objTarget->getFrontendUrl());
		}

		// Confirm activation
		$this->Template->type = 'confirm';
		$this->Template->message = $GLOBALS['TL_LANG']['MSC']['accountActivated'];
	}

	/**
	 * Re-send the activation mail
	 *
	 * @param MemberModel $objMember
	 */
	protected function resendActivationMail(MemberModel $objMember)
	{
		if ($objMember->disable == '')
		{
			return;
		}

		$this->strTemplate = 'mod_message';

		/** @var FrontendTemplate|object $objTemplate */
		$objTemplate = new \FrontendTemplate($this->strTemplate);

		$this->Template = $objTemplate;

		$this->sendActivationMail($objMember->row());

		// Confirm activation
		$this->Template->type = 'confirm';
		$this->Template->message = $GLOBALS['TL_LANG']['MSC']['resendActivation'];
	}

	/**
	 * Send an admin notification e-mail
	 *
	 * @param integer $intId
	 * @param array   $arrData
	 */
	protected function sendAdminNotification($intId, $arrData)
	{
		$objEmail = new \Email();

		$objEmail->from = $GLOBALS['TL_ADMIN_EMAIL'];
		$objEmail->fromName = $GLOBALS['TL_ADMIN_NAME'];
		$objEmail->subject = sprintf($GLOBALS['TL_LANG']['MSC']['adminSubject'], \Idna::decode(\Environment::get('host')));

		$strData = "\n\n";

		// Add user details
		foreach ($arrData as $k=>$v)
		{
			if ($k == 'password' || $k == 'tstamp' || $k == 'activation' || $k == 'dateAdded')
			{
				continue;
			}

			$v = \StringUtil::deserialize($v);

			if ($k == 'dateOfBirth' && \strlen($v))
			{
				$v = \Date::parse(\Config::get('dateFormat'), $v);
			}

			$strData .= $GLOBALS['TL_LANG']['tl_member'][$k][0] . ': ' . (\is_array($v) ? implode(', ', $v) : $v) . "\n";
		}

		$objEmail->text = sprintf($GLOBALS['TL_LANG']['MSC']['adminText'], $intId, $strData . "\n") . "\n";
		$objEmail->sendTo($GLOBALS['TL_ADMIN_EMAIL']);

		$this->log('A new user (ID ' . $intId . ') has registered on the website', __METHOD__, TL_ACCESS);
	}
}
