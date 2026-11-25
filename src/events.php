<?php

use Aws\Sns\SnsClient;

function events_sns_client(): SnsClient
{
    static $client = null;

    if ($client === null) {
        $client = new SnsClient([
            'version' => 'latest',
            'region' => config('AWS_REGION', 'eu-central-1'),
        ]);
    }

    return $client;
}

function publish_capsule_sealed(int $capsuleId): void
{
    events_sns_client()->publish([
        'TopicArn' => config('SNS_TOPIC_ARN'),
        'Message' => json_encode([
            'event_type' => 'capsule.sealed',
            'capsule_id' => $capsuleId,
        ], JSON_THROW_ON_ERROR),
    ]);
}