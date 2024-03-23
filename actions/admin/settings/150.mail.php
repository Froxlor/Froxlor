<?php

/**
 * This file is part of the Froxlor project.
 * Copyright (c) 2010 the Froxlor Team (see authors).
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, you can also view it online at
 * https://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright  the authors
 * @author     Froxlor team <team@froxlor.org>
 * @license    https://files.froxlor.org/misc/COPYING.txt GPLv2
 */

return [
	'groups' => [
		'mail' => [
			'title' => lng('admin.mailserversettings'),
			'icon' => 'fa-solid fa-envelope',
			'fields' => [
				'system_vmail_uid' => [
					'label' => lng('serversettings.vmail_uid'),
					'settinggroup' => 'system',
					'varname' => 'vmail_uid',
					'type' => 'number',
					'default' => 2000,
					'min' => 2,
					'max' => 65535,
					'save_method' => 'storeSettingField',
					'advanced_mode' => true,
					'requires_reconf' => ['smtp']
				],
				'system_vmail_gid' => [
					'label' => lng('serversettings.vmail_gid'),
					'settinggroup' => 'system',
					'varname' => 'vmail_gid',
					'type' => 'number',
					'default' => 2000,
					'min' => 2,
					'max' => 65535,
					'save_method' => 'storeSettingField',
					'advanced_mode' => true,
					'requires_reconf' => ['smtp']
				],
				'system_vmail_homedir' => [
					'label' => lng('serversettings.vmail_homedir'),
					'settinggroup' => 'system',
					'varname' => 'vmail_homedir',
					'type' => 'text',
					'string_type' => 'dir',
					'default' => '/var/customers/mail/',
					'save_method' => 'storeSettingField',
					'requires_reconf' => ['smtp']
				],
				'system_vmail_maildirname' => [
					'label' => lng('serversettings.vmail_maildirname'),
					'settinggroup' => 'system',
					'varname' => 'vmail_maildirname',
					'type' => 'text',
					'string_type' => 'dir',
					'default' => 'Maildir',
					'string_emptyallowed' => true,
					'save_method' => 'storeSettingField',
					'advanced_mode' => true
				],
				'panel_sendalternativemail' => [
					'label' => lng('serversettings.sendalternativemail'),
					'settinggroup' => 'panel',
					'varname' => 'sendalternativemail',
					'type' => 'checkbox',
					'default' => false,
					'save_method' => 'storeSettingField'
				],
				'system_mail_quota_enabled' => [
					'label' => lng('serversettings.mail_quota_enabled'),
					'settinggroup' => 'system',
					'varname' => 'mail_quota_enabled',
					'type' => 'checkbox',
					'default' => false,
					'save_method' => 'storeSettingField'
				],
				'system_mail_quota' => [
					'label' => lng('serversettings.mail_quota'),
					'settinggroup' => 'system',
					'varname' => 'mail_quota',
					'type' => 'number',
					'default' => 100,
					'save_method' => 'storeSettingField'
				],
				'catchall_catchall_enabled' => [
					'label' => lng('serversettings.catchall_enabled'),
					'settinggroup' => 'catchall',
					'varname' => 'catchall_enabled',
					'type' => 'checkbox',
					'default' => true,
					'save_method' => 'storeSettingResetCatchall'
				],
				'system_mailtraffic_enabled' => [
					'label' => lng('serversettings.mailtraffic_enabled'),
					'settinggroup' => 'system',
					'varname' => 'mailtraffic_enabled',
					'type' => 'checkbox',
					'default' => true,
					'save_method' => 'storeSettingField',
					'advanced_mode' => true
				],
				'system_mail_sni_enabled' => [
					'label' => lng('serversettings.mail_sni_enabled'),
					'settinggroup' => 'system',
					'varname' => 'mail_sni_enabled',
					'type' => 'checkbox',
					'default' => false,
					'save_method' => 'storeSettingField',
				],
				'system_mdaserver' => [
					'label' => lng('serversettings.mdaserver'),
					'settinggroup' => 'system',
					'varname' => 'mdaserver',
					'type' => 'select',
					'default' => 'dovecot',
					'select_var' => [
						'courier' => 'Courier',
						'dovecot' => 'Dovecot'
					],
					'save_method' => 'storeSettingField',
					'advanced_mode' => true
				],
				'system_mdalog' => [
					'label' => lng('serversettings.mdalog'),
					'settinggroup' => 'system',
					'varname' => 'mdalog',
					'type' => 'text',
					'string_type' => 'file',
					'default' => '/var/log/mail.log',
					'string_emptyallowed' => true,
					'save_method' => 'storeSettingField',
					'advanced_mode' => true
				],
                'system_mda_conf_dir' => [
                    'label' => lng('serversettings.mda_conf_dir'),
                    'settinggroup' => 'system',
                    'varname' => 'mda_conf_dir',
                    'type' => 'text',
                    'string_type' => 'confdir',
                    'default' => '/etc/dovecot/conf.d/',
                    'save_method' => 'storeSettingField'
                ],
                'system_mda_reload_command' => [
                    'label' => lng('serversettings.mda_reload_command'),
                    'settinggroup' => 'system',
                    'varname' => 'mda_reload_command',
                    'type' => 'text',
                    'string_regexp' => '/^[a-z0-9\/\._\- ]+$/i',
                    'default' => 'service dovecot restart',
                    'save_method' => 'storeSettingField',
                    'required_otp' => true
                ],
				'system_mtaserver' => [
					'label' => lng('serversettings.mtaserver'),
					'settinggroup' => 'system',
					'varname' => 'mtaserver',
					'type' => 'select',
					'default' => 'postfix',
					'select_var' => [
						'exim4' => 'Exim4',
						'postfix' => 'Postfix'
					],
					'save_method' => 'storeSettingField',
					'advanced_mode' => true
				],
				'system_mtalog' => [
					'label' => lng('serversettings.mtalog'),
					'settinggroup' => 'system',
					'varname' => 'mtalog',
					'type' => 'text',
					'string_type' => 'file',
					'default' => '/var/log/mail.log',
					'string_emptyallowed' => true,
					'save_method' => 'storeSettingField',
					'advanced_mode' => true
				],
                'system_mta_conf_dir' => [
                    'label' => lng('serversettings.mta_conf_dir'),
                    'settinggroup' => 'system',
                    'varname' => 'mta_conf_dir',
                    'type' => 'text',
                    'string_type' => 'confdir',
                    'default' => '/etc/postfix/',
                    'save_method' => 'storeSettingField'
                ],
                'system_mta_reload_command' => [
                    'label' => lng('serversettings.mta_reload_command'),
                    'settinggroup' => 'system',
                    'varname' => 'mta_reload_command',
                    'type' => 'text',
                    'string_regexp' => '/^[a-z0-9\/\._\- ]+$/i',
                    'default' => 'service postfix restart',
                    'save_method' => 'storeSettingField',
                    'required_otp' => true
                ],
			]
		]
	]
];
