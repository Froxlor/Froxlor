<?php

namespace Froxlor\UI\Callbacks;

use Froxlor\UI\Panel\UI;

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
 * @package    Froxlor\UI\Callbacks
 *
 */
class Admin
{
	public static function canChangeServerSettings(array $attributes)
	{
		return (bool)UI::getCurrentUser()['change_serversettings'];
	}

	public static function isNotMe(array $attributes)
	{
		return (UI::getCurrentUser()['adminid'] != $attributes['fields']['adminid']);
	}
}