<?php

# Ignore inline messages (via @)
if ($v->via_bot) die;

function del ($text, $string) {
	if (is_array($text)) {
		foreach ($text as $text) {
			$string = str_replace($text, '', $string);
		}
		return $string;
	}
	return str_replace($text, '', $string);
}

function delTags ($text, $s = 0) {
	$safe_tags = ['strong', 'b', 'italic', 'i', 'a', 'code'];
	$e = explode('<', $text);
	if ($text[0] != '<') unset($e[0]);
	foreach ($e as $string) {
		$html_tag = explode('>', $string, 2)[0];
		$tag = explode(' ', del('/', $html_tag), 2)[0];
		if (!in_array($tag, $safe_tags) or empty($tag)) {
			$tags .= PHP_EOL . '<' . $html_tag . '>';
			if (!empty($html_tag)) $text = del('<' . $html_tag . '>', $text);
		}
	}
	return $text;
}

function getPHPFunction ($function, $db = [], $update = false) {
	# Supported languages by php.net (Available with database only)
	$supported_languages = ['de', 'en', 'es', 'ja', 'fr', 'pt_BR', 'ru', 'tr', 'zh'];
	
	# Use database only to set and get cache from Redis and/or Database
	if (!is_a($db, 'Database')) unset($db);
	
	if (!$function) return ['ok' => false, 'error' => 400, 'description' => 'Bad Request: missing function parameter'];
	if (isset($db) && in_array($db->user['lang'], $supported_languages)) {
		$lang = $db->user['lang'];
	} else {
		$lang = 'en';
	}
	$all['name'] = strtolower($function);
	$all['lang'] = $lang;
	$function = str_replace(' ', '', str_replace('_', '-', $all['name']));
	if (!$update) {
		if (isset($db) && $db->configs['redis']['status']) {
			if ($rredis = json_decode($db->rget('PHPFunction-' . $lang . '-' . $function), true)) {
				if ($rredis) return ['ok' => true, 'result' => $rredis, 'from' => 'redis'];
			}
		}
		if (isset($db) && $db->configs['database']['status']) {
			$rdb = $db->query('SELECT * FROM functions WHERE function_name = ? and lang = ?', [$function, $lang], 1);
			if ($db->configs['redis']['status']) $db->rset('PHPFunction-' . $lang . '-' . $function, json_encode($rdb), 60 * 60 * 2);
			if ($rdb['function_name'] == $function) return ['ok' => true, 'result' => $rdb, 'from' => 'database'];
		}
	}
	$all['url'] = 'https://php.net/manual/' . $lang . '/function.' . $function . '.php';
	$get = file_get_contents($all['url']);
	if (!$get) return ['ok' => false, 'error' => 404, 'description' => 'Not Found: function not found'];
	$all['function_name'] = $function;
	$get = str_replace('a href=\'/', 'a href=\'https://php.net/', $get);
	$get = del('&nbsp;', $get);
	$all['versions'] = explode('</p>', explode('<p class="verinfo">', $get)[1])[0];
	$all['versions'] = del(['(', ')'], $all['versions']);
	$all['versions'] = delTags($all['versions']);
	$all['about'] = explode('</span>', explode('<span class="dc-title">', $get)[1])[0];
	$all['about'] = delTags($all['about']);
	$all['description'] = explode('</p>', explode('<p class="para rdfs-comment">', $get)[1])[0];
	$all['description'] = del([PHP_EOL . '   ', PHP_EOL], $description);
	$all['description'] = delTags($all['description']);
	$all['syntax'] = explode('</div>', explode('<h3 class="title">Description</h3>', $get)[1])[0];
	$all['syntax'] = str_replace(PHP_EOL . '   , ', ', ', $all['syntax']);
	$all['syntax'] = del([PHP_EOL . '   ', PHP_EOL . ' '], $all['syntax']);
	$all['syntax'] = delTags($all['syntax'], 1);
	$all['returns'] = explode('</p>', explode('<h3 class="title">Return Values</h3>', $get)[1])[0];
	$all['returns'] = del([PHP_EOL . '  '], $all['returns']);
	$all['returns'] = delTags($all['returns']);
	$all['last_update'] = time();
	if ($db->configs['database']['status']) $db->query('INSERT INTO functions (function_name, about, versions, description, syntax, returns, lang, last_update) VALUES (?,?,?,?,?,?,?)', [$all['function_name'], $all['about'], $all['versions'], $all['description'], $all['syntax'], $all['returns'], $all['lang'], $all['last_update']]);
	if ($db->configs['redis']['status']) $db->rset('PHPFunction-' . $lang . '-' . $function, json_encode($all), 60 * 60 * 2);
	return ['ok' => true, 'result' => $all, 'from' => 'request'];
}

