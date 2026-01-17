/**
 * Mostrar indicador de correos externos en MailMessageView
 */
(rl => {
	const templateId = 'MailMessageView';

	// Modificar el template al cargar (una sola vez)
	addEventListener('rl-view-model.create', e => {
		if (templateId === e.detail.viewModelTemplateID) {
			const template = document.getElementById(templateId);
			const infoShort = template.content.querySelector('.informationShort');
			
			if (infoShort && !infoShort.querySelector('.external-badge-container')) {
				// Añadir badge "Externo" estilo Gmail
				infoShort.append(Element.fromHTML(`
					<span class="external-badge-container" data-bind="visible: message() && message().flags && message().flags().includes('$external')" style="display:none;">
						<span class="external-badge">Externo</span>
					</span>
				`));
				console.log('Mark External - Badge "Externo" añadido al template');
			}
		}
	});

	// Logs de debugging cuando se carga un mensaje
	addEventListener('rl-view-model', e => {
		if (templateId === e.detail.viewModelTemplateID) {
			const viewModel = e.detail.viewModel;
			if (viewModel.message) {
				viewModel.message.subscribe(msg => {
					if (msg && msg.flags) {
						const flags = msg.flags();
						if (flags.includes('$external')) {
							console.log('Mark External - Correo externo detectado:', msg.from?.[0]?.email || 'N/A');
						}
					}
				});
			}
		}
	});

})(window.rl);
