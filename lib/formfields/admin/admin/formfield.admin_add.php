<?php

/**
 * This file is part of the Froxlor project.
 * Copyright (c) 2010 the Froxlor Team (see authors).
 *
 * For the full copyright and license information, please view the COPYING
 * file that was distributed with this source code. You can also view the
 * COPYING file online at http://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright  (c) the authors
 * @author     Froxlor team <team@froxlor.org> (2010-)
 * @license    GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @package    Formfields
 *
 */

use Froxlor\Settings;

return array(
	'admin_add' => array(
		'title' => $lng['admin']['admin_add'],
		'image' => 'fa-solid fa-user-plus',
		'sections' => array(
			'section_a' => array(
				'title' => $lng['admin']['accountdata'],
				'image' => 'icons/user_add.png',
				'fields' => array(
					'new_loginname' => array(
						'label' => $lng['login']['username'],
						'type' => 'text',
						'mandatory' => true
					),
					'admin_password' => array(
						'label' => $lng['login']['password'],
						'type' => 'password',
						'mandatory' => true,
						'autocomplete' => 'off',
						'next_to' => [
							'admin_password_suggestion' => array(
								'next_to_prefix' => $lng['customer']['generated_pwd'].':',
								'type' => 'text',
								'visible' => (Settings::Get('panel.password_regex') == ''),
								'value' => \Froxlor\System\Crypt::generatePassword(),
								'readonly' => true
							)
						]
					),
					'def_language' => array(
						'label' => $lng['login']['language'],
						'type' => 'select',
						'select_var' => $languages,
						'selected' => $userinfo['language']

					),
					'api_allowed' => array(
						'label' => $lng['usersettings']['api_allowed']['title'],
						'desc' => $lng['usersettings']['api_allowed']['description'],
						'type' => 'checkbox',
						'value' => '1',
						'checked' => Settings::Get('api.enabled') == '1',
						'visible' => Settings::Get('api.enabled') == '1'
					)
				)
			),
			'section_b' => array(
				'title' => $lng['admin']['contactdata'],
				'image' => 'icons/user_add.png',
				'fields' => array(
					'name' => array(
						'label' => $lng['customer']['name'],
						'type' => 'text',
						'mandatory' => true
					),
					'email' => array(
						'label' => $lng['customer']['email'],
						'type' => 'text',
						'mandatory' => true
					),
					'custom_notes' => array(
						'label' => $lng['usersettings']['custom_notes']['title'],
						'desc' => $lng['usersettings']['custom_notes']['description'],
						'type' => 'textarea',
						'cols' => 60,
						'rows' => 12
					),
					'custom_notes_show' => array(
						'label' => $lng['usersettings']['custom_notes']['show'],
						'type' => 'checkbox',
						'value' => '1',
						'checked' => false
					)
				)
			),
			'section_c' => array(
				'title' => $lng['admin']['servicedata'],
				'image' => 'icons/user_add.png',
				'fields' => array(
					'ipaddress' => array(
						'label' => $lng['serversettings']['ipaddress']['title'],
						'type' => 'select',
						'select_var' => $ipaddress
					),
					'change_serversettings' => array(
						'label' => $lng['admin']['change_serversettings'],
						'type' => 'checkbox',
						'value' => '1',
						'checked' => false
					),
					'customers' => array(
						'label' => $lng['admin']['customers'],
						'type' => 'textul',
						'value' => 0,
						'maxlength' => 9,
						'mandatory' => true
					),
					'customers_see_all' => array(
						'label' => $lng['admin']['customers_see_all'],
						'type' => 'checkbox',
						'value' => '1',
						'checked' => false
					),
					'domains' => array(
						'label' => $lng['admin']['domains'],
						'type' => 'textul',
						'value' => 0,
						'maxlength' => 9,
						'mandatory' => true
					),
					'domains_see_all' => array(
						'label' => $lng['admin']['domains_see_all'],
						'type' => 'checkbox',
						'value' => '1',
						'checked' => false
					),
					'caneditphpsettings' => array(
						'label' => $lng['admin']['caneditphpsettings'],
						'type' => 'checkbox',
						'value' => '1',
						'checked' => false
					),
					'diskspace' => array(
						'label' => $lng['customer']['diskspace'] . ' (' . $lng['customer']['mib'] . ')',
						'type' => 'textul',
						'value' => 0,
						'maxlength' => 6,
						'mandatory' => true
					),
					'traffic' => array(
						'label' => $lng['customer']['traffic'] . ' (' . $lng['customer']['gib'] . ')',
						'type' => 'textul',
						'value' => 0,
						'maxlength' => 4,
						'mandatory' => true
					),
					'subdomains' => array(
						'label' => $lng['customer']['subdomains'],
						'type' => 'textul',
						'value' => 0,
						'maxlength' => 9,
						'mandatory' => true
					),
					'emails' => array(
						'label' => $lng['customer']['emails'],
						'type' => 'textul',
						'value' => 0,
						'maxlength' => 9,
						'mandatory' => true
					),
					'email_accounts' => array(
						'label' => $lng['customer']['accounts'],
						'type' => 'textul',
						'value' => 0,
						'maxlength' => 9,
						'mandatory' => true
					),
					'email_forwarders' => array(
						'label' => $lng['customer']['forwarders'],
						'type' => 'textul',
						'value' => 0,
						'maxlength' => 9,
						'mandatory' => true
					),
					'email_quota' => array(
						'label' => $lng['customer']['email_quota'] . ' (' . $lng['customer']['mib'] . ')',
						'type' => 'textul',
						'value' => 0,
						'maxlength' => 9,
						'visible' => Settings::Get('system.mail_quota_enabled') == '1',
						'mandatory' => true
					),
					'ftps' => array(
						'label' => $lng['customer']['ftps'],
						'type' => 'textul',
						'value' => 0,
						'maxlength' => 9
					),
					'mysqls' => array(
						'label' => $lng['customer']['mysqls'],
						'type' => 'textul',
						'value' => 0,
						'maxlength' => 9,
						'mandatory' => true
					)
				)
			)
		)
	)
);
