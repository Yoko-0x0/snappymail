/**
 * AI Overview - Interfaz de usuario
 * Muestra un resumen automático del hilo de correos usando IA
 */
(rl => {
	const templateId = 'MailMessageView';

	addEventListener('rl-view-model.create', e => {
		if (templateId === e.detail.viewModelTemplateID) {
			const
				template = document.getElementById(templateId),
				view = e.detail;

			// Buscar un lugar donde insertar nuestro contenido
			// Vamos a insertarlo después de .messageItemHeader
			const messageItemHeader = template.content.querySelector('.messageItemHeader');
			
			if (!messageItemHeader) {
				console.warn('AI Overview - No se encontró .messageItemHeader');
				return;
			}

			// Insertar el panel AI Overview después del header
			messageItemHeader.after(Element.fromHTML(`
				<div class="ai-overview-container" data-bind="visible: message" style="display:none;">
					<div class="ai-overview-header">
						<span class="ai-overview-icon">
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-subtitles-ai"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M11.5 19h-5.5a3 3 0 0 1 -3 -3v-8a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v4" /><path d="M7 15h5" /><path d="M17 12h-3" /><path d="M11 12h-1" /><path d="M19 22.5a4.75 4.75 0 0 1 3.5 -3.5a4.75 4.75 0 0 1 -3.5 -3.5a4.75 4.75 0 0 1 -3.5 3.5a4.75 4.75 0 0 1 3.5 3.5" /></svg>
						</span>
						<span class="ai-overview-title">Resumen AI</span>
						<button class="ai-overview-toggle" title="Expandir/Colapsar">▲</button>
					</div>
					<div class="ai-overview-content">
						<div class="ai-overview-loading">
							<div class="spinner"></div>
							<span>Generando resumen...</span>
						</div>
						<ul class="ai-overview-bullets" style="display:none;"></ul>
						<div class="ai-overview-error" style="display:none;"></div>
					</div>
				</div>
			`));

			console.log('AI Overview - Panel agregado al template');

			// Solicitar resumen cuando se carga un mensaje
			view.message.subscribe(msg => {
				if (msg) {
					// Configurar toggle
					setTimeout(() => {
						setupToggle();
					}, 100);

					// Esperar a que el cuerpo del mensaje esté disponible
					// El cuerpo puede tardar un momento en cargarse
					const checkBodyLoaded = () => {
						// Intentar obtener el contenido del mensaje
						const bodyElement = msg.body || document.querySelector(`#rl-msg-${msg.hash}`);
						const plainText = msg.plain && typeof msg.plain === 'function' ? msg.plain() : (msg.plain || '');
						const htmlText = msg.html && typeof msg.html === 'function' ? msg.html() : (msg.html || '');

						if (bodyElement || plainText || htmlText) {
							// El cuerpo está disponible, solicitar resumen
							requestAiSummary(msg);
						} else {
							// Esperar un poco más y volver a intentar
							setTimeout(checkBodyLoaded, 200);
						}
					};

					// Iniciar verificación después de un pequeño delay
					setTimeout(checkBodyLoaded, 300);
				}
			});
		}
	});


	/**
	 * Solicitar resumen de IA
	 */
	function requestAiSummary(msg) {
		const container = document.querySelector('.ai-overview-container');
		if (!container) return;

		const loading = container.querySelector('.ai-overview-loading');
		const bullets = container.querySelector('.ai-overview-bullets');
		const error = container.querySelector('.ai-overview-error');

		// Recopilar información del mensaje desde el frontend
		let messageData = '';
		
		try {
			// Obtener información del mensaje
			// msg.from es un EmailCollectionModel, no una función
			let from = '';
			if (msg.from) {
				// from es un objeto EmailCollectionModel con métodos
				if (msg.from.toString && typeof msg.from.toString === 'function') {
					from = msg.from.toString();
				} else if (msg.from[0]) {
					from = msg.from[0].email || msg.from[0].name || '';
				}
			}
			
			const subject = msg.subject && typeof msg.subject === 'function' ? msg.subject() : (msg.subject || '');
			const dateTimestamp = msg.dateTimestamp && typeof msg.dateTimestamp === 'function' ? msg.dateTimestamp() : (msg.dateTimestamp || 0);
			const date = dateTimestamp ? new Date(dateTimestamp * 1000).toLocaleString('es-ES') : '';
			
			// Obtener el cuerpo del mensaje
			let body = '';
			
			// Primero intentar obtener desde el DOM si está disponible
			const bodyElement = msg.body || document.querySelector(`#rl-msg-${msg.hash}`);
			if (bodyElement && bodyElement.innerHTML) {
				const tempDiv = document.createElement('div');
				tempDiv.innerHTML = bodyElement.innerHTML;
				body = tempDiv.textContent || tempDiv.innerText || '';
			}
			
			// Si no hay contenido del DOM, intentar desde los observables
			if (!body) {
				if (msg.plain && typeof msg.plain === 'function') {
					body = msg.plain() || '';
				} else if (msg.plain) {
					body = msg.plain || '';
				}
			}
			
			if (!body && msg.html) {
				// Convertir HTML a texto plano
				if (typeof msg.html === 'function') {
					const html = msg.html() || '';
					if (html) {
						const div = document.createElement('div');
						div.innerHTML = html;
						body = div.textContent || div.innerText || '';
					}
				} else if (msg.html) {
					const div = document.createElement('div');
					div.innerHTML = msg.html;
					body = div.textContent || div.innerText || '';
				}
			}

			// Construir la información del mensaje
			messageData = `De: ${from}\nAsunto: ${subject}\nFecha: ${date}\nContenido: ${body}`;
			
			console.log('AI Overview - Datos del mensaje recopilados:', {
				from,
				subject,
				date,
				bodyLength: body.length,
				totalLength: messageData.length
			});
			
		} catch (err) {
			console.error('AI Overview - Error al recopilar datos del mensaje:', err);
			showError(container, 'Error al obtener información del mensaje');
			return;
		}

		if (!messageData || messageData.length < 10) {
			console.warn('AI Overview - Datos del mensaje insuficientes');
			showError(container, 'No se pudo obtener información suficiente del mensaje');
			return;
		}

		// Mostrar contenedor y loading
		container.style.display = 'block';
		if (loading) loading.style.display = 'flex';
		if (bullets) bullets.style.display = 'none';
		if (error) error.style.display = 'none';

		// Hacer petición al backend enviando los datos del mensaje
		rl.pluginRemoteRequest(
			(iError, oData) => {
				if (iError) {
					console.error('AI Overview - Error:', iError, oData);
					showError(container, oData?.ErrorMessage || 'Error al obtener resumen');
					return;
				}

				console.log('AI Overview - Respuesta:', oData);
				console.log('AI Overview - Result:', oData?.Result);

				// Intentar diferentes estructuras de respuesta
				if (oData?.Result?.summary) {
					const summary = oData.Result.summary;
					const messageCount = oData.Result.messageCount || 1;
					showSummary(container, summary, messageCount);
				} else if (oData?.Result?.Error) {
					console.warn('AI Overview - Error del servidor:', oData.Result.Error);
					showError(container, oData.Result.Error);
				} else {
					console.warn('AI Overview - Estructura de respuesta no reconocida:', {
						hasResult: !!oData?.Result,
						resultKeys: oData?.Result ? Object.keys(oData.Result) : [],
						fullData: oData
					});
					showError(container, 'No se pudo obtener el resumen');
				}
			},
			'AiOverview',
			{
				'MessageData': messageData
			},
			60000 // 60 segundos de timeout
		);
	}

	/**
	 * Mostrar resumen
	 */
	function showSummary(container, summaryText, messageCount) {
		const loading = container.querySelector('.ai-overview-loading');
		const bullets = container.querySelector('.ai-overview-bullets');
		const error = container.querySelector('.ai-overview-error');

		if (loading) loading.style.display = 'none';
		if (error) error.style.display = 'none';

		// Parsear el resumen en bullets
		// Asumiendo que el resumen viene separado por saltos de línea o bullets
		const lines = summaryText.split('\n').filter(line => line.trim());
		
		if (bullets) {
			bullets.innerHTML = '';
			lines.forEach(line => {
				const li = document.createElement('li');
				li.innerHTML = line.trim().replace(/^[•\-\*]\s*/, ''); // Remover bullets si vienen
				bullets.appendChild(li);
			});
			bullets.style.display = 'block';
		}

		console.log('AI Overview - Resumen mostrado:', messageCount, 'mensajes');
	}

	/**
	 * Mostrar error
	 */
	function showError(container, errorMessage) {
		const loading = container.querySelector('.ai-overview-loading');
		const bullets = container.querySelector('.ai-overview-bullets');
		const error = container.querySelector('.ai-overview-error');

		if (loading) loading.style.display = 'none';
		if (bullets) bullets.style.display = 'none';
		if (error) {
			error.textContent = errorMessage;
			error.style.display = 'block';
		}
	}

	/**
	 * Configurar toggle de expandir/colapsar
	 */
	function setupToggle() {
		const container = document.querySelector('.ai-overview-container');
		if (!container) return;

		const header = container.querySelector('.ai-overview-header');
		const toggle = container.querySelector('.ai-overview-toggle');
		const content = container.querySelector('.ai-overview-content');

		if (!header || !toggle || !content) return;

		// Solo configurar si no está ya configurado
		if (header.dataset.toggleConfigured) return;

		header.dataset.toggleConfigured = 'true';

		// Agregar event listener para toggle
		header.addEventListener('click', (e) => {
			e.preventDefault();
			e.stopPropagation();
			
			const isCollapsed = content.style.display === 'none';
			content.style.display = isCollapsed ? 'block' : 'none';
			toggle.textContent = isCollapsed ? '▲' : '▼';
			container.classList.toggle('collapsed', !isCollapsed);
		});

		console.log('AI Overview - Toggle configurado');
	}

})(window.rl);
