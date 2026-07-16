<?php

declare(strict_types=1);

require __DIR__ . '/ips_stubs.php';
require dirname(__DIR__) . '/IrrigationKNX/module.php';

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '; expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function newModule(array $properties): WangariIrrigation
{
    static $instanceID = 1;
    $module = new WangariIrrigation($instanceID++);
    $module->Create();
    foreach ($properties as $name => $value) {
        $module->TestSetProperty($name, $value);
    }
    $module->ApplyChanges();
    return $module;
}

function zone(bool $enabled, int $valve1, int $valve2 = 0, int $feedback1 = 0, bool $rainSensitive = true): array
{
    return [
        'Enabled' => $enabled,
        'Name' => 'Test',
        'Valve1ID' => $valve1,
        'Valve2ID' => $valve2,
        'Feedback1ID' => $feedback1,
        'Feedback2ID' => 0,
        'Feedback1Inverted' => false,
        'Feedback2Inverted' => false,
        'RainSensitive' => $rainSensitive,
        'RuntimeMinutes' => 1
    ];
}

function forceDeadline(WangariIrrigation $module): void
{
    $module->SetBuffer('PhaseDeadline', (string) (time() - 1));
}

function isScheduledFor(WangariIrrigation $module, string $date): bool
{
    $method = new ReflectionMethod(WangariIrrigation::class, 'isScheduledDate');
    $method->setAccessible(true);
    return (bool) $method->invoke($module, new DateTimeImmutable($date));
}

$tests = [];

$tests['sequence skips disabled zones and reaches zone 5'] = static function (): void {
    $valve1 = testCreateVariable(0, false);
    $valve5 = testCreateVariable(0, false);
    $zones = [
        zone(true, $valve1),
        zone(false, 0),
        zone(false, 0),
        zone(false, 0),
        zone(true, $valve5),
        zone(false, 0)
    ];
    $module = newModule([
        'Enabled' => true,
        'Simulation' => true,
        'MasterLeadSeconds' => 0,
        'InterZoneSeconds' => 0,
        'Zones' => json_encode($zones)
    ]);
    assertSameValue(true, $module->StartProgram(true), 'Program should start');
    assertSameValue(1, GetValue($module->GetIDForIdent('CurrentZone')), 'Zone 1 should run first');
    forceDeadline($module);
    $module->Tick();
    forceDeadline($module);
    $module->Tick();
    assertSameValue(5, GetValue($module->GetIDForIdent('CurrentZone')), 'Zone 5 should run after disabled zones');
    $module->EmergencyStop('test');
    $states = json_decode($module->GetBuffer('SimulatedOutputs'), true);
    assertSameValue(false, $states[(string) $valve1], 'Zone 1 must be closed');
    assertSameValue(false, $states[(string) $valve5], 'Zone 5 must be closed');
};

$tests['simulation uses the separate test runtime'] = static function (): void {
    $zoneValve = testCreateVariable(0, false);
    $configuredZone = zone(true, $zoneValve);
    $configuredZone['RuntimeMinutes'] = 60;
    $module = newModule([
        'Enabled' => true,
        'Simulation' => true,
        'SimulationRuntimeMinutes' => 1,
        'MasterLeadSeconds' => 0,
        'Zones' => json_encode([$configuredZone])
    ]);
    assertSameValue(true, $module->StartProgram(true), 'Simulation should start');
    assertSameValue(60, GetValue($module->GetIDForIdent('RemainingSeconds')), 'Simulation must use the one-minute test runtime');
};

