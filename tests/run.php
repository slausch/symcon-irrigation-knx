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

function newModule(array $properties): IrrigationKNX
{
    static $instanceID = 1;
    $module = new IrrigationKNX($instanceID++);
    $module->Create();
    foreach ($properties as $name => $value) {
        $module->TestSetProperty($name, $value);
    }
    $module->ApplyChanges();
    return $module;
}

function zone(bool $enabled, int $valve1, int $valve2 = 0, int $feedback1 = 0): array
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
        'RuntimeMinutes' => 1
    ];
}

function forceDeadline(IrrigationKNX $module): void
{
    $module->SetBuffer('PhaseDeadline', (string) (time() - 1));
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

$tests['both main valves use their own RequestAction target'] = static function (): void {
    $GLOBALS['IPS_TEST_ACTIONS'] = [];
    $main1 = testCreateVariable(0, false);
    $main2 = testCreateVariable(0, false);
    $zoneValve = testCreateVariable(0, false);
    $module = newModule([
        'Enabled' => true,
        'Simulation' => false,
        'MasterLeadSeconds' => 0,
        'MainValves' => json_encode([
            ['Enabled' => true, 'VariableID' => $main1, 'FeedbackID' => 0, 'FeedbackInverted' => false],
            ['Enabled' => true, 'VariableID' => $main2, 'FeedbackID' => 0, 'FeedbackInverted' => false]
        ]),
        'Zones' => json_encode([zone(true, $zoneValve)])
    ]);
    assertSameValue(true, $module->StartProgram(true), 'Hardware program should start');
    assertSameValue([$main1, true], $GLOBALS['IPS_TEST_ACTIONS'][0], 'Main valve 1 target');
    assertSameValue([$main2, true], $GLOBALS['IPS_TEST_ACTIONS'][1], 'Main valve 2 target');
    assertSameValue([$zoneValve, true], $GLOBALS['IPS_TEST_ACTIONS'][2], 'Zone valve target');
    $module->Stop();
    assertSameValue(false, GetValue($main1), 'Main valve 1 must close');
    assertSameValue(false, GetValue($main2), 'Main valve 2 must close');
    assertSameValue(false, GetValue($zoneValve), 'Zone valve must close');
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
