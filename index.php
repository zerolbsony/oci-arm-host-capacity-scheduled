<?php
declare(strict_types=1);


// useful when script is being executed by cron user
$pathPrefix = ''; // e.g. /usr/share/nginx/oci-arm-host-capacity/

require "{$pathPrefix}vendor/autoload.php";

use Dotenv\Dotenv;
use Hitrov\Exception\ApiCallException;
use Hitrov\FileCache;
use Hitrov\OciApi;
use Hitrov\OciConfig;
use Hitrov\TooManyRequestsWaiter;

function reportResult(string $status, string $message, int $exitCode = 0): void
{
    $message = preg_replace('/ocid1\.[a-zA-Z0-9._-]+/', '[redacted OCID]', $message);
    echo "$status: $message\n";
    if (getenv('GITHUB_OUTPUT')) {
        file_put_contents(getenv('GITHUB_OUTPUT'), "result=$status\n", FILE_APPEND);
    }
    if (getenv('GITHUB_STEP_SUMMARY')) {
        file_put_contents(getenv('GITHUB_STEP_SUMMARY'), "OCI result: **$status**\n\n$message\n", FILE_APPEND);
    }
    exit($exitCode);
}

set_exception_handler(function (Throwable $error): void {
    reportResult('failed', $error->getMessage(), 1);
});

$envFilename = empty($argv[1]) ? '.env' : $argv[1];
$dotenv = Dotenv::createUnsafeImmutable(__DIR__, $envFilename);
$dotenv->safeLoad();

foreach ([
    'OCI_REGION', 'OCI_USER_ID', 'OCI_TENANCY_ID', 'OCI_KEY_FINGERPRINT',
    'OCI_PRIVATE_KEY_FILENAME', 'OCI_SUBNET_ID', 'OCI_IMAGE_ID',
    'OCI_OCPUS', 'OCI_MEMORY_IN_GBS', 'OCI_SHAPE', 'OCI_MAX_INSTANCES',
    'OCI_SSH_PUBLIC_KEY',
] as $requiredVariable) {
    if (!getenv($requiredVariable)) {
        reportResult('failed', "Missing required configuration: $requiredVariable", 1);
    }
}
if (!is_readable(getenv('OCI_PRIVATE_KEY_FILENAME'))) {
    reportResult('failed', 'The OCI private key file is not readable.', 1);
}
if (
    getenv('OCI_REGION') !== 'us-ashburn-1'
    || getenv('OCI_SHAPE') !== 'VM.Standard.A1.Flex'
    || (int) getenv('OCI_OCPUS') !== 1
    || (int) getenv('OCI_MEMORY_IN_GBS') !== 6
    || (int) getenv('OCI_BOOT_VOLUME_SIZE_IN_GBS') !== 50
    || getenv('OCI_BOOT_VOLUME_ID')
) {
    reportResult('failed', 'Refusing to launch outside the approved Always Free profile: Ashburn A1, 1 OCPU, 6 GB memory, 50 GB boot volume.', 1);
}

/*
 * No need to modify any value in this file anymore!
 * Copy .env.example to .env and adjust there instead.
 *
 * README.md now has all the information.
 */
$config = new OciConfig(
    getenv('OCI_REGION'),
    getenv('OCI_USER_ID'),
    getenv('OCI_TENANCY_ID'),
    getenv('OCI_KEY_FINGERPRINT'),
    getenv('OCI_PRIVATE_KEY_FILENAME'),
    getenv('OCI_AVAILABILITY_DOMAIN') ?: null, // null or '' or 'jYtI:PHX-AD-1' or ['jYtI:PHX-AD-1','jYtI:PHX-AD-2']
    getenv('OCI_SUBNET_ID'),
    getenv('OCI_IMAGE_ID'),
    (int) getenv('OCI_OCPUS'),
    (int) getenv('OCI_MEMORY_IN_GBS')
);