$tests['pump and main valve start sequentially on their own RequestAction targets'] = static function (): void {
    $GLOBALS['IPS_TEST_ACTIONS'] = [];
    $main1 = testCreateVariable(0, false);
    $main2 = testCreateVariable(0, false);
    $zoneValve = testCreateVariable(0, false);
    $module = newModule([
        'Enabled' => true,
        'Simulation' => false,
        'PumpLeadSeconds' => 5,
        'InterZoneSeconds' => 2,
        'MainValves' => json_encode([
            ['Enabled' => true, 'VariableID' => $main1, 'FeedbackID' => 0, 'FeedbackInverted' => false],
            ['Enabled' => true, 'VariableID' => $main2, 'FeedbackID' => 0, 'FeedbackInverted' => false]
        ]),
        'Zones' => json_encode([zone(true, $zoneValve)])
    ]);
    assertSameValue(true, $module->StartProgram(true), 'Hardware program should start');
    assertSameValue([$main1, true], $GLOBALS['IPS_TEST_ACTIONS'][0], 'Pump must start first');
    assertSameValue(1, count($GLOBALS['IPS_TEST_ACTIONS']), 'Main valve must wait for pump pressure');
    forceDeadline($module);
    $module->Tick();
    assertSameValue([$main2, true], $GLOBALS['IPS_TEST_ACTIONS'][1], 'Main valve must start after pump delay');
    forceDeadline($module);
    $module->Tick();
    assertSameValue([$zoneValve, true], $GLOBALS['IPS_TEST_ACTIONS'][2], 'Zone must start after main-valve delay');
    $module->Stop();
    assertSameValue(false, GetValue($main1), 'Main valve 1 must close');
    assertSameValue(false, GetValue($main2), 'Main valve 2 must close');
    assertSameValue(false, GetValue($zoneValve), 'Zone valve must close');
};

$tests['program works without pump and main valve'] = static function (): void {
    $zoneValve = testCreateVariable(0, false);
    $module = newModule([
        'Enabled' => true,
        'Simulation' => true,
        'PumpLeadSeconds' => 5,
        'InterZoneSeconds' => 2,
        'Zones' => json_encode([zone(true, $zoneValve)])
    ]);
    assertSameValue(true, $module->StartProgram(true), 'A water-supply output must not be required');
    assertSameValue('running-zone', $module->GetBuffer('Phase'), 'Zone should start directly without optional supply outputs');
};

$tests['skip closes the zone and repeated skip advances through the queue'] = static function (): void {
    $valve1 = testCreateVariable(0, false);
    $valve2 = testCreateVariable(0, false);
    $valve3 = testCreateVariable(0, false);
    $module = newModule([
        'Enabled' => true,
        'Simulation' => true,
        'InterZoneSeconds' => 2,
        'Zones' => json_encode([zone(true, $valve1), zone(true, $valve2), zone(true, $valve3)])
    ]);
    assertSameValue(true, $module->StartProgram(true), 'Program should start');
    assertSameValue(true, $module->SkipCurrentZone(), 'Running zone should be skipped');
    $states = json_decode($module->GetBuffer('SimulatedOutputs'), true);
    assertSameValue(false, $states[(string) $valve1], 'Skipped valve must close');
    assertSameValue('', GetValue($module->GetIDForIdent('Zone1Progress')), 'Skipped zone progress must be cleared');
    assertSameValue('inter-zone', $module->GetBuffer('Phase'), 'Configured zone delay must follow');
    assertSameValue(true, $module->SkipCurrentZone(), 'Second press during delay should skip the next queued zone');
    forceDeadline($module);
    $module->Tick();
    assertSameValue(3, GetValue($module->GetIDForIdent('CurrentZone')), 'Third zone should start');
    assertSameValue(true, $module->SkipCurrentZone(), 'Last zone should be skippable');
    assertSameValue(false, GetValue($module->GetIDForIdent('ProgramActive')), 'Program must end after the last zone');
};

