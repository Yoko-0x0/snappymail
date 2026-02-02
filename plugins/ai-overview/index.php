<?php

use RainLoop\Providers\Storage\Enumerations\StorageType;

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
				->SetDefaultValue(30),
			
			\RainLoop\Plugins\Property::NewInstance('use_cache')
				->SetLabel('Usar Caché')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::BOOL)
				->SetDescription('Activar/desactivar el uso de caché para los resúmenes. Si está desactivado, siempre se llamará al webhook.')
				->SetDefaultValue(true),
			
			\RainLoop\Plugins\Property::NewInstance('debug')
				->SetLabel('Debug')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::BOOL)
				->SetDescription('Activar logs de depuración')
				->SetDefaultValue(false)
		);
	}

	/**
	 * Verificar si debug está activado
	 */
	private function isDebugEnabled() : bool
	{
		return (bool) $this->Config()->Get('plugin', 'debug', false);
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
		$sMessageId = $this->jsonParam('MessageId', '');
		$iThreadCount = (int) $this->jsonParam('ThreadCount', 1);
		
		if (empty($sMessageData)) {
			return $this->jsonResponse(__FUNCTION__, ['Error' => 'No se recibió información del mensaje']);
		}

		if ($this->isDebugEnabled()) {
			\SnappyMail\Log::info('AI_OVERVIEW', "Solicitando resumen - MessageId: {$sMessageId}, ThreadCount: {$iThreadCount}, Datos: " . \strlen($sMessageData) . " caracteres");
		}

		try {
			// La información del mensaje ya viene del frontend
			// Solo necesitamos enviarla al webhook de IA
			$sInformation = $sMessageData;

			if (empty($sInformation)) {
				if ($this->isDebugEnabled()) {
					\SnappyMail\Log::error('AI_OVERVIEW', "ERROR: Información del mensaje vacía");
				}
				return $this->jsonResponse(__FUNCTION__, ['Error' => 'Información del mensaje vacía']);
			}

			if ($this->isDebugEnabled()) {
				\SnappyMail\Log::info('AI_OVERVIEW', "Información del mensaje recibida: " . \strlen($sInformation) . " caracteres");
			}

			// Verificar si el uso de caché está habilitado en la configuración
			$bCacheEnabled = (bool) $this->Config()->Get('plugin', 'use_cache', true);
			
			// Generar clave única para la cache basada en MessageId y email del usuario
			// Solo usar cache si está habilitado Y MessageId no está vacío
			$bUseCache = $bCacheEnabled && !empty($sMessageId);
			$sSummary = '';
			$bFromCache = false;
			
			if ($bUseCache) {
				$sCacheKey = $this->getCacheKey($oAccount, $sMessageId);
				
				// Intentar obtener desde cache primero
				$sSummary = $this->getCachedSummary($oAccount, $sCacheKey);
				$bFromCache = !empty($sSummary);
				
				if ($bFromCache) {
					if ($this->isDebugEnabled()) {
						\SnappyMail\Log::info('AI_OVERVIEW', "Resumen obtenido desde cache - MessageId: {$sMessageId}");
					}
				} else {
					if ($this->isDebugEnabled()) {
						\SnappyMail\Log::info('AI_OVERVIEW', "No se encontró en cache - MessageId: {$sMessageId}, llamando al webhook");
					}
				}
			} else {
				if ($this->isDebugEnabled()) {
					if (!$bCacheEnabled) {
						\SnappyMail\Log::info('AI_OVERVIEW', "Caché deshabilitada en configuración, llamando directamente al webhook");
					} else {
						\SnappyMail\Log::info('AI_OVERVIEW', "MessageId vacío, no se usará cache, llamando directamente al webhook");
					}
				}
			}
			
			// Si no está en cache o caché está deshabilitada o MessageId está vacío, hacer petición al webhook
			if (!$bFromCache) {
				$sSummary = $this->requestAiSummary($sInformation, $sMessageId, $iThreadCount);

				if (empty($sSummary)) {
					if ($this->isDebugEnabled()) {
						\SnappyMail\Log::error('AI_OVERVIEW', "requestAiSummary retornó vacío");
					}
					return $this->jsonResponse(__FUNCTION__, ['Error' => 'No se pudo obtener el resumen']);
				}

				// Guardar en cache solo si:
				// 1. La caché está habilitada en configuración
				// 2. MessageId no está vacío
				// 3. Se obtuvo exitosamente el resumen
				if ($bUseCache && !empty($sMessageId)) {
					$sCacheKey = $this->getCacheKey($oAccount, $sMessageId);
					$this->saveCachedSummary($oAccount, $sCacheKey, $sSummary);
					
					if ($this->isDebugEnabled()) {
						\SnappyMail\Log::info('AI_OVERVIEW', "Resumen obtenido exitosamente y guardado en cache. Longitud: " . \strlen($sSummary) . " caracteres");
					}
				} else {
					if ($this->isDebugEnabled()) {
						if (!$bCacheEnabled) {
							\SnappyMail\Log::info('AI_OVERVIEW', "Resumen obtenido exitosamente pero NO guardado en cache (caché deshabilitada). Longitud: " . \strlen($sSummary) . " caracteres");
						} else {
							\SnappyMail\Log::info('AI_OVERVIEW', "Resumen obtenido exitosamente pero NO guardado en cache (MessageId vacío). Longitud: " . \strlen($sSummary) . " caracteres");
						}
					}
				}
			}

			return $this->jsonResponse(__FUNCTION__, [
				'summary' => $sSummary,
				'messageCount' => 1, // Por ahora solo 1 mensaje
				'fromCache' => $bFromCache
			]);

		} catch (\Throwable $e) {
			if ($this->isDebugEnabled()) {
				\SnappyMail\Log::error('AI_OVERVIEW', 'Error: ' . $e->getMessage());
			}
			$this->Manager()->WriteException((string) $e, \LOG_ERR);
			return $this->jsonResponse(__FUNCTION__, ['Error' => 'Error al procesar: ' . $e->getMessage()]);
		}
	}


	/**
	 * Hacer petición al webhook de IA
	 */
	private function requestAiSummary($sInformation, $sMessageId = '', $iThreadCount = 1) : string
	{
		$sWebhookUrl = $this->Config()->Get('plugin', 'webhook_url', 'https://workflow.saludplus.co/webhook/AI-Overview');
		$iTimeout = (int) $this->Config()->Get('plugin', 'timeout', 30);

		if ($this->isDebugEnabled()) {
			\SnappyMail\Log::info('AI_OVERVIEW', "Enviando petición a: {$sWebhookUrl}");
		}

		// Preparar el payload con MessageId para cache
		$aPayload = [
			'information' => $sInformation,
			'cache_id' => $sMessageId // ID único del mensaje (cada respuesta tiene su propio ID)
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
			if ($this->isDebugEnabled()) {
				\SnappyMail\Log::error('AI_OVERVIEW', "Error cURL: {$sError}");
			}
			return '';
		}

		if ($iHttpCode !== 200) {
			if ($this->isDebugEnabled()) {
				\SnappyMail\Log::error('AI_OVERVIEW', "HTTP Code: {$iHttpCode}, Response: {$sResponse}");
			}
			return '';
		}

		if ($this->isDebugEnabled()) {
			\SnappyMail\Log::info('AI_OVERVIEW', "Respuesta recibida: " . \strlen($sResponse) . " caracteres");
			\SnappyMail\Log::info('AI_OVERVIEW', "Respuesta raw (primeros 500 chars): " . \substr($sResponse, 0, 500));
		}

		// Parsear respuesta
		$aResponse = \json_decode($sResponse, true);
		
		if (\json_last_error() !== JSON_ERROR_NONE) {
			if ($this->isDebugEnabled()) {
				\SnappyMail\Log::error('AI_OVERVIEW', "Error al parsear JSON: " . \json_last_error_msg());
			}
			return '';
		}
		
		if ($this->isDebugEnabled()) {
			\SnappyMail\Log::info('AI_OVERVIEW', "Tipo de respuesta parseada: " . \gettype($aResponse));
		}
		
		// Si la respuesta es un array, tomar el primer elemento
		if (\is_array($aResponse) && !empty($aResponse) && isset($aResponse[0])) {
			if (\is_array($aResponse[0])) {
				$aResponse = $aResponse[0];
				if ($this->isDebugEnabled()) {
					\SnappyMail\Log::info('AI_OVERVIEW', "Respuesta es array, usando primer elemento");
				}
			} else {
				if ($this->isDebugEnabled()) {
					\SnappyMail\Log::info('AI_OVERVIEW', "Respuesta es array simple, primer elemento no es array");
				}
			}
		}
		
		// Intentar diferentes estructuras de respuesta
		if (\is_array($aResponse) && isset($aResponse['output'])) {
			if ($this->isDebugEnabled()) {
				\SnappyMail\Log::info('AI_OVERVIEW', "Resumen encontrado en campo 'output'");
			}
			return $aResponse['output'];
		} elseif (\is_array($aResponse) && isset($aResponse['summary'])) {
			if ($this->isDebugEnabled()) {
				\SnappyMail\Log::info('AI_OVERVIEW', "Resumen encontrado en campo 'summary'");
			}
			return $aResponse['summary'];
		} elseif (\is_array($aResponse) && isset($aResponse['result'])) {
			if ($this->isDebugEnabled()) {
				\SnappyMail\Log::info('AI_OVERVIEW', "Resumen encontrado en campo 'result'");
			}
			return $aResponse['result'];
		} elseif (\is_array($aResponse) && isset($aResponse['data'])) {
			if ($this->isDebugEnabled()) {
				\SnappyMail\Log::info('AI_OVERVIEW', "Resumen encontrado en campo 'data'");
			}
			return $aResponse['data'];
		} elseif (\is_array($aResponse) && isset($aResponse['response'])) {
			if ($this->isDebugEnabled()) {
				\SnappyMail\Log::info('AI_OVERVIEW', "Resumen encontrado en campo 'response'");
			}
			return $aResponse['response'];
		} else {
			// Si la respuesta es solo un string o no se encontró campo
			if ($this->isDebugEnabled()) {
				\SnappyMail\Log::warning('AI_OVERVIEW', "No se encontró campo de resumen. Tipo: " . \gettype($aResponse) . ", Keys: " . (\is_array($aResponse) ? \implode(', ', \array_keys($aResponse)) : 'N/A'));
				\SnappyMail\Log::warning('AI_OVERVIEW', "Retornando respuesta completa");
			}
			return $sResponse;
		}
	}

	/**
	 * Genera una clave única para la cache basada en el email del usuario y el MessageId
	 */
	private function getCacheKey(\RainLoop\Model\Account $oAccount, string $sMessageId) : string
	{
		$sUserEmail = $oAccount->Email();
		// Usar hash para crear una clave única y segura
		return 'ai-overview-' . \md5($sUserEmail . '-' . $sMessageId);
	}

	/**
	 * Obtiene el resumen desde la cache
	 */
	private function getCachedSummary(\RainLoop\Model\Account $oAccount, string $sCacheKey) : string
	{
		try {
			$oStorage = $this->Manager()->Actions()->StorageProvider();
			$sCached = $oStorage->Get($oAccount, StorageType::CONFIG, $sCacheKey, '');
			
			if ($this->isDebugEnabled()) {
				\SnappyMail\Log::info('AI_OVERVIEW', "getCachedSummary - Key: {$sCacheKey}, Cached length: " . \strlen($sCached));
			}
			
			if (!empty($sCached)) {
				// Verificar que el contenido sea válido (no esté corrupto)
				$aData = \json_decode($sCached, true);
				if (\json_last_error() === JSON_ERROR_NONE && isset($aData['summary']) && isset($aData['timestamp'])) {
					if ($this->isDebugEnabled()) {
						\SnappyMail\Log::info('AI_OVERVIEW', "Cache válida encontrada - Summary length: " . \strlen($aData['summary']));
					}
					return $aData['summary'];
				} else {
					if ($this->isDebugEnabled()) {
						\SnappyMail\Log::warning('AI_OVERVIEW', "Cache corrupta o inválida - JSON error: " . \json_last_error_msg());
					}
				}
			} else {
				if ($this->isDebugEnabled()) {
					\SnappyMail\Log::info('AI_OVERVIEW', "No se encontró cache para la clave: {$sCacheKey}");
				}
			}
		} catch (\Throwable $e) {
			if ($this->isDebugEnabled()) {
				\SnappyMail\Log::error('AI_OVERVIEW', "Error al obtener cache: " . $e->getMessage());
			}
		}
		
		return '';
	}

	/**
	 * Guarda el resumen en la cache
	 */
	private function saveCachedSummary(\RainLoop\Model\Account $oAccount, string $sCacheKey, string $sSummary) : bool
	{
		$oStorage = $this->Manager()->Actions()->StorageProvider();
		
		// Guardar con timestamp para posible expiración futura
		$aCacheData = [
			'summary' => $sSummary,
			'timestamp' => \time()
		];
		
		$sCacheData = \json_encode($aCacheData);
		
		if ($this->isDebugEnabled()) {
			\SnappyMail\Log::info('AI_OVERVIEW', "Guardando resumen en cache - Key: {$sCacheKey}");
		}
		
		return $oStorage->Put($oAccount, StorageType::CONFIG, $sCacheKey, $sCacheData);
	}

}
