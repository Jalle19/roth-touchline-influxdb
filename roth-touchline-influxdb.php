<?php

// Parse options
$longOpts = [
    'controllerIpAddress:',
    'influxDbUrl:',
    'influxDbName:',
    'influxDbUsername:',
    'influxDbPassword:',
];

$options = getopt('', $longOpts);

if (count($options) !== 5) {
    die('Usage: ' . $argv[0] . " --controllerIpAddress <ipAddress> --influxDbUrl <influxDbUrl> --influxDbName <influxDbName> --influxDbUsername <influxDbUsername> --influxDbPassword <influxDbPassword>\n");
}

$controllerApiUrl = sprintf('http://%s/cgi-bin/ILRReadValues.cgi', $options['controllerIpAddress']);

// Query the total number of devices so we know how many thermostats we should query for later
$numDevicesQuery = <<<XML
<item>
    <n>totalNumberOfDevices</n>
</item>
XML;

$numDevicesContext = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: text/xml',
        'content' => $numDevicesQuery,
    ],
]);

// Parse the response
$numDevicesResponse = file_get_contents($controllerApiUrl, false, $numDevicesContext);

if ($numDevicesResponse === false) {
    throw new RuntimeException('Failed to query for total number of devices');
}

$numDevicesResponseElement = new SimpleXMLElement($numDevicesResponse);
$numDevices                = (int)$numDevicesResponseElement->v;

if ($numDevices === 0) {
    throw new RuntimeException('Total number of devices is zero, cannot continue');
}

// Query for the names of the thermostats. We need this for proper tagging
$thermostatQueryElement = new SimpleXMLElement('<thermostats></thermostats>');

for ($i = 0; $i < $numDevices; $i++) {
    $thermostatElement = $thermostatQueryElement->addChild('thermostat');
    $thermostatElement->addAttribute('id', $i);
    $thermostatElement->addChild('n', sprintf('G%d.name', $i));
}

$thermostatQueryContext = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: text/xml',
        'content' => $thermostatQueryElement->asXML(),
    ],
]);

// Parse the response
$thermostatResponse = file_get_contents($controllerApiUrl, false, $thermostatQueryContext);

if ($thermostatResponse === false) {
    throw new RuntimeException('Failed to query for total number of devices');
}

// Build a map of thermostat names
$thermostatResponseElement = new SimpleXMLElement($thermostatResponse);
$thermostatNameMap         = [];

foreach ($thermostatResponseElement as $thermostat) {
    $thermostatNameMap[(int)$thermostat['id']] = (string)$thermostat->v;
}

// Define all measurements and fields we want to query for
$statisticsMeasurementsFieldsMap = [
    // CD values
    'CD'         => [
        'CD.uname',
        'CD.upass',
        'CD.reg',
    ],
    // Controller values (only one controller supported)
    'Controller' => [
        'R0.DateTime',
        'R0.ErrorCode',
        'R0.OPModeRegler',
        'R0.Safety',
        'R0.SystemStatus',
        'R0.Taupunkt',
        'R0.WeekProgWarn',
        'R0.kurzID',
        'R0.numberOfPairedDevices',
        'R0.uniqueId',
    ],
    // STELL/STM/VPI values
    'STELL'      => [
        'STELL-APP',
        'STELL-BL',
    ],
    'STM'        => [
        'STM-APP',
        'STM-BL',
    ],
    'VPI'        => [
        'VPI.href',
        'VPI.state',
    ],
    // hw values
    'hw'         => [
        'hw.Addr',
        'hw.DNS1',
        'hw.DNS2',
        'hw.GW',
        'hw.HostName',
        'hw.IP',
        'hw.NM',
    ],
    // Other values
    'Misc'       => [
        'isMaster',
        'numberOfSlaveControllers',
        'totalNumberOfDevices',
    ],
];

// Define the values we want to query for each thermostat
$thermostatFields = [
    'RaumTemp',
    'WeekProg',
    'name',
    'TempSIUnit',
];

// Build the main query XML document
$measurementsElement = new SimpleXMLElement('<measurements></measurements>');

foreach ($statisticsMeasurementsFieldsMap as $measurement => $fields) {
    $measurementElement = $measurementsElement->addChild('measurement');
    $measurementElement->addAttribute('name', $measurement);

    $fieldsElement = $measurementElement->addChild('fields');

    foreach ($fields as $field) {
        $fieldElement = $fieldsElement->addChild('field');
        $fieldElement->addAttribute('n', $field);
        $nElement = $fieldElement->addChild('n', $field);
    }
}