$tests['zone progress strings use seconds below one minute and remain instance-local'] = static function (): void {
    $valve1 = testCreateVariable(0, false);
    $configuredZone = zone(true, $valve1);
    $configuredZone['Name'] = 'Lawn';
    $configuredZone['RuntimeMinutes'] = 60;
    $module1 = newModule([
        'Enabled' => true,
        'Simulation' => false,
        'PumpLeadSeconds' => 0,
        'InterZoneSeconds' => 0,
        'Zones' => json_encode([$configuredZone])
    ]);
    $module2 = newModule([
        'Enabled' => true,
        'Simulation' => true,
        'Zones' => json_encode([$configuredZone])
    ]);

    $progress1 = $module1->GetIDForIdent('Zone1Progress');
    $progress2 = $module2->GetIDForIdent('Zone1Progress');
    assertSameValue(false, $progress1 === $progress2, 'Each instance needs its own progress variable');
    assertSameValue('Lawn', $GLOBALS['IPS_TEST_VARIABLES'][$progress1]['name'], 'Progress variable should use the configured zone name');
    assertSameValue(true, $module1->StartProgram(true), 'First instance should start independently');
    assertSameValue('since 0 sec, 60 min remaining', GetValue($progress1), 'Initial progress should show seconds below one minute');
    assertSameValue('', GetValue($progress2), 'Second instance progress must remain untouched');

    $module1->SetBuffer('PhaseDeadline', (string) (time() + 3566));
    $module1->Tick();
    assertSameValue('since 34 sec, 59 min remaining', GetValue($progress1), 'Elapsed seconds should be shown below one minute');
    $module1->SetBuffer('PhaseDeadline', (string) (time() + 13));
    $module1->Tick();
    assertSameValue('since 59 min, 13 sec remaining', GetValue($progress1), 'Remaining seconds should be shown below one minute');
    assertSameValue(true, $module1->Pause(), 'Progress test should be pausable');
    $frozen = GetValue($progress1);
    $module1->Tick();
    assertSameValue($frozen, GetValue($progress1), 'Progress must remain frozen while paused');
    $module1->Stop();
    assertSameValue('', GetValue($progress1), 'Progress must clear when the program ends');
};

$tests['zone runtime and automatic participation are controllable'] = static function (): void {
    $valve1 = testCreateVariable(0, false);
    $valve2 = testCreateVariable(0, false);
    $module = newModule([
        'Enabled' => true,
        'Simulation' => false,
        'PumpLeadSeconds' => 0,
        'InterZoneSeconds' => 0,
        'Zones' => json_encode([zone(true, $valve1), zone(false, $valve2)])
    ]);
    $module->RequestAction('Zone2Automatic', true);
    $module->RequestAction('Zone2Runtime', 37);
    $module->RequestAction('InterZoneSeconds', 0);
    assertSameValue(true, GetValue($module->GetIDForIdent('Zone2Automatic')), 'Automatic variable should be true');
    assertSameValue(37, GetValue($module->GetIDForIdent('Zone2Runtime')), 'Runtime variable should contain 37 minutes');
    assertSameValue(0, GetValue($module->GetIDForIdent('InterZoneSeconds')), 'Zone delay should be writable at runtime');
    assertSameValue(true, $module->StartProgram(true), 'Program should include both zones');
    assertSameValue(true, $module->SkipCurrentZone(), 'Zone 1 should be skipped');
    forceDeadline($module);
    $module->Tick();
    assertSameValue(2, GetValue($module->GetIDForIdent('CurrentZone')), 'Enabled zone 2 should run next');
    assertSameValue(37 * 60, GetValue($module->GetIDForIdent('RemainingSeconds')), 'Zone runtime variable must control the program');
};

$tests['rain blocks manual and automatic starts'] = static function (): void {
    $rain = testCreateVariable(0, true, false);
    $zoneValve = testCreateVariable(0, false);
    $module = newModule([
        'Enabled' => true,
        'Simulation' => true,
        'RainSensorID' => $rain,
        'RainActiveValue' => true,
        'Zones' => json_encode([zone(true, $zoneValve)])
    ]);
    assertSameValue(false, $module->StartProgram(true), 'Rain must block manual start');
    assertSameValue(false, GetValue($module->GetIDForIdent('ProgramActive')), 'No run may be active');
    assertSameValue(true, GetValue($module->GetIDForIdent('SensorBlocked')), 'Sensor block must be visible');
};

