#!/usr/bin/env php
<?php
	// This takes events from the docker logs queue logs them.

	use shanemcc\phpdb\DB;

	define('NODB', true);
	require_once(dirname(__FILE__) . '/../functions.php');

	echo showTime(), ' ', 'Container Logger started.', "\n";

	function consumeEvents($function, $bindingKey = '#') {
		RabbitMQ::get()->getChannel()->exchange_declare('docker', 'topic', false, true, false);
		RabbitMQ::get()->getChannel()->queue_bind(RabbitMQ::get()->getQueue(), 'docker', $bindingKey);

		RabbitMQ::get()->getChannel()->basic_consume(RabbitMQ::get()->getQueue(), '', false, true, false, false, function($msg) use ($function) {
			$event = @json_decode($msg->body, true);
			if (json_last_error() != JSON_ERROR_NONE) { $event = $msg->body; }

			call_user_func_array($function, [$event]);
		});
	}

	Mongo::get()->connect();
	Mongo::get()->getCollection('dockerlogs')->createIndex(['timestamp' => 1], ['expireAfterSeconds' => 5 * 24 * 60 * 60]);
	Mongo::get()->getCollection('dockerlogs')->createIndex(['docker.hostname' => 1]);
	Mongo::get()->getCollection('dockerlogs')->createIndex(['timestamp' => 1, 'docker.hostname' => 1]);

	consumeEvents(function ($event) {
		$event['timestamp'] = $event['@timestamp']; unset($event['@timestamp']);

		// Things we don't really care about.
		foreach (['@version', '@tags', 't', 'id', 'ctx', 's', 'c', 'attr', 'client'] as $t) {
			unset($event[$t]);
		}

		if (!isset($event['message'])) {
			echo 'Ignoring invalid event: ', json_encode($event), "\n";
			return;
		}

		echo sprintf('%s [%s:%s] %s', showTime(), $event['docker']['name'], $event['stream'], $event['message']), "\n";

		try {
			Mongo::get()->getCollection('dockerlogs')->insertOne($event);
		} catch (Exception $ex) {
			echo 'Error inserting event log to mongo: ', $ex->getMessage(), "\n";
			echo 'Event: ', json_encode($event), "\n";
		}
	}, 'docker.logs');

	$activeJobs = [];

	RabbitMQ::get()->consume();
