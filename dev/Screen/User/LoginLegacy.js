import { AbstractScreen } from 'Knoin/AbstractScreen';

import { LoginLegacyView } from 'View/User/LoginLegacy';

export class LoginLegacyScreen extends AbstractScreen {
	constructor() {
		super('login-legacy', [LoginLegacyView]);
	}

	onShow() {
		rl.setTitle();
	}
}