$tests['schedule catches a disabled interval day up on the next enabled weekday'] = static function (): void {
    $weekdayModule = newModule([
        'IntervalDays' => 1,
        'IntervalAnchor' => '2026-01-01',
        'Monday' => true,
        'Tuesday' => false,
        'Wednesday' => true,
        'Thursday' => false,
        'Friday' => true,
        'Saturday' => false,
        'Sunday' => false
    ]);
    assertSameValue(true, isScheduledFor($weekdayModule, '2026-01-05'), 'Monday should be allowed with interval one');
    assertSameValue(false, isScheduledFor($weekdayModule, '2026-01-06'), 'Disabled Tuesday must be rejected');

    $intervalModule = newModule([
        'IntervalDays' => 3,
        'IntervalAnchor' => '2026-01-01',
        'Sunday' => false
    ]);
    assertSameValue(true, isScheduledFor($intervalModule, '2026-01-01'), 'Anchor date is an interval day');
    assertSameValue(false, isScheduledFor($intervalModule, '2026-01-04'), 'Disabled Sunday interval day must be skipped');
    assertSameValue(true, isScheduledFor($intervalModule, '2026-01-05'), 'Skipped Sunday interval must catch up on Monday');
    assertSameValue(false, isScheduledFor($intervalModule, '2026-01-06'), 'Catch-up must run only once');
    assertSameValue(true, isScheduledFor($intervalModule, '2026-01-07'), 'Next regular interval day should run');
};

$tests['rain skips only rain-sensitive zones and advances a running program'] = static function (): void {
    $rain = testCreateVariable(0, false, false);
    $sensitiveValve = testCreateVariable(0, false);
    $unaffectedValve = testCreateVariable(0, false);
    $laterSensitiveValve = testCreateVariable(0, false);
    $module = newModule([
        'Enabled' => true,
        'Simulation' => true,
        'InterZoneSeconds' => 0,
        'RainSensorID' => $rain,
        'RainActiveValue' => true,
        'Zones' => json_encode([
            zone(true, $sensitiveValve, 0, 0, true),
            zone(true, $unaffectedValve, 0, 0, false),
            zone(true, $laterSensitiveValve, 0, 0, true)
        ])
    ]);

    assertSameValue(true, $module->StartProgram(true), 'Program should start before rain');
    SetValue($rain, true);
    $module->Tick();
    assertSameValue('inter-zone', $module->GetBuffer('Phase'), 'Rain-sensitive running zone must close and enter the zone delay');
    forceDeadline($module);
    $module->Tick();
    assertSameValue(2, GetValue($module->GetIDForIdent('CurrentZone')), 'Next unaffected zone must start');
    assertSameValue(true, GetValue($module->GetIDForIdent('ProgramActive')), 'Program must continue for unaffected zones');
    assertSameValue(true, GetValue($module->GetIDForIdent('SensorBlocked')), 'Active rain sensor must remain visible');

    forceDeadline($module);
    $module->Tick();
    forceDeadline($module);
    $module->Tick();
    assertSameValue(false, GetValue($module->GetIDForIdent('ProgramActive')), 'Program must end when only rain-sensitive zones remain');

    $secondModule = newModule([
        'Enabled' => true,
        'Simulation' => true,
        'RainSensorID' => $rain,
        'RainActiveValue' => true,
        'Zones' => json_encode([
            zone(true, $sensitiveValve, 0, 0, true),
            zone(true, $unaffectedValve, 0, 0, false)
        ])
    ]);
    assertSameValue(true, $secondModule->StartProgram(true), 'Rain must still allow a program with an unaffected zone');
    assertSameValue(2, GetValue($secondModule->GetIDForIdent('CurrentZone')), 'Start queue must omit rain-sensitive zones');
};