function createMessage ($function, $bot, $tr) {
	$text = $bot->bold($function['name']) . PHP_EOL;
	if ($function['about']) $text .= '• ' . $function['about'] . PHP_EOL;
	if ($function['versions']) {
		$text .= PHP_EOL . $bot->bold($tr->getTranslation('versions')) . PHP_EOL;
		$text .= '• ' . $function['versions'] . PHP_EOL;
	}
	if ($function['description']) {
		$text .= PHP_EOL . $bot->bold($tr->getTranslation('description')) . PHP_EOL;
		$text .= '• ' . $function['description'] . PHP_EOL;
	}
	if ($function['syntax']) {
		$text .= PHP_EOL . $bot->bold($tr->getTranslation('syntax')) . PHP_EOL;
		$text .= '• ' . $function['syntax'] . PHP_EOL;
	}
	if ($function['returns']) {
		$text .= PHP_EOL . $bot->bold($tr->getTranslation('returnValues')) . PHP_EOL;
		$text .= '• ' . $function['returns'] . PHP_EOL;
	}
	if ($function['last_update'] >= time() - 5) {
		$update = $tr->getTranslation('updatedNow');
	} elseif ($function['last_update'] >= time() - (60 * 60)) {
		$update = $tr->getTranslation('updatedMinutesAgo', [round((time() - $function['last_update']) / 60)]);
	} elseif ($function['last_update'] >= time() - (60 * 60 * 23)) {
		$update = $tr->getTranslation('updatedHoursAgo', [round((time() - $function['last_update']) / 60 / 60)]);
	} else {
		$update = $tr->getTranslation('updatedOn', [date('d-m-Y', $function['last_update'])]);
	}
	$text .= PHP_EOL . $bot->italic($update, 1);
	return $text;
}

# Pass user variable to the database
$db->user = $user;

# Callback Query
if (strpos($v->query_data, 'update-') === 0) {
	$bot->answerCBQ($v->query_id, $tr->getTranslation('loading'));
	$f = getPHPFunction(del('update-', $v->query_data), $db, true);
	if ($f['ok']) {
		$t = createMessage($f['result'], $bot, $tr);
		$buttons[] = [
			$bot->createInlineButton($tr->getTranslation('updateData'), 'update-' . $f['result']['name']),
			$bot->createInlineButton($tr->getTranslation('shareFunction'), $f['result']['name'], 'switch_inline_query'),
		];
	} else {
		$t = $tr->getTranslation('functionNotFound');
	}
	if (!$v->message_id) $v->message_id = $v->inline_message_id;
	$bot->editText($v->chat_id, $v->message_id, $t, $buttons);
}

