/**
 * AI Overview - Interfaz de usuario
 * Muestra un resumen automático del hilo de correos usando IA
 */
(rl => {
	const templateId = 'MailMessageView';
	
	/**
	 * Verificar si debug está activado
	 */
	function isDebugEnabled() {
		return rl.pluginSettingsGet('ai-overview', 'debug') || false;
	}

	addEventListener('rl-view-model.create', e => {
		if (templateId === e.detail.viewModelTemplateID) {
			const
				template = document.getElementById(templateId),
				view = e.detail;

			// Buscar un lugar donde insertar nuestro contenido
			// Vamos a insertarlo después de .messageItemHeader
			const messageItemHeader = template.content.querySelector('.messageItemHeader');
			
			if (!messageItemHeader) {
				if (isDebugEnabled()) {
					console.warn('AI Overview - No se encontró .messageItemHeader');
				}
				return;
			}

			// Insertar el panel AI Overview después del header
			messageItemHeader.after(Element.fromHTML(`
				<div class="ai-overview-container collapsed" style="display:none !important;">
					<div class="ai-overview-header">
						<span class="ai-overview-icon">
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-subtitles-ai"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M11.5 19h-5.5a3 3 0 0 1 -3 -3v-8a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v4" /><path d="M7 15h5" /><path d="M17 12h-3" /><path d="M11 12h-1" /><path d="M19 22.5a4.75 4.75 0 0 1 3.5 -3.5a4.75 4.75 0 0 1 -3.5 -3.5a4.75 4.75 0 0 1 -3.5 3.5a4.75 4.75 0 0 1 3.5 3.5" /></svg>
						</span>
						<span class="ai-overview-title">Resumir este correo electrónico</span>
						<button class="ai-overview-toggle" title="Expandir/Colapsar">▼</button>
					</div>
					<div class="ai-overview-content" style="display:none;">
						<div class="ai-overview-loading">
							<div class="spinner"></div>
							<span class="ai-overview-loading-text">Generando Resumen General</span>
						</div>
						<ul class="ai-overview-bullets" style="display:none;"></ul>
						<div class="ai-overview-error" style="display:none;"></div>
					</div>
				</div>
			`));

			if (isDebugEnabled()) {
				console.log('AI Overview - Panel agregado al template');
			}

			// Estado por mensaje: resumen en memoria y messageId actual
			let state = { messageId: '', summary: '' };
			let currentMsg = null;

			// Delegación de clic: el template se clona al renderizar, el listener va en document
			setupToggleDelegation(state, () => currentMsg);

			// Al cambiar de mensaje: mostrar panel colapsado, sin auto-solicitar resumen
			view.message.subscribe(msg => {
				const container = document.querySelector('.ai-overview-container');
				if (!container) return;

				container.style.display = 'none';
				container.classList.remove('ai-overview-loading-border');

				if (msg) {
					state.messageId = getMessageId(msg);
					state.summary = '';
					currentMsg = msg;
					container.dataset.messageId = state.messageId;

					// Panel visible; por defecto colapsado hasta saber si hay cache
					container.style.display = 'block';
					container.style.setProperty('display', 'block', 'important');
					const content = container.querySelector('.ai-overview-content');
					const toggle = container.querySelector('.ai-overview-toggle');
					if (content) content.style.display = 'none';
					if (toggle) toggle.textContent = '▼';
					container.classList.add('collapsed');

					const loading = container.querySelector('.ai-overview-loading');
					const bullets = container.querySelector('.ai-overview-bullets');
					const error = container.querySelector('.ai-overview-error');
					if (loading) loading.style.display = 'none';
					if (bullets) bullets.style.display = 'none';
					if (error) error.style.display = 'none';

					// Si hay cache, abrir el panel y mostrar el resumen
					rl.pluginRemoteRequest(
						(iErr, oData) => {
							if (iErr || !oData?.Result?.fromCache || !oData?.Result?.summary) return;
							const summary = oData.Result.summary;
							state.summary = summary;
							if (content) content.style.display = 'block';
							if (toggle) toggle.textContent = '▲';
							container.classList.remove('collapsed');
							showSummary(container, summary, 1);
						},
						'AiOverviewGetCached',
						{ MessageId: state.messageId },
						5000
					);
				}
			});
		}
	});

	function getMessageId(msg) {
		return msg.hash || (msg.folder && msg.uid ? `${msg.folder}_${msg.uid}` : '');
	}

	/**
	 * Solicitar resumen de IA (solo al clic; muestra "Generando Resumen General" y borde animado).
	 */
	function requestAiSummary(msg, container, state) {
		if (!container) container = document.querySelector('.ai-overview-container');
		if (!container) return;

		const loading = container.querySelector('.ai-overview-loading');
		const loadingText = container.querySelector('.ai-overview-loading-text');
		const bullets = container.querySelector('.ai-overview-bullets');
		const error = container.querySelector('.ai-overview-error');

		if (loadingText) loadingText.textContent = 'Generando Resumen General';
		container.classList.add('ai-overview-loading-border');
		if (loading) loading.style.display = 'flex';
		if (bullets) bullets.style.display = 'none';
		if (error) error.style.display = 'none';

		// Recopilar información del mensaje desde el frontend
		let messageData = '';
		let messageId = '';
		let threadCount = 1;

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
			
			// Obtener ID del mensaje y conteo del hilo
			messageId = msg.hash || (msg.folder && msg.uid ? `${msg.folder}_${msg.uid}` : '');
			
			// Verificar threads - puede ser un observableArray de Knockout
			const threads = msg.threads && typeof msg.threads === 'function' ? msg.threads() : (msg.threads || []);
			const threadsLength = Array.isArray(threads) ? threads.length : 0;
			
			// Si tiene inReplyTo, sabemos que hay al menos 2 mensajes (original + respuesta)
			const inReplyTo = msg.inReplyTo && typeof msg.inReplyTo === 'function' ? msg.inReplyTo() : (msg.inReplyTo || '');
			
			// threads contiene los UIDs de otros mensajes en el hilo
			// El threadCount debe ser threads.length + 1 (para incluir el mensaje actual)
			// Si threads está vacío pero tiene inReplyTo, es al menos 2
			if (threadsLength > 0) {
				// threads contiene otros mensajes, el total es threads.length + 1 (mensaje actual)
				threadCount = threadsLength + 1;
			} else if (inReplyTo) {
				// Tiene respuesta pero threads no está poblado, asumir al menos 2
				threadCount = 2;
			} else {
				// Si pasó hasThread() pero no hay datos, usar 1 (solo este mensaje)
				threadCount = 1;
			}
			
			if (isDebugEnabled()) {
				console.log('AI Overview - Conteo de hilo:', {
					messageId,
					threadsLength,
					threads: threads,
					inReplyTo: !!inReplyTo,
					threadCountCalculado: threadCount,
					msgObject: {
						hash: msg.hash,
						folder: msg.folder,
						uid: msg.uid,
						hasThreadsFunction: typeof msg.threads === 'function',
						threadsRaw: msg.threads
					}
				});
				
				console.log('AI Overview - Datos del mensaje recopilados:', {
					from,
					subject,
					date,
					bodyLength: body.length,
					totalLength: messageData.length,
					messageId,
					threadCount
				});
			}
			
		} catch (err) {
			if (isDebugEnabled()) {
				console.error('AI Overview - Error al recopilar datos del mensaje:', err);
			}
			container.classList.remove('ai-overview-loading-border');
			showError(container, 'Error al obtener información del mensaje');
			return;
		}

		if (!messageData || messageData.length < 10) {
			if (isDebugEnabled()) {
				console.warn('AI Overview - Datos del mensaje insuficientes');
			}
			container.classList.remove('ai-overview-loading-border');
			showError(container, 'No se pudo obtener información suficiente del mensaje');
			return;
		}

		rl.pluginRemoteRequest(
			(iError, oData) => {
				container.classList.remove('ai-overview-loading-border');

				if (iError) {
					if (isDebugEnabled()) {
						console.error('AI Overview - Error:', iError, oData);
					}
					showError(container, oData?.ErrorMessage || 'Error al obtener resumen');
					return;
				}

				if (isDebugEnabled()) {
					console.log('AI Overview - Respuesta:', oData);
					console.log('AI Overview - Result:', oData?.Result);
				}

				if (oData?.Result?.summary) {
					const summary = oData.Result.summary;
					const messageCount = oData.Result.messageCount || 1;
					if (state) state.summary = summary;
					showSummary(container, summary, messageCount);
				} else if (oData?.Result?.Error) {
					if (isDebugEnabled()) {
						console.warn('AI Overview - Error del servidor:', oData.Result.Error);
					}
					showError(container, oData.Result.Error);
				} else {
					if (isDebugEnabled()) {
						console.warn('AI Overview - Estructura de respuesta no reconocida:', {
							hasResult: !!oData?.Result,
							resultKeys: oData?.Result ? Object.keys(oData.Result) : [],
							fullData: oData
						});
					}
					showError(container, 'No se pudo obtener el resumen');
				}
			},
			'AiOverview',
			{
				'MessageData': messageData,
				'MessageId': messageId,
				'ThreadCount': threadCount
			},
			60000
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

		if (isDebugEnabled()) {
			console.log('AI Overview - Resumen mostrado:', messageCount, 'mensajes');
		}
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
	 * Delegación de clic en document: el panel está en un clon del template, así el clic se captura.
	 */
	function setupToggleDelegation(state, getCurrentMsg) {
		if (document.body.dataset.aiOverviewDelegation) return;
		document.body.dataset.aiOverviewDelegation = 'true';

		document.body.addEventListener('click', (e) => {
			const header = e.target.closest('.ai-overview-header');
			if (!header) return;

			const container = header.closest('.ai-overview-container');
			if (!container) return;

			const toggle = container.querySelector('.ai-overview-toggle');
			const content = container.querySelector('.ai-overview-content');
			if (!toggle || !content) return;

			e.preventDefault();
			e.stopPropagation();

			const isCollapsed = content.style.display === 'none';

			if (isCollapsed) {
				content.style.display = 'block';
				toggle.textContent = '▲';
				container.classList.remove('collapsed');

				if (state.summary) {
					showSummary(container, state.summary, 1);
				} else {
					const msg = getCurrentMsg();
					if (msg) {
						requestAiSummary(msg, container, state);
					}
				}
			} else {
				content.style.display = 'none';
				toggle.textContent = '▼';
				container.classList.add('collapsed');
				container.classList.remove('ai-overview-loading-border');

				if (state.messageId) {
					rl.pluginRemoteRequest(
						() => {},
						'AiOverviewClearCache',
						{ MessageId: state.messageId },
						5000
					);
				}
				state.summary = '';
			}
		});
	}

})(window.rl);
