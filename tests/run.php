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
    $activeZones = json_decode($module->GetBuffer('ActiveStepZones'), true);
    if (is_array($activeZones) && count($activeZones) > 0) {
        $deadlines = [];
        foreach ($activeZones as $zoneNumber) {
            $deadlines[(string) $zoneNumber] = time() - 1;
        }
        $module->SetBuffer('ActiveZoneDeadlines', json_encode($deadlines));
    }
}

function setZoneRemaining(WangariIrrigation $module, int $zoneNumber, int $seconds): void
{
    $deadlines = json_decode($module->GetBuffer('ActiveZoneDeadlines'), true);
    $deadlines = is_array($deadlines) ? $deadlines : [];
    $deadlines[(string) $zoneNumber] = time() + $seconds;
    $module->SetBuffer('ActiveZoneDeadlines', json_encode($deadlines));
    $module->SetBuffer('PhaseDeadline', (string) (time() + $seconds));
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
    $zoneDelayDeadline = $module->GetBuffer('PhaseDeadline');
    assertSameValue(true, $module->SkipCurrentZone(), 'Second press during delay should skip the next queued zone');
    assertSameValue($zoneDelayDeadline, $module->GetBuffer('PhaseDeadline'), 'Repeated skip must not restart the existing zone delay');
    forceDeadline($module);
    $module->Tick();
    assertSameValue(3, GetValue($module->GetIDForIdent('CurrentZone')), 'Third zone should start');
    assertSameValue(true, $module->SkipCurrentZone(), 'Last zone should be skippable');
    assertSameValue(false, GetValue($module->GetIDForIdent('ProgramActive')), 'Program must end after the last zone');
};

$tests['zones can be skipped during pump pressure build-up without opening them'] = static function (): void {
    $pump = testCreateVariable(0, false);
    $valve1 = testCreateVariable(0, false);
    $valve2 = testCreateVariable(0, false);
    $valve3 = testCreateVariable(0, false);
    $zones = [zone(true, $valve1), zone(true, $valve2), zone(true, $valve3)];
    $zones[0]['Name'] = 'Lawn';
    $zones[1]['Name'] = 'Hedge';
    $zones[2]['Name'] = 'Patio';
    $module = newModule([
        'Enabled' => true,
        'Simulation' => true,
        'PumpLeadSeconds' => 5,
        'MainValves' => json_encode([
            ['Enabled' => true, 'Name' => 'Pump', 'VariableID' => $pump, 'FeedbackID' => 0, 'FeedbackInverted' => false],
            ['Enabled' => false, 'Name' => 'Main valve', 'VariableID' => 0, 'FeedbackID' => 0, 'FeedbackInverted' => false]
        ]),
        'Zones' => json_encode($zones)
    ]);

    assertSameValue(true, $module->StartProgram(true), 'Program should enter pump pressure build-up');
    assertSameValue('opening-pump', $module->GetBuffer('Phase'), 'Pump delay must be active');
    $pumpDeadline = $module->GetBuffer('PhaseDeadline');
    $module->RequestAction('Skip', true);
    assertSameValue(1, (int) $module->GetBuffer('QueuePosition'), 'First queued zone should be skippable from the action button during pressure build-up');
    assertSameValue($pumpDeadline, $module->GetBuffer('PhaseDeadline'), 'Skipping must not restart pump pressure build-up');
    $module->RequestAction('Skip', true);
    assertSameValue(2, (int) $module->GetBuffer('QueuePosition'), 'Second queued zone should be skippable from the same button during pressure build-up');
    assertSameValue($pumpDeadline, $module->GetBuffer('PhaseDeadline'), 'Repeated skip must retain the original pressure deadline');
    $stateProfile = $GLOBALS['IPS_TEST_VARIABLES'][$module->GetIDForIdent('State')]['profile'];
    assertSameValue('Preparing: Patio', $GLOBALS['IPS_TEST_PROFILES'][$stateProfile]['associations'][1]['caption'], 'Status must name the next zone');

    forceDeadline($module);
    $module->Tick();
    assertSameValue(3, GetValue($module->GetIDForIdent('CurrentZone')), 'Only the third zone may open');
    $states = json_decode($module->GetBuffer('SimulatedOutputs'), true);
    assertSameValue(false, (bool) ($states[(string) $valve1] ?? false), 'First skipped valve must never open');
    assertSameValue(false, (bool) ($states[(string) $valve2] ?? false), 'Second skipped valve must never open');
    assertSameValue(true, (bool) ($states[(string) $valve3] ?? false), 'Third valve must open after the original pressure delay');
    assertSameValue('Watering: Patio', $GLOBALS['IPS_TEST_PROFILES'][$stateProfile]['associations'][2]['caption'], 'Running status must name the active zone');

    $lastPump = testCreateVariable(0, false);
    $lastValve = testCreateVariable(0, false);
    $lastModule = newModule([
        'Enabled' => true,
        'Simulation' => true,
        'PumpLeadSeconds' => 5,
        'MainValves' => json_encode([
            ['Enabled' => true, 'Name' => 'Pump', 'VariableID' => $lastPump, 'FeedbackID' => 0, 'FeedbackInverted' => false],
            ['Enabled' => false, 'Name' => 'Main valve', 'VariableID' => 0, 'FeedbackID' => 0, 'FeedbackInverted' => false]
        ]),
        'Zones' => json_encode([zone(true, $lastValve)])
    ]);
    assertSameValue(true, $lastModule->StartProgram(true), 'Single-zone program should enter pressure build-up');
    $lastModule->RequestAction('Skip', true);
    assertSameValue(false, GetValue($lastModule->GetIDForIdent('ProgramActive')), 'Skipping the last queued zone must end the program immediately');
    $lastStates = json_decode($lastModule->GetBuffer('SimulatedOutputs'), true);
    assertSameValue(false, (bool) ($lastStates[(string) $lastPump] ?? true), 'Pump must stop when the last queued zone is skipped before opening');
    assertSameValue(false, (bool) ($lastStates[(string) $lastValve] ?? false), 'Last skipped zone must never open');
};

