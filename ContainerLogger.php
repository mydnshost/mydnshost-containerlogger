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

	// Logs are kept in a capped collection so that a burst of log spam can
	// only push out older logs rather than fill the disk.
	$logsSize = intval(getEnvOrDefault('DOCKERLOGS_SIZE_MB', 1024)) * 1024 * 1024;
	$isCapped = null;
	foreach (Mongo::get()->getMongoDB()->listCollections(['filter' => ['name' => 'dockerlogs']]) as $info) {
		$isCapped = !empty($info->getOptions()['capped']);
	}

	if ($isCapped === null) {
		echo showTime(), ' ', 'Creating capped dockerlogs collection.', "\n";
		Mongo::get()->getMongoDB()->createCollection('dockerlogs', ['capped' => true, 'size' => $logsSize]);
	} else if (!$isCapped) {
		echo showTime(), ' ', 'Converting dockerlogs to a capped collection.', "\n";
		Mongo::get()->getMongoDB()->command(['convertToCapped' => 'dockerlogs', 'size' => $logsSize]);
	}

	// Remove indexes from before the collection was capped.
	foreach (['timestamp_1', 'docker.hostname_1', 'timestamp_1_docker.hostname_1', 'message_text'] as $index) {
		try {
			Mongo::get()->getCollection('dockerlogs')->dropIndex($index);
		} catch (Exception $ex) { }
	}

	// Matches the queries in the API: distinct hostnames, and per-host logs sorted by time.
	Mongo::get()->getCollection('dockerlogs')->createIndex(['docker.hostname' => 1, 'timestamp' => -1]);

	consumeEvents(function ($event) {
		$event['timestamp'] = $event['@timestamp']; unset($event['@timestamp']);
		$event['timestamp'] = new MongoDB\BSON\UTCDateTime(new \DateTime($event['timestamp']));

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
