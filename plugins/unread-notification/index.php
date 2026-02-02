<?php

class UnreadNotificationPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME     = 'Unread Notification',
		AUTHOR   = 'SaludPlus',
		URL      = 'https://saludplus.co/',
		VERSION  = '1.0',
		RELEASE  = '2026-01-23',
		REQUIRED = '2.35.0',
		CATEGORY = 'General',
		LICENSE  = 'MIT',
		DESCRIPTION = 'Notifica cuando se marca un correo como leído, no leído o se elimina (manual o automático)';

	private static ?int $lastSetAction = null;

	private static ?string $lastToFolder = null;

	public function Init() : void
	{
		// Hook para capturar el parámetro setAction antes de que se ejecute la acción
		$this->addHook('json.before-MessageSetSeen', 'OnMessageSetSeenBefore');
		
		// Hook para detectar cuando se marca como no leído manualmente
		$this->addHook('json.after-MessageSetSeen', 'OnMessageSetSeen');
		
		// Hook para detectar cuando se abre un correo no leído
		$this->addHook('filter.result-message', 'OnMessageOpen');
		
		// Hook para capturar el parámetro toFolder antes de mover
		$this->addHook('json.before-MessageMove', 'OnMessageMoveBefore');
		
		// Hook para detectar cuando se mueve un mensaje (incluyendo a Trash)
		$this->addHook('json.after-MessageMove', 'OnMessageMove');
		
		// Hook para detectar cuando se marca un mensaje como eliminado
		$this->addHook('json.before-MessageSetDeleted', 'OnMessageSetDeletedBefore');
		$this->addHook('json.after-MessageSetDeleted', 'OnMessageSetDeleted');
		
		// Hook para detectar cuando se elimina un mensaje permanentemente
		$this->addHook('json.after-MessageDelete', 'OnMessageDelete');
	}

	public function Supported() : string
	{
		return '';
	}

	protected function configMapping() : array
	{
		return array(
			\RainLoop\Plugins\Property::NewInstance('api_url')
				->SetLabel('API URL')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::STRING)
				->SetDescription('URL del endpoint para notificar correos no leídos')
				->SetDefaultValue('https://qmanager.saludplus.co/MessageEnqueue'),
			
			\RainLoop\Plugins\Property::NewInstance('api_key')
				->SetLabel('API Key')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::STRING)
				->SetEncrypted()
				->SetDescription('API Key para autenticación')
				->SetDefaultValue('550e8400-e29b-41d4-a716-446655440000'),
			
			\RainLoop\Plugins\Property::NewInstance('queue_name')
				->SetLabel('Queue Name')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::STRING)
				->SetDescription('Nombre de la cola para el mensaje')
				->SetDefaultValue('get-unread-message-count'),
			
			\RainLoop\Plugins\Property::NewInstance('debug')
				->SetLabel('Debug')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::BOOL)
				->SetDescription('Activar logs de depuración')
				->SetDefaultValue(false)
		);
	}

	/**
	 * Captura el parámetro setAction antes de que se ejecute la acción
	 */
	public function OnMessageSetSeenBefore() : void
	{
		// Guardar el valor de setAction antes de que se ejecute la acción
		self::$lastSetAction = (int) $this->Manager()->Actions()->GetActionParam('setAction', '1');
		
		if ($this->isDebugEnabled()) {
			\SnappyMail\Log::info('UNREAD_NOTIFICATION', "MessageSetSeenBefore - setAction capturado: " . self::$lastSetAction);
		}
	}

	/**
	 * Detecta cuando se marca un mensaje como leído o no leído (manual o automático)
	 */
	public function OnMessageSetSeen(array &$aResponse) : void
	{
		$oActions = $this->Manager()->Actions();
		$oAccount = $oActions->getAccountFromToken(false);
		
		if (!$oAccount) {
			return;
		}

		// Usar el valor capturado en el hook before
		$iSetAction = self::$lastSetAction ?? 1;
		
		if ($this->isDebugEnabled()) {
			\SnappyMail\Log::info('UNREAD_NOTIFICATION', "MessageSetSeen - setAction: {$iSetAction}");
		}

		// Notificar tanto cuando se marca como leído (1) como cuando se marca como no leído (0)
		// Esto cubre: marcar como leído manualmente, marcar como leído automáticamente al abrir, y marcar como no leído
		$sUserEmail = $oAccount->Email();
		
		if ($iSetAction === 0) {
			if ($this->isDebugEnabled()) {
				\SnappyMail\Log::info('UNREAD_NOTIFICATION', "Mensaje marcado como NO leído - Usuario: {$sUserEmail}");
			}
		} else {
			if ($this->isDebugEnabled()) {
				\SnappyMail\Log::info('UNREAD_NOTIFICATION', "Mensaje marcado como leído (manual o automático) - Usuario: {$sUserEmail}");
			}
		}

		$this->notifyUnreadMessage($sUserEmail);
		
		// Limpiar el valor estático después de usarlo
		self::$lastSetAction = null;
	}

	/**
	 * Detecta cuando se abre un correo no leído (hook filter.result-message)
	 * Nota: Este hook se ejecuta cuando se obtiene el mensaje, antes de que se marque como leído automáticamente
	 */
	public function OnMessageOpen($oMessage)
	{
		if (!$oMessage) {
			return;
		}

		$oActions = $this->Manager()->Actions();
		$oAccount = $oActions->getAccountFromToken(false);
		
		if (!$oAccount) {
			return;
		}

		// Verificar si el mensaje NO tiene el flag \Seen (es decir, está no leído)
		$ref = new \ReflectionClass($oMessage);
		$prop = $ref->getProperty('aFlagsLowerCase');
		$prop->setAccessible(true);
		$aFlags = $prop->getValue($oMessage);

		$bIsUnread = !\in_array('\\seen', $aFlags) && !\in_array('seen', $aFlags);

		if ($this->isDebugEnabled()) {
			\SnappyMail\Log::info('UNREAD_NOTIFICATION', "OnMessageOpen - Flags: " . \implode(', ', $aFlags) . " - IsUnread: " . ($bIsUnread ? 'true' : 'false'));
		}

		// Si el mensaje está no leído, notificar
		// Nota: Cuando SnappyMail marca automáticamente como leído después de abrir,
		// se ejecutará el hook json.after-MessageSetSeen con setAction=1, que también notificará
		if ($bIsUnread) {
			$sUserEmail = $oAccount->Email();
			
			if ($this->isDebugEnabled()) {
				\SnappyMail\Log::info('UNREAD_NOTIFICATION', "Correo no leído abierto - Usuario: {$sUserEmail}");
			}

			$this->notifyUnreadMessage($sUserEmail);
		}
	}


	/**
	 * Captura el parámetro toFolder antes de mover
	 */
	public function OnMessageMoveBefore() : void
	{
		$sToFolder = (string) $this->Manager()->Actions()->GetActionParam('toFolder', '');
		self::$lastToFolder = $sToFolder;
		
		if ($this->isDebugEnabled()) {
			\SnappyMail\Log::info('UNREAD_NOTIFICATION', "MessageMoveBefore - toFolder capturado: " . self::$lastToFolder);
		}
	}

	/**
	 * Detecta cuando se mueve un mensaje (incluyendo a Trash)
	 */
	public function OnMessageMove(array &$aResponse) : void
	{
		$oActions = $this->Manager()->Actions();
		$oAccount = $oActions->getAccountFromToken(false);
		
		if (!$oAccount) {
			return;
		}

		$sToFolder = self::$lastToFolder ?? '';
		
		// También intentar obtener desde los parámetros de acción por si el hook before no funcionó
		if (empty($sToFolder)) {
			$sToFolder = (string) $oActions->GetActionParam('toFolder', '');
		}
		
		if ($this->isDebugEnabled()) {
			\SnappyMail\Log::info('UNREAD_NOTIFICATION', "OnMessageMove - toFolder: {$sToFolder}");
		}
		
		// Obtener la carpeta de basura configurada
		$sTrashFolder = '';
		try {
			$oFolderCollection = $oActions->MailClient()->FolderList();
			if ($oFolderCollection) {
				foreach ($oFolderCollection as $oFolder) {
					if ($oFolder && $oFolder->IsTrash()) {
						$sTrashFolder = $oFolder->FullName();
						break;
					}
				}
			}
		} catch (\Throwable $e) {
			if ($this->isDebugEnabled()) {
				\SnappyMail\Log::warning('UNREAD_NOTIFICATION', "Error obteniendo carpeta Trash: " . $e->getMessage());
			}
		}

		// Verificar si se está moviendo a Trash o si el toFolder contiene "trash" o "papelera"
		$bIsTrash = false;
		if (!empty($sToFolder)) {
			$sToFolderLower = \strtolower($sToFolder);
			$bIsTrash = ($sToFolder === $sTrashFolder) || 
			            \strpos($sToFolderLower, 'trash') !== false ||
			            \strpos($sToFolderLower, 'papelera') !== false ||
			            \strpos($sToFolderLower, 'eliminados') !== false ||
			            \strpos($sToFolderLower, 'deleted') !== false ||
			            \strpos($sToFolderLower, 'borradores') !== false;
		}

		if ($bIsTrash) {
			$sUserEmail = $oAccount->Email();
			
			if ($this->isDebugEnabled()) {
				\SnappyMail\Log::info('UNREAD_NOTIFICATION', "Mensaje movido a Trash - Usuario: {$sUserEmail}, Carpeta: {$sToFolder}");
			}

			$this->notifyUnreadMessage($sUserEmail);
		} else {
			if ($this->isDebugEnabled()) {
				\SnappyMail\Log::info('UNREAD_NOTIFICATION', "Mensaje movido a otra carpeta (no Trash) - Carpeta: {$sToFolder}");
			}
		}
		
		// Limpiar el valor estático después de usarlo
		self::$lastToFolder = null;
	}

	/**
	 * Captura información antes de marcar como eliminado
	 */
	public function OnMessageSetDeletedBefore() : void
	{
		if ($this->isDebugEnabled()) {
			\SnappyMail\Log::info('UNREAD_NOTIFICATION', "MessageSetDeletedBefore - Hook ejecutado");
		}
	}

	/**
	 * Detecta cuando se marca un mensaje como eliminado
	 */
	public function OnMessageSetDeleted(array &$aResponse) : void
	{
		$oActions = $this->Manager()->Actions();
		$oAccount = $oActions->getAccountFromToken(false);
		
		if (!$oAccount) {
			return;
		}

		$sUserEmail = $oAccount->Email();
		
		if ($this->isDebugEnabled()) {
			\SnappyMail\Log::info('UNREAD_NOTIFICATION', "Mensaje marcado como eliminado (flag \\Deleted) - Usuario: {$sUserEmail}");
		}

		$this->notifyUnreadMessage($sUserEmail);
	}

	/**
	 * Detecta cuando se elimina un mensaje permanentemente
	 */
	public function OnMessageDelete(array &$aResponse) : void
	{
		$oActions = $this->Manager()->Actions();
		$oAccount = $oActions->getAccountFromToken(false);
		
		if (!$oAccount) {
			return;
		}

		$sUserEmail = $oAccount->Email();
		
		if ($this->isDebugEnabled()) {
			\SnappyMail\Log::info('UNREAD_NOTIFICATION', "Mensaje eliminado permanentemente - Usuario: {$sUserEmail}");
		}

		$this->notifyUnreadMessage($sUserEmail);
	}

	/**
	 * Notifica a la API cuando se marca un correo como no leído
	 */
	private function notifyUnreadMessage(string $sUserEmail) : void
	{
		$sApiUrl = $this->Config()->Get('plugin', 'api_url', 'https://qmanager.saludplus.co/MessageEnqueue');
		$sApiKey = $this->Config()->getDecrypted('plugin', 'api_key', '550e8400-e29b-41d4-a716-446655440000');
		$sQueueName = $this->Config()->Get('plugin', 'queue_name', 'get-unread-message-count');

		if (empty($sApiUrl) || empty($sApiKey) || empty($sQueueName)) {
			if ($this->isDebugEnabled()) {
				\SnappyMail\Log::warning('UNREAD_NOTIFICATION', 'Configuración incompleta - API URL, API Key o Queue Name faltantes');
			}
			return;
		}

		// Preparar el payload
		$aPayload = [
			'queueName' => $sQueueName,
			'requestBody' => $sUserEmail,
			'isBroadcast' => true
		];
		$sJsonPayload = \json_encode($aPayload);

		if ($this->isDebugEnabled()) {
			\SnappyMail\Log::info('UNREAD_NOTIFICATION', "Enviando notificación - URL: {$sApiUrl}, Email: {$sUserEmail}");
		}

		// Hacer petición con cURL
		$ch = \curl_init($sApiUrl);
		\curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		\curl_setopt($ch, CURLOPT_POST, true);
		\curl_setopt($ch, CURLOPT_POSTFIELDS, $sJsonPayload);
		\curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'X-API-Key: ' . $sApiKey,
			'Content-Type: application/json',
			'Content-Length: ' . \strlen($sJsonPayload)
		]);
		\curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		\curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Para desarrollo, en producción debería ser true

		$sResponse = \curl_exec($ch);
		$iHttpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$sError = \curl_error($ch);
		\curl_close($ch);

		if ($sError) {
			if ($this->isDebugEnabled()) {
				\SnappyMail\Log::error('UNREAD_NOTIFICATION', "Error cURL: {$sError}");
			}
			$this->Manager()->WriteException("cURL Error: {$sError}", \LOG_ERR);
			return;
		}

		if ($iHttpCode !== 200 && $iHttpCode !== 201) {
			if ($this->isDebugEnabled()) {
				\SnappyMail\Log::error('UNREAD_NOTIFICATION', "HTTP Code: {$iHttpCode}, Response: {$sResponse}");
			}
			$this->Manager()->WriteException("HTTP Error {$iHttpCode}: {$sResponse}", \LOG_ERR);
			return;
		}

		if ($this->isDebugEnabled()) {
			\SnappyMail\Log::info('UNREAD_NOTIFICATION', "Notificación enviada exitosamente - HTTP Code: {$iHttpCode}");
		}
	}

	/**
	 * Verificar si debug está activado
	 */
	private function isDebugEnabled() : bool
	{
		return (bool) $this->Config()->Get('plugin', 'debug', false);
	}
}