// Add the measurements for the thermostats
for ($i = 0; $i < $numDevices; $i++) {
    $measurementElement = $measurementsElement->addChild('measurement');
    $measurementElement->addAttribute('name', 'Thermostat');

    // We want to tag each thermostat separately, using the name we determined earlier
    $tagsElement = $measurementElement->addChild('tags');
    $tagElement  = $tagsElement->addChild('tag', $thermostatNameMap[$i]);
    $tagElement->addAttribute('key', 'Thermostat');

    $fieldsElement = $measurementElement->addChild('fields');

    foreach ($thermostatFields as $field) {
        $itemName = sprintf('G%d.%s', $i, $field);

        $fieldElement = $fieldsElement->addChild('field');
        $fieldElement->addAttribute('n', $field); // intentionally not $itemName
        $nElement = $fieldElement->addChild('n', $itemName);
    }
}

// Query for the statistics
$measurementsQuery      = $measurementsElement->asXML();
$measurementsQueryContext = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: text/xml',
        'content' => $measurementsQuery,
    ],
]);

// Parse the response
$measurementsResponse = file_get_contents($controllerApiUrl, false, $measurementsQueryContext);

if ($measurementsResponse === false) {
    throw new RuntimeException('Failed to query for total number of devices');
}

$measurementsResponseElement = new SimpleXMLElement($measurementsResponse);

// Check if the specified InfluxDB database exists
$databaseExistsRequestUrl = sprintf('%s/query?q=%s', rtrim($options['influxDbUrl'], '/'), urlencode('SHOW DATABASES'));
$databaseExistsResponse   = @file_get_contents($databaseExistsRequestUrl);

if ($databaseExistsResponse === false) {
    throw new RuntimeException('Failed to query list of databases from InfluxDb');
}

$databaseExistsDecodedResponse = json_decode($databaseExistsResponse, true);
$availableDatabases            = array_map(static function ($value) {
    return $value[0];
}, $databaseExistsDecodedResponse['results'][0]['series'][0]['values']);

if (!in_array($options['influxDbName'], $availableDatabases, true)) {
    throw new RuntimeException(sprintf('The specified database "%s" does not exist in InfluxDb',
        $options['influxDbName']));
}

// Prepare queries for InfluxDB
$influxDbQueries = [];

foreach ($measurementsResponseElement as $measurement) {
    $measurementName = (string)$measurement['name'];
    $tagSet          = [];
    $fieldSet        = [];

    foreach ($measurement->tags->tag ?? [] as $tag) {
        $value = (string)$tag;

        // Escape special characters
        foreach ([',', '=', ' '] as $character) {
            $value = str_replace($character, '\\' . $character, $value);
        }

        $tagSet[] = sprintf('%s=%s', $tag['key'], $value);
    }

    foreach ($measurement->fields->field as $field) {
        $fieldName = (string)$field['n'];
        $value     = (string)$field->v;

        // Omit empty values, InfluxDB will refuse to handle them
        if ($value === '') {
            continue;
        }

        // Handle strings
        if (!is_numeric($value)) {
            $value = '"' . $value . '"';
        }

        // Handle known booleans
        if ($fieldName === 'isMaster') {
            $value = (int)$field->v === 1 ? 'true' : 'false';
        }

        $fieldSet[] = sprintf('%s=%s', $fieldName, $value);
    }

    if (count($tagSet) > 0) {
        $influxDbQueries[] = sprintf('%s,%s %s', $measurementName, implode(',', $tagSet), implode(',', $fieldSet));
    } else {
        $influxDbQueries[] = sprintf('%s %s', $measurementName, implode(',', $fieldSet));
    }
}

// Write the measurements to InfluxDB
$combinedInfluxDbQuery = implode("\n", $influxDbQueries);

//echo sprintf("curl -i -XPOST '%s/write?db=%s' --data-binary '%s'", rtrim($options['influxDbUrl'], '/'),
//    $options['influxDbName'], $combinedInfluxDbQuery);

$writeContext = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: text/plain',
        'content' => $combinedInfluxDbQuery,
    ],
]);

$writeApiUrl        = sprintf('%s/write?db=%s', rtrim($options['influxDbUrl'], '/'), $options['influxDbName']);
$numDevicesResponse = file_get_contents($writeApiUrl, false, $writeContext);

if ($numDevicesResponse === false) {
    throw new RuntimeException('Failed to write measurements to InfluxDb');
}
