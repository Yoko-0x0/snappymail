(rl => {
	const client_id = rl.pluginSettingsGet('login-google-saludplus', 'client_id'),
		hide_standard_login = rl.pluginSettingsGet('login-google-saludplus', 'hide_standard_login'),
		login = () => {
			document.location = 'https://accounts.google.com/o/oauth2/auth?' + (new URLSearchParams({
				response_type: 'code',
				client_id: client_id,
				redirect_uri: document.location.href + '?LoginGoogleSaludPlus',
				scope: [
					// Primary Google Account email address
					'https://www.googleapis.com/auth/userinfo.email',
					// Personal info
					'https://www.googleapis.com/auth/userinfo.profile',
					// Associate personal info
					'openid'
				].join(' '),
				state: 'saludplus',
				// Force authorize screen, so we always get a refresh_token
				access_type: 'offline',
				prompt: 'consent'
			}));
		};

	if (client_id) {
		// NO interceptamos el evento sm-user-login para permitir login tradicional
		// El usuario puede elegir entre:
		// 1. Botón ACCEDER → Login tradicional con usuario/contraseña
		// 2. Botón GOOGLE SALUDPLUS → Login con OAuth

		addEventListener('rl-view-model', e => {
			if ('Login' === e.detail.viewModelTemplateID) {
				const
					container = e.detail.viewModelDom.querySelector('#plugin-Login-BottomControlGroup'),
					btn = Element.fromHTML('<button type="button" class="btn btn-success">Google SaludPlus</button>'),
					div = Element.fromHTML('<div class="controls"></div>');
				btn.onclick = login;
				div.append(btn);
				container && container.append(div);

				// Si hide_standard_login está activado, ocultar campos de contraseña para @saludplus.co
				if (hide_standard_login) {
					const emailInput = e.detail.viewModelDom.querySelector('input[name="Email"]');
					const passwordGroup = e.detail.viewModelDom.querySelector('#plugin-Login-PasswordControlGroup');
					const signInBtn = e.detail.viewModelDom.querySelector('.submitButton');

					if (emailInput && passwordGroup && signInBtn) {
						// Función para mostrar/ocultar campos según el dominio
						const togglePasswordFields = () => {
							const email = emailInput.value.toLowerCase();
							if (email.includes('@saludplus.co')) {
								passwordGroup.style.display = 'none';
								signInBtn.style.display = 'none';
							} else {
								passwordGroup.style.display = '';
								signInBtn.style.display = '';
							}
						};

						// Verificar al escribir en el campo de email
						emailInput.addEventListener('input', togglePasswordFields);
						emailInput.addEventListener('change', togglePasswordFields);
						// Verificar al cargar la página
						togglePasswordFields();
					}
				}
			}
		});
	}

})(window.rl);
