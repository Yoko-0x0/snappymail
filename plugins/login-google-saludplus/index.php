<?php

/**
 * OAuth2 login with Google for SaludPlus domain
 * Only allows @saludplus.co domain with email and profile permissions
 * https://developers.google.com/identity/protocols/oauth2
 * https://console.cloud.google.com/apis/dashboard
 */

use RainLoop\Model\MainAccount;
use RainLoop\Providers\Storage\Enumerations\StorageType;

class LoginGoogleSaludPlusPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME     = 'Login Google SaludPlus',
		VERSION  = '1.0',
		RELEASE  = '2026-01-17',
		REQUIRED = '2.36.1',
		CATEGORY = 'Login',
		DESCRIPTION = 'Google OAuth2 login for @saludplus.co domain with credential mapping';

	const
		LOGIN_URI = 'https://accounts.google.com/o/oauth2/auth',
		TOKEN_URI = 'https://accounts.google.com/o/oauth2/token';

	// Activar logs de debug (cambiar a true para troubleshooting)
	const DEBUG_LOG = false;

	private static ?array $auth = null;
	private static bool $isOAuthLogin = false;

	public function Init() : void
	{
		$this->UseLangs(true);
		$this->addCss('google-button.css');
		$this->addJs('LoginOAuth2.js');
		
		// NO necesitamos el hook login.credentials porque las credenciales
		// ya se pasan directamente en LoginProcess() después del OAuth

		$this->addPartHook('LoginGoogleSaludPlus', 'ServiceLoginGoogleSaludPlus');

		// Prevent Disallowed Sec-Fetch Dest: document Mode: navigate Site: cross-site User: true
		$this->addHook('filter.http-paths', 'httpPaths');
	}

	public function httpPaths(array $aPaths) : void
	{
		if (!empty($aPaths[0]) && 'LoginGoogleSaludPlus' === $aPaths[0]) {
			$oConfig = \RainLoop\Api::Config();
			$oConfig->Set('security', 'secfetch_allow',
				\trim($oConfig->Get('security', 'secfetch_allow', '') . ';site=cross-site', ';')
			);
		}
	}

	public function ServiceLoginGoogleSaludPlus() : string
	{
		$oActions = \RainLoop\Api::Actions();
		$oHttp = $oActions->Http();
		$oHttp->ServerNoCache();

		$uri = \preg_replace('/.LoginGoogleSaludPlus.*$/D', '', $_SERVER['REQUEST_URI']);

		try
		{
			if (isset($_GET['error'])) {
				throw new \RuntimeException($_GET['error']);
			}
			if (isset($_GET['code']) && isset($_GET['state']) && 'saludplus' === $_GET['state']) {
				$oGoogle = $this->googleConnector();
			}
			if (empty($oGoogle)) {
				$oActions->Location($uri);
				exit;
			}

			$iExpires = \time();
			$aResponse = $oGoogle->getAccessToken(
				static::TOKEN_URI,
				'authorization_code',
				array(
					'code' => $_GET['code'],
					'redirect_uri' => $oHttp->GetFullUrl().'?LoginGoogleSaludPlus'
				)
			);
			if (200 != $aResponse['code']) {
				if (isset($aResponse['result']['error'])) {
					throw new \RuntimeException(
						$aResponse['code']
						. ': '
						. $aResponse['result']['error']
						. ' / '
						. $aResponse['result']['error_description']
					);
				}
				throw new \RuntimeException("HTTP: {$aResponse['code']}");
			}
			$aResponse = $aResponse['result'];
			if (empty($aResponse['access_token'])) {
				throw new \RuntimeException('access_token missing');
			}

			$sAccessToken = $aResponse['access_token'];
			$iExpires += $aResponse['expires_in'];

			$oGoogle->setAccessToken($sAccessToken);
			$aUserInfo = $oGoogle->fetch('https://www.googleapis.com/oauth2/v2/userinfo');
			if (200 != $aUserInfo['code']) {
				throw new \RuntimeException("HTTP: {$aUserInfo['code']}");
			}
			$aUserInfo = $aUserInfo['result'];
			if (empty($aUserInfo['id'])) {
				throw new \RuntimeException('unknown id');
			}
			if (empty($aUserInfo['email'])) {
				throw new \RuntimeException('unknown email address');
			}

			// Validar que el email sea del dominio @saludplus.co
			if (!\str_ends_with(\strtolower($aUserInfo['email']), '@saludplus.co')) {
				throw new \RuntimeException('Solo se permite el dominio @saludplus.co');
			}

		// Buscar las credenciales mapeadas
		$oActions->Logger()->Write('[GoogleSaludPlus] Buscando mapping para: ' . $aUserInfo['email'], \LOG_ERR);
		$aMappedCredentials = $this->getMappedCredentials($aUserInfo['email']);
		if (!$aMappedCredentials) {
			$oActions->Logger()->Write('[GoogleSaludPlus] ERROR: No se encontró mapping para: ' . $aUserInfo['email'], \LOG_ERR);
			throw new \RuntimeException('No se encontró mapeo de credenciales para este email: ' . $aUserInfo['email']);
		}

		$oActions->Logger()->Write('[GoogleSaludPlus] Mapping encontrado. Email destino: ' . $aMappedCredentials['email'], \LOG_ERR);

		static::$auth = [
			'email' => $aUserInfo['email'],
			'mapped_email' => $aMappedCredentials['email'],
			'mapped_password' => $aMappedCredentials['password'],
			'expires' => $iExpires
		];
		static::$isOAuthLogin = true;

		// Login con las credenciales mapeadas
		$oPassword = new \SnappyMail\SensitiveString($aMappedCredentials['password']);
		
		if (self::DEBUG_LOG) {
			$oActions->Logger()->Write('[GoogleSaludPlus] Intentando login con: ' . $aMappedCredentials['email'], \LOG_ERR);
		}
		
		// Duplicar lógica de LoginProcess pero guardar token ANTES de imapConnect
		// para evitar que InvalidToken del hook search-filters interrumpa el flujo
		if (self::DEBUG_LOG) {
			$oActions->Logger()->Write('[GoogleSaludPlus] Creando cuenta manualmente', \LOG_ERR);
		}
		
		// Usar reflexión para llamar a resolveLoginCredentials (es protected)
		$reflection = new \ReflectionClass($oActions);
		$method = $reflection->getMethod('resolveLoginCredentials');
		$method->setAccessible(true);
		$aCredentials = $method->invoke($oActions, $aMappedCredentials['email'], $oPassword);
		
		if (self::DEBUG_LOG) {
			$oActions->Logger()->Write('[GoogleSaludPlus] Credenciales resueltas', \LOG_ERR);
		}
		
		// Crear cuenta
		$oAccount = new \RainLoop\Model\MainAccount();
		$oAccount->setCredentials(
			$aCredentials['domain'],
			$aCredentials['email'],
			$aCredentials['imapUser'],
			$aCredentials['pass'],
			$aCredentials['smtpUser']
		);
		
		if (self::DEBUG_LOG) {
			$oActions->Logger()->Write('[GoogleSaludPlus] Account creada', \LOG_ERR);
		}
		
		// Ejecutar hook filter.account
		$oActions->Plugins()->RunHook('filter.account', array($oAccount));
		if (!$oAccount) {
			throw new \RuntimeException('Account filtrada');
		}
		
		// GUARDAR TOKEN ANTES de conectar IMAP (esto es la clave)
		$oActions->StorageProvider()->Put($oAccount, StorageType::SESSION, \RainLoop\Utils::GetSessionToken(), 'true');
		$oActions->SetMainAuthAccount($oAccount);
		
		if (self::DEBUG_LOG) {
			$oActions->Logger()->Write('[GoogleSaludPlus] Token guardado ANTES de IMAP', \LOG_ERR);
		}
		
		// Ahora conectar IMAP usando reflexión (es protected)
		try {
			if (self::DEBUG_LOG) {
				$oActions->Logger()->Write('[GoogleSaludPlus] Conectando IMAP', \LOG_ERR);
			}
			$reflection = new \ReflectionClass($oActions);
			$imapMethod = $reflection->getMethod('imapConnect');
			$imapMethod->setAccessible(true);
			$imapMethod->invoke($oActions, $oAccount, true);
			
			if (self::DEBUG_LOG) {
				$oActions->Logger()->Write('[GoogleSaludPlus] IMAP conectado', \LOG_ERR);
			}
		} catch (\RainLoop\Exceptions\ClientException $e) {
			// InvalidToken[101] viene del hook search-filters - ignorarlo
			if (101 === $e->getCode()) {
				if (self::DEBUG_LOG) {
					$oActions->Logger()->Write('[GoogleSaludPlus] InvalidToken[101] ignorado - hook search-filters', \LOG_ERR);
				}
			} else {
				$oActions->Logger()->Write('[GoogleSaludPlus] ERROR IMAP: ' . $e->getMessage(), \LOG_ERR);
				throw $e;
			}
		}
		
		// Ejecutar hook login.success
		$oActions->Plugins()->RunHook('login.success', array($oAccount));
		$oActions->SetAuthToken($oAccount);
		
		if (self::DEBUG_LOG) {
			$oActions->Logger()->Write('[GoogleSaludPlus] Login completado exitosamente', \LOG_ERR);
		}
	}
	catch (\Exception $oException)
	{
		$oActions->Logger()->Write('[GoogleSaludPlus] ERROR GENERAL: ' . $oException->getMessage(), \LOG_ERR);
		$oActions->Logger()->WriteException($oException, \LOG_ERR);
	}
		$oActions->Location($uri);
		exit;
	}

	// Eliminamos FilterLoginCredentials porque no es necesario
	// Las credenciales se pasan directamente en LoginProcess() después del OAuth

	/**
	 * Obtener las credenciales mapeadas para un email de Google
	 * Lee desde el archivo saludplus-email-accouts.txt en el directorio data
	 */
	private function getMappedCredentials(string $sGoogleEmail) : ?array
	{
		$oActions = \RainLoop\Api::Actions();
		
		// Obtener la ruta del directorio data
		$sDataPath = \defined('APP_DATA_FOLDER_PATH') 
			? \rtrim(\APP_DATA_FOLDER_PATH, '\\/') 
			: \rtrim(\dirname(__DIR__, 4) . '/data', '\\/');
		
		$sMappingFile = $sDataPath . '/saludplus-email-accouts.txt';
		
		$oActions->Logger()->Write('[GoogleSaludPlus] Buscando archivo de mapping: ' . $sMappingFile, \LOG_ERR);
		$oActions->Logger()->Write('[GoogleSaludPlus] APP_DATA_FOLDER_PATH definido: ' . (\defined('APP_DATA_FOLDER_PATH') ? 'SI' : 'NO'), \LOG_ERR);
		if (\defined('APP_DATA_FOLDER_PATH')) {
			$oActions->Logger()->Write('[GoogleSaludPlus] APP_DATA_FOLDER_PATH = ' . \APP_DATA_FOLDER_PATH, \LOG_ERR);
		}
		$oActions->Logger()->Write('[GoogleSaludPlus] Ruta calculada: ' . $sDataPath, \LOG_ERR);
		$oActions->Logger()->Write('[GoogleSaludPlus] Archivo completo: ' . $sMappingFile, \LOG_ERR);
		$oActions->Logger()->Write('[GoogleSaludPlus] Archivo existe: ' . (\is_file($sMappingFile) ? 'SI' : 'NO'), \LOG_ERR);
		$oActions->Logger()->Write('[GoogleSaludPlus] Archivo legible: ' . (\is_readable($sMappingFile) ? 'SI' : 'NO'), \LOG_ERR);
		
		// Verificar que el archivo existe
		if (!\is_file($sMappingFile)) {
			$oActions->Logger()->Write('[GoogleSaludPlus] ERROR: Archivo de mapping no encontrado: ' . $sMappingFile, \LOG_ERR);
			return null;
		}
		
		// Leer el contenido del archivo
		$sMapping = \file_get_contents($sMappingFile);
		if ($sMapping === false) {
			$oActions->Logger()->Write('[GoogleSaludPlus] ERROR: No se pudo leer el archivo de mapping', \LOG_ERR);
			return null;
		}
		
		// Eliminar BOM (Byte Order Mark) si existe
		$sMapping = \preg_replace('/^\xEF\xBB\xBF/', '', $sMapping);
		$sMapping = \trim($sMapping);
		
		$oActions->Logger()->Write('[GoogleSaludPlus] Mapping file length: ' . \strlen($sMapping) . ' caracteres', \LOG_ERR);
		$oActions->Logger()->Write('[GoogleSaludPlus] Primeros 200 caracteres del archivo: ' . \substr($sMapping, 0, 200), \LOG_ERR);
		
		if (empty($sMapping)) {
			$oActions->Logger()->Write('[GoogleSaludPlus] ERROR: Archivo de mapping vacío', \LOG_ERR);
			return null;
		}

		$aLines = \explode("\n", \preg_replace('/[\r\n\t]+/', "\n", $sMapping));
		
		$oActions->Logger()->Write('[GoogleSaludPlus] Total de líneas en archivo: ' . \count($aLines), \LOG_ERR);
		
		foreach ($aLines as $iIndex => $sLine) {
			$sLine = \trim($sLine);
			
			// Ignorar líneas vacías y comentarios
			if (empty($sLine) || $sLine[0] === '#') {
				$oActions->Logger()->Write('[GoogleSaludPlus] Línea ' . $iIndex . ' ignorada (vacía o comentario)', \LOG_ERR);
				continue;
			}
			
			$oActions->Logger()->Write('[GoogleSaludPlus] Procesando línea ' . $iIndex . ': ' . \substr($sLine, 0, 80), \LOG_ERR);
			
			$aData = \explode(':', $sLine, 3);
			
			$oActions->Logger()->Write('[GoogleSaludPlus] Partes encontradas: ' . \count($aData), \LOG_ERR);
			
			if (\is_array($aData) && \count($aData) === 3) {
				// Limpiar emails eliminando BOM y espacios
				$sMapGoogleEmail = \preg_replace('/^\xEF\xBB\xBF/', '', \trim($aData[0]));
				$sMapDestEmail = \preg_replace('/^\xEF\xBB\xBF/', '', \trim($aData[1]));
				$sMapPassword = \trim($aData[2]);
				
				// Limpiar también el email de entrada
				$sCleanGoogleEmail = \preg_replace('/^\xEF\xBB\xBF/', '', \trim($sGoogleEmail));
				
				$oActions->Logger()->Write('[GoogleSaludPlus] Email Google: "' . $sMapGoogleEmail . '"', \LOG_ERR);
				$oActions->Logger()->Write('[GoogleSaludPlus] Email destino: "' . $sMapDestEmail . '"', \LOG_ERR);
				$oActions->Logger()->Write('[GoogleSaludPlus] Password length: ' . \strlen($sMapPassword), \LOG_ERR);
				$oActions->Logger()->Write('[GoogleSaludPlus] Comparando "' . $sMapGoogleEmail . '" con "' . $sCleanGoogleEmail . '"', \LOG_ERR);
				$oActions->Logger()->Write('[GoogleSaludPlus] strcasecmp resultado: ' . \strcasecmp($sMapGoogleEmail, $sCleanGoogleEmail), \LOG_ERR);
				
				if (\strcasecmp($sMapGoogleEmail, $sCleanGoogleEmail) === 0) {
					$oActions->Logger()->Write('[GoogleSaludPlus] ¡MATCH ENCONTRADO! Email destino: ' . $sMapDestEmail, \LOG_ERR);
					return [
						'email' => $sMapDestEmail,
						'password' => $sMapPassword
					];
				} else {
					$oActions->Logger()->Write('[GoogleSaludPlus] No coincide, continuando...', \LOG_ERR);
				}
			} else {
				$oActions->Logger()->Write('[GoogleSaludPlus] ERROR: Línea ' . $iIndex . ' no tiene formato correcto (esperado: email:email:password)', \LOG_ERR);
			}
		}

		$oActions->Logger()->Write('[GoogleSaludPlus] ERROR: No se encontró match para: ' . $sGoogleEmail, \LOG_ERR);
		return null;
	}

	public function configMapping() : array
	{
		return [
			\RainLoop\Plugins\Property::NewInstance('client_id')
				->SetLabel('Client ID')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::STRING)
				->SetAllowedInJs()
				->SetDescription('Google Cloud Console - OAuth 2.0 Client ID'),
			\RainLoop\Plugins\Property::NewInstance('client_secret')
				->SetLabel('Client Secret')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::STRING)
				->SetEncrypted()
				->SetDescription('Google Cloud Console - OAuth 2.0 Client Secret'),
			\RainLoop\Plugins\Property::NewInstance('hide_standard_login')
				->SetLabel('Ocultar login estándar')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::BOOL)
				->SetDescription('Ocultar el formulario de login tradicional para usuarios con @saludplus.co')
				->SetDefaultValue(false)
		];
	}

	protected function googleConnector() : ?\OAuth2\Client
	{
		$client_id = \trim($this->Config()->Get('plugin', 'client_id', ''));
		$client_secret = \trim($this->Config()->getDecrypted('plugin', 'client_secret', ''));
		if ($client_id && $client_secret) {
			try
			{
				$oGoogle = new \OAuth2\Client($client_id, $client_secret);
				$oActions = \RainLoop\Api::Actions();
				$sProxy = $oActions->Config()->Get('labs', 'curl_proxy', '');
				if (\strlen($sProxy)) {
					$oGoogle->setCurlOption(CURLOPT_PROXY, $sProxy);
					$sProxyAuth = $oActions->Config()->Get('labs', 'curl_proxy_auth', '');
					if (\strlen($sProxyAuth)) {
						$oGoogle->setCurlOption(CURLOPT_PROXYUSERPWD, $sProxyAuth);
					}
				}
				return $oGoogle;
			}
			catch (\Exception $oException)
			{
				$oActions->Logger()->WriteException($oException, \LOG_ERR);
			}
		}
		return null;
	}
}