$tests['manual zone completion always closes pump and main valve'] = static function (): void {
    $pump = testCreateVariable(0, false);
    $mainValve = testCreateVariable(0, false);
    $zoneValve = testCreateVariable(0, false);
    $module = newModule([
        'Enabled' => true,
        'Simulation' => false,
        'PumpLeadSeconds' => 0,
        'InterZoneSeconds' => 0,
        'MainValves' => json_encode([
            ['Enabled' => true, 'Name' => 'Pump', 'VariableID' => $pump, 'FeedbackID' => 0, 'FeedbackInverted' => false],
            ['Enabled' => true, 'Name' => 'Main valve', 'VariableID' => $mainValve, 'FeedbackID' => 0, 'FeedbackInverted' => false]
        ]),
        'Zones' => json_encode([zone(false, $zoneValve)])
    ]);

    $module->RequestAction('ManualRuntime', 1);
    $module->RequestAction('ManualZone', 1);
    assertSameValue(true, GetValue($module->GetIDForIdent('ProgramActive')), 'Manual-zone action should start independently of automatic participation');
    assertSameValue(true, GetValue($pump), 'Pump must run for a manual zone');
    assertSameValue(true, GetValue($mainValve), 'Main valve must open for a manual zone');
    assertSameValue(true, GetValue($zoneValve), 'Manual zone valve must open');
    forceDeadline($module);
    $module->Tick();
    assertSameValue(false, GetValue($zoneValve), 'Manual zone valve must close after its runtime');
    assertSameValue(false, GetValue($pump), 'Pump must stop when no queued zone remains');
    assertSameValue(false, GetValue($mainValve), 'Main valve must close when no queued zone remains');
    assertSameValue(false, GetValue($module->GetIDForIdent('ProgramActive')), 'Manual run must be inactive after completion');
    assertSameValue(0, GetValue($module->GetIDForIdent('State')), 'Manual completion must return to ready');
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

    setZoneRemaining($module1, 1, 3566);
    $module1->Tick();
    assertSameValue('since 34 sec, 59 min remaining', GetValue($progress1), 'Elapsed seconds should be shown below one minute');
    setZoneRemaining($module1, 1, 13);
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

$tests['group mode follows table order and keeps individual runtimes'] = static function (): void {
    $valve1 = testCreateVariable(0, false);
    $valve2 = testCreateVariable(0, false);
    $valve3 = testCreateVariable(0, false);
    $valve4 = testCreateVariable(0, false);
    $zones = [zone(true, $valve1), zone(true, $valve2), zone(true, $valve3), zone(true, $valve4)];
    foreach ([0 => ['Lawn', 5, 1], 1 => ['Hedge', 2, 1], 2 => ['Patio', 5, 1], 3 => ['Beds', 2, 2]] as $index => $settings) {
        $zones[$index]['Name'] = $settings[0];
        $zones[$index]['Group'] = $settings[1];
        $zones[$index]['RuntimeMinutes'] = $settings[2];
    }
    $module = newModule([
        'Enabled' => true,
        'Simulation' => false,
        'PumpLeadSeconds' => 0,
        'InterZoneSeconds' => 0,
        'Zones' => json_encode($zones)
    ]);
    $module->RequestAction('IrrigationMode', 2);
    assertSameValue(true, $module->StartProgram(true), 'Group program should start');
    assertSameValue(true, GetValue($valve1), 'Group at the first table position must start first');
    assertSameValue(false, GetValue($valve2), 'A numerically lower group must still wait for its table position');
    assertSameValue(true, GetValue($valve3), 'Later members of the first group must open simultaneously');
    assertSameValue(false, GetValue($valve4), 'Later group must wait');
    $stateProfile = $GLOBALS['IPS_TEST_VARIABLES'][$module->GetIDForIdent('State')]['profile'];
    assertSameValue('Watering: Group 5: Lawn, Patio', $GLOBALS['IPS_TEST_PROFILES'][$stateProfile]['associations'][2]['caption'], 'Status must name the active group and zones');

    setZoneRemaining($module, 1, -1);
    setZoneRemaining($module, 3, 60);
    $module->Tick();
    assertSameValue(false, GetValue($valve1), 'Shorter group member must close at its own deadline');
    assertSameValue(true, GetValue($valve3), 'Longer group member must remain open');
    assertSameValue(true, GetValue($module->GetIDForIdent('ProgramActive')), 'Group step must remain active for its longer member');

    forceDeadline($module);
    $module->Tick();
    forceDeadline($module);
    $module->Tick();
    assertSameValue(true, GetValue($valve2), 'Next group in table order must start after the group delay');
    assertSameValue(true, GetValue($valve4), 'All members of the next group must open together');
    assertSameValue(true, $module->SkipCurrentZone(), 'Skip must skip the complete running group');
    assertSameValue(false, GetValue($valve2), 'First skipped group member must close');
    assertSameValue(false, GetValue($valve4), 'Second skipped group member must close');
    assertSameValue(false, GetValue($module->GetIDForIdent('ProgramActive')), 'Skipping the last group must end the program');
};

$tests['watering mode zero blocks starts and oversized groups are limited to 100'] = static function (): void {
    $valve1 = testCreateVariable(0, false);
    $valve2 = testCreateVariable(0, false);
    $zones = [zone(true, $valve1), zone(true, $valve2)];
    $zones[0]['Group'] = 101;
    $zones[1]['Group'] = 100;
    $module = newModule([
        'Enabled' => true,
        'Simulation' => true,
        'Zones' => json_encode($zones)
    ]);
    assertSameValue(true, str_contains(GetValue($module->GetIDForIdent('LastError')), 'limited to 100'), 'Oversized group must produce a visible warning');
    $module->RequestAction('IrrigationMode', 0);
    assertSameValue(false, $module->StartProgram(true), 'Mode zero must block watering');
    assertSameValue(false, $module->StartZone(1, 1), 'Mode zero must also block a manual zone');
    assertSameValue(false, GetValue($module->GetIDForIdent('ProgramActive')), 'No program may run in mode zero');
    $module->RequestAction('IrrigationMode', 2);
    assertSameValue(true, $module->StartProgram(true), 'Group mode should start after selection');
    $states = json_decode($module->GetBuffer('SimulatedOutputs'), true);
    assertSameValue(true, $states[(string) $valve1], 'Group 101 must be clamped into group 100');
    assertSameValue(true, $states[(string) $valve2], 'Configured group 100 must run with the clamped zone');
};

$tests['group zero runs zones individually in table order'] = static function (): void {
    $valve1 = testCreateVariable(0, false);
    $valve2 = testCreateVariable(0, false);
    $valve3 = testCreateVariable(0, false);
    $valve4 = testCreateVariable(0, false);
    $zones = [zone(true, $valve1), zone(true, $valve2), zone(true, $valve3), zone(true, $valve4)];
    foreach ($zones as $index => &$zoneConfiguration) {
        $zoneConfiguration['Name'] = 'Zone ' . ($index + 1);
    }
    unset($zoneConfiguration);
    $zones[0]['Group'] = 0;
    $zones[1]['Group'] = 2;
    $zones[2]['Group'] = 2;
    $zones[3]['Group'] = 0;
    $module = newModule([
        'Enabled' => true,
        'Simulation' => false,
        'PumpLeadSeconds' => 0,
        'InterZoneSeconds' => 0,
        'Zones' => json_encode($zones)
    ]);
    $module->RequestAction('IrrigationMode', 2);
    assertSameValue(true, $module->StartProgram(true), 'Group mode should start with an ungrouped zone');
    assertSameValue(true, GetValue($valve1), 'First group-zero zone must run individually');
    assertSameValue(false, GetValue($valve2), 'Following real group must wait');
    assertSameValue(false, GetValue($valve3), 'All members of the following group must wait');
    assertSameValue(false, GetValue($valve4), 'Later group-zero zone must wait separately');

    forceDeadline($module);
    $module->Tick();
    forceDeadline($module);
    $module->Tick();
    assertSameValue(true, GetValue($valve2), 'Real group must start at its first table position');
    assertSameValue(true, GetValue($valve3), 'Equal nonzero group must start together');
    assertSameValue(true, $module->SkipCurrentZone(), 'Real group should be skippable as one step');
    forceDeadline($module);
    $module->Tick();
    assertSameValue(true, GetValue($valve4), 'Last group-zero zone must run individually after the group');
    $stateProfile = $GLOBALS['IPS_TEST_VARIABLES'][$module->GetIDForIdent('State')]['profile'];
    assertSameValue('Watering: Zone 4', $GLOBALS['IPS_TEST_PROFILES'][$stateProfile]['associations'][2]['caption'], 'Ungrouped zones must not be labelled as group 0');
};

$tests['group pause resumes all members and rain removes only affected members'] = static function (): void {
    $rain = testCreateVariable(0, false, false);
    $sensitiveValve = testCreateVariable(0, false);
    $unaffectedValve = testCreateVariable(0, false);
    $zones = [zone(true, $sensitiveValve, 0, 0, true), zone(true, $unaffectedValve, 0, 0, false)];
    $zones[0]['Group'] = 1;
    $zones[1]['Group'] = 1;
    $module = newModule([
        'Enabled' => true,
        'Simulation' => true,
        'RainSensorID' => $rain,
        'RainActiveValue' => true,
        'Zones' => json_encode($zones)
    ]);
    $module->RequestAction('IrrigationMode', 2);
    assertSameValue(true, $module->StartProgram(true), 'Grouped zones should start');
    setZoneRemaining($module, 1, 30);
    setZoneRemaining($module, 2, 45);
    assertSameValue(true, $module->Pause(), 'Complete group should pause');
    $states = json_decode($module->GetBuffer('SimulatedOutputs'), true);
    assertSameValue(false, $states[(string) $sensitiveValve], 'First group member must close for pause');
    assertSameValue(false, $states[(string) $unaffectedValve], 'Second group member must close for pause');
    assertSameValue(true, $module->Resume(), 'Complete group should resume');
    assertSameValue(45, GetValue($module->GetIDForIdent('RemainingSeconds')), 'Resume must show the longest remaining member runtime');
    $states = json_decode($module->GetBuffer('SimulatedOutputs'), true);
    assertSameValue(true, $states[(string) $sensitiveValve], 'First group member must reopen');
    assertSameValue(true, $states[(string) $unaffectedValve], 'Second group member must reopen');

    SetValue($rain, true);
    $module->Tick();
    $states = json_decode($module->GetBuffer('SimulatedOutputs'), true);
    assertSameValue(false, $states[(string) $sensitiveValve], 'Rain-sensitive group member must close');
    assertSameValue(true, $states[(string) $unaffectedValve], 'Unaffected group member must keep watering');
    assertSameValue(true, GetValue($module->GetIDForIdent('ProgramActive')), 'Mixed group must continue with unaffected member');
    assertSameValue(2, GetValue($module->GetIDForIdent('CurrentZone')), 'Current zone must point to the remaining group member');
};

$tests['missing OFF feedback is ignored by default while the next zone starts'] = static function (): void {
    $valve1 = testCreateVariable(0, false);
    $feedback1 = testCreateVariable(0, false, false);
    $valve2 = testCreateVariable(0, false);
    $module = newModule([
        'Enabled' => true,
        'Simulation' => false,
        'InterZoneSeconds' => 0,
        'FeedbackTimeoutSeconds' => 1,
        'Zones' => json_encode([zone(true, $valve1, 0, $feedback1), zone(true, $valve2)])
    ]);
    assertSameValue(true, $module->StartProgram(true), 'Program should start with OFF monitoring disabled');
    SetValue($feedback1, true);
    forceDeadline($module);
    $module->Tick();
    assertSameValue('inter-zone', $module->GetBuffer('Phase'), 'First zone should finish normally');
    forceDeadline($module);
    $module->Tick();
    assertSameValue(2, GetValue($module->GetIDForIdent('CurrentZone')), 'Missing OFF telegram must not block the next zone');
    assertSameValue(true, GetValue($valve2), 'Next zone must open despite stale ON feedback of the previous zone');
    assertSameValue('', GetValue($module->GetIDForIdent('LastError')), 'Ignored OFF feedback must not create an error');
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
        'MonitorClosedFeedback' => true,
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
    setZoneRemaining($module, 1, 30);
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
        unset($zones[$number - 1]['Group']);
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
    assertSameValue(0, $result[0]['Group'], 'Existing zones without a group must remain ungrouped');
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
