<?php

class AiOverviewPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME     = 'AI Overview',
		AUTHOR   = 'SaludPlus',
		URL      = 'https://saludplus.co/',
		VERSION  = '1.0',
		RELEASE  = '2026-01-17',
		REQUIRED = '2.35.0',
		CATEGORY = 'General',
		LICENSE  = 'MIT',
		DESCRIPTION = 'Muestra un resumen automático del hilo de correos usando IA, similar a Gmail';

	public function Init() : void
	{
		// Agregar endpoint personalizado para obtener resumen
		$this->addJsonHook('AiOverview', 'ServiceAiOverview');
		
		// Agregar archivos JS y CSS
		$this->addJs('ai-overview-ui.js');
		$this->addCss('ai-overview.css');
	}

	public function Supported() : string
	{
		return '';
	}

	protected function configMapping() : array
	{
		return array(
			\RainLoop\Plugins\Property::NewInstance('webhook_url')
				->SetLabel('Webhook URL')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::STRING)
				->SetDescription('URL del webhook para obtener el resumen de IA')
				->SetDefaultValue('https://workflow.saludplus.co/webhook/AI-Overview'),
			
			\RainLoop\Plugins\Property::NewInstance('min_messages')
				->SetLabel('Mensajes Mínimos')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::INT)
				->SetDescription('Número mínimo de mensajes en el hilo para mostrar el resumen (1 = siempre mostrar)')
				->SetDefaultValue(1),
			
			\RainLoop\Plugins\Property::NewInstance('enabled')
				->SetLabel('Activado')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::BOOL)
				->SetDescription('Activar/desactivar el resumen automático')
				->SetDefaultValue(true),
			
			\RainLoop\Plugins\Property::NewInstance('timeout')
				->SetLabel('Timeout (segundos)')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::INT)
				->SetDescription('Timeout para la petición al webhook')
				->SetDefaultValue(30)
		);
	}

	/**
	 * Servicio para obtener resumen de IA
	 */
	public function ServiceAiOverview() : array
	{
		$oActions = $this->Manager()->Actions();
		$oAccount = $oActions->getAccountFromToken(false);
		
		if (!$oAccount) {
			return $this->jsonResponse(__FUNCTION__, ['Error' => 'No autenticado']);
		}

		// Verificar si el plugin está activado
		if (!$this->Config()->Get('plugin', 'enabled', true)) {
			return $this->jsonResponse(__FUNCTION__, ['Error' => 'Plugin desactivado']);
		}

		// Obtener información del mensaje desde el frontend
		$sMessageData = $this->jsonParam('MessageData', '');
		
		if (empty($sMessageData)) {
			return $this->jsonResponse(__FUNCTION__, ['Error' => 'No se recibió información del mensaje']);
		}

		\SnappyMail\Log::info('AI_OVERVIEW', "Solicitando resumen - Datos recibidos: " . \strlen($sMessageData) . " caracteres");

		try {
			// La información del mensaje ya viene del frontend
			// Solo necesitamos enviarla al webhook de IA
			$sInformation = $sMessageData;

			if (empty($sInformation)) {
				\SnappyMail\Log::error('AI_OVERVIEW', "ERROR: Información del mensaje vacía");
				return $this->jsonResponse(__FUNCTION__, ['Error' => 'Información del mensaje vacía']);
			}

			\SnappyMail\Log::info('AI_OVERVIEW', "Información del mensaje recibida: " . \strlen($sInformation) . " caracteres");

			// Hacer petición al webhook
			$sSummary = $this->requestAiSummary($sInformation);

			if (empty($sSummary)) {
				\SnappyMail\Log::error('AI_OVERVIEW', "requestAiSummary retornó vacío");
				return $this->jsonResponse(__FUNCTION__, ['Error' => 'No se pudo obtener el resumen']);
			}

			\SnappyMail\Log::info('AI_OVERVIEW', "Resumen obtenido exitosamente. Longitud: " . \strlen($sSummary) . " caracteres");

			return $this->jsonResponse(__FUNCTION__, [
				'summary' => $sSummary,
				'messageCount' => 1 // Por ahora solo 1 mensaje
			]);

		} catch (\Throwable $e) {
			\SnappyMail\Log::error('AI_OVERVIEW', 'Error: ' . $e->getMessage());
			$this->Manager()->WriteException((string) $e, \LOG_ERR);
			return $this->jsonResponse(__FUNCTION__, ['Error' => 'Error al procesar: ' . $e->getMessage()]);
		}
	}


	/**
	 * Hacer petición al webhook de IA
	 */
	private function requestAiSummary($sInformation) : string
	{
		$sWebhookUrl = $this->Config()->Get('plugin', 'webhook_url', 'https://workflow.saludplus.co/webhook/AI-Overview');
		$iTimeout = (int) $this->Config()->Get('plugin', 'timeout', 30);

		\SnappyMail\Log::info('AI_OVERVIEW', "Enviando petición a: {$sWebhookUrl}");

		// Preparar el payload
		$aPayload = [
			'information' => $sInformation
		];
		$sJsonPayload = \json_encode($aPayload);

		// Hacer petición con cURL
		$ch = \curl_init($sWebhookUrl);
		\curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		\curl_setopt($ch, CURLOPT_POST, true);
		\curl_setopt($ch, CURLOPT_POSTFIELDS, $sJsonPayload);
		\curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json',
			'Content-Length: ' . \strlen($sJsonPayload),
			'api-key: tNTCfcApZ6w2WruLpOdWBDc5Zi577F1NaD0xyp7oAg3dvQfvEE'
		]);
		\curl_setopt($ch, CURLOPT_TIMEOUT, $iTimeout);
		\curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Para desarrollo, en producción debería ser true

		$sResponse = \curl_exec($ch);
		$iHttpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$sError = \curl_error($ch);
		\curl_close($ch);

		if ($sError) {
			\SnappyMail\Log::error('AI_OVERVIEW', "Error cURL: {$sError}");
			return '';
		}

		if ($iHttpCode !== 200) {
			\SnappyMail\Log::error('AI_OVERVIEW', "HTTP Code: {$iHttpCode}, Response: {$sResponse}");
			return '';
		}

		\SnappyMail\Log::info('AI_OVERVIEW', "Respuesta recibida: " . \strlen($sResponse) . " caracteres");
		\SnappyMail\Log::info('AI_OVERVIEW', "Respuesta raw (primeros 500 chars): " . \substr($sResponse, 0, 500));

		// Parsear respuesta
		$aResponse = \json_decode($sResponse, true);
		
		if (\json_last_error() !== JSON_ERROR_NONE) {
			\SnappyMail\Log::error('AI_OVERVIEW', "Error al parsear JSON: " . \json_last_error_msg());
			return '';
		}
		
		\SnappyMail\Log::info('AI_OVERVIEW', "Tipo de respuesta parseada: " . \gettype($aResponse));
		
		// Si la respuesta es un array, tomar el primer elemento
		if (\is_array($aResponse) && !empty($aResponse) && isset($aResponse[0])) {
			if (\is_array($aResponse[0])) {
				$aResponse = $aResponse[0];
				\SnappyMail\Log::info('AI_OVERVIEW', "Respuesta es array, usando primer elemento");
			} else {
				\SnappyMail\Log::info('AI_OVERVIEW', "Respuesta es array simple, primer elemento no es array");
			}
		}
		
		// Intentar diferentes estructuras de respuesta
		if (\is_array($aResponse) && isset($aResponse['output'])) {
			\SnappyMail\Log::info('AI_OVERVIEW', "Resumen encontrado en campo 'output'");
			return $aResponse['output'];
		} elseif (\is_array($aResponse) && isset($aResponse['summary'])) {
			\SnappyMail\Log::info('AI_OVERVIEW', "Resumen encontrado en campo 'summary'");
			return $aResponse['summary'];
		} elseif (\is_array($aResponse) && isset($aResponse['result'])) {
			\SnappyMail\Log::info('AI_OVERVIEW', "Resumen encontrado en campo 'result'");
			return $aResponse['result'];
		} elseif (\is_array($aResponse) && isset($aResponse['data'])) {
			\SnappyMail\Log::info('AI_OVERVIEW', "Resumen encontrado en campo 'data'");
			return $aResponse['data'];
		} elseif (\is_array($aResponse) && isset($aResponse['response'])) {
			\SnappyMail\Log::info('AI_OVERVIEW', "Resumen encontrado en campo 'response'");
			return $aResponse['response'];
		} else {
			// Si la respuesta es solo un string o no se encontró campo
			\SnappyMail\Log::warning('AI_OVERVIEW', "No se encontró campo de resumen. Tipo: " . \gettype($aResponse) . ", Keys: " . (\is_array($aResponse) ? \implode(', ', \array_keys($aResponse)) : 'N/A'));
			\SnappyMail\Log::warning('AI_OVERVIEW', "Retornando respuesta completa");
			return $sResponse;
		}
	}

}