$tests['soil moisture supports both comparison directions'] = static function (): void {
    $soil = testCreateVariable(2, 70.0, false);
    $zoneValve = testCreateVariable(0, false);
    $blocking = newModule([
        'Enabled' => true,
        'Simulation' => true,
        'SoilMoistureSensorID' => $soil,
        'SoilMoistureLimit' => 60.0,
        'SoilBlocksAboveLimit' => true,
        'Zones' => json_encode([zone(true, $zoneValve)])
    ]);
    assertSameValue(false, $blocking->StartProgram(true), 'Value above limit should block in above mode');

    $notBlocking = newModule([
        'Enabled' => true,
        'Simulation' => true,
        'SoilMoistureSensorID' => $soil,
        'SoilMoistureLimit' => 60.0,
        'SoilBlocksAboveLimit' => false,
        'Zones' => json_encode([zone(true, $zoneValve)])
    ]);
    assertSameValue(true, $notBlocking->StartProgram(true), 'Value above limit should not block in below mode');
    $notBlocking->Stop();
};

$tests['regular stop is ready while safety stop records an error'] = static function (): void {
    $zoneValve = testCreateVariable(0, false);
    $module = newModule([
        'Enabled' => true,
        'Simulation' => true,
        'Zones' => json_encode([zone(true, $zoneValve)])
    ]);
    assertSameValue(true, $module->StartProgram(true), 'Program should start for regular stop');
    $module->Stop();
    assertSameValue(0, GetValue($module->GetIDForIdent('State')), 'Regular stop should return to ready');
    assertSameValue('', GetValue($module->GetIDForIdent('LastError')), 'Regular stop should not create a new error');
    assertSameValue(true, $module->StartProgram(false), 'False marks an automatic source but must still start');
    $module->EmergencyStop('Leak detected');
    assertSameValue(5, GetValue($module->GetIDForIdent('State')), 'Safety stop should set error state');
    assertSameValue('Leak detected', GetValue($module->GetIDForIdent('LastError')), 'Safety stop should record its reason');
};

$tests['feedback mismatch causes safe shutdown'] = static function (): void {
    $GLOBALS['IPS_TEST_ACTIONS'] = [];
    $zoneValve = testCreateVariable(0, false);
    $feedback = testCreateVariable(0, false, false);
    $module = newModule([
        'Enabled' => true,
        'Simulation' => false,
        'MasterLeadSeconds' => 0,
        'FeedbackTimeoutSeconds' => 1,
        'Zones' => json_encode([zone(true, $zoneValve, 0, $feedback)])
    ]);
    assertSameValue(true, $module->StartProgram(true), 'Program should start before feedback timeout');
    $module->SetBuffer('FeedbackDeadline', (string) (time() - 1));
    $module->Tick();
    assertSameValue(false, GetValue($module->GetIDForIdent('ProgramActive')), 'Feedback fault must stop program');
    assertSameValue(false, GetValue($zoneValve), 'Zone must be commanded closed');
    assertSameValue(5, GetValue($module->GetIDForIdent('State')), 'State must report error');
};

$tests['shutdown waits for confirmed closed feedback'] = static function (): void {
    $zoneValve = testCreateVariable(0, false);
    $feedback = testCreateVariable(0, false, false);
    $module = newModule([
        'Enabled' => true,
        'Simulation' => false,
        'MasterLeadSeconds' => 0,
        'FeedbackTimeoutSeconds' => 1,
        'Zones' => json_encode([zone(true, $zoneValve, 0, $feedback)])
    ]);
    assertSameValue(true, $module->StartProgram(true), 'Program should start');
    SetValue($feedback, true);
    forceDeadline($module);
    $module->Tick();
    assertSameValue(false, GetValue($module->GetIDForIdent('ProgramActive')), 'Program flag should be off while closing');
    assertSameValue(6, GetValue($module->GetIDForIdent('State')), 'Module must wait for closed feedback');
    $module->SetBuffer('FeedbackDeadline', (string) (time() - 1));
    $module->Tick();
    assertSameValue(5, GetValue($module->GetIDForIdent('State')), 'Missing closed feedback must report an error');
};