# Private chat with Bot
if ($v->chat_type == 'private') {
	if ($bot->configs['database']['status'] && $user['status'] !== 'started') $db->setStatus($v->user_id, 'started');
	# Create the new functions table (Bot-Admin only)
	if ($v->isAdmin() && $v->command == 'create_functions') {
		$q = $db->query('CREATE TABLE IF NOT EXISTS functions (
		function_name	VARCHAR(4096)	DEFAULT \'\',
		versions		VARCHAR(4096)	DEFAULT \'\',
		about			VARCHAR(4096)	DEFAULT \'\',
		description		VARCHAR(4096)	DEFAULT \'\',
		syntax			VARCHAR(4096)	DEFAULT \'\',
		returns			VARCHAR(4096)	DEFAULT \'\',
		lang			VARCHAR(4096)	DEFAULT \'\',
		last_update		INT				DEFAULT \'0\');');
		$bot->sendMessage($v->chat_id, $bot->bold('Result: ') . $bot->code(json_encode($q)));
	}
	# Drop all functions recorded (Bot-Admin only)
	elseif ($v->isAdmin() && $v->command == 'drop') {
		$db->query('DELETE FROM functions');
		$db->query('DROP TABLLE functions');
		if ($db->configs['redis']['status']) $db->rdel($db->rkeys('PHPFunction-*'));
		$bot->sendMessage($v->chat_id, 'Done');
	}
	# Example command (Bot-Admin only)
	elseif ($v->isAdmin() && $v->command == 'example') {
		$function = 'str_replace';
		$f = getPHPFunction($function, $db);
		file_put_contents('example.json', json_encode($f, JSON_PRETTY_PRINT));
		$bot->sendMessage($v->chat_id, $bot->bold('Result: ') . $bot->code(substr(json_encode($f, JSON_PRETTY_PRINT), 0, 1024), 1));
	}
	# Start command
	elseif ($v->command == 'start' || $v->query_data == 'start') {
		$buttons[][] = $bot->createInlineButton($tr->getTranslation('switchInlineMode'), '', 'switch_inline_query');
		$buttons[] = [
			$bot->createInlineButton($tr->getTranslation('helpButton'), 'help'),
			$bot->createInlineButton($tr->getTranslation('aboutButton'), 'about'),
		];
		$buttons[][] = $bot->createInlineButton($tr->getTranslation('changeLanguage'), 'changeLanguage');
		$t = $tr->getTranslation('startMessage') . PHP_EOL . 
		'@NeleBots';
		if ($v->query_data) {
			$bot->editText($v->chat_id, $v->message_id, $t, $buttons);
			$bot->answerCBQ($v->query_id, $cbtext, $show);
		} else {
			$bot->sendMessage($v->chat_id, $t, $buttons);
		}
	}
	# Help command
	elseif ($v->command == 'help' || $v->query_data == 'help') {
		$buttons[] = [$bot->createInlineButton($tr->getTranslation('switchInlineMode'), '', 'switch_inline_query')];
		$buttons[] = [$bot->createInlineButton('◀️', 'start')];
		$t = $tr->getTranslation('helpMessage');
		if ($v->query_data) {
			$bot->editText($v->chat_id, $v->message_id, $t, $buttons);
			$bot->answerCBQ($v->query_id, $cbtext, $show);
		} else {
			$bot->sendMessage($v->chat_id, $t, $buttons);
		}
	}
	# About command
	elseif ($v->command == 'about' || $v->query_data == 'about') {
		$buttons[] = [$bot->createInlineButton('◀️', 'start')];
		$t = $tr->getTranslation('aboutMessage', [explode(' ', phpversion())[0]]);
		if ($v->query_data) {
			$bot->editText($v->chat_id, $v->message_id, $t, $buttons);
			$bot->answerCBQ($v->query_id, $cbtext, $show);
		} else {
			$bot->sendMessage($v->chat_id, $t, $buttons);
		}
	}
	# Change language
	elseif (strpos($v->query_data, 'changeLanguage') === 0) {
		$langnames = [
			'de' => '🇩🇪 Deutsch',
			'en' => '🇬🇧 English',
			'es' => '🇪🇸 Español',
			'it' => '🇮🇹 Italiano',
			'fr' => '🇫🇷 Français',
			'pt_BR' => '🇵🇹 Português',
			'zh-TW' => '🇨🇳 简体中文'
		];
		if (strpos($v->query_data, 'changeLanguage-') === 0) {
			$select = str_replace('changeLanguage-', '', $v->query_data);
			if (in_array($select, array_keys($langnames))) {
				$tr->setLanguage($user['lang'] = $select);
				$db->query('UPDATE users SET lang = ? WHERE id = ?', [$user['lang'], $user['id']]);
			}
		}
		$langnames[$user['lang']] .= ' ✅';
		$formenu = 2;
		$mcount = 0;
		foreach ($langnames as $lang_code => $name) {
			if (isset($buttons[$mcount]) && count($buttons[$mcount]) >= $formenu) $mcount += 1;
			$buttons[$mcount][] = $bot->createInlineButton($name, 'changeLanguage-' . $lang_code);
		}
		$buttons[][] = $bot->createInlineButton('◀️', 'start');
		$bot->editText($v->chat_id, $v->message_id, 'Set your language:', $buttons);
		$bot->answerCBQ($v->query_id);
	}
	# Search php function
	elseif (isset($v->text) && strlen($v->text) <= 128) {
		$f = getPHPFunction($v->text, $db);
		if ($f['ok']) {
			$t = createMessage($f['result'], $bot, $tr);
			$buttons[] = [
				$bot->createInlineButton($tr->getTranslation('updateData'), 'update-' . $f['result']['name']),
				$bot->createInlineButton($tr->getTranslation('shareFunction'), $f['result']['name'], 'switch_inline_query'),
			];
		} else {
			$t = $tr->getTranslation('functionNotFound');
		}
		$bot->sendMessage($v->chat_id, $t, $buttons);
	}
	# Unknown command/button/action
	else {
		if ($v->command) $t = '😶 ' . $tr->getTranslation('unknownCommand');
		if ($v->query_id) {
			$bot->answerCBQ($v->query_id, $t);
		} else {
			$bot->sendMessage($v->chat_id, $t);
		}
	}
}

# Inline commands
if ($v->update['inline_query']) {
	$description = $tr->getTranslation('inlineHelpMessage');
	$content = $bot->createTextInput('¯\_(ツ)_/¯');
	$buttons = [];
	if ($v->query) {
		$f = getPHPFunction($v->query, $db);
		if ($f['ok']) {
			$id = $f['result']['name'];
			$title = $f['result']['name'];
			$description = $f['result']['about'];
			$content = $bot->createTextInput(createMessage($f['result'], $bot, $tr));
			$buttons = [
				[
					$bot->createInlineButton($tr->getTranslation('updateData'), 'update-' . $f['result']['name']),
					$bot->createInlineButton($tr->getTranslation('shareFunction'), $f['result']['name'], 'switch_inline_query'),
				]
			];
		} else {
			$id = 404;
			$title = $tr->getTranslation('functionNotFound');
		}
	} else {
		$id = 200;
		$title = 'PHP Index';
	}
	$results[] = $bot->createInlineArticle($id, $title, $description, $content, $buttons);
	$bot->answerIQ($v->id, $results);
}

?>
