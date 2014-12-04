(function () {
	'use strict';

	var UserLoginModal = {
		loginAjaxForm: false,
		isInitializationStarted: false,
		uiFactory: false,
		packagesData: false,
		$modal: false,
		bucky: window.Bucky('UserLoginModal'),

		initModal: function (options) {
			var self = this;

			this.bucky.timer.start('initModal');

			require(['wikia.tracker'], function (tracker) {
				self.trackerActions = tracker.ACTIONS;
				self.track = tracker.buildTrackingFunction({
					category: 'force-login-modal',
					trackingMethod: 'both'
				});
			});

			$.when(
				Wikia.getMultiTypePackage({
					templates: [{
						controller: 'UserLoginSpecial',
						method: 'modal',
						params: {
							uselang: window.wgUserLanguage
						}
					}],
					styles: '/extensions/wikia/UserLogin/css/UserLoginModal.scss',
					messages: 'UserLogin'
				})
			).done(function (packagesData) {
				require(['wikia.ui.factory', 'wikia.loader'], function (uiFactory, loader) {
					loader.processStyle(packagesData.styles);

					self.uiFactory = uiFactory;
					self.packagesData = packagesData;

					if (window.UserLoginFacebook) {
						window.UserLoginFacebook.init(window.UserLoginFacebook.origins.MODAL);
					}

					self.buildModal(options);
					self.bucky.timer.stop('initModal');
				});
			});
		},

		buildModal: function (options) {
			var self = this,
				origin = '',
				clickAction = this.trackerActions.CLICK;

			// start performance tracking
			this.bucky.timer.start('buildModal');

			this.uiFactory.init('modal').then(function (uiModal) {
				var modalTemplateName = 'UserLoginSpecial_modal',
					modalConfig = {
						type: 'default',
						vars: {
							id: 'userForceLoginModal',
							size: 'medium',
							content: self.packagesData.templates[modalTemplateName],
							title: $.msg('userlogin-login-heading'),
							closeButton: true
						}
					};

				uiModal.createComponent(modalConfig, function (loginModal) {
					UserLoginModal.$modal = loginModal;

					var $loginModal = loginModal.$element;

					UserLoginModal.loginAjaxForm = new window.UserLoginAjaxForm($loginModal, {
						ajaxLogin: true,
						// context is this instance of UserLoginAjaxForm
						callback: function (res) {
							window.wgUserName = res.username;
							var callback = options.callback;
							if (callback && typeof callback === 'function') {
								if (!options.persistModal) {
									UserLoginModal.$modal.trigger('close');
								}
								callback();
							} else {
								this.reloadPage();
							}
						},
						// context is this instance of UserLoginAjaxForm
						resetpasscallback: function () {
							$.nirvana.sendRequest({
								controller: 'UserLoginSpecial',
								method: 'changePassword',
								format: 'html',
								data: {
									username: this.inputs.username.val(),
									password: this.inputs.password.val(),
									returnto: this.inputs.returnto.val(),
									fakeGet: 1
								},
								callback: function (html) {
									var content = $('<div style="display:none" />').append(html),
										heading = content.find('h1'),
										modal = loginModal,
										contentBlock = $loginModal.find('.UserLoginModal');

									modal.setTitle(heading.text());
									heading.remove();

									contentBlock.slideUp(400, function () {
										contentBlock.html('').html(content);
										content.show();
										contentBlock.slideDown(400);
									});
								}
							});
						}
					});

					if (options.modalInitCallback && typeof options.modalInitCallback === 'function') {
						options.modalInitCallback();
					}

					if (options.origin) {
						origin = options.origin;
					}

					self.track({
						action: self.trackerActions.OPEN,
						label: 'from-' + origin
					});

					// Click tracking
					$loginModal.on('click', '.forgot-password', function () {
						self.track({
							action: clickAction,
							label: 'forgot-password'
						});
					}).on('click', 'input.keep-logged-in', function () {
						self.track({
							action: clickAction,
							label: 'keep-me-logged-in'
						});
					}).on('click', '.get-account a', function () {
						self.track({
							action: clickAction,
							label: 'sign-up-from-' + origin
						});
					}).on('click', '.sso-login-facebook', function () {
						self.track({
							action: clickAction,
							label: 'facebook-connect'
						});
					}).on('click', 'input.login-button', function () {
						self.track({
							action: clickAction,
							label: 'login-from-' + origin
						});
					});
					// End performance tracking
					self.bucky.timer.stop('buildModal');
				});
			});
		},

		/**
		 * options (optional):
		 *  callback: callback after login successful login
		 * returns: true if modal is shown, false if it is not
		 */
		show: function (options) {
			if (!window.wgComboAjaxLogin) {
				                               // @lixlux - always true, therefore unneeded?
				options = options || {};

				options.modalInitCallback = $.proxy(function () {
					this.$modal.show();
				}, this);
				this.initModal(options);

				return true;
			} else {
				/* 1st, 2nd, 4th, and 5th vars in this method is not used outside of ajaxlogin itself*/
				window.showComboAjaxForPlaceHolder(false, false, function () {
					if (options.callback) {
						window.AjaxLogin.doSuccess = options.callback;
					}
				}, false, true);

				return true;
			}

			return false;
		},
		isPreventingForceLogin: function (element) {
			if (!(element.closest('span').hasClass('drop')) &&
				!(element.closest('ul').hasClass('WikiaMenuElement'))) {
				return false;
			}
			return true;
		},
		init: function () {
			// attach event handler
			var editpromptable = $('#te-editanon, .loginToEditProtectedPage, .upphotoslogin');

			// add .editsection on wikis with anon editing disabled
			if (window.wgDisableAnonymousEditing) {
				editpromptable = editpromptable.add('.editsection');
			}

			editpromptable.click($.proxy(function (ev) {
				ev.stopPropagation(); // (BugId:34026) stop bubbling up when parent and child both have event listener.

				if (!this.isPreventingForceLogin($(ev.target)) && window.UserLogin.isForceLogIn()) {
					ev.preventDefault();
				}
			}, this));

			//Attach DOM-Ready handlers
			$('body').delegate('.ajaxLogin', 'click', function (e) {
				UserLoginModal.show();
				e.preventDefault();
			});
		}
	};

	// expose globally
	window.UserLoginModal = UserLoginModal;

	$(function () {
		UserLoginModal.init();
	});
})();