$bootVolumeSizeInGBs = (string) getenv('OCI_BOOT_VOLUME_SIZE_IN_GBS');
$bootVolumeId = (string) getenv('OCI_BOOT_VOLUME_ID');
if ($bootVolumeSizeInGBs) {
    $config->setBootVolumeSizeInGBs($bootVolumeSizeInGBs);
} elseif ($bootVolumeId) {
    $config->setBootVolumeId($bootVolumeId);
}

$api = new OciApi();
if (getenv('CACHE_AVAILABILITY_DOMAINS')) {
    $api->setCache(new FileCache($config));
}
if (getenv('TOO_MANY_REQUESTS_TIME_WAIT')) {
    $api->setWaiter(new TooManyRequestsWaiter((int) getenv('TOO_MANY_REQUESTS_TIME_WAIT')));
}
$notifier = (function (): \Hitrov\Interfaces\NotifierInterface {
    /*
     * if you have own https://core.telegram.org/bots
     * and set TELEGRAM_BOT_API_KEY and your TELEGRAM_USER_ID in .env
     *
     * then you can get notified when script will succeed.
     * otherwise - don't mind OR develop you own NotifierInterface
     * to e.g. send SMS or email.
     */
    return new \Hitrov\Notification\Telegram();
})();

$shape = getenv('OCI_SHAPE');

$maxRunningInstancesOfThatShape = 1;
if (getenv('OCI_MAX_INSTANCES') !== false) {
    $maxRunningInstancesOfThatShape = (int) getenv('OCI_MAX_INSTANCES');
}
if ($maxRunningInstancesOfThatShape !== 1) {
    reportResult('failed', 'This scheduled deployment requires OCI_MAX_INSTANCES=1.', 1);
}

$instances = $api->getInstances($config);

$existingInstances = $api->checkExistingInstances($config, $instances, $shape, $maxRunningInstancesOfThatShape);
if ($existingInstances) {
    reportResult('already_exists', 'An active instance of the configured shape already exists. No instance was created.');
}

if (!empty($config->availabilityDomains)) {
    if (is_array($config->availabilityDomains)) {
        $availabilityDomains = $config->availabilityDomains;
    } else {
        $availabilityDomains = [ $config->availabilityDomains ];
    }
} else {
    $availabilityDomains = $api->getAvailabilityDomains($config);
}
if (!$availabilityDomains) {
    reportResult('failed', 'OCI returned no availability domains.', 1);
}

foreach ($availabilityDomains as $availabilityDomainEntity) {
    $availabilityDomain = is_array($availabilityDomainEntity) ? $availabilityDomainEntity['name'] : $availabilityDomainEntity;
    echo "Checking availability domain $availabilityDomain\n";
    try {
        $instanceDetails = $api->createInstance($config, $shape, getenv('OCI_SSH_PUBLIC_KEY'), $availabilityDomain);
    } catch(ApiCallException $e) {
        $message = $e->getMessage();
        echo preg_replace('/ocid1\.[a-zA-Z0-9._-]+/', '[redacted OCID]', $message) . "\n";
//            if ($notifier->isSupported()) {
//                $notifier->notify($message);
//            }

        if (
            $e->getCode() === 500 &&
            (stripos($message, 'Out of host capacity') !== false || stripos($message, 'Out of capacity') !== false)
        ) {
            // trying next availability domain
            sleep(16);
            continue;
        }

        if ($e->getCode() === 429) {
            reportResult('retry_later', 'OCI rate limited this attempt. A later scheduled run will retry.');
        }

        reportResult('failed', $message, 1);
    }

    // success
    $message = 'OCI accepted the launch in ' . $availabilityDomain
        . '. State: ' . ($instanceDetails['lifecycleState'] ?? 'PROVISIONING') . '.';
    if ($notifier->isSupported()) {
        $notifier->notify($message);
    }

    reportResult('created', $message);
}

reportResult('waiting_for_capacity', 'All availability domains are currently out of host capacity. A later scheduled run will retry.');
