<?php

class MarkExternalPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME     = 'Mark External Emails',
		AUTHOR   = 'SnappyMail',
		URL      = 'https://snappymail.eu/',
		VERSION  = '1.0',
		RELEASE  = '2026-01-17',
		REQUIRED = '2.35.0',
		CATEGORY = 'General',
		LICENSE  = 'MIT',
		DESCRIPTION = 'Marca automáticamente los correos externos con un tag "External" al abrirlos';

	public function Init() : void
	{
		$this->addHook('filter.result-message', 'OnMessageOpen');
		$this->addCss('external-label.css');
		$this->addJs('mark-external-ui.js');
	}

	public function Supported() : string
	{
		return '';
	}

	protected function configMapping() : array
	{
		return array(
			\RainLoop\Plugins\Property::NewInstance('internal_domains')
				->SetLabel('Dominios Internos')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::STRING_TEXT)
				->SetDescription('Lista de dominios internos separados por coma (ej: saludplus.co,example.com)')
				->SetDefaultValue('saludplus.co')
		);
	}

	/**
	 * Al abrir un correo: si el remitente es externo, se marca con $external.
	 */
	public function OnMessageOpen($oMessage)
	{
		\SnappyMail\Log::info('MARK_EXTERNAL', '=== INICIANDO VERIFICACION ===');
		
		$oFrom = $oMessage->From();
		if (!$oFrom || \count($oFrom) < 1) {
			\SnappyMail\Log::warning('MARK_EXTERNAL', 'No hay remitente');
			return;
		}

		$oEmail = null;
		foreach ($oFrom as $oEmail) {
			break;
		}
		if (!$oEmail) {
			\SnappyMail\Log::warning('MARK_EXTERNAL', 'No se pudo obtener el email del remitente');
			return;
		}

		$sFromEmail = $oEmail->GetEmail();
		$sFromDomain = \strtolower(\MailSo\Base\Utils::getEmailAddressDomain($sFromEmail));
		\SnappyMail\Log::info('MARK_EXTERNAL', "Remitente: {$sFromEmail}, Dominio: {$sFromDomain}");
		
		if (empty($sFromDomain)) {
			\SnappyMail\Log::warning('MARK_EXTERNAL', 'Dominio vacio');
			return;
		}

		$sInternal = \trim($this->Config()->Get('plugin', 'internal_domains', ''));
		\SnappyMail\Log::info('MARK_EXTERNAL', "Dominios internos configurados: {$sInternal}");
		
		if (empty($sInternal)) {
			\SnappyMail\Log::warning('MARK_EXTERNAL', 'No hay dominios internos configurados');
			return;
		}

		$aInternal = \array_map('strtolower', \array_map('trim', \explode(',', $sInternal)));
		$oAccount = $this->Manager()->Actions()->getAccountFromToken(false);
		if ($oAccount) {
			$sUserDom = \strtolower(\MailSo\Base\Utils::getEmailAddressDomain($oAccount->Email()));
			if ($sUserDom && !\in_array($sUserDom, $aInternal)) {
				$aInternal[] = $sUserDom;
			}
		}
		\SnappyMail\Log::info('MARK_EXTERNAL', 'Dominios internos finales: ' . \implode(', ', $aInternal));

		if (\in_array($sFromDomain, $aInternal)) {
			\SnappyMail\Log::info('MARK_EXTERNAL', "Correo INTERNO - NO se marca: {$sFromEmail}");
			return; // interno, no hacer nada
		}

		\SnappyMail\Log::info('MARK_EXTERNAL', ">>> CORREO EXTERNO DETECTADO: {$sFromEmail} <<<");

		$sFolder = $oMessage->sFolder;
		$iUid = $oMessage->Uid();
		\SnappyMail\Log::info('MARK_EXTERNAL', "Carpeta: {$sFolder}, UID: {$iUid}");
		
		if (!$sFolder || $iUid < 1) {
			\SnappyMail\Log::warning('MARK_EXTERNAL', 'Carpeta o UID invalido');
			return;
		}

		// Si ya tiene $external, no repetir
		$ref = new \ReflectionClass($oMessage);
		$prop = $ref->getProperty('aFlagsLowerCase');
		$prop->setAccessible(true);
		$arr = $prop->getValue($oMessage);
		\SnappyMail\Log::info('MARK_EXTERNAL', 'Flags actuales: ' . \implode(', ', $arr));
		
		if (\in_array('$external', $arr)) {
			\SnappyMail\Log::info('MARK_EXTERNAL', 'Ya tiene flag $external');
			return;
		}

		try {
			$this->Manager()->Actions()->MailClient()->MessageSetFlag(
				$sFolder,
				new \MailSo\Imap\SequenceSet([$iUid]),
				'$external',
				true,
				true
			);
			\SnappyMail\Log::info('MARK_EXTERNAL', 'Flag $external añadido en servidor IMAP');
			
			$arr[] = '$external';
			$prop->setValue($oMessage, $arr);
			\SnappyMail\Log::info('MARK_EXTERNAL', 'Flag $external añadido a aFlagsLowerCase del mensaje');
			\SnappyMail\Log::info('MARK_EXTERNAL', 'Flags finales: ' . \implode(', ', $arr));
		} catch (\Throwable $e) {
			\SnappyMail\Log::error('MARK_EXTERNAL', 'Error: ' . $e->getMessage());
			$this->Manager()->WriteException((string) $e, \LOG_ERR);
		}
	}
}