$tests['pause closes and resume continues the same remaining runtime'] = static function (): void {
    $zoneValve = testCreateVariable(0, false);
    $module = newModule([
        'Enabled' => true,
        'Simulation' => true,
        'MasterLeadSeconds' => 0,
        'Zones' => json_encode([zone(true, $zoneValve)])
    ]);
    assertSameValue(true, $module->StartProgram(true), 'Program should start');
    $module->SetBuffer('PhaseDeadline', (string) (time() + 30));
    assertSameValue(true, $module->Pause(), 'Active zone should pause');
    assertSameValue('paused', $module->GetBuffer('Phase'), 'Phase should be paused');
    assertSameValue(7, GetValue($module->GetIDForIdent('State')), 'State should show paused');
    assertSameValue(30, GetValue($module->GetIDForIdent('RemainingSeconds')), 'Remaining runtime should be retained');
    $states = json_decode($module->GetBuffer('SimulatedOutputs'), true);
    assertSameValue(false, $states[(string) $zoneValve], 'Paused zone must close');
    $module->Tick();
    assertSameValue(30, GetValue($module->GetIDForIdent('RemainingSeconds')), 'Paused runtime must not count down');
    assertSameValue(true, $module->Resume(), 'Paused zone should resume');
    assertSameValue('running-zone', $module->GetBuffer('Phase'), 'Same zone should continue');
    assertSameValue(1, GetValue($module->GetIDForIdent('CurrentZone')), 'Current zone should remain unchanged');
    $states = json_decode($module->GetBuffer('SimulatedOutputs'), true);
    assertSameValue(true, $states[(string) $zoneValve], 'Resumed zone must reopen');
};

$tests['configuration action toggles pause and resume'] = static function (): void {
    $zoneValve = testCreateVariable(0, false);
    $module = newModule([
        'Enabled' => true,
        'Simulation' => true,
        'MasterLeadSeconds' => 0,
        'Zones' => json_encode([zone(true, $zoneValve)])
    ]);
    assertSameValue(true, $module->StartProgram(true), 'Program should start');
    assertSameValue(true, $module->TogglePause(), 'Toggle should pause a running zone');
    assertSameValue('paused', $module->GetBuffer('Phase'), 'Toggle should set paused phase');
    assertSameValue(true, $module->TogglePause(), 'Toggle should resume a paused zone');
    assertSameValue('running-zone', $module->GetBuffer('Phase'), 'Toggle should resume the same zone');
};

$tests['migration preserves six configured zones and appends four disabled zones'] = static function (): void {
    $module = new WangariIrrigation(99);
    $module->Create();
    $zones = [];
    for ($number = 1; $number <= 6; $number++) {
        $zones[] = zone($number <= 2, 100 + $number);
        $zones[$number - 1]['Name'] = 'Existing ' . $number;
        unset($zones[$number - 1]['RainSensitive']);
    }
    $persistence = json_encode([
        'configuration' => ['Zones' => json_encode($zones)],
        'attributes' => new stdClass()
    ]);
    $migrated = json_decode($module->Migrate($persistence), true);
    $result = json_decode($migrated['configuration']['Zones'], true);
    assertSameValue(10, count($result), 'Migration must provide ten zones');
    assertSameValue('Existing 1', $result[0]['Name'], 'Existing names must be preserved');
    assertSameValue(true, $result[0]['Enabled'], 'Existing activation must be preserved');
    assertSameValue('Zone 7', $result[6]['Name'], 'Zone 7 should be appended');
    assertSameValue(false, $result[6]['Enabled'], 'New zones must be disabled');
    assertSameValue(true, $result[0]['RainSensitive'], 'Existing zones must preserve the former global rain behavior');
    assertSameValue(5, $migrated['configuration']['PumpLeadSeconds'], 'Pump delay should be added without changing existing settings');
    assertSameValue(1, $migrated['configuration']['SimulationRuntimeMinutes'], 'Simulation runtime should receive its safe default');
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS: {$name}\n";
    } catch (Throwable $exception) {
        $failures++;
        echo "FAIL: {$name}: {$exception->getMessage()}\n";
    }
}
exit($failures === 0 ? 0 : 1);
