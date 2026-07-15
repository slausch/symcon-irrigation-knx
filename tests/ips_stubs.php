<?php

declare(strict_types=1);

$GLOBALS['IPS_TEST_VARIABLES'] = [];
$GLOBALS['IPS_TEST_NEXT_ID'] = 1000;
$GLOBALS['IPS_TEST_ACTIONS'] = [];
$GLOBALS['IPS_TEST_PROFILES'] = [];

function testCreateVariable(int $type, $value, bool $hasAction = true): int
{
    $id = $GLOBALS['IPS_TEST_NEXT_ID']++;
    $GLOBALS['IPS_TEST_VARIABLES'][$id] = [
        'type' => $type,
        'value' => $value,
        'action' => $hasAction,
        'ident' => ''
    ];
    return $id;
}

function GetValue(int $id)
{
    if (!isset($GLOBALS['IPS_TEST_VARIABLES'][$id])) {
        throw new RuntimeException('Unknown variable ' . $id);
    }
    return $GLOBALS['IPS_TEST_VARIABLES'][$id]['value'];
}

function SetValue(int $id, $value): void
{
    if (!isset($GLOBALS['IPS_TEST_VARIABLES'][$id])) {
        throw new RuntimeException('Unknown variable ' . $id);
    }
    $GLOBALS['IPS_TEST_VARIABLES'][$id]['value'] = $value;
}

function RequestAction(int $id, $value): void
{
    if (!isset($GLOBALS['IPS_TEST_VARIABLES'][$id]) || !$GLOBALS['IPS_TEST_VARIABLES'][$id]['action']) {
        throw new RuntimeException('Variable has no action: ' . $id);
    }
    $GLOBALS['IPS_TEST_ACTIONS'][] = [$id, (bool) $value];
    $GLOBALS['IPS_TEST_VARIABLES'][$id]['value'] = (bool) $value;
}

function IPS_VariableExists(int $id): bool
{
    return isset($GLOBALS['IPS_TEST_VARIABLES'][$id]);
}

function IPS_GetVariable(int $id): array
{
    return ['VariableType' => $GLOBALS['IPS_TEST_VARIABLES'][$id]['type']];
}

function IPS_LogMessage(string $sender, string $message): void
{
}

function IPS_VariableProfileExists(string $name): bool
{
    return isset($GLOBALS['IPS_TEST_PROFILES'][$name]);
}

function IPS_CreateVariableProfile(string $name, int $type): void
{
    $GLOBALS['IPS_TEST_PROFILES'][$name] = ['type' => $type];
}

function IPS_SetVariableProfileIcon(string $name, string $icon): void
{
}

function IPS_SetVariableProfileAssociation(string $name, int $value, string $caption, string $icon, int $color): void
{
}

function IPS_SetVariableProfileValues(string $name, float $minimum, float $maximum, float $step): void
{
}

function IPS_SetVariableProfileText(string $name, string $prefix, string $suffix): void
{
}

class IPSModule
{
    protected int $InstanceID;
    private array $properties = [];
    private array $buffers = [];
    private array $variables = [];
    public array $timers = [];
    public int $status = 0;

    public function __construct(int $instanceID = 1)
    {
        $this->InstanceID = $instanceID;
    }

    public function Create(): void
    {
    }

    public function ApplyChanges(): void
    {
    }

    public function TestSetProperty(string $name, $value): void
    {
        $this->properties[$name] = $value;
    }

    public function RegisterPropertyBoolean(string $name, bool $value): void
    {
        $this->properties[$name] = $this->properties[$name] ?? $value;
    }

    public function RegisterPropertyInteger(string $name, int $value): void
    {
        $this->properties[$name] = $this->properties[$name] ?? $value;
    }

    public function RegisterPropertyFloat(string $name, float $value): void
    {
        $this->properties[$name] = $this->properties[$name] ?? $value;
    }

    public function RegisterPropertyString(string $name, string $value): void
    {
        $this->properties[$name] = $this->properties[$name] ?? $value;
    }

    public function ReadPropertyBoolean(string $name): bool
    {
        return (bool) $this->properties[$name];
    }

    public function ReadPropertyInteger(string $name): int
    {
        return (int) $this->properties[$name];
    }

    public function ReadPropertyFloat(string $name): float
    {
        return (float) $this->properties[$name];
    }

    public function ReadPropertyString(string $name): string
    {
        return (string) $this->properties[$name];
    }

    public function RegisterTimer(string $ident, int $interval, string $script): void
    {
        $this->timers[$ident] = $interval;
    }

    public function SetTimerInterval(string $ident, int $interval): void
    {
        $this->timers[$ident] = $interval;
    }

    public function RegisterVariableBoolean(string $ident, string $name, string $profile = '', int $position = 0): void
    {
        $this->registerVariable($ident, 0, false);
    }

    public function RegisterVariableInteger(string $ident, string $name, string $profile = '', int $position = 0): void
    {
        $this->registerVariable($ident, 1, 0);
    }

    public function RegisterVariableString(string $ident, string $name, string $profile = '', int $position = 0): void
    {
        $this->registerVariable($ident, 3, '');
    }

    private function registerVariable(string $ident, int $type, $value): void
    {
        if (isset($this->variables[$ident])) {
            return;
        }
        $id = testCreateVariable($type, $value, false);
        $GLOBALS['IPS_TEST_VARIABLES'][$id]['ident'] = $ident;
        $this->variables[$ident] = $id;
    }

    public function EnableAction(string $ident): void
    {
    }

    public function GetIDForIdent(string $ident): int
    {
        return $this->variables[$ident];
    }

    public function SetBuffer(string $name, string $value): void
    {
        $this->buffers[$name] = $value;
    }

    public function GetBuffer(string $name): string
    {
        return $this->buffers[$name] ?? '';
    }

    public function RegisterMessage(int $id, int $message): void
    {
    }

    public function UnregisterMessage(int $id, int $message): void
    {
    }

    public function SetStatus(int $status): void
    {
        $this->status = $status;
    }

    public function Translate(string $text): string
    {
        return $text;
    }

    public function SendDebug(string $message, string $data, int $format): void
    {
    }
}
